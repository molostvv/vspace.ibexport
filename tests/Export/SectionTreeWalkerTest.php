<?php

namespace Vspace\Ibexport\Tests\Export;

use PHPUnit\Framework\TestCase;
use Vspace\Ibexport\Export\ExportContext;
use Vspace\Ibexport\Export\ExportFrame;
use Vspace\Ibexport\Export\SectionTreeWalker;
use Vspace\Ibexport\Tests\Export\Fake\InMemoryTreeSource;
use Vspace\Ibexport\Tests\Export\Fake\RecordingElementWriter;
use Vspace\Ibexport\Tests\Export\Fake\RecordingSectionWriter;
use Vspace\Ibexport\TraversalFrame;
use Vspace\Ibexport\XmlStreamWriter;

/**
 * Логика возобновляемого обхода дерева разделов при экспорте — без БД и без
 * ядра Bitrix: источник дерева и писатели — поддельные. "Бюджет тика" здесь —
 * число проходов цикла (часы подменены счётчиком), поэтому обрыв тика можно
 * поставить на любой шаг и сверить итог с непрерванным обходом.
 *
 * Дерево:  1 ─┬─ 2
 *             └─ 3 ── 4        элементы: 1:[10,11,12]  2:[20]  4:[40,41]
 */
final class SectionTreeWalkerTest extends TestCase
{
    private const CHILDREN = [1 => [2, 3], 3 => [4]];
    private const ELEMENTS = [1 => [10, 11, 12], 2 => [20], 4 => [40, 41]];

    /** Ожидаемый export.xml для всего дерева (отступы убраны — см. normalize()). */
    private const FULL_TREE_XML = <<<'XML'
<section id="1">
<name>S1</name>
<elements>
<element id="10"/>
<element id="11"/>
<element id="12"/>
</elements>
<sections>
<section id="2">
<name>S2</name>
<elements>
<element id="20"/>
</elements>
</section>
<section id="3">
<name>S3</name>
<elements>
</elements>
<sections>
<section id="4">
<name>S4</name>
<elements>
<element id="40"/>
<element id="41"/>
</elements>
</section>
</sections>
</section>
</sections>
</section>

XML;

    private function context(int $batchSize = 2): ExportContext
    {
        return new ExportContext(8, false, false, '', $batchSize);
    }

    /**
     * Отступ на границе тика зависит от того, где оборвался обход (косметика, как и в проде) — сравниваем без него.
     * Переводы строк приводятся к "\n": эталоны-heredoc в этом файле при checkout на Windows (core.autocrlf) получают CRLF.
     */
    private function normalize(string $xml): string
    {
        return preg_replace('~^[ ]+~m', '', str_replace("\r\n", "\n", $xml));
    }

    /** Часы-счётчик: каждый вызов — +1; с крайним сроком N цикл делает ровно N проходов. */
    private function tickClock(): \Closure
    {
        $now = 0;
        return static function () use (&$now): float {
            return (float)$now++;
        };
    }

    /**
     * Обходит дерево тиками по $budget проходов, между тиками пропуская стек через
     * JSON (как STATE_JSON в БД). $budget = null — одним тиком без ограничения.
     *
     * @param ExportFrame[]|null $stack Начальный стек; null — с корня (раздел 1)
     * @return array{xml: string, sections: int, elements: int, ticks: int, source: InMemoryTreeSource, sectionWriter: RecordingSectionWriter, elementWriter: RecordingElementWriter, snapshots: array[]}
     */
    private function walkTree(?int $budget, bool $recursive = true, ?array $stack = null, int $batchSize = 2): array
    {
        $source = new InMemoryTreeSource(self::CHILDREN, self::ELEMENTS);
        $sectionWriter = new RecordingSectionWriter();
        $elementWriter = new RecordingElementWriter();
        $stack ??= SectionTreeWalker::initialStack(1);

        $xml = '';
        $sections = 0;
        $elements = 0;
        $ticks = 0;
        $snapshots = [];

        do {
            $handle = fopen('php://memory', 'w+');
            $writer = new XmlStreamWriter($handle, count($stack));
            $walker = new SectionTreeWalker($source, $sectionWriter, $elementWriter, $this->tickClock());

            $result = $walker->walk($writer, $stack, $this->context($batchSize), $recursive, $budget === null ? 1e12 : (float)$budget);

            rewind($handle);
            $xml .= stream_get_contents($handle);
            fclose($handle);
            $sections += $result->processedSections;
            $elements += $result->processedElements;
            $ticks++;

            // состояние между тиками — как оно уходит в STATE_JSON и возвращается из него
            $rows = json_decode(json_encode(ExportFrame::stackToArray($result->stack)), true);
            $snapshots[] = $rows;
            $stack = ExportFrame::stackFromArray($rows);
        } while (!$result->isFinished() && $ticks < 1000);

        return compact('xml', 'sections', 'elements', 'ticks', 'source', 'sectionWriter', 'elementWriter', 'snapshots');
    }

