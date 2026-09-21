<?php

namespace Vspace\Ibexport\Admin;

/**
 * Единый вывод сообщений админки через штатный CAdminMessage — вместо
 * вручную собранной разметки adm-info-message-* и повторяющихся вызовов
 * ShowMessage() на каждой странице.
 */
final class AdminMessages
{
    /** Вывод ошибок над формой: ошибки — обычный текст, экранируются здесь. */
    public static function showErrors(array $errors): void
    {
        if ($errors) {
            \CAdminMessage::ShowMessage(['TYPE' => 'ERROR', 'MESSAGE' => implode('<br>', array_map('htmlspecialcharsbx', $errors))]);
        }
    }

    /** Вывод информационного сообщения об успехе (зелёный блок). */
    public static function showNotice(string $text): void
    {
        \CAdminMessage::ShowMessage(['TYPE' => 'OK', 'MESSAGE' => htmlspecialcharsbx($text)]);
    }

    /**
     * Разметка зелёного блока для вставки в страницу (страницы прогресса
     * пре-рендерят итог уже завершённого задания той же разметкой, что и JS
     * в progress.js для только что завершившегося).
     *
     * @param string $text Обычный текст — экранируется здесь
     */
    public static function ok(string $text): string
    {
        return (new \CAdminMessage(['TYPE' => 'OK', 'MESSAGE' => htmlspecialcharsbx($text), 'HTML' => true]))->Show();
    }

    /** То же, красный блок ошибки. */
    public static function error(string $text): string
    {
        return (new \CAdminMessage(['TYPE' => 'ERROR', 'MESSAGE' => htmlspecialcharsbx($text), 'HTML' => true]))->Show();
    }
}
