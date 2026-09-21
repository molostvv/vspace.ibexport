<?php

namespace Vspace\Ibexport\Tests\Import;

use PHPUnit\Framework\TestCase;
use SimpleXMLElement;
use Vspace\Ibexport\Import\SectionImporter;

final class SectionUserFieldsTest extends TestCase
{
    private function parse(string $properties): array
    {
        return SectionImporter::parseUserFields(new SimpleXMLElement('<properties>' . $properties . '</properties>'));
    }

    public function testSingleAndMultipleValues(): void
    {
        $parsed = $this->parse(
            '<property code="UF_TITLE">Заголовок</property>'
            . '<property code="UF_TAGS" multiple="true"><value>a</value><value>b</value></property>'
        );

        $this->assertSame(['UF_TITLE' => 'Заголовок', 'UF_TAGS' => ['a', 'b']], $parsed);
    }

    public function testOnlyUserFieldsAreTaken(): void
    {
        $this->assertSame(['UF_X' => '1'], $this->parse('<property code="SOMETHING">no</property><property code="UF_X">1</property>'));
    }

    public function testEmptyPropertiesGiveNothing(): void
    {
        $this->assertSame([], $this->parse(''));
    }

    public function testValueWrittenAsPlainTextStillReads(): void
    {
        // формат, в котором несколько значений склеены через ", " в одном тексте, читается как одна строка
        $this->assertSame(['UF_OLD' => 'a, b'], $this->parse('<property code="UF_OLD">a, b</property>'));
    }
}
