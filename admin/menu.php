<?php

if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true) {
    die();
}

use Bitrix\Main\Loader;

if (!Loader::includeModule('vspace.ibexport')) {
    return [];
}

global $USER;
if (
    !$USER->IsAdmin()
    && !$USER->CanDoOperation('vspace_ibexport_export')
    && !$USER->CanDoOperation('vspace_ibexport_import')
) {
    return [];
}

IncludeModuleLangFile(__FILE__);

return [
    [
        'parent_menu' => 'global_menu_content',
        'sort' => 500,
        'text' => GetMessage('IBEXPORT_MENU_TEXT'),
        'title' => GetMessage('IBEXPORT_MENU_TITLE'),
        'items_id' => 'menu_vspace_ibexport',
        'url' => 'vspace_ibexport_export.php?lang=' . LANGUAGE_ID,
        'more_url' => [
            'vspace_ibexport_export.php',
            'vspace_ibexport_progress.php',
            'vspace_ibexport_log.php',
            'vspace_ibexport_import.php',
            'vspace_ibexport_import_progress.php',
            'vspace_ibexport_import_log.php',
        ],
        'items' => [
            [
                'text' => GetMessage('IBEXPORT_MENU_EXPORT'),
                'url' => 'vspace_ibexport_export.php?lang=' . LANGUAGE_ID,
                'more_url' => ['vspace_ibexport_export.php', 'vspace_ibexport_progress.php'],
            ],
            [
                'text' => GetMessage('IBEXPORT_MENU_LOG'),
                'url' => 'vspace_ibexport_log.php?lang=' . LANGUAGE_ID,
                'more_url' => ['vspace_ibexport_log.php'],
            ],
            [
                'text' => GetMessage('IBEXPORT_MENU_IMPORT'),
                'url' => 'vspace_ibexport_import.php?lang=' . LANGUAGE_ID,
                'more_url' => ['vspace_ibexport_import.php', 'vspace_ibexport_import_progress.php'],
            ],
            [
                'text' => GetMessage('IBEXPORT_MENU_IMPORT_LOG'),
                'url' => 'vspace_ibexport_import_log.php?lang=' . LANGUAGE_ID,
                'more_url' => ['vspace_ibexport_import_log.php'],
            ],
        ],
    ],
];
