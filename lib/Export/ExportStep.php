<?php

namespace Vspace\Ibexport\Export;

use Bitrix\Main\Type\DateTime;
use Vspace\Ibexport\JobEventLog;
use Vspace\Ibexport\JobTable;
use Vspace\Ibexport\Options;
use Vspace\Ibexport\XmlStreamWriter;

/**
 * Специфичная для экспорта часть одного тика (колбэк TickRunner): по строке
 * задания собирает писателей и обход, ведёт файл export.xml через стадии
 * init -> traverse -> finalize и сохраняет прогресс/курсор возобновления
 * (STATE_JSON) в JobTable. Общая механика тика (блокировка, ошибки, агент)
 * — в TickRunner.
 */
final class ExportStep
{
    public function __construct(private JobEventLog $eventLog)
    {
    }

    public function run(array $job, float $deadline): void
    {
        if ($job['ENTITY_TYPE'] === 'element') {
            $this->runElementExport($job);
        } else {
            $this->runSectionExport($job, $job['MODE'] === 'section_tree', $deadline);
        }
    }

    // ---------------------------------------------------------------
    // режим element (FR-1) — всегда достаточно мал для одного тика
    // ---------------------------------------------------------------

    private function runElementExport(array $job): void
    {
        $jobId = (int)$job['ID'];
        $tmpDir = JobTable::getTmpPath($job);
        $xmlPath = $tmpDir . '/export.xml';
        $ctx = $this->buildContext($job, $tmpDir);

        $handle = fopen($xmlPath, 'w');
        $w = new XmlStreamWriter($handle);
        $w->raw('<?xml version="1.0" encoding="UTF-8"?>' . "\n");
        $w->openTag('export', [
            'date' => (new DateTime())->format('c'),
            'mode' => 'element',
            'iblock_id' => $job['IBLOCK_ID'],
        ]);

        $this->buildElementWriter($jobId, $ctx)->writeSingle($w, $ctx->iblockId, (int)$job['ENTITY_ID']);

        $w->closeTag('export');
        fclose($handle);

        JobTable::update($jobId, ['PROCESSED_ELEMENTS' => 1]);
        $this->finalize($job, $tmpDir);
    }

    // ---------------------------------------------------------------
    // режимы раздела (FR-2 / FR-3) — универсальный возобновляемый DFS-обход
    // ---------------------------------------------------------------

    private function runSectionExport(array $job, bool $recursive, float $deadline): void
    {
        $jobId = (int)$job['ID'];
        $tmpDir = JobTable::getTmpPath($job);
        $xmlPath = $tmpDir . '/export.xml';
        $ctx = $this->buildContext($job, $tmpDir);

        if ($job['STAGE'] === 'init') {
            $handle = fopen($xmlPath, 'w');
            $w = new XmlStreamWriter($handle);
            $w->raw('<?xml version="1.0" encoding="UTF-8"?>' . "\n");
            $w->openTag('export', [
                'date' => (new DateTime())->format('c'),
                'mode' => $recursive ? 'section_tree' : 'section_single',
                'iblock_id' => $ctx->iblockId,
            ]);
            fclose($handle);

            $state = ['stack' => SectionTreeWalker::initialStack((int)$job['ENTITY_ID'])];
            JobTable::update($jobId, ['STAGE' => 'traverse', 'STATE_JSON' => json_encode($state)]);
            $job['STAGE'] = 'traverse';
            $job['STATE_JSON'] = json_encode($state);
        }

        if ($job['STAGE'] === 'traverse') {
            $state = json_decode($job['STATE_JSON'], true);
            $stack = $state['stack'];

            $handle = fopen($xmlPath, 'a');
            $w = new XmlStreamWriter($handle, count($stack)); // отступ приблизительный, чисто косметический

            $walker = new SectionTreeWalker(
                new BitrixTreeSource(),
                new SectionWriter($this->buildFileWriter($jobId, $ctx)),
                $this->buildElementWriter($jobId, $ctx)
            );
            $result = $walker->walk($w, $stack, $ctx, $recursive, $deadline);

            fclose($handle);

            JobTable::update($jobId, [
                'PROCESSED_SECTIONS' => (int)$job['PROCESSED_SECTIONS'] + $result->processedSections,
                'PROCESSED_ELEMENTS' => (int)$job['PROCESSED_ELEMENTS'] + $result->processedElements,
                'STATE_JSON' => json_encode(['stack' => $result->stack]),
                'STAGE' => $result->isFinished() ? 'finalize' : 'traverse',
            ]);

            $job['STAGE'] = $result->isFinished() ? 'finalize' : 'traverse';
        }

        if ($job['STAGE'] === 'finalize') {
            $handle = fopen($xmlPath, 'a');
            fwrite($handle, "</export>\n");
            fclose($handle);
            $this->finalize($job, $tmpDir);
        }
    }

    // ---------------------------------------------------------------
    // финализация: архив, статус, срок хранения
    // ---------------------------------------------------------------

    private function finalize(array $job, string $tmpDir): void
    {
        $jobId = (int)$job['ID'];
        $archive = (new ArchiveBuilder())->build($job, $tmpDir);

        $ttl = Options::getTtlHours();

        JobTable::update($jobId, [
            'STATUS' => JobTable::STATUS_DONE,
            'STAGE' => 'done',
            'ARCHIVE_FILE' => $archive['name'],
            'ARCHIVE_SIZE' => $archive['size'],
            'DATE_FINISH' => new DateTime(),
            'DATE_EXPIRE' => DateTime::createFromTimestamp(time() + $ttl * 3600),
        ]);

        $this->eventLog->done($jobId);
    }

    // ---------------------------------------------------------------
    // сборка зависимостей тика из строки задания
    // ---------------------------------------------------------------

    private function buildContext(array $job, string $tmpDir): ExportContext
    {
        return new ExportContext(
            (int)$job['IBLOCK_ID'],
            $job['ACTIVE_ONLY'] === 'Y',
            $job['WITH_FILES'] === 'Y',
            $tmpDir . '/files',
            Options::getBatchSize()
        );
    }

    private function buildFileWriter(int $jobId, ExportContext $ctx): FileRefWriter
    {
        return new FileRefWriter($ctx->withFiles, $ctx->filesDir, $this->warningSink($jobId));
    }

    private function buildElementWriter(int $jobId, ExportContext $ctx): ElementWriter
    {
        return new ElementWriter($this->buildFileWriter($jobId, $ctx), $this->warningSink($jobId));
    }

    /** @return \Closure(string): void */
    private function warningSink(int $jobId): \Closure
    {
        return static function (string $message) use ($jobId): void {
            JobTable::addWarning($jobId, $message);
        };
    }
}
