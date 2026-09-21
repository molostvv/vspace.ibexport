<?php

namespace Vspace\Ibexport\Import;

use SimpleXMLElement;
use Vspace\Ibexport\WalkResult;

/**
 * Зеркало Export\SectionTreeWalker: возобновляемый DFS-обход дерева разделов
 * при импорте, только источник — уже распакованный export.xml, а не
 * постраничные SQL-выборки. Кадр стека (ImportFrame) хранит не узел XML, а
 * его путь — последовательность индексов дочерних <sections><section> от
 * корня (сам XML разбирается заново на каждый тик), поэтому стек легко
 * сериализуется в STATE_JSON между тиками.
 *
 * Один вызов walk() — порция работы в пределах бюджета времени тика; каждый
 * проход цикла делает ровно один шаг над верхним кадром: импортировать раздел
 * -> импортировать его элементы по одному -> перейти к подразделам -> закрыть.
 */
final class SectionTreeWalker
{
    /** @var \Closure(): float */
    private \Closure $clock;

    /** @param (\Closure(): float)|null $clock Источник времени для сверки с крайним сроком; по умолчанию microtime(true) (подмена — для тестов) */
    public function __construct(
        private SectionImporter $sections,
        private ElementImporter $elements,
        ?\Closure $clock = null
    ) {
        $this->clock = $clock ?? static fn(): float => microtime(true);
    }

    /** @return ImportFrame[] Начальный стек обхода — один кадр корневого <section> (пустой путь) */
    public static function initialStack(): array
    {
        return [new ImportFrame([])];
    }

    /**
     * @param SimpleXMLElement $rootSection Корневой <section> export.xml
     * @param ImportFrame[] $stack Стек кадров (из initialStack() либо восстановленный из STATE_JSON)
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

        while (!empty($stack) && ($this->clock)() < $deadline) {
            $i = count($stack) - 1;
            $frame = $stack[$i];
            $node = self::nodeByPath($rootSection, $frame->path);

            if (!$frame->opened) {
                $parentId = $i > 0 ? $stack[$i - 1]->targetId : $parentSectionId;
                $frame->targetId = $this->sections->import($node, $parentId, $ctx, $report);
                $frame->opened = true;
                $processedSections++;
            }

            if ($frame->phase === ImportFrame::PHASE_ELEMENTS) {
                $elements = isset($node->elements->element) ? $node->elements->element : [];
                if ($frame->elementsIndex < count($elements)) {
                    $elementNode = $elements[$frame->elementsIndex];
                    $frame->elementsIndex++;
                    $this->elements->import($elementNode, $frame->targetId, $ctx, $report, false);
                    $processedElements++;
                } else {
                    $frame->phase = ImportFrame::PHASE_CHILDREN;
                }
            } elseif ($frame->phase === ImportFrame::PHASE_CHILDREN) {
                if (!$recursive) {
                    $frame->phase = ImportFrame::PHASE_DONE;
                } else {
                    $children = isset($node->sections->section) ? $node->sections->section : [];
                    if ($frame->childrenIndex < count($children)) {
                        $childPath = array_merge($frame->path, [$frame->childrenIndex]);
                        $frame->childrenIndex++;
                        $stack[] = new ImportFrame($childPath);
                    } else {
                        $frame->phase = ImportFrame::PHASE_DONE;
                    }
                }
            } else { // done
                array_pop($stack);
            }
        }

        return new WalkResult($stack, $processedSections, $processedElements);
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
