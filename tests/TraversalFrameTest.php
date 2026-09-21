<?php

namespace Vspace\Ibexport\Tests;

use PHPUnit\Framework\TestCase;
use Vspace\Ibexport\Export\ExportFrame;
use Vspace\Ibexport\Import\ImportFrame;
use Vspace\Ibexport\TraversalFrame;

/**
 * Кадр обхода — value-объект, сериализуемый в STATE_JSON. Главное свойство:
 * формат массива тот же, что писал код до рефакторинга, поэтому незавершённые
 * задания, начатые до выкладки, продолжаются без миграции.
 */
final class TraversalFrameTest extends TestCase
{
    /** Кадры экспорта/импорта, как их сохранял код до рефакторинга (реальные значения из незавершённых заданий). */
    private const LEGACY_EXPORT_STATE = '{"stack":[{"section_id":47,"opened":true,"phase":"elements","elements_offset":461,"elements_tag_open":true,"children_ids":null,"children_index":0,"sections_tag_open":false}]}';
    private const LEGACY_IMPORT_STATE = '{"stack":[{"path":[],"target_id":136,"opened":true,"phase":"elements","elements_index":72,"children_index":0}],"counts":{"created":73,"updated":0,"skipped":0}}';

    public function testExportFrameRoundTripKeepsEveryField(): void
    {
        $frame = new ExportFrame(12);
        $frame->opened = true;
        $frame->phase = TraversalFrame::PHASE_CHILDREN;
        $frame->elementsOffset = 400;
        $frame->elementsTagOpen = true;
        $frame->childrenIds = [5, 6, 7];
        $frame->childrenIndex = 2;
        $frame->sectionsTagOpen = true;

        $restored = ExportFrame::fromArray($frame->toArray());

        $this->assertEquals($frame, $restored);
        $this->assertSame($frame->toArray(), $restored->toArray());
    }

    public function testImportFrameRoundTripKeepsEveryField(): void
    {
        $frame = new ImportFrame([0, 2, 1]);
        $frame->targetId = 77;
        $frame->opened = true;
        $frame->phase = TraversalFrame::PHASE_DONE;
        $frame->elementsIndex = 9;
        $frame->childrenIndex = 3;

        $restored = ImportFrame::fromArray($frame->toArray());

        $this->assertEquals($frame, $restored);
        $this->assertSame($frame->toArray(), $restored->toArray());
    }

    public function testFreshFramesStartInTheElementsPhaseUnopened(): void
    {
        $export = new ExportFrame(1);
        $this->assertFalse($export->opened);
        $this->assertSame(TraversalFrame::PHASE_ELEMENTS, $export->phase);
        $this->assertSame(0, $export->elementsOffset);
        $this->assertFalse($export->elementsTagOpen);
        $this->assertNull($export->childrenIds);
        $this->assertSame(0, $export->childrenIndex);
        $this->assertFalse($export->sectionsTagOpen);

        $import = new ImportFrame([]);
        $this->assertFalse($import->opened);
        $this->assertSame(TraversalFrame::PHASE_ELEMENTS, $import->phase);
        $this->assertNull($import->targetId);
        $this->assertSame(0, $import->elementsIndex);
        $this->assertSame(0, $import->childrenIndex);
    }

    public function testExportFrameArrayKeysAndOrderMatchTheHistoricalFormat(): void
    {
        $this->assertSame(
            ['section_id', 'opened', 'phase', 'elements_offset', 'elements_tag_open', 'children_ids', 'children_index', 'sections_tag_open'],
            array_keys((new ExportFrame(1))->toArray())
        );
    }

    public function testImportFrameArrayKeysAndOrderMatchTheHistoricalFormat(): void
    {
        $this->assertSame(
            ['path', 'target_id', 'opened', 'phase', 'elements_index', 'children_index'],
            array_keys((new ImportFrame([]))->toArray())
        );
    }

