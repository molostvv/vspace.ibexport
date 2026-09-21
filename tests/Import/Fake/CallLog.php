<?php

namespace Vspace\Ibexport\Tests\Import\Fake;

/** Общий хронологический журнал вызовов поддельных импортёров — по нему проверяется порядок обхода. */
final class CallLog
{
    /** @var string[] */
    public array $entries = [];

    public function add(string $entry): void
    {
        $this->entries[] = $entry;
    }
}
