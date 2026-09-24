<?php

namespace Vspace\Ibexport;

use Bitrix\Main\Type\Date;
use Bitrix\Main\Type\DateTime;

/**
 * Даты в export.xml — в ISO 8601 (2026-09-18 или 2026-09-18T10:00:00), а не в формате сайта: формат даты
 * у инсталляций может различаться (d.m.Y, m/d/Y …), и строка одной не разобралась бы на другой.
 * Время — "настенное" время сайта без часового пояса, как его хранит и показывает Bitrix.
 * Архивы версий до 1.1.0 содержат даты в формате сайта-источника — такие значения передаются как есть.
 *
 * parseIso() не зависит от Bitrix и покрыт юнит-тестами; остальное — обёртки над штатными функциями:
 * классическими (MakeTimeStamp/ConvertTimeStamp) для полей элемента, которые пишет CIBlockElement, и D7
 * (Type\Date/Type\DateTime) для пользовательских полей, которые штатные UF-типы принимают объектами.
 */
final class DateValue
{
    private const ISO_DATE = 'Y-m-d';
    private const ISO_DATETIME = 'Y-m-d\TH:i:s';

    /** Поле элемента (DATE_ACTIVE_FROM/TO в формате сайта) → ISO; пустое — пусто, неразборчивое — как есть. */
    public static function fieldToIso(?string $value): string
    {
        $value = trim((string)$value);
        if ($value === '' || !CheckDateTime($value)) {
            return $value;
        }

        return date(self::hasTime($value) ? self::ISO_DATETIME : self::ISO_DATE, MakeTimeStamp($value));
    }

    /** ISO из архива → строка в формате сайта для CIBlockElement::Add()/Update(); не ISO (архив до 1.1.0) — как есть. */
    public static function isoToField(string $value): string
    {
        $parsed = self::parseIso($value);

        return $parsed === null ? $value : ConvertTimeStamp($parsed['timestamp'], $parsed['time'] ? 'FULL' : 'SHORT');
    }

    /** Значение пользовательского поля date/datetime (строка в формате сайта) → ISO; неразборчивое — как есть. */
    public static function userFieldToIso(string $value, bool $withTime): string
    {
        try {
            return $withTime ? (new DateTime($value))->format(self::ISO_DATETIME) : (new Date($value))->format(self::ISO_DATE);
        } catch (\Throwable $e) {
            return $value;
        }
    }

    /** ISO из архива → Date/DateTime для пользовательского поля; не ISO (архив до 1.1.0) — строка как есть. */
    public static function isoToUserField(string $value, bool $withTime): Date|string
    {
        $parsed = self::parseIso($value);
        if ($parsed === null) {
            return $value;
        }

        return $withTime ? DateTime::createFromTimestamp($parsed['timestamp']) : Date::createFromTimestamp($parsed['timestamp']);
    }

    /**
     * @return array{timestamp: int, time: bool}|null null — не дата в ISO 8601 (или несуществующая дата)
     */
    public static function parseIso(string $value): ?array
    {
        if (!preg_match('~^(\d{4})-(\d{2})-(\d{2})(?:T(\d{2}):(\d{2}):(\d{2}))?\z~', trim($value), $m)) {
            return null;
        }
        [$year, $month, $day] = [(int)$m[1], (int)$m[2], (int)$m[3]];
        $time = isset($m[4]) && $m[4] !== '';
        [$hour, $minute, $second] = $time ? [(int)$m[4], (int)$m[5], (int)$m[6]] : [0, 0, 0];
        if (!checkdate($month, $day, $year) || $hour > 23 || $minute > 59 || $second > 59) {
            return null;
        }

        return ['timestamp' => mktime($hour, $minute, $second, $month, $day, $year), 'time' => $time];
    }

    private static function hasTime(string $value): bool
    {
        return (bool)preg_match('~\d{1,2}:\d{2}~', $value);
    }
}
