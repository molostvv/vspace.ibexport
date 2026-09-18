<?php

namespace Vspace\Ibexport\Integration;

use Bitrix\Main\Localization\Loc;
use CAdminList;
use Vspace\Ibexport\Rights;

Loc::loadMessages(__FILE__);

/**
 * Добавляет пункт "Экспорт" в контекстное меню действий строки стандартных
 * списков элементов и разделов инфоблока в админке (разделы 7.1 / 7.2 ТЗ).
 *
 * Использует штатное событие ядра main::OnAdminListDisplay — оно вызывается
 * из CAdminList::Display() / CAdminUiList::DisplayList() (см.
 * bitrix/modules/main/interface/admin_list.php,
 * bitrix/modules/main/interface/admin_ui_list.php) прямо перед выводом
 * списка и передаёт сам объект списка со всеми уже добавленными строками.
 * Списки элементов/разделов инфоблока (iblock_element_admin.php,
 * iblock_section_admin.php) построены на CAdminUiList, который наследует
 * этот же механизм показа.
 *
 * Пункт действия строки добавляется через штатный API самой строки
 * ($row->aActions[], формат — тот же массив ID/ICON/TEXT/LINK, каким
 * ядро добавляет собственные действия "Изменить"/"Копировать" и т.д., см.
 * iblock_element_admin.php), а не парсингом уже отрисованного HTML через
 * JS/MutationObserver. Событие срабатывает при каждой перерисовке списка,
 * включая AJAX-подгрузку страниц и фильтра, так что пункт появляется
 * без каких-либо дополнительных скриптов. См. docs/admin-guide.md.
 */
class AdminListIntegration
{
    private const TARGET_SCRIPTS = [
        'iblock_element_admin.php',
        'iblock_section_admin.php',
    ];

    public static function onAdminListDisplay(CAdminList $list): void
    {
        if (!defined('ADMIN_SECTION')) {
            return;
        }

        $script = basename($_SERVER['SCRIPT_NAME'] ?? '');
        if (!in_array($script, self::TARGET_SCRIPTS, true)) {
            return;
        }

        $iblockId = (int)($_REQUEST['IBLOCK_ID'] ?? 0);
        if ($iblockId <= 0 || !Rights::canExport($iblockId)) {
            return;
        }

        $entityType = $script === 'iblock_section_admin.php' ? 'section' : 'element';
        $contextUrl = '/bitrix/admin/vspace_ibexport_context.php?lang=' . LANGUAGE_ID
            . '&IBLOCK_ID=' . $iblockId . '&ENTITY_TYPE=' . $entityType;

        foreach ($list->aRows as $row) {
            $entityId = (int)$row->id;
            if ($entityId <= 0) {
                continue;
            }

            $row->aActions[] = [
                'ID' => 'vspace_ibexport_export',
                'ICON' => 'download',
                'TEXT' => Loc::getMessage('IBEXPORT_CONTEXT_ACTION'),
                'LINK' => $contextUrl . '&ID=' . $entityId,
            ];
        }
    }
}
