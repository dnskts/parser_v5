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
 * - Три независимых журнала (файлы / запуски / служебные)
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
    
    /** Кнопка очистки логов */
    var btnClearLogs = document.getElementById('btn-clear-logs');
    
    /** Кнопка обновления логов */
    var btnRefreshLogs = document.getElementById('btn-refresh-logs');
    
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

    var logsPageSize = 100;
    var logsPollPaused = false;

    /**
     * Три независимых панели журнала (свой channel / offset / known).
     */
    var logPanes = {
        files: {
            channel: 'files',
            el: document.getElementById('logs-files'),
            loadedFromEnd: 0,
            hasMore: true,
            loadingOlder: false,
            known: {}
        },
        runs: {
            channel: 'runs',
            el: document.getElementById('logs-runs'),
            loadedFromEnd: 0,
            hasMore: true,
            loadingOlder: false,
            known: {}
        },
        service: {
            channel: 'service',
            el: document.getElementById('logs-service'),
            loadedFromEnd: 0,
            hasMore: true,
            loadingOlder: false,
            known: {}
        }
    };

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
    // ЗАГРУЗКА И ОТОБРАЖЕНИЕ ЛОГОВ (три канала)
    // -------------------------------------------------------

    function eachLogPane(fn) {
        fn(logPanes.files);
        fn(logPanes.runs);
        fn(logPanes.service);
    }

    function clearLogPanes() {
        eachLogPane(function(pane) {
            if (!pane.el) return;
            pane.el.innerHTML = '';
            pane.known = {};
            pane.loadedFromEnd = 0;
            pane.hasMore = true;
            pane.loadingOlder = false;
        });
    }

    function showLogsEmpty(msg) {
        clearLogPanes();
        eachLogPane(function(pane) {
            if (!pane.el) return;
            pane.el.innerHTML = '<p class="logs__placeholder">' + escapeHtml(msg) + '</p>';
        });
    }

    function createLogLineEl(line) {
        var div = document.createElement('div');
        div.className = 'log-line ' + getLogLineClass(line);
        div.textContent = line;
        div.setAttribute('data-line', line);
        return div;
    }

    function appendLogLineToPane(pane, line) {
        if (!pane || !pane.el || pane.known[line]) return false;
        pane.known[line] = 1;
        var ph = pane.el.querySelector('.logs__placeholder');
        if (ph) pane.el.innerHTML = '';
        pane.el.appendChild(createLogLineEl(line));
        return true;
    }

    function prependLogLineToPane(pane, line) {
        if (!pane || !pane.el || pane.known[line]) return false;
        pane.known[line] = 1;
        var ph = pane.el.querySelector('.logs__placeholder');
        if (ph) pane.el.innerHTML = '';
        var el = createLogLineEl(line);
        if (pane.el.firstChild) {
            pane.el.insertBefore(el, pane.el.firstChild);
        } else {
            pane.el.appendChild(el);
        }
        return true;
    }

    function isNearBottom(el) {
        return (el.scrollHeight - el.scrollTop - el.clientHeight) < 40;
    }

    function scrollPaneToBottom(pane) {
        if (pane && pane.el) {
            pane.el.scrollTop = pane.el.scrollHeight;
        }
    }

    function fetchPaneChunk(pane, offset, limit) {
        var url = 'api.php?action=logs&channel=' + encodeURIComponent(pane.channel)
            + '&offset=' + offset + '&limit=' + limit;
        return fetch(url).then(function(response) { return response.json(); });
    }

    /**
     * Загрузка одного канала: fullReset / первая заливка или poll (offset=0).
     */
    function loadPaneLogs(pane, fullReset) {
        if (!pane || !pane.el) return;
        fetchPaneChunk(pane, 0, logsPageSize)
            .then(function(data) {
                if (!data || data.status !== 'ok') return;
                if (!data.logs || data.logs.length === 0) {
                    if (fullReset || pane.loadedFromEnd === 0) {
                        pane.el.innerHTML = '<p class="logs__placeholder">Нет записей</p>';
                        pane.loadedFromEnd = 0;
                        pane.hasMore = false;
                        pane.known = {};
                    }
                    return;
                }

                if (fullReset || pane.loadedFromEnd === 0) {
                    pane.el.innerHTML = '';
                    pane.known = {};
                    for (var i = 0; i < data.logs.length; i++) {
                        appendLogLineToPane(pane, data.logs[i]);
                    }
                    pane.loadedFromEnd = data.logs.length;
                    pane.hasMore = !!data.has_more;
                    scrollPaneToBottom(pane);
                    return;
                }

                var stick = isNearBottom(pane.el);
                var added = 0;
                for (var j = 0; j < data.logs.length; j++) {
                    if (appendLogLineToPane(pane, data.logs[j])) {
                        added++;
                    }
                }
                if (added > 0 && stick) {
                    scrollPaneToBottom(pane);
                }
            })
            .catch(function(error) {
                console.error('Ошибка загрузки логов [' + pane.channel + ']:', error);
            });
    }

    /**
     * Первичная загрузка / refresh / poll для всех трёх панелей.
     */
    function loadLogs(fullReset) {
        if (fullReset) {
            clearLogPanes();
        }
        eachLogPane(function(pane) {
            loadPaneLogs(pane, !!fullReset);
        });
    }

    function loadOlderForPane(pane) {
        if (!pane || !pane.el || pane.loadingOlder || !pane.hasMore) return;
        pane.loadingOlder = true;
        var prevHeight = pane.el.scrollHeight;
        fetchPaneChunk(pane, pane.loadedFromEnd, logsPageSize)
            .then(function(data) {
                pane.loadingOlder = false;
                if (!data || data.status !== 'ok' || !data.logs || data.logs.length === 0) {
                    pane.hasMore = false;
                    return;
                }
                for (var i = data.logs.length - 1; i >= 0; i--) {
                    prependLogLineToPane(pane, data.logs[i]);
                }
                pane.loadedFromEnd += data.logs.length;
                pane.hasMore = !!data.has_more;
                pane.el.scrollTop = pane.el.scrollHeight - prevHeight;
            })
            .catch(function(error) {
                pane.loadingOlder = false;
                console.error('Ошибка подгрузки логов [' + pane.channel + ']:', error);
            });
    }

    function onLogScroll(ev) {
        var el = ev.target;
        if (el.scrollTop > 8) return;
        var pane = null;
        eachLogPane(function(p) {
            if (p.el === el) pane = p;
        });
        if (pane) {
            loadOlderForPane(pane);
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
        if (line.indexOf('[WARNING]') !== -1) return 'log-line--warning';
        // изменения галок отправки со страницы Setup — своим цветом
        if (line.indexOf('Setup: ') !== -1)   return 'log-line--setup';
        if (line.indexOf('[SUCCESS]') !== -1) return 'log-line--success';
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
    btnClearLogs.addEventListener('click', clearLogs);
    btnRefreshLogs.addEventListener('click', function() { loadLogs(true); });

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
    eachLogPane(function(pane) {
        if (!pane.el) return;
        pane.el.addEventListener('scroll', onLogScroll);
        pane.el.addEventListener('mouseenter', pauseLogPoll);
        pane.el.addEventListener('mouseleave', resumeLogPoll);
    });

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
