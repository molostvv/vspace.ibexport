<?php

namespace Vspace\Ibexport\Tests\Export;

use PHPUnit\Framework\TestCase;
use Vspace\Ibexport\Export\UserFieldExport;

final class UserFieldExportTest extends TestCase
{
    public function testOnlySelfContainedTypesAreSupported(): void
    {
        foreach (['string', 'integer', 'double', 'boolean', 'date', 'datetime', 'url'] as $type) {
            $this->assertTrue(UserFieldExport::isSupported($type), $type);
        }
        foreach (['file', 'enumeration', 'iblock_element', 'iblock_section', 'video', 'address', 'money'] as $type) {
            $this->assertFalse(UserFieldExport::isSupported($type), $type . ' — значение привязано к инсталляции');
        }
    }

    public function testSingleValue(): void
    {
        $this->assertSame(['SEO заголовок'], UserFieldExport::values(['USER_TYPE_ID' => 'string', 'MULTIPLE' => 'N', 'VALUE' => 'SEO заголовок']));
    }

    public function testEmptyAndMissingValuesGiveNothing(): void
    {
        $this->assertSame([], UserFieldExport::values(['MULTIPLE' => 'N', 'VALUE' => '']));
        $this->assertSame([], UserFieldExport::values(['MULTIPLE' => 'N', 'VALUE' => null]));
        $this->assertSame([], UserFieldExport::values(['MULTIPLE' => 'Y', 'VALUE' => []]));
        $this->assertSame([], UserFieldExport::values([]));
    }

    public function testZeroIsAValue(): void
    {
        $this->assertSame(['0'], UserFieldExport::values(['USER_TYPE_ID' => 'boolean', 'MULTIPLE' => 'N', 'VALUE' => '0']));
        $this->assertSame(['0'], UserFieldExport::values(['USER_TYPE_ID' => 'integer', 'MULTIPLE' => 'N', 'VALUE' => 0]));
    }

    public function testMultipleKeepsEveryNonEmptyValueInOrder(): void
    {
        $this->assertSame(['a', 'b'], UserFieldExport::values(['MULTIPLE' => 'Y', 'VALUE' => ['a', '', 'b', null]]));
    }

    public function testSingleFieldIgnoresExtraValues(): void
    {
        $this->assertSame(['a'], UserFieldExport::values(['MULTIPLE' => 'N', 'VALUE' => ['a', 'b']]));
    }
}
