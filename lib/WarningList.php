<?php

namespace Vspace\Ibexport;

/**
 * Список предупреждений задания (WARNINGS_JSON): одинаковые сообщения объединяются в одно с числом
 * повторов ("… (×60)"), а размер списка ограничен — колонка TEXT вмещает 64 КБ, и без лимита
 * накопленный JSON молча обрезался (а следующая запись начинала список заново).
 * Не зависит от Bitrix — покрыт юнит-тестами.
 */
final class WarningList
{
    /** Сколько разных сообщений хранить; остальные считаются в итоговой строке "… и ещё N". */
    public const MAX_ENTRIES = 100;

    /** Потолок размера JSON (с запасом под колонку TEXT — 65535 байт). */
    public const MAX_BYTES = 60000;

    private const OVERFLOW_PREFIX = '… и ещё ';
    private const OVERFLOW_SUFFIX = ' предупреждений не показано (лимит журнала)';
    private const REPEAT_MARK = ' (×';

    /** Читает WARNINGS_JSON; повреждённое или пустое значение — пустой список (а не null и не исключение). */
    public static function decode(?string $json): array
    {
        $decoded = ($json !== null && $json !== '') ? json_decode($json, true) : null;
        if (!is_array($decoded)) {
            return [];
        }

        return array_values(array_map('strval', $decoded));
    }

    public static function encode(array $warnings): string
    {
        return json_encode(array_values($warnings), JSON_UNESCAPED_UNICODE);
    }

    /**
     * @param string[] $warnings текущий список
     * @param int $count сколько раз сообщение встретилось
     * @return string[] новый список
     */
    public static function add(array $warnings, string $message, int $count = 1): array
    {
        [$entries, $overflow] = self::splitOverflow($warnings);

        foreach ($entries as $i => $entry) {
            $repeats = self::repeatsOf($entry, $message);
            if ($repeats !== null) {
                $entries[$i] = self::format($message, $repeats + $count);

                return self::join($entries, $overflow);
            }
        }

        $candidate = array_merge($entries, [self::format($message, $count)]);
        if (count($candidate) > self::MAX_ENTRIES || strlen(self::encode(self::join($candidate, $overflow + $count))) > self::MAX_BYTES) {
            return self::join($entries, $overflow + $count);
        }

        return self::join($candidate, $overflow);
    }

    private static function format(string $message, int $repeats): string
    {
        return $repeats > 1 ? $message . self::REPEAT_MARK . $repeats . ')' : $message;
    }

    /** @return int|null число повторов, если $entry — это $message (с суффиксом или без), иначе null */
    private static function repeatsOf(string $entry, string $message): ?int
    {
        if ($entry === $message) {
            return 1;
        }

        $prefix = $message . self::REPEAT_MARK;
        if (str_starts_with($entry, $prefix) && preg_match('/^(\d+)\)$/', substr($entry, strlen($prefix)), $m)) {
            return (int)$m[1];
        }

        return null;
    }

    /** @return array{0: string[], 1: int} обычные записи и число предупреждений, не поместившихся в лимит */
    private static function splitOverflow(array $warnings): array
    {
        $last = end($warnings);
        if (is_string($last) && str_starts_with($last, self::OVERFLOW_PREFIX) && preg_match('/^' . preg_quote(self::OVERFLOW_PREFIX, '/') . '(\d+)/u', $last, $m)) {
            array_pop($warnings);

            return [array_values($warnings), (int)$m[1]];
        }

        return [array_values($warnings), 0];
    }

    private static function join(array $entries, int $overflow): array
    {
        if ($overflow > 0) {
            $entries[] = self::OVERFLOW_PREFIX . $overflow . self::OVERFLOW_SUFFIX;
        }

        return $entries;
    }
}
