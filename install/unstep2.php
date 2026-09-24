<?php
/** @var CMain $APPLICATION */

if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true) {
    die();
}

if ($ex = $APPLICATION->GetException()) {
    CAdminMessage::ShowMessage([
        'TYPE' => 'ERROR',
        'MESSAGE' => GetMessage('MOD_UNINST_ERR'),
        'DETAILS' => $ex->GetString(),
        'HTML' => true,
    ]);
} else {
    CAdminMessage::ShowNote(GetMessage('MOD_UNINST_OK'));
}
?>
<form action="<?= $APPLICATION->GetCurPage() ?>">
    <input type="hidden" name="lang" value="<?= LANGUAGE_ID ?>">
    <input type="submit" value="<?= GetMessage('MOD_BACK') ?>">
</form>
