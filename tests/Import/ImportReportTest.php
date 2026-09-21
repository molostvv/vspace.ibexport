<?php

namespace Vspace\Ibexport\Tests\Import;

use PHPUnit\Framework\TestCase;
use Vspace\Ibexport\Import\ImportReport;

final class ImportReportTest extends TestCase
{
    public function testStartsEmpty(): void
    {
        $report = new ImportReport();

        $this->assertSame(['created' => 0, 'updated' => 0, 'skipped' => 0], $report->toCounts());
        $this->assertSame([], $report->getWarnings());
    }

    public function testCountersRoundTripThroughStateJson(): void
    {
        $report = new ImportReport();
        $report->created = 5;
        $report->updated = 2;
        $report->skipped = 1;

        $json = json_encode(['counts' => $report->toCounts()]);
        $restored = ImportReport::withCounts(json_decode($json, true)['counts']);

        $this->assertSame($report->toCounts(), $restored->toCounts());
        $this->assertSame('{"counts":{"created":5,"updated":2,"skipped":1}}', $json, 'STATE_JSON counts format is unchanged');
    }

    public function testMissingCountersDefaultToZero(): void
    {
        // STATE_JSON первого тика ещё не содержит counts
        $this->assertSame(['created' => 0, 'updated' => 0, 'skipped' => 0], ImportReport::withCounts([])->toCounts());
        $this->assertSame(['created' => 3, 'updated' => 0, 'skipped' => 0], ImportReport::withCounts(['created' => '3'])->toCounts());
    }

    public function testIdenticalWarningsAreCountedOnce(): void
    {
        $report = new ImportReport();
        $report->addWarning('missing X');
        $report->addWarning('other');
        $report->addWarning('missing X');
        $report->addWarning('missing X');

        $this->assertSame(['missing X', 'other'], $report->getWarnings());
        $this->assertSame(['missing X' => 3, 'other' => 1], $report->getWarningCounts());
    }

    public function testWarningsAreKeptInOrderAndNotPersistedInCounts(): void
    {
        $report = new ImportReport();
        $report->addWarning('first');
        $report->addWarning('second');

        $this->assertSame(['first', 'second'], $report->getWarnings());
        $this->assertArrayNotHasKey('warnings', $report->toCounts());
        $this->assertSame([], ImportReport::withCounts($report->toCounts())->getWarnings(), 'warnings are flushed to the job every tick, not carried in STATE_JSON');
    }
}
