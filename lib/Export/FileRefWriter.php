<?php

namespace Vspace\Ibexport\Export;

use CFile;
use Vspace\Ibexport\XmlStreamWriter;

/**
 * Пишет XML-ссылку на файл (<picture/>, <preview_picture/>, <file/> ...) и,
 * если выгрузка идёт с файлами, копирует сам файл в files/ рабочего
 * каталога задания. Общий для SectionWriter и ElementWriter (картинки
 * разделов, картинки элементов, значения файловых свойств).
 */
final class FileRefWriter
{
    /** @param \Closure(string): void $warn Приёмник предупреждений задания */
    public function __construct(
        private bool $withFiles,
        private string $filesDir,
        private \Closure $warn
    ) {
    }

    public function write(XmlStreamWriter $w, string $tag, int $fileId): void
    {
        if (!$fileId) {
            $w->openTag($tag, [], true);
            return;
        }

        $fileArr = CFile::GetFileArray($fileId);
        if (!$fileArr || empty($fileArr['SRC'])) {
            $w->openTag($tag, ['missing' => 'Y', 'file_id' => $fileId], true);
            ($this->warn)('Файл #' . $fileId . ' не найден, пропущен.');
            return;
        }

        $attrs = [
            'name' => $fileArr['FILE_NAME'],
            'size' => $fileArr['FILE_SIZE'],
            'mime' => $fileArr['CONTENT_TYPE'],
        ];

        if ($this->withFiles) {
            $destName = $fileId . '_' . preg_replace('~[^A-Za-z0-9._-]+~u', '_', $fileArr['FILE_NAME']);
            $destPath = $this->filesDir . '/' . $destName;
            $srcPath = $_SERVER['DOCUMENT_ROOT'] . $fileArr['SRC'];

            try {
                if (is_file($srcPath)) {
                    if (!is_dir($this->filesDir)) {
                        mkdir($this->filesDir, 0755, true);
                    }
                    if (!copy($srcPath, $destPath)) {
                        throw new \Exception('ошибка copy()');
                    }
                    $attrs['file_ref'] = 'files/' . $destName;
                } else {
                    throw new \Exception('исходный файл отсутствует на диске');
                }
            } catch (\Throwable $e) {
                ($this->warn)('Не удалось скопировать файл #' . $fileId . ' (' . $fileArr['FILE_NAME'] . '): ' . $e->getMessage());
            }
        }

        $w->openTag($tag, $attrs, true);
    }
}
