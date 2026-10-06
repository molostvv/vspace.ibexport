<?php

namespace Vspace\Ibexport\Export;

use Bitrix\Main\Type\DateTime;
use Vspace\Ibexport\JobEventLog;
use Vspace\Ibexport\JobTable;
use Vspace\Ibexport\Options;
use Vspace\Ibexport\XmlStreamWriter;
use Vspace\Ibexport\YandexDisk\JobUploader;

/**
 * Специфичная для экспорта часть одного тика (колбэк TickRunner): по строке
 * задания собирает писателей и обход, ведёт файл export.xml через стадии
 * init -> traverse -> finalize и сохраняет прогресс/курсор возобновления
 * (STATE_JSON) в JobTable. Общая механика тика (блокировка, ошибки, агент)
 * — в TickRunner.
 */
final class ExportStep
{
    /**
     * Версия формата export.xml (атрибут version корня; docs/xml-format.md). 2 — с версии модуля 1.1.0: значения
     * без HTML-экранирования, даты в ISO 8601, ключи связанных записей у свойств-привязок, описания значений,
     * main_section у элементов. 3 — с версии 1.2.0: SEO-шаблоны записей (<seo>). В архивах до 1.1.0 атрибута нет.
     */
    public const FORMAT_VERSION = 3;

    /** Первая версия формата без HTML-экранирования значений; архивы старее страница импорта помечает как устаревшие. */
    public const FORMAT_VERSION_UNESCAPED = 2;

    /** Первая версия формата с SEO-шаблонами; в архивах старее их нет, и импорт SEO записей не меняет. */
    public const FORMAT_VERSION_SEO = 3;

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
            'version' => self::FORMAT_VERSION,
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
                'version' => self::FORMAT_VERSION,
                'date' => (new DateTime())->format('c'),
                'mode' => $recursive ? 'section_tree' : 'section_single',
                'iblock_id' => $ctx->iblockId,
            ]);
            fclose($handle);

            $stateJson = json_encode(['stack' => ExportFrame::stackToArray(SectionTreeWalker::initialStack((int)$job['ENTITY_ID']))]);
            JobTable::update($jobId, ['STAGE' => 'traverse', 'STATE_JSON' => $stateJson]);
            $job['STAGE'] = 'traverse';
            $job['STATE_JSON'] = $stateJson;
        }

        if ($job['STAGE'] === 'traverse') {
            $state = json_decode($job['STATE_JSON'], true);
            $stack = ExportFrame::stackFromArray($state['stack']);

            $handle = fopen($xmlPath, 'a');
            $w = new XmlStreamWriter($handle, count($stack)); // отступ приблизительный, чисто косметический

            $walker = new SectionTreeWalker(
                new BitrixTreeSource(),
                new SectionWriter($this->buildFileWriter($jobId, $ctx), $this->warningSink($jobId)),
                $this->buildElementWriter($jobId, $ctx)
            );
            $result = $walker->walk($w, $stack, $ctx, $recursive, $deadline);

            fclose($handle);

            JobTable::update($jobId, [
                'PROCESSED_SECTIONS' => (int)$job['PROCESSED_SECTIONS'] + $result->processedSections,
                'PROCESSED_ELEMENTS' => (int)$job['PROCESSED_ELEMENTS'] + $result->processedElements,
                'STATE_JSON' => json_encode(['stack' => ExportFrame::stackToArray($result->stack)]),
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

        JobTable::update($jobId, [
            'STATUS' => JobTable::STATUS_DONE,
            'STAGE' => 'done',
            'ARCHIVE_FILE' => $archive['name'],
            'ARCHIVE_SIZE' => $archive['size'],
            'DATE_FINISH' => new DateTime(),
            'DATE_EXPIRE' => JobTable::expireDate(),
        ]);

        $this->eventLog->done($jobId);

        // Сразу на Яндекс.Диск (настройка модуля, по умолчанию включена, если Диск подключён). Задание уже завершено и
        // архив готов: сбой выгрузки экспорт не роняет — результат (JobUploadTable) виден на странице прогресса, там же
        // кнопка "Выгрузить в Яндекс.Диск" для повтора.
        if (JobUploader::isAutoEnabled()) {
            try {
                JobUploader::upload(['ARCHIVE_FILE' => $archive['name']] + $job);
            } catch (\Throwable $e) {
                // причина уже записана JobUploader в JobUploadTable
            }
        }
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
