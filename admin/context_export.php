<?php

/**
 * Точка входа для ссылки "Экспорт", добавляемой в стандартные списки
 * элементов/разделов инфоблока (разделы 7.1 / 7.2 ТЗ). Вместо дублирования
 * формы с расчётом/запуском экспорта, страница передаёт управление той же
 * странице ручного экспорта (export.php) с предзаполненной сущностью — там
 * уже есть выбор режима и предварительный расчёт количества для разделов.
 */

require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_admin_before.php';

use Bitrix\Main\Loader;

Loader::includeModule('vspace.ibexport');

$iblockId = (int)($_REQUEST['IBLOCK_ID'] ?? 0);
$entityType = in_array($_REQUEST['ENTITY_TYPE'] ?? '', ['element', 'section'], true) ? $_REQUEST['ENTITY_TYPE'] : 'element';
$id = (int)($_REQUEST['ID'] ?? 0);

$url = '/bitrix/admin/vspace_ibexport_export.php?lang=' . LANGUAGE_ID
    . '&IBLOCK_ID=' . $iblockId
    . '&ENTITY_TYPE=' . $entityType
    . '&ENTITY_REF=' . $id
    . '&STEP=estimate';

LocalRedirect($url);
