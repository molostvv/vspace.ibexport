<?php

require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_admin_before.php';

use Bitrix\Main\Loader;
use Vspace\Ibexport\Exporter;
use Vspace\Ibexport\Options;
use Vspace\Ibexport\Rights;

Loader::includeModule('vspace.ibexport');
Loader::includeModule('iblock');

IncludeModuleLangFile(__FILE__);

$APPLICATION->SetTitle(GetMessage('IBEXPORT_EXPORT_TITLE'));

global $USER, $APPLICATION;

$errors = [];
$estimate = null;

$iblockId = (int)($_REQUEST['IBLOCK_ID'] ?? 0);
$entityType = in_array($_REQUEST['ENTITY_TYPE'] ?? '', ['element', 'section'], true) ? $_REQUEST['ENTITY_TYPE'] : 'element';
$entityRef = trim((string)($_REQUEST['ENTITY_REF'] ?? ''));
$mode = in_array($_REQUEST['MODE'] ?? '', ['section_single', 'section_tree'], true) ? $_REQUEST['MODE'] : 'section_single';
$step = $_REQUEST['STEP'] ?? '';
// Снятый чекбокс браузер вообще не передаёт в POST — isset() тут не отличит
// "форму ещё не отправляли" от "пользователь снял галку" (см. тот же фикс
// в admin/import.php). Различаем по факту отправки формы (наличию STEP).
$withFiles = $step !== '' ? (($_REQUEST['WITH_FILES'] ?? '') === 'Y') : Options::getDefaultWithFiles();
$activeOnly = $step !== '' ? (($_REQUEST['ACTIVE_ONLY'] ?? '') === 'Y') : Options::getDefaultActiveOnly();

// Инфоблоки, из которых текущий пользователь может выгружать данные (раздел 10 ТЗ).
$iblocksList = [];
$ibRes = \CIBlock::GetList(['SORT' => 'ASC'], ['ACTIVE' => 'Y']);
while ($ib = $ibRes->Fetch()) {
    if (Rights::canExport((int)$ib['ID'])) {
        $iblocksList[] = $ib;
    }
}

if ($step === 'estimate' || $step === 'run') {
    try {
        if ($iblockId <= 0) {
            throw new Exception(GetMessage('IBEXPORT_ERR_NO_IBLOCK'));
        }
        if (!Rights::canExport($iblockId)) {
            throw new Exception(GetMessage('IBEXPORT_ERR_NO_RIGHTS'));
        }
        if ($entityRef === '') {
            throw new Exception(GetMessage('IBEXPORT_ERR_NO_ID'));
        }

        if ($entityType === 'element') {
            $entityId = Exporter::resolveElementId($iblockId, $entityRef);
            if (!$entityId) {
                throw new Exception(GetMessage('IBEXPORT_ERR_ELEMENT_NOT_FOUND'));
            }
            $mode = 'element';
        } else {
            $entityId = Exporter::resolveSectionId($iblockId, $entityRef);
            if (!$entityId) {
                throw new Exception(GetMessage('IBEXPORT_ERR_SECTION_NOT_FOUND'));
            }
        }

        $params = [
            'IBLOCK_ID' => $iblockId,
            'ENTITY_TYPE' => $entityType,
            'ENTITY_ID' => $entityId,
            'MODE' => $mode,
            'WITH_FILES' => $withFiles,
            'ACTIVE_ONLY' => $activeOnly,
        ];

        if ($step === 'estimate') {
            $estimate = Exporter::estimate($params);
        } else { // STEP=run — фактический запуск экспорта
            if (!check_bitrix_sessid()) {
                throw new Exception(GetMessage('IBEXPORT_ERR_SESSID'));
            }
            $jobId = Exporter::createJob($params);
            LocalRedirect('/bitrix/admin/vspace_ibexport_progress.php?lang=' . LANGUAGE_ID . '&JOB_ID=' . $jobId);
        }
    } catch (\Throwable $e) {
        $errors[] = $e->getMessage();
    }
}

require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_admin_after.php';

if ($errors) {
    CAdminMessage::ShowMessage(['TYPE' => 'ERROR', 'MESSAGE' => implode('<br>', array_map('htmlspecialcharsbx', $errors))]);
}

// Стандартная детальная форма админки Bitrix (тот же движок, что рисует
// формы редактирования элементов/разделов) — вместо голой <table> без рамки
// и заголовка.
$tabControl = new CAdminTabControl('tabControl', [
    ['DIV' => 'edit1', 'TAB' => GetMessage('IBEXPORT_FORM_HEADING'), 'TITLE' => GetMessage('IBEXPORT_FORM_HEADING')],
]);
?>

