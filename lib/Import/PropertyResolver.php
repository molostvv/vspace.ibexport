<?php

namespace Vspace\Ibexport\Import;

use Bitrix\Main\Localization\Loc;
use SimpleXMLElement;

Loc::loadMessages(__FILE__);

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
 *  - тип F — файл берётся из распакованного архива (только files/…, см. ArchiveFileRef),
 *    отсутствующий в архиве файл пропускается с предупреждением;
 *  - типы E/G (привязка к элементу/разделу) — связанная запись ищется в инфоблоке привязки
 *    целевого свойства по символьному коду (ref_code), без кода — по XML_ID (ref_xml_id, если
 *    включено сопоставление по XML_ID); ID исходной инсталляции не переносится никогда;
 *  - прочие типы — текст как есть.
 * Для множественного свойства значение — массив, для одиночного — первое значение. Описание значения
 * (атрибут description) передаётся, если у целевого свойства включены описания. Пустое свойство в
 * архиве (<property .../> без значений) — значение false (для файлов — ['del' => 'Y']): при обновлении
 * элемента оно очищает свойство.
 */
final class PropertyResolver
{
    /** Значение, очищающее файловое свойство в SetPropertyValuesEx() (false файловые свойства пропускают). */
    public const CLEAR_FILES = ['del' => 'Y'];

    public function __construct(
        private PropertySourceInterface $source,
        private FileArrayFactoryInterface $files,
        private ?ExistingRecordFinderInterface $finder = null
    ) {
    }

