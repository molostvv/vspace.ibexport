<?php

namespace Vspace\Ibexport;

class Rights
{
    const MODULE_ID = 'vspace.ibexport';

    /**
     * Пользователь может экспортировать данные инфоблока, если у него есть
     * хотя бы право на чтение (раздел 10 ТЗ) — либо через отдельное право
     * модуля "export", либо через стандартное право на чтение элементов/разделов.
     */
    public static function canExport(int $iblockId): bool
    {
        global $USER;

        if ($USER->IsAdmin()) {
            return true;
        }

        if ($USER->CanDoOperation('vspace_ibexport_export')) {
            return true;
        }

        if (!\Bitrix\Main\Loader::includeModule('iblock')) {
            return false;
        }

        $elementRight = \CIBlockRights::UserHasRightTo($iblockId, $iblockId, 'element_read');
        $sectionRight = \CIBlockRights::UserHasRightTo($iblockId, $iblockId, 'section_read');

        return $elementRight || $sectionRight;
    }

    public static function requireExportRight(int $iblockId): void
    {
        if (!self::canExport($iblockId)) {
            throw new \Bitrix\Main\AccessDeniedException('Недостаточно прав для экспорта данного инфоблока.');
        }
    }

    /**
     * Импорт пишет данные (создаёт/обновляет элементы и разделы), поэтому,
     * в отличие от canExport(), проверяется право на редактирование, а не
     * на чтение.
     */
    public static function canImport(int $iblockId): bool
    {
        global $USER;

        if ($USER->IsAdmin()) {
            return true;
        }

        if ($USER->CanDoOperation('vspace_ibexport_import')) {
            return true;
        }

        if (!\Bitrix\Main\Loader::includeModule('iblock')) {
            return false;
        }

        $elementRight = \CIBlockRights::UserHasRightTo($iblockId, $iblockId, 'element_edit');
        $sectionRight = \CIBlockRights::UserHasRightTo($iblockId, $iblockId, 'section_edit');

        return $elementRight || $sectionRight;
    }

    public static function requireImportRight(int $iblockId): void
    {
        if (!self::canImport($iblockId)) {
            throw new \Bitrix\Main\AccessDeniedException('Недостаточно прав для импорта в данный инфоблок.');
        }
    }
}
