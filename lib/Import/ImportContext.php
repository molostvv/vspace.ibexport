<?php

namespace Vspace\Ibexport\Import;

/** Неизменяемые параметры одного импорта, нужные обходу и импортёрам узлов: собираются из строки задания. */
final class ImportContext
{
    /**
     * @param int|null $jobId ID задания импорта (им помечаются созданные элементы, см. ElementImporter); null — вне
     *                        задания (предпросмотр)
     */
    public function __construct(
        public readonly int $iblockId,
        public readonly bool $updateByCode,
        public readonly string $tmpDir,
        public readonly bool $matchByXmlId = false,
        public readonly ?int $jobId = null
    ) {
    }
}
