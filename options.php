<?php
/**
 * Настройки модуля (Настройки → Настройки продукта → Настройки модулей). Подключается из
 * bitrix/modules/main/admin/settings.php — пролог админки там уже подключён, а вывод буферизуется,
 * поэтому после сохранения работает LocalRedirect(). Вкладки — CAdminTabControl, значения —
 * \Bitrix\Main\Config\Option (читает lib/Options.php), вкладка "Доступ" — штатная group_rights2.php
 * (уровни доступа — задачи модуля, install/index.php GetModuleTasks()).
 *
 * Свои переменные — с префиксом $vibx: файл выполняется в глобальной области вместе с group_rights2.php.
 *
 * @global CMain $APPLICATION
 * @global CUser $USER
 */

use Bitrix\Main\Config\Option;
use Bitrix\Main\Context;
use Bitrix\Main\Loader;
use Vspace\Ibexport\Admin\AdminMessages;

if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true) {
    die();
}

$module_id = 'vspace.ibexport'; // это имя переменной ожидает group_rights2.php

if (!$USER->IsAdmin() || !Loader::includeModule($module_id)) {
    return;
}

IncludeModuleLangFile($_SERVER['DOCUMENT_ROOT'] . BX_ROOT . '/modules/main/options.php');
IncludeModuleLangFile(__FILE__);

/** Числовые параметры: код => [по умолчанию, минимум, максимум] (умолчания — как в lib/Options.php). */
$vibxNumeric = [
    'BATCH_SIZE' => [200, 1, 5000],
    'SYNC_THRESHOLD' => [300, 1, 1000000],
    'TICK_BUDGET' => [12, 1, 300],
    'TTL_HOURS' => [24, 1, 8760],
];
/** Флажки: код => значение по умолчанию. */
$vibxCheckboxes = [
    'DEFAULT_WITH_FILES' => 'Y',
    'DEFAULT_ACTIVE_ONLY' => 'N',
    'DEFAULT_UPDATE_BY_CODE' => 'Y',
    'YANDEX_DISK_AUTO_UPLOAD' => 'Y',
];

$tabControl = new CAdminTabControl('tabControl', [
    ['DIV' => 'edit1', 'TAB' => GetMessage('IBEXPORT_OPT_TAB_SETTINGS'), 'TITLE' => GetMessage('IBEXPORT_OPT_TAB_SETTINGS_TITLE')],
    ['DIV' => 'edit2', 'TAB' => GetMessage('IBEXPORT_OPT_TAB_RIGHTS'), 'TITLE' => GetMessage('IBEXPORT_OPT_TAB_RIGHTS_TITLE')],
]);

$vibxRequest = Context::getCurrent()->getRequest();
$vibxErrors = [];
$vibxPosted = []; // введённые значения — чтобы при ошибке показать их, а не сохранённые

if (
    $vibxRequest->isPost()
    && ((string)$vibxRequest->getPost('Update') !== '' || (string)$vibxRequest->getPost('Apply') !== '')
    && check_bitrix_sessid()
) {
    foreach ($vibxNumeric as $vibxCode => [$vibxDefault, $vibxMin, $vibxMax]) {
        $vibxPosted[$vibxCode] = trim((string)$vibxRequest->getPost($vibxCode));
        if (!preg_match('/^\d+$/', $vibxPosted[$vibxCode]) || (int)$vibxPosted[$vibxCode] < $vibxMin || (int)$vibxPosted[$vibxCode] > $vibxMax) {
            $vibxErrors[] = GetMessage('IBEXPORT_OPT_ERR_RANGE', [
                '#NAME#' => GetMessage('IBEXPORT_OPT_' . $vibxCode),
                '#MIN#' => $vibxMin,
                '#MAX#' => $vibxMax,
            ]);
        }
    }

    if (!$vibxErrors) {
        foreach ($vibxNumeric as $vibxCode => $vibxRange) {
            Option::set($module_id, $vibxCode, (string)(int)$vibxPosted[$vibxCode]);
        }
        foreach ($vibxCheckboxes as $vibxCode => $vibxDefault) {
            Option::set($module_id, $vibxCode, $vibxRequest->getPost($vibxCode) === 'Y' ? 'Y' : 'N');
        }

        // Уровни доступа групп с вкладки "Доступ" сохраняет штатный обработчик (ему нужна переменная $Update).
        $Update = 'Y';
        ob_start();
        require_once $_SERVER['DOCUMENT_ROOT'] . BX_ROOT . '/modules/main/admin/group_rights2.php';
        ob_end_clean();

        // Post/Redirect/Get: обновление страницы после сохранения не отправит форму повторно.
        LocalRedirect($APPLICATION->GetCurPage() . '?mid=' . urlencode($module_id) . '&lang=' . urlencode(LANGUAGE_ID) . '&' . $tabControl->ActiveTabParam());
    }
}

AdminMessages::showErrors($vibxErrors);
?>
<form method="post" action="<?= $APPLICATION->GetCurPage() ?>?mid=<?= urlencode($module_id) ?>&amp;lang=<?= LANGUAGE_ID ?>">
    <?php $tabControl->Begin(); ?>
    <?php $tabControl->BeginNextTab(); ?>

    <?php foreach ($vibxNumeric as $vibxCode => [$vibxDefault, $vibxMin, $vibxMax]): ?>
        <tr>
            <td width="50%"><label for="vibx_<?= $vibxCode ?>"><?= GetMessage('IBEXPORT_OPT_' . $vibxCode) ?></label></td>
            <td width="50%">
                <input type="text" size="8" id="vibx_<?= $vibxCode ?>" name="<?= $vibxCode ?>"
                       value="<?= htmlspecialcharsbx($vibxPosted[$vibxCode] ?? Option::get($module_id, $vibxCode, (string)$vibxDefault)) ?>">
                <?= GetMessage('IBEXPORT_OPT_RANGE_HINT', ['#MIN#' => $vibxMin, '#MAX#' => $vibxMax]) ?>
            </td>
        </tr>
    <?php endforeach; ?>
    <?php foreach ($vibxCheckboxes as $vibxCode => $vibxDefault): ?>
        <?php $vibxChecked = $vibxPosted ? $vibxRequest->getPost($vibxCode) === 'Y' : Option::get($module_id, $vibxCode, $vibxDefault) === 'Y'; ?>
        <tr>
            <td width="50%"><label for="vibx_<?= $vibxCode ?>"><?= GetMessage('IBEXPORT_OPT_' . $vibxCode) ?></label></td>
            <td width="50%"><input type="checkbox" id="vibx_<?= $vibxCode ?>" name="<?= $vibxCode ?>" value="Y" <?= $vibxChecked ? 'checked' : '' ?>></td>
        </tr>
    <?php endforeach; ?>

    <?php $tabControl->BeginNextTab(); ?>
    <?php require_once $_SERVER['DOCUMENT_ROOT'] . BX_ROOT . '/modules/main/admin/group_rights2.php'; ?>

    <?php $tabControl->Buttons(); ?>
    <input type="submit" name="Update" value="<?= GetMessage('MAIN_SAVE') ?>" class="adm-btn-save">
    <input type="submit" name="Apply" value="<?= GetMessage('MAIN_OPT_APPLY') ?>">
    <?= bitrix_sessid_post() ?>
    <?php $tabControl->End(); ?>
</form>
