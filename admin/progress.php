<?php

require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_admin_before.php';

use Bitrix\Main\Loader;
use Vspace\Ibexport\Admin\AdminMessages;
use Vspace\Ibexport\Exporter;
use Vspace\Ibexport\JobTable;
use Vspace\Ibexport\Options;
use Vspace\Ibexport\YandexDisk\JobUploadTable;
use Vspace\Ibexport\YandexDisk\Settings;

Loader::includeModule('vspace.ibexport');
Loader::includeModule('iblock');

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

// Тот же расчёт процента, что и в AJAX-ответе (TickRunner::toProgress()) —
// чтобы при открытии/обновлении страницы уже завершённого или частично
// выполненного задания полоса сразу показывала верное значение, не дожидаясь
// первого AJAX-опроса.
$initialProgress = JobTable::calculateProgress($job);

// Если страница открыта повторно для уже завершённого/упавшего задания —
// сразу отрисовываем итоговые блоки той же разметкой, что и JS в
// progress.js, вместо пустого места до первого AJAX-опроса. Сообщение и
// кнопка скачивания — два независимых плейсхолдера: кнопка уходит в штатную
// область кнопок самой панели (см. $tabControl->Buttons() ниже), а не в
// отдельный блок под ней.
$messageHtml = '';
$downloadUrl = '/bitrix/admin/vspace_ibexport_download.php?lang=' . LANGUAGE_ID
    . '&JOB_ID=' . $jobId . '&sessid=' . bitrix_sessid();
// Кнопка скачивания есть в панели кнопок всегда, но до завершения выгрузки скрыта (visibility — место в панели
// сохраняется): с пустой панелью ядро (core_admin_interface.js) всё равно ставит в неё булавку "закрепить панель",
// и та без кнопок вываливается под форму. progress.js заменяет содержимое и показывает его по завершении.
$downloadButtonHtml = '<a class="adm-btn adm-btn-save" href="' . htmlspecialcharsbx($downloadUrl) . '">' . htmlspecialcharsbx(GetMessage('IBEXPORT_BTN_DOWNLOAD')) . '</a>';
if ($job['STATUS'] === JobTable::STATUS_DONE) {
    // Выгрузка архива на Яндекс.Диск (сразу после экспорта или кнопкой ниже) — в том же сообщении; ошибка — отдельным
    // красным блоком. Та же разметка, что дорисовывает progress.js по завершении через AJAX-опрос.
    $diskUpload = JobUploadTable::getByJob($jobId);
    $doneText = GetMessage('IBEXPORT_PROGRESS_DONE');
    if ($diskUpload && $diskUpload['status'] === JobUploadTable::STATUS_DONE) {
        $doneText .= ' ' . GetMessage('IBYADISK_AUTO_DONE', ['#PATH#' => $diskUpload['disk_path']]);
    } elseif ($diskUpload && $diskUpload['status'] === JobUploadTable::STATUS_RUNNING) {
        $doneText .= ' ' . GetMessage('IBYADISK_AUTO_RUNNING');
    }
    $messageHtml = AdminMessages::ok($doneText);
    if ($diskUpload && $diskUpload['status'] === JobUploadTable::STATUS_ERROR) {
        $messageHtml .= AdminMessages::error(GetMessage('IBYADISK_UPLOAD_ERROR', ['#MESSAGE#' => $diskUpload['message']]) . ' ' . GetMessage('IBYADISK_UPLOAD_RETRY'));
    }

    // Выгрузка в Яндекс.Диск — отдельная ручная кнопка рядом со "Скачать архив" (ТЗ "Экспорт в Яндекс.Диск",
    // раздел 3): недоступность Диска не должна ронять/блокировать обычное скачивание, поэтому это отдельная форма
    // с собственным action, а не часть текущей страницы. Только пока архива на Диске нет — выгрузка сразу после
    // экспорта выключена, не удалась или ещё идёт; уже выгруженный архив выгружать повторно незачем.
    if (Options::isYandexDiskEnabled() && Settings::hasToken()
        && !($diskUpload && $diskUpload['status'] === JobUploadTable::STATUS_DONE)) {
        $downloadButtonHtml .= ' <form method="post" action="/bitrix/admin/vspace_ibexport_yandex_disk_upload.php" style="display:inline;">'
            . bitrix_sessid_post()
            . '<input type="hidden" name="lang" value="' . LANGUAGE_ID . '">'
            . '<input type="hidden" name="JOB_ID" value="' . (int)$jobId . '">'
            . '<input type="submit" class="adm-btn" value="' . htmlspecialcharsbx(GetMessage('IBYADISK_BTN_UPLOAD')) . '">'
            . '</form>';
    }
} elseif ($job['STATUS'] === JobTable::STATUS_ERROR) {
    $messageHtml = AdminMessages::error(GetMessage('IBEXPORT_PROGRESS_ERROR') . ': ' . (string)$job['ERROR_MESSAGE']);
}

