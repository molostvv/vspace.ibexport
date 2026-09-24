<?php
if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true) {
    die();
}

// Константы, общие для кода модуля и тонких обёрток в /bitrix/admin/.
if (!defined('VSPACE_IBEXPORT_MODULE_ID')) {
    define('VSPACE_IBEXPORT_MODULE_ID', 'vspace.ibexport');
}
if (!defined('VSPACE_IBEXPORT_PATH')) {
    define('VSPACE_IBEXPORT_PATH', __DIR__);
}

// Классы lib/ подключаются автоматически: Loader::includeModule() регистрирует для партнёрского модуля
// PSR-4 пространство имён Vspace\Ibexport -> lib/. Рабочие каталоги заданий — см. lib/TmpStorage.php.
