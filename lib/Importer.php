<?php

namespace Vspace\Ibexport;

use Bitrix\Iblock\ElementTable;
use Bitrix\Iblock\SectionTable;
use Bitrix\Main\Loader;
use Bitrix\Main\Type\DateTime;
use CFile;
use CIBlockElement;
use CIBlockProperty;
use CIBlockPropertyEnum;
use CIBlockSection;
use SimpleXMLElement;

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
 * Как и экспорт, длинные деревья импортируются тиками ограниченными по
 * времени (Options::getTickBudgetSeconds()), с возобновлением между тиками
 * через STATE_JSON в ImportJobTable — тот же приём, что и в
 * Exporter::runSectionExport(), только источник — уже распакованный
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

        $xml = @simplexml_load_file($xmlPath);
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
            self::scheduleAgent($id);
        }

        return $id;
    }

    private static function scheduleAgent(int $jobId): void
    {
        $call = '\\Vspace\\Ibexport\\Importer::agentTick(' . $jobId . ');';
        \CAgent::AddAgent(
            $call,
            'vspace.ibexport',
            'N',
            5,
            '',
            'Y',
            DateTime::createFromTimestamp(time())->toString()
        );
    }

    public static function agentTick(int $jobId): string
    {
        $call = '\\Vspace\\Ibexport\\Importer::agentTick(' . $jobId . ');';

        $job = ImportJobTable::getJobById($jobId);
        if (!$job || in_array($job['STATUS'], [ImportJobTable::STATUS_DONE, ImportJobTable::STATUS_ERROR], true)) {
            \CAgent::RemoveAgent($call, 'vspace.ibexport');
            return '';
        }

        self::runStep($jobId);

        $job = ImportJobTable::getJobById($jobId);
        if (!$job || in_array($job['STATUS'], [ImportJobTable::STATUS_DONE, ImportJobTable::STATUS_ERROR], true)) {
            \CAgent::RemoveAgent($call, 'vspace.ibexport');
            return '';
        }

        return $call;
    }

    public static function runStep(int $jobId): array
    {
        $job = ImportJobTable::getJobById($jobId);
        if (!$job) {
            throw new \Exception('Задание импорта не найдено.');
        }

        if (in_array($job['STATUS'], [ImportJobTable::STATUS_DONE, ImportJobTable::STATUS_ERROR], true)) {
            return self::toProgress($job);
        }

        if (!ImportJobTable::tryLock($jobId, Options::getTickBudgetSeconds() * 4)) {
            return self::toProgress($job);
        }

        try {
            ImportJobTable::update($jobId, ['STATUS' => ImportJobTable::STATUS_RUNNING]);
            $job['STATUS'] = ImportJobTable::STATUS_RUNNING;

            $deadline = microtime(true) + Options::getTickBudgetSeconds();
            $tmpDir = $_SERVER['DOCUMENT_ROOT'] . VSPACE_IBEXPORT_TMP_DIR . '/' . $job['TMP_DIR'];
            $xml = simplexml_load_file($tmpDir . '/export.xml');

            if ($job['MODE'] === 'element') {
                self::runElementImport($job, $xml, $tmpDir);
            } else {
                self::runSectionImport($job, $xml, $tmpDir, $job['MODE'] === 'section_tree', $deadline);
            }

            $job = ImportJobTable::getJobById($jobId);
        } catch (\Throwable $e) {
            ImportJobTable::update($jobId, [
                'STATUS' => ImportJobTable::STATUS_ERROR,
                'ERROR_MESSAGE' => $e->getMessage(),
                'DATE_FINISH' => new DateTime(),
            ]);
            self::logEvent('ERROR', $jobId, $e->getMessage());
            $job = ImportJobTable::getJobById($jobId);
        } finally {
            ImportJobTable::unlock($jobId);
        }

        return self::toProgress($job);
    }

    private static function toProgress(array $job): array
    {
        $totalNodes = max(1, (int)$job['TOTAL_SECTIONS'] + (int)$job['TOTAL_ELEMENTS']);
        $doneNodes = (int)$job['PROCESSED_SECTIONS'] + (int)$job['PROCESSED_ELEMENTS'];
        $progress = $job['STATUS'] === ImportJobTable::STATUS_DONE ? 100 : (int)min(99, round(100 * $doneNodes / $totalNodes));

        return [
            'id' => (int)$job['ID'],
            'status' => $job['STATUS'],
            'stage' => $job['STAGE'],
            'progress' => $progress,
            'processed_sections' => (int)$job['PROCESSED_SECTIONS'],
            'processed_elements' => (int)$job['PROCESSED_ELEMENTS'],
            'total_sections' => (int)$job['TOTAL_SECTIONS'],
            'total_elements' => (int)$job['TOTAL_ELEMENTS'],
            'created_count' => (int)$job['CREATED_COUNT'],
            'updated_count' => (int)$job['UPDATED_COUNT'],
            'skipped_count' => (int)$job['SKIPPED_COUNT'],
            'error_message' => $job['ERROR_MESSAGE'],
        ];
    }

    // ---------------------------------------------------------------
    // режим element — всегда достаточно мал для одного тика
    // ---------------------------------------------------------------

    private static function runElementImport(array $job, SimpleXMLElement $xml, string $tmpDir): void
    {
        $jobId = (int)$job['ID'];
        $iblockId = (int)$job['TARGET_IBLOCK_ID'];
        $updateByCode = $job['UPDATE_BY_CODE'] === 'Y';

        $counts = ['created' => 0, 'updated' => 0, 'skipped' => 0];
        $warnings = [];

        self::importElementNode($xml->element, $iblockId, null, $updateByCode, $tmpDir, $counts, $warnings, true);

        foreach ($warnings as $w) {
            self::addWarning($jobId, $w);
        }

        ImportJobTable::update($jobId, [
            'PROCESSED_ELEMENTS' => 1,
            'CREATED_COUNT' => $counts['created'],
            'UPDATED_COUNT' => $counts['updated'],
            'SKIPPED_COUNT' => $counts['skipped'],
        ]);
        self::finalize($jobId);
    }

    // ---------------------------------------------------------------
    // режимы раздела — универсальный возобновляемый DFS-обход, зеркало
    // Exporter::runSectionExport(), только источник — уже распакованный XML
    // ---------------------------------------------------------------

    private static function runSectionImport(array $job, SimpleXMLElement $xml, string $tmpDir, bool $recursive, float $deadline): void
    {
        $jobId = (int)$job['ID'];
        $iblockId = (int)$job['TARGET_IBLOCK_ID'];
        $updateByCode = $job['UPDATE_BY_CODE'] === 'Y';
        $parentSectionId = (int)$job['PARENT_SECTION_ID'] ?: null;

        $state = $job['STATE_JSON'] ? json_decode($job['STATE_JSON'], true) : null;
        $stack = $state['stack'] ?? [self::newFrame([])];
        $counts = $state['counts'] ?? ['created' => 0, 'updated' => 0, 'skipped' => 0];
        $processedSections = (int)$job['PROCESSED_SECTIONS'];
        $processedElements = (int)$job['PROCESSED_ELEMENTS'];
        $warnings = [];

        while (!empty($stack) && microtime(true) < $deadline) {
            $i = count($stack) - 1;
            $frame = &$stack[$i];
            $node = self::nodeByPath($xml->section, $frame['path']);

            if (!$frame['opened']) {
                $parentId = $i > 0 ? $stack[$i - 1]['target_id'] : $parentSectionId;
                $frame['target_id'] = self::importSectionNode($node, $iblockId, $parentId, $updateByCode, $tmpDir, $counts, $warnings);
                $frame['opened'] = true;
                $processedSections++;
            }

            if ($frame['phase'] === 'elements') {
                $elements = isset($node->elements->element) ? $node->elements->element : [];
                if ($frame['elements_index'] < count($elements)) {
                    $elementNode = $elements[$frame['elements_index']];
                    $frame['elements_index']++;
                    self::importElementNode($elementNode, $iblockId, $frame['target_id'], $updateByCode, $tmpDir, $counts, $warnings, false);
                    $processedElements++;
                } else {
                    $frame['phase'] = 'children';
                }
            } elseif ($frame['phase'] === 'children') {
                if (!$recursive) {
                    $frame['phase'] = 'done';
                } else {
                    $children = isset($node->sections->section) ? $node->sections->section : [];
                    if ($frame['children_index'] < count($children)) {
                        $childPath = array_merge($frame['path'], [$frame['children_index']]);
                        $frame['children_index']++;
                        $stack[] = self::newFrame($childPath);
                    } else {
                        $frame['phase'] = 'done';
                    }
                }
            } else { // done
                array_pop($stack);
            }
            unset($frame);
        }

        foreach ($warnings as $w) {
            self::addWarning($jobId, $w);
        }

        ImportJobTable::update($jobId, [
            'PROCESSED_SECTIONS' => $processedSections,
            'PROCESSED_ELEMENTS' => $processedElements,
            'CREATED_COUNT' => $counts['created'],
            'UPDATED_COUNT' => $counts['updated'],
            'SKIPPED_COUNT' => $counts['skipped'],
            'STATE_JSON' => json_encode(['stack' => $stack, 'counts' => $counts]),
            'STAGE' => empty($stack) ? 'finalize' : 'traverse',
        ]);

        if (empty($stack)) {
            self::finalize($jobId);
        }
    }

    private static function newFrame(array $path): array
    {
        return [
            'path' => $path,
            'target_id' => null,
            'opened' => false,
            'phase' => 'elements',
            'elements_index' => 0,
            'children_index' => 0,
        ];
    }

    /** Идёт от корневого <section> по последовательности индексов дочерних <sections><section>. */
    private static function nodeByPath(SimpleXMLElement $root, array $path): SimpleXMLElement
    {
        $node = $root;
        foreach ($path as $index) {
            $node = $node->sections->section[$index];
        }
        return $node;
    }

    // ---------------------------------------------------------------
    // создание/обновление одного раздела или элемента
    // ---------------------------------------------------------------

    /** @return int ID найденного/созданного/обновлённого раздела в целевом инфоблоке */
    private static function importSectionNode(SimpleXMLElement $node, int $iblockId, ?int $parentId, bool $updateByCode, string $tmpDir, array &$counts, array &$warnings): int
    {
        $code = trim((string)$node['code']);
        $fields = [
            'IBLOCK_ID' => $iblockId,
            'NAME' => (string)$node->name,
            'ACTIVE' => (string)$node['active'] === 'N' ? 'N' : 'Y',
            'SORT' => self::readSort($node),
            'DESCRIPTION' => (string)$node->description,
        ];
        if ($code !== '') {
            $fields['CODE'] = $code;
        }
        if ($parentId) {
            $fields['IBLOCK_SECTION_ID'] = $parentId;
        }

        self::applyFileField($fields, 'PICTURE', $node->picture, $tmpDir, $warnings);
        self::applySectionUserFields($fields, $node->properties);

        $existingId = $code !== '' ? self::findByCode(SectionTable::class, $iblockId, $code) : null;

        if ($existingId) {
            if ($updateByCode) {
                $section = new CIBlockSection();
                if (!$section->Update($existingId, $fields)) {
                    $warnings[] = 'Раздел "' . $fields['NAME'] . '" (код ' . $code . '): ' . $section->LAST_ERROR;
                }
                $counts['updated']++;
            } else {
                $counts['skipped']++;
            }
            return $existingId;
        }

        $section = new CIBlockSection();
        $newId = $section->Add($fields);
        if (!$newId) {
            throw new \Exception('Не удалось создать раздел "' . $fields['NAME'] . '": ' . $section->LAST_ERROR);
        }
        $counts['created']++;

        return (int)$newId;
    }

    /**
     * @param SimpleXMLElement $node <element>
     * @param int|null $sectionId Раздел-владелец (режимы раздела) либо null (mode=element — привязка по <sections> самого элемента)
     * @param bool $withSections Разбирать ли собственный список <sections> элемента (только mode=element)
     */
    private static function importElementNode(SimpleXMLElement $node, int $iblockId, ?int $sectionId, bool $updateByCode, string $tmpDir, array &$counts, array &$warnings, bool $withSections): void
    {
        $code = trim((string)$node['code']);
        $fields = [
            'IBLOCK_ID' => $iblockId,
            'NAME' => (string)$node->name,
            'ACTIVE' => (string)$node['active'] === 'N' ? 'N' : 'Y',
            'SORT' => self::readSort($node),
            'PREVIEW_TEXT' => (string)$node->preview_text,
            'PREVIEW_TEXT_TYPE' => (string)($node->preview_text['type'] ?: 'text'),
            'DETAIL_TEXT' => (string)$node->detail_text,
            'DETAIL_TEXT_TYPE' => (string)($node->detail_text['type'] ?: 'text'),
        ];
        if ($code !== '') {
            $fields['CODE'] = $code;
        }
        if ((string)$node->date_active_from !== '') {
            $fields['DATE_ACTIVE_FROM'] = (string)$node->date_active_from;
        }
        if ((string)$node->date_active_to !== '') {
            $fields['DATE_ACTIVE_TO'] = (string)$node->date_active_to;
        }

        $sectionIds = [];
        if ($withSections && isset($node->sections->section)) {
            foreach ($node->sections->section as $secRef) {
                $refCode = trim((string)$secRef['code']);
                $targetId = $refCode !== '' ? self::findByCode(SectionTable::class, $iblockId, $refCode) : null;
                if ($targetId) {
                    $sectionIds[] = $targetId;
                } else {
                    $warnings[] = 'Элемент "' . $fields['NAME'] . '": раздел с кодом "' . $refCode . '" не найден в целевом инфоблоке, привязка пропущена.';
                }
            }
        } elseif ($sectionId) {
            $sectionIds[] = $sectionId;
        }
        if (!empty($sectionIds)) {
            $fields['IBLOCK_SECTION_ID'] = $sectionIds[0];
            $fields['IBLOCK_SECTION'] = $sectionIds;
        }

        self::applyFileField($fields, 'PREVIEW_PICTURE', $node->preview_picture, $tmpDir, $warnings);
        self::applyFileField($fields, 'DETAIL_PICTURE', $node->detail_picture, $tmpDir, $warnings);

        $existingId = $code !== '' ? self::findByCode(ElementTable::class, $iblockId, $code) : null;

        if ($existingId) {
            if ($updateByCode) {
                $element = new CIBlockElement();
                if (!$element->Update($existingId, $fields)) {
                    $warnings[] = 'Элемент "' . $fields['NAME'] . '" (код ' . $code . '): ' . $element->LAST_ERROR;
                }
                $counts['updated']++;
            } else {
                $counts['skipped']++;
            }
            $elementId = $existingId;
        } else {
            $element = new CIBlockElement();
            $newId = $element->Add($fields);
            if (!$newId) {
                throw new \Exception('Не удалось создать элемент "' . $fields['NAME'] . '": ' . $element->LAST_ERROR);
            }
            $counts['created']++;
            $elementId = (int)$newId;
        }

        self::applyElementProperties($elementId, $iblockId, $node->properties, $tmpDir, $warnings);
    }

    /** ?: тут не годится — SORT=0 валиден и не должен подменяться значением по умолчанию. */
    private static function readSort(SimpleXMLElement $node): int
    {
        $raw = (string)$node['sort'];
        return $raw !== '' ? (int)$raw : 500;
    }

    /** Ищет существующую запись по CODE в целевом инфоблоке (для сопоставления при повторном импорте). */
    private static function findByCode(string $ormClass, int $iblockId, string $code): ?int
    {
        $row = $ormClass::getList([
            'filter' => ['IBLOCK_ID' => $iblockId, 'CODE' => $code],
            'select' => ['ID'],
            'limit' => 1,
        ])->fetch();

        return $row ? (int)$row['ID'] : null;
    }

    /** UF_* поля раздела экспортированы обычным текстом (см. Exporter::writeSectionOpen) — ставятся как есть. */
    private static function applySectionUserFields(array &$fields, SimpleXMLElement $properties): void
    {
        if (!isset($properties->property)) {
            return;
        }
        foreach ($properties->property as $prop) {
            $code = (string)$prop['code'];
            if (strpos($code, 'UF_') === 0) {
                $fields[$code] = (string)$prop;
            }
        }
    }

    /** Прямые файловые поля (PICTURE/PREVIEW_PICTURE/DETAIL_PICTURE) — самозакрывающийся тег с атрибутом file_ref. */
    private static function applyFileField(array &$fields, string $fieldName, SimpleXMLElement $node, string $tmpDir, array &$warnings): void
    {
        $fileRef = (string)$node['file_ref'];
        if ($fileRef === '') {
            return; // файлы не выгружались либо исходный файл отсутствовал — не трогаем поле
        }

        $absPath = $tmpDir . '/' . $fileRef;
        if (!is_file($absPath)) {
            $warnings[] = 'Файл "' . $fileRef . '" не найден в архиве, поле ' . $fieldName . ' пропущено.';
            return;
        }

        $fileArr = CFile::MakeFileArray($absPath);
        if ($fileArr) {
            $fields[$fieldName] = $fileArr;
        }
    }

    /**
     * Свойства элемента (docs/xml-format.md, "Свойства элементов"). Определение
     * свойства должно уже существовать в целевом инфоблоке по CODE — сам
     * импорт свойства/варианты списков не создаёт (см. docs/import-format.md).
     */
    private static function applyElementProperties(int $elementId, int $iblockId, SimpleXMLElement $properties, string $tmpDir, array &$warnings): void
    {
        if (!isset($properties->property)) {
            return;
        }

        $propDefs = self::getPropertyDefinitions($iblockId);
        $resolved = [];

        foreach ($properties->property as $propNode) {
            $code = (string)$propNode['code'];
            $type = (string)$propNode['type'];
            if (!isset($propDefs[$code])) {
                $warnings[] = 'Свойство с кодом "' . $code . '" не найдено в целевом инфоблоке, значение пропущено.';
                continue;
            }
            $propId = $propDefs[$code]['ID'];

            if ($type === 'F') {
                $files = [];
                foreach ($propNode->file as $fileNode) {
                    $fileRef = (string)$fileNode['file_ref'];
                    if ($fileRef === '') {
                        continue;
                    }
                    $absPath = $tmpDir . '/' . $fileRef;
                    if (!is_file($absPath)) {
                        $warnings[] = 'Файл свойства "' . $code . '" не найден в архиве, значение пропущено.';
                        continue;
                    }
                    $fileArr = CFile::MakeFileArray($absPath);
                    if ($fileArr) {
                        $files[] = $fileArr;
                    }
                }
                if (!empty($files)) {
                    $resolved[$code] = $propDefs[$code]['MULTIPLE'] === 'Y' ? $files : $files[0];
                }
                continue;
            }

            if (isset($propNode->value) && count($propNode->value) > 0) {
                $texts = [];
                foreach ($propNode->value as $v) {
                    $texts[] = (string)$v;
                }
            } else {
                $text = trim((string)$propNode);
                $texts = $text !== '' ? [$text] : [];
            }
            if (empty($texts)) {
                continue;
            }

            if ($type === 'L') {
                $enumMap = self::getEnumMap($propId);
                $values = [];
                foreach ($texts as $text) {
                    if (isset($enumMap[$text])) {
                        $values[] = $enumMap[$text];
                    } else {
                        $warnings[] = 'Свойство "' . $code . '": вариант "' . $text . '" не найден среди значений списка в целевом инфоблоке, пропущен.';
                    }
                }
                if (!empty($values)) {
                    $resolved[$code] = $propDefs[$code]['MULTIPLE'] === 'Y' ? $values : $values[0];
                }
            } else {
                $resolved[$code] = $propDefs[$code]['MULTIPLE'] === 'Y' ? $texts : $texts[0];
            }
        }

        if (!empty($resolved)) {
            CIBlockElement::SetPropertyValuesEx($elementId, $iblockId, $resolved);
        }
    }

    /** @return array<string, array{ID:int, MULTIPLE:string}> код свойства => метаданные, для данного инфоблока */
    private static function getPropertyDefinitions(int $iblockId): array
    {
        static $cache = [];
        if (isset($cache[$iblockId])) {
            return $cache[$iblockId];
        }

        $defs = [];
        $res = CIBlockProperty::GetList([], ['IBLOCK_ID' => $iblockId, 'ACTIVE' => 'Y']);
        while ($prop = $res->Fetch()) {
            if ($prop['CODE'] !== '') {
                $defs[$prop['CODE']] = ['ID' => (int)$prop['ID'], 'MULTIPLE' => $prop['MULTIPLE']];
            }
        }

        $cache[$iblockId] = $defs;
        return $defs;
    }

    /** @return array<string, int> отображаемое значение варианта списка => ID варианта, для данного свойства */
    private static function getEnumMap(int $propertyId): array
    {
        static $cache = [];
        if (isset($cache[$propertyId])) {
            return $cache[$propertyId];
        }

        $map = [];
        $res = CIBlockPropertyEnum::GetList([], ['PROPERTY_ID' => $propertyId]);
        while ($enum = $res->Fetch()) {
            $map[$enum['VALUE']] = (int)$enum['ID'];
        }

        $cache[$propertyId] = $map;
        return $map;
    }

    private static function addWarning(int $jobId, string $message): void
    {
        $job = ImportJobTable::getJobById($jobId);
        $warnings = $job['WARNINGS_JSON'] ? json_decode($job['WARNINGS_JSON'], true) : [];
        $warnings[] = $message;
        ImportJobTable::update($jobId, ['WARNINGS_JSON' => json_encode($warnings, JSON_UNESCAPED_UNICODE)]);
    }

    // ---------------------------------------------------------------
    // финализация / временные файлы / журнал
    // ---------------------------------------------------------------

    private static function finalize(int $jobId): void
    {
        $ttl = Options::getTtlHours();

        ImportJobTable::update($jobId, [
            'STATUS' => ImportJobTable::STATUS_DONE,
            'STAGE' => 'done',
            'DATE_FINISH' => new DateTime(),
            'DATE_EXPIRE' => DateTime::createFromTimestamp(time() + $ttl * 3600),
        ]);

        self::logEvent('DONE', $jobId, '');
    }

    private static function logEvent(string $type, int $jobId, string $message): void
    {
        \CEventLog::Add([
            'SEVERITY' => $type === 'ERROR' ? 'ERROR' : 'INFO',
            'AUDIT_TYPE_ID' => 'VSPACE_IBIMPORT_' . $type,
            'MODULE_ID' => 'vspace.ibexport',
            'ITEM_ID' => $jobId,
            'DESCRIPTION' => $message,
        ]);
    }

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
            $dir = $_SERVER['DOCUMENT_ROOT'] . VSPACE_IBEXPORT_TMP_DIR . '/' . $row['TMP_DIR'];
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