    public function testFullWalkWritesTheWholeTreeInDepthFirstOrder(): void
    {
        $r = $this->walkTree(null);

        $this->assertSame($this->normalize(self::FULL_TREE_XML), $this->normalize($r['xml']));
        $this->assertSame(4, $r['sections']);
        $this->assertSame(6, $r['elements']);
        $this->assertSame(1, $r['ticks']);
        $this->assertSame([1, 2, 3, 4], $r['sectionWriter']->opened);
        $this->assertSame([10, 11, 12, 20, 40, 41], $r['elementWriter']->written);
    }

    public function testResumingAfterAnyNumberOfStepsGivesTheSameOutputAndCounters(): void
    {
        $reference = $this->walkTree(null);

        // от "каждый тик — один шаг" до "почти весь обход за тик": разрыв попадает на все фазы всех разделов
        foreach (range(1, 45) as $budget) {
            $r = $this->walkTree($budget);

            $this->assertSame($this->normalize($reference['xml']), $this->normalize($r['xml']), "output differs at budget $budget");
            $this->assertSame($reference['sections'], $r['sections'], "sections counter at budget $budget");
            $this->assertSame($reference['elements'], $r['elements'], "elements counter at budget $budget");
            $this->assertSame($reference['sectionWriter']->opened, $r['sectionWriter']->opened, "sections written twice/skipped at budget $budget");
            $this->assertSame($reference['elementWriter']->written, $r['elementWriter']->written, "elements written twice/skipped at budget $budget");
        }
    }

    public function testResumingNeverReadsTheSameSourcePageTwice(): void
    {
        $reference = $this->walkTree(null);

        foreach ([1, 2, 3, 5, 8] as $budget) {
            $this->assertSame($reference['source']->calls, $this->walkTree($budget)['source']->calls, "source reads differ at budget $budget");
        }
    }

    public function testTickWithAnExpiredDeadlineDoesNothingAndKeepsTheStack(): void
    {
        $stack = SectionTreeWalker::initialStack(1);
        $walker = new SectionTreeWalker(new InMemoryTreeSource(self::CHILDREN, self::ELEMENTS), new RecordingSectionWriter(), new RecordingElementWriter(), $this->tickClock());
        $handle = fopen('php://memory', 'w+');

        $result = $walker->walk(new XmlStreamWriter($handle, 1), $stack, $this->context(), true, 0.0);

        rewind($handle);
        $this->assertSame('', stream_get_contents($handle));
        $this->assertSame(0, $result->processedSections);
        $this->assertSame(0, $result->processedElements);
        $this->assertFalse($result->isFinished());
        $this->assertSame($stack, $result->stack);
    }

    public function testPhasesAdvanceElementsThenChildrenThenDone(): void
    {
        // шаг за шагом: 1) открыть раздел + первая страница; 2) вторая (короткая) страница -> фаза подразделов
        $r = $this->walkTree(1, true, null, 2);
        $root = $r['snapshots'];

        // после 1-го шага: раздел открыт, <elements> открыт, прочитана страница из 2 (полная — читаем дальше)
        $this->assertSame(true, $root[0][0]['opened']);
        $this->assertSame(TraversalFrame::PHASE_ELEMENTS, $root[0][0]['phase']);
        $this->assertSame(true, $root[0][0]['elements_tag_open']);
        $this->assertSame(2, $root[0][0]['elements_offset']);

        // после 2-го: страница из 1 (короткая) -> <elements> закрыт, фаза подразделов
        $this->assertSame(3, $root[1][0]['elements_offset']);
        $this->assertSame(TraversalFrame::PHASE_CHILDREN, $root[1][0]['phase']);
        $this->assertNull($root[1][0]['children_ids']);

        // после 3-го: подразделы выбраны, <sections> открыт
        $this->assertSame([2, 3], $root[2][0]['children_ids']);
        $this->assertSame(true, $root[2][0]['sections_tag_open']);
        $this->assertSame(0, $root[2][0]['children_index']);

        // после 4-го: взят первый подраздел — на стеке два кадра
        $this->assertCount(2, $root[3]);
        $this->assertSame(1, $root[3][0]['children_index']);
        $this->assertSame(2, $root[3][1]['section_id']);
        $this->assertFalse($root[3][1]['opened']);
    }

    public function testSectionWithoutChildrenDoesNotOpenTheSectionsTag(): void
    {
        $r = $this->walkTree(null);

        // у раздела 2 нет подразделов: между его </elements> и </section> ничего нет
        $this->assertStringContainsString("<element id=\"20\"/>\n</elements>\n</section>", $this->normalize($r['xml']));
    }

    public function testSectionSingleWritesStubsInsteadOfDescending(): void
    {
        $r = $this->walkTree(null, false);

        $this->assertSame([1], $r['sectionWriter']->opened);
        $this->assertSame([[1, false]], $r['sectionWriter']->stubCalls);
        $this->assertSame([10, 11, 12], $r['elementWriter']->written);
        $this->assertSame(1, $r['sections']);
        $this->assertSame(3, $r['elements']);
        $this->assertNotContains(['children', 1, 0], $r['source']->calls, 'section_single must not ask for child sections');
        $this->assertSame(
            "<section id=\"1\">\n<name>S1</name>\n<elements>\n<element id=\"10\"/>\n<element id=\"11\"/>\n<element id=\"12\"/>\n</elements>\n<subsections note=\"not_included_see_mode\">\n</subsections>\n</section>\n",
            $this->normalize($r['xml'])
        );
    }

