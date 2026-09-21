<?php

namespace Vspace\Ibexport\Import;

use CIBlockProperty;
use CIBlockPropertyEnum;

/** Боевой источник определений свойств — классический API инфоблоков; результаты кэшируются на время жизни экземпляра. */
final class BitrixPropertySource implements PropertySourceInterface
{
    /** @var array<int, array<string, array{ID:int, MULTIPLE:string}>> */
    private array $definitions = [];

    /** @var array<int, array<string, int>> */
    private array $enumMaps = [];

    public function getDefinitions(int $iblockId): array
    {
        if (isset($this->definitions[$iblockId])) {
            return $this->definitions[$iblockId];
        }

        $defs = [];
        $res = CIBlockProperty::GetList([], ['IBLOCK_ID' => $iblockId, 'ACTIVE' => 'Y']);
        while ($prop = $res->Fetch()) {
            if ($prop['CODE'] !== '') {
                $defs[$prop['CODE']] = ['ID' => (int)$prop['ID'], 'MULTIPLE' => $prop['MULTIPLE']];
            }
        }

        return $this->definitions[$iblockId] = $defs;
    }

    /** @var array<int, string[]> */
    private array $sectionUserFields = [];

    public function getSectionUserFieldCodes(int $iblockId): array
    {
        if (!isset($this->sectionUserFields[$iblockId])) {
            global $USER_FIELD_MANAGER;
            $this->sectionUserFields[$iblockId] = array_keys($USER_FIELD_MANAGER->GetUserFields('IBLOCK_' . $iblockId . '_SECTION'));
        }

        return $this->sectionUserFields[$iblockId];
    }

    public function getEnumMap(int $propertyId): array
    {
        if (isset($this->enumMaps[$propertyId])) {
            return $this->enumMaps[$propertyId];
        }

        $map = [];
        $res = CIBlockPropertyEnum::GetList([], ['PROPERTY_ID' => $propertyId]);
        while ($enum = $res->Fetch()) {
            $map[$enum['VALUE']] = (int)$enum['ID'];
        }

        return $this->enumMaps[$propertyId] = $map;
    }
}
