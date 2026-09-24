<?php
if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true) {
    die();
}

use Bitrix\Main\Localization\Loc;

Loc::loadMessages(__FILE__);

// Названия операций модуля (их проверяет lib/Rights.php через $USER->CanDoOperation()).
return [
    'VSPACE_IBEXPORT_EXPORT' => [
        'title' => Loc::getMessage('OP_NAME_VSPACE_IBEXPORT_EXPORT'),
    ],
    'VSPACE_IBEXPORT_IMPORT' => [
        'title' => Loc::getMessage('OP_NAME_VSPACE_IBEXPORT_IMPORT'),
    ],
];
