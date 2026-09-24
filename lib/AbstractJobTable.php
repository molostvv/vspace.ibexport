<?php

namespace Vspace\Ibexport;

use Bitrix\Main\Entity;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\ORM\Data\DataManager;
use Bitrix\Main\Type\DateTime;

Loc::loadMessages(__FILE__);

/**
 * Общая основа таблиц заданий экспорта (JobTable) и импорта (ImportJobTable):
 * каждая запись — и "задание" (прогресс/курсор возобновления во время
 * выполнения), и "журнал" (история завершённых и упавших запусков).
 *
 * Здесь — всё, что у экспорта и импорта совпадает: набор служебных полей
 * (статус, стадия, курсор STATE_JSON, счётчики прогресса, блокировка, даты),
 * значения STATUS_*, атомарный захват задания на один тик и расчёт процента.
 * Наследники добавляют только собственные поля через getOwnFields().
 */
abstract class AbstractJobTable extends DataManager
{
    const STATUS_NEW = 'NEW';
    const STATUS_RUNNING = 'RUNNING';
    const STATUS_DONE = 'DONE';
    const STATUS_ERROR = 'ERROR';

    /** Поля, специфичные для вида задания (экспорт/импорт) — встают между USER_ID и общей частью. */
    abstract protected static function getOwnFields(): array;

    public static function getMap()
    {
        return array_merge(
            [
                new Entity\IntegerField('ID', ['primary' => true, 'autocomplete' => true]),
                new Entity\IntegerField('USER_ID'),
            ],
            static::getOwnFields(),
            [
                new Entity\StringField('STATUS', ['default_value' => self::STATUS_NEW]),
                new Entity\StringField('STAGE'),
                new Entity\TextField('STATE_JSON'),
                new Entity\IntegerField('TOTAL_SECTIONS', ['default_value' => 0]),
                new Entity\IntegerField('TOTAL_ELEMENTS', ['default_value' => 0]),
                new Entity\IntegerField('PROCESSED_SECTIONS', ['default_value' => 0]),
                new Entity\IntegerField('PROCESSED_ELEMENTS', ['default_value' => 0]),
                new Entity\StringField('TMP_DIR'),
                new Entity\TextField('ERROR_MESSAGE'),
                new Entity\TextField('WARNINGS_JSON'),
                new Entity\IntegerField('LOCKED_AT'), // unix-время; защищает от параллельных тиков (агент + AJAX-опрос)
                new Entity\DatetimeField('DATE_CREATE', ['default_value' => function () {
                    return new DateTime();
                }]),
                new Entity\DatetimeField('DATE_FINISH'),
                new Entity\DatetimeField('DATE_EXPIRE'),
            ]
        );
    }

    public static function getJobById(int $id): ?array
    {
        $row = static::getList(['filter' => ['=ID' => $id], 'limit' => 1])->fetch();
        return $row ?: null;
    }

    /** Абсолютный путь рабочего каталога задания (TMP_DIR — имя каталога в TmpStorage). */
    public static function getTmpPath(array $job): string
    {
        return TmpStorage::getPath((string)$job['TMP_DIR']);
    }

    /** Когда истекает срок хранения завершённого (успешно или с ошибкой) задания — от текущего момента. */
    public static function expireDate(): DateTime
    {
        return DateTime::createFromTimestamp(time() + Options::getTtlHours() * 3600);
    }

    /**
     * Задания, которые давно не завершены и сейчас не обрабатываются (вкладку прогресса закрыли до конца
     * небольшой выгрузки, агенты перестали запускаться и т.п.), переводятся в ошибку с обычным сроком
     * хранения: иначе у них никогда не появится DATE_EXPIRE, и агент очистки не удалит ни запись, ни каталог.
     * "Давно" — дольше срока хранения, но не меньше суток: живое фоновое задание за это время завершается.
     */
    public static function failAbandoned(int $ttlHours): void
    {
        $rows = static::getList([
            'filter' => [
                '@STATUS' => [self::STATUS_NEW, self::STATUS_RUNNING],
                '<DATE_CREATE' => DateTime::createFromTimestamp(time() - max($ttlHours, 24) * 3600),
            ],
            'select' => ['ID', 'LOCKED_AT'],
            'limit' => 200,
        ]);

        $lockedAfter = time() - Options::getTickBudgetSeconds() * 4; // та же давность блокировки, что в TickRunner::runStep()
        while ($row = $rows->fetch()) {
            if ((int)$row['LOCKED_AT'] >= $lockedAfter) {
                continue; // прямо сейчас идёт тик
            }
            static::update($row['ID'], [
                'STATUS' => self::STATUS_ERROR,
                'ERROR_MESSAGE' => Loc::getMessage('IBX_JOB_ABANDONED'),
                'DATE_FINISH' => new DateTime(),
                'DATE_EXPIRE' => static::expireDate(),
            ]);
        }
    }

    /** Задание завершено (успешно или с ошибкой) — дальнейшие тики ему не нужны. */
    public static function isFinished(array $job): bool
    {
        return in_array($job['STATUS'], [self::STATUS_DONE, self::STATUS_ERROR], true);
    }

    /** Процент выполнения по обработанным/ожидаемым узлам (разделы + элементы); 100 — только у завершённого задания. */
    public static function calculateProgress(array $job): int
    {
        $totalNodes = max(1, (int)$job['TOTAL_SECTIONS'] + (int)$job['TOTAL_ELEMENTS']);
        $doneNodes = (int)$job['PROCESSED_SECTIONS'] + (int)$job['PROCESSED_ELEMENTS'];

        return $job['STATUS'] === self::STATUS_DONE ? 100 : (int)min(99, round(100 * $doneNodes / $totalNodes));
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

    /** Добавляет предупреждение; одинаковые объединяются с числом повторов, размер списка ограничен (см. WarningList). */
    public static function addWarning(int $jobId, string $message, int $count = 1): void
    {
        $job = static::getJobById($jobId);
        $warnings = WarningList::add(WarningList::decode($job['WARNINGS_JSON'] ?? null), $message, $count);
        static::update($jobId, ['WARNINGS_JSON' => WarningList::encode($warnings)]);
    }
}
