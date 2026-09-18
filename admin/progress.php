<?php

require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_admin_before.php';

use Bitrix\Main\Loader;
use Vspace\Ibexport\Exporter;
use Vspace\Ibexport\JobTable;
use Vspace\Ibexport\Options;
use Vspace\Ibexport\YandexDisk\Settings;

Loader::includeModule('vspace.ibexport');

IncludeModuleLangFile(__FILE__);

global $USER, $APPLICATION;

$jobId = (int)($_REQUEST['JOB_ID'] ?? 0);
$job = $jobId ? JobTable::getJobById($jobId) : null;

$isOwner = $job && ((int)$job['USER_ID'] === (int)$USER->GetID() || $USER->IsAdmin());

if (($_REQUEST['AJAX'] ?? '') === 'Y') {
    header('Content-Type: application/json; charset=UTF-8');
    if (!$job || !$isOwner) {
        echo json_encode(['status' => 'ERROR', 'error_message' => GetMessage('IBEXPORT_ERR_JOB_NOT_FOUND')]);
        die();
    }
    if (!check_bitrix_sessid()) {
        echo json_encode(['status' => 'ERROR', 'error_message' => GetMessage('IBEXPORT_ERR_SESSID')]);
        die();
    }

    $progress = Exporter::runStep($jobId);
    if ($progress['status'] === JobTable::STATUS_DONE) {
        $progress['download_url'] = '/bitrix/admin/vspace_ibexport_download.php?lang=' . LANGUAGE_ID
            . '&JOB_ID=' . $jobId . '&sessid=' . bitrix_sessid();
    }
    echo json_encode($progress);
    die();
}

$APPLICATION->SetTitle(GetMessage('IBEXPORT_PROGRESS_TITLE'));

require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_admin_after.php';

if (!$job || !$isOwner) {
    CAdminMessage::ShowMessage(['TYPE' => 'ERROR', 'MESSAGE' => GetMessage('IBEXPORT_ERR_JOB_NOT_FOUND')]);
    require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/epilog_admin.php';
    die();
}

$statusLabels = [
    JobTable::STATUS_NEW => GetMessage('IBEXPORT_STATUS_NEW'),
    JobTable::STATUS_RUNNING => GetMessage('IBEXPORT_STATUS_RUNNING'),
    JobTable::STATUS_DONE => GetMessage('IBEXPORT_STATUS_DONE'),
    JobTable::STATUS_ERROR => GetMessage('IBEXPORT_STATUS_ERROR'),
];

// Тот же расчёт процента, что и в Exporter::toProgress() — чтобы при
// открытии/обновлении страницы уже завершённого или частично выполненного
// задания полоса сразу показывала верное значение, не дожидаясь первого
// AJAX-опроса.
$totalNodes = max(1, (int)$job['TOTAL_SECTIONS'] + (int)$job['TOTAL_ELEMENTS']);
$doneNodes = (int)$job['PROCESSED_SECTIONS'] + (int)$job['PROCESSED_ELEMENTS'];
$initialProgress = $job['STATUS'] === JobTable::STATUS_DONE ? 100 : (int)min(99, round(100 * $doneNodes / $totalNodes));

