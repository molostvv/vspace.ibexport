<?php

namespace Vspace\Ibexport;

use Bitrix\Main\Entity;
use Bitrix\Main\ORM\Data\DataManager;
use Bitrix\Main\Type\DateTime;

/**
 * Одна запись на каждый запуск экспорта. Выполняет двойную роль: "задание"
 * (прогресс/курсор во время выполнения) и "журнал" (история завершённых и
 * упавших выгрузок, требуемая разделом 9 ТЗ).
 */
class JobTable extends DataManager
{
    const STATUS_NEW = 'NEW';
    const STATUS_RUNNING = 'RUNNING';
    const STATUS_DONE = 'DONE';
    const STATUS_ERROR = 'ERROR';

    public static function getTableName()
    {
        return 'vspace_ibexport_job';
    }

    public static function getMap()
    {
        return [
            new Entity\IntegerField('ID', ['primary' => true, 'autocomplete' => true]),
            new Entity\IntegerField('USER_ID'),
            new Entity\IntegerField('IBLOCK_ID', ['required' => true]),
            new Entity\StringField('ENTITY_TYPE', ['required' => true]), // element | section
            new Entity\IntegerField('ENTITY_ID', ['required' => true]),
            new Entity\StringField('MODE', ['required' => true]), // element | section_single | section_tree (см. режимы FR-1..FR-3)
            new Entity\BooleanField('WITH_FILES', ['values' => ['N', 'Y'], 'default_value' => 'Y']),
            new Entity\BooleanField('ACTIVE_ONLY', ['values' => ['N', 'Y'], 'default_value' => 'N']),
            new Entity\StringField('STATUS', ['default_value' => self::STATUS_NEW]),
            new Entity\StringField('STAGE'),
            new Entity\TextField('STATE_JSON'),
            new Entity\IntegerField('TOTAL_SECTIONS', ['default_value' => 0]),
            new Entity\IntegerField('TOTAL_ELEMENTS', ['default_value' => 0]),
            new Entity\IntegerField('PROCESSED_SECTIONS', ['default_value' => 0]),
            new Entity\IntegerField('PROCESSED_ELEMENTS', ['default_value' => 0]),
            new Entity\IntegerField('ARCHIVE_SIZE', ['default_value' => 0]),
            new Entity\StringField('TMP_DIR'),
            new Entity\StringField('XML_FILE'),
            new Entity\StringField('ARCHIVE_FILE'),
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
     * Атомарно захватывает задание на один тик, чтобы тик агента CAgent и
     * опрос из браузера никогда не обрабатывали одно задание одновременно
     * (иначе оба допишут в один export.xml и задвоят счётчики прогресса).
     * Блокировка старше $staleSeconds считается брошенной (упавший запрос)
     * и может быть перехвачена заново.
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
