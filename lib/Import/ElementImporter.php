<?php

namespace Vspace\Ibexport\Import;

use Bitrix\Iblock\ElementTable;
use Bitrix\Iblock\SectionTable;
use CIBlockElement;
use SimpleXMLElement;

/**
 * Создаёт либо обновляет один элемент по его XML-узлу (Add/Update по CODE,
 * как и для разделов — см. SectionImporter) и записывает его свойства так,
 * как их разобрал PropertyResolver: собственной логики резолва здесь нет.
 * Запись — классический API (CIBlockElement).
 */
class ElementImporter extends AbstractNodeImporter
{
    public function __construct(FileArrayFactoryInterface $files, private PropertyResolver $properties)
    {
        parent::__construct($files);
    }

    /**
     * @param SimpleXMLElement $node <element>
     * @param int|null $sectionId Раздел-владелец (режимы раздела) либо null (mode=element — привязка по <sections> самого элемента)
     * @param bool $withSections Разбирать ли собственный список <sections> элемента (только mode=element)
     */
    public function import(SimpleXMLElement $node, ?int $sectionId, ImportContext $ctx, ImportReport $report, bool $withSections): void
    {
        $iblockId = $ctx->iblockId;
        $code = trim((string)$node['code']);
        $match = self::matchFilter($code, trim((string)$node['xml_id']), $ctx->matchByXmlId);
        $fields = [
            'IBLOCK_ID' => $iblockId,
            'NAME' => (string)$node->name,
            'ACTIVE' => (string)$node['active'] === 'N' ? 'N' : 'Y',
            'SORT' => self::readSort($node),
            'PREVIEW_TEXT' => (string)$node->preview_text,
            'PREVIEW_TEXT_TYPE' => (string)($node->preview_text['type'] ?: 'text'),
            'DETAIL_TEXT' => (string)$node->detail_text,
            'DETAIL_TEXT_TYPE' => (string)($node->detail_text['type'] ?: 'text'),
        ];
        if ($code !== '') {
            $fields['CODE'] = $code;
        }
        if ((string)$node->date_active_from !== '') {
            $fields['DATE_ACTIVE_FROM'] = (string)$node->date_active_from;
        }
        if ((string)$node->date_active_to !== '') {
            $fields['DATE_ACTIVE_TO'] = (string)$node->date_active_to;
        }

        $sectionIds = [];
        if ($withSections && isset($node->sections->section)) {
            foreach ($node->sections->section as $secRef) {
                $refCode = trim((string)$secRef['code']);
                $targetId = $refCode !== '' ? $this->findByCode(SectionTable::class, $iblockId, $refCode) : null;
                if ($targetId) {
                    $sectionIds[] = $targetId;
                } else {
                    $report->addWarning('Элемент "' . $fields['NAME'] . '": раздел с кодом "' . $refCode . '" не найден в целевом инфоблоке, привязка пропущена.');
                }
            }
        } elseif ($sectionId) {
            $sectionIds[] = $sectionId;
        }
        if (!empty($sectionIds)) {
            $fields['IBLOCK_SECTION_ID'] = $sectionIds[0];
            $fields['IBLOCK_SECTION'] = $sectionIds;
        }

        $this->applyFileField($fields, 'PREVIEW_PICTURE', $node->preview_picture, $ctx, $report);
        $this->applyFileField($fields, 'DETAIL_PICTURE', $node->detail_picture, $ctx, $report);

        $existingId = $this->findByMatch(ElementTable::class, $iblockId, $match);

        if ($existingId) {
            if ($ctx->updateByCode) {
                $element = new CIBlockElement();
                if (!$element->Update($existingId, $fields)) {
                    $report->addWarning('Элемент "' . $fields['NAME'] . '" (' . self::matchLabel($match) . '): ' . $element->LAST_ERROR);
                }
                $report->updated++;
            } else {
                $report->skipped++;
            }
            $elementId = $existingId;
        } else {
            if (isset($match['XML_ID'])) {
                $fields['XML_ID'] = $match['XML_ID']; // без этого повторный импорт снова не найдёт запись
            }
            $element = new CIBlockElement();
            $newId = $element->Add($fields);
            if (!$newId) {
                throw new \Exception('Не удалось создать элемент "' . $fields['NAME'] . '": ' . $element->LAST_ERROR);
            }
            $report->created++;
            $elementId = (int)$newId;
        }

        $resolved = $this->properties->resolve($iblockId, $node->properties, $ctx->tmpDir, $report);
        if (!empty($resolved)) {
            CIBlockElement::SetPropertyValuesEx($elementId, $iblockId, $resolved);
        }
    }
}
