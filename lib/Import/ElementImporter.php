<?php

namespace Vspace\Ibexport\Import;

use Bitrix\Iblock\ElementTable;
use Bitrix\Iblock\SectionTable;
use Bitrix\Main\Localization\Loc;
use CIBlockElement;
use SimpleXMLElement;
use Vspace\Ibexport\DateValue;

Loc::loadMessages(__FILE__);

/**
 * Создаёт либо обновляет один элемент по его XML-узлу (Add/Update по CODE,
 * как и для разделов — см. SectionImporter) и записывает его свойства так,
 * как их разобрал PropertyResolver: собственной логики резолва здесь нет.
 * Запись — классический API (CIBlockElement).
 *
 * Привязки к разделам только добавляются, существующие не снимаются (в том числе к разделам вне архива).
 * Элемент, привязанный к нескольким разделам выгруженного дерева, встречается в архиве под каждым из них
 * (под основным — с main_section="Y"): первое вхождение создаёт или обновляет элемент. Созданный элемент
 * помечается в штатном служебном поле TMP_ID (задание + ID в архиве), и следующие вхождения находят его по
 * этой метке — даже без символьного кода — и только добавляют свою привязку.
 *
 * Каждое обработанное вхождение попадает ровно в один счётчик отчёта: создано / обновлено / пропущено.
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
        $name = (string)$node->name;
        // В архивах до 1.1.0 атрибута нет: элементы выгружались только под своим основным разделом.
        $mainSectionId = $sectionId !== null && (string)$node['main_section'] !== 'N' ? $sectionId : null;

        // Метка нужна только архивам 1.1.0+ (есть main_section): в прежних каждый элемент выгружался один раз.
        $marker = $sectionId !== null && isset($node['main_section']) ? self::marker($node, $ctx) : null;
        if ($marker !== null) {
            $createdId = $this->findByMarker($ctx->iblockId, $marker);
            if ($createdId !== null) {
                // Повторное вхождение элемента, созданного этим же импортом под другим разделом архива: данные те же.
                $this->addSectionLink($createdId, $sectionId, $mainSectionId, $name, $report);
                return;
            }
        }

        $match = self::matchFilter(trim((string)$node['code']), trim((string)$node['xml_id']), $ctx->matchByXmlId);
        $found = $this->findMatch(ElementTable::class, $ctx->iblockId, $match);
        if ($found['ambiguous']) {
            // Обновлять "какую-то из" записей нельзя, а создавать ещё одну с тем же XML_ID — плодить неоднозначность.
            $report->addWarning(Loc::getMessage('IBX_ELEMENT_AMBIGUOUS', ['#NAME#' => $name, '#KEY#' => self::matchLabel($match)]));
            $report->skipped++;
            return;
        }
        $existingId = $found['id'];

        if ($existingId === null) {
            $this->create($node, $sectionId, $mainSectionId, $match, $marker, $ctx, $report, $withSections);
            return;
        }

        if (!$ctx->updateByCode) {
            $report->skipped++; // запись не трогается совсем — ни поля, ни свойства, ни привязки
            return;
        }

        [$sectionIds, $mainId] = $this->archiveSections($node, $sectionId, $mainSectionId, $ctx, $report, $withSections);
        $fields = $this->readFields($node, $ctx, true) + $this->sectionFields($existingId, $sectionIds, $mainId);
        $this->applyFileField($fields, 'PREVIEW_PICTURE', $node->preview_picture, $ctx, $report);
        $this->applyFileField($fields, 'DETAIL_PICTURE', $node->detail_picture, $ctx, $report);

        $element = new CIBlockElement();
        if (!$element->Update($existingId, $fields)) {
            $report->addWarning(Loc::getMessage('IBX_ELEMENT_UPDATE_FAILED', ['#NAME#' => $name, '#KEY#' => self::matchLabel($match), '#ERROR#' => $element->LAST_ERROR]));
            $report->skipped++;
            return;
        }
        $report->updated++;

        $this->writeProperties($existingId, $node, $ctx, $report, false);
    }

    private function create(SimpleXMLElement $node, ?int $sectionId, ?int $mainSectionId, ?array $match, ?string $marker, ImportContext $ctx, ImportReport $report, bool $withSections): void
    {
        [$sectionIds, $mainId] = $this->archiveSections($node, $sectionId, $mainSectionId, $ctx, $report, $withSections);
        $fields = $this->readFields($node, $ctx, false) + $this->sectionFields(null, $sectionIds, $mainId);
        if (isset($match['XML_ID'])) {
            $fields['XML_ID'] = $match['XML_ID']; // без этого повторный импорт снова не найдёт запись
        }
        if ($marker !== null) {
            $fields['TMP_ID'] = $marker;
        }
        $this->applyFileField($fields, 'PREVIEW_PICTURE', $node->preview_picture, $ctx, $report);
        $this->applyFileField($fields, 'DETAIL_PICTURE', $node->detail_picture, $ctx, $report);

        $element = new CIBlockElement();
        $newId = $element->Add($fields);
        if (!$newId) {
            throw new \Exception(Loc::getMessage('IBX_ELEMENT_ADD_FAILED', ['#NAME#' => $fields['NAME'], '#ERROR#' => $element->LAST_ERROR]));
        }
        $report->created++;

        $this->writeProperties((int)$newId, $node, $ctx, $report, true);
    }

    /** Поля элемента из узла. $forUpdate — пустая дата в архиве снимает дату и у существующего элемента. */
    private function readFields(SimpleXMLElement $node, ImportContext $ctx, bool $forUpdate): array
    {
        $fields = [
            'IBLOCK_ID' => $ctx->iblockId,
            'NAME' => (string)$node->name,
            'ACTIVE' => (string)$node['active'] === 'N' ? 'N' : 'Y',
            'SORT' => self::readSort($node),
            'PREVIEW_TEXT' => (string)$node->preview_text,
            'PREVIEW_TEXT_TYPE' => (string)$node->preview_text['type'] === 'html' ? 'html' : 'text',
            'DETAIL_TEXT' => (string)$node->detail_text,
            'DETAIL_TEXT_TYPE' => (string)$node->detail_text['type'] === 'html' ? 'html' : 'text',
        ];

        $code = trim((string)$node['code']);
        if ($code !== '') {
            $fields['CODE'] = $code;
        }

        foreach (['DATE_ACTIVE_FROM' => 'date_active_from', 'DATE_ACTIVE_TO' => 'date_active_to'] as $field => $tag) {
            $value = trim((string)$node->{$tag});
            if ($value !== '') {
                $fields[$field] = DateValue::isoToField($value);
            } elseif ($forUpdate && isset($node->{$tag})) {
                $fields[$field] = '';
            }
        }

        return $fields;
    }

    /**
     * Разделы из архива: в режимах раздела — текущий раздел обхода, в mode=element — собственный список
     * <sections> элемента (разделы ищутся по коду; основной помечен main="Y").
     *
     * @return array{0: int[], 1: int|null} ID разделов в целевом инфоблоке и основной из них (null — не задан)
     */
    private function archiveSections(SimpleXMLElement $node, ?int $sectionId, ?int $mainSectionId, ImportContext $ctx, ImportReport $report, bool $withSections): array
    {
        if (!$withSections) {
            return [$sectionId !== null ? [$sectionId] : [], $mainSectionId];
        }

        $ids = [];
        $main = null;
        foreach ($node->sections->section ?? [] as $secRef) {
            $refCode = trim((string)$secRef['code']);
            $targetId = $refCode !== '' ? $this->findByCode(SectionTable::class, $ctx->iblockId, $refCode) : null;
            if (!$targetId) {
                $report->addWarning(Loc::getMessage('IBX_ELEMENT_SECTION_NOT_FOUND', ['#NAME#' => (string)$node->name, '#CODE#' => $refCode]));
                continue;
            }
            $ids[] = $targetId;
            if ((string)$secRef['main'] === 'Y') {
                $main = $targetId;
            }
        }

        return [$ids, $main];
    }

    /**
     * IBLOCK_SECTION / IBLOCK_SECTION_ID для Add()/Update(): к текущим привязкам элемента добавляются разделы из
     * архива. Основной раздел — указанный архивом, иначе прежний, иначе первый из архива. Нет разделов — поля
     * не передаются (привязки не меняются).
     *
     * @param int[] $sectionIds
     */
    private function sectionFields(?int $elementId, array $sectionIds, ?int $mainSectionId): array
    {
        if (!$sectionIds) {
            return [];
        }

        $currentIds = [];
        $currentMain = 0;
        if ($elementId !== null) {
            $groups = CIBlockElement::GetElementGroups($elementId, true, ['ID']);
            while ($group = $groups->Fetch()) {
                $currentIds[] = (int)$group['ID'];
            }
            $row = ElementTable::getList(['filter' => ['=ID' => $elementId], 'select' => ['IBLOCK_SECTION_ID'], 'limit' => 1])->fetch();
            $currentMain = (int)($row['IBLOCK_SECTION_ID'] ?? 0);
        }

        return [
            'IBLOCK_SECTION' => array_values(array_unique(array_merge($currentIds, $sectionIds))),
            'IBLOCK_SECTION_ID' => $mainSectionId ?? ($currentMain ?: $sectionIds[0]),
        ];
    }

    private function addSectionLink(int $elementId, int $sectionId, ?int $mainSectionId, string $name, ImportReport $report): void
    {
        $element = new CIBlockElement();
        if ($element->Update($elementId, $this->sectionFields($elementId, [$sectionId], $mainSectionId))) {
            $report->updated++;
        } else {
            $report->addWarning(Loc::getMessage('IBX_ELEMENT_LINK_FAILED', ['#NAME#' => $name, '#ERROR#' => $element->LAST_ERROR]));
            $report->skipped++;
        }
    }

    /** Метка элемента, созданного этим заданием импорта (поле TMP_ID, до 40 символов): задание + ID элемента в архиве. */
    private static function marker(SimpleXMLElement $node, ImportContext $ctx): ?string
    {
        $sourceId = (int)$node['id'];

        return $ctx->jobId !== null && $sourceId > 0 ? 'vibx' . $ctx->jobId . '_' . $sourceId : null;
    }

    private function findByMarker(int $iblockId, string $marker): ?int
    {
        $row = ElementTable::getList([
            'filter' => ['=IBLOCK_ID' => $iblockId, '=TMP_ID' => $marker],
            'select' => ['ID'],
            'limit' => 1,
        ])->fetch();

        return $row ? (int)$row['ID'] : null;
    }

    /** Свойства после Add()/Update(); у нового элемента очищать нечего — пустые значения не передаются. */
    private function writeProperties(int $elementId, SimpleXMLElement $node, ImportContext $ctx, ImportReport $report, bool $isNew): void
    {
        $resolved = $this->properties->resolve($ctx->iblockId, $node->properties, $ctx->tmpDir, $report, $ctx->matchByXmlId);
        if ($isNew) {
            $resolved = array_filter($resolved, static fn($value): bool => $value !== false && $value !== PropertyResolver::CLEAR_FILES);
        }
        if ($resolved) {
            CIBlockElement::SetPropertyValuesEx($elementId, $ctx->iblockId, $resolved);
        }
    }
}
