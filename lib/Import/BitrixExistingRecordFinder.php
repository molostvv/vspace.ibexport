<?php

namespace Vspace\Ibexport\Import;

use Bitrix\Iblock\ElementTable;
use Bitrix\Iblock\SectionTable;

/** Тот же запрос, что делают SectionImporter/ElementImporter при сопоставлении (AbstractNodeImporter::findByMatch()) — D7 ORM, только чтение. */
final class BitrixExistingRecordFinder implements ExistingRecordFinderInterface
{
    public function findId(string $kind, int $iblockId, array $match): ?int
    {
        $ormClass = $kind === self::KIND_SECTION ? SectionTable::class : ElementTable::class;
        $row = $ormClass::getList([
            'filter' => ['IBLOCK_ID' => $iblockId] + $match,
            'select' => ['ID'],
            'limit' => 1,
        ])->fetch();

        return $row ? (int)$row['ID'] : null;
    }
}
