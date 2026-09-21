# Формат выгрузки — техническое описание

Результат экспорта — ZIP-архив:

```
export.xml       — основной файл с данными
files/            — файлы (только если включена опция «Выгружать файлы»)
  <FILE_ID>_<исходное_имя>
```

## Корневой элемент

```xml
<?xml version="1.0" encoding="UTF-8"?>
<export date="2026-09-18T12:00:00+00:00" mode="element|section_single|section_tree" iblock_id="12">
  ...
</export>
```

- `mode="element"` — экспорт одного элемента (FR-1). Внутри `<export>` находится ровно один `<element>`.
- `mode="section_single"` — экспорт одного раздела без вложенных (FR-2). Внутри `<export>` — один `<section>`.
- `mode="section_tree"` — экспорт раздела со всей вложенной структурой (FR-3). Внутри `<export>` — один корневой `<section>`, рекурсивно содержащий дочерние `<section>` в `<sections>`.

## `<section>`

```xml
<section id="45" code="news" xml_id="45" active="Y" sort="100">
  <name>Новости</name>
  <description><![CDATA[...]]></description>
  <picture file_ref="files/301_cover.jpg" name="cover.jpg" size="12345" mime="image/jpeg"/>
  <properties>
    <property code="UF_SEO_TITLE">...</property>
    <property code="UF_TAGS" multiple="true"><value>...</value><value>...</value></property>
  </properties>

  <!-- <properties> — пользовательские поля раздела (UF_*): значение одиночного поля — текст узла, множественного — <value>
       на каждое. Выгружаются только поля с самодостаточным значением: string, integer, double, boolean, date, datetime, url.
       У файла, списка и привязки к элементу/разделу значение — ID записи этой инсталляции, поэтому такие поля не
       выгружаются (в журнал выгрузки пишется предупреждение). -->

  <!-- только в mode=section_tree: реальные дочерние разделы, рекурсивно -->
  <sections>
    <section id="46" code="news-2026" active="Y">...</section>
  </sections>

  <!-- только в mode=section_single: перечень прямых подразделов БЕЗ их содержимого -->
  <subsections note="not_included_see_mode">
    <section id="46" code="news-2026" active="Y"/>
  </subsections>

  <elements>
    <element>...</element>
  </elements>
</section>
```

`<sections>` (полные вложенные разделы) и `<subsections>` (только перечень ID/кодов) — два разных, взаимоисключающих тега, зависящие от `mode` в корне документа. Раздел без подразделов не содержит ни того, ни другого.

## `<element>`

```xml
<element id="501" code="news-item" xml_id="501" active="Y" sort="500">
  <name>Заголовок новости</name>
  <preview_text type="text"><![CDATA[...]]></preview_text>
  <detail_text type="html"><![CDATA[...]]></detail_text>
  <date_active_from>...</date_active_from>
  <date_active_to>...</date_active_to>
  <preview_picture file_ref="files/502_preview.jpg" name="preview.jpg" size="1111" mime="image/jpeg"/>
  <detail_picture .../>

  <!-- только при экспорте отдельного элемента (mode=element): все разделы, к которым он привязан -->
  <sections>
    <section id="45" code="news" path="Каталог &gt; Новости"/>
  </sections>

  <properties>
    <property code="AUTHOR" type="S">Иванов И.И.</property>
    <property code="TAGS" type="S" multiple="true">
      <value>тег1</value>
      <value>тег2</value>
    </property>
    <property code="GALLERY" type="F" multiple="true">
      <file file_ref="files/510_img1.jpg" name="img1.jpg" size="222" mime="image/jpeg"/>
    </property>
    <property code="EMPTY_PROP" type="S"/>
  </properties>
</element>
```

Внутри `<sections>` элемента, привязанного к разделу через `mode=section_single|section_tree`, этот блок не дублируется — принадлежность к обходимому дереву и так очевидна из положения `<element>` внутри `<section>`. Блок `<sections>` элемента пишется только при экспорте одиночного элемента (`mode=element`), где нужно явно перечислить все его разделы (FR-1: «привязку к разделу(ам) — идентификаторы и пути»).

## Файлы (`file_ref`)

- Если «Выгружать файлы» включена: `file_ref` указывает на файл внутри архива (`files/<ID>_<имя>`), плюс атрибуты `name`/`size`/`mime`.
- Если выключена: атрибут `file_ref` отсутствует, но `name`/`size`/`mime` сохраняются — раздел 6 ТЗ.
- Если файл был указан, но не найден на диске: `missing="Y" file_id="..."`, факт фиксируется как предупреждение в журнале задания, выгрузка не прерывается (раздел 9 ТЗ).

## Пустые значения

Текстовые поля с пустым значением выводятся как пустой (самозакрывающийся) тег, а не опускаются полностью:

```xml
<description/>
```

## Свойства элементов

Каждое свойство инфоблока — один `<property code="..." type="...">`:

- Непустое одиночное (не файл) — текстовое содержимое тега, возможно в CDATA для типа "Список" не требуется, а для строковых с HTML — как есть.
- Множественное или файловое — вложенные `<value>` (для типов кроме файла) или `<file>` (для типа `F`, `multiple="true"` или нет).
- Тип "Список" (`L`) — выводится отображаемое значение (`VALUE_ENUM`), а не внутренний ID варианта.
- Пустое свойство — самозакрывающийся `<property .../>`.

## Почему не `\XMLWriter`

Большие деревья разделов (раздел 8 ТЗ: возможна большая глубина и десятки тысяч элементов) экспортируются не за один HTTP-запрос, а серией ограниченных по времени «тиков» (см. `lib/Export/SectionTreeWalker.php`). Между тиками процесс PHP завершается, поэтому состояние обхода должно быть восстановимо из данных, а не из памяти. `\XMLWriter` не поддерживает сериализацию/восстановление внутреннего состояния между запросами, поэтому запись ведётся вручную построчным дозаписыванием (`fwrite` в режиме `a`) уже экранированных фрагментов, а точка возобновления (текущий путь в дереве, смещения постраничной выборки элементов, список ещё не пройденных дочерних разделов) сохраняется в поле `STATE_JSON` таблицы `vspace_ibexport_job` как явный стек кадров обхода в глубину (DFS). Подробности — комментарии в `Exporter::runSectionExport()`.

## Идемпотентность

Повторный запуск экспорта одной и той же сущности с одинаковыми параметрами даёт структурно идентичный `export.xml` (тот же набор узлов, свойств и ссылок на файлы) при неизменных исходных данных — единственное отличие между запусками, помимо самих данных, это атрибут `date` в корневом `<export>` и конкретные имена файлов в архиве (раздел 11 ТЗ, «Идемпотентность»).
