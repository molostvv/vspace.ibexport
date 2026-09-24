<?php

namespace Bitrix\Main\Localization;

/**
 * Заглушка штатного Loc для юнит-тестов (ядро Bitrix в тестах не поднимается): loadMessages()
 * читает русский языковой файл модуля по тому же правилу, что и ядро, — lang/ru/<путь файла
 * относительно корня модуля>, getMessage() подставляет #МАРКЕРЫ#. Благодаря этому тесты проверяют
 * те же тексты, что видит пользователь.
 */
final class Loc
{
    /** @var array<string, string> */
    private static array $messages = [];

    public static function loadMessages($file): void
    {
        $root = str_replace('\\', '/', dirname(__DIR__, 2));
        $file = str_replace('\\', '/', (string)$file);
        if (!str_starts_with($file, $root . '/')) {
            return;
        }

        $langFile = $root . '/lang/ru/' . substr($file, strlen($root) + 1);
        if (is_file($langFile)) {
            $MESS = [];
            include $langFile;
            self::$messages = $MESS + self::$messages;
        }
    }

    public static function getMessage($code, $replace = null, $language = null): ?string
    {
        $message = self::$messages[$code] ?? null;
        if ($message !== null && is_array($replace)) {
            $message = strtr($message, array_map('strval', $replace));
        }

        return $message;
    }
}
