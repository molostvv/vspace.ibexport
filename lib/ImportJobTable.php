<?php

namespace Vspace\Ibexport;

use Bitrix\Main\Entity;

/**
 * Одна запись на каждый запуск импорта — задание (прогресс/курсор во время
 * выполнения) и журнал (история завершённых и упавших импортов) одновременно,
 * по той же схеме, что и JobTable для экспорта. Общая часть — в AbstractJobTable,
 * здесь только то, что свойственно импорту.
 */
class ImportJobTable extends AbstractJobTable
{
    public static function getTableName()
    {
        return 'vspace_ibexport_import_job';
    }

    protected static function getOwnFields(): array
    {
        return [
            new Entity\IntegerField('TARGET_IBLOCK_ID', ['required' => true]),
            new Entity\IntegerField('PARENT_SECTION_ID', ['default_value' => 0]),
            new Entity\BooleanField('UPDATE_BY_CODE', ['values' => ['N', 'Y'], 'default_value' => 'Y']),
            new Entity\BooleanField('MATCH_BY_XML_ID', ['values' => ['N', 'Y'], 'default_value' => 'N']), // запасной ключ сопоставления для записей без CODE
            new Entity\StringField('MODE', ['required' => true]), // element | section_single | section_tree — читается из корня export.xml
            new Entity\IntegerField('SOURCE_IBLOCK_ID', ['default_value' => 0]), // из export.xml, только для справки/журнала
            new Entity\IntegerField('CREATED_COUNT', ['default_value' => 0]),
            new Entity\IntegerField('UPDATED_COUNT', ['default_value' => 0]),
            new Entity\IntegerField('SKIPPED_COUNT', ['default_value' => 0]),
            new Entity\StringField('SOURCE_FILE_NAME'),
        ];
    }
}
