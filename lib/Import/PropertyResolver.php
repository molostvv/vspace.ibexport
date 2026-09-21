<?php

namespace Vspace\Ibexport\Import;

use SimpleXMLElement;

/**
 * Резолв свойств элемента при импорте (docs/xml-format.md, "Свойства
 * элементов"): по XML-описанию <properties> строит массив значений для
 * CIBlockElement::SetPropertyValuesEx() — не записывая ничего сам.
 *
 * Определение свойства должно уже существовать в целевом инфоблоке по CODE —
 * импорт свойства и варианты списков не создаёт (docs/import-format.md):
 *  - свойства нет в целевом инфоблоке — значение пропускается с предупреждением;
 *  - тип L — значение сопоставляется с вариантом списка по отображаемому
 *    тексту, не найденный вариант пропускается с предупреждением;
 *  - тип F — файл берётся из распакованного архива, отсутствующий в архиве
 *    файл пропускается с предупреждением;
 *  - прочие типы — текст как есть.
 * Для множественного свойства значение — массив, для одиночного — первое значение.
 */
final class PropertyResolver
{
    public function __construct(
        private PropertySourceInterface $source,
        private FileArrayFactoryInterface $files
    ) {
    }

    /**
     * @param string $tmpDir Каталог распакованного архива (относительно него читаются file_ref)
     * @return array<string, mixed> код свойства => значение для SetPropertyValuesEx; пустой массив — писать нечего
     */
    public function resolve(int $iblockId, SimpleXMLElement $properties, string $tmpDir, ImportReport $report): array
    {
        if (!isset($properties->property)) {
            return [];
        }

        $propDefs = $this->source->getDefinitions($iblockId);
        $resolved = [];

        foreach ($properties->property as $propNode) {
            $code = (string)$propNode['code'];
            $type = (string)$propNode['type'];
            if (!isset($propDefs[$code])) {
                $report->addWarning('Свойство с кодом "' . $code . '" не найдено в целевом инфоблоке, значение пропущено.');
                continue;
            }
            $propId = $propDefs[$code]['ID'];
            $multiple = $propDefs[$code]['MULTIPLE'] === 'Y';

            if ($type === 'F') {
                $files = $this->resolveFiles($propNode, $code, $tmpDir, $report);
                if (!empty($files)) {
                    $resolved[$code] = $multiple ? $files : $files[0];
                }
                continue;
            }

            $texts = $this->readTexts($propNode);
            if (empty($texts)) {
                continue;
            }

            if ($type === 'L') {
                $enumMap = $this->source->getEnumMap($propId);
                $values = [];
                foreach ($texts as $text) {
                    if (isset($enumMap[$text])) {
                        $values[] = $enumMap[$text];
                    } else {
                        $report->addWarning('Свойство "' . $code . '": вариант "' . $text . '" не найден среди значений списка в целевом инфоблоке, пропущен.');
                    }
                }
                if (!empty($values)) {
                    $resolved[$code] = $multiple ? $values : $values[0];
                }
            } else {
                $resolved[$code] = $multiple ? $texts : $texts[0];
            }
        }

        return $resolved;
    }

    /** @return array[] подготовленные файловые значения (в порядке следования в XML) */
    private function resolveFiles(SimpleXMLElement $propNode, string $code, string $tmpDir, ImportReport $report): array
    {
        $files = [];
        foreach ($propNode->file as $fileNode) {
            $fileRef = (string)$fileNode['file_ref'];
            if ($fileRef === '') {
                continue;
            }
            $absPath = $tmpDir . '/' . $fileRef;
            if (!is_file($absPath)) {
                $report->addWarning('Файл свойства "' . $code . '" не найден в архиве, значение пропущено.');
                continue;
            }
            $fileArr = $this->files->make($absPath);
            if ($fileArr) {
                $files[] = $fileArr;
            }
        }

        return $files;
    }

    /**
     * Текстовые значения свойства: несколько <value> у множественного,
     * иначе текст самого узла <property> (одиночное значение).
     *
     * @return string[]
     */
    private function readTexts(SimpleXMLElement $propNode): array
    {
        if (isset($propNode->value) && count($propNode->value) > 0) {
            $texts = [];
            foreach ($propNode->value as $v) {
                $texts[] = (string)$v;
            }
            return $texts;
        }

        $text = trim((string)$propNode);
        return $text !== '' ? [$text] : [];
    }
}
