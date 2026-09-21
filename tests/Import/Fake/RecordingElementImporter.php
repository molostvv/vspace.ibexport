<?php

namespace Vspace\Ibexport\Tests\Import\Fake;

use SimpleXMLElement;
use Vspace\Ibexport\Import\ElementImporter;
use Vspace\Ibexport\Import\ImportContext;
use Vspace\Ibexport\Import\ImportReport;

/** Вместо CIBlockElement::Add() запоминает вызов. */
final class RecordingElementImporter extends ElementImporter
{
    public function __construct(private CallLog $log)
    {
        // родительский конструктор требует фабрику файлов и PropertyResolver, которые тут не нужны
    }

    public function import(SimpleXMLElement $node, ?int $sectionId, ImportContext $ctx, ImportReport $report, bool $withSections): void
    {
        $this->log->add('element:' . (string)$node['code'] . ':section=' . ($sectionId ?? '-') . ($withSections ? ':withSections' : ''));
        $report->created++;
    }
}
