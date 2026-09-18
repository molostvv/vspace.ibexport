<?php

namespace Vspace\Ibexport;

use Bitrix\Main\Entity;
use Bitrix\Main\ORM\Data\DataManager;
use Bitrix\Main\Type\DateTime;

/**
 * Одна запись на каждый запуск импорта — задание (прогресс/курсор во время
 * выполнения) и журнал (история завершённых и упавших импортов) одновременно,
 * по той же схеме, что и JobTable для экспорта.
 */
class ImportJobTable extends DataManager
{
    const STATUS_NEW = 'NEW';
    const STATUS_RUNNING = 'RUNNING';
    const STATUS_DONE = 'DONE';
    const STATUS_ERROR = 'ERROR';

    public static function getTableName()
    {
        return 'vspace_ibexport_import_job';
    }

    public static function getMap()
    {
        return [
            new Entity\IntegerField('ID', ['primary' => true, 'autocomplete' => true]),
            new Entity\IntegerField('USER_ID'),
            new Entity\IntegerField('TARGET_IBLOCK_ID', ['required' => true]),
            new Entity\IntegerField('PARENT_SECTION_ID', ['default_value' => 0]),
            new Entity\BooleanField('UPDATE_BY_CODE', ['values' => ['N', 'Y'], 'default_value' => 'Y']),
            new Entity\StringField('MODE', ['required' => true]), // element | section_single | section_tree — читается из корня export.xml
            new Entity\IntegerField('SOURCE_IBLOCK_ID', ['default_value' => 0]), // из export.xml, только для справки/журнала
            new Entity\StringField('STATUS', ['default_value' => self::STATUS_NEW]),
            new Entity\StringField('STAGE'),
            new Entity\TextField('STATE_JSON'),
            new Entity\IntegerField('TOTAL_SECTIONS', ['default_value' => 0]),
            new Entity\IntegerField('TOTAL_ELEMENTS', ['default_value' => 0]),
            new Entity\IntegerField('PROCESSED_SECTIONS', ['default_value' => 0]),
            new Entity\IntegerField('PROCESSED_ELEMENTS', ['default_value' => 0]),
            new Entity\IntegerField('CREATED_COUNT', ['default_value' => 0]),
            new Entity\IntegerField('UPDATED_COUNT', ['default_value' => 0]),
            new Entity\IntegerField('SKIPPED_COUNT', ['default_value' => 0]),
            new Entity\StringField('TMP_DIR'),
            new Entity\StringField('SOURCE_FILE_NAME'),
            new Entity\TextField('ERROR_MESSAGE'),
            new Entity\TextField('WARNINGS_JSON'),
            new Entity\IntegerField('LOCKED_AT'), // unix-время; защищает от параллельных тиков (агент + AJAX-опрос)
            new Entity\DatetimeField('DATE_CREATE', ['default_value' => function () {
                return new DateTime();
            }]),
            new Entity\DatetimeField('DATE_FINISH'),
            new Entity\DatetimeField('DATE_EXPIRE'),
        ];
    }

    public static function getJobById(int $id): ?array
    {
        $row = static::getList(['filter' => ['=ID' => $id], 'limit' => 1])->fetch();
        return $row ?: null;
    }

    /**
     * Атомарно захватывает задание на один тик — та же защита от
     * параллельных тиков (агент + AJAX-опрос), что и в JobTable::tryLock().
     */
    public static function tryLock(int $id, int $staleSeconds = 120): bool
    {
        $connection = \Bitrix\Main\Application::getConnection();
        $table = static::getTableName();
        $now = time();
        $stale = $now - $staleSeconds;

        $sql = "UPDATE {$table} SET LOCKED_AT = {$now} "
            . "WHERE ID = {$id} AND (LOCKED_AT IS NULL OR LOCKED_AT < {$stale})";
        $connection->queryExecute($sql);

        return $connection->getAffectedRowsCount() > 0;
    }

    public static function unlock(int $id): void
    {
        static::update($id, ['LOCKED_AT' => null]);
    }
}
