<?php

// Юнит-тесты работают без ядра Bitrix: вместо Bitrix\Main\Localization\Loc — заглушка,
// которая читает языковые файлы модуля (lang/ru/...), как это делает ядро.
require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/Stub/Loc.php';
