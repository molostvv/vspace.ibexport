<?php

namespace Vspace\Ibexport\Tests\Import;

use PHPUnit\Framework\TestCase;
use SimpleXMLElement;
use Vspace\Ibexport\Import\ImportContext;
use Vspace\Ibexport\Import\ImportFrame;
use Vspace\Ibexport\Import\ImportReport;
use Vspace\Ibexport\Import\SectionTreeWalker;
use Vspace\Ibexport\Tests\Import\Fake\CallLog;
use Vspace\Ibexport\Tests\Import\Fake\RecordingElementImporter;
use Vspace\Ibexport\Tests\Import\Fake\RecordingSectionImporter;
use Vspace\Ibexport\TraversalFrame;

/**
 * Обход дерева при импорте (зеркало экспорта) — на настоящем SimpleXML, но с
 * поддельными импортёрами: проверяются порядок обхода, родитель каждого
 * раздела и возобновление с любого шага. "Бюджет тика" — число проходов цикла
 * (часы подменены счётчиком).
 *
 * Дерево:  a ─┬─ b            элементы: a:[e1,e2]  b:[e3]  d:[e4]
 *             └─ c ── d
 * Поддельный импортёр выдаёт ID разделов по порядку: a=101, b=102, c=103, d=104.
 */
final class SectionTreeWalkerTest extends TestCase
{
    private const XML = <<<'XML'
<export mode="section_tree" iblock_id="8">
  <section code="a">
    <name>A</name>
    <elements><element code="e1"/><element code="e2"/></elements>
    <sections>
      <section code="b">
        <elements><element code="e3"/></elements>
      </section>
      <section code="c">
        <elements/>
        <sections>
          <section code="d">
            <elements><element code="e4"/></elements>
          </section>
        </sections>
      </section>
    </sections>
  </section>
</export>
XML;

    /** Раздел -> его элементы по одному -> подразделы; родитель вложенного раздела — ID, выданный его родителю. */
    private const FULL_LOG = [
        'section:a:parent=-',
        'element:e1:section=101',
        'element:e2:section=101',
        'section:b:parent=101',
        'element:e3:section=102',
        'section:c:parent=101',
        'section:d:parent=103',
        'element:e4:section=104',
    ];

    /** Часы-счётчик: каждый вызов — +1; с крайним сроком N цикл делает ровно N проходов. */
    private function tickClock(): \Closure
    {
        $now = 0;
        return static function () use (&$now): float {
            return (float)$now++;
        };
    }

    /**
     * Обходит дерево тиками по $budget проходов (null — одним тиком без ограничения); между
     * тиками стек проходит через JSON, как STATE_JSON в БД.
     *
     * @param ImportFrame[]|null $stack Начальный стек; null — с корня
     * @return array{log: string[], sections: int, elements: int, ticks: int, report: ImportReport, firstTick: array[]}
     */
    private function walkTree(?int $budget, bool $recursive = true, ?array $stack = null, ?int $parentSectionId = null): array
    {
        $xml = new SimpleXMLElement(self::XML);
        $log = new CallLog();
        $sections = new RecordingSectionImporter($log);
        $elements = new RecordingElementImporter($log);
        $report = new ImportReport();
        $ctx = new ImportContext(8, true, '/tmp/none');
        $stack ??= SectionTreeWalker::initialStack();

        $processedSections = 0;
        $processedElements = 0;
        $ticks = 0;
        $firstTick = [];

        do {
            $walker = new SectionTreeWalker($sections, $elements, $this->tickClock());
            $result = $walker->walk($xml->section, $stack, $parentSectionId, $recursive, $ctx, $report, $budget === null ? 1e12 : (float)$budget);
            $processedSections += $result->processedSections;
            $processedElements += $result->processedElements;
            $ticks++;

            $rows = json_decode(json_encode(ImportFrame::stackToArray($result->stack)), true);
            if ($ticks === 1) {
                $firstTick = $rows;
            }
            $stack = ImportFrame::stackFromArray($rows);
        } while (!$result->isFinished() && $ticks < 1000);

        return [
            'log' => $log->entries,
            'sections' => $processedSections,
            'elements' => $processedElements,
            'ticks' => $ticks,
            'report' => $report,
            'firstTick' => $firstTick,
        ];
    }

    public function testFullWalkImportsEverythingInDepthFirstOrderWithCorrectParents(): void
    {
        $r = $this->walkTree(null);

        $this->assertSame(self::FULL_LOG, $r['log']);
        $this->assertSame(4, $r['sections']);
        $this->assertSame(4, $r['elements']);
        $this->assertSame(1, $r['ticks']);
        $this->assertSame(8, $r['report']->created);
    }

    public function testResumingAfterAnyNumberOfStepsImportsEachRecordExactlyOnce(): void
    {
        // от "каждый тик — один шаг" до "почти весь обход за тик": разрыв попадает на все фазы всех разделов
        foreach (range(1, 30) as $budget) {
            $r = $this->walkTree($budget);

            $this->assertSame(self::FULL_LOG, $r['log'], "import sequence differs at budget $budget");
            $this->assertSame(4, $r['sections'], "sections counter at budget $budget");
            $this->assertSame(4, $r['elements'], "elements counter at budget $budget");
            $this->assertSame(8, $r['report']->created, "created counter at budget $budget");
        }
    }