    public function testSectionSingleResumesAcrossTicksToo(): void
    {
        $reference = $this->walkTree(null, false);

        foreach ([1, 2, 3, 4] as $budget) {
            $r = $this->walkTree($budget, false);
            $this->assertSame($this->normalize($reference['xml']), $this->normalize($r['xml']), "budget $budget");
            $this->assertSame([[1, false]], $r['sectionWriter']->stubCalls, "stubs written once at budget $budget");
        }
    }

    public function testElementsPagingHandlesAnExactMultipleOfTheBatchSize(): void
    {
        // раздел 4: ровно 2 элемента при batch=2 -> вторая страница пустая, и только она закрывает <elements>
        $r = $this->walkTree(null, true, null, 2);

        $reads = array_values(array_filter($r['source']->calls, static fn(array $c): bool => $c[0] === 'elements' && $c[1] === 4));
        $this->assertSame([['elements', 4, 0], ['elements', 4, 2]], $reads);
    }

    public function testChildSectionsAreReadPageByPageAndOnlyOnePageIsKeptInTheState(): void
    {
        // batch=1: подразделы корня читаются страницами [2], [3], [] — в STATE_JSON никогда не больше одной страницы
        $r = $this->walkTree(1, true, null, 1);

        $rootReads = array_values(array_filter($r['source']->calls, static fn(array $c): bool => $c[0] === 'children' && $c[1] === 1));
        $this->assertSame([['children', 1, 0], ['children', 1, 1], ['children', 1, 2]], $rootReads);
        foreach ($r['snapshots'] as $stack) {
            foreach ($stack as $frame) {
                $this->assertLessThanOrEqual(1, count($frame['children_ids'] ?? []));
            }
        }
        $this->assertSame($this->normalize($this->walkTree(null)['xml']), $this->normalize($r['xml']));
    }

    public function testEachElementIsWrittenWithTheSectionItIsExportedUnder(): void
    {
        $r = $this->walkTree(null);

        $this->assertSame([10 => 1, 11 => 1, 12 => 1, 20 => 2, 40 => 4, 41 => 4], $r['elementWriter']->sections);
    }

    public function testDifferentBatchSizesGiveTheSameOutput(): void
    {
        $reference = $this->normalize($this->walkTree(null, true, null, 2)['xml']);

        foreach ([1, 3, 100] as $batch) {
            $this->assertSame($reference, $this->normalize($this->walkTree(null, true, null, $batch)['xml']), "batch $batch");
        }
    }

    public function testResumesFromAStateSavedByThePreviousVersion(): void
    {
        // STATE_JSON, записанный кодом до рефакторинга: раздел 2 уже выгружен, обход стоит на подразделе 3
        $legacy = '{"stack":[{"section_id":1,"opened":true,"phase":"children","elements_offset":3,"elements_tag_open":true,"children_ids":[2,3],"children_index":1,"sections_tag_open":true}]}';
        $stack = ExportFrame::stackFromArray(json_decode($legacy, true)['stack']);

        $r = $this->walkTree(null, true, $stack);

        $expectedTail = <<<'XML'
<section id="3">
<name>S3</name>
<elements>
</elements>
<sections>
<section id="4">
<name>S4</name>
<elements>
<element id="40"/>
<element id="41"/>
</elements>
</section>
</sections>
</section>
</sections>
</section>

XML;
        $this->assertSame($this->normalize($expectedTail), $this->normalize($r['xml']));
        $this->assertSame([3, 4], $r['sectionWriter']->opened, 'already exported sections must not be written again');
        $this->assertSame([40, 41], $r['elementWriter']->written);
        $this->assertSame(2, $r['sections']);
        $this->assertSame(2, $r['elements']);
    }

    public function testResumesFromALegacyStateInTheMiddleOfANestedSection(): void
    {
        // два кадра: корень уже взял подраздел 3 (children_index=2), внутри 3 выбраны подразделы и взят 4
        $legacy = '{"stack":['
            . '{"section_id":1,"opened":true,"phase":"children","elements_offset":3,"elements_tag_open":true,"children_ids":[2,3],"children_index":2,"sections_tag_open":true},'
            . '{"section_id":3,"opened":true,"phase":"children","elements_offset":0,"elements_tag_open":true,"children_ids":[4],"children_index":0,"sections_tag_open":true}'
            . ']}';
        $stack = ExportFrame::stackFromArray(json_decode($legacy, true)['stack']);

        $r = $this->walkTree(null, true, $stack);

        $this->assertSame([4], $r['sectionWriter']->opened);
        $this->assertSame([40, 41], $r['elementWriter']->written);
        // закрывает всё, что было открыто до обрыва: </section>(4) </sections> </section>(3) </sections> </section>(1)
        $this->assertStringEndsWith("</section>\n</sections>\n</section>\n</sections>\n</section>\n", $this->normalize($r['xml']));
    }
}
