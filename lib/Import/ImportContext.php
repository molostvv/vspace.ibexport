<?php

namespace Vspace\Ibexport\Import;

/** Неизменяемые параметры одного импорта, нужные обходу и импортёрам узлов: собираются из строки задания. */
final class ImportContext
{
    public function __construct(
        public readonly int $iblockId,
        public readonly bool $updateByCode,
        public readonly string $tmpDir,
        public readonly bool $matchByXmlId = false
    ) {
    }
}
