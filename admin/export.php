<?php

require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_admin_before.php';

use Bitrix\Main\Loader;
use Vspace\Ibexport\Admin\AdminMessages;
use Vspace\Ibexport\Admin\ExportPageController;

Loader::includeModule('vspace.ibexport');
Loader::includeModule('iblock');

IncludeModuleLangFile(__FILE__);

$APPLICATION->SetTitle(GetMessage('IBEXPORT_EXPORT_TITLE'));
$APPLICATION->SetAdditionalCSS('/local/modules/vspace.ibexport/admin/css/vibx.css');

// Разбор запроса, валидация, права, расчёт объёма и запуск экспорта — в контроллере;
// он же делает редирект на страницу прогресса при STEP=run. Здесь — только вёрстка.
$page = (new ExportPageController())->handle(\Bitrix\Main\Context::getCurrent()->getRequest());
[
    'errors' => $errors,
    'estimate' => $estimate,
    'iblocks' => $iblocksList,
    'iblockId' => $iblockId,
    'entityType' => $entityType,
    'entityRef' => $entityRef,
    'mode' => $mode,
    'withFiles' => $withFiles,
    'activeOnly' => $activeOnly,
] = $page;

require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_admin_after.php';

AdminMessages::showErrors($errors);

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
<?php endif; ?>

<?php
require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/epilog_admin.php';
