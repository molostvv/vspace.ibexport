<?php

namespace Vspace\Ibexport\YandexDisk;

use Bitrix\Main\Application;
use Bitrix\Main\Entity;
use Bitrix\Main\ORM\Data\DataManager;
use Bitrix\Main\Type\DateTime;

/**
 * Выгрузка архива задания экспорта на Яндекс.Диск: одна строка на задание — идёт, выгружен (путь на Диске) или
 * ошибка (текст). По ней страница прогресса показывает результат и после перезагрузки, и для выгрузки, сделанной в
 * фоне агентом. Отдельная таблица, а не колонки JobTable: на инсталляции, где после замены файлов не выполнен
 * InstallDB(), её просто нет (isAvailable()) — выгрузка работает, только результат не запоминается; новые колонки
 * JobTable ломали бы там любой запрос к заданиям экспорта.
 */
class JobUploadTable extends DataManager
{
    public const STATUS_RUNNING = 'RUNNING';
    public const STATUS_DONE = 'DONE';
    public const STATUS_ERROR = 'ERROR';

    public static function getTableName()
    {
        return 'vspace_ibexport_disk_upload';
    }

    public static function getMap()
    {
        return [
            new Entity\IntegerField('ID', ['primary' => true, 'autocomplete' => true]),
            new Entity\IntegerField('JOB_ID', ['required' => true]),
            new Entity\StringField('STATUS', ['required' => true]),
            new Entity\StringField('DISK_PATH'),
            new Entity\TextField('MESSAGE'),
            new Entity\DatetimeField('DATE_UPDATE', ['default_value' => function () {
                return new DateTime();
            }]),
        ];
    }

    /** Таблица появилась в версии 1.5.0 — до InstallDB() её нет. */
    public static function isAvailable(): bool
    {
        static $available = null;

        return $available ??= Application::getConnection()->isTableExists(self::getTableName());
    }

    /** Состояние выгрузки задания (последняя запись). */
    public static function remember(int $jobId, string $status, string $diskPath, string $message = ''): void
    {
        if (!self::isAvailable()) {
            return;
        }
        self::deleteByJob($jobId);
        self::add(['JOB_ID' => $jobId, 'STATUS' => $status, 'DISK_PATH' => $diskPath, 'MESSAGE' => $message]);
    }

    /** @return array{status: string, disk_path: string, message: string}|null null — выгрузки не было (или таблицы нет) */
    public static function getByJob(int $jobId): ?array
    {
        if (!self::isAvailable()) {
            return null;
        }
        $row = self::getList([
            'filter' => ['=JOB_ID' => $jobId],
            'select' => ['STATUS', 'DISK_PATH', 'MESSAGE'],
            'order' => ['ID' => 'DESC'],
            'limit' => 1,
        ])->fetch();

        return $row ? ['status' => (string)$row['STATUS'], 'disk_path' => (string)$row['DISK_PATH'], 'message' => (string)$row['MESSAGE']] : null;
    }

    /** Вместе с заданием (Exporter::cleanupAgent()) удаляется и его состояние выгрузки. */
    public static function deleteByJob(int $jobId): void
    {
        if (!self::isAvailable()) {
            return;
        }
        $rows = self::getList(['filter' => ['=JOB_ID' => $jobId], 'select' => ['ID']]);
        while ($row = $rows->fetch()) {
            self::delete($row['ID']);
        }
    }
}
