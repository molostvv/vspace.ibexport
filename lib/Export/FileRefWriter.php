<?php

namespace Vspace\Ibexport\Export;

use Bitrix\Main\Localization\Loc;
use CFile;
use Vspace\Ibexport\XmlStreamWriter;

Loc::loadMessages(__FILE__);

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

    /**
     * @param string|null $description Описание значения (у файлового свойства); null — описание самого файла (b_file)
     */
    public function write(XmlStreamWriter $w, string $tag, int $fileId, ?string $description = null): void
    {
        if (!$fileId) {
            $w->openTag($tag, [], true);
            return;
        }

        $fileArr = CFile::GetFileArray($fileId);
        if (!$fileArr || empty($fileArr['SRC'])) {
            $w->openTag($tag, ['missing' => 'Y', 'file_id' => $fileId], true);
            ($this->warn)(Loc::getMessage('IBX_FILE_REF_MISSING', ['#ID#' => $fileId]));
            return;
        }

        $attrs = [
            'name' => $fileArr['FILE_NAME'],
            'size' => $fileArr['FILE_SIZE'],
            'mime' => $fileArr['CONTENT_TYPE'],
        ];
        $description = ($description ?? '') !== '' ? $description : (string)($fileArr['DESCRIPTION'] ?? '');
        if ($description !== '') {
            $attrs['description'] = $description;
        }

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
                        throw new \Exception(Loc::getMessage('IBX_FILE_REF_COPY_FAILED'));
                    }
                    $attrs['file_ref'] = 'files/' . $destName;
                } else {
                    throw new \Exception(Loc::getMessage('IBX_FILE_REF_NO_SOURCE'));
                }
            } catch (\Throwable $e) {
                ($this->warn)(Loc::getMessage('IBX_FILE_REF_NOT_COPIED', ['#ID#' => $fileId, '#NAME#' => $fileArr['FILE_NAME'], '#ERROR#' => $e->getMessage()]));
            }
        }

        $w->openTag($tag, $attrs, true);
    }
}
