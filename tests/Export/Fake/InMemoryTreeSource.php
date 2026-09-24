<?php

namespace Vspace\Ibexport\Tests\Export\Fake;

use Vspace\Ibexport\Export\TreeSourceInterface;

/** Дерево разделов в памяти вместо ElementTable/SectionTable; записывает обращения для ассертов. */
final class InMemoryTreeSource implements TreeSourceInterface
{
    /** @var array<int, array{string, int, int}> [метод, ID раздела, offset] */
    public array $calls = [];

    /**
     * @param array<int, int[]> $children ID раздела => ID прямых подразделов
     * @param array<int, int[]> $elements ID раздела => ID элементов в порядке выгрузки
     */
    public function __construct(private array $children, private array $elements)
    {
    }

    public function getChildSectionIds(int $iblockId, int $parentId, bool $activeOnly, int $limit = 0, int $offset = 0): array
    {
        $this->calls[] = ['children', $parentId, $offset];
        $ids = $this->children[$parentId] ?? [];
        return $limit > 0 ? array_slice($ids, $offset, $limit) : $ids;
    }

    public function getElementIdsPage(int $iblockId, int $sectionId, bool $activeOnly, int $limit, int $offset): array
    {
        $this->calls[] = ['elements', $sectionId, $offset];
        return array_slice($this->elements[$sectionId] ?? [], $offset, $limit);
    }

    public function countElements(int $iblockId, int $sectionId, bool $activeOnly): int
    {
        return count($this->elements[$sectionId] ?? []);
    }
}
