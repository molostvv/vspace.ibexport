<?php

namespace Vspace\Ibexport;

use Bitrix\Main\Application;
use Bitrix\Main\IO\Directory;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Security\Random;

Loc::loadMessages(__FILE__);

/**
 * Рабочие каталоги заданий: export.xml, скопированные файлы и готовый ZIP экспорта, распакованный
 * архив импорта. В БД (поле TMP_DIR) хранится только имя каталога.
 *
 * Корень — штатный каталог временных файлов Bitrix (CTempFile::GetAbsoluteRoot()): константа
 * BX_TEMPORARY_FILES_DIRECTORY, если задана (на bitrixenv — /home/bitrix/tmpfiles, вне корня сайта),
 * иначе /upload/tmp. Во втором случае каталог доступен из веба, поэтому в корень модуля кладётся
 * .htaccess с запретом доступа (для nginx правило добавляется в конфиг сервера — docs/admin-guide.md),
 * а имена каталогов случайные: uniqid() угадывается по времени создания задания.
 */
final class TmpStorage
{
    public const PREFIX_EXPORT = 'job';
    public const PREFIX_IMPORT = 'import';

    /** Расположение до версии 1.1.0 (относительно корня сайта): после обновления там только брошенные файлы. */
    public const LEGACY_DIR = '/upload/tmp/vspace.ibexport';

    private const SUBDIR = 'vspace.ibexport';

    private const HTACCESS = "# vspace.ibexport working files: direct web access is forbidden.\n"
        . "<IfModule mod_authz_core.c>\n\tRequire all denied\n</IfModule>\n"
        . "<IfModule !mod_authz_core.c>\n\tOrder allow,deny\n\tDeny from all\n</IfModule>\n";

    public static function getRoot(): string
    {
        return rtrim(str_replace('\\', '/', \CTempFile::GetAbsoluteRoot()), '/') . '/' . self::SUBDIR;
    }

    /** Имя рабочего каталога: вид задания и случайная часть (у каталогов, созданных до 1.1.0, — uniqid(), в т.ч. с точкой). */
    public static function isValidName(string $name, ?string $prefix = null): bool
    {
        $prefixes = $prefix !== null ? preg_quote($prefix, '~') : self::PREFIX_EXPORT . '|' . self::PREFIX_IMPORT;

        return (bool)preg_match('~^(' . $prefixes . ')_[A-Za-z0-9.]+\z~', $name);
    }

    /** Абсолютный путь рабочего каталога; имя проверяется — оно приходит из БД и из скрытых полей формы. */
    public static function getPath(string $name): string
    {
        if (!self::isValidName($name)) {
            throw new \InvalidArgumentException(Loc::getMessage('IBX_TMP_BAD_NAME'));
        }

        return self::getRoot() . '/' . $name;
    }

    /** Создаёт новый рабочий каталог и возвращает его имя (значение для TMP_DIR). */
    public static function create(string $prefix): string
    {
        self::ensureRoot();

        $name = $prefix . '_' . Random::getString(20);
        $path = self::getPath($name);
        Directory::createDirectory($path);
        if (!is_dir($path)) {
            throw new \RuntimeException(Loc::getMessage('IBX_TMP_CREATE_FAILED', ['#PATH#' => self::getRoot()]));
        }

        return $name;
    }

    /** Удаляет рабочий каталог; сбой (права, занятый файл) не бросает исключение — это вызывается из агентов очистки. */
    public static function delete(string $name): void
    {
        if (self::isValidName($name)) {
            self::deleteQuietly(self::getRoot() . '/' . $name);
        }
    }

    /**
     * Удаляет каталоги вида $prefix, созданные раньше $olderThan (unix-время), которыми не пользуется ни одно
     * задание: например, архивы импорта, которые проверили, но так и не запустили.
     *
     * @param \Closure(string): bool $isUsed Принимает имя каталога, возвращает true, если на него ссылается задание
     */
    public static function deleteOrphans(string $prefix, int $olderThan, \Closure $isUsed): void
    {
        $root = self::getRoot();
        if (!is_dir($root)) {
            return;
        }

        foreach (scandir($root) ?: [] as $name) {
            $path = $root . '/' . $name;
            if (self::isValidName($name, $prefix) && is_dir($path) && filemtime($path) < $olderThan && !$isUsed($name)) {
                self::deleteQuietly($path);
            }
        }
    }

    /**
     * Каталог версий до 1.1.0 (/upload/tmp/vspace.ibexport — внутри корня сайта): после обновления задания ищут
     * файлы в getRoot(), так что там остаются только брошенные файлы. Удаляется, если это не тот же каталог.
     */
    public static function deleteLegacy(): void
    {
        $legacy = realpath(Application::getDocumentRoot() . self::LEGACY_DIR);
        if ($legacy !== false && $legacy !== realpath(self::getRoot())) {
            self::deleteQuietly($legacy);
        }
    }

    /** Все рабочие каталоги модуля — при удалении модуля. */
    public static function deleteAll(): void
    {
        self::deleteQuietly(self::getRoot());
        self::deleteLegacy();
    }

    private static function deleteQuietly(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }
        try {
            Directory::deleteDirectory($path);
        } catch (\Throwable $e) {
            // не удалось сейчас — агент очистки попробует снова в следующий запуск
        }
    }

    private static function ensureRoot(): void
    {
        $root = self::getRoot();
        if (!is_dir($root)) {
            Directory::createDirectory($root);
        }
        if (is_dir($root) && !is_file($root . '/.htaccess')) {
            file_put_contents($root . '/.htaccess', self::HTACCESS);
        }
    }
}
