<?php

namespace Vspace\Ibexport\Export;

use Bitrix\Iblock\ElementTable;
use Bitrix\Iblock\SectionTable;
use Bitrix\Main\Entity\ExpressionField;

/** Боевой источник дерева: D7 ORM (ElementTable/SectionTable) — только чтение. */
final class BitrixTreeSource implements TreeSourceInterface
{
    public function getChildSectionIds(int $iblockId, int $parentId, bool $activeOnly): array
    {
        $filter = ['IBLOCK_ID' => $iblockId, 'IBLOCK_SECTION_ID' => $parentId];
        if ($activeOnly) {
            $filter['ACTIVE'] = 'Y';
        }

        $ids = [];
        $res = SectionTable::getList([
            'filter' => $filter,
            'select' => ['ID'],
            'order' => ['SORT' => 'ASC', 'NAME' => 'ASC'],
            'limit' => 5000,
        ]);
        while ($row = $res->fetch()) {
            $ids[] = (int)$row['ID'];
        }

        return $ids;
    }

    public function getElementIdsPage(int $iblockId, int $sectionId, bool $activeOnly, int $limit, int $offset): array
    {
        $filter = ['IBLOCK_ID' => $iblockId, 'IBLOCK_SECTION_ID' => $sectionId];
        if ($activeOnly) {
            $filter['ACTIVE'] = 'Y';
        }

        $rows = [];
        $res = ElementTable::getList([
            'filter' => $filter,
            'select' => ['ID'],
            'order' => ['SORT' => 'ASC', 'ID' => 'ASC'],
            'limit' => $limit,
            'offset' => $offset,
        ]);
        while ($row = $res->fetch()) {
            $rows[] = (int)$row['ID'];
        }

        return $rows;
    }

    public function countElements(int $iblockId, int $sectionId, bool $activeOnly): int
    {
        $filter = ['IBLOCK_ID' => $iblockId, 'IBLOCK_SECTION_ID' => $sectionId];
        if ($activeOnly) {
            $filter['ACTIVE'] = 'Y';
        }

        $row = ElementTable::getList([
            'filter' => $filter,
            'select' => ['CNT'],
            'runtime' => [new ExpressionField('CNT', 'COUNT(*)')],
        ])->fetch();

        return (int)($row['CNT'] ?? 0);
    }
}
