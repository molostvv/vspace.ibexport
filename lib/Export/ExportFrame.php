<?php

namespace Vspace\Ibexport\Export;

use Vspace\Ibexport\TraversalFrame;

/** Кадр обхода при экспорте: раздел определяется своим ID, элементы читаются страницами SQL-выборки. */
final class ExportFrame extends TraversalFrame
{
    public function __construct(public int $sectionId)
    {
    }

    /** Смещение следующей страницы элементов раздела. */
    public int $elementsOffset = 0;

    /** Тег <elements> уже открыт (не открывать повторно при возобновлении). */
    public bool $elementsTagOpen = false;

    /**
     * ID подразделов текущей страницы (childrenIndex — позиция в ней); null — страница ещё не выбрана (в отличие
     * от пустого массива — выбрана, подразделов больше нет). В STATE_JSON хранится только страница, а не все
     * подразделы: их может быть сколько угодно, а колонка — TEXT.
     */
    public ?array $childrenIds = null;

    /** Смещение текущей страницы подразделов. В STATE_JSON до 1.1.0 ключа нет: там childrenIds — все подразделы с нуля. */
    public int $childrenOffset = 0;

    /** Тег <sections> уже открыт (пишется только если у раздела есть подразделы). */
    public bool $sectionsTagOpen = false;

    /**
     * Порядок ключей — как в STATE_JSON исторически (json_encode сохраняет порядок); новые ключи — в конце,
     * поэтому состояние прежних версий читается как есть.
     */
    public function toArray(): array
    {
        return [
            'section_id' => $this->sectionId,
            'opened' => $this->opened,
            'phase' => $this->phase,
            'elements_offset' => $this->elementsOffset,
            'elements_tag_open' => $this->elementsTagOpen,
            'children_ids' => $this->childrenIds,
            'children_index' => $this->childrenIndex,
            'sections_tag_open' => $this->sectionsTagOpen,
            'children_offset' => $this->childrenOffset,
        ];
    }

    public static function fromArray(array $data): static
    {
        $frame = new self((int)$data['section_id']);
        $frame->opened = !empty($data['opened']);
        $frame->phase = (string)($data['phase'] ?? self::PHASE_ELEMENTS);
        $frame->elementsOffset = (int)($data['elements_offset'] ?? 0);
        $frame->elementsTagOpen = !empty($data['elements_tag_open']);
        $frame->childrenIds = isset($data['children_ids']) ? array_map('intval', $data['children_ids']) : null;
        $frame->childrenIndex = (int)($data['children_index'] ?? 0);
        $frame->sectionsTagOpen = !empty($data['sections_tag_open']);
        $frame->childrenOffset = (int)($data['children_offset'] ?? 0);

        return $frame;
    }
}
