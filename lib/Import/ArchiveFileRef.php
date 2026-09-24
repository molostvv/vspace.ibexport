<?php

namespace Vspace\Ibexport\Import;

/**
 * Ссылки на файлы внутри архива выгрузки — атрибут file_ref в export.xml и имена записей ZIP.
 * Экспорт (Export\FileRefWriter) пишет только "files/<ID>_<имя из символов [A-Za-z0-9._-]>", поэтому всё
 * прочее — подкаталоги, "..", абсолютные пути — не принимается: иначе поддельный export.xml мог бы
 * указать на любой файл сервера (например, ../../bitrix/.settings.php), и импорт скопировал бы его
 * в инфоблок. Не зависит от Bitrix — покрыт юнит-тестами.
 */
final class ArchiveFileRef
{
    public const XML_FILE = 'export.xml';

    private const PATTERN = '~^files/[A-Za-z0-9_-][A-Za-z0-9._-]*\z~';

    public static function isValid(string $ref): bool
    {
        return (bool)preg_match(self::PATTERN, $ref);
    }

    /** Запись ZIP, которую импорт распаковывает: export.xml и файлы из files/, остальное пропускается. */
    public static function isExtractable(string $entryName): bool
    {
        return $entryName === self::XML_FILE || self::isValid($entryName);
    }

    /** Путь файла в распакованном архиве по допустимой ссылке (isValid()); существование не проверяется. */
    public static function path(string $tmpDir, string $ref): string
    {
        return rtrim($tmpDir, '/\\') . '/' . $ref;
    }
}
