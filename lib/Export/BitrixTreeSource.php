<?php

namespace Vspace\Ibexport\Export;

use Bitrix\Iblock\SectionElementTable;
use Bitrix\Iblock\SectionTable;
use Bitrix\Main\ORM\Fields\ExpressionField;
use Bitrix\Main\ORM\Query\Query;

/**
 * Боевой источник дерева: D7 ORM, только чтение.
 *
 * Элементы раздела берутся из таблицы привязок b_iblock_section_element (SectionElementTable), а не по
 * ElementTable::IBLOCK_SECTION_ID: иначе элемент, у которого раздел в выгрузке только дополнительный, в
 * архив не попадал бы. Привязки через свойства (ADDITIONAL_PROPERTY_ID) и копии документооборота не берутся.
 */
final class BitrixTreeSource implements TreeSourceInterface
{
    public function getChildSectionIds(int $iblockId, int $parentId, bool $activeOnly, int $limit = 0, int $offset = 0): array
    {
        $filter = ['IBLOCK_ID' => $iblockId, 'IBLOCK_SECTION_ID' => $parentId];
        if ($activeOnly) {
            $filter['ACTIVE'] = 'Y';
        }

        $params = [
            'filter' => $filter,
            'select' => ['ID'],
            'order' => ['SORT' => 'ASC', 'NAME' => 'ASC', 'ID' => 'ASC'],
        ];
        if ($limit > 0) {
            $params['limit'] = $limit;
            $params['offset'] = $offset;
        }

        $ids = [];
        $res = SectionTable::getList($params);
        while ($row = $res->fetch()) {
            $ids[] = (int)$row['ID'];
        }

        return $ids;
    }

    public function getElementIdsPage(int $iblockId, int $sectionId, bool $activeOnly, int $limit, int $offset): array
    {
        $res = $this->elementLinks($iblockId, $sectionId, $activeOnly)
            ->setSelect(['IBLOCK_ELEMENT_ID'])
            ->setOrder(['IBLOCK_ELEMENT.SORT' => 'ASC', 'IBLOCK_ELEMENT_ID' => 'ASC'])
            ->setLimit($limit)
            ->setOffset($offset)
            ->exec();

        $ids = [];
        while ($row = $res->fetch()) {
            $ids[] = (int)$row['IBLOCK_ELEMENT_ID'];
        }

        return $ids;
    }

    public function countElements(int $iblockId, int $sectionId, bool $activeOnly): int
    {
        $row = $this->elementLinks($iblockId, $sectionId, $activeOnly)
            ->registerRuntimeField(new ExpressionField('CNT', 'COUNT(*)'))
            ->setSelect(['CNT'])
            ->exec()
            ->fetch();

        return (int)($row['CNT'] ?? 0);
    }

    /** Привязки элементов инфоблока к разделу: основные и дополнительные, без привязок через свойства и копий документооборота. */
    private function elementLinks(int $iblockId, int $sectionId, bool $activeOnly): Query
    {
        $query = SectionElementTable::query()
            ->where('IBLOCK_SECTION_ID', $sectionId)
            ->whereNull('ADDITIONAL_PROPERTY_ID')
            ->where('IBLOCK_ELEMENT.IBLOCK_ID', $iblockId)
            ->whereNull('IBLOCK_ELEMENT.WF_PARENT_ELEMENT_ID');
        if ($activeOnly) {
            $query->where('IBLOCK_ELEMENT.ACTIVE', 'Y');
        }

        return $query;
    }
}
