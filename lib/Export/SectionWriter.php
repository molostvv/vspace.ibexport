<?php

namespace Vspace\Ibexport\Export;

use Bitrix\Iblock\SectionTable;
use Bitrix\Main\Localization\Loc;
use CIBlockSection;
use Vspace\Ibexport\DateValue;
use Vspace\Ibexport\SeoTemplates;
use Vspace\Ibexport\XmlStreamWriter;

Loc::loadMessages(__FILE__);

/**
 * Читает раздел из БД и пишет его XML-узел (docs/xml-format.md, "Разделы").
 *
 * Как и в ElementWriter: Fetch(), а не GetNext() (иначе в архив попали бы значения, экранированные для HTML), и без
 * проверки прав — CIBlockSection::GetList() по умолчанию проверяет права текущего пользователя, а фоновый тик идёт в
 * агенте без авторизации, и раздел закрытого инфоблока "не находился".
 */
class SectionWriter
{
    /** @param (\Closure(string): void)|null $warn Куда сообщать о полях, которые не удалось выгрузить */
    public function __construct(private FileRefWriter $files, private ?\Closure $warn = null)
    {
    }

    /**
     * Открывает <section> и пишет его поля, картинку, UF_*-свойства и SEO-шаблоны; закрывающий
     * тег и вложенное содержимое (<elements>, <sections>) пишет обход дерева.
     */
    public function writeOpen(XmlStreamWriter $w, int $sectionId): void
    {
        $section = CIBlockSection::GetList([], ['ID' => $sectionId, 'CHECK_PERMISSIONS' => 'N'])->Fetch();
        if (!$section) {
            throw new \Exception(Loc::getMessage('IBX_SECTION_WRITER_NOT_FOUND', ['#ID#' => $sectionId]));
        }

        $w->openTag('section', [
            'id' => $section['ID'],
            'code' => $section['CODE'],
            'xml_id' => $section['XML_ID'],
            'active' => $section['ACTIVE'],
            'sort' => $section['SORT'],
        ]);
        $w->textTag('name', $section['NAME']);
        $w->textTag('description', $section['DESCRIPTION'], ['type' => $section['DESCRIPTION_TYPE'] ?: 'text'], true);
        $this->files->write($w, 'picture', (int)$section['PICTURE']);

        $w->openTag('properties');
        $this->writeUserFields($w, (int)$section['IBLOCK_ID'], $sectionId);
        $w->closeTag('properties');

        SeoTemplates::write($w, SeoTemplates::load((int)$section['IBLOCK_ID'], SeoTemplates::ENTITY_SECTION, $sectionId));
    }

    /**
     * UF_*-поля раздела через штатный UserFieldManager (CIBlockSection::GetList их без явного select не возвращает).
     * Одиночное значение — текст узла, множественное — <value> на каждое; атрибут type — тип поля, даты — в ISO 8601.
     * Типы, значение которых привязано к этой инсталляции (файл, список и т.п.), не выгружаются — с предупреждением.
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
                    ($this->warn)(Loc::getMessage('IBX_SECTION_WRITER_UF_SKIPPED', ['#CODE#' => $code, '#TYPE#' => $type]));
                }
                continue;
            }
            if ($type === 'date' || $type === 'datetime') {
                $values = array_map(static fn(string $value): string => DateValue::userFieldToIso($value, $type === 'datetime'), $values);
            }

            if (($field['MULTIPLE'] ?? 'N') === 'Y') {
                $w->openTag('property', ['code' => $code, 'type' => $type, 'multiple' => 'true']);
                foreach ($values as $value) {
                    $w->textTag('value', $value);
                }
                $w->closeTag('property');
            } else {
                $w->textTag('property', $values[0], ['code' => $code, 'type' => $type]);
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
            'select' => ['ID', 'CODE', 'ACTIVE'],
            'order' => ['SORT' => 'ASC', 'NAME' => 'ASC'],
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
