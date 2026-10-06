<?php

namespace Vspace\Ibexport;

use Bitrix\Main\Application;
use Bitrix\Main\Entity;
use Bitrix\Main\ORM\Data\DataManager;
use Bitrix\Main\Type\DateTime;
use Vspace\Ibexport\Import\SourceInfo;

/**
 * История импортированных архивов: запись на каждый успешно завершённый импорт. Журнал импорта (ImportJobTable)
 * удаляется через TTL_HOURS вместе с рабочим каталогом, а эта таблица хранится бессрочно — по ней список файлов на
 * Яндекс.Диске помечает архивы, уже импортированные на этой инсталляции. Архив узнаётся по MD5 содержимого (Диск
 * отдаёт его в списке файлов), а не по имени: имя могло достаться и другому архиву (например, после переустановки
 * модуля на сайте-источнике номера заданий в именах начинаются заново).
 */
class ImportedFileTable extends DataManager
{
    public static function getTableName()
    {
        return 'vspace_ibexport_imported_file';
    }

    public static function getMap()
    {
        return [
            new Entity\IntegerField('ID', ['primary' => true, 'autocomplete' => true]),
            new Entity\StringField('FILE_MD5', ['required' => true]),
            new Entity\StringField('FILE_NAME'),
            new Entity\IntegerField('FILE_SIZE', ['default_value' => 0]),
            new Entity\IntegerField('TARGET_IBLOCK_ID', ['default_value' => 0]),
            new Entity\IntegerField('IMPORT_JOB_ID', ['default_value' => 0]),
            new Entity\IntegerField('USER_ID', ['default_value' => 0]),
            new Entity\DatetimeField('DATE_IMPORT', ['default_value' => function () {
                return new DateTime();
            }]),
        ];
    }

    /**
     * Таблица появилась в версии 1.3.0: на инсталляции, где после замены файлов не выполнен InstallDB(), её нет —
     * тогда история просто не ведётся, а импорт и список файлов Диска работают как раньше.
     */
    public static function isAvailable(): bool
    {
        static $available = null;

        return $available ??= Application::getConnection()->isTableExists(self::getTableName());
    }

    /** Запоминает успешно завершённое задание импорта; сведения об архиве — из его рабочего каталога (SourceInfo). */
    public static function remember(array $job, string $tmpDir): void
    {
        $source = SourceInfo::read($tmpDir);
        if ($source === null || !self::isAvailable()) {
            return;
        }

        self::add([
            'FILE_MD5' => $source['md5'],
            'FILE_NAME' => (string)$job['SOURCE_FILE_NAME'],
            'FILE_SIZE' => $source['size'],
            'TARGET_IBLOCK_ID' => (int)$job['TARGET_IBLOCK_ID'],
            'IMPORT_JOB_ID' => (int)$job['ID'],
            'USER_ID' => (int)$job['USER_ID'],
        ]);
    }

    /**
     * Последний импорт каждого из архивов.
     *
     * @param string[] $md5List MD5 архивов (пустые значения пропускаются)
     * @return array<string, array{DATE_IMPORT: DateTime, TARGET_IBLOCK_ID: int, COUNT: int}> MD5 => последний импорт и число импортов
     */
    public static function findLatestByMd5(array $md5List): array
    {
        $md5List = array_values(array_unique(array_filter($md5List)));
        if (!$md5List || !self::isAvailable()) {
            return [];
        }

        $rows = self::getList([
            'filter' => ['@FILE_MD5' => $md5List],
            'select' => ['FILE_MD5', 'DATE_IMPORT', 'TARGET_IBLOCK_ID'],
            'order' => ['ID' => 'ASC'],
        ]);
        $latest = [];
        while ($row = $rows->fetch()) {
            $md5 = (string)$row['FILE_MD5'];
            $latest[$md5] = [
                'DATE_IMPORT' => $row['DATE_IMPORT'],
                'TARGET_IBLOCK_ID' => (int)$row['TARGET_IBLOCK_ID'],
                'COUNT' => ($latest[$md5]['COUNT'] ?? 0) + 1,
            ];
        }

        return $latest;
    }
}
