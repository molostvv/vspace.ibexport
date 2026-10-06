<?php

namespace Vspace\Ibexport;

use Bitrix\Main\Loader;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Type\DateTime;
use SimpleXMLElement;
use Vspace\Ibexport\Import\ArchiveFileRef;
use Vspace\Ibexport\Import\BitrixExistingRecordFinder;
use Vspace\Ibexport\Import\BitrixPropertySource;
use Vspace\Ibexport\Import\ImportContext;
use Vspace\Ibexport\Import\ImportPreview;
use Vspace\Ibexport\Import\ImportStep;
use Vspace\Ibexport\Import\SourceInfo;

Loader::includeModule('iblock');
Loc::loadMessages(__FILE__);

/**
 * Движок импорта — обратная операция к Exporter (см. lib/Exporter.php).
 * Читает ZIP-архив, выгруженный этим же модулем (docs/xml-format.md), и
 * создаёт/обновляет элементы и разделы в выбранном пользователем
 * инфоблоке. Сопоставление при повторном импорте — по символьному коду
 * (CODE): совпадение найдено и включена опция "Обновлять существующие по
 * коду" — Update(), иначе — Add(). Свойства и варианты списков должны уже
 * существовать в целевом инфоблоке — сам импорт их не создаёт, несовпавшее
 * значение пропускается с предупреждением (см. docs/import-format.md).
 *
 * Тонкий фасад: приём и проверка архива, создание задания и запуск тика;
 * сама работа распределена по коллабораторам:
 *  - TickRunner — общая механика тика (блокировка, агент, прогресс, ошибки);
 *  - Import\ImportStep — один тик импорта;
 *  - Import\SectionTreeWalker — возобновляемый DFS-обход дерева разделов;
 *  - Import\SectionImporter / ElementImporter — создание/обновление записи;
 *  - Import\PropertyResolver — резолв свойств элемента.
 *
 * Как и экспорт, длинные деревья импортируются тиками ограниченными по
 * времени (Options::getTickBudgetSeconds()), с возобновлением между тиками
 * через STATE_JSON в ImportJobTable — тот же приём, что и в
 * Export\SectionTreeWalker, только источник — уже распакованный
 * export.xml, разбираемый заново на каждый тик через simplexml_load_file()
 * (файл локальный, это дёшево), а не постраничные SQL-выборки.
 */
class Importer
{
    /** Сколько имён записей архива показывать в сообщении "в архиве нет export.xml". */
    private const DIAGNOSTIC_NAMES = 10;

    /**
     * Принимает загруженный файл ($_FILES['ARCHIVE']), распаковывает во
     * временный каталог и считает объём — до создания задания (шаг
     * "Проверить архив" в admin/import.php). Бросает исключение при любой
     * проблеме с файлом.
     */
    public static function prepareUpload(array $file): array
    {
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            throw new \Exception(Loc::getMessage('IBX_IMPORTER_UPLOAD_ERROR', ['#CODE#' => $file['error'] ?? '?']));
        }

