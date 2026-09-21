<?php

namespace Vspace\Ibexport\Import;

use SimpleXMLElement;

/**
 * Предварительный просмотр импорта: список записей из export.xml в порядке их
 * обработки (раздел -> его элементы -> подразделы) с ключами сопоставления и тем,
 * что с ними случится в целевом инфоблоке (создать / обновить / пропустить). Ничего
 * не пишет; логика выбора ключа и действия та же, что в SectionImporter/ElementImporter.
 */
final class ImportPreview
{
    public const ACTION_CREATE = 'create';
    public const ACTION_UPDATE = 'update';
    public const ACTION_SKIP = 'skip';

    public const DEFAULT_LIMIT = 200;

    /** @var array[] */
    private array $rows = [];
    private bool $truncated = false;
    private int $limit = self::DEFAULT_LIMIT;

    public function __construct(private ExistingRecordFinderInterface $finder)
    {
    }

    /**
     * @param SimpleXMLElement $export корень export.xml
     * @param string $mode element | section_single | section_tree
     * @param int $limit Сколько строк вернуть (поиск в БД — по одному запросу на строку, поэтому список ограничен)
     * @return array{rows: array<int, array{
     *     kind: string, src_id: int, xml_id: string, code: string, name: string, active: bool, context: string,
     *     files: int, props: int, match_by: string|null, target_id: int|null, action: string
     * }>, truncated: bool}
     */
    public function build(SimpleXMLElement $export, string $mode, ImportContext $ctx, int $limit = self::DEFAULT_LIMIT): array
    {
        $this->rows = [];
        $this->truncated = false;
        $this->limit = $limit;

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

        return ['rows' => $this->rows, 'truncated' => $this->truncated];
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
        $match = AbstractNodeImporter::matchFilter($code, $xmlId, $ctx->matchByXmlId);
        $targetId = $match === null ? null : $this->finder->findId($kind, $ctx->iblockId, $match);

        if ($targetId === null) {
            $action = self::ACTION_CREATE;
        } else {
            $action = $ctx->updateByCode ? self::ACTION_UPDATE : self::ACTION_SKIP;
        }

        if ($kind === ExistingRecordFinderInterface::KIND_SECTION) {
            $files = (string)$node->picture['file_ref'] !== '' ? 1 : 0; // xpath по разделу захватил бы и вложенные
        } else {
            $files = count($node->xpath('.//@file_ref') ?: []);
        }

        $this->rows[] = [
            'kind' => $kind,
            'src_id' => (int)$node['id'],
            'xml_id' => $xmlId,
            'code' => $code,
            'name' => (string)$node->name,
            'active' => (string)$node['active'] !== 'N',
            'context' => $context,
            'files' => $files,
            'props' => isset($node->properties->property) ? count($node->properties->property) : 0,
            'match_by' => $match === null ? null : (string)array_key_first($match),
            'target_id' => $targetId,
            'action' => $action,
        ];

        return true;
    }
}
