<?php

namespace Vspace\Ibexport\Export;

/**
 * Тонкая граница между обходом дерева разделов и базой данных — по образцу
 * YandexDisk\Http\TransportInterface. Нужна, чтобы логику обхода
 * (SectionTreeWalker: переходы между фазами, возобновление между тиками)
 * можно было покрыть юнит-тестами с поддельным источником, без ядра Bitrix.
 * Боевая реализация — BitrixTreeSource поверх SectionTable/SectionElementTable.
 */
interface TreeSourceInterface
{
    /**
     * @param int $limit Размер страницы; 0 — все подразделы сразу
     * @return int[] ID прямых подразделов в порядке обхода (SORT, NAME, ID), начиная с $offset
     */
    public function getChildSectionIds(int $iblockId, int $parentId, bool $activeOnly, int $limit = 0, int $offset = 0): array;

    /**
     * @return int[] ID элементов, привязанных к разделу (основной или дополнительной привязкой), — страница $limit
     *               записей, начиная с $offset (порядок SORT, ID)
     */
    public function getElementIdsPage(int $iblockId, int $sectionId, bool $activeOnly, int $limit, int $offset): array;

    /** Число элементов, привязанных к разделу (основной или дополнительной привязкой). */
    public function countElements(int $iblockId, int $sectionId, bool $activeOnly): int;
}
