<?php

namespace Vspace\Ibexport\Tests\Import;

use PHPUnit\Framework\TestCase;
use SimpleXMLElement;
use Vspace\Ibexport\Import\ImportReport;
use Vspace\Ibexport\Import\PropertyResolver;
use Vspace\Ibexport\Tests\Import\Fake\FakeFileArrayFactory;
use Vspace\Ibexport\Tests\Import\Fake\FakePropertySource;

/**
 * Резолв свойств элемента при импорте по правилам docs/import-format.md:
 * "дано XML-описание свойств — отдать массив для SetPropertyValuesEx".
 * Определения свойств, варианты списков и подготовка файлов — поддельные.
 */
final class PropertyResolverTest extends TestCase
{
    private const IBLOCK = 8;

    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/vibx_resolver_' . uniqid();
        mkdir($this->tmpDir . '/files', 0777, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->tmpDir . '/files/*') ?: [] as $f) {
            unlink($f);
        }
        @rmdir($this->tmpDir . '/files');
        @rmdir($this->tmpDir);
    }

    private function source(): FakePropertySource
    {
        return new FakePropertySource(
            [self::IBLOCK => [
                'TITLE' => ['ID' => 1, 'MULTIPLE' => 'N'],
                'TAGS_TEXT' => ['ID' => 2, 'MULTIPLE' => 'Y'],
                'COLOR' => ['ID' => 3, 'MULTIPLE' => 'N'],
                'TAGS' => ['ID' => 4, 'MULTIPLE' => 'Y'],
                'LOGO' => ['ID' => 5, 'MULTIPLE' => 'N'],
                'GALLERY' => ['ID' => 6, 'MULTIPLE' => 'Y'],
                'RATING' => ['ID' => 7, 'MULTIPLE' => 'N'],
            ]],
            [
                3 => ['Красный' => 31, 'Синий' => 32],
                4 => ['новинка' => 41, 'акция' => 42, 'хит' => 43],
            ]
        );
    }

    private function resolve(string $propertiesXml, ?FakePropertySource $source = null, ?FakeFileArrayFactory $files = null, ?ImportReport $report = null): array
    {
        $resolver = new PropertyResolver($source ?? $this->source(), $files ?? new FakeFileArrayFactory());

        return $resolver->resolve(self::IBLOCK, new SimpleXMLElement($propertiesXml), $this->tmpDir, $report ?? new ImportReport());
    }

    private function touch(string $name): string
    {
        $path = $this->tmpDir . '/files/' . $name;
        file_put_contents($path, 'x');

        return $path;
    }

    // ---------------------------------------------------------------- текстовые

    public function testSingleTextPropertyIsPassedAsAString(): void
    {
        $report = new ImportReport();

        $resolved = $this->resolve('<properties><property code="TITLE" type="S">Заголовок</property></properties>', null, null, $report);

        $this->assertSame(['TITLE' => 'Заголовок'], $resolved);
        $this->assertSame([], $report->getWarnings());
    }

    public function testMultipleTextPropertyIsPassedAsAListOfValues(): void
    {
        $resolved = $this->resolve('<properties><property code="TAGS_TEXT" type="S" multiple="true"><value>a</value><value>b</value></property></properties>');

        $this->assertSame(['TAGS_TEXT' => ['a', 'b']], $resolved);
    }

    public function testSingleValueOfAMultipleTextPropertyStaysAList(): void
    {
        $resolved = $this->resolve('<properties><property code="TAGS_TEXT" type="S">only</property></properties>');

        $this->assertSame(['TAGS_TEXT' => ['only']], $resolved);
    }

    public function testSingleValueNodeOfANonMultiplePropertyTakesTheFirstValue(): void
    {
        $resolved = $this->resolve('<properties><property code="TITLE" type="S" multiple="true"><value>first</value><value>second</value></property></properties>');

        $this->assertSame(['TITLE' => 'first'], $resolved);
    }

    public function testTextIsTrimmedAndEmptyPropertyIsSkippedWithoutWarning(): void
    {
        $report = new ImportReport();

        $resolved = $this->resolve(
            '<properties><property code="TITLE" type="S">  padded  </property><property code="RATING" type="N"/></properties>',
            null,
            null,
            $report
        );

        $this->assertSame(['TITLE' => 'padded'], $resolved);
        $this->assertSame([], $report->getWarnings());
    }

    public function testOtherPropertyTypesAreTreatedAsPlainText(): void
    {
        // N (число), E/G (привязка) и т.п. — как есть, без резолва
        $resolved = $this->resolve('<properties><property code="RATING" type="N">4.5</property></properties>');

        $this->assertSame(['RATING' => '4.5'], $resolved);
    }

    // ---------------------------------------------------------------- отсутствие определения

    public function testPropertyMissingInTheTargetIblockIsSkippedWithAWarning(): void
    {
        $report = new ImportReport();

        $resolved = $this->resolve(
            '<properties><property code="NO_SUCH" type="S">v</property><property code="TITLE" type="S">ok</property></properties>',
            null,
            null,
            $report
        );

        $this->assertSame(['TITLE' => 'ok'], $resolved);
        $this->assertSame(['Свойство с кодом "NO_SUCH" не найдено в целевом инфоблоке, значение пропущено.'], $report->getWarnings());
    }

    public function testMissingDefinitionIsReportedForFilePropertiesToo(): void
    {
        $report = new ImportReport();
        $files = new FakeFileArrayFactory();

        $resolved = $this->resolve('<properties><property code="GHOST" type="F"><file file_ref="files/a.jpg"/></property></properties>', null, $files, $report);

        $this->assertSame([], $resolved);
        $this->assertCount(1, $report->getWarnings());
        $this->assertSame([], $files->requested, 'no file must be prepared for an unknown property');
    }

    // ---------------------------------------------------------------- список (L)

    public function testListValueIsMappedToTheEnumVariantId(): void
    {
        $resolved = $this->resolve('<properties><property code="COLOR" type="L">Синий</property></properties>');

        $this->assertSame(['COLOR' => 32], $resolved);
    }

    public function testMultipleListValuesAreMappedToVariantIds(): void
    {
        $resolved = $this->resolve('<properties><property code="TAGS" type="L" multiple="true"><value>хит</value><value>новинка</value></property></properties>');

        $this->assertSame(['TAGS' => [43, 41]], $resolved);
    }

    public function testUnknownListVariantIsSkippedWithAWarningAndTheRestIsKept(): void
    {
        $report = new ImportReport();

        $resolved = $this->resolve(
            '<properties><property code="TAGS" type="L" multiple="true"><value>хит</value><value>распродажа</value></property></properties>',
            null,
            null,
            $report
        );

        $this->assertSame(['TAGS' => [43]], $resolved);
        $this->assertSame(['Свойство "TAGS": вариант "распродажа" не найден среди значений списка в целевом инфоблоке, пропущен.'], $report->getWarnings());
    }

    public function testPropertyIsOmittedWhenNoListVariantMatches(): void
    {
        $report = new ImportReport();

        $resolved = $this->resolve('<properties><property code="COLOR" type="L">Зелёный</property></properties>', null, null, $report);

        $this->assertSame([], $resolved);
        $this->assertCount(1, $report->getWarnings());
    }

    public function testListVariantsAreMatchedByTheirDisplayTextExactly(): void
    {
        $resolved = $this->resolve('<properties><property code="COLOR" type="L">красный</property></properties>');

        $this->assertSame([], $resolved, 'matching is case-sensitive: "красный" is not "Красный"');
    }

    // ---------------------------------------------------------------- файлы (F)

    public function testFileFromTheArchiveIsPreparedForTheProperty(): void
    {
        $path = $this->touch('logo.png');
        $files = new FakeFileArrayFactory();
        $report = new ImportReport();

        $resolved = $this->resolve('<properties><property code="LOGO" type="F"><file file_ref="files/logo.png"/></property></properties>', null, $files, $report);

        $this->assertSame(['LOGO' => ['name' => 'logo.png', 'tmp_name' => $path]], $resolved);
        $this->assertSame([$path], $files->requested);
        $this->assertSame([], $report->getWarnings());
    }

    public function testMultipleFilePropertyKeepsAllFilesInOrder(): void
    {
        $a = $this->touch('a.jpg');
        $b = $this->touch('b.jpg');

        $resolved = $this->resolve('<properties><property code="GALLERY" type="F" multiple="true"><file file_ref="files/a.jpg"/><file file_ref="files/b.jpg"/></property></properties>');

        $this->assertSame(['GALLERY' => [
            ['name' => 'a.jpg', 'tmp_name' => $a],
            ['name' => 'b.jpg', 'tmp_name' => $b],
        ]], $resolved);
    }

    public function testNonMultipleFilePropertyTakesTheFirstFileOnly(): void
    {
        $a = $this->touch('a.jpg');
        $this->touch('b.jpg');

        $resolved = $this->resolve('<properties><property code="LOGO" type="F"><file file_ref="files/a.jpg"/><file file_ref="files/b.jpg"/></property></properties>');

        $this->assertSame(['LOGO' => ['name' => 'a.jpg', 'tmp_name' => $a]], $resolved);
    }

    public function testFileMissingFromTheArchiveIsSkippedWithAWarning(): void
    {
        $report = new ImportReport();
        $files = new FakeFileArrayFactory();

        $resolved = $this->resolve('<properties><property code="LOGO" type="F"><file file_ref="files/absent.png"/></property></properties>', null, $files, $report);

        $this->assertSame([], $resolved);
        $this->assertSame(['Файл свойства "LOGO" не найден в архиве, значение пропущено.'], $report->getWarnings());
        $this->assertSame([], $files->requested);
    }

    public function testExistingFilesAreKeptWhenAnotherOneIsMissing(): void
    {
        $a = $this->touch('a.jpg');
        $report = new ImportReport();

        $resolved = $this->resolve('<properties><property code="GALLERY" type="F" multiple="true"><file file_ref="files/a.jpg"/><file file_ref="files/gone.jpg"/></property></properties>', null, null, $report);

        $this->assertSame(['GALLERY' => [['name' => 'a.jpg', 'tmp_name' => $a]]], $resolved);
        $this->assertCount(1, $report->getWarnings());
    }

    public function testFileNodeWithoutFileRefIsIgnoredSilently(): void
    {
        // файлы не выгружались (WITH_FILES=N) либо исходного файла не было — экспорт пишет <file/> без file_ref
        $report = new ImportReport();
        $files = new FakeFileArrayFactory();

        $resolved = $this->resolve('<properties><property code="LOGO" type="F"><file/></property></properties>', null, $files, $report);

        $this->assertSame([], $resolved);
        $this->assertSame([], $report->getWarnings());
        $this->assertSame([], $files->requested);
    }

    public function testFileThatCannotBePreparedIsSilentlyDropped(): void
    {
        $path = $this->touch('bad.png');

        $resolved = $this->resolve(
            '<properties><property code="LOGO" type="F"><file file_ref="files/bad.png"/></property></properties>',
            null,
            new FakeFileArrayFactory([$path])
        );

        $this->assertSame([], $resolved);
    }

    // ---------------------------------------------------------------- прочее

    public function testPropertiesNodeWithoutPropertiesResolvesToNothingAndDoesNotTouchTheSource(): void
    {
        $source = $this->source();

        $this->assertSame([], $this->resolve('<properties/>', $source));
        $this->assertSame([], $source->definitionRequests, 'definitions must not be loaded when there is nothing to resolve');
    }

    public function testDefinitionsAreLoadedOncePerElementAndEnumOnlyForListProperties(): void
    {
        $source = $this->source();

        $this->resolve(
            '<properties>'
            . '<property code="TITLE" type="S">t</property>'
            . '<property code="COLOR" type="L">Красный</property>'
            . '<property code="TAGS" type="L" multiple="true"><value>хит</value></property>'
            . '</properties>',
            $source
        );

        $this->assertSame([self::IBLOCK], $source->definitionRequests);
        $this->assertSame([3, 4], $source->enumRequests);
    }

    public function testMixedProperties(): void
    {
        $logo = $this->touch('logo.png');
        $report = new ImportReport();

        $resolved = $this->resolve(
            '<properties>'
            . '<property code="TITLE" type="S">Заголовок</property>'
            . '<property code="COLOR" type="L">Красный</property>'
            . '<property code="UNKNOWN" type="S">x</property>'
            . '<property code="LOGO" type="F"><file file_ref="files/logo.png"/></property>'
            . '<property code="TAGS" type="L" multiple="true"><value>акция</value><value>?</value></property>'
            . '</properties>',
            null,
            null,
            $report
        );

        $this->assertSame([
            'TITLE' => 'Заголовок',
            'COLOR' => 31,
            'LOGO' => ['name' => 'logo.png', 'tmp_name' => $logo],
            'TAGS' => [42],
        ], $resolved);
        $this->assertSame([
            'Свойство с кодом "UNKNOWN" не найдено в целевом инфоблоке, значение пропущено.',
            'Свойство "TAGS": вариант "?" не найден среди значений списка в целевом инфоблоке, пропущен.',
        ], $report->getWarnings());
    }
}
