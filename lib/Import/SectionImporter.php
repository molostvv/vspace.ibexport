<?php

namespace Vspace\Ibexport\Import;

use Bitrix\Iblock\SectionTable;
use Bitrix\Main\Localization\Loc;
use CIBlockSection;
use SimpleXMLElement;
use Vspace\Ibexport\DateValue;
use Vspace\Ibexport\SeoTemplates;

Loc::loadMessages(__FILE__);

/**
 * Создаёт либо обновляет один раздел по его XML-узлу: совпадение по CODE и
 * включённое "Обновлять существующие по коду" — Update(), нет совпадения —
 * Add() (docs/import-format.md). Запись — классический API (CIBlockSection).
 */
class SectionImporter extends AbstractNodeImporter
{
    public function __construct(FileArrayFactoryInterface $files, private PropertySourceInterface $source)
    {
        parent::__construct($files);
    }

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
        // В архивах до 1.1.0 типа описания нет — тогда он не передаётся (у нового раздела будет "text").
        $descriptionType = (string)$node->description['type'];
        if ($descriptionType === 'html' || $descriptionType === 'text') {
            $fields['DESCRIPTION_TYPE'] = $descriptionType;
        }
        if ($code !== '') {
            $fields['CODE'] = $code;
        }
        if ($parentId) {
            $fields['IBLOCK_SECTION_ID'] = $parentId;
        }

        $this->applyFileField($fields, 'PICTURE', $node->picture, $ctx, $report);
        $this->applyUserFields($fields, $node->properties, $iblockId, $report);

        $found = $this->findMatch(SectionTable::class, $iblockId, $match);
        $existingId = $found['id'];
        if ($found['ambiguous']) {
            // Сам раздел не обновляем, но вложенные записи надо куда-то класть — в раздел с наименьшим ID.
            $report->addWarning(Loc::getMessage('IBX_SECTION_AMBIGUOUS', ['#NAME#' => $fields['NAME'], '#KEY#' => self::matchLabel($match), '#ID#' => $existingId]));
            $report->skipped++;
            return $existingId;
        }

        if ($existingId) {
            if (!$ctx->updateByCode) {
                $report->skipped++;
                return $existingId;
            }
            $fields += $this->seoFields($node, SeoTemplates::ENTITY_SECTION, $iblockId, $existingId, $report);
            $section = new CIBlockSection();
            if ($section->Update($existingId, $fields)) {
                $report->updated++;
            } else {
                // Раздел остаётся прежним, но вложенные записи по-прежнему кладутся в него.
                $report->addWarning(Loc::getMessage('IBX_SECTION_UPDATE_FAILED', ['#NAME#' => $fields['NAME'], '#KEY#' => self::matchLabel($match), '#ERROR#' => $section->LAST_ERROR]));
                $report->skipped++;
            }
            return $existingId;
        }

        if (isset($match['XML_ID'])) {
            $fields['XML_ID'] = $match['XML_ID']; // без этого повторный импорт снова не найдёт запись
        }
        $fields += $this->seoFields($node, SeoTemplates::ENTITY_SECTION, $iblockId, null, $report);
        $section = new CIBlockSection();
        $newId = $section->Add($fields);
        if (!$newId) {
            throw new \Exception(Loc::getMessage('IBX_SECTION_ADD_FAILED', ['#NAME#' => $fields['NAME'], '#ERROR#' => $section->LAST_ERROR]));
        }
        $report->created++;

        return (int)$newId;
    }

    /**
     * UF_*-поля раздела из <properties> (см. Export\SectionWriter): одиночное значение — текст узла,
     * множественное — <value> на каждое.
     *
     * @return array<string, string|string[]> код поля => значение
     */
    public static function parseUserFields(SimpleXMLElement $properties): array
    {
        $result = [];
        foreach ($properties->property ?? [] as $prop) {
            $code = (string)$prop['code'];
            if (strpos($code, 'UF_') !== 0) {
                continue;
            }
            if (count($prop->value) > 0) {
                $values = [];
                foreach ($prop->value as $value) {
                    $values[] = (string)$value;
                }
                $result[$code] = $values;
            } else {
                $result[$code] = (string)$prop;
            }
        }

        return $result;
    }

    /** @return array<string, string> код UF-поля => его тип в исходной инсталляции (атрибут type; в архивах до 1.1.0 его нет) */
    public static function parseUserFieldTypes(SimpleXMLElement $properties): array
    {
        $types = [];
        foreach ($properties->property ?? [] as $prop) {
            $types[(string)$prop['code']] = (string)$prop['type'];
        }

        return $types;
    }

    /**
     * Поля, которых нет в целевом инфоблоке, пропускаются с предупреждением (Bitrix проигнорировал бы их молча).
     * Даты (типы date/datetime) в архиве — в ISO 8601 и передаются объектами Date/DateTime (DateValue).
     */
    private function applyUserFields(array &$fields, SimpleXMLElement $properties, int $iblockId, ImportReport $report): void
    {
        $known = $this->source->getSectionUserFieldCodes($iblockId);
        $types = self::parseUserFieldTypes($properties);
        foreach (self::parseUserFields($properties) as $code => $value) {
            if (!in_array($code, $known, true)) {
                $report->addWarning(Loc::getMessage('IBX_SECTION_NO_USER_FIELD', ['#CODE#' => $code]));
                continue;
            }

            $type = $types[$code] ?? '';
            if ($type === 'date' || $type === 'datetime') {
                $convert = static fn(string $v) => DateValue::isoToUserField($v, $type === 'datetime');
                $value = is_array($value) ? array_map($convert, $value) : $convert($value);
            }
            $fields[$code] = $value;
        }
    }
}
