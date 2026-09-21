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
        $match = self::matchFilter($code, trim((string)$node['xml_id']), $ctx->matchByXmlId);
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

        $found = $this->findMatch(SectionTable::class, $iblockId, $match);
        $existingId = $found['id'];
        if ($found['ambiguous']) {
            // Сам раздел не обновляем, но вложенные записи надо куда-то класть — в раздел с наименьшим ID.
            $report->addWarning('Раздел "' . $fields['NAME'] . '" (' . self::matchLabel($match) . '): в целевом инфоблоке несколько разделов с таким XML_ID, раздел не обновлён; вложенные записи помещены в раздел ID ' . $existingId . '.');
            $report->skipped++;
            return $existingId;
        }

        if ($existingId) {
            if ($ctx->updateByCode) {
                $section = new CIBlockSection();
                if (!$section->Update($existingId, $fields)) {
                    $report->addWarning('Раздел "' . $fields['NAME'] . '" (' . self::matchLabel($match) . '): ' . $section->LAST_ERROR);
                }
                $report->updated++;
            } else {
                $report->skipped++;
            }
            return $existingId;
        }

        if (isset($match['XML_ID'])) {
            $fields['XML_ID'] = $match['XML_ID']; // без этого повторный импорт снова не найдёт запись
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
