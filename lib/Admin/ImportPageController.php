<?php

namespace Vspace\Ibexport\Admin;

use Bitrix\Main\HttpRequest;
use Vspace\Ibexport\Exporter;
use Vspace\Ibexport\IblockListProvider;
use Vspace\Ibexport\ImportedFileTable;
use Vspace\Ibexport\Importer;
use Vspace\Ibexport\Options;
use Vspace\Ibexport\Rights;
use Vspace\Ibexport\YandexDisk\Exception as YandexDiskException;
use Vspace\Ibexport\YandexDisk\ImportSource;
use Vspace\Ibexport\YandexDisk\Settings;

/**
 * Обработка запроса страницы "Импорт" (admin/import.php). Шаги (параметр STEP):
 *  - validate    — принять загруженный архив и посчитать объём;
 *  - recalc      — пересчитать предпросмотр уже принятого архива по текущим отметкам формы (без повторной загрузки);
 *  - disk_list   — показать список файлов в папке обмена на Яндекс.Диске;
 *  - disk_import — принять выбранный файл с Диска (то же, что validate, но источник — Диск);
 *  - run         — создать задание импорта по уже провалидированному архиву и перейти к странице прогресса;
 *  - form        — ничего не делать, только показать форму с переданными параметрами (инфоблок, родительский раздел,
 *                  галки) — кнопка "Импортировать ещё" со страницы прогресса импорта.
 * HTML не формирует — возвращает данные для отрисовки формы.
 *
 * Тексты ошибок берутся из языкового файла страницы (lang/ru/admin/import.php):
 * страница обязана подключить его через IncludeModuleLangFile() до handle().
 */
