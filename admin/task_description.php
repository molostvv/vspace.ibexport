<?php
if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true) {
    die();
}

use Bitrix\Main\Localization\Loc;

Loc::loadMessages(__FILE__);

// Названия и описания задач модуля (install/index.php, GetModuleTasks()) для вкладки "Доступ" настроек модуля —
// ядро ищет этот файл по пути modules/<модуль>/admin/task_description.php (CTask::GetLangTitle()).
return [
    'VSPACE_IBEXPORT_DENIED' => [
        'title' => Loc::getMessage('TASK_NAME_VSPACE_IBEXPORT_DENIED'),
        'description' => Loc::getMessage('TASK_DESC_VSPACE_IBEXPORT_DENIED'),
    ],
    'VSPACE_IBEXPORT_EXPORT' => [
        'title' => Loc::getMessage('TASK_NAME_VSPACE_IBEXPORT_EXPORT'),
        'description' => Loc::getMessage('TASK_DESC_VSPACE_IBEXPORT_EXPORT'),
    ],
    'VSPACE_IBEXPORT_FULL' => [
        'title' => Loc::getMessage('TASK_NAME_VSPACE_IBEXPORT_FULL'),
        'description' => Loc::getMessage('TASK_DESC_VSPACE_IBEXPORT_FULL'),
    ],
];
