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
        } elseif ($action === 'clear_folder') {
            // Файлы удаляются по одному (запрос к API на каждый) — на большой папке это дольше обычного запроса страницы.
            @set_time_limit(300);
            $folder = Settings::getFolder();
            $result = (new Client(Settings::getToken()))->clearFolder($folder);
            $notice = GetMessage('IBYADISK_CLEARED', ['#FOLDER#' => $folder, '#COUNT#' => $result['deleted']]);
            foreach ($result['failed'] as $name => $message) {
                $errors[] = GetMessage('IBYADISK_CLEAR_FAILED_FILE', ['#NAME#' => $name, '#MESSAGE#' => $message]);
            }
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

// Очистка папки может закончиться частично: сколько удалено — и какие файлы удалить не удалось.
AdminMessages::showErrors($errors);
if ($notice !== '') {
    AdminMessages::showNotice($notice);
}

// Статус подключения — синхронная проверка при каждом открытии страницы
// (ТЗ, раздел 6: все операции синхронные, по явному действию администратора,
// открытие страницы настроек — такое же явное действие).
$statusHtml = '';
$folderFileCount = null; // null — неизвестно (Диск не подключён или недоступен)
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
        // Сколько файлов в папке обмена — для кнопки очистки (listFiles() отдаёт до 200 файлов).
        $folderFileCount = count($client->listFiles(Settings::getFolder()));
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
    <div class="vibx-actions">
        <?php $folder = Settings::getFolder(); ?>
        <?php if ($folderFileCount === 0): ?>
            <?php // Пустую папку очищать нечего — вместо кнопки говорим об этом прямо, чтобы её отсутствие не озадачивало ?>
            <span class="vibx-actions-note"><?= htmlspecialcharsbx(GetMessage('IBYADISK_FOLDER_EMPTY', ['#FOLDER#' => $folder])) ?></span>
        <?php else: ?>
            <?php
            // Очистка папки обмена: файлы — в Корзину Диска (Client::clearFolder()). Папка общая для всех сайтов,
            // подключённых к этому Диску, поэтому — с подтверждением.
            $clearTitle = GetMessage('IBYADISK_BTN_CLEAR', ['#FOLDER#' => $folder])
                . ($folderFileCount !== null ? ' ' . GetMessage('IBYADISK_BTN_CLEAR_COUNT', ['#COUNT#' => $folderFileCount >= 200 ? '200+' : $folderFileCount]) : '');
            ?>
            <form method="post" action="<?= htmlspecialcharsbx($APPLICATION->GetCurPage()) ?>" name="vibx_yandex_disk_clear">
                <?= bitrix_sessid_post() ?>
                <input type="hidden" name="lang" value="<?= LANGUAGE_ID ?>">
                <input type="hidden" name="ACTION" value="clear_folder">
                <input type="submit" class="adm-btn" value="<?= htmlspecialcharsbx($clearTitle) ?>"
                       onclick="return confirm('<?= \CUtil::JSEscape(GetMessage('IBYADISK_CLEAR_CONFIRM', ['#FOLDER#' => $folder])) ?>');">
            </form>
        <?php endif; ?>
        <form method="post" action="<?= htmlspecialcharsbx($APPLICATION->GetCurPage()) ?>" name="vibx_yandex_disk_disconnect">
            <?= bitrix_sessid_post() ?>
            <input type="hidden" name="lang" value="<?= LANGUAGE_ID ?>">
            <input type="hidden" name="ACTION" value="disconnect">
            <input type="submit" class="adm-btn" value="<?= GetMessage('IBYADISK_BTN_DISCONNECT') ?>"
                   onclick="return confirm('<?= \CUtil::JSEscape(GetMessage('IBYADISK_DISCONNECT_CONFIRM')) ?>');">
        </form>
    </div>
<?php endif; ?>

<?php
require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/epilog_admin.php';
