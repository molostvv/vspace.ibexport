<?php

namespace Vspace\Ibexport\Import;

use Vspace\Ibexport\TraversalFrame;

/**
 * Кадр обхода при импорте: раздел определяется путём индексов от корневого
 * <section> распакованного XML (сам XML разбирается заново на каждый тик).
 */
final class ImportFrame extends TraversalFrame
{
    /** @param int[] $path Индексы дочерних <sections><section> от корня; пустой путь — сам корневой раздел */
    public function __construct(public array $path)
    {
    }

    /** ID раздела в целевом инфоблоке, созданного/найденного при открытии кадра (родитель для вложенных). */
    public ?int $targetId = null;

    /** Сколько элементов раздела уже импортировано. */
    public int $elementsIndex = 0;

    /** Порядок ключей — как исторически в STATE_JSON (см. ExportFrame::toArray()). */
    public function toArray(): array
    {
        return [
            'path' => $this->path,
            'target_id' => $this->targetId,
            'opened' => $this->opened,
            'phase' => $this->phase,
            'elements_index' => $this->elementsIndex,
            'children_index' => $this->childrenIndex,
        ];
    }

    public static function fromArray(array $data): static
    {
        $frame = new self(array_map('intval', $data['path'] ?? []));
        $frame->targetId = isset($data['target_id']) ? (int)$data['target_id'] : null;
        $frame->opened = !empty($data['opened']);
        $frame->phase = (string)($data['phase'] ?? self::PHASE_ELEMENTS);
        $frame->elementsIndex = (int)($data['elements_index'] ?? 0);
        $frame->childrenIndex = (int)($data['children_index'] ?? 0);

        return $frame;
    }
}
