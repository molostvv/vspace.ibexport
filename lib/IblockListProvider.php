<?php

namespace Vspace\Ibexport;

/**
 * Список инфоблоков для выпадающего списка на страницах экспорта/импорта:
 * активные инфоблоки, из которых текущему пользователю разрешена операция
 * (право проверяет вызывающий — экспорт и импорт проверяют разные права).
 */
final class IblockListProvider
{
    /**
     * @param callable(int): bool $rightsCheck Принимает ID инфоблока, возвращает true, если операция разрешена
     * @return array[] Строки CIBlock::GetList() (ID, NAME, ...), отсортированные по SORT
     */
    public static function getAvailable(callable $rightsCheck): array
    {
        $list = [];
        $res = \CIBlock::GetList(['SORT' => 'ASC'], ['ACTIVE' => 'Y']);
        while ($ib = $res->Fetch()) {
            if ($rightsCheck((int)$ib['ID'])) {
                $list[] = $ib;
            }
        }

        return $list;
    }
}