    /**
     * @param string $tmpDir Каталог распакованного архива (относительно него читаются file_ref)
     * @param bool $matchLinksByXmlId Искать связанные записи без кода по XML_ID — та же опция импорта, что и для самих записей
     * @return array<string, mixed> код свойства => значение для SetPropertyValuesEx; пустой массив — писать нечего
     */
    public function resolve(int $iblockId, SimpleXMLElement $properties, string $tmpDir, ImportReport $report, bool $matchLinksByXmlId = false): array
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
                $report->addWarning(Loc::getMessage('IBX_RESOLVER_NO_PROPERTY', ['#CODE#' => $code]));
                continue;
            }
            $def = $propDefs[$code];

            if (self::isEmpty($propNode)) {
                $resolved[$code] = $type === 'F' ? self::CLEAR_FILES : false;
                continue;
            }

            $values = match ($type) {
                'F' => $this->resolveFiles($propNode, $code, $tmpDir, $report),
                'L' => $this->resolveList($propNode, $code, (int)$def['ID'], $report),
                'E', 'G' => $this->resolveLinks($propNode, $code, $type, (int)($def['LINK_IBLOCK_ID'] ?? 0), $matchLinksByXmlId, $report),
                default => self::readValues($propNode),
            };
            if (empty($values)) {
                continue;
            }

            $withDescription = ($def['WITH_DESCRIPTION'] ?? 'N') === 'Y' && $type !== 'L';
            $prepared = array_map(
                static fn(array $v) => $withDescription && $v['description'] !== ''
                    ? ['VALUE' => $v['value'], 'DESCRIPTION' => $v['description']]
                    : $v['value'],
                $values
            );
            $resolved[$code] = $def['MULTIPLE'] === 'Y' ? $prepared : $prepared[0];
        }

        return $resolved;
    }

    /** Свойство выгружено пустым: ни <value>, ни <file>, ни текста. */
    private static function isEmpty(SimpleXMLElement $propNode): bool
    {
        return count($propNode->value) === 0 && count($propNode->file) === 0 && trim((string)$propNode) === '';
    }

    /**
     * Узлы значений: несколько <value> у множественного, иначе сам узел <property> (одиночное значение).
     *
     * @return SimpleXMLElement[]
     */
    private static function valueNodes(SimpleXMLElement $propNode): array
    {
        if (count($propNode->value) > 0) {
            $nodes = [];
            foreach ($propNode->value as $value) {
                $nodes[] = $value;
            }
            return $nodes;
        }

        return [$propNode];
    }

    /** @return list<array{value: string, description: string}> текстовые значения (одиночное значение обрезается по краям) */
    private static function readValues(SimpleXMLElement $propNode): array
    {
        $single = count($propNode->value) === 0;
        $values = [];
        foreach (self::valueNodes($propNode) as $node) {
            $text = $single ? trim((string)$node) : (string)$node;
            if ($single && $text === '') {
                continue;
            }
            $values[] = ['value' => $text, 'description' => (string)$node['description']];
        }

        return $values;
    }

    /** @return list<array{value: int, description: string}> ID вариантов списка */
    private function resolveList(SimpleXMLElement $propNode, string $code, int $propertyId, ImportReport $report): array
    {
        $enumMap = $this->source->getEnumMap($propertyId);
        $values = [];
        foreach (self::readValues($propNode) as $v) {
            if (isset($enumMap[$v['value']])) {
                $values[] = ['value' => $enumMap[$v['value']], 'description' => ''];
            } else {
                $report->addWarning(Loc::getMessage('IBX_RESOLVER_NO_ENUM', ['#CODE#' => $code, '#VALUE#' => $v['value']]));
            }
        }

        return $values;
    }

    /** @return list<array{value: array, description: string}> подготовленные файловые значения (в порядке следования в XML) */
    private function resolveFiles(SimpleXMLElement $propNode, string $code, string $tmpDir, ImportReport $report): array
    {
        $files = [];
        foreach ($propNode->file as $fileNode) {
            $fileRef = (string)$fileNode['file_ref'];
            if ($fileRef === '') {
                continue; // файлы не выгружались либо исходного файла не было
            }
            if (!ArchiveFileRef::isValid($fileRef)) {
                $report->addWarning(Loc::getMessage('IBX_RESOLVER_BAD_FILE_REF', ['#CODE#' => $code, '#REF#' => $fileRef]));
                continue;
            }
            $absPath = ArchiveFileRef::path($tmpDir, $fileRef);
            if (!is_file($absPath)) {
                $report->addWarning(Loc::getMessage('IBX_RESOLVER_NO_FILE', ['#CODE#' => $code]));
                continue;
            }
            $fileArr = $this->files->make($absPath);
            if ($fileArr) {
                $description = (string)$fileNode['description'];
                if ($description !== '') {
                    $fileArr['description'] = $description;
                }
                $files[] = ['value' => $fileArr, 'description' => $description];
            }
        }

        return $files;
    }

    /** @return list<array{value: int, description: string}> ID связанных записей в целевой инсталляции */
    private function resolveLinks(SimpleXMLElement $propNode, string $code, string $type, int $linkIblockId, bool $matchByXmlId, ImportReport $report): array
    {
        $kind = $type === 'G' ? ExistingRecordFinderInterface::KIND_SECTION : ExistingRecordFinderInterface::KIND_ELEMENT;
        $values = [];
        foreach (self::valueNodes($propNode) as $node) {
            $refCode = trim((string)$node['ref_code']);
            $refXmlId = trim((string)$node['ref_xml_id']);
            if ($refCode === '' && $refXmlId === '') {
                // архив прежнего формата (в нём только ID записи исходной инсталляции) либо связанная запись не найдена при выгрузке
                $report->addWarning(Loc::getMessage('IBX_RESOLVER_LINK_NO_KEY', ['#CODE#' => $code]));
                continue;
            }
            if ($this->finder === null || $linkIblockId <= 0) {
                $report->addWarning(Loc::getMessage('IBX_RESOLVER_LINK_NO_IBLOCK', ['#CODE#' => $code]));
                continue;
            }

            $match = AbstractNodeImporter::matchFilter($refCode, $refXmlId, $matchByXmlId);
            if ($match === null) {
                $report->addWarning(Loc::getMessage('IBX_RESOLVER_LINK_XML_ID_OFF', ['#CODE#' => $code, '#XML_ID#' => $refXmlId]));
                continue;
            }

            $found = $this->finder->find($kind, $linkIblockId, $match);
            $key = isset($match['CODE']) ? 'CODE ' . $match['CODE'] : 'XML_ID ' . $match['XML_ID'];
            if (!$found) {
                $report->addWarning(Loc::getMessage('IBX_RESOLVER_LINK_NOT_FOUND', ['#CODE#' => $code, '#KEY#' => $key, '#IBLOCK_ID#' => $linkIblockId]));
                continue;
            }
            if (isset($match['XML_ID']) && count($found) > 1) {
                $report->addWarning(Loc::getMessage('IBX_RESOLVER_LINK_AMBIGUOUS', ['#CODE#' => $code, '#KEY#' => $key]));
                continue;
            }

            $values[] = ['value' => $found[0]['id'], 'description' => (string)$node['description']];
        }

        return $values;
    }
}
