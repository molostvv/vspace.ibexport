<?php

namespace Vspace\Ibexport\Import;

use Bitrix\Main\Type\DateTime;
use SimpleXMLElement;
use Vspace\Ibexport\ImportJobTable;
use Vspace\Ibexport\JobEventLog;
use Vspace\Ibexport\Options;

/**
 * Специфичная для импорта часть одного тика (колбэк TickRunner): разбирает
 * распакованный export.xml, собирает импортёров и обход, применяет порцию
 * данных и сохраняет прогресс/счётчики/курсор возобновления (STATE_JSON) в
 * ImportJobTable. Общая механика тика (блокировка, ошибки, агент) — в TickRunner.
 */
final class ImportStep
{
    public function __construct(private JobEventLog $eventLog)
    {
    }

    public function run(array $job, float $deadline): void
    {
        $tmpDir = ImportJobTable::getTmpPath($job);
        $xml = simplexml_load_file($tmpDir . '/export.xml');

        if ($job['MODE'] === 'element') {
            $this->runElementImport($job, $xml, $tmpDir);
        } else {
            $this->runSectionImport($job, $xml, $tmpDir, $job['MODE'] === 'section_tree', $deadline);
        }
    }

    // ---------------------------------------------------------------
    // режим element — всегда достаточно мал для одного тика
    // ---------------------------------------------------------------

    private function runElementImport(array $job, SimpleXMLElement $xml, string $tmpDir): void
    {
        $jobId = (int)$job['ID'];
        $ctx = $this->buildContext($job, $tmpDir);
        $report = new ImportReport();

        $this->buildElementImporter()->import($xml->element, null, $ctx, $report, true);

        $this->flushWarnings($jobId, $report);

        ImportJobTable::update($jobId, [
            'PROCESSED_ELEMENTS' => 1,
            'CREATED_COUNT' => $report->created,
            'UPDATED_COUNT' => $report->updated,
            'SKIPPED_COUNT' => $report->skipped,
        ]);
        $this->finalize($jobId);
    }

    // ---------------------------------------------------------------
    // режимы раздела — универсальный возобновляемый DFS-обход
    // (Import\SectionTreeWalker), только источник — уже распакованный XML
    // ---------------------------------------------------------------

    private function runSectionImport(array $job, SimpleXMLElement $xml, string $tmpDir, bool $recursive, float $deadline): void
    {
        $jobId = (int)$job['ID'];
        $ctx = $this->buildContext($job, $tmpDir);
        $parentSectionId = (int)$job['PARENT_SECTION_ID'] ?: null;

        $state = $job['STATE_JSON'] ? json_decode($job['STATE_JSON'], true) : null;
        $stack = isset($state['stack']) ? ImportFrame::stackFromArray($state['stack']) : SectionTreeWalker::initialStack();
        $report = ImportReport::withCounts($state['counts'] ?? []);

        $source = new BitrixPropertySource();
        $walker = new SectionTreeWalker(
            new SectionImporter(new BitrixFileArrayFactory(), $source),
            $this->buildElementImporter($source)
        );
        $result = $walker->walk($xml->section, $stack, $parentSectionId, $recursive, $ctx, $report, $deadline);

        $this->flushWarnings($jobId, $report);

        ImportJobTable::update($jobId, [
            'PROCESSED_SECTIONS' => (int)$job['PROCESSED_SECTIONS'] + $result->processedSections,
            'PROCESSED_ELEMENTS' => (int)$job['PROCESSED_ELEMENTS'] + $result->processedElements,
            'CREATED_COUNT' => $report->created,
            'UPDATED_COUNT' => $report->updated,
            'SKIPPED_COUNT' => $report->skipped,
            'STATE_JSON' => json_encode(['stack' => ImportFrame::stackToArray($result->stack), 'counts' => $report->toCounts()]),
            'STAGE' => $result->isFinished() ? 'finalize' : 'traverse',
        ]);

        if ($result->isFinished()) {
            $this->finalize($jobId);
        }
    }

    // ---------------------------------------------------------------
    // финализация / сборка зависимостей тика
    // ---------------------------------------------------------------

    private function finalize(int $jobId): void
    {
        $ttl = Options::getTtlHours();

        ImportJobTable::update($jobId, [
            'STATUS' => ImportJobTable::STATUS_DONE,
            'STAGE' => 'done',
            'DATE_FINISH' => new DateTime(),
            'DATE_EXPIRE' => DateTime::createFromTimestamp(time() + $ttl * 3600),
        ]);

        $this->eventLog->done($jobId);
    }

    private function buildContext(array $job, string $tmpDir): ImportContext
    {
        return new ImportContext((int)$job['TARGET_IBLOCK_ID'], $job['UPDATE_BY_CODE'] === 'Y', $tmpDir, ($job['MATCH_BY_XML_ID'] ?? 'N') === 'Y');
    }

    private function buildElementImporter(?BitrixPropertySource $source = null): ElementImporter
    {
        $files = new BitrixFileArrayFactory();

        return new ElementImporter($files, new PropertyResolver($source ?? new BitrixPropertySource(), $files));
    }

    /** Предупреждения тика — в WARNINGS_JSON задания (в конце тика: при исключении посреди тика не сохраняются). */
    private function flushWarnings(int $jobId, ImportReport $report): void
    {
        foreach ($report->getWarningCounts() as $warning => $count) {
            ImportJobTable::addWarning($jobId, (string)$warning, $count);
        }
    }
}
