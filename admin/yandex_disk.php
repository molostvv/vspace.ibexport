<?php

require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_admin_before.php';

use Bitrix\Main\Loader;
use Vspace\Ibexport\Options;
use Vspace\Ibexport\YandexDiskClient;
use Vspace\Ibexport\YandexDiskException;

Loader::includeModule('vspace.ibexport');

IncludeModuleLangFile(__FILE__);

global $USER, $APPLICATION;

// Токен — общий секрет для канала тест↔прод, поэтому страница подключения
// доступна только администратору (жёстче, чем права на сам экспорт/импорт),
// как и генеральные настройки модуля (options.php).
if (!$USER->IsAdmin()) {
    $APPLICATION->AuthForm(GetMessage('ACCESS_DENIED'));
    die();
}

$APPLICATION->SetTitle(GetMessage('IBYADISK_TITLE'));

$errors = [];
$notice = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && check_bitrix_sessid()) {
    $action = (string)($_REQUEST['ACTION'] ?? '');
    try {
        if ($action === 'disconnect') {
            Options::clearYandexDiskToken();
            $notice = GetMessage('IBYADISK_DISCONNECTED');
        } else { // ACTION=save
            $folder = trim((string)($_REQUEST['FOLDER'] ?? ''));
            Options::setYandexDiskFolder($folder !== '' ? $folder : '/vspace.ibexport');

            $token = trim((string)($_REQUEST['TOKEN'] ?? ''));
            if ($token !== '') {
                Options::setYandexDiskToken($token);
            }

            Options::setYandexDiskEnabled(($_REQUEST['ENABLED'] ?? '') === 'Y');
            $notice = GetMessage('IBYADISK_SAVED');
        }
    } catch (\Throwable $e) {
        $errors[] = $e->getMessage();
    }
}

require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_admin_after.php';

if ($errors) {
    CAdminMessage::ShowMessage(['TYPE' => 'ERROR', 'MESSAGE' => implode('<br>', array_map('htmlspecialcharsbx', $errors))]);
} elseif ($notice !== '') {
    CAdminMessage::ShowMessage(['TYPE' => 'OK', 'MESSAGE' => htmlspecialcharsbx($notice)]);
}

// Статус подключения — синхронная проверка при каждом открытии страницы
// (ТЗ, раздел 6: все операции синхронные, по явному действию администратора,
// открытие страницы настроек — такое же явное действие).
$statusHtml = '';
if (Options::hasYandexDiskToken()) {
    try {
        $client = new YandexDiskClient(Options::getYandexDiskToken());
        $status = $client->checkConnection();
        $freeGb = round($status['free_space'] / 1073741824, 1);
        $totalGb = round($status['total_space'] / 1073741824, 1);
        $statusHtml = '<div class="adm-info-message-wrap adm-info-message-green"><div class="adm-info-message">'
            . '<div class="adm-info-message-title">' . htmlspecialcharsbx(GetMessage('IBYADISK_STATUS_CONNECTED', [
                '#LOGIN#' => $status['user_login'],
                '#FREE#' => $freeGb,
                '#TOTAL#' => $totalGb,
            ])) . '</div>'
            . '<div class="adm-info-message-icon"></div>'
            . '</div></div>';
    } catch (YandexDiskException $e) {
        $statusHtml = '<div class="adm-info-message-wrap adm-info-message-red"><div class="adm-info-message">'
            . '<div class="adm-info-message-title">' . htmlspecialcharsbx(GetMessage('IBYADISK_STATUS_ERROR', ['#MESSAGE#' => $e->getMessage()])) . '</div>'
            . '<div class="adm-info-message-icon"></div>'
            . '</div></div>';
    }
} else {
    $statusHtml = '<div class="vibx-note">' . htmlspecialcharsbx(GetMessage('IBYADISK_STATUS_NOT_CONNECTED')) . '</div>';
}

$tabControl = new CAdminTabControl('tabControl', [
    ['DIV' => 'edit1', 'TAB' => GetMessage('IBYADISK_FORM_HEADING'), 'TITLE' => GetMessage('IBYADISK_FORM_HEADING')],
]);
?>

<?= $statusHtml ?>
<style>
    .vibx-note {
        display: flex;
        align-items: center;
        box-sizing: border-box;
        margin-top: 15px;
        padding: 12px 16px;
        background: #f5f6f7;
        border: 1px solid #d5dbe0;
        border-radius: 3px;
        color: #2b3446;
        font-size: 13px;
        line-height: 1.4;
    }
</style>

<form method="post" action="<?= htmlspecialcharsbx($APPLICATION->GetCurPage()) ?>" name="vibx_yandex_disk_form">
    <?php $tabControl->Begin(); ?>
    <?= bitrix_sessid_post() ?>
    <input type="hidden" name="lang" value="<?= LANGUAGE_ID ?>">
    <input type="hidden" name="ACTION" value="save">
    <?php $tabControl->BeginNextTab(); ?>

    <tr>
        <td width="40%"><?= GetMessage('IBYADISK_FIELD_ENABLED') ?></td>
        <td><input type="checkbox" name="ENABLED" value="Y" <?= Options::isYandexDiskEnabled() ? 'checked' : '' ?>></td>
    </tr>
    <tr>
        <td><?= GetMessage('IBYADISK_FIELD_TOKEN') ?></td>
        <td>
            <input type="password" size="50" name="TOKEN" value="" autocomplete="off"
                   placeholder="<?= Options::hasYandexDiskToken() ? htmlspecialcharsbx(GetMessage('IBYADISK_TOKEN_SAVED_PLACEHOLDER')) : '' ?>">
            <div style="color:#888;font-size:11px;"><?= GetMessage('IBYADISK_FIELD_TOKEN_HINT') ?></div>
        </td>
    </tr>
    <tr>
        <td><?= GetMessage('IBYADISK_FIELD_FOLDER') ?></td>
        <td><input type="text" size="40" name="FOLDER" value="<?= htmlspecialcharsbx(Options::getYandexDiskFolder()) ?>"></td>
    </tr>

    <?php $tabControl->Buttons(); ?>
    <input type="submit" class="adm-btn-save" value="<?= GetMessage('IBYADISK_BTN_SAVE') ?>">
    <?php $tabControl->End(); ?>
</form>

<?php if (Options::hasYandexDiskToken()): ?>
    <form method="post" action="<?= htmlspecialcharsbx($APPLICATION->GetCurPage()) ?>" name="vibx_yandex_disk_disconnect" style="margin-top: 10px;">
        <?= bitrix_sessid_post() ?>
        <input type="hidden" name="lang" value="<?= LANGUAGE_ID ?>">
        <input type="hidden" name="ACTION" value="disconnect">
        <input type="submit" class="adm-btn" value="<?= GetMessage('IBYADISK_BTN_DISCONNECT') ?>"
               onclick="return confirm('<?= \CUtil::JSEscape(GetMessage('IBYADISK_DISCONNECT_CONFIRM')) ?>');">
    </form>
<?php endif; ?>

<?php
require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/epilog_admin.php';
