<?php

namespace Vspace\Ibexport;

/** Итог одного вызова SectionTreeWalker::walk() (экспорта и импорта): обновлённый стек и сколько узлов обработано за этот вызов. */
final class WalkResult
{
    /** @param array[] $stack */
    public function __construct(
        public readonly array $stack,
        public readonly int $processedSections,
        public readonly int $processedElements
    ) {
    }

    /** Стек пуст — обход завершён; иначе нужно продолжить в следующем тике. */
    public function isFinished(): bool
    {
        return empty($this->stack);
    }
}
