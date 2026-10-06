(function () {
    'use strict';

    // Внешняя полоса теперь резиновая (width:100%; max-width:400px — см.
    // progress.php), поэтому вложенный слой с текстом ("adm-progress-bar-
    // inner-text"), который по трюку ядра должен быть шириной со ВСЮ
    // полосу целиком (а не с закрашенную часть), нельзя задать фиксированным
    // числом в вёрстке — меряем реальную ширину полосы в пикселях и
    // подставляем её сюда. Пересчитываем и при изменении размера окна.
    function syncBarWidth() {
        var outerEl = document.getElementById('vibx-bar-outer');
        var textWrapEl = document.getElementById('vibx-bar-inner-text-wrap');
        if (outerEl && textWrapEl) {
            textWrapEl.style.width = outerEl.offsetWidth + 'px';
        }
    }

    function poll() {
        var cfg = window.vspaceIbexportProgress;
        if (!cfg) {
            return;
        }

        var xhr = new XMLHttpRequest();
        xhr.open('POST', cfg.pollUrl, true);
        xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
        xhr.onload = function () {
            var data;
            try {
                data = JSON.parse(xhr.responseText);
            } catch (e) {
                setTimeout(poll, 3000);
                return;
            }
            render(data, cfg);

            if (data.status === 'DONE' || data.status === 'ERROR') {
                return;
            }
            setTimeout(poll, 1500);
        };
        xhr.onerror = function () {
            setTimeout(poll, 5000);
        };
        xhr.send('AJAX=Y&JOB_ID=' + encodeURIComponent(cfg.jobId) + '&sessid=' + encodeURIComponent(cfg.sessid));
    }

    function escapeHtml(s) {
        return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
            return {'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'}[c];
        });
    }

    function render(data, cfg) {
        var statusEl = document.getElementById('vibx-status');
        var barInnerEl = document.getElementById('vibx-bar-inner');
        var textInnerEl = document.getElementById('vibx-bar-text-inner');
        var textOuterEl = document.getElementById('vibx-bar-text-outer');
        var sectionsEl = document.getElementById('vibx-sections');
        var elementsEl = document.getElementById('vibx-elements');
        var resultEl = document.getElementById('vibx-result');
        var downloadEl = document.getElementById('vibx-download');
        // Кнопки возврата на странице прогресса импорта (import_progress.php): скрыты, пока задание выполняется.
        var finishButtonsEl = document.getElementById('vibx-finish-buttons');
        if (finishButtonsEl && (data.status === 'DONE' || data.status === 'ERROR')) {
            finishButtonsEl.style.visibility = '';
        }

        var percent = data.progress || 0;
        var percentText = percent + '%';

        if (statusEl) statusEl.textContent = (cfg.messages.statusLabels && cfg.messages.statusLabels[data.status]) || data.status;
        if (barInnerEl) barInnerEl.style.width = percent + '%';
        if (textInnerEl) textInnerEl.textContent = percentText;
        if (textOuterEl) textOuterEl.textContent = percentText;
        if (sectionsEl) sectionsEl.textContent = (data.processed_sections || 0) + ' / ' + (data.total_sections || 0);
        if (elementsEl) elementsEl.textContent = (data.processed_elements || 0) + ' / ' + (data.total_elements || 0);

        // Разметка сообщения повторяет структуру ядрового CAdminMessage
        // (bitrix/modules/main/interface/admin_lib.php, класс CAdminMessage::Show):
        // "adm-info-message-wrap adm-info-message-<color>" снаружи и
        // "adm-info-message" + "adm-info-message-icon" внутри — только при
        // такой вложенности тема админки рисует цветную рамку/фон/иконку.
        // Кнопка скачивания (страница прогресса экспорта) уходит в штатную
        // область кнопок панели (#vibx-download, см. progress.php), а не в
        // отдельный блок под сообщением; ссылка-кнопка всегда с базовым
        // классом "adm-btn" + цветовым модификатором. На странице прогресса
        // импорта скачивать нечего — вместо кнопки в то же сообщение
        // добавляется сводка "создано/обновлено/пропущено" (cfg.messages.summary
        // задаётся только там, см. import_progress.php).
        if (data.status === 'DONE') {
            if (resultEl) {
                var doneText = cfg.messages.done;
                if (cfg.messages.summary && typeof data.created_count !== 'undefined') {
                    doneText += ' ' + cfg.messages.summary
                        .replace('#CREATED#', data.created_count)
                        .replace('#UPDATED#', data.updated_count)
                        .replace('#SKIPPED#', data.skipped_count);
                }
                // Выгрузка архива на Яндекс.Диск сразу после экспорта (data.disk_upload, страница прогресса экспорта):
                // выгружен / выгружается — в том же сообщении, ошибка — отдельным красным блоком (см. progress.php).
                var disk = data.disk_upload;
                var diskErrorHtml = '';
                if (disk && cfg.messages.diskDone) {
                    if (disk.status === 'DONE') {
                        doneText += ' ' + cfg.messages.diskDone.replace('#PATH#', escapeHtml(disk.disk_path));
                    } else if (disk.status === 'RUNNING') {
                        doneText += ' ' + cfg.messages.diskRunning;
                    } else if (disk.status === 'ERROR') {
                        diskErrorHtml = '<div class="adm-info-message-wrap adm-info-message-red"><div class="adm-info-message">'
                            + '<div class="adm-info-message-title">' + cfg.messages.diskError.replace('#MESSAGE#', escapeHtml(disk.message)) + '</div>'
                            + '<div class="adm-info-message-icon"></div>'
                            + '</div></div>';
                    }
                }
                resultEl.innerHTML = '<div class="adm-info-message-wrap adm-info-message-green"><div class="adm-info-message">'
                    + '<div class="adm-info-message-title">' + doneText + '</div>'
                    + '<div class="adm-info-message-icon"></div>'
                    + '</div></div>' + diskErrorHtml;
            }
            if (downloadEl && data.download_url) {
                var downloadHtml = '<a class="adm-btn adm-btn-save" href="' + data.download_url + '">' + cfg.messages.download + '</a>';
                if (cfg.yandexDiskEnabled) {
                    // Та же форма, что и в серверной разметке уже
                    // завершённого задания (см. progress.php) — иначе
                    // кнопка появится только после перезагрузки страницы.
                    downloadHtml += ' <form method="post" action="/bitrix/admin/vspace_ibexport_yandex_disk_upload.php" style="display:inline;">'
                        + '<input type="hidden" name="sessid" value="' + cfg.sessid + '">'
                        + '<input type="hidden" name="lang" value="' + cfg.lang + '">'
                        + '<input type="hidden" name="JOB_ID" value="' + cfg.jobId + '">'
                        + '<input type="submit" class="adm-btn" value="' + cfg.messages.yandexUpload + '">'
                        + '</form>';
                }
                downloadEl.innerHTML = downloadHtml;
                downloadEl.style.visibility = '';
            }
        } else if (data.status === 'ERROR') {
            if (resultEl) {
                resultEl.innerHTML = '<div class="adm-info-message-wrap adm-info-message-red"><div class="adm-info-message">'
                    + '<div class="adm-info-message-title">' + cfg.messages.error + ': ' + (data.error_message || '') + '</div>'
                    + '<div class="adm-info-message-icon"></div>'
                    + '</div></div>';
            }
        }
    }

    document.addEventListener('DOMContentLoaded', function () {
        syncBarWidth();
        poll();
    });

    var resizeTimer = null;
    window.addEventListener('resize', function () {
        clearTimeout(resizeTimer);
        resizeTimer = setTimeout(syncBarWidth, 150);
    });
})();
