<?php

namespace Vspace\Ibexport;

use Bitrix\Main\Entity;

/**
 * Одна запись на каждый запуск экспорта. Выполняет двойную роль: "задание"
 * (прогресс/курсор во время выполнения) и "журнал" (история завершённых и
 * упавших выгрузок, требуемая разделом 9 ТЗ). Общая часть полей и логики —
 * в AbstractJobTable, здесь только то, что свойственно экспорту.
 */
class JobTable extends AbstractJobTable
{
    public static function getTableName()
    {
        return 'vspace_ibexport_job';
    }

    protected static function getOwnFields(): array
    {
        return [
            new Entity\IntegerField('IBLOCK_ID', ['required' => true]),
            new Entity\StringField('ENTITY_TYPE', ['required' => true]), // element | section
            new Entity\IntegerField('ENTITY_ID', ['required' => true]),
            new Entity\StringField('MODE', ['required' => true]), // element | section_single | section_tree (см. режимы FR-1..FR-3)
            new Entity\BooleanField('WITH_FILES', ['values' => ['N', 'Y'], 'default_value' => 'Y']),
            new Entity\BooleanField('ACTIVE_ONLY', ['values' => ['N', 'Y'], 'default_value' => 'N']),
            new Entity\IntegerField('ARCHIVE_SIZE', ['default_value' => 0]),
            new Entity\StringField('XML_FILE'),
            new Entity\StringField('ARCHIVE_FILE'),
        ];
    }
}
