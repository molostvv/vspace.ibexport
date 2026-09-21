<?php

namespace Vspace\Ibexport;

use Bitrix\Main\Loader;
use Bitrix\Main\Type\DateTime;
use SimpleXMLElement;
use Vspace\Ibexport\Import\BitrixExistingRecordFinder;
use Vspace\Ibexport\Import\BitrixPropertySource;
use Vspace\Ibexport\Import\ImportContext;
use Vspace\Ibexport\Import\ImportPreview;
use Vspace\Ibexport\Import\ImportStep;

Loader::includeModule('iblock');

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
    /**
     * Принимает загруженный файл ($_FILES['ARCHIVE']), распаковывает во
     * временный каталог и считает объём — до создания задания (шаг
     * "Проверить архив" в admin/import.php). Бросает исключение при любой
     * проблеме с файлом.
     */
    public static function prepareUpload(array $file): array
    {
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            throw new \Exception('Файл не был загружен (код ошибки ' . ($file['error'] ?? '?') . ').');
        }

        $tmpDirName = 'import_' . uniqid();
        $tmpDir = $_SERVER['DOCUMENT_ROOT'] . VSPACE_IBEXPORT_TMP_DIR . '/' . $tmpDirName;
        if (!is_dir($tmpDir) && !mkdir($tmpDir, 0755, true) && !is_dir($tmpDir)) {
            throw new \Exception('Не удалось создать временный каталог для импорта.');
        }

        $zipPath = $tmpDir . '/upload.zip';
        if (!move_uploaded_file($file['tmp_name'], $zipPath)) {
            throw new \Exception('Не удалось сохранить загруженный файл.');
        }

        return self::extractAndValidate($zipPath, $tmpDir, $tmpDirName, (string)($file['name'] ?? 'export.zip'));
    }

    /**
     * Общая часть prepareUpload() и YandexDisk\ImportSource::prepareFromDisk():
     * распаковка уже сохранённого на диске ZIP и проверка/подсчёт export.xml.
     * Публичный (а не private), т.к. используется из другого класса —
     * см. lib/YandexDisk/ImportSource.php.
     */
    public static function extractAndValidate(string $zipPath, string $tmpDir, string $tmpDirName, string $sourceFileName): array
    {
        $zip = new \ZipArchive();
        $openResult = $zip->open($zipPath);
        if ($openResult !== true) {
            throw new \Exception('Файл не является корректным ZIP-архивом (код ошибки ' . $openResult . ').');
        }

        $entryCount = $zip->numFiles;
        if (!$zip->extractTo($tmpDir)) {
            $zip->close();
            throw new \Exception('Не удалось распаковать архив во временный каталог — проверьте права на запись в ' . VSPACE_IBEXPORT_TMP_DIR . '.');
        }
        $zip->close();
        @unlink($zipPath);

        $xmlPath = $tmpDir . '/export.xml';
        if (!is_file($xmlPath)) {
            // Диагностика: без неё "export.xml не найден" неотличимо от
            // "архив не тот" и от "распаковка тихо не удалась" — при
            // расследовании нужно видеть, что реально оказалось на диске.
            $found = array_values(array_diff(scandir($tmpDir) ?: [], ['.', '..']));
            throw new \Exception(
                'В архиве не найден export.xml — это не выгрузка модуля "Экспорт инфоблоков". '
                . 'Записей в архиве: ' . $entryCount . '. '
                . 'После распаковки на диске: ' . ($found ? implode(', ', $found) : '(ничего)') . '.'
            );
        }

        return self::describeExtracted($tmpDir, $tmpDirName, $sourceFileName);
    }

    /**
     * Повторно открывает уже принятый архив (распакованный ранее prepareUpload()/prepareFromDisk()) — для шага
     * "Пересчитать" на странице импорта: без повторной загрузки файла.
     */
    public static function reopen(string $tmpDirName, string $sourceFileName): array
    {
        return self::describeExtracted(self::resolveTmpDir($tmpDirName), $tmpDirName, $sourceFileName);
    }

    /** Разбор распакованного export.xml: режим и объём. */
    private static function describeExtracted(string $tmpDir, string $tmpDirName, string $sourceFileName): array
    {
        $xml = @simplexml_load_file($tmpDir . '/export.xml');
        if ($xml === false) {
            throw new \Exception('Не удалось разобрать export.xml — файл повреждён.');
        }

        $mode = (string)$xml['mode'];
        if (!in_array($mode, ['element', 'section_single', 'section_tree'], true)) {
            throw new \Exception('Неизвестный режим выгрузки в export.xml: "' . $mode . '".');
        }

        $counts = self::countTree($xml, $mode);

        return [
            'tmp_dir' => $tmpDirName,
            'mode' => $mode,
            'source_iblock_id' => (int)$xml['iblock_id'],
            'sections' => $counts['sections'],
            'elements' => $counts['elements'],
            'source_file_name' => $sourceFileName !== '' ? $sourceFileName : 'export.zip',
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
        $xml = simplexml_load_file($tmpDir . '/export.xml');

        return (new ImportPreview(new BitrixExistingRecordFinder(), new BitrixPropertySource()))
            ->build($xml, (string)$xml['mode'], new ImportContext($iblockId, $updateByCode, $tmpDir, $matchByXmlId));
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
        if (!preg_match('~^import_[a-z0-9.]+$~i', $tmpDirName)) {
            throw new \Exception('Некорректный идентификатор загруженного архива, загрузите файл заново.');
        }

        $tmpDir = $_SERVER['DOCUMENT_ROOT'] . VSPACE_IBEXPORT_TMP_DIR . '/' . $tmpDirName;
        if (!is_file($tmpDir . '/export.xml')) {
            throw new \Exception('Загруженный архив не найден (истёк срок хранения?), загрузите файл заново.');
        }

        return $tmpDir;
    }

    public static function createJob(array $params): int
    {
        $iblockId = (int)$params['TARGET_IBLOCK_ID'];
        Rights::requireImportRight($iblockId);

        global $USER;

        $tmpDir = self::resolveTmpDir($params['TMP_DIR']);
        $xml = simplexml_load_file($tmpDir . '/export.xml');
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
            'Задание импорта не найдено.',
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

    /** Постоянный агент CAgent: удаляет просроченные каталоги и записи заданий импорта, включая "провалидированные, но не запущенные" (без задания). */
    public static function cleanupAgent(): string
    {
        $rows = ImportJobTable::getList([
            'filter' => ['<DATE_EXPIRE' => new DateTime()],
            'select' => ['ID', 'TMP_DIR'],
            'limit' => 200,
        ]);

        $knownDirs = [];
        while ($row = $rows->fetch()) {
            $knownDirs[$row['TMP_DIR']] = true;
            $dir = ImportJobTable::getTmpPath($row);
            if (is_dir($dir)) {
                self::rrmdir($dir);
            }
            ImportJobTable::delete($row['ID']);
        }

        // каталоги, созданные prepareUpload() на шаге "Проверить архив", для
        // которых пользователь так и не запустил импорт (задания нет вовсе)
        $base = $_SERVER['DOCUMENT_ROOT'] . VSPACE_IBEXPORT_TMP_DIR;
        if (is_dir($base)) {
            $staleBefore = time() - Options::getTtlHours() * 3600;
            foreach (scandir($base) as $entry) {
                if (strpos($entry, 'import_') !== 0 || isset($knownDirs[$entry])) {
                    continue;
                }
                $dir = $base . '/' . $entry;
                if (is_dir($dir) && filemtime($dir) < $staleBefore) {
                    $stillUsed = ImportJobTable::getList(['filter' => ['TMP_DIR' => $entry], 'select' => ['ID'], 'limit' => 1])->fetch();
                    if (!$stillUsed) {
                        self::rrmdir($dir);
                    }
                }
            }
        }

        return '\\Vspace\\Ibexport\\Importer::cleanupAgent();';
    }

    private static function rrmdir(string $dir): void
    {
        foreach (scandir($dir) as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            if (is_dir($path)) {
                self::rrmdir($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($dir);
    }
}
