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
if (!defined('VSPACE_IBEXPORT_TMP_DIR')) {
    // Путь относительно $_SERVER['DOCUMENT_ROOT'], см. раздел 8 ТЗ.
    define('VSPACE_IBEXPORT_TMP_DIR', '/upload/tmp/vspace.ibexport');
}

// Автозагрузка классов регистрируется в install/index.php через Loader::registerAutoLoadClasses(),
// поэтому здесь ничего дополнительно подключать не нужно. Файл существует для
// обратной совместимости с CModule::IncludeModule() и ради констант выше.
