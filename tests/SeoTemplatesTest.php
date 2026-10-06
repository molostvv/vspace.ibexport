<?php

namespace Vspace\Ibexport\Tests;

use PHPUnit\Framework\TestCase;
use SimpleXMLElement;
use Vspace\Ibexport\SeoTemplates;
use Vspace\Ibexport\XmlStreamWriter;

final class SeoTemplatesTest extends TestCase
{
    private static function written(array $templates): string
    {
        $handle = fopen('php://memory', 'w+');
        SeoTemplates::write(new XmlStreamWriter($handle), $templates);
        rewind($handle);

        return stream_get_contents($handle);
    }

    public function testWrittenTemplatesParseBackUnchanged(): void
    {
        $templates = [
            'ELEMENT_META_TITLE' => 'Купить {=this.Name} & "дёшево" <сразу>',
            'ELEMENT_PAGE_TITLE' => ' с пробелами по краям ',
        ];
        $node = new SimpleXMLElement('<element>' . self::written($templates) . '</element>');

        $this->assertSame(['templates' => $templates, 'rejected' => []], SeoTemplates::parse($node, SeoTemplates::ENTITY_ELEMENT));
    }

    public function testNoTemplatesStillWritesEmptyBlock(): void
    {
        $node = new SimpleXMLElement('<element>' . self::written([]) . '</element>');

        $this->assertSame(['templates' => [], 'rejected' => []], SeoTemplates::parse($node, SeoTemplates::ENTITY_ELEMENT));
    }

    public function testArchiveWithoutSeoBlockGivesNull(): void
    {
        $this->assertNull(SeoTemplates::parse(new SimpleXMLElement('<element><name>x</name></element>'), SeoTemplates::ENTITY_ELEMENT));
    }

    public function testEmptyTemplateIsDropped(): void
    {
        $node = new SimpleXMLElement('<section><seo><template code="SECTION_META_TITLE"/></seo></section>');

        $this->assertSame([], SeoTemplates::parse($node, SeoTemplates::ENTITY_SECTION)['templates']);
    }

    public function testCodesAreCheckedPerEntityType(): void
    {
        $xml = '<seo><template code="SECTION_META_TITLE">s</template><template code="ELEMENT_META_TITLE">e</template>'
            . '<template code="bad code">x</template></seo>';

        $element = SeoTemplates::parse(new SimpleXMLElement('<element>' . $xml . '</element>'), SeoTemplates::ENTITY_ELEMENT);
        $this->assertSame(['ELEMENT_META_TITLE' => 'e'], $element['templates']);
        $this->assertSame(['SECTION_META_TITLE', 'bad code'], $element['rejected']);

        // у раздела ELEMENT_* — шаблоны для его элементов
        $section = SeoTemplates::parse(new SimpleXMLElement('<section>' . $xml . '</section>'), SeoTemplates::ENTITY_SECTION);
        $this->assertSame(['SECTION_META_TITLE' => 's', 'ELEMENT_META_TITLE' => 'e'], $section['templates']);
        $this->assertSame(['bad code'], $section['rejected']);
    }

    public function testCodeLongerThanColumnIsRejected(): void
    {
        $this->assertTrue(SeoTemplates::isValidCode('ELEMENT_' . str_repeat('A', 42), SeoTemplates::ENTITY_ELEMENT));
        $this->assertFalse(SeoTemplates::isValidCode('ELEMENT_' . str_repeat('A', 43), SeoTemplates::ENTITY_ELEMENT));
        $this->assertFalse(SeoTemplates::isValidCode('ELEMENT_', SeoTemplates::ENTITY_ELEMENT));
    }

    public function testOwnTemplatesMissingInArchiveAreCleared(): void
    {
        $this->assertSame(
            ['ELEMENT_META_TITLE' => 'новый', 'ELEMENT_META_KEYWORDS' => ''],
            SeoTemplates::forSave(['ELEMENT_META_TITLE' => 'новый'], ['ELEMENT_META_TITLE', 'ELEMENT_META_KEYWORDS'])
        );
    }

    public function testNewRecordGetsArchiveTemplatesOnly(): void
    {
        $this->assertSame(['ELEMENT_META_TITLE' => 't'], SeoTemplates::forSave(['ELEMENT_META_TITLE' => 't'], []));
        $this->assertSame([], SeoTemplates::forSave([], []));
    }
}
