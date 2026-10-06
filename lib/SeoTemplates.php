<?php

namespace Vspace\Ibexport;

use Bitrix\Iblock\InheritedPropertyTable;
use SimpleXMLElement;

/**
 * SEO-шаблоны записи — вкладка «SEO» формы элемента/раздела (мета-теги, заголовок страницы, alt/title/имя файла
 * картинок; таблица b_iblock_iproperty). Переносятся только собственные шаблоны записи: унаследованные от инфоблока
 * или раздела-родителя приёмник вычисляет из своих настроек. У раздела собственные шаблоны включают и ELEMENT_* —
 * шаблоны для элементов этого раздела.
 *
 * Макросы шаблона ({=this.Name}, {=this.property.CODE}) ссылаются на поля и коды свойств, а не на ID, поэтому шаблон
 * переносится как есть. Запись — штатным ключом IPROPERTY_TEMPLATES в Add()/Update(): ядро передаёт его в
 * InheritedProperty\*Templates::set(), где пустая строка удаляет собственный шаблон, а не переданный код не меняется.
 */
final class SeoTemplates
{
    public const ENTITY_ELEMENT = 'E';
    public const ENTITY_SECTION = 'S';

    /** Длина колонки CODE в b_iblock_iproperty. */
    private const MAX_CODE_LENGTH = 50;

    /** @return array<string, string> собственные шаблоны записи (код => шаблон) в порядке кодов — архив не зависит от порядка в БД */
    public static function load(int $iblockId, string $entityType, int $entityId): array
    {
        $rows = InheritedPropertyTable::getList([
            'select' => ['CODE', 'TEMPLATE'],
            'filter' => ['=IBLOCK_ID' => $iblockId, '=ENTITY_TYPE' => $entityType, '=ENTITY_ID' => $entityId],
            'order' => ['CODE' => 'ASC'],
        ]);
        $templates = [];
        while ($row = $rows->fetch()) {
            $templates[(string)$row['CODE']] = (string)$row['TEMPLATE'];
        }

        return $templates;
    }

    /** <seo> пишется всегда, и пустым: так импорт отличает «своих шаблонов в источнике нет» от архива без этого блока. */
    public static function write(XmlStreamWriter $w, array $templates): void
    {
        if (!$templates) {
            $w->openTag('seo', [], true);
            return;
        }

        $w->openTag('seo');
        foreach ($templates as $code => $template) {
            $w->textTag('template', $template, ['code' => $code]);
        }
        $w->closeTag('seo');
    }

    /**
     * Шаблоны из <seo> узла записи. Пустой шаблон не возвращается (в Bitrix его нет — значение наследуется).
     *
     * @return array{templates: array<string, string>, rejected: string[]}|null null — блока <seo> нет (архив до
     *         версии модуля 1.2.0): SEO-шаблоны записи на приёмнике не трогаются
     */
    public static function parse(SimpleXMLElement $node, string $entityType): ?array
    {
        if (!isset($node->seo)) {
            return null;
        }

        $templates = [];
        $rejected = [];
        foreach ($node->seo->template ?? [] as $template) {
            $code = (string)$template['code'];
            if (!self::isValidCode($code, $entityType)) {
                $rejected[] = $code;
                continue;
            }
            $value = (string)$template;
            if ($value !== '') {
                $templates[$code] = $value;
            }
        }

        return ['templates' => $templates, 'rejected' => $rejected];
    }

    /** Код шаблона: у элемента — ELEMENT_*, у раздела — SECTION_* и ELEMENT_* (шаблоны для его элементов). */
    public static function isValidCode(string $code, string $entityType): bool
    {
        $prefix = $entityType === self::ENTITY_SECTION ? '(SECTION|ELEMENT)' : 'ELEMENT';

        return strlen($code) <= self::MAX_CODE_LENGTH && preg_match('/^' . $prefix . '_[A-Z0-9_]+$/', $code) === 1;
    }

    /**
     * Значение IPROPERTY_TEMPLATES: шаблоны из архива и пустая строка для собственных шаблонов записи на приёмнике,
     * которых в архиве нет, — ядро их удалит, и значение снова наследуется, как в источнике.
     *
     * @param array<string, string> $archive шаблоны из архива (parse())
     * @param string[] $currentCodes коды собственных шаблонов записи на приёмнике (у новой записи — [])
     * @return array<string, string>
     */
    public static function forSave(array $archive, array $currentCodes): array
    {
        foreach ($currentCodes as $code) {
            $archive += [$code => ''];
        }

        return $archive;
    }
}
