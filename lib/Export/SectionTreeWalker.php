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
 * Кадр стека — один раздел в обработке; каждый проход цикла делает ровно
 * один шаг над верхним кадром: открыть раздел -> страницами выгрузить его
 * элементы -> перебрать подразделы (для каждого — новый кадр) -> закрыть.
 */
final class SectionTreeWalker
{
    public function __construct(
        private TreeSourceInterface $source,
        private SectionWriter $sections,
        private ElementWriter $elements
    ) {
    }

    /** @return array[] Начальный стек обхода — один кадр корневого раздела */
    public static function initialStack(int $sectionId): array
    {
        return [self::newFrame($sectionId)];
    }

    /**
     * @param array[] $stack Стек кадров (из initialStack() либо восстановленный из STATE_JSON)
     * @param bool $recursive true — mode=section_tree, false — mode=section_single (подразделы не обходятся, пишутся заглушки)
     * @param float $deadline Крайний срок тика (microtime(true))
     */
    public function walk(XmlStreamWriter $w, array $stack, ExportContext $ctx, bool $recursive, float $deadline): WalkResult
    {
        $processedSections = 0;
        $processedElements = 0;

        while (!empty($stack) && microtime(true) < $deadline) {
            $i = count($stack) - 1;
            $frame = &$stack[$i];

            if (!$frame['opened']) {
                $this->sections->writeOpen($w, $frame['section_id']);
                $frame['opened'] = true;
                $processedSections++;
            }

            if ($frame['phase'] === 'elements') {
                if (!$frame['elements_tag_open']) {
                    $w->openTag('elements');
                    $frame['elements_tag_open'] = true;
                }

                $ids = $this->source->getElementIdsPage($ctx->iblockId, $frame['section_id'], $ctx->activeOnly, $ctx->batchSize, $frame['elements_offset']);
                foreach ($ids as $id) {
                    $this->elements->writeRow($w, $id);
                    $processedElements++;
                }
                $frame['elements_offset'] += count($ids);

                if (count($ids) < $ctx->batchSize) {
                    $w->closeTag('elements');
                    $frame['phase'] = 'children';
                }
            } elseif ($frame['phase'] === 'children') {
                if (!$recursive) {
                    // section_single: фиксируем только ID/код прямых подразделов, без рекурсии (FR-2).
                    $this->sections->writeSubsectionStubs($w, $ctx->iblockId, $frame['section_id'], $ctx->activeOnly);
                    $frame['phase'] = 'done';
                } elseif ($frame['children_ids'] === null) {
                    $frame['children_ids'] = $this->source->getChildSectionIds($ctx->iblockId, $frame['section_id'], $ctx->activeOnly);
                    $frame['children_index'] = 0;
                    if (!empty($frame['children_ids'])) {
                        $w->openTag('sections');
                        $frame['sections_tag_open'] = true;
                    }
                } elseif ($frame['children_index'] < count($frame['children_ids'])) {
                    $childId = $frame['children_ids'][$frame['children_index']];
                    $frame['children_index']++;
                    $stack[] = self::newFrame($childId);
                } else {
                    if (!empty($frame['sections_tag_open'])) {
                        $w->closeTag('sections');
                    }
                    $frame['phase'] = 'done';
                }
            } else { // done — раздел полностью обработан
                $w->closeTag('section');
                array_pop($stack);
            }
            unset($frame);
        }

        return new WalkResult($stack, $processedSections, $processedElements);
    }

    private static function newFrame(int $sectionId): array
    {
        return [
            'section_id' => $sectionId,
            'opened' => false,
            'phase' => 'elements',
            'elements_offset' => 0,
            'elements_tag_open' => false,
            'children_ids' => null,
            'children_index' => 0,
            'sections_tag_open' => false,
        ];
    }
}
