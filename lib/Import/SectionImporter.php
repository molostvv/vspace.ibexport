<?php

namespace Vspace\Ibexport\Import;

use Bitrix\Iblock\SectionTable;
use CIBlockSection;
use SimpleXMLElement;

/**
 * Создаёт либо обновляет один раздел по его XML-узлу: совпадение по CODE и
 * включённое "Обновлять существующие по коду" — Update(), нет совпадения —
 * Add() (docs/import-format.md). Запись — классический API (CIBlockSection).
 */
class SectionImporter extends AbstractNodeImporter
{
    /** @return int ID найденного/созданного/обновлённого раздела в целевом инфоблоке */
    public function import(SimpleXMLElement $node, ?int $parentId, ImportContext $ctx, ImportReport $report): int
    {
        $iblockId = $ctx->iblockId;
        $code = trim((string)$node['code']);
        $fields = [
            'IBLOCK_ID' => $iblockId,
            'NAME' => (string)$node->name,
            'ACTIVE' => (string)$node['active'] === 'N' ? 'N' : 'Y',
            'SORT' => self::readSort($node),
            'DESCRIPTION' => (string)$node->description,
        ];
        if ($code !== '') {
            $fields['CODE'] = $code;
        }
        if ($parentId) {
            $fields['IBLOCK_SECTION_ID'] = $parentId;
        }

        $this->applyFileField($fields, 'PICTURE', $node->picture, $ctx, $report);
        $this->applyUserFields($fields, $node->properties);

        $existingId = $code !== '' ? $this->findByCode(SectionTable::class, $iblockId, $code) : null;

        if ($existingId) {
            if ($ctx->updateByCode) {
                $section = new CIBlockSection();
                if (!$section->Update($existingId, $fields)) {
                    $report->addWarning('Раздел "' . $fields['NAME'] . '" (код ' . $code . '): ' . $section->LAST_ERROR);
                }
                $report->updated++;
            } else {
                $report->skipped++;
            }
            return $existingId;
        }

        $section = new CIBlockSection();
        $newId = $section->Add($fields);
        if (!$newId) {
            throw new \Exception('Не удалось создать раздел "' . $fields['NAME'] . '": ' . $section->LAST_ERROR);
        }
        $report->created++;

        return (int)$newId;
    }

    /** UF_* поля раздела экспортированы обычным текстом (см. Export\SectionWriter) — ставятся как есть. */
    private function applyUserFields(array &$fields, SimpleXMLElement $properties): void
    {
        if (!isset($properties->property)) {
            return;
        }
        foreach ($properties->property as $prop) {
            $code = (string)$prop['code'];
            if (strpos($code, 'UF_') === 0) {
                $fields[$code] = (string)$prop;
            }
        }
    }
}
