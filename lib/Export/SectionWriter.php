<?php

namespace Vspace\Ibexport\Export;

use Bitrix\Iblock\SectionTable;
use CIBlockSection;
use Vspace\Ibexport\XmlStreamWriter;

/** Читает раздел из БД и пишет его XML-узел (docs/xml-format.md, "Разделы"). */
class SectionWriter
{
    public function __construct(private FileRefWriter $files)
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
        foreach ($section as $key => $value) {
            if (strpos($key, 'UF_') === 0 && $value !== '' && $value !== null) {
                $w->textTag('property', is_array($value) ? implode(', ', $value) : (string)$value, ['code' => $key]);
            }
        }
        $w->closeTag('properties');
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