// Кнопка возврата к форме экспорта — выгрузить следующие элементы/разделы того же инфоблока: тот же тип сущности
// (и режим для раздела), со списками последних изменённых; галки — по умолчанию из настроек модуля, как при обычном
// открытии формы. Видна по завершении задания (и с ошибкой), до того — скрыта, как и "Скачать архив".
$iblockId = (int)$job['IBLOCK_ID'];
$iblock = \Bitrix\Iblock\IblockTable::getList(['filter' => ['=ID' => $iblockId], 'select' => ['NAME']])->fetch();
$backParams = ['lang' => LANGUAGE_ID, 'IBLOCK_ID' => $iblockId, 'ENTITY_TYPE' => $job['ENTITY_TYPE']];
if ($job['ENTITY_TYPE'] === 'section') {
    $backParams['MODE'] = $job['MODE'];
}
$backUrl = '/bitrix/admin/vspace_ibexport_export.php?' . http_build_query($backParams);
$backTitle = GetMessage('IBEXPORT_BTN_BACK', ['#IBLOCK#' => '[' . $iblockId . ']' . ($iblock ? ' ' . $iblock['NAME'] : '')]);
$jobFinished = in_array($job['STATUS'], [JobTable::STATUS_DONE, JobTable::STATUS_ERROR], true);
// Пока задание идёт, кнопки скрыты visibility (место в панели остаётся — см. выше про булавку); у упавшего задания
// скачивать нечего — блок скачивания убран совсем, чтобы кнопка возврата не стояла после пустого места.
$downloadStyle = $job['STATUS'] === JobTable::STATUS_DONE ? '' : ($job['STATUS'] === JobTable::STATUS_ERROR ? ' display: none;' : ' visibility: hidden;');

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
        // ручного обновления страницы уже завершённого задания. Архив уже
        // на Диске (data.disk_upload.status = DONE) — кнопки нет.
        yandexDiskEnabled: <?= (Options::isYandexDiskEnabled() && Settings::hasToken()) ? 'true' : 'false' ?>,
        messages: {
            running: <?= \CUtil::PhpToJSObject(GetMessage('IBEXPORT_PROGRESS_RUNNING')) ?>,
            done: <?= \CUtil::PhpToJSObject(GetMessage('IBEXPORT_PROGRESS_DONE')) ?>,
            error: <?= \CUtil::PhpToJSObject(GetMessage('IBEXPORT_PROGRESS_ERROR')) ?>,
            download: <?= \CUtil::PhpToJSObject(GetMessage('IBEXPORT_BTN_DOWNLOAD')) ?>,
            yandexUpload: <?= \CUtil::PhpToJSObject(GetMessage('IBYADISK_BTN_UPLOAD')) ?>,
            diskDone: <?= \CUtil::PhpToJSObject(GetMessage('IBYADISK_AUTO_DONE')) ?>,
            diskRunning: <?= \CUtil::PhpToJSObject(GetMessage('IBYADISK_AUTO_RUNNING')) ?>,
            diskError: <?= \CUtil::PhpToJSObject(GetMessage('IBYADISK_UPLOAD_ERROR') . ' ' . GetMessage('IBYADISK_UPLOAD_RETRY')) ?>,
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
    <div id="vibx-download" style="display: inline-block;<?= $downloadStyle ?>"><?= $downloadButtonHtml ?></div>
    <div id="vibx-finish-buttons" style="display: inline-block;<?= $jobFinished ? '' : ' visibility: hidden;' ?>">
        <a class="adm-btn" href="<?= htmlspecialcharsbx($backUrl) ?>"><?= htmlspecialcharsbx($backTitle) ?></a>
    </div>
    <?php $tabControl->End(); ?>
</div>
<div id="vibx-result"><?= $messageHtml ?></div>

<?php
// Результат отдельного действия "Выгрузить в Яндекс.Диск"
// (yandex_disk_upload.php перенаправляет сюда с этим флагом) — свой,
// независимый от основного messageHtml блок, чтобы не путать со статусом
// самого экспорта. Если состояние выгрузки запоминается (JobUploadTable), оно уже показано в итоге выше.
$diskUploadStatus = JobUploadTable::isAvailable() ? '' : (string)($_REQUEST['DISK_UPLOAD'] ?? '');
if ($diskUploadStatus === 'ok'): ?>
    <?= AdminMessages::ok(GetMessage('IBYADISK_UPLOAD_OK')) ?>
<?php elseif ($diskUploadStatus === 'error'): ?>
    <?= AdminMessages::error(GetMessage('IBYADISK_UPLOAD_ERROR', ['#MESSAGE#' => (string)($_REQUEST['DISK_ERROR'] ?? '')])) ?>
<?php endif; ?>

<?php
require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/epilog_admin.php';
