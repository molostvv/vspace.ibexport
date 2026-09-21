<?php

namespace Vspace\Ibexport\Tests\Import\Fake;

use SimpleXMLElement;
use Vspace\Ibexport\Import\ImportContext;
use Vspace\Ibexport\Import\ImportReport;
use Vspace\Ibexport\Import\SectionImporter;

/** Вместо CIBlockSection::Add() запоминает вызов и выдаёт следующий ID (101, 102, ...). */
final class RecordingSectionImporter extends SectionImporter
{
    private int $nextId = 101;

    public function __construct(private CallLog $log)
    {
        // родительский конструктор требует фабрику файлов, которая тут не нужна
    }

    public function import(SimpleXMLElement $node, ?int $parentId, ImportContext $ctx, ImportReport $report): int
    {
        $this->log->add('section:' . (string)$node['code'] . ':parent=' . ($parentId ?? '-'));
        $report->created++;

        return $this->nextId++;
    }
}
