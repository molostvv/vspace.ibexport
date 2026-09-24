<?php

namespace Vspace\Ibexport\Export;

use Vspace\Ibexport\WalkResult;
use Vspace\Ibexport\XmlStreamWriter;

/**
 * Возобновляемый DFS-обход дерева разделов при экспорте (docs/xml-format.md,
 * "Модель обхода"). Один вызов walk() — одна порция работы в пределах
 * бюджета времени тика: обход идёт, пока не кончится стек кадров либо не
 * истечёт крайний срок; состояние (стек) возвращается вызывающему для
 * сохранения в STATE_JSON, и следующий тик продолжает ровно с того же места.
 *
 * Кадр стека (ExportFrame) — один раздел в обработке; каждый проход цикла
 * делает ровно один шаг над верхним кадром: открыть раздел -> страницами
 * выгрузить его элементы -> страницами перебрать подразделы (для каждого —
 * новый кадр) -> закрыть. Размер страницы и для элементов, и для подразделов —
 * ExportContext::$batchSize.
 */
final class SectionTreeWalker
{
    /** @var \Closure(): float */
    private \Closure $clock;

    /** @param (\Closure(): float)|null $clock Источник времени для сверки с крайним сроком; по умолчанию microtime(true) (подмена — для тестов) */
    public function __construct(
        private TreeSourceInterface $source,
        private SectionWriter $sections,
        private ElementWriter $elements,
        ?\Closure $clock = null
    ) {
        $this->clock = $clock ?? static fn(): float => microtime(true);
    }

    /** @return ExportFrame[] Начальный стек обхода — один кадр корневого раздела */
    public static function initialStack(int $sectionId): array
    {
        return [new ExportFrame($sectionId)];
    }

    /**
     * @param ExportFrame[] $stack Стек кадров (из initialStack() либо восстановленный из STATE_JSON)
     * @param bool $recursive true — mode=section_tree, false — mode=section_single (подразделы не обходятся, пишутся заглушки)
     * @param float $deadline Крайний срок тика (microtime(true))
     */
    public function walk(XmlStreamWriter $w, array $stack, ExportContext $ctx, bool $recursive, float $deadline): WalkResult
    {
        $processedSections = 0;
        $processedElements = 0;

        while (!empty($stack) && ($this->clock)() < $deadline) {
            $frame = $stack[count($stack) - 1];

            if (!$frame->opened) {
                $this->sections->writeOpen($w, $frame->sectionId);
                $frame->opened = true;
                $processedSections++;
            }

            if ($frame->phase === ExportFrame::PHASE_ELEMENTS) {
                if (!$frame->elementsTagOpen) {
                    $w->openTag('elements');
                    $frame->elementsTagOpen = true;
                }

                $ids = $this->source->getElementIdsPage($ctx->iblockId, $frame->sectionId, $ctx->activeOnly, $ctx->batchSize, $frame->elementsOffset);
                foreach ($ids as $id) {
                    $this->elements->writeRow($w, $id, false, $frame->sectionId);
                    $processedElements++;
                }
                $frame->elementsOffset += count($ids);

                if (count($ids) < $ctx->batchSize) {
                    $w->closeTag('elements');
                    $frame->phase = ExportFrame::PHASE_CHILDREN;
                }
            } elseif ($frame->phase === ExportFrame::PHASE_CHILDREN) {
                if (!$recursive) {
                    // section_single: фиксируем только ID/код прямых подразделов, без рекурсии (FR-2).
                    $this->sections->writeSubsectionStubs($w, $ctx->iblockId, $frame->sectionId, $ctx->activeOnly);
                    $frame->phase = ExportFrame::PHASE_DONE;
                } elseif ($frame->childrenIds === null) {
                    $frame->childrenIds = $this->source->getChildSectionIds($ctx->iblockId, $frame->sectionId, $ctx->activeOnly, $ctx->batchSize, $frame->childrenOffset);
                    $frame->childrenIndex = 0;
                    if (!empty($frame->childrenIds) && !$frame->sectionsTagOpen) {
                        $w->openTag('sections');
                        $frame->sectionsTagOpen = true;
                    }
                } elseif ($frame->childrenIndex < count($frame->childrenIds)) {
                    $childId = $frame->childrenIds[$frame->childrenIndex];
                    $frame->childrenIndex++;
                    $stack[] = new ExportFrame($childId);
                } elseif (count($frame->childrenIds) >= $ctx->batchSize) {
                    // страница была полной — за ней может быть следующая
                    $frame->childrenOffset += count($frame->childrenIds);
                    $frame->childrenIds = null;
                } else {
                    if ($frame->sectionsTagOpen) {
                        $w->closeTag('sections');
                    }
                    $frame->phase = ExportFrame::PHASE_DONE;
                }
            } else { // done — раздел полностью обработан
                $w->closeTag('section');
                array_pop($stack);
            }
        }

        return new WalkResult($stack, $processedSections, $processedElements);
    }
}
