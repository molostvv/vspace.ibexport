<?php

namespace Vspace\Ibexport;

use Bitrix\Iblock\ElementTable;
use Bitrix\Iblock\SectionTable;
use Bitrix\Main\IO\Directory;
use Bitrix\Main\Loader;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Type\DateTime;
use Vspace\Ibexport\Export\BitrixTreeSource;
use Vspace\Ibexport\Export\ExportStep;
use Vspace\Ibexport\Export\TreeSourceInterface;
use Vspace\Ibexport\YandexDisk\JobUploadTable;

Loader::includeModule('iblock');
Loc::loadMessages(__FILE__);

/**
 * Движок экспорта (раздел 3 ТЗ) — единая точка входа, которой пользуется и
 * админка, и (в перспективе) внешние вызывающие модули — cron/REST (раздел 11).
 * Тонкий фасад: расчёт объёма, создание задания и запуск тика; сама работа
 * распределена по коллабораторам:
 *  - TickRunner — общая механика тика (блокировка, агент, прогресс, ошибки);
 *  - Export\ExportStep — один тик экспорта (стадии init/traverse/finalize);
 *  - Export\SectionTreeWalker — возобновляемый DFS-обход дерева разделов;
 *  - Export\SectionWriter / ElementWriter / FileRefWriter — XML-узлы;
 *  - Export\ArchiveBuilder — итоговый ZIP.
 *
 * Долгие выгрузки в режиме "section_tree" возобновляемы: работа выполняется
 * ограниченными по времени тиками (см. Options::getTickBudgetSeconds()), так
 * что один HTTP-запрос или вызов агента никогда не рискует упереться в
 * max_execution_time (раздел 8). Прогресс и курсор возобновления хранятся в
 * JobTable; модель обхода дерева описана в docs/xml-format.md.
 */
class Exporter
{
    public static function estimate(array $params): array
    {
        $iblockId = (int)$params['IBLOCK_ID'];
        $activeOnly = !empty($params['ACTIVE_ONLY']);

        if ($params['ENTITY_TYPE'] === 'element') {
            return ['sections' => 0, 'elements' => 1];
        }

        $source = new BitrixTreeSource();
        $sectionId = (int)$params['ENTITY_ID'];

        if ($params['MODE'] === 'section_single') {
            return [
                'sections' => 1,
                'elements' => $source->countElements($iblockId, $sectionId, $activeOnly),
            ];
        }

        $sections = 0;
        $elements = 0;
        self::countSectionTree($source, $iblockId, $sectionId, $activeOnly, $sections, $elements);

        return ['sections' => $sections, 'elements' => $elements];
    }

    private static function countSectionTree(TreeSourceInterface $source, int $iblockId, int $sectionId, bool $activeOnly, int &$sections, int &$elements): void
    {
        $sections++;
        $elements += $source->countElements($iblockId, $sectionId, $activeOnly);

        foreach ($source->getChildSectionIds($iblockId, $sectionId, $activeOnly) as $childId) {
            self::countSectionTree($source, $iblockId, $childId, $activeOnly, $sections, $elements);
        }
    }

    public static function resolveElementId(int $iblockId, $idOrCode): ?int
    {
        if (ctype_digit((string)$idOrCode)) {
            $filter = ['IBLOCK_ID' => $iblockId, 'ID' => (int)$idOrCode];
        } else {
            $filter = ['IBLOCK_ID' => $iblockId, 'CODE' => (string)$idOrCode];
        }
        $row = ElementTable::getList(['filter' => $filter, 'select' => ['ID'], 'limit' => 1])->fetch();
        return $row ? (int)$row['ID'] : null;
    }

    public static function resolveSectionId(int $iblockId, $idOrCode): ?int
    {
        if (ctype_digit((string)$idOrCode)) {
            $filter = ['IBLOCK_ID' => $iblockId, 'ID' => (int)$idOrCode];
        } else {
            $filter = ['IBLOCK_ID' => $iblockId, 'CODE' => (string)$idOrCode];
        }
        $row = SectionTable::getList(['filter' => $filter, 'select' => ['ID'], 'limit' => 1])->fetch();
        return $row ? (int)$row['ID'] : null;
    }