        $tmpDirName = TmpStorage::create(TmpStorage::PREFIX_IMPORT);
        try {
            $zipPath = TmpStorage::getPath($tmpDirName) . '/upload.zip';
            if (!move_uploaded_file($file['tmp_name'], $zipPath)) {
                throw new \Exception(Loc::getMessage('IBX_IMPORTER_SAVE_FAILED'));
            }

            return self::extractAndValidate($zipPath, TmpStorage::getPath($tmpDirName), $tmpDirName, (string)($file['name'] ?? 'export.zip'));
        } catch (\Throwable $e) {
            TmpStorage::delete($tmpDirName);
            throw $e;
        }
    }

    /**
     * Общая часть prepareUpload() и YandexDisk\ImportSource::prepareFromDisk():
     * распаковка уже сохранённого на диске ZIP и проверка/подсчёт export.xml.
     * Публичный (а не private), т.к. используется из другого класса —
     * см. lib/YandexDisk/ImportSource.php.
     *
     * Распаковываются только export.xml и файлы из files/ (Import\ArchiveFileRef): архив приходит от
     * пользователя, и прочее его содержимое (скрипты, HTML, вложенные каталоги) на диск не попадает —
     * такие записи только считаются (ignored_entries), чтобы страница импорта могла о них сообщить.
     */
    public static function extractAndValidate(string $zipPath, string $tmpDir, string $tmpDirName, string $sourceFileName): array
    {
        $zip = new \ZipArchive();
        $openResult = $zip->open($zipPath);
        if ($openResult !== true) {
            throw new \Exception(Loc::getMessage('IBX_IMPORTER_NOT_ZIP', ['#CODE#' => $openResult]));
        }

        $entries = [];
        $names = [];
        $ignored = 0;
        $entryCount = $zip->numFiles;
        for ($i = 0; $i < $entryCount; $i++) {
            $name = (string)$zip->getNameIndex($i);
            $names[] = $name;
            if (ArchiveFileRef::isExtractable($name)) {
                $entries[] = $name;
            } elseif (!str_ends_with($name, '/')) { // записи-каталоги (например, "files/") не в счёт
                $ignored++;
            }
        }

        if (!in_array(ArchiveFileRef::XML_FILE, $entries, true)) {
            $zip->close();
            // Диагностика: без неё "export.xml не найден" неотличимо от "архив не тот" —
            // при расследовании нужно видеть, что реально лежит в архиве.
            throw new \Exception(Loc::getMessage('IBX_IMPORTER_NO_XML', [
                '#COUNT#' => $entryCount,
                '#NAMES#' => $names ? implode(', ', array_slice($names, 0, self::DIAGNOSTIC_NAMES)) . (count($names) > self::DIAGNOSTIC_NAMES ? ', …' : '') : '—',
            ]));
        }

        if (!$zip->extractTo($tmpDir, $entries)) {
            $zip->close();
            throw new \Exception(Loc::getMessage('IBX_IMPORTER_EXTRACT_FAILED', ['#PATH#' => TmpStorage::getRoot()]));
        }
        $zip->close();
        SourceInfo::write($tmpDir, $zipPath); // MD5 архива — для истории импортов (ImportedFileTable), сам ZIP удаляется
        @unlink($zipPath);

        return self::describeExtracted($tmpDir, $tmpDirName, $sourceFileName) + ['ignored_entries' => $ignored];
    }

    /**
     * Повторно открывает уже принятый архив (распакованный ранее prepareUpload()/prepareFromDisk()) — для шага
     * "Пересчитать" на странице импорта: без повторной загрузки файла.
     */
    public static function reopen(string $tmpDirName, string $sourceFileName): array
    {
        return self::describeExtracted(self::resolveTmpDir($tmpDirName), $tmpDirName, $sourceFileName);
    }

    /** Разбор распакованного export.xml: режим, объём и версия формата (0 — архив версии модуля до 1.1.0). */
    private static function describeExtracted(string $tmpDir, string $tmpDirName, string $sourceFileName): array
    {
        $xml = self::loadXml($tmpDir);

        $mode = (string)$xml['mode'];
        if (!in_array($mode, ['element', 'section_single', 'section_tree'], true)) {
            throw new \Exception(Loc::getMessage('IBX_IMPORTER_BAD_MODE', ['#MODE#' => $mode]));
        }

        $counts = self::countTree($xml, $mode);

        return [
            'tmp_dir' => $tmpDirName,
            'mode' => $mode,
            'source_iblock_id' => (int)$xml['iblock_id'],
            'sections' => $counts['sections'],
            'elements' => $counts['elements'],
            'source_file_name' => $sourceFileName !== '' ? $sourceFileName : 'export.zip',
            'format_version' => (int)$xml['version'],
        ];
    }

    /**
     * Что будет сделано при импорте уже принятого архива (Import\ImportPreview): записи из export.xml
     * с ключами сопоставления и ожидаемым действием при заданных опциях.
     *
     * @return array{rows: array[], truncated: bool, missing_props: array<string, int>, missing_uf: array<string, int>}
     */
    public static function preview(string $tmpDirName, int $iblockId, bool $updateByCode, bool $matchByXmlId): array
    {
        $tmpDir = self::resolveTmpDir($tmpDirName);
        $xml = self::loadXml($tmpDir);

        return (new ImportPreview(new BitrixExistingRecordFinder(), new BitrixPropertySource()))
            ->build($xml, (string)$xml['mode'], new ImportContext($iblockId, $updateByCode, $tmpDir, $matchByXmlId));
    }

    private static function loadXml(string $tmpDir): SimpleXMLElement
    {
        $xml = @simplexml_load_file($tmpDir . '/' . ArchiveFileRef::XML_FILE);
        if ($xml === false) {
            throw new \Exception(Loc::getMessage('IBX_IMPORTER_BAD_XML'));
        }

        return $xml;
    }

    private static function countTree(SimpleXMLElement $export, string $mode): array
    {
        if ($mode === 'element') {
            return ['sections' => 0, 'elements' => 1];
        }

        $section = $export->section;
        $sections = 0;
        $elements = 0;
        self::countSectionNode($section, $mode === 'section_tree', $sections, $elements);

        return ['sections' => $sections, 'elements' => $elements];
    }

    private static function countSectionNode(SimpleXMLElement $section, bool $recursive, int &$sections, int &$elements): void
    {
        $sections++;
        if (isset($section->elements->element)) {
            $elements += count($section->elements->element);
        }
        if ($recursive && isset($section->sections->section)) {
            foreach ($section->sections->section as $child) {
                self::countSectionNode($child, true, $sections, $elements);
            }
        }
    }

    /**
     * Достаёт уже распакованный ранее prepareUpload() временный каталог по
     * его имени. Имя жёстко проверяется по маске — оно приходит из скрытого
     * поля формы между шагами "Проверить"/"Запустить", доверять ему
     * напрямую как части файлового пути нельзя.
     */
    public static function resolveTmpDir(string $tmpDirName): string
    {
        if (!TmpStorage::isValidName($tmpDirName, TmpStorage::PREFIX_IMPORT)) {
            throw new \Exception(Loc::getMessage('IBX_IMPORTER_BAD_TMP_DIR'));
        }

        $tmpDir = TmpStorage::getPath($tmpDirName);
        if (!is_file($tmpDir . '/' . ArchiveFileRef::XML_FILE)) {
            throw new \Exception(Loc::getMessage('IBX_IMPORTER_TMP_DIR_GONE'));
        }

        return $tmpDir;
    }

    public static function createJob(array $params): int
    {
        $iblockId = (int)$params['TARGET_IBLOCK_ID'];
        Rights::requireImportRight($iblockId);

        global $USER;

        $tmpDir = self::resolveTmpDir($params['TMP_DIR']);
        $xml = self::loadXml($tmpDir);
        $mode = (string)$xml['mode'];
        $counts = self::countTree($xml, $mode);

        $id = ImportJobTable::add([
            'USER_ID' => (int)$USER->GetID(),
            'TARGET_IBLOCK_ID' => $iblockId,
            'PARENT_SECTION_ID' => (int)($params['PARENT_SECTION_ID'] ?? 0),
            'UPDATE_BY_CODE' => !empty($params['UPDATE_BY_CODE']) ? 'Y' : 'N',
            'MATCH_BY_XML_ID' => !empty($params['MATCH_BY_XML_ID']) ? 'Y' : 'N',
            'MODE' => $mode,
            'SOURCE_IBLOCK_ID' => (int)$xml['iblock_id'],
            'STATUS' => ImportJobTable::STATUS_NEW,
            'STAGE' => 'init',
            'TOTAL_SECTIONS' => $counts['sections'],
            'TOTAL_ELEMENTS' => $counts['elements'],
            'TMP_DIR' => $params['TMP_DIR'],
            'SOURCE_FILE_NAME' => (string)($params['SOURCE_FILE_NAME'] ?? ''),
        ])->getId();

        if (($counts['sections'] + $counts['elements']) > Options::getSyncThreshold()) {
            self::runner()->scheduleAgent($id);
        }

        return $id;
    }

    /** Колбэк CAgent для одного задания. Возвращает себя же для перепланирования, либо '' при завершении. */
    public static function agentTick(int $jobId): string
    {
        return self::runner()->agentTick($jobId);
    }

    /** Один ограниченный по времени тик импорта — общий движок см. в TickRunner. */
    public static function runStep(int $jobId): array
    {
        return self::runner()->runStep($jobId);
    }

    /** Общий движок тика (блокировка, агент, прогресс) — здесь только настройка под импорт. */
    private static function runner(): TickRunner
    {
        $eventLog = new JobEventLog('VSPACE_IBIMPORT_');

        return new TickRunner(
            ImportJobTable::class,
            self::class,
            $eventLog,
            Loc::getMessage('IBX_IMPORTER_JOB_NOT_FOUND'),
            (new ImportStep($eventLog))->run(...),
            static fn(array $job): array => [
                'created_count' => (int)$job['CREATED_COUNT'],
                'updated_count' => (int)$job['UPDATED_COUNT'],
                'skipped_count' => (int)$job['SKIPPED_COUNT'],
            ]
        );
    }

    // ---------------------------------------------------------------
    // временные файлы
    // ---------------------------------------------------------------

    /**
     * Постоянный агент CAgent: брошенные незавершённые задания переводит в ошибку, удаляет просроченные
     * задания импорта с их каталогами и каталоги "проверенных, но не запущенных" архивов (без задания).
     */
    public static function cleanupAgent(): string
    {
        $ttlHours = Options::getTtlHours();
        ImportJobTable::failAbandoned($ttlHours);

        $rows = ImportJobTable::getList([
            'filter' => ['<DATE_EXPIRE' => new DateTime()],
            'select' => ['ID', 'TMP_DIR'],
            'limit' => 200,
        ]);
        while ($row = $rows->fetch()) {
            TmpStorage::delete((string)$row['TMP_DIR']);
            ImportJobTable::delete($row['ID']);
        }

        TmpStorage::deleteOrphans(
            TmpStorage::PREFIX_IMPORT,
            time() - $ttlHours * 3600,
            static fn(string $name): bool => (bool)ImportJobTable::getList(['filter' => ['=TMP_DIR' => $name], 'select' => ['ID'], 'limit' => 1])->fetch()
        );
        TmpStorage::deleteLegacy();

        return '\\Vspace\\Ibexport\\Importer::cleanupAgent();';
    }
}
