<?php

namespace Vspace\Ibexport\Import;

use SimpleXMLElement;

/**
 * Предварительный просмотр импорта: список записей из export.xml в порядке их
 * обработки (раздел -> его элементы -> подразделы) с ключами сопоставления и тем,
 * что с ними случится в целевом инфоблоке (создать / обновить / пропустить). Ничего
 * не пишет; логика выбора ключа и действия та же, что в SectionImporter/ElementImporter.
 *
 * Совпадения по XML_ID — самые ненадёжные, поэтому для них строка помечается флагом:
 *  - FLAG_AMBIGUOUS — в инфоблоке несколько записей с таким XML_ID (импорт такую запись пропустит);
 *  - FLAG_NAME_MISMATCH — название найденной записи отличается от названия в архиве.
 *
 * Отдельно считаются свойства элементов и UF-поля разделов из архива, которых нет в целевом
 * инфоблоке: их значения импорт пропустит (с предупреждением).
 */
final class ImportPreview
{
    public const ACTION_CREATE = 'create';
    public const ACTION_UPDATE = 'update';
    public const ACTION_SKIP = 'skip';

    public const FLAG_AMBIGUOUS = 'ambiguous';
    public const FLAG_NAME_MISMATCH = 'name_mismatch';

    public const DEFAULT_LIMIT = 200;

    /** @var array[] */
    private array $rows = [];
    private bool $truncated = false;
    private int $limit = self::DEFAULT_LIMIT;

    /** @var array<string, int> код свойства элемента => у скольких показанных элементов его нет в целевом инфоблоке */
    private array $missingProps = [];

    /** @var array<string, int> код UF-поля => у скольких показанных разделов его нет в целевом инфоблоке */
    private array $missingUserFields = [];

    public function __construct(private ExistingRecordFinderInterface $finder, private PropertySourceInterface $properties)
    {
    }

    /**
     * @param SimpleXMLElement $export корень export.xml
     * @param string $mode element | section_single | section_tree
     * @param int $limit Сколько строк вернуть (поиск в БД — по одному запросу на строку, поэтому список ограничен)
     * @return array{rows: array<int, array{
     *     kind: string, src_id: int, xml_id: string, code: string, name: string, active: bool, context: string,
     *     files: int, props: int, match_by: string|null, target_id: int|null, target_name: string|null,
     *     flags: string[], missing: string[], action: string
     * }>, truncated: bool, missing_props: array<string, int>, missing_uf: array<string, int>}
     */
    public function build(SimpleXMLElement $export, string $mode, ImportContext $ctx, int $limit = self::DEFAULT_LIMIT): array
    {
        $this->rows = [];
        $this->truncated = false;
        $this->limit = $limit;
        $this->missingProps = [];
        $this->missingUserFields = [];

        if ($mode === 'element') {
            foreach ($export->element as $element) {
                $paths = [];
                foreach ($element->sections->section ?? [] as $sectionRef) {
                    $paths[] = trim((string)$sectionRef['path']) ?: trim((string)$sectionRef['code']);
                }
                if (!$this->add(ExistingRecordFinderInterface::KIND_ELEMENT, $element, implode('; ', array_filter($paths)), $ctx)) {
                    break;
                }
            }
        } elseif (isset($export->section)) {
            $this->walkSection($export->section, [], $mode === 'section_tree', $ctx);
        }

        return [
            'rows' => $this->rows,
            'truncated' => $this->truncated,
            'missing_props' => $this->missingProps,
            'missing_uf' => $this->missingUserFields,
        ];
    }

    /** Названия считаются одинаковыми без учёта регистра и лишних пробелов. */
    public static function sameName(string $a, string $b): bool
    {
        $normalize = static fn(string $s): string => mb_strtolower(trim((string)preg_replace('/\s+/u', ' ', $s)));

        return $normalize($a) === $normalize($b);
    }

