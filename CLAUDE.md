# Проект: модули для 1С-Битрикс

Разработка модулей для Bitrix Framework / Управление сайтом. Ниже — обязательные
правила. Их нельзя обходить "для простоты" или "чтобы было быстрее".

## Главное правило

Если для задачи есть штатный механизм Битрикса — используется ТОЛЬКО он.
Своя вёрстка, свой CSS, свои формы вместо стандартных классов админки —
запрещены, даже если так "быстрее" или "проще".

Перед тем как писать код с нуля, агент обязан:
1. Проверить, есть ли в этом файле готовый паттерн под задачу.
2. Если паттерна нет — поискать пример в реальных файлах ядра
   (`/bitrix/modules/main/classes/general/`, `/bitrix/modules/<module>/`)
   и опереться на них, а не на память/предположения.
3. Если сомневается в сигнатуре класса или метода — сначала открыть исходник
   через инструмент чтения файлов, а не угадывать.

## Структура модуля

```
local/modules/<vendor>.<module>/
├── .description.php          # имя, версия модуля
├── include.php                # автоподключение классов
├── options.php                # страница настроек в админке
├── index.php                  # ! запрещать прямой доступ (define("B_PROLOG_INCLUDED"))
├── install/
│   ├── index.php               # класс установки (CModule)
│   ├── db/                     # install.sql, uninstall.sql
│   └── version.php
├── lib/                        # PSR-4 классы (Bitrix\Vendor\Module\...)
├── admin/                      # доп. страницы админки
└── lang/ru/
```

## Страница настроек модуля (options.php)

ТОЛЬКО через `CAdminTabControl`. Опции — ТОЛЬКО через `\Bitrix\Main\Config\Option`.
Никакого ручного `<form>` без обвязки Битрикса, никакого самодельного сохранения
через `$_POST` напрямую в базу.

```php
<?php
if (!$USER->IsAdmin()) {
    return;
}

\Bitrix\Main\Loader::includeModule("vendor.module");

$module_id = "vendor.module";
$request = \Bitrix\Main\Context::getCurrent()->getRequest();

$tabControl = new CAdminTabControl("tabControl", [
    ["DIV" => "edit1", "TAB" => "Настройки", "TITLE" => "Основные настройки"],
]);

if ($request->isPost() && check_bitrix_sessid()) {
    \Bitrix\Main\Config\Option::set($module_id, "SOME_PARAM", $request->getPost("SOME_PARAM"));
    LocalRedirect($APPLICATION->GetCurPage() . "?mid=" . urlencode($module_id) . "&lang=" . LANG);
}

$tabControl->Begin();
?>
<form method="post" action="<?= $APPLICATION->GetCurPage() ?>?mid=<?= $module_id ?>&lang=<?= LANG ?>">
    <?php $tabControl->BeginNextTab(); ?>
    <tr>
        <td>Параметр:</td>
        <td>
            <input type="text" name="SOME_PARAM"
                   value="<?= htmlspecialcharsbx(\Bitrix\Main\Config\Option::get($module_id, "SOME_PARAM")) ?>">
        </td>
    </tr>
    <?php
    $tabControl->Buttons();
    ?>
    <input type="submit" name="Update" value="Сохранить" class="adm-btn-save">
    <?= bitrix_sessid_post() ?>
    <?php
    $tabControl->End();
    ?>
</form>
```

## Списки в админке (таблицы данных)

Только `CAdminUiList` (новый интерфейс) или `CAdminList` (legacy, если проект
на старом UI). Не рисовать таблицы вручную через `<table>`.

## Работа с БД

Только ORM: `\Bitrix\Main\Entity\DataManager`, миграции через `install/db/*.sql`
или `Bitrix\Main\Application::getConnection()->query()`. Прямые `mysqli_query`
и сырые SQL без подготовленных выражений — запрещены.

## События

Регистрация через `RegisterModuleDependences` в `install/index.php`,
обработчики — методы статических классов, не глобальные функции в `include.php`.

## Формат ответов агента

- Если задача про UI админки — сначала явно указать, какой класс Битрикса
  используется (`CAdminTabControl`, `CAdminUiList`, `CAdminOptions` и т.п.),
  и только потом код.
- Если нужного стандартного механизма нет и вёрстка неизбежно своя —
  агент обязан сказать об этом прямо и объяснить почему, а не делать это молча.
- Крупные задачи ("сделай страницу настроек") бить на шаги: сначала вкладки,
  затем сохранение опций, затем валидация — с проверкой после каждого шага.

## Версия платформы

- Продукт: Bitrix Framework (ядро `main` 24.350.0, `iblock` 24.200.0).
- PHP: 8.1.9.
- API: смешанный — новые классы модуля пишутся на D7
  (`\Bitrix\Main\ORM\Data\DataManager`, `Bitrix\Iblock\ElementTable`/`SectionTable`,
  `\Bitrix\Main\EventManager`), но операции записи элементов/разделов идут
  через классический API (`CIBlockElement`, `CIBlockSection`,
  `CIBlockProperty`, `CFile`), так как D7-аналогов для записи данных
  инфоблоков с той же полнотой (свойства, файлы, множественные значения) в
  этой версии ядра нет либо они менее удобны. Используем D7 для чтения/схемы
  БД и событий, legacy API — для операций с элементами/разделами инфоблоков.
