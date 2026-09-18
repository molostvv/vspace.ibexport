<?php

require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_admin_before.php';

use Bitrix\Main\Config\Option;
use Bitrix\Main\Loader;

$moduleId = 'vspace.ibexport';

if (!Loader::includeModule($moduleId)) {
    return;
}

IncludeModuleLangFile(__FILE__);

global $APPLICATION, $USER;

if (!$USER->IsAdmin()) {
    $APPLICATION->AuthForm(GetMessage('ACCESS_DENIED'));
    die();
}

$fields = ['BATCH_SIZE', 'SYNC_THRESHOLD', 'TICK_BUDGET', 'TTL_HOURS', 'DEFAULT_WITH_FILES', 'DEFAULT_ACTIVE_ONLY', 'DEFAULT_UPDATE_BY_CODE'];

if (($_REQUEST['Update'] ?? '') === 'Y' && check_bitrix_sessid()) {
    Option::set($moduleId, 'BATCH_SIZE', (int)($_REQUEST['BATCH_SIZE'] ?? 0));
    Option::set($moduleId, 'SYNC_THRESHOLD', (int)($_REQUEST['SYNC_THRESHOLD'] ?? 0));
    Option::set($moduleId, 'TICK_BUDGET', (int)($_REQUEST['TICK_BUDGET'] ?? 0));
    Option::set($moduleId, 'TTL_HOURS', (int)($_REQUEST['TTL_HOURS'] ?? 0));
    Option::set($moduleId, 'DEFAULT_WITH_FILES', ($_REQUEST['DEFAULT_WITH_FILES'] ?? '') === 'Y' ? 'Y' : 'N');
    Option::set($moduleId, 'DEFAULT_ACTIVE_ONLY', ($_REQUEST['DEFAULT_ACTIVE_ONLY'] ?? '') === 'Y' ? 'Y' : 'N');
    Option::set($moduleId, 'DEFAULT_UPDATE_BY_CODE', ($_REQUEST['DEFAULT_UPDATE_BY_CODE'] ?? '') === 'Y' ? 'Y' : 'N');
}

$tabControl = new CAdminTabControl('tabControl', [
    ['DIV' => 'edit1', 'TAB' => GetMessage('MAIN_TAB_SET'), 'TITLE' => GetMessage('MAIN_TAB_TITLE')],
]);
?>
<form method="post" action="<?= $APPLICATION->GetCurPage() ?>?mid=<?= $moduleId ?>&lang=<?= LANGUAGE_ID ?>">
    <?php $tabControl->Begin(); ?>
    <?= bitrix_sessid_post() ?>
    <?php $tabControl->BeginNextTab(); ?>

    <tr>
        <td><?= GetMessage('IBEXPORT_OPT_BATCH_SIZE') ?></td>
        <td><input type="text" size="6" name="BATCH_SIZE" value="<?= (int)Option::get($moduleId, 'BATCH_SIZE', 200) ?>"></td>
    </tr>
    <tr>
        <td><?= GetMessage('IBEXPORT_OPT_SYNC_THRESHOLD') ?></td>
        <td><input type="text" size="6" name="SYNC_THRESHOLD" value="<?= (int)Option::get($moduleId, 'SYNC_THRESHOLD', 300) ?>"></td>
    </tr>
    <tr>
        <td><?= GetMessage('IBEXPORT_OPT_TICK_BUDGET') ?></td>
        <td><input type="text" size="6" name="TICK_BUDGET" value="<?= (int)Option::get($moduleId, 'TICK_BUDGET', 12) ?>"></td>
    </tr>
    <tr>
        <td><?= GetMessage('IBEXPORT_OPT_TTL_HOURS') ?></td>
        <td><input type="text" size="6" name="TTL_HOURS" value="<?= (int)Option::get($moduleId, 'TTL_HOURS', 24) ?>"></td>
    </tr>
    <tr>
        <td><?= GetMessage('IBEXPORT_OPT_DEFAULT_WITH_FILES') ?></td>
        <td><input type="checkbox" name="DEFAULT_WITH_FILES" value="Y" <?= Option::get($moduleId, 'DEFAULT_WITH_FILES', 'Y') === 'Y' ? 'checked' : '' ?>></td>
    </tr>
    <tr>
        <td><?= GetMessage('IBEXPORT_OPT_DEFAULT_ACTIVE_ONLY') ?></td>
        <td><input type="checkbox" name="DEFAULT_ACTIVE_ONLY" value="Y" <?= Option::get($moduleId, 'DEFAULT_ACTIVE_ONLY', 'N') === 'Y' ? 'checked' : '' ?>></td>
    </tr>
    <tr>
        <td><?= GetMessage('IBEXPORT_OPT_DEFAULT_UPDATE_BY_CODE') ?></td>
        <td><input type="checkbox" name="DEFAULT_UPDATE_BY_CODE" value="Y" <?= Option::get($moduleId, 'DEFAULT_UPDATE_BY_CODE', 'Y') === 'Y' ? 'checked' : '' ?>></td>
    </tr>

    <?php $tabControl->Buttons(); ?>
    <input type="hidden" name="Update" value="Y">
    <input type="submit" value="<?= GetMessage('MAIN_SAVE') ?>" class="adm-btn-save">
    <?php $tabControl->End(); ?>
</form>