    public function testFirstStepOpensTheSectionAndImportsItsFirstElement(): void
    {
        $r = $this->walkTree(1);

        $this->assertCount(1, $r['firstTick']);
        $frame = $r['firstTick'][0];
        $this->assertTrue($frame['opened']);
        $this->assertSame(101, $frame['target_id']);
        $this->assertSame(TraversalFrame::PHASE_ELEMENTS, $frame['phase']);
        $this->assertSame(1, $frame['elements_index']);
    }

    public function testTickWithAnExpiredDeadlineDoesNothing(): void
    {
        $log = new CallLog();
        $stack = SectionTreeWalker::initialStack();
        $walker = new SectionTreeWalker(new RecordingSectionImporter($log), new RecordingElementImporter($log), $this->tickClock());

        $result = $walker->walk((new SimpleXMLElement(self::XML))->section, $stack, null, true, new ImportContext(8, true, '/tmp'), new ImportReport(), 0.0);

        $this->assertSame([], $log->entries);
        $this->assertSame(0, $result->processedSections);
        $this->assertFalse($result->isFinished());
        $this->assertSame($stack, $result->stack);
    }

    public function testSectionSingleImportsOnlyTheRootSectionAndItsElements(): void
    {
        foreach ([null, 1, 2, 3] as $budget) {
            $r = $this->walkTree($budget, false);

            $this->assertSame(['section:a:parent=-', 'element:e1:section=101', 'element:e2:section=101'], $r['log'], 'budget ' . var_export($budget, true));
            $this->assertSame(1, $r['sections']);
            $this->assertSame(2, $r['elements']);
        }
    }

    public function testRootSectionGoesIntoTheRequestedParentSection(): void
    {
        $r = $this->walkTree(null, true, null, 7);

        $this->assertSame('section:a:parent=7', $r['log'][0]);
        // остальные — под разделами, созданными внутри этого импорта, а не под 7
        $this->assertSame('section:b:parent=101', $r['log'][3]);
    }

    public function testEmptyElementsNodeAndSectionWithoutChildrenAreHandled(): void
    {
        $r = $this->walkTree(null);

        // раздел c без элементов (<elements/>), раздел b без подразделов
        $this->assertNotContains('element:e3:section=103', $r['log']);
        $this->assertSame(['element:e4:section=104'], array_values(array_filter($r['log'], static fn(string $l): bool => str_starts_with($l, 'element:e4'))));
    }

    public function testResumesFromAStateSavedByThePreviousVersion(): void
    {
        // STATE_JSON, записанный кодом до рефакторинга: a (ID 50), его элементы и раздел b уже импортированы
        $legacy = '{"stack":[{"path":[],"target_id":50,"opened":true,"phase":"children","elements_index":2,"children_index":1}],"counts":{"created":5,"updated":0,"skipped":0}}';
        $stack = ImportFrame::stackFromArray(json_decode($legacy, true)['stack']);

        $r = $this->walkTree(null, true, $stack);

        // c вкладывается в a по сохранённому target_id; уже импортированное не повторяется
        $this->assertSame(['section:c:parent=50', 'section:d:parent=101', 'element:e4:section=102'], $r['log']);
        $this->assertSame(2, $r['sections']);
        $this->assertSame(1, $r['elements']);
    }

    public function testResumesFromALegacyStateInsideANestedSection(): void
    {
        // два кадра: a уже взял c (children_index=2); c (ID 60) стоит перед подразделом d
        $legacy = '{"stack":['
            . '{"path":[],"target_id":50,"opened":true,"phase":"children","elements_index":2,"children_index":2},'
            . '{"path":[1],"target_id":60,"opened":true,"phase":"children","elements_index":0,"children_index":0}'
            . '],"counts":{"created":6,"updated":0,"skipped":0}}';
        $stack = ImportFrame::stackFromArray(json_decode($legacy, true)['stack']);

        $r = $this->walkTree(null, true, $stack);

        $this->assertSame(['section:d:parent=60', 'element:e4:section=101'], $r['log']);
    }

    public function testLegacyNestedStateResumesIdenticallyAtEveryBudget(): void
    {
        $legacy = '{"stack":['
            . '{"path":[],"target_id":50,"opened":true,"phase":"children","elements_index":2,"children_index":2},'
            . '{"path":[1],"target_id":60,"opened":true,"phase":"children","elements_index":0,"children_index":0}'
            . ']}';

        foreach (range(1, 8) as $budget) {
            $stack = ImportFrame::stackFromArray(json_decode($legacy, true)['stack']);
            $r = $this->walkTree($budget, true, $stack);
            $this->assertSame(['section:d:parent=60', 'element:e4:section=101'], $r['log'], "budget $budget");
        }
    }
}
