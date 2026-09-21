<?php

namespace Vspace\Ibexport\Tests\Import;

use PHPUnit\Framework\TestCase;
use SimpleXMLElement;
use Vspace\Ibexport\Import\ImportContext;
use Vspace\Ibexport\Import\ImportPreview;
use Vspace\Ibexport\Tests\Import\Fake\FakePropertySource;
use Vspace\Ibexport\Tests\Import\Fake\FakeRecordFinder;

final class ImportPreviewTest extends TestCase
{
    private const TREE = <<<'XML'
<export mode="section_tree" iblock_id="8">
  <section id="1" code="root" xml_id="s-1" active="Y">
    <name>Root</name>
    <picture file_ref="files/1_a.jpg"/>
    <elements>
      <element id="10" code="el-code" xml_id="e-10" active="Y">
        <name>WithCode</name>
        <preview_picture file_ref="files/10_p.jpg"/>
        <detail_picture file_ref="files/10_d.jpg"/>
        <properties><property code="A" type="S">x</property><property code="B" type="S">y</property></properties>
      </element>
      <element id="11" code="" xml_id="e-11" active="N">
        <name>NoCode</name>
      </element>
    </elements>
    <sections>
      <section id="2" code="" xml_id="s-2" active="Y">
        <name>Child</name>
        <elements>
          <element id="12" code="" xml_id="" active="Y"><name>NoKeys</name></element>
        </elements>
      </section>
    </sections>
  </section>
</export>
XML;

    private function ctx(bool $update, bool $xml): ImportContext
    {
        return new ImportContext(8, $update, '/tmp', $xml);
    }

    private function build(string $xml, string $mode, ImportContext $ctx, FakeRecordFinder $finder, int $limit = 200, ?FakePropertySource $properties = null): array
    {
        $properties ??= new FakePropertySource([8 => ['A' => ['ID' => 1, 'MULTIPLE' => 'N'], 'B' => ['ID' => 2, 'MULTIPLE' => 'N']]]);

        return (new ImportPreview($finder, $properties))->build(new SimpleXMLElement($xml), $mode, $ctx, $limit);
    }

    public function testRowsFollowImportOrderWithContext(): void
    {
        $result = $this->build(self::TREE, 'section_tree', $this->ctx(true, false), new FakeRecordFinder());

        $this->assertFalse($result['truncated']);
        $this->assertSame(
            [['section', 'Root', ''], ['element', 'WithCode', 'Root'], ['element', 'NoCode', 'Root'], ['section', 'Child', 'Root'], ['element', 'NoKeys', 'Root › Child']],
            array_map(static fn(array $r) => [$r['kind'], $r['name'], $r['context']], $result['rows'])
        );
    }

    public function testCountsFilesAndPropertiesPerNodeNotPerSubtree(): void
    {
        $rows = $this->build(self::TREE, 'section_tree', $this->ctx(true, false), new FakeRecordFinder())['rows'];

        $this->assertSame(1, $rows[0]['files'], 'у раздела считается только его картинка, а не файлы вложенных элементов');
        $this->assertSame(2, $rows[1]['files']);
        $this->assertSame(2, $rows[1]['props']);
        $this->assertFalse($rows[2]['active']);
    }

    public function testMatchByCodeOnlyByDefault(): void
    {
        $finder = new FakeRecordFinder(['section:CODE:root' => [100, 'Root'], 'element:CODE:el-code' => [200, 'WithCode'], 'element:XML_ID:e-11' => [300, 'NoCode']]);
        $rows = $this->build(self::TREE, 'section_tree', $this->ctx(true, false), $finder)['rows'];

        $this->assertSame([100, 200, null, null, null], array_column($rows, 'target_id'));
        $this->assertSame(['CODE', 'CODE', null, null, null], array_column($rows, 'match_by'));
        $this->assertSame(['update', 'update', 'create', 'create', 'create'], array_column($rows, 'action'));
        $this->assertNotContains('element:XML_ID:e-11', $finder->asked, 'без опции по XML_ID не ищем');
    }

    public function testXmlIdFallbackOnlyForRecordsWithoutCode(): void
    {
        $finder = new FakeRecordFinder(['element:XML_ID:e-11' => [300, 'NoCode'], 'section:XML_ID:s-2' => [400, 'Child'], 'element:XML_ID:e-10' => [999, 'x']]);
        $rows = $this->build(self::TREE, 'section_tree', $this->ctx(true, true), $finder)['rows'];

        $this->assertSame([null, null, 300, 400, null], array_column($rows, 'target_id'));
        $this->assertSame(['CODE', 'CODE', 'XML_ID', 'XML_ID', null], array_column($rows, 'match_by'));
        $this->assertSame(['create', 'create', 'update', 'update', 'create'], array_column($rows, 'action'));
    }

    public function testActionIsSkipWhenUpdateIsOff(): void
    {
        $finder = new FakeRecordFinder(['element:CODE:el-code' => [200, 'WithCode']]);
        $rows = $this->build(self::TREE, 'section_tree', $this->ctx(false, false), $finder)['rows'];

        $this->assertSame('skip', $rows[1]['action']);
        $this->assertSame('create', $rows[0]['action']);
    }