// Если страница открыта повторно для уже завершённого/упавшего задания —
// сразу отрисовываем итоговые блоки той же разметкой, что и JS в
// progress.js, вместо пустого места до первого AJAX-опроса. Сообщение и
// кнопка скачивания — два независимых плейсхолдера: кнопка уходит в штатную
// область кнопок самой панели (см. $tabControl->Buttons() ниже), а не в
// отдельный блок под ней.
$messageHtml = '';
$downloadButtonHtml = '';
if ($job['STATUS'] === JobTable::STATUS_DONE) {
    $downloadUrl = '/bitrix/admin/vspace_ibexport_download.php?lang=' . LANGUAGE_ID
        . '&JOB_ID=' . $jobId . '&sessid=' . bitrix_sessid();
    $messageHtml = '<div class="adm-info-message-wrap adm-info-message-green"><div class="adm-info-message">'
        . '<div class="adm-info-message-title">' . htmlspecialcharsbx(GetMessage('IBEXPORT_PROGRESS_DONE')) . '</div>'
        . '<div class="adm-info-message-icon"></div>'
        . '</div></div>';
    $downloadButtonHtml = '<a class="adm-btn adm-btn-save" href="' . htmlspecialcharsbx($downloadUrl) . '">' . htmlspecialcharsbx(GetMessage('IBEXPORT_BTN_DOWNLOAD')) . '</a>';

    // Выгрузка в Яндекс.Диск — отдельная, полностью ручная кнопка рядом со
    // "Скачать архив" (ТЗ "Экспорт в Яндекс.Диск", раздел 3): недоступность
    // Диска не должна ронять/блокировать обычное скачивание, поэтому это
    // отдельная форма с собственным action, а не часть текущей страницы.
    if (Options::isYandexDiskEnabled() && Settings::hasToken()) {
        $downloadButtonHtml .= ' <form method="post" action="/bitrix/admin/vspace_ibexport_yandex_disk_upload.php" style="display:inline;">'
            . bitrix_sessid_post()
            . '<input type="hidden" name="lang" value="' . LANGUAGE_ID . '">'
            . '<input type="hidden" name="JOB_ID" value="' . (int)$jobId . '">'
            . '<input type="submit" class="adm-btn" value="' . htmlspecialcharsbx(GetMessage('IBYADISK_BTN_UPLOAD')) . '">'
            . '</form>';
    }
} elseif ($job['STATUS'] === JobTable::STATUS_ERROR) {
    $messageHtml = '<div class="adm-info-message-wrap adm-info-message-red"><div class="adm-info-message">'
        . '<div class="adm-info-message-title">' . htmlspecialcharsbx(GetMessage('IBEXPORT_PROGRESS_ERROR')) . ': ' . htmlspecialcharsbx((string)$job['ERROR_MESSAGE']) . '</div>'
        . '<div class="adm-info-message-icon"></div>'
        . '</div></div>';
}

$APPLICATION->AddHeadScript('/local/modules/vspace.ibexport/admin/js/progress.js');
?>
<script>
    window.vspaceIbexportProgress = {
        jobId: <?= (int)$jobId ?>,
        sessid: '<?= bitrix_sessid() ?>',
        lang: '<?= LANGUAGE_ID ?>',
        pollUrl: '/bitrix/admin/vspace_ibexport_progress.php?lang=<?= LANGUAGE_ID ?>',
        // Нужно JS, чтобы дорисовать кнопку "Выгрузить в Яндекс.Диск" и
        // при завершении экспорта через AJAX-опрос (без перезагрузки
        // страницы) — см. progress.js, иначе кнопка появится только после
        // ручного обновления страницы уже завершённого задания.
        yandexDiskEnabled: <?= (Options::isYandexDiskEnabled() && Settings::hasToken()) ? 'true' : 'false' ?>,
        messages: {
            running: <?= \CUtil::PhpToJSObject(GetMessage('IBEXPORT_PROGRESS_RUNNING')) ?>,
            done: <?= \CUtil::PhpToJSObject(GetMessage('IBEXPORT_PROGRESS_DONE')) ?>,
            error: <?= \CUtil::PhpToJSObject(GetMessage('IBEXPORT_PROGRESS_ERROR')) ?>,
            download: <?= \CUtil::PhpToJSObject(GetMessage('IBEXPORT_BTN_DOWNLOAD')) ?>,
            yandexUpload: <?= \CUtil::PhpToJSObject(GetMessage('IBYADISK_BTN_UPLOAD')) ?>,
            statusLabels: <?= \CUtil::PhpToJSObject($statusLabels) ?>
        }
    };
</script>

