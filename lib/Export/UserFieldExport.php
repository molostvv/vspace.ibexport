<?php

namespace Vspace\Ibexport\Export;

/**
 * Какие пользовательские поля (UF_*) раздела и в каком виде попадают в export.xml.
 * Переносятся только поля с самодостаточным значением: у файла, привязки к элементу/разделу
 * и списка значение — это ID записи этой инсталляции, на другой инсталляции оно указывало бы
 * на чужую запись. Не зависит от Bitrix — покрыт юнит-тестами.
 */
final class UserFieldExport
{
    /** Типы пользовательских полей, значение которых — обычный текст/число/дата. */
    public const SUPPORTED_TYPES = ['string', 'integer', 'double', 'boolean', 'date', 'datetime', 'url'];

    public static function isSupported(string $typeId): bool
    {
        return in_array($typeId, self::SUPPORTED_TYPES, true);
    }

    /**
     * @param array{USER_TYPE_ID?: string, MULTIPLE?: string, VALUE?: mixed} $field элемент результата UserFieldManager::GetUserFields()
     * @return string[] непустые значения в порядке хранения (у одиночного поля — не больше одного)
     */
    public static function values(array $field): array
    {
        $raw = $field['VALUE'] ?? null;
        $items = is_array($raw) ? $raw : [$raw];

        $values = [];
        foreach ($items as $item) {
            if ($item === null || $item === '' || is_array($item)) {
                continue;
            }
            $values[] = (string)$item;
        }

        return ($field['MULTIPLE'] ?? 'N') === 'Y' ? $values : array_slice($values, 0, 1);
    }
}
