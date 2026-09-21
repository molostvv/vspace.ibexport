<?php

namespace Vspace\Ibexport\Import;

/**
 * Превращает файл из распакованного архива в массив для записи через
 * классический API (значение файлового поля/свойства). Граница нужна по той
 * же причине, что и PropertySourceInterface: CFile::MakeFileArray() требует
 * ядро Bitrix, а правила "файл не найден в архиве" проверяются в тестах.
 */
interface FileArrayFactoryInterface
{
    /** @return array|null массив вида CFile::MakeFileArray() либо null, если файл не удалось подготовить */
    public function make(string $absPath): ?array;
}
