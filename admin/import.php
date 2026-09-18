<?php

require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_admin_before.php';

use Bitrix\Main\Loader;
use Vspace\Ibexport\Exporter;
use Vspace\Ibexport\Importer;
use Vspace\Ibexport\Options;
use Vspace\Ibexport\Rights;
use Vspace\Ibexport\YandexDiskException;

Loader::includeModule('vspace.ibexport');
Loader::includeModule('iblock');

IncludeModuleLangFile(__FILE__);

$APPLICATION->SetTitle(GetMessage('IBIMPORT_TITLE'));

global $USER, $APPLICATION;

$errors = [];
$prepared = null; // результат Importer::prepareUpload()/prepareFromDisk() либо восстановленный из скрытых полей формы
$diskFiles = null; // результат "Проверить Диск" — список файлов в папке обмена на Яндекс.Диске

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

if (in_array($step, ['validate', 'run', 'disk_list', 'disk_import'], true)) {
    try {
        if ($iblockId <= 0) {
            throw new Exception(GetMessage('IBIMPORT_ERR_NO_IBLOCK'));
        }
        if (!Rights::canImport($iblockId)) {
            throw new Exception(GetMessage('IBIMPORT_ERR_NO_RIGHTS'));
        }

        if ($step === 'validate') {
            $prepared = Importer::prepareUpload($_FILES['ARCHIVE'] ?? []);
        } elseif ($step === 'disk_list') {
            // Отдельный, полностью ручной шаг (ТЗ "Экспорт в Яндекс.Диск",
            // раздел 3) — недоступность Диска здесь не мешает обычной
            // ручной загрузке архива веткой STEP=validate выше.
            try {
                $diskFiles = Importer::listDiskFiles();
            } catch (YandexDiskException $e) {
                $errors[] = GetMessage('IBYADISK_LIST_ERROR', ['#MESSAGE#' => $e->getMessage()]);
            }
        } elseif ($step === 'disk_import') {
            $diskPath = (string)($_REQUEST['DISK_PATH'] ?? '');
            if ($diskPath === '') {
                throw new Exception(GetMessage('IBYADISK_ERR_NO_PATH'));
            }
            try {
                $prepared = Importer::prepareFromDisk($diskPath);
            } catch (YandexDiskException $e) {
                throw new Exception(GetMessage('IBYADISK_DOWNLOAD_ERROR', ['#MESSAGE#' => $e->getMessage()]));
            }
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
        <input type="submit" class="adm-btn adm-btn-save" value="<?= GetMessage('IBIMPORT_BTN_RUN') ?>">
    <?php else: ?>
        <button type="submit" name="STEP" value="validate" class="adm-btn adm-btn-save"><?= GetMessage('IBIMPORT_BTN_VALIDATE') ?></button>
        <?php if (Options::isYandexDiskEnabled() && Options::hasYandexDiskToken()): ?>
            <?php
            // Отдельная, независимая от загрузки файла кнопка (ТЗ "Экспорт
            // в Яндекс.Диск", раздел 3) — формметод GET, чтобы не заходить
            // в основную ветку STEP=validate; недоступность Диска в момент
            // нажатия не мешает обычной загрузке .zip выше.
            ?>
            <button type="submit" name="STEP" value="disk_list" formmethod="get" class="adm-btn"><?= GetMessage('IBYADISK_BTN_CHECK') ?></button>
        <?php endif; ?>
    <?php endif; ?>
    <?php $tabControl->End(); ?>
</form>

<?php if ($diskFiles !== null): ?>
    <?php
    // Список файлов на Диске — превью-список для выбора файла перед
    // импортом, а не постоянная сущность с сортировкой/фильтрами/БД, для
    // которой создан CAdminList/CAdminUiList — поэтому здесь обычная
    // таблица, оформленная штатными классами адм-списка (adm-list-table*)
    // для визуальной консистентности, а не полноценный список ядра.
    ?>
    <div class="vibx-note" style="display: block;">
        <strong><?= GetMessage('IBYADISK_LIST_HEADING') ?></strong>
        <?php if (!$diskFiles): ?>
            <div style="margin-top: 8px;"><?= GetMessage('IBYADISK_LIST_EMPTY') ?></div>
        <?php else: ?>
            <table class="adm-list-table" style="margin-top: 8px; width: 100%;">
                <thead>
                <tr class="adm-list-table-header">
                    <td class="adm-list-table-cell"><?= GetMessage('IBYADISK_COL_NAME') ?></td>
                    <td class="adm-list-table-cell"><?= GetMessage('IBYADISK_COL_SIZE') ?></td>
                    <td class="adm-list-table-cell"><?= GetMessage('IBYADISK_COL_MODIFIED') ?></td>
                    <td class="adm-list-table-cell"></td>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($diskFiles as $file): ?>
                    <tr class="adm-list-table-row">
                        <td class="adm-list-table-cell"><?= htmlspecialcharsbx($file['name']) ?></td>
                        <td class="adm-list-table-cell"><?= number_format($file['size'] / 1024, 1, '.', ' ') ?> <?= GetMessage('IBYADISK_KB') ?></td>
                        <td class="adm-list-table-cell"><?= htmlspecialcharsbx($file['modified']) ?></td>
                        <td class="adm-list-table-cell">
                            <form method="get" action="<?= htmlspecialcharsbx($APPLICATION->GetCurPage()) ?>" style="display:inline;">
                                <input type="hidden" name="lang" value="<?= LANGUAGE_ID ?>">
                                <input type="hidden" name="IBLOCK_ID" value="<?= (int)$iblockId ?>">
                                <input type="hidden" name="STEP" value="disk_import">
                                <input type="hidden" name="DISK_PATH" value="<?= htmlspecialcharsbx($file['path']) ?>">
                                <input type="submit" class="adm-btn" value="<?= GetMessage('IBYADISK_BTN_IMPORT_FILE') ?>">
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
<?php endif; ?>

<?php
require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/epilog_admin.php';
