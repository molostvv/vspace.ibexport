<?php

namespace Vspace\Ibexport\Tests\Export\Fake;

use Vspace\Ibexport\Export\ElementWriter;
use Vspace\Ibexport\XmlStreamWriter;

/** Пишет упрощённый узел элемента без обращения к ядру Bitrix. */
final class RecordingElementWriter extends ElementWriter
{
    /** @var int[] */
    public array $written = [];

    public function __construct()
    {
        // родительский конструктор требует FileRefWriter и колбэк предупреждений, которые тут не нужны
    }

    public function writeRow(XmlStreamWriter $w, int $elementId, bool $withSections = false): void
    {
        $this->written[] = $elementId;
        $w->openTag('element', ['id' => $elementId], true);
    }
}