    /**
     * Создаёт запись задания. Для небольших выгрузок вызывающий код сразу
     * же начинает дёргать runStep() (через AJAX-опрос страницы прогресса).
     * Для больших выгрузок дополнительно регистрируется самопланирующийся
     * агент (см. agentTick()).
     */
    public static function createJob(array $params): int
    {
        Rights::requireExportRight((int)$params['IBLOCK_ID']);

        global $USER;

        $est = self::estimate($params);

        $tmpDirName = TmpStorage::create(TmpStorage::PREFIX_EXPORT);
        Directory::createDirectory(TmpStorage::getPath($tmpDirName) . '/files');

        $id = JobTable::add([
            'USER_ID' => (int)$USER->GetID(),
            'IBLOCK_ID' => (int)$params['IBLOCK_ID'],
            'ENTITY_TYPE' => $params['ENTITY_TYPE'],
            'ENTITY_ID' => (int)$params['ENTITY_ID'],
            'MODE' => $params['MODE'],
            'WITH_FILES' => !empty($params['WITH_FILES']) ? 'Y' : 'N',
            'ACTIVE_ONLY' => !empty($params['ACTIVE_ONLY']) ? 'Y' : 'N',
            'STATUS' => JobTable::STATUS_NEW,
            'STAGE' => 'init',
            'TOTAL_SECTIONS' => $est['sections'],
            'TOTAL_ELEMENTS' => $est['elements'],
            'TMP_DIR' => $tmpDirName,
        ])->getId();

        if (($est['sections'] + $est['elements']) > Options::getSyncThreshold()) {
            self::runner()->scheduleAgent($id);
        }

        return $id;
    }

    /** Колбэк CAgent для одного задания. Возвращает себя же для перепланирования, либо '' при завершении. */
    public static function agentTick(int $jobId): string
    {
        return self::runner()->agentTick($jobId);
    }

    /**
     * Выполняет один ограниченный по времени тик работы над заданием
     * (раздел 8: батчами, с бюджетом времени, без разрастания памяти).
     * Безопасно вызывать повторно и "параллельно" — из AJAX-опроса и/или
     * фонового агента одновременно (см. TickRunner и AbstractJobTable::tryLock()).
     */
    public static function runStep(int $jobId): array
    {
        return self::runner()->runStep($jobId);
    }

    /** Общий движок тика (блокировка, агент, прогресс) — здесь только настройка под экспорт. */
    private static function runner(): TickRunner
    {
        $eventLog = new JobEventLog('VSPACE_IBEXPORT_');

        return new TickRunner(
            JobTable::class,
            self::class,
            $eventLog,
            Loc::getMessage('IBX_EXPORTER_JOB_NOT_FOUND'),
            (new ExportStep($eventLog))->run(...),
            static fn(array $job): array => [
                'archive_file' => $job['ARCHIVE_FILE'],
                'archive_size' => (int)$job['ARCHIVE_SIZE'],
                'disk_upload' => JobUploadTable::getByJob((int)$job['ID']), // выгрузка на Яндекс.Диск, null — не было
            ]
        );
    }

    // ---------------------------------------------------------------
    // временные файлы (разделы 5, 8)
    // ---------------------------------------------------------------

    public static function getTmpDir(int $jobId): string
    {
        return JobTable::getTmpPath(JobTable::getJobById($jobId));
    }

    /**
     * Постоянный агент CAgent (раздел 8): брошенные незавершённые задания переводит в ошибку, удаляет
     * просроченные задания с их каталогами и каталоги выгрузок, на которые не ссылается ни одно задание.
     */
    public static function cleanupAgent(): string
    {
        $ttlHours = Options::getTtlHours();
        JobTable::failAbandoned($ttlHours);

        $rows = JobTable::getList([
            'filter' => ['<DATE_EXPIRE' => new DateTime()],
            'select' => ['ID', 'TMP_DIR'],
            'limit' => 200,
        ]);
        while ($row = $rows->fetch()) {
            TmpStorage::delete((string)$row['TMP_DIR']);
            JobUploadTable::deleteByJob((int)$row['ID']);
            JobTable::delete($row['ID']);
        }

        TmpStorage::deleteOrphans(
            TmpStorage::PREFIX_EXPORT,
            time() - $ttlHours * 3600,
            static fn(string $name): bool => (bool)JobTable::getList(['filter' => ['=TMP_DIR' => $name], 'select' => ['ID'], 'limit' => 1])->fetch()
        );
        TmpStorage::deleteLegacy();

        return '\\Vspace\\Ibexport\\Exporter::cleanupAgent();';
    }
}
