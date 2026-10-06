<?php

namespace Vspace\Ibexport\YandexDisk;

use Vspace\Ibexport\JobTable;
use Vspace\Ibexport\Options;

/**
 * Выгрузка архива завершённого задания экспорта в папку обмена на Яндекс.Диске — общая для автоматической выгрузки
 * сразу после экспорта (Export\ExportStep, настройка модуля YANDEX_DISK_AUTO_UPLOAD) и кнопки "Выгрузить в
 * Яндекс.Диск" на странице прогресса (admin/yandex_disk_upload.php). Файл с тем же именем на Диске перезаписывается.
 * Результат запоминается в JobUploadTable.
 */
final class JobUploader
{
    /** Выгружать ли архив сразу после экспорта: включено в настройках модуля и Диск подключён. */
    public static function isAutoEnabled(): bool
    {
        return Options::isYandexDiskAutoUpload() && Options::isYandexDiskEnabled() && Settings::hasToken();
    }

    /**
     * @param array $job строка JobTable завершённого задания (нужны ID, TMP_DIR, ARCHIVE_FILE)
     * @return string путь файла на Диске
     * @throws \Throwable ошибка выгрузки (она же запомнена в JobUploadTable)
     */
    public static function upload(array $job): string
    {
        $jobId = (int)$job['ID'];
        $folder = Settings::getFolder();
        $diskPath = rtrim($folder, '/') . '/' . $job['ARCHIVE_FILE'];

        JobUploadTable::remember($jobId, JobUploadTable::STATUS_RUNNING, $diskPath);
        try {
            $client = new Client(Settings::getToken());
            $client->ensureFolder($folder);
            $client->uploadFile($diskPath, JobTable::getTmpPath($job) . '/' . $job['ARCHIVE_FILE'], true);
        } catch (\Throwable $e) {
            JobUploadTable::remember($jobId, JobUploadTable::STATUS_ERROR, $diskPath, $e->getMessage());
            throw $e;
        }
        JobUploadTable::remember($jobId, JobUploadTable::STATUS_DONE, $diskPath);

        return $diskPath;
    }
}