    public function testLegacyExportStateIsReadableAndRewritesByteForByte(): void
    {
        $state = json_decode(self::LEGACY_EXPORT_STATE, true);

        $stack = ExportFrame::stackFromArray($state['stack']);

        $this->assertCount(1, $stack);
        $this->assertSame(47, $stack[0]->sectionId);
        $this->assertTrue($stack[0]->opened);
        $this->assertSame(TraversalFrame::PHASE_ELEMENTS, $stack[0]->phase);
        $this->assertSame(461, $stack[0]->elementsOffset);
        $this->assertTrue($stack[0]->elementsTagOpen);
        $this->assertNull($stack[0]->childrenIds);
        $this->assertSame(self::LEGACY_EXPORT_STATE, json_encode(['stack' => ExportFrame::stackToArray($stack)]));
    }

    public function testLegacyImportStateIsReadableAndRewritesByteForByte(): void
    {
        $state = json_decode(self::LEGACY_IMPORT_STATE, true);

        $stack = ImportFrame::stackFromArray($state['stack']);

        $this->assertCount(1, $stack);
        $this->assertSame([], $stack[0]->path);
        $this->assertSame(136, $stack[0]->targetId);
        $this->assertSame(72, $stack[0]->elementsIndex);
        $this->assertSame(
            self::LEGACY_IMPORT_STATE,
            json_encode(['stack' => ImportFrame::stackToArray($stack), 'counts' => $state['counts']])
        );
    }

    public function testEmptyChildrenListIsNotConfusedWithNotYetFetched(): void
    {
        // null = подразделы ещё не выбраны; [] = выбраны, их нет — обход обязан различать эти состояния
        $notFetched = ExportFrame::fromArray(['section_id' => 1, 'children_ids' => null]);
        $fetchedEmpty = ExportFrame::fromArray(['section_id' => 1, 'children_ids' => []]);

        $this->assertNull($notFetched->childrenIds);
        $this->assertSame([], $fetchedEmpty->childrenIds);
        $this->assertSame([], ExportFrame::fromArray($fetchedEmpty->toArray())->childrenIds);
    }

    public function testMissingOptionalKeysFallBackToDefaults(): void
    {
        $export = ExportFrame::fromArray(['section_id' => '15']);
        $this->assertSame(15, $export->sectionId);
        $this->assertFalse($export->opened);
        $this->assertSame(TraversalFrame::PHASE_ELEMENTS, $export->phase);
        $this->assertSame(0, $export->elementsOffset);
        $this->assertFalse($export->sectionsTagOpen);

        $import = ImportFrame::fromArray([]);
        $this->assertSame([], $import->path);
        $this->assertNull($import->targetId);
        $this->assertFalse($import->opened);
    }

    public function testStackSurvivesTheJsonRoundTripThroughStateJson(): void
    {
        $root = new ExportFrame(1);
        $root->opened = true;
        $root->phase = TraversalFrame::PHASE_CHILDREN;
        $root->childrenIds = [2, 3];
        $root->childrenIndex = 1;
        $root->sectionsTagOpen = true;
        $child = new ExportFrame(2);
        $child->opened = true;
        $child->elementsOffset = 3;
        $child->elementsTagOpen = true;

        $json = json_encode(['stack' => ExportFrame::stackToArray([$root, $child])]);
        $restored = ExportFrame::stackFromArray(json_decode($json, true)['stack']);

        $this->assertEquals([$root, $child], $restored);
    }

    public function testStackHelpersReindexAndPreserveOrder(): void
    {
        $frames = [3 => new ImportFrame([0]), 7 => new ImportFrame([0, 1])];

        $rows = ImportFrame::stackToArray($frames);

        $this->assertSame([[0], [0, 1]], array_column($rows, 'path'));
        $this->assertSame([0, 1], array_keys($rows));
        $this->assertSame([], ImportFrame::stackFromArray([]));
    }
}
