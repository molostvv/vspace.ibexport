<?php

namespace Vspace\Ibexport\Import;

use CFile;

final class BitrixFileArrayFactory implements FileArrayFactoryInterface
{
    public function make(string $absPath): ?array
    {
        $fileArr = CFile::MakeFileArray($absPath);

        return $fileArr ?: null;
    }
}
