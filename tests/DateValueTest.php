<?php

namespace Vspace\Ibexport\Tests;

use PHPUnit\Framework\TestCase;
use Vspace\Ibexport\DateValue;

/** Разбор дат ISO 8601 из export.xml (преобразование в формат сайта — штатными функциями Bitrix, не тестируется). */
final class DateValueTest extends TestCase
{
    public function testDateAndDateTime(): void
    {
        $this->assertSame(['timestamp' => mktime(0, 0, 0, 9, 18, 2026), 'time' => false], DateValue::parseIso('2026-09-18'));
        $this->assertSame(['timestamp' => mktime(10, 5, 7, 9, 18, 2026), 'time' => true], DateValue::parseIso('2026-09-18T10:05:07'));
    }

    public function testDatesInSiteFormatFromOldArchivesAreNotIso(): void
    {
        foreach (['18.09.2026', '18.09.2026 10:05:07', '09/18/2026', '2026-09-18 10:05:07', ''] as $value) {
            $this->assertNull(DateValue::parseIso($value), $value);
        }
    }

    public function testImpossibleDatesAreRejected(): void
    {
        foreach (['2026-02-30', '2026-13-01', '2026-09-18T24:00:00', '2026-09-18T10:60:00'] as $value) {
            $this->assertNull(DateValue::parseIso($value), $value);
        }
    }
}
