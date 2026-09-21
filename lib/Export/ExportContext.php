<?php

namespace Vspace\Ibexport\Export;

/** Неизменяемые параметры одного экспорта, нужные обходу и писателям XML: собираются из строки задания. */
final class ExportContext
{
    public function __construct(
        public readonly int $iblockId,
        public readonly bool $activeOnly,
        public readonly bool $withFiles,
        public readonly string $filesDir,
        public readonly int $batchSize
    ) {
    }
}
