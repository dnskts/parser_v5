/**
 * ============================================================
 * ЛОГИКА ВЕБ-ИНТЕРФЕЙСА XML PARSER
 * ============================================================
 * 
 * Этот файл управляет поведением веб-страницы:
 * - Загрузка и отображение логов
 * - Автоматическое обновление логов каждые 3 секунды
 * - Ручной запуск обработки файлов
 * - Автообработка по таймеру (пока страница открыта)
 * - Сохранение настроек (интервал обработки)
 * - Импорт справочников из выгрузки 1С
 * - Очистка логов
 * 
 * Все запросы к серверу идут через AJAX (fetch API)
 * к файлу api.php с параметром action.
 * ============================================================
 */

// Ждём, пока страница полностью загрузится
document.addEventListener('DOMContentLoaded', function() {

    // -------------------------------------------------------
    // Получаем ссылки на элементы страницы
    // -------------------------------------------------------
    
    /** Поле ввода интервала (в секундах) */
    var intervalInput = document.getElementById('interval');
    
    /** Кнопка сохранения интервала */
    var btnSaveInterval = document.getElementById('btn-save-interval');
    
    /** Кнопка ручного запуска обработки */
    var btnRun = document.getElementById('btn-run');
    
    /** Кнопка включения/выключения автообработки */
    var btnToggleAuto = document.getElementById('btn-toggle-auto');
    
    /** Кнопка импорта справочников из выгрузки 1С */
    var btnImportRefs = document.getElementById('btn-import-refs');
    
    /** Кнопка очистки логов */
    var btnClearLogs = document.getElementById('btn-clear-logs');
    
    /** Кнопка обновления логов */
    var btnRefreshLogs = document.getElementById('btn-refresh-logs');
    
    /** Контейнеры журнала: события (лево) и служебные (право) */
    var logsMain = document.getElementById('logs-main');
    var logsService = document.getElementById('logs-service');
    
    /** Текст статуса (Ожидание / Обработка / Успех / Ошибка) */
    var statusText = document.getElementById('status-text');
    
    /** Статус автообработки (Авто: вкл / выкл) */
    var autoStatus = document.getElementById('auto-status');
    
    /** Текст с временем последнего запуска */
    var lastRunText = document.getElementById('last-run');

    // -------------------------------------------------------
    // Переменные состояния
    // -------------------------------------------------------
    
    /** Идентификатор таймера автообработки (null = выключен) */
    var autoTimer = null;
    
    /** Идентификатор таймера обновления логов */
    var logsTimer = null;
    
    /** Флаг: идёт ли обработка прямо сейчас (чтобы не запускать повторно) */
    var isRunning = false;
    
    /** AbortController для прерывания fetch при уходе со страницы */
    var runAbortController = null;

    /** Сколько строк уже загружено с конца файла (курсор истории) */
    var logsLoadedFromEnd = 0;
    var logsPageSize = 100;
    var logsHasMore = true;
    var logsLoadingOlder = false;
    var logsPollPaused = false;
    /** Последняя известная самая новая строка (для append при poll) */
    var logsNewestLine = '';
    var knownLogLines = {};

    // -------------------------------------------------------
    // ЗАГРУЗКА НАСТРОЕК ПРИ ОТКРЫТИИ СТРАНИЦЫ
    // -------------------------------------------------------
    
    /**
     * Загружает текущие настройки с сервера (интервал и время последнего запуска)
     * и обновляет элементы на странице.
     */
    function loadSettings() {
        fetch('api.php?action=settings')
            .then(function(response) { return response.json(); })
            .then(function(data) {
                if (data.status === 'ok' && data.settings) {
                    // Устанавливаем значение интервала в поле ввода
                    intervalInput.value = data.settings.interval || 60;
                    
                    // Показываем время последнего запуска
                    if (data.settings.last_run && data.settings.last_run > 0) {
                        var date = new Date(data.settings.last_run * 1000);
                        lastRunText.textContent = 'Последний запуск: ' + formatDate(date);
                    }
                    // Восстанавливаем автообработку после возврата на страницу (навигация сбрасывала таймер)
                    try {
                        if (localStorage.getItem('parser_auto_enabled') === '1') {
                            toggleAutoProcessing();
                        }
                    } catch (e) {}
                }
            })
            .catch(function(error) {
                console.error('Ошибка загрузки настроек:', error);
            });
    }

    // -------------------------------------------------------
    // ЗАГРУЗКА И ОТОБРАЖЕНИЕ ЛОГОВ
    // -------------------------------------------------------

    function isServiceLogLine(line) {
        var l = (line || '').toLowerCase();
        if (l.indexOf('нет новых файлов') !== -1) return true;
        if (l.indexOf('import_references') !== -1) return true;
        if (l.indexOf('referenceimporter') !== -1) return true;
        if (l.indexOf('references.last_import') !== -1) return true;
        if (l.indexOf('справочник') !== -1) return true;
        if (l.indexOf('parsermanager') !== -1) return true;
        if (l.indexOf('зарегистрирован') !== -1 && l.indexOf('парсер') !== -1) return true;
        if (l.indexOf('загружен') !== -1 && l.indexOf('парсер') !== -1) return true;
        if (l.indexOf('обнаружен парсер') !== -1) return true;
        return false;
    }

    function clearLogPanes() {
        logsMain.innerHTML = '';
        logsService.innerHTML = '';
        knownLogLines = {};
        logsNewestLine = '';
        logsLoadedFromEnd = 0;
        logsHasMore = true;
    }

    function showLogsEmpty(msg) {
        clearLogPanes();
        logsMain.innerHTML = '<p class="logs__placeholder">' + escapeHtml(msg) + '</p>';
        logsService.innerHTML = '<p class="logs__placeholder">' + escapeHtml(msg) + '</p>';
    }

    function createLogLineEl(line) {
        var div = document.createElement('div');
        div.className = 'log-line ' + getLogLineClass(line);
        div.textContent = line;
        div.setAttribute('data-line', line);
        return div;
    }

    function appendLogLine(line) {
        if (knownLogLines[line]) return false;
        knownLogLines[line] = 1;
        var el = createLogLineEl(line);
        var pane = isServiceLogLine(line) ? logsService : logsMain;
        var ph = pane.querySelector('.logs__placeholder');
        if (ph) pane.innerHTML = '';
        pane.appendChild(el);
        return true;
    }

    function prependLogLine(line) {
        if (knownLogLines[line]) return false;
        knownLogLines[line] = 1;
        var el = createLogLineEl(line);
        var pane = isServiceLogLine(line) ? logsService : logsMain;
        var ph = pane.querySelector('.logs__placeholder');
        if (ph) pane.innerHTML = '';
        if (pane.firstChild) {
            pane.insertBefore(el, pane.firstChild);
        } else {
            pane.appendChild(el);
        }
        return true;
    }

    function isNearBottom(el) {
        return (el.scrollHeight - el.scrollTop - el.clientHeight) < 40;
    }

    function scrollPanesToBottom() {
        logsMain.scrollTop = logsMain.scrollHeight;
        logsService.scrollTop = logsService.scrollHeight;
    }

    /**
     * Первичная загрузка / полный refresh: последние N строк.
     */
    function loadLogs(fullReset) {
        if (fullReset) {
            clearLogPanes();
        }
        var url = 'api.php?action=logs&offset=0&limit=' + logsPageSize;
        fetch(url)
            .then(function(response) { return response.json(); })
            .then(function(data) {
                if (data.status !== 'ok') return;
                if (!data.logs || data.logs.length === 0) {
                    if (logsLoadedFromEnd === 0) {
                        showLogsEmpty('Логи пусты');
                    }
                    return;
                }

                if (fullReset || logsLoadedFromEnd === 0) {
                    clearLogPanes();
                    for (var i = 0; i < data.logs.length; i++) {
                        appendLogLine(data.logs[i]);
                    }
                    logsLoadedFromEnd = data.logs.length;
                    logsHasMore = !!data.has_more;
                    logsNewestLine = data.logs[data.logs.length - 1] || '';
                    scrollPanesToBottom();
                    return;
                }

                // poll: append только новые строки в хвосте
                var stickMain = isNearBottom(logsMain);
                var stickService = isNearBottom(logsService);
                var added = 0;
                for (var j = 0; j < data.logs.length; j++) {
                    if (appendLogLine(data.logs[j])) {
                        added++;
                    }
                }
                if (data.logs.length) {
                    logsNewestLine = data.logs[data.logs.length - 1];
                }
                if (added > 0) {
                    if (stickMain) logsMain.scrollTop = logsMain.scrollHeight;
                    if (stickService) logsService.scrollTop = logsService.scrollHeight;
                }
            })
            .catch(function(error) {
                console.error('Ошибка загрузки логов:', error);
            });
    }

    function loadOlderLogs() {
        if (logsLoadingOlder || !logsHasMore) return;
        logsLoadingOlder = true;
        var prevMainHeight = logsMain.scrollHeight;
        var prevServiceHeight = logsService.scrollHeight;
        var url = 'api.php?action=logs&offset=' + logsLoadedFromEnd + '&limit=' + logsPageSize;
        fetch(url)
            .then(function(response) { return response.json(); })
            .then(function(data) {
                logsLoadingOlder = false;
                if (data.status !== 'ok' || !data.logs || data.logs.length === 0) {
                    logsHasMore = false;
                    return;
                }
                // data.logs chronological old→new; prepend from newest of chunk to oldest
                // so visual order stays chronological
                for (var i = data.logs.length - 1; i >= 0; i--) {
                    prependLogLine(data.logs[i]);
                }
                logsLoadedFromEnd += data.logs.length;
                logsHasMore = !!data.has_more;
                logsMain.scrollTop = logsMain.scrollHeight - prevMainHeight;
                logsService.scrollTop = logsService.scrollHeight - prevServiceHeight;
            })
            .catch(function(error) {
                logsLoadingOlder = false;
                console.error('Ошибка подгрузки логов:', error);
            });
    }

    function onLogScroll(ev) {
        var el = ev.target;
        if (el.scrollTop <= 8) {
            loadOlderLogs();
        }
    }

    /**
     * Определяет CSS-класс для строки лога по её содержимому.
     * 
     * @param {string} line — строка лога
     * @returns {string} — CSS-класс
     */
    function getLogLineClass(line) {
        if (line.indexOf('[ERROR]') !== -1)   return 'log-line--error';
        if (line.indexOf('[SUCCESS]') !== -1) return 'log-line--success';
        if (line.indexOf('[WARNING]') !== -1) return 'log-line--warning';
        if (line.indexOf('=====') !== -1)     return 'log-line--separator';
        return 'log-line--info';
    }

    /**
     * Экранирует HTML-символы в строке, чтобы избежать XSS-атак.
     * 
     * @param {string} text — исходный текст
     * @returns {string} — безопасный текст
     */
    function escapeHtml(text) {
        var div = document.createElement('div');
        div.appendChild(document.createTextNode(text));
        return div.innerHTML;
    }

    /**
     * Форматирует дату в удобный вид: "16.02.2026 14:30:00"
     * 
     * @param {Date} date — объект даты
     * @returns {string} — отформатированная строка
     */
    function formatDate(date) {
        var day = String(date.getDate()).padStart(2, '0');
        var month = String(date.getMonth() + 1).padStart(2, '0');
        var year = date.getFullYear();
        var hours = String(date.getHours()).padStart(2, '0');
        var minutes = String(date.getMinutes()).padStart(2, '0');
        var seconds = String(date.getSeconds()).padStart(2, '0');
        return day + '.' + month + '.' + year + ' ' + hours + ':' + minutes + ':' + seconds;
    }

    // -------------------------------------------------------
    // РУЧНОЙ ЗАПУСК ОБРАБОТКИ
    // -------------------------------------------------------
    
    /**
     * Отправляет запрос на сервер для запуска обработки файлов.
     * Во время обработки кнопка блокируется, статус меняется.
     */
    function runProcessing() {
        if (isRunning) return;

        isRunning = true;
        btnRun.disabled = true;
        setStatus('running', 'Обработка...');
        if (runAbortController) runAbortController.abort();
        runAbortController = new AbortController();

        fetch('api.php?action=run', { method: 'POST', signal: runAbortController.signal })
            .then(function(response) { return response.json(); })
            .then(function(data) {
                if (data.status === 'ok') {
                    var msg = 'Готово: ' + data.processed + ' обработано, ' + data.errors + ' ошибок';
                    if (data.sftp_status) {
                        msg = 'SFTP: ' + data.sftp_status + '. ' + msg;
                    }
                    setStatus('success', msg);
                    lastRunText.textContent = 'Последний запуск: ' + formatDate(new Date());
                } else {
                    setStatus('error', data.message || 'Ошибка обработки');
                }
                // Обновляем логи после обработки
                loadLogs();
            })
            .catch(function(error) {
                if (error.name === 'AbortError') return;
                setStatus('error', 'Ошибка связи с сервером');
                console.error('Ошибка запуска обработки:', error);
            })
            .finally(function() {
                isRunning = false;
                btnRun.disabled = false;
            });
    }

    /**
     * Обновляет статус на странице (текст и цвет значка).
     * 
     * @param {string} type — тип статуса (idle, running, success, error)
     * @param {string} text — текст для отображения
     */
    function setStatus(type, text) {
        statusText.textContent = text;
        statusText.className = 'status-badge status-badge--' + type;
    }

    // -------------------------------------------------------
    // АВТООБРАБОТКА ПО ТАЙМЕРУ
    // -------------------------------------------------------
    
    /**
     * Включает или выключает автоматическую обработку.
     * 
     * Когда автообработка включена, система будет запускать обработку
     * через заданный интервал (пока страница открыта в браузере).
     */
    function toggleAutoProcessing() {
        if (autoTimer) {
            // Выключаем автообработку
            clearInterval(autoTimer);
            autoTimer = null;
            try { localStorage.removeItem('parser_auto_enabled'); } catch (e) {}
            btnToggleAuto.textContent = '⏱ Вкл. автообработку';
            btnToggleAuto.classList.remove('btn--active');
            btnToggleAuto.classList.add('btn--outline');
            autoStatus.textContent = 'Авто: выкл';
            autoStatus.className = 'status-badge status-badge--off';
        } else {
            // Включаем автообработку
            var interval = parseInt(intervalInput.value, 10) || 60;
            try { localStorage.setItem('parser_auto_enabled', '1'); } catch (e) {}
            // Запускаем обработку сразу при включении
            runProcessing();
            
            // Устанавливаем таймер на повторный запуск через каждый интервал
            autoTimer = setInterval(function() {
                runProcessing();
            }, interval * 1000);

            btnToggleAuto.textContent = '⏱ Выкл. автообработку';
            btnToggleAuto.classList.remove('btn--outline');
            btnToggleAuto.classList.add('btn--active');
            autoStatus.textContent = 'Авто: вкл (' + interval + ' сек)';
            autoStatus.className = 'status-badge status-badge--on';
        }
    }

    // -------------------------------------------------------
    // СОХРАНЕНИЕ НАСТРОЕК
    // -------------------------------------------------------
    
    /**
     * Отправляет новое значение интервала на сервер.
     */
    function saveInterval() {
        var interval = parseInt(intervalInput.value, 10);

        if (isNaN(interval) || interval < 10) {
            alert('Минимальный интервал — 10 секунд');
            return;
        }
        if (interval > 86400) {
            alert('Максимальный интервал — 86400 секунд (24 часа)');
            return;
        }

        fetch('api.php?action=settings', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ interval: interval })
        })
        .then(function(response) { return response.json(); })
        .then(function(data) {
            if (data.status === 'ok') {
                setStatus('success', 'Интервал сохранён: ' + interval + ' сек');
                loadLogs();

                // Если автообработка включена — перезапускаем с новым интервалом
                if (autoTimer) {
                    clearInterval(autoTimer);
                    autoTimer = setInterval(function() {
                        runProcessing();
                    }, interval * 1000);
                    autoStatus.textContent = 'Авто: вкл (' + interval + ' сек)';
                }
            } else {
                setStatus('error', data.message || 'Ошибка сохранения');
            }
        })
        .catch(function(error) {
            setStatus('error', 'Ошибка связи с сервером');
            console.error('Ошибка сохранения настроек:', error);
        });
    }

    // -------------------------------------------------------
    // ИМПОРТ СПРАВОЧНИКОВ ИЗ ВЫГРУЗКИ 1С
    // -------------------------------------------------------
    
    /**
     * Читает файлы выгрузки 1С из references/import/ и обновляет
     * справочники references/*.json. После успеха сервер удаляет *.txt.
     */
    function importReferences() {
        btnImportRefs.disabled = true;
        setStatus('running', 'Загрузка справочников...');

        fetch('api.php?action=import_references', { method: 'POST' })
            .then(function(response) {
                return response.text().then(function(text) {
                    var data = null;
                    try {
                        data = text ? JSON.parse(text) : null;
                    } catch (e) {
                        data = null;
                    }
                    return { httpStatus: response.status, ok: response.ok, data: data, raw: text };
                });
            })
            .then(function(result) {
                if (!result.ok) {
                    var hint = '';
                    if (result.data && result.data.message) {
                        hint = result.data.message;
                    } else if (result.raw) {
                        hint = result.raw.replace(/\s+/g, ' ').substring(0, 180);
                    }
                    setStatus(
                        'error',
                        'Ошибка HTTP ' + result.httpStatus
                            + (hint ? ': ' + hint : ' (часто таймаут прокси на Контрагенты.txt)')
                    );
                    loadLogs();
                    return;
                }
                if (!result.data) {
                    setStatus('error', 'Сервер вернул не-JSON (HTTP ' + result.httpStatus + ')');
                    loadLogs();
                    return;
                }
                if (result.data.status === 'ok') {
                    setStatus('success', result.data.message || 'Справочники обновлены');
                } else {
                    setStatus('error', result.data.message || 'Ошибка загрузки справочников');
                }
                loadLogs();
            })
            .catch(function(error) {
                setStatus('error', 'Ошибка связи с сервером: ' + (error && error.message ? error.message : error));
                console.error('Ошибка загрузки справочников:', error);
            })
            .finally(function() {
                btnImportRefs.disabled = false;
            });
    }

    // -------------------------------------------------------
    // ОЧИСТКА ЛОГОВ
    // -------------------------------------------------------
    
    /**
     * Отправляет запрос на очистку файла логов.
     */
    function clearLogs() {
        if (!confirm('Вы уверены, что хотите очистить все логи?')) {
            return;
        }

        fetch('api.php?action=clear_logs', { method: 'POST' })
            .then(function(response) { return response.json(); })
            .then(function(data) {
                if (data.status === 'ok') {
                    showLogsEmpty('Логи очищены');
                    setStatus('success', 'Логи очищены');
                }
            })
            .catch(function(error) {
                console.error('Ошибка очистки логов:', error);
            });
    }

    // -------------------------------------------------------
    // ПРИВЯЗКА СОБЫТИЙ К КНОПКАМ
    // -------------------------------------------------------
    
    btnRun.addEventListener('click', runProcessing);
    btnToggleAuto.addEventListener('click', toggleAutoProcessing);
    btnSaveInterval.addEventListener('click', saveInterval);
    btnImportRefs.addEventListener('click', importReferences);
    btnClearLogs.addEventListener('click', clearLogs);
    btnRefreshLogs.addEventListener('click', function() { loadLogs(true); });

    logsMain.addEventListener('scroll', onLogScroll);
    logsService.addEventListener('scroll', onLogScroll);

    function pauseLogPoll() {
        logsPollPaused = true;
        clearInterval(logsTimer);
        logsTimer = null;
    }
    function resumeLogPoll() {
        logsPollPaused = false;
        if (logsTimer) clearInterval(logsTimer);
        logsTimer = setInterval(function() { loadLogs(false); }, 3000);
    }
    logsMain.addEventListener('mouseenter', pauseLogPoll);
    logsService.addEventListener('mouseenter', pauseLogPoll);
    logsMain.addEventListener('mouseleave', resumeLogPoll);
    logsService.addEventListener('mouseleave', resumeLogPoll);

    // -------------------------------------------------------
    // ИНИЦИАЛИЗАЦИЯ ПРИ ЗАГРУЗКЕ СТРАНИЦЫ
    // -------------------------------------------------------
    
    loadSettings();
    loadLogs(true);

    window.addEventListener('beforeunload', function() {
        if (runAbortController) runAbortController.abort();
    });

    logsTimer = setInterval(function() { loadLogs(false); }, 3000);
});
