<?php

namespace Vspace\Ibexport\Import;

use SimpleXMLElement;
use Vspace\Ibexport\WalkResult;

/**
 * Зеркало Export\SectionTreeWalker: возобновляемый DFS-обход дерева разделов
 * при импорте, только источник — уже распакованный export.xml, а не
 * постраничные SQL-выборки. Кадр стека хранит не узел XML, а его путь —
 * последовательность индексов дочерних <sections><section> от корня
 * (сам XML разбирается заново на каждый тик), поэтому стек легко
 * сериализуется в STATE_JSON между тиками.
 *
 * Один вызов walk() — порция работы в пределах бюджета времени тика; каждый
 * проход цикла делает ровно один шаг над верхним кадром: импортировать раздел
 * -> импортировать его элементы по одному -> перейти к подразделам -> закрыть.
 */
final class SectionTreeWalker
{
    public function __construct(
        private SectionImporter $sections,
        private ElementImporter $elements
    ) {
    }

    /** @return array[] Начальный стек обхода — один кадр корневого <section> (пустой путь) */
    public static function initialStack(): array
    {
        return [self::newFrame([])];
    }

    /**
     * @param SimpleXMLElement $rootSection Корневой <section> export.xml
     * @param array[] $stack Стек кадров (из initialStack() либо восстановленный из STATE_JSON)
     * @param int|null $parentSectionId В какой раздел целевого инфоблока вкладывается корневой раздел (null — в корень)
     * @param bool $recursive true — mode=section_tree, false — mode=section_single (вложенные подразделы не импортируются)
     * @param float $deadline Крайний срок тика (microtime(true))
     */
    public function walk(
        SimpleXMLElement $rootSection,
        array $stack,
        ?int $parentSectionId,
        bool $recursive,
        ImportContext $ctx,
        ImportReport $report,
        float $deadline
    ): WalkResult {
        $processedSections = 0;
        $processedElements = 0;

        while (!empty($stack) && microtime(true) < $deadline) {
            $i = count($stack) - 1;
            $frame = &$stack[$i];
            $node = self::nodeByPath($rootSection, $frame['path']);

            if (!$frame['opened']) {
                $parentId = $i > 0 ? $stack[$i - 1]['target_id'] : $parentSectionId;
                $frame['target_id'] = $this->sections->import($node, $parentId, $ctx, $report);
                $frame['opened'] = true;
                $processedSections++;
            }

            if ($frame['phase'] === 'elements') {
                $elements = isset($node->elements->element) ? $node->elements->element : [];
                if ($frame['elements_index'] < count($elements)) {
                    $elementNode = $elements[$frame['elements_index']];
                    $frame['elements_index']++;
                    $this->elements->import($elementNode, $frame['target_id'], $ctx, $report, false);
                    $processedElements++;
                } else {
                    $frame['phase'] = 'children';
                }
            } elseif ($frame['phase'] === 'children') {
                if (!$recursive) {
                    $frame['phase'] = 'done';
                } else {
                    $children = isset($node->sections->section) ? $node->sections->section : [];
                    if ($frame['children_index'] < count($children)) {
                        $childPath = array_merge($frame['path'], [$frame['children_index']]);
                        $frame['children_index']++;
                        $stack[] = self::newFrame($childPath);
                    } else {
                        $frame['phase'] = 'done';
                    }
                }
            } else { // done
                array_pop($stack);
            }
            unset($frame);
        }

        return new WalkResult($stack, $processedSections, $processedElements);
    }

    private static function newFrame(array $path): array
    {
        return [
            'path' => $path,
            'target_id' => null,
            'opened' => false,
            'phase' => 'elements',
            'elements_index' => 0,
            'children_index' => 0,
        ];
    }

    /** Идёт от корневого <section> по последовательности индексов дочерних <sections><section>. */
    private static function nodeByPath(SimpleXMLElement $root, array $path): SimpleXMLElement
    {
        $node = $root;
        foreach ($path as $index) {
            $node = $node->sections->section[$index];
        }
        return $node;
    }
}
