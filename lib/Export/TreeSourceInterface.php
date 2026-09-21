<?php

namespace Vspace\Ibexport\Export;

/**
 * Тонкая граница между обходом дерева разделов и базой данных — по образцу
 * YandexDisk\Http\TransportInterface. Нужна, чтобы логику обхода
 * (SectionTreeWalker: переходы между фазами, возобновление между тиками)
 * можно было покрыть юнит-тестами с поддельным источником, без ядра Bitrix.
 * Боевая реализация — BitrixTreeSource поверх ElementTable/SectionTable.
 */
interface TreeSourceInterface
{
    /** @return int[] ID прямых подразделов в порядке обхода (SORT, NAME) */
    public function getChildSectionIds(int $iblockId, int $parentId, bool $activeOnly): array;

    /** @return int[] ID элементов раздела — страница $limit записей, начиная с $offset (порядок SORT, ID) */
    public function getElementIdsPage(int $iblockId, int $sectionId, bool $activeOnly, int $limit, int $offset): array;

    /** Число элементов, лежащих непосредственно в разделе. */
    public function countElements(int $iblockId, int $sectionId, bool $activeOnly): int;
}
