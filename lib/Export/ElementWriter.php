<?php

namespace Vspace\Ibexport\Export;

use Bitrix\Iblock\ElementTable;
use CIBlockElement;
use CIBlockSection;
use Vspace\Ibexport\XmlStreamWriter;

/** Читает элемент (поля, свойства, файлы, привязки к разделам) из БД и пишет его XML-узел (docs/xml-format.md, "Элементы"). */
class ElementWriter
{
    /** @param \Closure(string): void $warn Приёмник предупреждений задания */
    public function __construct(
        private FileRefWriter $files,
        private \Closure $warn
    ) {
    }

    /** mode=element: единственный элемент выгрузки, вместе со списком его разделов. */
    public function writeSingle(XmlStreamWriter $w, int $iblockId, int $elementId): void
    {
        $res = ElementTable::getList(['filter' => ['IBLOCK_ID' => $iblockId, 'ID' => $elementId], 'select' => ['ID'], 'limit' => 1]);
        if (!$res->fetch()) {
            throw new \Exception('Элемент #' . $elementId . ' не найден.');
        }
        $this->writeRow($w, $elementId, true);
    }

    /** Один <element>; $withSections — писать ли собственный список его разделов (только mode=element). */
    public function writeRow(XmlStreamWriter $w, int $elementId, bool $withSections = false): void
    {
        $el = CIBlockElement::GetByID($elementId)->GetNext();
        if (!$el) {
            ($this->warn)('Элемент #' . $elementId . ' не найден, пропущен.');
            return;
        }

        $w->openTag('element', [
            'id' => $el['ID'],
            'code' => $el['CODE'],
            'active' => $el['ACTIVE'],
            'sort' => $el['SORT'],
        ]);
        $w->textTag('name', $el['NAME']);
        $w->textTag('preview_text', $el['PREVIEW_TEXT'], ['type' => $el['PREVIEW_TEXT_TYPE'] ?: 'text'], true);
        $w->textTag('detail_text', $el['DETAIL_TEXT'], ['type' => $el['DETAIL_TEXT_TYPE'] ?: 'text'], true);
        $w->textTag('date_active_from', $el['DATE_ACTIVE_FROM']);
        $w->textTag('date_active_to', $el['DATE_ACTIVE_TO']);

        $this->files->write($w, 'preview_picture', (int)$el['PREVIEW_PICTURE']);
        $this->files->write($w, 'detail_picture', (int)$el['DETAIL_PICTURE']);

        if ($withSections) {
            $w->openTag('sections');
            $groups = CIBlockElement::GetElementGroups($elementId, true);
            while ($sec = $groups->Fetch()) {
                $w->openTag('section', [
                    'id' => $sec['ID'],
                    'code' => $sec['CODE'],
                    'path' => $this->getSectionPath((int)$sec['IBLOCK_ID'], (int)$sec['ID']),
                ], true);
            }
            $w->closeTag('sections');
        }

        $this->writeProperties($w, (int)$el['IBLOCK_ID'], $elementId);

        $w->closeTag('element');
    }

    private function writeProperties(XmlStreamWriter $w, int $iblockId, int $elementId): void
    {
        $grouped = [];
        $props = CIBlockElement::GetProperty($iblockId, $elementId, ['sort' => 'asc'], []);
        while ($p = $props->Fetch()) {
            $code = $p['CODE'] !== '' ? $p['CODE'] : $p['ID'];
            if (!isset($grouped[$code])) {
                $grouped[$code] = ['meta' => $p, 'values' => []];
            }
            if ($p['VALUE'] === '' || $p['VALUE'] === null || $p['VALUE'] === false) {
                continue;
            }
            $grouped[$code]['values'][] = $p;
        }

        $w->openTag('properties');
        foreach ($grouped as $code => $data) {
            $meta = $data['meta'];
            $values = $data['values'];
            $multiple = $meta['MULTIPLE'] === 'Y';
            $type = $meta['PROPERTY_TYPE'];

            if (empty($values)) {
                $w->openTag('property', ['code' => $code, 'type' => $type], true);
                continue;
            }

            if (!$multiple && count($values) === 1 && $type !== 'F') {
                $display = $this->propertyDisplayValue($values[0]);
                $w->textTag('property', $display, ['code' => $code, 'type' => $type]);
                continue;
            }

            $w->openTag('property', ['code' => $code, 'type' => $type, 'multiple' => 'true']);
            foreach ($values as $v) {
                if ($type === 'F') {
                    $this->files->write($w, 'file', (int)$v['VALUE']);
                } else {
                    $w->textTag('value', $this->propertyDisplayValue($v));
                }
            }
            $w->closeTag('property');
        }
        $w->closeTag('properties');
    }

    private function propertyDisplayValue(array $p): string
    {
        if (isset($p['VALUE_ENUM']) && $p['VALUE_ENUM'] !== '') {
            return $p['VALUE_ENUM'];
        }
        if (is_array($p['VALUE']) && isset($p['VALUE']['TEXT'])) {
            return (string)$p['VALUE']['TEXT'];
        }
        return is_scalar($p['VALUE']) ? (string)$p['VALUE'] : '';
    }

    private function getSectionPath(int $iblockId, int $sectionId): string
    {
        $chain = [];
        $res = CIBlockSection::GetNavChain($iblockId, $sectionId);
        while ($row = $res->Fetch()) {
            $chain[] = $row['NAME'];
        }
        return implode(' > ', $chain);
    }
}