<div id="vspace-ibexport-progress">
    <?php
    // Стандартная детальная форма админки Bitrix — та же "плашка" с рамкой и
    // заголовком, что и на странице "Новая выгрузка", вместо голой <table>.
    $tabControl = new CAdminTabControl('tabControl', [
        ['DIV' => 'edit1', 'TAB' => GetMessage('IBEXPORT_PROGRESS_TAB'), 'TITLE' => GetMessage('IBEXPORT_PROGRESS_TAB')],
    ]);
    $tabControl->Begin();
    $tabControl->BeginNextTab();
    ?>
    <tr>
        <td width="40%"><?= GetMessage('IBEXPORT_PROGRESS_STATUS') ?></td>
        <td id="vibx-status"><?= htmlspecialcharsbx($statusLabels[$job['STATUS']] ?? $job['STATUS']) ?></td>
    </tr>
    <tr>
        <td><?= GetMessage('IBEXPORT_PROGRESS_BAR') ?></td>
        <td>
            <?php
            // Разметка и классы в точности повторяют нативный прогресс-бар
            // ядра (CAdminMessage::_getProgressHtml() в
            // bitrix/modules/main/interface/admin_lib.php) — тот же виджет,
            // что использует сам Bitrix для длительных операций в админке.
            ?>
            <div id="vibx-bar-outer" class="adm-progress-bar-outer" style="width: 100%; max-width: 400px; box-sizing: border-box;">
                <div id="vibx-bar-inner" class="adm-progress-bar-inner" style="width: <?= $initialProgress ?>%;">
                    <div id="vibx-bar-inner-text-wrap" class="adm-progress-bar-inner-text"><span id="vibx-bar-text-inner"><?= $initialProgress ?>%</span></div>
                </div>
                <span id="vibx-bar-text-outer"><?= $initialProgress ?>%</span>
            </div>
        </td>
    </tr>
    <tr>
        <td><?= GetMessage('IBEXPORT_PROGRESS_SECTIONS') ?></td>
        <td id="vibx-sections"><?= (int)$job['PROCESSED_SECTIONS'] ?> / <?= (int)$job['TOTAL_SECTIONS'] ?></td>
    </tr>
    <tr>
        <td><?= GetMessage('IBEXPORT_PROGRESS_ELEMENTS') ?></td>
        <td id="vibx-elements"><?= (int)$job['PROCESSED_ELEMENTS'] ?> / <?= (int)$job['TOTAL_ELEMENTS'] ?></td>
    </tr>
    <?php
    // Кнопка скачивания — в штатной области кнопок панели (та же
    // "adm-detail-content-btns", что и Save/Cancel на других формах), а не
    // в отдельном блоке под панелью с пустым местом-заглушкой.
    $tabControl->Buttons();
    ?>
    <div id="vibx-download"><?= $downloadButtonHtml ?></div>
    <?php $tabControl->End(); ?>
</div>
<div id="vibx-result"><?= $messageHtml ?></div>

<?php
// Результат отдельного действия "Выгрузить в Яндекс.Диск"
// (yandex_disk_upload.php перенаправляет сюда с этим флагом) — свой,
// независимый от основного messageHtml блок, чтобы не путать со статусом
// самого экспорта.
$diskUploadStatus = (string)($_REQUEST['DISK_UPLOAD'] ?? '');
if ($diskUploadStatus === 'ok'): ?>
    <div class="adm-info-message-wrap adm-info-message-green"><div class="adm-info-message">
        <div class="adm-info-message-title"><?= htmlspecialcharsbx(GetMessage('IBYADISK_UPLOAD_OK')) ?></div>
        <div class="adm-info-message-icon"></div>
    </div></div>
<?php elseif ($diskUploadStatus === 'error'): ?>
    <div class="adm-info-message-wrap adm-info-message-red"><div class="adm-info-message">
        <div class="adm-info-message-title"><?= htmlspecialcharsbx(GetMessage('IBYADISK_UPLOAD_ERROR', ['#MESSAGE#' => (string)($_REQUEST['DISK_ERROR'] ?? '')])) ?></div>
        <div class="adm-info-message-icon"></div>
    </div></div>
<?php endif; ?>

<?php
require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/epilog_admin.php';
