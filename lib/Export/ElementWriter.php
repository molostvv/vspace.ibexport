<?php

namespace Vspace\Ibexport\Export;

use Bitrix\Iblock\ElementTable;
use Bitrix\Iblock\SectionTable;
use Bitrix\Main\Localization\Loc;
use CIBlockElement;
use CIBlockSection;
use Vspace\Ibexport\DateValue;
use Vspace\Ibexport\XmlStreamWriter;

Loc::loadMessages(__FILE__);

/**
 * Читает элемент (поля, свойства, файлы, привязки к разделам) из БД и пишет его XML-узел (docs/xml-format.md, "Элементы").
 *
 * Поля читаются через Fetch(), а не GetNext(): GetNext() готовит значения к выводу в HTML (название с кавычкой
 * становится &quot;, текст типа text — HTML с <br />), и в архив попадали бы уже искажённые данные. Права не
 * проверяются: они проверены при создании задания, а фоновые тики идут в агенте без авторизованного пользователя.
 */
class ElementWriter
{
    /** Типы свойств-привязок: значение — ID записи этой инсталляции, в архив дополнительно пишутся её CODE и XML_ID. */
    private const LINK_TYPES = ['E' => ElementTable::class, 'G' => SectionTable::class];

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
            throw new \Exception(Loc::getMessage('IBX_ELEMENT_WRITER_NOT_FOUND', ['#ID#' => $elementId]));
        }
        $this->writeRow($w, $elementId, true);
    }

    /**
     * Один <element>.
     *
     * @param bool $withSections Писать ли собственный список разделов элемента (только mode=element)
     * @param int|null $sectionId Раздел, под которым элемент выгружается в режимах раздела. Элемент, привязанный к
     *                            нескольким разделам дерева, выгружается под каждым из них; атрибут main_section
     *                            говорит импорту, основной ли это раздел элемента.
     */
    public function writeRow(XmlStreamWriter $w, int $elementId, bool $withSections = false, ?int $sectionId = null): void
    {
        $el = CIBlockElement::GetList([], ['ID' => $elementId, 'SHOW_HISTORY' => 'Y', 'CHECK_PERMISSIONS' => 'N'])->Fetch();
        if (!$el) {
            ($this->warn)(Loc::getMessage('IBX_ELEMENT_WRITER_SKIPPED', ['#ID#' => $elementId]));
            return;
        }

        $attrs = [
            'id' => $el['ID'],
            'code' => $el['CODE'],
            'xml_id' => $el['XML_ID'],
            'active' => $el['ACTIVE'],
            'sort' => $el['SORT'],
        ];
        if ($sectionId !== null) {
            $attrs['main_section'] = (int)$el['IBLOCK_SECTION_ID'] === $sectionId ? 'Y' : 'N';
        }
        $w->openTag('element', $attrs);
        $w->textTag('name', $el['NAME']);
        $w->textTag('preview_text', $el['PREVIEW_TEXT'], ['type' => $el['PREVIEW_TEXT_TYPE'] ?: 'text'], true);
        $w->textTag('detail_text', $el['DETAIL_TEXT'], ['type' => $el['DETAIL_TEXT_TYPE'] ?: 'text'], true);
        $w->textTag('date_active_from', DateValue::fieldToIso($el['DATE_ACTIVE_FROM']));
        $w->textTag('date_active_to', DateValue::fieldToIso($el['DATE_ACTIVE_TO']));

        $this->files->write($w, 'preview_picture', (int)$el['PREVIEW_PICTURE']);
        $this->files->write($w, 'detail_picture', (int)$el['DETAIL_PICTURE']);

        if ($withSections) {
            $w->openTag('sections');
            $groups = CIBlockElement::GetElementGroups($elementId, true);
            while ($sec = $groups->Fetch()) {
                $sectionAttrs = [
                    'id' => $sec['ID'],
                    'code' => $sec['CODE'],
                    'path' => $this->getSectionPath((int)$sec['IBLOCK_ID'], (int)$sec['ID']),
                ];
                if ((int)$sec['ID'] === (int)$el['IBLOCK_SECTION_ID']) {
                    $sectionAttrs['main'] = 'Y';
                }
                $w->openTag('section', $sectionAttrs, true);
            }
            $w->closeTag('sections');
        }

        $this->writeProperties($w, (int)$el['IBLOCK_ID'], $elementId);

        $w->closeTag('element');
    }

    private function writeProperties(XmlStreamWriter $w, int $iblockId, int $elementId): void
    {
        $grouped = [];
        $linkIds = array_fill_keys(array_keys(self::LINK_TYPES), []);
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
            if (isset($linkIds[$p['PROPERTY_TYPE']])) {
                $linkIds[$p['PROPERTY_TYPE']][] = (int)$p['VALUE'];
            }
        }
        $linkKeys = $this->loadLinkKeys($linkIds);

        $w->openTag('properties');
        foreach ($grouped as $code => $data) {
            $meta = $data['meta'];
            $values = $data['values'];
            $multiple = $meta['MULTIPLE'] === 'Y';
            $type = $meta['PROPERTY_TYPE'];
            $attrs = ['code' => $code, 'type' => $type];

            if (empty($values)) {
                $w->openTag('property', $attrs, true);
                continue;
            }

            if (!$multiple && count($values) === 1 && $type !== 'F') {
                $display = $this->propertyDisplayValue($values[0]);
                $w->textTag('property', $display, $attrs + $this->valueAttrs((string)$code, $type, $values[0], $linkKeys));
                continue;
            }

            $w->openTag('property', $multiple ? $attrs + ['multiple' => 'true'] : $attrs);
            foreach ($values as $v) {
                if ($type === 'F') {
                    $this->files->write($w, 'file', (int)$v['VALUE'], (string)($v['DESCRIPTION'] ?? ''));
                } else {
                    $w->textTag('value', $this->propertyDisplayValue($v), $this->valueAttrs((string)$code, $type, $v, $linkKeys));
                }
            }
            $w->closeTag('property');
        }
        $w->closeTag('properties');
    }

    /**
     * Ключи сопоставления связанных записей свойств-привязок: ID этой инсталляции на другой указал бы на чужую запись,
     * поэтому импорт ищет связанную запись по CODE/XML_ID.
     *
     * @param array<string, int[]> $ids тип свойства (E|G) => ID связанных записей
     * @return array<string, array<int, array{code: string, xml_id: string}>>
     */
    private function loadLinkKeys(array $ids): array
    {
        $keys = [];
        foreach (self::LINK_TYPES as $type => $table) {
            $keys[$type] = [];
            $unique = array_values(array_unique(array_filter($ids[$type])));
            if (!$unique) {
                continue;
            }
            $rows = $table::getList(['filter' => ['@ID' => $unique], 'select' => ['ID', 'CODE', 'XML_ID']]);
            while ($row = $rows->fetch()) {
                $keys[$type][(int)$row['ID']] = ['code' => (string)$row['CODE'], 'xml_id' => (string)$row['XML_ID']];
            }
        }

        return $keys;
    }

    /** Атрибуты значения: ключи связанной записи (для E/G) и описание значения, если оно есть. */
    private function valueAttrs(string $code, string $type, array $p, array $linkKeys): array
    {
        $attrs = [];
        if (isset($linkKeys[$type])) {
            $ref = $linkKeys[$type][(int)$p['VALUE']] ?? null;
            if ($ref !== null) {
                $attrs['ref_code'] = $ref['code'];
                $attrs['ref_xml_id'] = $ref['xml_id'];
            } else {
                ($this->warn)(Loc::getMessage('IBX_ELEMENT_WRITER_LINK_MISSING', ['#CODE#' => $code, '#ID#' => (int)$p['VALUE']]));
            }
        }
        if ((string)($p['DESCRIPTION'] ?? '') !== '') {
            $attrs['description'] = (string)$p['DESCRIPTION'];
        }

        return $attrs;
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
