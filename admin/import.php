<?php

require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_admin_before.php';

use Bitrix\Main\Loader;
use Vspace\Ibexport\Exporter;
use Vspace\Ibexport\Importer;
use Vspace\Ibexport\Options;
use Vspace\Ibexport\Rights;

Loader::includeModule('vspace.ibexport');
Loader::includeModule('iblock');

IncludeModuleLangFile(__FILE__);

$APPLICATION->SetTitle(GetMessage('IBIMPORT_TITLE'));

global $USER, $APPLICATION;

$errors = [];
$prepared = null; // результат Importer::prepareUpload() либо восстановленный из скрытых полей формы

$iblockId = (int)($_REQUEST['IBLOCK_ID'] ?? 0);
$parentSectionRef = trim((string)($_REQUEST['PARENT_SECTION_REF'] ?? ''));
$step = $_REQUEST['STEP'] ?? '';
// Снятый чекбокс браузер вообще не передаёт в POST — isset() тут не отличит
// "форму ещё не отправляли" от "пользователь снял галку". Различаем по
// факту отправки формы (наличию STEP): при первом заходе — значение по
// умолчанию, при реальном сабмите — ровно то, что пришло (отсутствие ключа
// после отправки формы означает "снято").
$updateByCode = $step !== '' ? (($_REQUEST['UPDATE_BY_CODE'] ?? '') === 'Y') : Options::getDefaultUpdateByCode();

// Инфоблоки, в которые текущий пользователь может импортировать данные.
$iblocksList = [];
$ibRes = \CIBlock::GetList(['SORT' => 'ASC'], ['ACTIVE' => 'Y']);
while ($ib = $ibRes->Fetch()) {
    if (Rights::canImport((int)$ib['ID'])) {
        $iblocksList[] = $ib;
    }
}

if ($step === 'validate' || $step === 'run') {
    try {
        if ($iblockId <= 0) {
            throw new Exception(GetMessage('IBIMPORT_ERR_NO_IBLOCK'));
        }
        if (!Rights::canImport($iblockId)) {
            throw new Exception(GetMessage('IBIMPORT_ERR_NO_RIGHTS'));
        }

        if ($step === 'validate') {
            $prepared = Importer::prepareUpload($_FILES['ARCHIVE'] ?? []);
        } else { // STEP=run — уже провалидированный на предыдущем шаге архив
            if (!check_bitrix_sessid()) {
                throw new Exception(GetMessage('IBIMPORT_ERR_SESSID'));
            }

            $tmpDirName = (string)($_REQUEST['TMP_DIR'] ?? '');
            Importer::resolveTmpDir($tmpDirName); // бросит исключение, если архив не найден/устарел

            $parentSectionId = 0;
            if ($parentSectionRef !== '') {
                $parentSectionId = Exporter::resolveSectionId($iblockId, $parentSectionRef);
                if (!$parentSectionId) {
                    throw new Exception(GetMessage('IBIMPORT_ERR_PARENT_NOT_FOUND'));
                }
            }

            $jobId = Importer::createJob([
                'TARGET_IBLOCK_ID' => $iblockId,
                'PARENT_SECTION_ID' => $parentSectionId,
                'UPDATE_BY_CODE' => $updateByCode,
                'TMP_DIR' => $tmpDirName,
                'SOURCE_FILE_NAME' => (string)($_REQUEST['SOURCE_FILE_NAME'] ?? ''),
            ]);
            LocalRedirect('/bitrix/admin/vspace_ibexport_import_progress.php?lang=' . LANGUAGE_ID . '&JOB_ID=' . $jobId);
        }
    } catch (\Throwable $e) {
        $errors[] = $e->getMessage();
    }
}

require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_admin_after.php';

if ($errors) {
    CAdminMessage::ShowMessage(['TYPE' => 'ERROR', 'MESSAGE' => implode('<br>', array_map('htmlspecialcharsbx', $errors))]);
}

// Стандартная детальная форма админки Bitrix, как и на странице экспорта.
$tabControl = new CAdminTabControl('tabControl', [
    ['DIV' => 'edit1', 'TAB' => GetMessage('IBIMPORT_FORM_HEADING'), 'TITLE' => GetMessage('IBIMPORT_FORM_HEADING')],
]);
?>

<form method="post" action="<?= htmlspecialcharsbx($APPLICATION->GetCurPage()) ?>" name="vibx_import_form" enctype="multipart/form-data">
    <?php $tabControl->Begin(); ?>
    <?= bitrix_sessid_post() ?>
    <input type="hidden" name="lang" value="<?= LANGUAGE_ID ?>">
    <?php $tabControl->BeginNextTab(); ?>

    <tr>
        <td width="40%"><?= GetMessage('IBIMPORT_FIELD_IBLOCK') ?></td>
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
        <td><?= GetMessage('IBIMPORT_FIELD_FILE') ?></td>
        <td>
            <input type="file" name="ARCHIVE" accept=".zip">
            <?php if ($prepared !== null): ?>
                <div style="color:#888;font-size:11px;"><?= GetMessage('IBIMPORT_FILE_LOADED', ['#NAME#' => htmlspecialcharsbx($prepared['source_file_name'])]) ?></div>
            <?php endif; ?>
        </td>
    </tr>
    <tr>
        <td><?= GetMessage('IBIMPORT_FIELD_PARENT_SECTION') ?></td>
        <td>
            <input type="text" size="30" name="PARENT_SECTION_REF" value="<?= htmlspecialcharsbx($parentSectionRef) ?>">
            <div style="color:#888;font-size:11px;"><?= GetMessage('IBIMPORT_PARENT_SECTION_HINT') ?></div>
        </td>
    </tr>
    <tr>
        <td><?= GetMessage('IBIMPORT_FIELD_UPDATE_BY_CODE') ?></td>
        <td><input type="checkbox" name="UPDATE_BY_CODE" value="Y" <?= $updateByCode ? 'checked' : '' ?>></td>
    </tr>

    <?php if ($prepared !== null): ?>
        <tr>
            <td colspan="2">
                <div class="vibx-note">
                    <?= GetMessage('IBIMPORT_ESTIMATE', [
                        '#MODE#' => GetMessage('IBIMPORT_MODE_' . strtoupper($prepared['mode'])),
                        '#SECTIONS#' => $prepared['sections'],
                        '#ELEMENTS#' => $prepared['elements'],
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
            </td>
        </tr>
        <input type="hidden" name="TMP_DIR" value="<?= htmlspecialcharsbx($prepared['tmp_dir']) ?>">
        <input type="hidden" name="SOURCE_FILE_NAME" value="<?= htmlspecialcharsbx($prepared['source_file_name']) ?>">
    <?php endif; ?>

    <?php $tabControl->Buttons(); ?>
    <?php if ($prepared !== null): ?>
        <input type="hidden" name="STEP" value="run">
        <input type="submit" class="adm-btn-save" value="<?= GetMessage('IBIMPORT_BTN_RUN') ?>">
    <?php else: ?>
        <input type="hidden" name="STEP" value="validate">
        <input type="submit" value="<?= GetMessage('IBIMPORT_BTN_VALIDATE') ?>">
    <?php endif; ?>
    <?php $tabControl->End(); ?>
</form>

<?php
require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/epilog_admin.php';