<form method="post" action="<?= htmlspecialcharsbx($APPLICATION->GetCurPage()) ?>" name="vibx_export_form">
    <?php $tabControl->Begin(); ?>
    <?= bitrix_sessid_post() ?>
    <input type="hidden" name="lang" value="<?= LANGUAGE_ID ?>">
    <?php $tabControl->BeginNextTab(); ?>

    <tr>
        <td width="40%"><?= GetMessage('IBEXPORT_FIELD_IBLOCK') ?></td>
        <td>
            <select name="IBLOCK_ID">
                <option value="0">...</option>
                <?php foreach ($iblocksList as $ib): ?>
                    <option value="<?= (int)$ib['ID'] ?>" <?= $iblockId === (int)$ib['ID'] ? 'selected' : '' ?>>
                        [<?= (int)$ib['ID'] ?>] <?= htmlspecialcharsbx($ib['NAME']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </td>
    </tr>
    <tr>
        <td><?= GetMessage('IBEXPORT_FIELD_ENTITY_TYPE') ?></td>
        <td>
            <label><input type="radio" name="ENTITY_TYPE" value="element" <?= $entityType === 'element' ? 'checked' : '' ?>> <?= GetMessage('IBEXPORT_ENTITY_ELEMENT') ?></label>
            &nbsp;
            <label><input type="radio" name="ENTITY_TYPE" value="section" <?= $entityType === 'section' ? 'checked' : '' ?>> <?= GetMessage('IBEXPORT_ENTITY_SECTION') ?></label>
        </td>
    </tr>
    <tr>
        <td><?= GetMessage('IBEXPORT_FIELD_ID') ?></td>
        <td><input type="text" size="30" name="ENTITY_REF" value="<?= htmlspecialcharsbx($entityRef) ?>"></td>
    </tr>
    <tr>
        <td><?= GetMessage('IBEXPORT_FIELD_MODE') ?></td>
        <td>
            <label><input type="radio" name="MODE" value="section_single" <?= $mode === 'section_single' ? 'checked' : '' ?>> <?= GetMessage('IBEXPORT_MODE_SINGLE') ?></label>
            <br>
            <label><input type="radio" name="MODE" value="section_tree" <?= $mode === 'section_tree' ? 'checked' : '' ?>> <?= GetMessage('IBEXPORT_MODE_TREE') ?></label>
            <div style="color:#888;font-size:11px;"><?= GetMessage('IBEXPORT_MODE_HINT') ?></div>
        </td>
    </tr>
    <tr>
        <td><?= GetMessage('IBEXPORT_FIELD_FILES') ?></td>
        <td><input type="checkbox" name="WITH_FILES" value="Y" <?= $withFiles ? 'checked' : '' ?>></td>
    </tr>
    <tr>
        <td><?= GetMessage('IBEXPORT_FIELD_ACTIVE_ONLY') ?></td>
        <td><input type="checkbox" name="ACTIVE_ONLY" value="Y" <?= $activeOnly ? 'checked' : '' ?>></td>
    </tr>

    <?php $tabControl->Buttons(); ?>
    <?php if ($estimate !== null): ?>
        <input type="hidden" name="STEP" value="run">
        <input type="submit" class="adm-btn adm-btn-save" value="<?= GetMessage('IBEXPORT_BTN_RUN') ?>">
    <?php else: ?>
        <input type="hidden" name="STEP" value="estimate">
        <input type="submit" class="adm-btn adm-btn-save" value="<?= GetMessage('IBEXPORT_BTN_ESTIMATE') ?>">
    <?php endif; ?>
    <?php $tabControl->End(); ?>
</form>

<?php if ($estimate !== null): ?>
    <?php
    // Результат — отдельным блоком ПОСЛЕ панели формы (штатная область под
    // ней), а не строкой внутри таблицы параметров вперемешку с полями
    // ввода. Оформление своё, без классов ядра: "-gray" без парного
    // "adm-info-message-icon" в этой теме не имеет собственных отступов и
    // выравнивания (см. предыдущую правку), а других нейтральных
    // (не success/error) типов сообщений ядро не предоставляет.
    ?>
    <div class="vibx-note">
        <?= GetMessage('IBEXPORT_ESTIMATE', [
            '#SECTIONS#' => $estimate['sections'],
            '#ELEMENTS#' => $estimate['elements'],
        ]) ?>
    </div>
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
<?php endif; ?>

<?php
require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/epilog_admin.php';
