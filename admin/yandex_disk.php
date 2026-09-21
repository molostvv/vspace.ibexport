<?php

require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_admin_before.php';

use Bitrix\Main\Loader;
use Vspace\Ibexport\Admin\AdminMessages;
use Vspace\Ibexport\Options;
use Vspace\Ibexport\YandexDisk\Client;
use Vspace\Ibexport\YandexDisk\Exception;
use Vspace\Ibexport\YandexDisk\Settings;

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
$APPLICATION->SetAdditionalCSS('/local/modules/vspace.ibexport/admin/css/vibx.css');

$errors = [];
$notice = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && check_bitrix_sessid()) {
    $action = (string)($_REQUEST['ACTION'] ?? '');
    try {
        if ($action === 'disconnect') {
            Settings::clearToken();
            $notice = GetMessage('IBYADISK_DISCONNECTED');
        } else { // ACTION=save
            $folder = trim((string)($_REQUEST['FOLDER'] ?? ''));
            Settings::setFolder($folder !== '' ? $folder : '/vspace.ibexport');

            $token = trim((string)($_REQUEST['TOKEN'] ?? ''));
            if ($token !== '') {
                Settings::setToken($token);
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
    AdminMessages::showErrors($errors);
} elseif ($notice !== '') {
    AdminMessages::showNotice($notice);
}

// Статус подключения — синхронная проверка при каждом открытии страницы
// (ТЗ, раздел 6: все операции синхронные, по явному действию администратора,
// открытие страницы настроек — такое же явное действие).
$statusHtml = '';
if (Settings::hasToken()) {
    try {
        $client = new Client(Settings::getToken());
        $status = $client->checkConnection();
        $freeGb = round($status['free_space'] / 1073741824, 1);
        $totalGb = round($status['total_space'] / 1073741824, 1);
        $statusHtml = AdminMessages::ok(GetMessage('IBYADISK_STATUS_CONNECTED', [
            '#LOGIN#' => $status['user_login'],
            '#FREE#' => $freeGb,
            '#TOTAL#' => $totalGb,
        ]));
    } catch (Exception $e) {
        $statusHtml = AdminMessages::error(GetMessage('IBYADISK_STATUS_ERROR', ['#MESSAGE#' => $e->getMessage()]));
    }
} else {
    $statusHtml = '<div class="vibx-note">' . htmlspecialcharsbx(GetMessage('IBYADISK_STATUS_NOT_CONNECTED')) . '</div>';
}

$tabControl = new CAdminTabControl('tabControl', [
    ['DIV' => 'edit1', 'TAB' => GetMessage('IBYADISK_FORM_HEADING'), 'TITLE' => GetMessage('IBYADISK_FORM_HEADING')],
]);
?>

<?= $statusHtml ?>

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
                   placeholder="<?= Settings::hasToken() ? htmlspecialcharsbx(GetMessage('IBYADISK_TOKEN_SAVED_PLACEHOLDER')) : '' ?>">
            <div style="color:#888;font-size:11px;"><?= GetMessage('IBYADISK_FIELD_TOKEN_HINT') ?></div>
        </td>
    </tr>
    <tr>
        <td><?= GetMessage('IBYADISK_FIELD_FOLDER') ?></td>
        <td><input type="text" size="40" name="FOLDER" value="<?= htmlspecialcharsbx(Settings::getFolder()) ?>"></td>
    </tr>

    <?php $tabControl->Buttons(); ?>
    <input type="submit" class="adm-btn-save" value="<?= GetMessage('IBYADISK_BTN_SAVE') ?>">
    <?php $tabControl->End(); ?>
</form>

<?php if (Settings::hasToken()): ?>
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
