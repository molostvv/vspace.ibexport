<?php

namespace Vspace\Ibexport\Admin;

use Bitrix\Main\HttpRequest;
use Vspace\Ibexport\Exporter;
use Vspace\Ibexport\IblockListProvider;
use Vspace\Ibexport\Options;
use Vspace\Ibexport\Rights;

/**
 * Обработка запроса страницы "Новая выгрузка" (admin/export.php): разбор
 * параметров, валидация, проверка прав, расчёт объёма (STEP=estimate) и
 * запуск экспорта (STEP=run). HTML не формирует — возвращает данные для
 * отрисовки формы; при запуске задания делает редирект на страницу прогресса.
 *
 * Тексты ошибок берутся из языкового файла страницы (lang/ru/admin/export.php):
 * страница обязана подключить его через IncludeModuleLangFile() до handle().
 */
final class ExportPageController
{
    /**
     * @return array{
     *     errors: string[],
     *     estimate: array|null,
     *     iblocks: array[],
     *     iblockId: int,
     *     entityType: string,
     *     entityRef: string,
     *     mode: string,
     *     withFiles: bool,
     *     activeOnly: bool
     * }
     */
    public function handle(HttpRequest $request): array
    {
        $errors = [];
        $estimate = null;

        $iblockId = (int)($request->get('IBLOCK_ID') ?? 0);
        $entityType = in_array($request->get('ENTITY_TYPE') ?? '', ['element', 'section'], true) ? $request->get('ENTITY_TYPE') : 'element';
        $entityRef = trim((string)($request->get('ENTITY_REF') ?? ''));
        $mode = in_array($request->get('MODE') ?? '', ['section_single', 'section_tree'], true) ? $request->get('MODE') : 'section_single';
        $step = $request->get('STEP') ?? '';
        // Снятый чекбокс браузер вообще не передаёт в POST — isset() тут не отличит
        // "форму ещё не отправляли" от "пользователь снял галку" (см. тот же фикс
        // в ImportPageController). Различаем по факту отправки формы (наличию STEP).
        $withFiles = $step !== '' ? (($request->get('WITH_FILES') ?? '') === 'Y') : Options::getDefaultWithFiles();
        $activeOnly = $step !== '' ? (($request->get('ACTIVE_ONLY') ?? '') === 'Y') : Options::getDefaultActiveOnly();

        // Инфоблоки, из которых текущий пользователь может выгружать данные (раздел 10 ТЗ).
        $iblocks = IblockListProvider::getAvailable(Rights::canExport(...));

        if ($step === 'estimate' || $step === 'run') {
            try {
                if ($iblockId <= 0) {
                    throw new \Exception(GetMessage('IBEXPORT_ERR_NO_IBLOCK'));
                }
                if (!Rights::canExport($iblockId)) {
                    throw new \Exception(GetMessage('IBEXPORT_ERR_NO_RIGHTS'));
                }
                if ($entityRef === '') {
                    throw new \Exception(GetMessage('IBEXPORT_ERR_NO_ID'));
                }

                if ($entityType === 'element') {
                    $entityId = Exporter::resolveElementId($iblockId, $entityRef);
                    if (!$entityId) {
                        throw new \Exception(GetMessage('IBEXPORT_ERR_ELEMENT_NOT_FOUND'));
                    }
                    $mode = 'element';
                } else {
                    $entityId = Exporter::resolveSectionId($iblockId, $entityRef);
                    if (!$entityId) {
                        throw new \Exception(GetMessage('IBEXPORT_ERR_SECTION_NOT_FOUND'));
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
                        throw new \Exception(GetMessage('IBEXPORT_ERR_SESSID'));
                    }
                    $jobId = Exporter::createJob($params);
                    LocalRedirect('/bitrix/admin/vspace_ibexport_progress.php?lang=' . LANGUAGE_ID . '&JOB_ID=' . $jobId);
                }
            } catch (\Throwable $e) {
                $errors[] = $e->getMessage();
            }
        }

        return [
            'errors' => $errors,
            'estimate' => $estimate,
            'iblocks' => $iblocks,
            'iblockId' => $iblockId,
            'entityType' => $entityType,
            'entityRef' => $entityRef,
            'mode' => $mode,
            'withFiles' => $withFiles,
            'activeOnly' => $activeOnly,
        ];
    }
}