    public function testXmlIdMatchWithDifferentNameIsFlaggedButStillUpdates(): void
    {
        $finder = new FakeRecordFinder(['element:XML_ID:e-11' => [300, 'Совсем другое название']]);
        $rows = $this->build(self::TREE, 'section_tree', $this->ctx(true, true), $finder)['rows'];

        $this->assertSame(['name_mismatch'], $rows[2]['flags']);
        $this->assertSame(300, $rows[2]['target_id']);
        $this->assertSame('Совсем другое название', $rows[2]['target_name']);
        $this->assertSame('update', $rows[2]['action'], 'название — предупреждение, а не запрет: запись могли переименовать');
    }

    public function testNameComparisonIgnoresCaseAndWhitespace(): void
    {
        $finder = new FakeRecordFinder(['element:XML_ID:e-11' => [300, "  nocode
"]]);
        $rows = $this->build(self::TREE, 'section_tree', $this->ctx(true, true), $finder)['rows'];

        $this->assertSame([], $rows[2]['flags']);
        $this->assertTrue(ImportPreview::sameName('Наши  отличия', ' наши отличия '));
        $this->assertFalse(ImportPreview::sameName('Наши отличия', 'Наши кейсы'));
    }

    public function testCodeMatchIsNeverFlaggedForName(): void
    {
        $finder = new FakeRecordFinder(['element:CODE:el-code' => [200, 'Переименованный элемент']]);
        $rows = $this->build(self::TREE, 'section_tree', $this->ctx(true, true), $finder)['rows'];

        $this->assertSame([], $rows[1]['flags'], 'совпадение по коду надёжно, сверка названия — только для XML_ID');
    }

    public function testSeveralRecordsWithSameXmlIdAreAmbiguousAndSkipped(): void
    {
        $finder = new FakeRecordFinder(['element:XML_ID:e-11' => [[300, 'NoCode'], [301, 'NoCode']]]);
        $rows = $this->build(self::TREE, 'section_tree', $this->ctx(true, true), $finder)['rows'];

        $this->assertSame(['ambiguous'], $rows[2]['flags']);
        $this->assertSame('skip', $rows[2]['action'], 'обновлять "какую-то из" записей нельзя');
    }

    public function testSeveralRecordsWithSameCodeKeepOldBehaviour(): void
    {
        $finder = new FakeRecordFinder(['element:CODE:el-code' => [[200, 'WithCode'], [201, 'WithCode']]]);
        $rows = $this->build(self::TREE, 'section_tree', $this->ctx(true, true), $finder)['rows'];

        $this->assertSame([], $rows[1]['flags']);
        $this->assertSame('update', $rows[1]['action']);
    }

    public function testCollectsElementPropertiesMissingInTargetIblock(): void
    {
        $properties = new FakePropertySource([8 => ['A' => ['ID' => 1, 'MULTIPLE' => 'N']]]); // свойства B в целевом инфоблоке нет
        $result = $this->build(self::TREE, 'section_tree', $this->ctx(true, false), new FakeRecordFinder(), 200, $properties);

        $this->assertSame(['B'], $result['rows'][1]['missing']);
        $this->assertSame([], $result['rows'][0]['missing'], 'у раздела свойств нет');
        $this->assertSame(['B' => 1], $result['missing_props']);
        $this->assertSame([], $result['missing_uf']);
    }

    public function testNothingIsMissingWhenTargetHasAllProperties(): void
    {
        $result = $this->build(self::TREE, 'section_tree', $this->ctx(true, false), new FakeRecordFinder());

        $this->assertSame([], $result['missing_props']);
    }

    public function testCollectsSectionUserFieldsMissingInTargetIblock(): void
    {
        $xml = '<export mode="section_tree"><section id="1" code="a" active="Y"><name>A</name><properties>'
            . '<property code="UF_HAVE">1</property><property code="UF_NO">2</property></properties>'
            . '<sections><section id="2" code="b" active="Y"><name>B</name><properties><property code="UF_NO">3</property></properties></section></sections>'
            . '</section></export>';
        $properties = new FakePropertySource([], [], [8 => ['UF_HAVE']]);
        $result = $this->build($xml, 'section_tree', $this->ctx(true, false), new FakeRecordFinder(), 200, $properties);

        $this->assertSame([['UF_NO'], ['UF_NO']], array_column($result['rows'], 'missing'));
        $this->assertSame(['UF_NO' => 2], $result['missing_uf'], 'считается, у скольких разделов поля нет');
        $this->assertSame([], $result['missing_props']);
    }

    public function testSectionSingleDoesNotDescendIntoChildren(): void
    {
        $rows = $this->build(self::TREE, 'section_single', $this->ctx(true, false), new FakeRecordFinder())['rows'];

        $this->assertSame(['Root', 'WithCode', 'NoCode'], array_column($rows, 'name'));
    }

    public function testLimitTruncatesAndStopsQueries(): void
    {
        $finder = new FakeRecordFinder();
        $result = $this->build(self::TREE, 'section_tree', $this->ctx(true, false), $finder, 2);

        $this->assertTrue($result['truncated']);
        $this->assertCount(2, $result['rows']);
        $this->assertCount(2, $finder->asked, 'в БД ходим только за показанными строками');
    }

    public function testElementModeShowsSectionPathFromArchive(): void
    {
        $xml = '<export mode="element"><element id="5" code="c" active="Y"><name>E</name><sections>'
            . '<section id="1" code="a" path="Каталог &gt; Новости"/><section id="2" code="b" path=""/></sections></element></export>';
        $rows = $this->build($xml, 'element', $this->ctx(true, false), new FakeRecordFinder())['rows'];

        $this->assertCount(1, $rows);
        $this->assertSame('Каталог > Новости; b', $rows[0]['context'], 'без path — символьный код раздела');
    }
}
