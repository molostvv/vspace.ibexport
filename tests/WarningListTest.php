<?php

namespace Vspace\Ibexport\Tests;

use PHPUnit\Framework\TestCase;
use Vspace\Ibexport\WarningList;

final class WarningListTest extends TestCase
{
    public function testAddsDistinctMessagesInOrder(): void
    {
        $list = WarningList::add(WarningList::add([], 'a'), 'b');

        $this->assertSame(['a', 'b'], $list);
    }

    public function testIdenticalMessagesAreMergedWithRepeatCount(): void
    {
        $list = [];
        foreach (range(1, 60) as $_) {
            $list = WarningList::add($list, 'Свойство "X" не найдено');
        }

        $this->assertSame(['Свойство "X" не найдено (×60)'], $list);
    }

    public function testCountArgumentAddsToExistingRepeatsAcrossTicks(): void
    {
        $list = WarningList::add([], 'm', 3);
        $list = WarningList::add($list, 'm', 4);

        $this->assertSame(['m (×7)'], $list);
    }

    public function testMessageThatOnlyStartsLikeAnotherIsNotMerged(): void
    {
        $list = WarningList::add(['Свойство "X" не найдено (×2)'], 'Свойство "X" не найдено в целевом инфоблоке');

        $this->assertSame(['Свойство "X" не найдено (×2)', 'Свойство "X" не найдено в целевом инфоблоке'], $list);
    }

    public function testEntryLimitTurnsTheRestIntoOverflowLine(): void
    {
        $list = [];
        foreach (range(1, WarningList::MAX_ENTRIES + 5) as $i) {
            $list = WarningList::add($list, 'message ' . $i);
        }

        $this->assertCount(WarningList::MAX_ENTRIES + 1, $list);
        $this->assertSame('message 1', $list[0]);
        $this->assertSame('… и ещё 5 предупреждений не показано (лимит журнала)', end($list));

        $list = WarningList::add($list, 'one more', 2);
        $this->assertSame('… и ещё 7 предупреждений не показано (лимит журнала)', end($list), 'счётчик переполнения копится');
        $this->assertCount(WarningList::MAX_ENTRIES + 1, $list);
    }

    public function testRepeatOfAnAlreadyStoredMessageStillCountsAfterOverflow(): void
    {
        $list = [];
        foreach (range(1, WarningList::MAX_ENTRIES + 1) as $i) {
            $list = WarningList::add($list, 'message ' . $i);
        }
        $list = WarningList::add($list, 'message 1');

        $this->assertSame('message 1 (×2)', $list[0]);
        $this->assertSame('… и ещё 1 предупреждений не показано (лимит журнала)', end($list));
    }

    public function testByteLimitKeepsJsonWithinTextColumn(): void
    {
        $long = str_repeat('я', 900); // ~1,8 КБ в UTF-8 на сообщение
        $list = [];
        foreach (range(1, 100) as $i) {
            $list = WarningList::add($list, $long . $i);
        }

        $json = WarningList::encode($list);
        $this->assertLessThanOrEqual(WarningList::MAX_BYTES, strlen($json));
        $this->assertLessThan(65535, strlen($json));
        $this->assertStringContainsString('не показано', end($list));
        $this->assertSame($list, WarningList::decode($json), 'JSON читается обратно без потерь');
    }

    public function testDecodeToleratesEmptyAndBrokenJson(): void
    {
        $this->assertSame([], WarningList::decode(null));
        $this->assertSame([], WarningList::decode(''));
        $this->assertSame([], WarningList::decode('["обрезанный JSON'));
        $this->assertSame([], WarningList::decode('"строка"'));
        $this->assertSame(['a', '1'], WarningList::decode('["a", 1]'));
    }

    public function testBrokenStoredValueDoesNotDropNewWarning(): void
    {
        $list = WarningList::add(WarningList::decode('["обрезанный'), 'new');

        $this->assertSame(['new'], $list);
    }
}