final class ImportPageController
{
    /**
     * @return array{
     *     errors: string[],
     *     prepared: array|null,
     *     preview: array{rows: array[], truncated: bool, missing_props: array<string, int>, missing_uf: array<string, int>}|null,
     *     diskFiles: array[]|null,
     *     diskImported: array<string, array>,
     *     iblocks: array[],
     *     iblockId: int,
     *     parentSectionRef: string,
     *     updateByCode: bool,
     *     matchByXmlId: bool
     * }
     *  prepared — результат Importer::prepareUpload()/ImportSource::prepareFromDisk(), null — архив ещё не принят;
     *  preview — результат Importer::preview() для принятого архива (что будет создано/обновлено), null — архив не принят;
     *  diskFiles — файлы на Диске ("Проверить Диск" либо сразу, если Диск подключён), null — Диск не подключён,
     *  недоступен или архив уже принят;
     *  diskImported — какие из этих файлов уже импортированы на этой инсталляции: MD5 => последний импорт
     *  (ImportedFileTable::findLatestByMd5()).
     */
    public function handle(HttpRequest $request): array
    {
        $errors = [];
        $prepared = null;
        $preview = null;
        $diskFiles = null;
        $diskImported = [];

        $iblockId = (int)($request->get('IBLOCK_ID') ?? 0);
        $parentSectionRef = trim((string)($request->get('PARENT_SECTION_REF') ?? ''));
        $step = $request->get('STEP') ?? '';
        // Снятый чекбокс браузер вообще не передаёт в POST — isset() тут не отличит
        // "форму ещё не отправляли" от "пользователь снял галку". Различаем по
        // факту отправки формы (наличию STEP): при первом заходе — значение по
        // умолчанию, при реальном сабмите — ровно то, что пришло (отсутствие ключа
        // после отправки формы означает "снято").
        $updateByCode = $step !== '' ? (($request->get('UPDATE_BY_CODE') ?? '') === 'Y') : Options::getDefaultUpdateByCode();
        // Запасной ключ сопоставления для записей без символьного кода: всегда выключен по умолчанию, включается вручную.
        $matchByXmlId = $step !== '' && (($request->get('MATCH_BY_XML_ID') ?? '') === 'Y');

        // Инфоблоки, в которые текущий пользователь может импортировать данные.
        $iblocks = IblockListProvider::getAvailable(Rights::canImport(...));

        if (in_array($step, ['validate', 'recalc', 'run', 'disk_list', 'disk_import'], true)) {
            try {
                // Каждый шаг что-то делает от имени пользователя (принимает архив, качает файл с Диска, запускает
                // импорт) — только из формы этой страницы, не по ссылке или форме с чужого сайта.
                if (!check_bitrix_sessid()) {
                    throw new \Exception(GetMessage('IBIMPORT_ERR_SESSID'));
                }
                if ($iblockId <= 0) {
                    throw new \Exception(GetMessage('IBIMPORT_ERR_NO_IBLOCK'));
                }
                if (!Rights::canImport($iblockId)) {
                    throw new \Exception(GetMessage('IBIMPORT_ERR_NO_RIGHTS'));
                }

                if ($step === 'validate') {
                    $prepared = Importer::prepareUpload($request->getFile('ARCHIVE') ?? []);
                } elseif ($step === 'recalc') {
                    // Тот же уже принятый архив (скрытые поля формы) — заново считаем предпросмотр по текущим отметкам, файл не перезагружается.
                    $prepared = Importer::reopen((string)($request->get('TMP_DIR') ?? ''), (string)($request->get('SOURCE_FILE_NAME') ?? ''));
                } elseif ($step === 'disk_list') {
                    // Отдельный, полностью ручной шаг (ТЗ "Экспорт в Яндекс.Диск",
                    // раздел 3) — недоступность Диска здесь не мешает обычной
                    // ручной загрузке архива веткой STEP=validate выше.
                    $diskFiles = $this->listDisk($errors, $diskImported);
                } elseif ($step === 'disk_import') {
                    $diskPath = (string)($request->get('DISK_PATH') ?? '');
                    if ($diskPath === '') {
                        throw new \Exception(GetMessage('IBYADISK_ERR_NO_PATH'));
                    }
                    try {
                        $prepared = ImportSource::prepareFromDisk($diskPath);
                    } catch (YandexDiskException $e) {
                        throw new \Exception(GetMessage('IBYADISK_DOWNLOAD_ERROR', ['#MESSAGE#' => $e->getMessage()]));
                    }
                } else { // STEP=run — уже провалидированный на предыдущем шаге архив
                    $tmpDirName = (string)($request->get('TMP_DIR') ?? '');
                    Importer::resolveTmpDir($tmpDirName); // бросит исключение, если архив не найден/устарел

                    $parentSectionId = 0;
                    if ($parentSectionRef !== '') {
                        $parentSectionId = Exporter::resolveSectionId($iblockId, $parentSectionRef);
                        if (!$parentSectionId) {
                            throw new \Exception(GetMessage('IBIMPORT_ERR_PARENT_NOT_FOUND'));
                        }
                    }

                    $jobId = Importer::createJob([
                        'TARGET_IBLOCK_ID' => $iblockId,
                        'PARENT_SECTION_ID' => $parentSectionId,
                        'UPDATE_BY_CODE' => $updateByCode,
                        'MATCH_BY_XML_ID' => $matchByXmlId,
                        'TMP_DIR' => $tmpDirName,
                        'SOURCE_FILE_NAME' => (string)($request->get('SOURCE_FILE_NAME') ?? ''),
                    ]);
                    LocalRedirect('/bitrix/admin/vspace_ibexport_import_progress.php?lang=' . LANGUAGE_ID . '&JOB_ID=' . $jobId);
                }

                if ($prepared !== null) {
                    $preview = Importer::preview($prepared['tmp_dir'], $iblockId, $updateByCode, $matchByXmlId);
                }
            } catch (\Throwable $e) {
                $errors[] = $e->getMessage();
            }
        }

        // Диск подключён — список его файлов и без нажатия "Проверить Диск", пока архив ещё не принят: при открытии
        // страницы, по кнопке "Импортировать ещё" и после неудачного шага. Только чтение, поэтому без sessid; при
        // недоступности Диска форма работает как обычно, ошибка — над ней.
        if ($prepared === null && $diskFiles === null && $iblocks && Options::isYandexDiskEnabled() && Settings::hasToken()) {
            $diskFiles = $this->listDisk($errors, $diskImported);
        }

        return [
            'errors' => $errors,
            'prepared' => $prepared,
            'preview' => $preview,
            'diskFiles' => $diskFiles,
            'diskImported' => $diskImported,
            'iblocks' => $iblocks,
            'iblockId' => $iblockId,
            'parentSectionRef' => $parentSectionRef,
            'updateByCode' => $updateByCode,
            'matchByXmlId' => $matchByXmlId,
        ];
    }

    /**
     * Файлы папки обмена на Диске и какие из них уже импортированы здесь; ошибка Диска — в $errors, результат null.
     *
     * @param array<string, array> $diskImported MD5 => последний импорт (ImportedFileTable::findLatestByMd5())
     */
    private function listDisk(array &$errors, array &$diskImported): ?array
    {
        try {
            $files = ImportSource::listDiskFiles();
        } catch (YandexDiskException $e) {
            $errors[] = GetMessage('IBYADISK_LIST_ERROR', ['#MESSAGE#' => $e->getMessage()]);
            return null;
        }
        $diskImported = ImportedFileTable::findLatestByMd5(array_column($files, 'md5'));

        return $files;
    }
}