    /** @param string[] $parentNames Названия разделов-предков в архиве */
    private function walkSection(SimpleXMLElement $section, array $parentNames, bool $recursive, ImportContext $ctx): bool
    {
        if (!$this->add(ExistingRecordFinderInterface::KIND_SECTION, $section, implode(' › ', $parentNames), $ctx)) {
            return false;
        }

        $names = array_merge($parentNames, [(string)$section->name]);
        foreach ($section->elements->element ?? [] as $element) {
            if (!$this->add(ExistingRecordFinderInterface::KIND_ELEMENT, $element, implode(' › ', $names), $ctx)) {
                return false;
            }
        }
        if ($recursive) {
            foreach ($section->sections->section ?? [] as $child) {
                if (!$this->walkSection($child, $names, true, $ctx)) {
                    return false;
                }
            }
        }

        return true;
    }

    /** @return bool false — лимит исчерпан, обход надо прекращать */
    private function add(string $kind, SimpleXMLElement $node, string $context, ImportContext $ctx): bool
    {
        if (count($this->rows) >= $this->limit) {
            $this->truncated = true;
            return false;
        }

        $code = trim((string)$node['code']);
        $xmlId = trim((string)$node['xml_id']);
        $name = (string)$node->name;
        $match = AbstractNodeImporter::matchFilter($code, $xmlId, $ctx->matchByXmlId);
        $found = $match === null ? [] : $this->finder->find($kind, $ctx->iblockId, $match);
        $target = $found[0] ?? null;

        // Как в AbstractNodeImporter::findMatch(): неоднозначность и сверка названия — только для XML_ID (по CODE поведение прежнее).
        $byXmlId = isset($match['XML_ID']);
        $ambiguous = $byXmlId && count($found) > 1;
        $flags = [];
        if ($ambiguous) {
            $flags[] = self::FLAG_AMBIGUOUS;
        } elseif ($byXmlId && $target !== null && !self::sameName($name, $target['name'])) {
            $flags[] = self::FLAG_NAME_MISMATCH;
        }

        if ($target === null) {
            $action = self::ACTION_CREATE;
        } elseif ($ambiguous) {
            $action = self::ACTION_SKIP;
        } else {
            $action = $ctx->updateByCode ? self::ACTION_UPDATE : self::ACTION_SKIP;
        }

        if ($kind === ExistingRecordFinderInterface::KIND_SECTION) {
            $files = (string)$node->picture['file_ref'] !== '' ? 1 : 0; // xpath по разделу захватил бы и вложенные
        } else {
            $files = count($node->xpath('.//@file_ref') ?: []);
        }

        $missing = $this->missingCodes($kind, $node, $ctx->iblockId);
        foreach ($missing as $missingCode) {
            if ($kind === ExistingRecordFinderInterface::KIND_SECTION) {
                $this->missingUserFields[$missingCode] = ($this->missingUserFields[$missingCode] ?? 0) + 1;
            } else {
                $this->missingProps[$missingCode] = ($this->missingProps[$missingCode] ?? 0) + 1;
            }
        }

        $this->rows[] = [
            'kind' => $kind,
            'src_id' => (int)$node['id'],
            'xml_id' => $xmlId,
            'code' => $code,
            'name' => $name,
            'active' => (string)$node['active'] !== 'N',
            'context' => $context,
            'files' => $files,
            'props' => isset($node->properties->property) ? count($node->properties->property) : 0,
            'match_by' => $match === null ? null : (string)array_key_first($match),
            'target_id' => $target['id'] ?? null,
            'target_name' => $target['name'] ?? null,
            'flags' => $flags,
            'missing' => $missing,
            'action' => $action,
        ];

        return true;
    }

    /**
     * Коды свойств элемента / UF-полей раздела из узла, которых нет в целевом инфоблоке
     * (то же, что пропустят PropertyResolver и SectionImporter).
     *
     * @return string[]
     */
    private function missingCodes(string $kind, SimpleXMLElement $node, int $iblockId): array
    {
        $codes = [];
        foreach ($node->properties->property ?? [] as $property) {
            $codes[] = (string)$property['code'];
        }
        if (!$codes) {
            return [];
        }

        if ($kind === ExistingRecordFinderInterface::KIND_SECTION) {
            $codes = array_filter($codes, static fn(string $code): bool => strpos($code, 'UF_') === 0);
            $known = $this->properties->getSectionUserFieldCodes($iblockId);
        } else {
            $known = array_keys($this->properties->getDefinitions($iblockId));
        }

        return array_values(array_unique(array_diff($codes, $known)));
    }
}
