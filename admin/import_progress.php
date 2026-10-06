<?php

require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_admin_before.php';

use Bitrix\Main\Loader;
use Vspace\Ibexport\Admin\AdminMessages;
use Vspace\Ibexport\ImportJobTable;
use Vspace\Ibexport\Importer;
use Vspace\Ibexport\Options;
use Vspace\Ibexport\YandexDisk\Settings;

Loader::includeModule('vspace.ibexport');
Loader::includeModule('iblock');

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

// Тот же расчёт процента, что и в AJAX-ответе (TickRunner::toProgress()) — см. progress.php.
$initialProgress = ImportJobTable::calculateProgress($job);

// Пре-рендер итогового блока для уже завершённого/упавшего задания — та же
// разметка, что и в progress.js (см. также admin/progress.php).
$messageHtml = '';
if ($job['STATUS'] === ImportJobTable::STATUS_DONE) {
    $messageHtml = AdminMessages::ok(GetMessage('IBIMPORT_PROGRESS_DONE') . ' ' . GetMessage('IBIMPORT_SUMMARY', [
        '#CREATED#' => (int)$job['CREATED_COUNT'],
        '#UPDATED#' => (int)$job['UPDATED_COUNT'],
        '#SKIPPED#' => (int)$job['SKIPPED_COUNT'],
    ]));
} elseif ($job['STATUS'] === ImportJobTable::STATUS_ERROR) {
    $messageHtml = AdminMessages::error(GetMessage('IBIMPORT_PROGRESS_ERROR') . ': ' . (string)$job['ERROR_MESSAGE']);
}

// Кнопка возврата к импорту следующих файлов в тот же инфоблок, с теми же параметрами формы. Диск подключён — сразу
// со списком его файлов (тот же GET-запрос, что делает кнопка "Проверить Диск"; форма для ручной загрузки .zip на
// той странице остаётся сверху), иначе только форма (STEP=form — ничего не выполнять, см. ImportPageController).
$targetIblockId = (int)$job['TARGET_IBLOCK_ID'];
$targetIblock = \Bitrix\Iblock\IblockTable::getList(['filter' => ['=ID' => $targetIblockId], 'select' => ['NAME']])->fetch();
$backParams = [
    'lang' => LANGUAGE_ID,
    'IBLOCK_ID' => $targetIblockId,
    'PARENT_SECTION_REF' => (int)$job['PARENT_SECTION_ID'] ?: '',
    'UPDATE_BY_CODE' => $job['UPDATE_BY_CODE'] === 'Y' ? 'Y' : '',
    'MATCH_BY_XML_ID' => ($job['MATCH_BY_XML_ID'] ?? 'N') === 'Y' ? 'Y' : '',
];
$backStep = Options::isYandexDiskEnabled() && Settings::hasToken()
    ? ['STEP' => 'disk_list', 'sessid' => bitrix_sessid()]
    : ['STEP' => 'form'];
$backUrl = '/bitrix/admin/vspace_ibexport_import.php?' . http_build_query($backParams + $backStep);
$backTitle = GetMessage('IBIMPORT_BTN_BACK', ['#IBLOCK#' => '[' . $targetIblockId . ']' . ($targetIblock ? ' ' . $targetIblock['NAME'] : '')]);
$jobFinished = in_array($job['STATUS'], [ImportJobTable::STATUS_DONE, ImportJobTable::STATUS_ERROR], true);

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
    <?php
    // Кнопки есть в панели всегда: если её содержимое пусто, ядро (core_admin_interface.js) всё равно ставит в неё
    // булавку "закрепить панель", и та без кнопок вываливается под форму. Пока задание выполняется, кнопки скрыты
    // (visibility — место в панели сохраняется) — небольшой импорт продвигает только опрос этой страницы, уход с неё
    // посреди импорта его бы остановил; progress.js показывает их по завершении.
    $tabControl->Buttons();
    ?>
    <div id="vibx-finish-buttons"<?= $jobFinished ? '' : ' style="visibility: hidden;"' ?>>
        <a class="adm-btn adm-btn-save" href="<?= htmlspecialcharsbx($backUrl) ?>"><?= htmlspecialcharsbx($backTitle) ?></a>
    </div>
    <?php $tabControl->End(); ?>
</div>
<div id="vibx-result"><?= $messageHtml ?></div>

<?php
require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/epilog_admin.php';
