<?php
/** @var CMain $APPLICATION */

use Bitrix\Main\Localization\Loc;

if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true) {
    die();
}

Loc::loadMessages(__FILE__);
?>
<form action="<?= $APPLICATION->GetCurPage() ?>">
    <?= bitrix_sessid_post() ?>
    <input type="hidden" name="lang" value="<?= LANGUAGE_ID ?>">
    <input type="hidden" name="id" value="vspace.ibexport">
    <input type="hidden" name="uninstall" value="Y">
    <input type="hidden" name="step" value="2">
    <?php CAdminMessage::ShowMessage(GetMessage('MOD_UNINST_WARN')); ?>
    <p><?= GetMessage('MOD_UNINST_SAVE') ?></p>
    <p>
        <input type="checkbox" name="savedata" id="savedata" value="Y" checked>
        <label for="savedata"><?= GetMessage('MOD_UNINST_SAVE_TABLES') ?></label>
    </p>
    <p><?= Loc::getMessage('IBEXPORT_UNINSTALL_SAVE_NOTE') ?></p>
    <input type="submit" name="inst" value="<?= GetMessage('MOD_UNINST_DEL') ?>">
</form>
