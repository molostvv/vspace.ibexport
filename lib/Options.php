<?php

namespace Vspace\Ibexport;

use Bitrix\Main\Config\Option;

class Options
{
    const MODULE_ID = 'vspace.ibexport';

    /** Количество записей за одну выборку из CIBlockElement/SectionTable (раздел 8: батчами, не всё сразу). */
    public static function getBatchSize(): int
    {
        return (int)(Option::get(self::MODULE_ID, 'BATCH_SIZE', 200)) ?: 200;
    }

    /** Выше этого расчётного числа узлов (разделы+элементы) экспорт уходит в фон (агент), а не выполняется целиком в запросе. */
    public static function getSyncThreshold(): int
    {
        return (int)(Option::get(self::MODULE_ID, 'SYNC_THRESHOLD', 300)) ?: 300;
    }

    /** Максимум секунд, которые может занять один тик фоновой обработки, прежде чем отдать управление (защита от max_execution_time). */
    public static function getTickBudgetSeconds(): int
    {
        return (int)(Option::get(self::MODULE_ID, 'TICK_BUDGET', 12)) ?: 12;
    }

    /** Через сколько часов временные файлы и архив завершённого задания удаляются автоматически. */
    public static function getTtlHours(): int
    {
        return (int)(Option::get(self::MODULE_ID, 'TTL_HOURS', 24)) ?: 24;
    }

    public static function getDefaultWithFiles(): bool
    {
        return Option::get(self::MODULE_ID, 'DEFAULT_WITH_FILES', 'Y') === 'Y';
    }

    public static function getDefaultActiveOnly(): bool
    {
        return Option::get(self::MODULE_ID, 'DEFAULT_ACTIVE_ONLY', 'N') === 'Y';
    }

    /** Начальное состояние чекбокса "Обновлять существующие по коду" в форме импорта. */
    public static function getDefaultUpdateByCode(): bool
    {
        return Option::get(self::MODULE_ID, 'DEFAULT_UPDATE_BY_CODE', 'Y') === 'Y';
    }
}
