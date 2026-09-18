<?php

require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_admin_before.php';

use Bitrix\Main\Loader;
use Vspace\Ibexport\ImportJobTable;
use Vspace\Ibexport\Importer;

Loader::includeModule('vspace.ibexport');

IncludeModuleLangFile(__FILE__);

global $USER, $APPLICATION;

$jobId = (int)($_REQUEST['JOB_ID'] ?? 0);
$job = $jobId ? ImportJobTable::getJobById($jobId) : null;

$isOwner = $job && ((int)$job['USER_ID'] === (int)$USER->GetID() || $USER->IsAdmin());

if (($_REQUEST['AJAX'] ?? '') === 'Y') {
    header('Content-Type: application/json; charset=UTF-8');
    if (!$job || !$isOwner) {
        echo json_encode(['status' => 'ERROR', 'error_message' => GetMessage('IBIMPORT_ERR_JOB_NOT_FOUND')]);
        die();
    }
    if (!check_bitrix_sessid()) {
        echo json_encode(['status' => 'ERROR', 'error_message' => GetMessage('IBIMPORT_ERR_SESSID')]);
        die();
    }

    echo json_encode(Importer::runStep($jobId));
    die();
}

$APPLICATION->SetTitle(GetMessage('IBIMPORT_PROGRESS_TITLE'));

require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_admin_after.php';

if (!$job || !$isOwner) {
    CAdminMessage::ShowMessage(['TYPE' => 'ERROR', 'MESSAGE' => GetMessage('IBIMPORT_ERR_JOB_NOT_FOUND')]);
    require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/epilog_admin.php';
    die();
}

$statusLabels = [
    ImportJobTable::STATUS_NEW => GetMessage('IBIMPORT_STATUS_NEW'),
    ImportJobTable::STATUS_RUNNING => GetMessage('IBIMPORT_STATUS_RUNNING'),
    ImportJobTable::STATUS_DONE => GetMessage('IBIMPORT_STATUS_DONE'),
    ImportJobTable::STATUS_ERROR => GetMessage('IBIMPORT_STATUS_ERROR'),
];

// Тот же расчёт процента, что и в Importer::toProgress() — см. progress.php.
$totalNodes = max(1, (int)$job['TOTAL_SECTIONS'] + (int)$job['TOTAL_ELEMENTS']);
$doneNodes = (int)$job['PROCESSED_SECTIONS'] + (int)$job['PROCESSED_ELEMENTS'];
$initialProgress = $job['STATUS'] === ImportJobTable::STATUS_DONE ? 100 : (int)min(99, round(100 * $doneNodes / $totalNodes));

// Пре-рендер итогового блока для уже завершённого/упавшего задания — та же
// разметка, что и в progress.js (см. также admin/progress.php).
$messageHtml = '';
if ($job['STATUS'] === ImportJobTable::STATUS_DONE) {
    $doneText = htmlspecialcharsbx(GetMessage('IBIMPORT_PROGRESS_DONE')) . ' ' . htmlspecialcharsbx(GetMessage('IBIMPORT_SUMMARY', [
        '#CREATED#' => (int)$job['CREATED_COUNT'],
        '#UPDATED#' => (int)$job['UPDATED_COUNT'],
        '#SKIPPED#' => (int)$job['SKIPPED_COUNT'],
    ]));
    $messageHtml = '<div class="adm-info-message-wrap adm-info-message-green"><div class="adm-info-message">'
        . '<div class="adm-info-message-title">' . $doneText . '</div>'
        . '<div class="adm-info-message-icon"></div>'
        . '</div></div>';
} elseif ($job['STATUS'] === ImportJobTable::STATUS_ERROR) {
    $messageHtml = '<div class="adm-info-message-wrap adm-info-message-red"><div class="adm-info-message">'
        . '<div class="adm-info-message-title">' . htmlspecialcharsbx(GetMessage('IBIMPORT_PROGRESS_ERROR')) . ': ' . htmlspecialcharsbx((string)$job['ERROR_MESSAGE']) . '</div>'
        . '<div class="adm-info-message-icon"></div>'
        . '</div></div>';
}

$APPLICATION->AddHeadScript('/local/modules/vspace.ibexport/admin/js/progress.js');
?>
<script>
    window.vspaceIbexportProgress = {
        jobId: <?= (int)$jobId ?>,
        sessid: '<?= bitrix_sessid() ?>',
        pollUrl: '/bitrix/admin/vspace_ibexport_import_progress.php?lang=<?= LANGUAGE_ID ?>',
        messages: {
            running: <?= \CUtil::PhpToJSObject(GetMessage('IBIMPORT_PROGRESS_RUNNING')) ?>,
            done: <?= \CUtil::PhpToJSObject(GetMessage('IBIMPORT_PROGRESS_DONE')) ?>,
            error: <?= \CUtil::PhpToJSObject(GetMessage('IBIMPORT_PROGRESS_ERROR')) ?>,
            summary: <?= \CUtil::PhpToJSObject(GetMessage('IBIMPORT_SUMMARY')) ?>,
            statusLabels: <?= \CUtil::PhpToJSObject($statusLabels) ?>
        }
    };
</script>

<div id="vspace-ibexport-progress">
    <?php
    $tabControl = new CAdminTabControl('tabControl', [
        ['DIV' => 'edit1', 'TAB' => GetMessage('IBIMPORT_PROGRESS_TAB'), 'TITLE' => GetMessage('IBIMPORT_PROGRESS_TAB')],
    ]);
    $tabControl->Begin();
    $tabControl->BeginNextTab();
    ?>
    <tr>
        <td width="40%"><?= GetMessage('IBIMPORT_PROGRESS_STATUS') ?></td>
        <td id="vibx-status"><?= htmlspecialcharsbx($statusLabels[$job['STATUS']] ?? $job['STATUS']) ?></td>
    </tr>
    <tr>
        <td><?= GetMessage('IBIMPORT_PROGRESS_BAR') ?></td>
        <td>
            <div id="vibx-bar-outer" class="adm-progress-bar-outer" style="width: 100%; max-width: 400px; box-sizing: border-box;">
                <div id="vibx-bar-inner" class="adm-progress-bar-inner" style="width: <?= $initialProgress ?>%;">
                    <div id="vibx-bar-inner-text-wrap" class="adm-progress-bar-inner-text"><span id="vibx-bar-text-inner"><?= $initialProgress ?>%</span></div>
                </div>
                <span id="vibx-bar-text-outer"><?= $initialProgress ?>%</span>
            </div>
        </td>
    </tr>
    <tr>
        <td><?= GetMessage('IBIMPORT_PROGRESS_SECTIONS') ?></td>
        <td id="vibx-sections"><?= (int)$job['PROCESSED_SECTIONS'] ?> / <?= (int)$job['TOTAL_SECTIONS'] ?></td>
    </tr>
    <tr>
        <td><?= GetMessage('IBIMPORT_PROGRESS_ELEMENTS') ?></td>
        <td id="vibx-elements"><?= (int)$job['PROCESSED_ELEMENTS'] ?> / <?= (int)$job['TOTAL_ELEMENTS'] ?></td>
    </tr>
    <?php $tabControl->Buttons(); ?>
    <div id="vibx-download"></div>
    <?php $tabControl->End(); ?>
</div>
<div id="vibx-result"><?= $messageHtml ?></div>

<?php
require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/epilog_admin.php';
