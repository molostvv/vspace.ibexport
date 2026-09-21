<?php

namespace Vspace\Ibexport\Export;

use Bitrix\Iblock\SectionTable;
use CIBlockSection;
use Vspace\Ibexport\XmlStreamWriter;

/** Читает раздел из БД и пишет его XML-узел (docs/xml-format.md, "Разделы"). */
class SectionWriter
{
    /** @param (\Closure(string): void)|null $warn Куда сообщать о полях, которые не удалось выгрузить */
    public function __construct(private FileRefWriter $files, private ?\Closure $warn = null)
    {
    }

    /**
     * Открывает <section> и пишет его поля, картинку и UF_*-свойства; закрывающий
     * тег и вложенное содержимое (<elements>, <sections>) пишет обход дерева.
     */
    public function writeOpen(XmlStreamWriter $w, int $sectionId): void
    {
        $section = CIBlockSection::GetByID($sectionId)->GetNext();
        if (!$section) {
            throw new \Exception('Раздел #' . $sectionId . ' не найден.');
        }

        $w->openTag('section', [
            'id' => $section['ID'],
            'code' => $section['CODE'],
            'xml_id' => $section['~XML_ID'],
            'active' => $section['ACTIVE'],
            'sort' => $section['SORT'],
        ]);
        $w->textTag('name', $section['NAME']);
        $w->textTag('description', $section['DESCRIPTION'], [], true);
        $this->files->write($w, 'picture', (int)$section['PICTURE']);

        $w->openTag('properties');
        $this->writeUserFields($w, (int)$section['IBLOCK_ID'], $sectionId);
        $w->closeTag('properties');
    }

    /**
     * UF_*-поля раздела через штатный UserFieldManager (CIBlockSection::GetByID их не возвращает).
     * Одиночное значение — текст узла, множественное — <value> на каждое; типы, значение которых
     * привязано к этой инсталляции (файл, список и т.п.), не выгружаются — с предупреждением.
     */
    private function writeUserFields(XmlStreamWriter $w, int $iblockId, int $sectionId): void
    {
        global $USER_FIELD_MANAGER;

        $fields = $USER_FIELD_MANAGER->GetUserFields('IBLOCK_' . $iblockId . '_SECTION', $sectionId, LANGUAGE_ID);
        foreach ($fields as $code => $field) {
            $values = UserFieldExport::values($field);
            if (!$values) {
                continue;
            }

            $type = (string)$field['USER_TYPE_ID'];
            if (!UserFieldExport::isSupported($type)) {
                if ($this->warn) {
                    ($this->warn)('Поле раздела "' . $code . '" (тип "' . $type . '") не выгружено: значение этого типа привязано к данной инсталляции.');
                }
                continue;
            }

            if (($field['MULTIPLE'] ?? 'N') === 'Y') {
                $w->openTag('property', ['code' => $code, 'multiple' => 'true']);
                foreach ($values as $value) {
                    $w->textTag('value', $value);
                }
                $w->closeTag('property');
            } else {
                $w->textTag('property', $values[0], ['code' => $code]);
            }
        }
    }

    /** section_single: фиксируем только ID/код прямых подразделов, без рекурсии (FR-2). */
    public function writeSubsectionStubs(XmlStreamWriter $w, int $iblockId, int $sectionId, bool $activeOnly): void
    {
        $filter = ['IBLOCK_ID' => $iblockId, 'IBLOCK_SECTION_ID' => $sectionId];
        if ($activeOnly) {
            $filter['ACTIVE'] = 'Y';
        }
        $res = SectionTable::getList([
            'filter' => $filter,
            'select' => ['ID', 'CODE', 'NAME', 'ACTIVE'],
            'order' => ['SORT' => 'ASC'],
            'limit' => 5000,
        ]);

        $w->openTag('subsections', ['note' => 'not_included_see_mode']);
        while ($row = $res->fetch()) {
            $w->openTag('section', [
                'id' => $row['ID'],
                'code' => $row['CODE'],
                'active' => $row['ACTIVE'],
            ], true);
        }
        $w->closeTag('subsections');
    }
}
