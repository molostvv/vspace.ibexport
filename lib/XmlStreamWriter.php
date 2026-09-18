<?php

namespace Vspace\Ibexport;

/**
 * Минимальный помощник для записи корректного XML прямо в открытый файловый
 * дескриптор. Сознательно не используется \XMLWriter: его внутреннее
 * состояние нельзя сериализовать и возобновить между отдельными тиками
 * агента (см. docs/xml-format.md, "Почему не XMLWriter"), тогда как обычный
 * fwrite() заранее экранированных строк возобновляется тривиально — тик
 * просто открывает файл на дозапись и продолжает с того места, где
 * остановился, а позиция хранится в Job::STATE_JSON.
 */
class XmlStreamWriter
{
    /** @var resource */
    private $handle;
    private int $indent = 0;

    public function __construct($handle, int $indent = 0)
    {
        $this->handle = $handle;
        $this->indent = $indent;
    }

    public function getIndent(): int
    {
        return $this->indent;
    }

    public function raw(string $data): void
    {
        fwrite($this->handle, $data);
    }

    public function line(string $data): void
    {
        fwrite($this->handle, str_repeat('  ', $this->indent) . $data . "\n");
    }

    public function openTag(string $name, array $attrs = [], bool $selfClose = false): void
    {
        $this->line('<' . $name . self::attrsToString($attrs) . ($selfClose ? '/>' : '>'));
        if (!$selfClose) {
            $this->indent++;
        }
    }

    public function closeTag(string $name): void
    {
        $this->indent = max(0, $this->indent - 1);
        $this->line('</' . $name . '>');
    }

    public function textTag(string $name, ?string $value, array $attrs = [], bool $cdata = false): void
    {
        $value = (string)$value;
        if ($value === '') {
            $this->line('<' . $name . self::attrsToString($attrs) . '/>');
            return;
        }

        $body = $cdata ? self::wrapCdata($value) : self::escapeText($value);
        $this->line('<' . $name . self::attrsToString($attrs) . '>' . $body . '</' . $name . '>');
    }

    public static function attrsToString(array $attrs): string
    {
        $out = '';
        foreach ($attrs as $key => $value) {
            if ($value === null) {
                continue;
            }
            $out .= ' ' . $key . '="' . self::escapeAttr((string)$value) . '"';
        }
        return $out;
    }

    public static function escapeAttr(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }

    public static function escapeText(string $value): string
    {
        return htmlspecialchars($value, ENT_NOQUOTES | ENT_XML1, 'UTF-8');
    }

    public static function wrapCdata(string $value): string
    {
        // Буквальное "]]>" преждевременно закрыло бы секцию CDATA; разбиваем
        // его на два соседних блока CDATA — это стандартный способ экранирования.
        $safe = str_replace(']]>', ']]]]><![CDATA[>', $value);
        return '<![CDATA[' . $safe . ']]>';
    }
}
