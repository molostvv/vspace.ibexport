<?php

namespace Vspace\Ibexport;

use Bitrix\Iblock\ElementTable;
use Bitrix\Iblock\SectionTable;
use Bitrix\Main\Entity\ExpressionField;
use Bitrix\Main\Loader;
use Bitrix\Main\Type\DateTime;
use CFile;
use CIBlockElement;
use CIBlockSection;

Loader::includeModule('iblock');

/**
 * Движок экспорта (раздел 3 ТЗ). Единая точка входа, которой пользуется и
 * админка, и (в перспективе) внешние вызывающие модули — cron/REST (раздел 11).
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

        $sectionId = (int)$params['ENTITY_ID'];

        if ($params['MODE'] === 'section_single') {
            return [
                'sections' => 1,
                'elements' => self::countElements($iblockId, $sectionId, $activeOnly),
            ];
        }

        $sections = 0;
        $elements = 0;
        self::countSectionTree($iblockId, $sectionId, $activeOnly, $sections, $elements);

        return ['sections' => $sections, 'elements' => $elements];
    }

    private static function countSectionTree(int $iblockId, int $sectionId, bool $activeOnly, int &$sections, int &$elements): void
    {
        $sections++;
        $elements += self::countElements($iblockId, $sectionId, $activeOnly);

        foreach (self::getDirectChildSectionIds($iblockId, $sectionId, $activeOnly) as $childId) {
            self::countSectionTree($iblockId, $childId, $activeOnly, $sections, $elements);
        }
    }

    private static function countElements(int $iblockId, int $sectionId, bool $activeOnly): int
    {
        $filter = ['IBLOCK_ID' => $iblockId, 'IBLOCK_SECTION_ID' => $sectionId];
        if ($activeOnly) {
            $filter['ACTIVE'] = 'Y';
        }

        $row = ElementTable::getList([
            'filter' => $filter,
            'select' => ['CNT'],
            'runtime' => [new ExpressionField('CNT', 'COUNT(*)')],
        ])->fetch();

        return (int)($row['CNT'] ?? 0);
    }

    private static function getDirectChildSectionIds(int $iblockId, int $parentId, bool $activeOnly): array
    {
        $filter = ['IBLOCK_ID' => $iblockId, 'IBLOCK_SECTION_ID' => $parentId];
        if ($activeOnly) {
            $filter['ACTIVE'] = 'Y';
        }

        $ids = [];
        $res = SectionTable::getList([
            'filter' => $filter,
            'select' => ['ID'],
            'order' => ['SORT' => 'ASC', 'NAME' => 'ASC'],
            'limit' => 5000,
        ]);
        while ($row = $res->fetch()) {
            $ids[] = (int)$row['ID'];
        }

        return $ids;
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
            'TMP_DIR' => 'job_' . uniqid(),
        ])->getId();

        self::ensureTmpDir($id);

        if (($est['sections'] + $est['elements']) > Options::getSyncThreshold()) {
            self::scheduleAgent($id);
        }

        return $id;
    }

    private static function scheduleAgent(int $jobId): void
    {
        $call = '\\Vspace\\Ibexport\\Exporter::agentTick(' . $jobId . ');';
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

    /** Колбэк CAgent для одного задания. Возвращает себя же для перепланирования, либо '' при завершении. */
    public static function agentTick(int $jobId): string
    {
        $call = '\\Vspace\\Ibexport\\Exporter::agentTick(' . $jobId . ');';

        $job = JobTable::getJobById($jobId);
        if (!$job || in_array($job['STATUS'], [JobTable::STATUS_DONE, JobTable::STATUS_ERROR], true)) {
            \CAgent::RemoveAgent($call, 'vspace.ibexport');
            return '';
        }

        self::runStep($jobId);

        $job = JobTable::getJobById($jobId);
        if (!$job || in_array($job['STATUS'], [JobTable::STATUS_DONE, JobTable::STATUS_ERROR], true)) {
            \CAgent::RemoveAgent($call, 'vspace.ibexport');
            return '';
        }

        return $call;
    }

    /**
     * Выполняет один ограниченный по времени тик работы над заданием
     * (раздел 8: батчами, с бюджетом времени, без разрастания памяти).
     * Безопасно вызывать повторно и "параллельно" — из AJAX-опроса и/или
     * фонового агента одновременно (см. блокировку JobTable::tryLock ниже).
     */
    public static function runStep(int $jobId): array
    {
        $job = JobTable::getJobById($jobId);
        if (!$job) {
            throw new \Exception('Задание экспорта не найдено.');
        }

        if (in_array($job['STATUS'], [JobTable::STATUS_DONE, JobTable::STATUS_ERROR], true)) {
            return self::toProgress($job);
        }

        // Другой тик (агент или второй опрос из браузера) уже работает с
        // этим заданием — просто отдаём текущий прогресс, файл не трогаем.
        if (!JobTable::tryLock($jobId, Options::getTickBudgetSeconds() * 4)) {
            return self::toProgress($job);
        }

        try {
            JobTable::update($jobId, ['STATUS' => JobTable::STATUS_RUNNING]);
            $job['STATUS'] = JobTable::STATUS_RUNNING;

            $deadline = microtime(true) + Options::getTickBudgetSeconds();

            if ($job['ENTITY_TYPE'] === 'element') {
                self::runElementExport($job);
            } else {
                self::runSectionExport($job, $job['MODE'] === 'section_tree', $deadline);
            }

            $job = JobTable::getJobById($jobId);
        } catch (\Throwable $e) {
            JobTable::update($jobId, [
                'STATUS' => JobTable::STATUS_ERROR,
                'ERROR_MESSAGE' => $e->getMessage(),
                'DATE_FINISH' => new DateTime(),
            ]);
            self::logEvent('ERROR', $jobId, $e->getMessage());
            $job = JobTable::getJobById($jobId);
        } finally {
            JobTable::unlock($jobId);
        }

        return self::toProgress($job);
    }

    private static function toProgress(array $job): array
    {
        $totalNodes = max(1, (int)$job['TOTAL_SECTIONS'] + (int)$job['TOTAL_ELEMENTS']);
        $doneNodes = (int)$job['PROCESSED_SECTIONS'] + (int)$job['PROCESSED_ELEMENTS'];
        $progress = $job['STATUS'] === JobTable::STATUS_DONE ? 100 : (int)min(99, round(100 * $doneNodes / $totalNodes));

        return [
            'id' => (int)$job['ID'],
            'status' => $job['STATUS'],
            'stage' => $job['STAGE'],
            'progress' => $progress,
            'processed_sections' => (int)$job['PROCESSED_SECTIONS'],
            'processed_elements' => (int)$job['PROCESSED_ELEMENTS'],
            'total_sections' => (int)$job['TOTAL_SECTIONS'],
            'total_elements' => (int)$job['TOTAL_ELEMENTS'],
            'error_message' => $job['ERROR_MESSAGE'],
            'archive_file' => $job['ARCHIVE_FILE'],
            'archive_size' => (int)$job['ARCHIVE_SIZE'],
        ];
    }

    // ---------------------------------------------------------------
    // режим element (FR-1) — всегда достаточно мал для одного тика
    // ---------------------------------------------------------------

    private static function runElementExport(array $job): void
    {
        $jobId = (int)$job['ID'];
        $tmpDir = self::getTmpDir($jobId);
        $filesDir = $tmpDir . '/files';
        $xmlPath = $tmpDir . '/export.xml';

        $handle = fopen($xmlPath, 'w');
        $w = new XmlStreamWriter($handle);
        $w->raw('<?xml version="1.0" encoding="UTF-8"?>' . "\n");
        $w->openTag('export', [
            'date' => (new DateTime())->format('c'),
            'mode' => 'element',
            'iblock_id' => $job['IBLOCK_ID'],
        ]);

        self::writeElement($w, (int)$job['IBLOCK_ID'], (int)$job['ENTITY_ID'], $job['WITH_FILES'] === 'Y', $filesDir, $jobId);

        $w->closeTag('export');
        fclose($handle);

        JobTable::update($jobId, ['PROCESSED_ELEMENTS' => 1]);
        self::finalize($jobId);
    }

    // ---------------------------------------------------------------
    // режимы раздела (FR-2 / FR-3) — универсальный возобновляемый DFS-обход
    // ---------------------------------------------------------------

    private static function runSectionExport(array $job, bool $recursive, float $deadline): void
    {
        $jobId = (int)$job['ID'];
        $iblockId = (int)$job['IBLOCK_ID'];
        $activeOnly = $job['ACTIVE_ONLY'] === 'Y';
        $withFiles = $job['WITH_FILES'] === 'Y';
        $tmpDir = self::getTmpDir($jobId);
        $filesDir = $tmpDir . '/files';
        $xmlPath = $tmpDir . '/export.xml';
        $batchSize = Options::getBatchSize();

        if ($job['STAGE'] === 'init') {
            $handle = fopen($xmlPath, 'w');
            $w = new XmlStreamWriter($handle);
            $w->raw('<?xml version="1.0" encoding="UTF-8"?>' . "\n");
            $w->openTag('export', [
                'date' => (new DateTime())->format('c'),
                'mode' => $recursive ? 'section_tree' : 'section_single',
                'iblock_id' => $iblockId,
            ]);
            fclose($handle);

            $state = [
                'stack' => [
                    self::newFrame($iblockId, (int)$job['ENTITY_ID'], $activeOnly),
                ],
            ];
            JobTable::update($jobId, ['STAGE' => 'traverse', 'STATE_JSON' => json_encode($state)]);
            $job['STAGE'] = 'traverse';
            $job['STATE_JSON'] = json_encode($state);
        }

        if ($job['STAGE'] === 'traverse') {
            $state = json_decode($job['STATE_JSON'], true);
            $stack = $state['stack'];
            $processedSections = (int)$job['PROCESSED_SECTIONS'];
            $processedElements = (int)$job['PROCESSED_ELEMENTS'];

            $handle = fopen($xmlPath, 'a');
            $w = new XmlStreamWriter($handle, count($stack)); // отступ приблизительный, чисто косметический

            while (!empty($stack) && microtime(true) < $deadline) {
                $i = count($stack) - 1;
                $frame = &$stack[$i];

                if (!$frame['opened']) {
                    self::writeSectionOpen($w, $iblockId, $frame['section_id'], $withFiles, $filesDir, $jobId);
                    $frame['opened'] = true;
                    $processedSections++;
                }

                if ($frame['phase'] === 'elements') {
                    if (!$frame['elements_tag_open']) {
                        $w->openTag('elements');
                        $frame['elements_tag_open'] = true;
                    }

                    $rows = self::fetchElementsPage($iblockId, $frame['section_id'], $activeOnly, $batchSize, $frame['elements_offset']);
                    foreach ($rows as $row) {
                        self::writeElementRow($w, $row, $withFiles, $filesDir, $jobId);
                        $processedElements++;
                    }
                    $frame['elements_offset'] += count($rows);

                    if (count($rows) < $batchSize) {
                        $w->closeTag('elements');
                        $frame['phase'] = 'children';
                    }
                } elseif ($frame['phase'] === 'children') {
                    if (!$recursive) {
                        // section_single: фиксируем только ID/код прямых подразделов, без рекурсии (FR-2).
                        self::writeSubsectionStubs($w, $iblockId, $frame['section_id'], $activeOnly);
                        $frame['phase'] = 'done';
                    } elseif ($frame['children_ids'] === null) {
                        $frame['children_ids'] = self::getDirectChildSectionIds($iblockId, $frame['section_id'], $activeOnly);
                        $frame['children_index'] = 0;
                        if (!empty($frame['children_ids'])) {
                            $w->openTag('sections');
                            $frame['sections_tag_open'] = true;
                        }
                    } elseif ($frame['children_index'] < count($frame['children_ids'])) {
                        $childId = $frame['children_ids'][$frame['children_index']];
                        $frame['children_index']++;
                        $stack[] = self::newFrame($iblockId, $childId, $activeOnly);
                    } else {
                        if (!empty($frame['sections_tag_open'])) {
                            $w->closeTag('sections');
                        }
                        $frame['phase'] = 'done';
                    }
                } else { // done — раздел полностью обработан
                    $w->closeTag('section');
                    array_pop($stack);
                }
                unset($frame);
            }

            fclose($handle);

            JobTable::update($jobId, [
                'PROCESSED_SECTIONS' => $processedSections,
                'PROCESSED_ELEMENTS' => $processedElements,
                'STATE_JSON' => json_encode(['stack' => $stack]),
                'STAGE' => empty($stack) ? 'finalize' : 'traverse',
            ]);

            $job['STAGE'] = empty($stack) ? 'finalize' : 'traverse';
        }

        if ($job['STAGE'] === 'finalize') {
            $handle = fopen($xmlPath, 'a');
            fwrite($handle, "</export>\n");
            fclose($handle);
            self::finalize($jobId);
        }
    }

    private static function newFrame(int $iblockId, int $sectionId, bool $activeOnly): array
    {
        return [
            'section_id' => $sectionId,
            'opened' => false,
            'phase' => 'elements',
            'elements_offset' => 0,
            'elements_tag_open' => false,
            'children_ids' => null,
            'children_index' => 0,
            'sections_tag_open' => false,
        ];
    }

    private static function fetchElementsPage(int $iblockId, int $sectionId, bool $activeOnly, int $limit, int $offset): array
    {
        $filter = ['IBLOCK_ID' => $iblockId, 'IBLOCK_SECTION_ID' => $sectionId];
        if ($activeOnly) {
            $filter['ACTIVE'] = 'Y';
        }

        $rows = [];
        $res = ElementTable::getList([
            'filter' => $filter,
            'select' => ['ID'],
            'order' => ['SORT' => 'ASC', 'ID' => 'ASC'],
            'limit' => $limit,
            'offset' => $offset,
        ]);
        while ($row = $res->fetch()) {
            $rows[] = (int)$row['ID'];
        }

        return $rows;
    }

    private static function writeSubsectionStubs(XmlStreamWriter $w, int $iblockId, int $sectionId, bool $activeOnly): void
    {
        $filter = ['IBLOCK_ID' => $iblockId, 'IBLOCK_SECTION_ID' => $sectionId];
        if ($activeOnly) {
            $filter['ACTIVE'] = 'Y';
        }
        $res = SectionTable::getList([
            'filter' => $filter,
            'select' => ['ID', 'CODE', 'NAME', 'ACTIVE'],
            'order' => ['SORT' => 'ASC'],
            'limit' => 5000,
        ]);

        $w->openTag('subsections', ['note' => 'not_included_see_mode']);
        while ($row = $res->fetch()) {
            $w->openTag('section', [
                'id' => $row['ID'],
                'code' => $row['CODE'],
                'active' => $row['ACTIVE'],
            ], true);
        }
        $w->closeTag('subsections');
    }

    private static function writeSectionOpen(XmlStreamWriter $w, int $iblockId, int $sectionId, bool $withFiles, string $filesDir, int $jobId): void
    {
        $section = CIBlockSection::GetByID($sectionId)->GetNext();
        if (!$section) {
            throw new \Exception('Раздел #' . $sectionId . ' не найден.');
        }

        $w->openTag('section', [
            'id' => $section['ID'],
            'code' => $section['CODE'],
            'active' => $section['ACTIVE'],
            'sort' => $section['SORT'],
        ]);
        $w->textTag('name', $section['NAME']);
        $w->textTag('description', $section['DESCRIPTION'], [], true);
        self::writeFileRef($w, 'picture', (int)$section['PICTURE'], $withFiles, $filesDir, $jobId);

        $w->openTag('properties');
        foreach ($section as $key => $value) {
            if (strpos($key, 'UF_') === 0 && $value !== '' && $value !== null) {
                $w->textTag('property', is_array($value) ? implode(', ', $value) : (string)$value, ['code' => $key]);
            }
        }
        $w->closeTag('properties');
    }

    private static function writeElement(XmlStreamWriter $w, int $iblockId, int $elementId, bool $withFiles, string $filesDir, int $jobId): void
    {
        $res = ElementTable::getList(['filter' => ['IBLOCK_ID' => $iblockId, 'ID' => $elementId], 'select' => ['ID'], 'limit' => 1]);
        if (!$res->fetch()) {
            throw new \Exception('Элемент #' . $elementId . ' не найден.');
        }
        self::writeElementRow($w, $elementId, $withFiles, $filesDir, $jobId, true);
    }

    private static function writeElementRow(XmlStreamWriter $w, int $elementId, bool $withFiles, string $filesDir, int $jobId, bool $withSections = false): void
    {
        $el = CIBlockElement::GetByID($elementId)->GetNext();
        if (!$el) {
            self::addWarning($jobId, 'Элемент #' . $elementId . ' не найден, пропущен.');
            return;
        }

        $w->openTag('element', [
            'id' => $el['ID'],
            'code' => $el['CODE'],
            'active' => $el['ACTIVE'],
            'sort' => $el['SORT'],
        ]);
        $w->textTag('name', $el['NAME']);
        $w->textTag('preview_text', $el['PREVIEW_TEXT'], ['type' => $el['PREVIEW_TEXT_TYPE'] ?: 'text'], true);
        $w->textTag('detail_text', $el['DETAIL_TEXT'], ['type' => $el['DETAIL_TEXT_TYPE'] ?: 'text'], true);
        $w->textTag('date_active_from', $el['DATE_ACTIVE_FROM']);
        $w->textTag('date_active_to', $el['DATE_ACTIVE_TO']);

        self::writeFileRef($w, 'preview_picture', (int)$el['PREVIEW_PICTURE'], $withFiles, $filesDir, $jobId);
        self::writeFileRef($w, 'detail_picture', (int)$el['DETAIL_PICTURE'], $withFiles, $filesDir, $jobId);

        if ($withSections) {
            $w->openTag('sections');
            $groups = CIBlockElement::GetElementGroups($elementId, true);
            while ($sec = $groups->Fetch()) {
                $w->openTag('section', [
                    'id' => $sec['ID'],
                    'code' => $sec['CODE'],
                    'path' => self::getSectionPath((int)$sec['IBLOCK_ID'], (int)$sec['ID']),
                ], true);
            }
            $w->closeTag('sections');
        }

        self::writeProperties($w, (int)$el['IBLOCK_ID'], $elementId, $withFiles, $filesDir, $jobId);

        $w->closeTag('element');
    }

    private static function writeProperties(XmlStreamWriter $w, int $iblockId, int $elementId, bool $withFiles, string $filesDir, int $jobId): void
    {
        $grouped = [];
        $props = CIBlockElement::GetProperty($iblockId, $elementId, ['sort' => 'asc'], []);
        while ($p = $props->Fetch()) {
            $code = $p['CODE'] !== '' ? $p['CODE'] : $p['ID'];
            if (!isset($grouped[$code])) {
                $grouped[$code] = ['meta' => $p, 'values' => []];
            }
            if ($p['VALUE'] === '' || $p['VALUE'] === null || $p['VALUE'] === false) {
                continue;
            }
            $grouped[$code]['values'][] = $p;
        }

        $w->openTag('properties');
        foreach ($grouped as $code => $data) {
            $meta = $data['meta'];
            $values = $data['values'];
            $multiple = $meta['MULTIPLE'] === 'Y';
            $type = $meta['PROPERTY_TYPE'];

            if (empty($values)) {
                $w->openTag('property', ['code' => $code, 'type' => $type], true);
                continue;
            }

            if (!$multiple && count($values) === 1 && $type !== 'F') {
                $display = self::propertyDisplayValue($values[0]);
                $w->textTag('property', $display, ['code' => $code, 'type' => $type]);
                continue;
            }

            $w->openTag('property', ['code' => $code, 'type' => $type, 'multiple' => 'true']);
            foreach ($values as $v) {
                if ($type === 'F') {
                    self::writeFileRef($w, 'file', (int)$v['VALUE'], $withFiles, $filesDir, $jobId);
                } else {
                    $w->textTag('value', self::propertyDisplayValue($v));
                }
            }
            $w->closeTag('property');
        }
        $w->closeTag('properties');
    }

    private static function propertyDisplayValue(array $p): string
    {
        if (isset($p['VALUE_ENUM']) && $p['VALUE_ENUM'] !== '') {
            return $p['VALUE_ENUM'];
        }
        if (is_array($p['VALUE']) && isset($p['VALUE']['TEXT'])) {
            return (string)$p['VALUE']['TEXT'];
        }
        return is_scalar($p['VALUE']) ? (string)$p['VALUE'] : '';
    }

    private static function getSectionPath(int $iblockId, int $sectionId): string
    {
        $chain = [];
        $res = CIBlockSection::GetNavChain($iblockId, $sectionId);
        while ($row = $res->Fetch()) {
            $chain[] = $row['NAME'];
        }
        return implode(' > ', $chain);
    }

    private static function writeFileRef(XmlStreamWriter $w, string $tag, int $fileId, bool $withFiles, string $filesDir, int $jobId): void
    {
        if (!$fileId) {
            $w->openTag($tag, [], true);
            return;
        }

        $fileArr = CFile::GetFileArray($fileId);
        if (!$fileArr || empty($fileArr['SRC'])) {
            $w->openTag($tag, ['missing' => 'Y', 'file_id' => $fileId], true);
            self::addWarning($jobId, 'Файл #' . $fileId . ' не найден, пропущен.');
            return;
        }

        $attrs = [
            'name' => $fileArr['FILE_NAME'],
            'size' => $fileArr['FILE_SIZE'],
            'mime' => $fileArr['CONTENT_TYPE'],
        ];

        if ($withFiles) {
            $destName = $fileId . '_' . preg_replace('~[^A-Za-z0-9._-]+~u', '_', $fileArr['FILE_NAME']);
            $destPath = $filesDir . '/' . $destName;
            $srcPath = $_SERVER['DOCUMENT_ROOT'] . $fileArr['SRC'];

            try {
                if (is_file($srcPath)) {
                    if (!is_dir($filesDir)) {
                        mkdir($filesDir, 0755, true);
                    }
                    if (!copy($srcPath, $destPath)) {
                        throw new \Exception('ошибка copy()');
                    }
                    $attrs['file_ref'] = 'files/' . $destName;
                } else {
                    throw new \Exception('исходный файл отсутствует на диске');
                }
            } catch (\Throwable $e) {
                self::addWarning($jobId, 'Не удалось скопировать файл #' . $fileId . ' (' . $fileArr['FILE_NAME'] . '): ' . $e->getMessage());
            }
        }

        $w->openTag($tag, $attrs, true);
    }

    private static function addWarning(int $jobId, string $message): void
    {
        $job = JobTable::getJobById($jobId);
        $warnings = $job['WARNINGS_JSON'] ? json_decode($job['WARNINGS_JSON'], true) : [];
        $warnings[] = $message;
        JobTable::update($jobId, ['WARNINGS_JSON' => json_encode($warnings, JSON_UNESCAPED_UNICODE)]);
    }

    // ---------------------------------------------------------------
    // финализация / архив / временные файлы / журнал (разделы 5, 6, 8, 9)
    // ---------------------------------------------------------------

    private static function finalize(int $jobId): void
    {
        $job = JobTable::getJobById($jobId);
        $tmpDir = self::getTmpDir($jobId);
        $archiveName = self::buildArchiveName($job);
        $archivePath = $tmpDir . '/' . $archiveName;

        $zip = new \ZipArchive();
        if ($zip->open($archivePath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            throw new \Exception('Не удалось создать архив выгрузки.');
        }

        $zip->addFile($tmpDir . '/export.xml', 'export.xml');

        $filesDir = $tmpDir . '/files';
        if (is_dir($filesDir)) {
            foreach (scandir($filesDir) as $f) {
                if ($f === '.' || $f === '..') {
                    continue;
                }
                $zip->addFile($filesDir . '/' . $f, 'files/' . $f);
            }
        }
        $zip->close();

        $ttl = Options::getTtlHours();

        JobTable::update($jobId, [
            'STATUS' => JobTable::STATUS_DONE,
            'STAGE' => 'done',
            'ARCHIVE_FILE' => $archiveName,
            'ARCHIVE_SIZE' => filesize($archivePath) ?: 0,
            'DATE_FINISH' => new DateTime(),
            'DATE_EXPIRE' => DateTime::createFromTimestamp(time() + $ttl * 3600),
        ]);

        self::logEvent('DONE', $jobId, '');
    }

    /**
     * Человекочитаемое имя архива вместо голого "export_<ID>.zip": инфоблок,
     * тип и код/название сущности, режим для разделов — чтобы по имени файла
     * в списке скачанных архивов было понятно, что внутри, без открытия XML.
     * ID задания остаётся в конце как гарантия уникальности имени.
     */
    private static function buildArchiveName(array $job): string
    {
        $iblock = \CIBlock::GetArrayByID((int)$job['IBLOCK_ID']) ?: [];
        $iblockSlug = self::slug($iblock['CODE'] ?? '', 'iblock' . $job['IBLOCK_ID']);

        $entityType = $job['ENTITY_TYPE'];
        $entityId = (int)$job['ENTITY_ID'];
        $entitySlug = self::slug(self::resolveEntityName($entityType, $entityId), (string)$entityId);

        $parts = [$iblockSlug, $entityType, $entitySlug, $entityId];

        if ($job['MODE'] === 'section_tree') {
            $parts[] = 'tree';
        }

        $parts[] = 'job' . $job['ID'];

        return implode('_', $parts) . '.zip';
    }

    private static function resolveEntityName(string $entityType, int $entityId): string
    {
        if ($entityType === 'element') {
            $el = CIBlockElement::GetByID($entityId)->GetNext();
            return $el ? (string)($el['CODE'] ?: $el['NAME']) : '';
        }

        $section = CIBlockSection::GetByID($entityId)->GetNext();
        return $section ? (string)($section['CODE'] ?: $section['NAME']) : '';
    }

    private static function slug(string $value, string $fallback): string
    {
        $value = trim($value);
        if ($value === '') {
            return $fallback;
        }

        $lang = defined('LANGUAGE_ID') ? LANGUAGE_ID : 'ru';
        $slug = \CUtil::translit($value, $lang, [
            'max_len' => 40,
            'change_case' => 'L',
            'replace_space' => '-',
            'replace_other' => '-',
        ]);

        return $slug !== '' ? $slug : $fallback;
    }

    public static function getTmpDir(int $jobId): string
    {
        $job = JobTable::getJobById($jobId);
        return $_SERVER['DOCUMENT_ROOT'] . VSPACE_IBEXPORT_TMP_DIR . '/' . $job['TMP_DIR'];
    }

    private static function ensureTmpDir(int $jobId): void
    {
        $dir = self::getTmpDir($jobId);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        if (!is_dir($dir . '/files')) {
            mkdir($dir . '/files', 0755, true);
        }
    }

    private static function logEvent(string $type, int $jobId, string $message): void
    {
        \CEventLog::Add([
            'SEVERITY' => $type === 'ERROR' ? 'ERROR' : 'INFO',
            'AUDIT_TYPE_ID' => 'VSPACE_IBEXPORT_' . $type,
            'MODULE_ID' => 'vspace.ibexport',
            'ITEM_ID' => $jobId,
            'DESCRIPTION' => $message,
        ]);
    }

    /** Постоянный агент CAgent: удаляет просроченные каталоги и записи заданий (раздел 8). */
    public static function cleanupAgent(): string
    {
        $rows = JobTable::getList([
            'filter' => ['<DATE_EXPIRE' => new DateTime()],
            'select' => ['ID', 'TMP_DIR'],
            'limit' => 200,
        ]);

        while ($row = $rows->fetch()) {
            $dir = $_SERVER['DOCUMENT_ROOT'] . VSPACE_IBEXPORT_TMP_DIR . '/' . $row['TMP_DIR'];
            if (is_dir($dir)) {
                self::rrmdir($dir);
            }
            JobTable::delete($row['ID']);
        }

        return '\\Vspace\\Ibexport\\Exporter::cleanupAgent();';
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
