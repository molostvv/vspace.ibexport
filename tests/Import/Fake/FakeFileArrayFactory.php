<?php

namespace Vspace\Ibexport\Tests\Import\Fake;

use Vspace\Ibexport\Import\FileArrayFactoryInterface;

/** Вместо CFile::MakeFileArray(): возвращает простой массив с путём либо null, если файл велено "не подготавливать". */
final class FakeFileArrayFactory implements FileArrayFactoryInterface
{
    /** @var string[] */
    public array $requested = [];

    /** @param string[] $failFor Пути, для которых make() вернёт null (как при ошибке MakeFileArray) */
    public function __construct(private array $failFor = [])
    {
    }

    public function make(string $absPath): ?array
    {
        $this->requested[] = $absPath;

        return in_array($absPath, $this->failFor, true) ? null : ['name' => basename($absPath), 'tmp_name' => $absPath];
    }
}
