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

    /** ID подразделов; null — ещё не выбраны (в отличие от пустого массива — выбраны, подразделов нет). */
    public ?array $childrenIds = null;

    /** Тег <sections> уже открыт (пишется только если у раздела есть подразделы). */
    public bool $sectionsTagOpen = false;

    /**
     * Порядок ключей — как в STATE_JSON исторически (json_encode сохраняет
     * порядок), чтобы состояние, записанное после рефакторинга, совпадало
     * с прежним байт-в-байт.
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

        return $frame;
    }
}
