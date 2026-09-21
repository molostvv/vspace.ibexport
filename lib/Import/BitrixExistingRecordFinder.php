<?php

namespace Vspace\Ibexport\Import;

use Bitrix\Iblock\ElementTable;
use Bitrix\Iblock\SectionTable;

/** Тот же запрос, что делают SectionImporter/ElementImporter при сопоставлении (AbstractNodeImporter::findMatch()) — D7 ORM, только чтение. */
final class BitrixExistingRecordFinder implements ExistingRecordFinderInterface
{
    public function find(string $kind, int $iblockId, array $match): array
    {
        $ormClass = $kind === self::KIND_SECTION ? SectionTable::class : ElementTable::class;
        $rows = $ormClass::getList([
            'filter' => ['IBLOCK_ID' => $iblockId] + $match,
            'select' => ['ID', 'NAME'],
            'order' => ['ID' => 'ASC'],
            'limit' => 2,
        ])->fetchAll();

        return array_map(static fn(array $row): array => ['id' => (int)$row['ID'], 'name' => (string)$row['NAME']], $rows);
    }
}
