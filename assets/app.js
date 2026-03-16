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
    
    /** Контейнер для отображения логов */
    var logsContainer = document.getElementById('logs-container');
    
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
    
    /**
     * Загружает последние записи лога с сервера и отображает их
     * в контейнере на странице. Каждая строка окрашивается
     * в зависимости от уровня (INFO, ERROR, SUCCESS, WARNING).
     */
    function loadLogs() {
        fetch('api.php?action=logs')
            .then(function(response) { return response.json(); })
            .then(function(data) {
                if (data.status === 'ok') {
                    if (data.logs.length === 0) {
                        logsContainer.innerHTML = '<p class="logs__placeholder">Логи пусты</p>';
                        return;
                    }

                    // Преобразуем каждую строку лога в HTML-элемент с нужным цветом
                    var html = '';
                    for (var i = 0; i < data.logs.length; i++) {
                        var line = data.logs[i];
                        var cssClass = getLogLineClass(line);
                        // Экранируем HTML-символы для безопасности
                        var safeLine = escapeHtml(line);
                        html += '<div class="log-line ' + cssClass + '">' + safeLine + '</div>';
                    }
                    
                    logsContainer.innerHTML = html;

                    // Прокручиваем контейнер вниз, чтобы были видны последние записи
                    logsContainer.scrollTop = logsContainer.scrollHeight;
                }
            })
            .catch(function(error) {
                console.error('Ошибка загрузки логов:', error);
            });
    }

    /**
     * Определяет CSS-класс для строки лога по её содержимому.
     * Это нужно для окраски строк разным цветом.
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
     * Например, символ < превращается в &lt;
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
                    if (data.sftp_downloaded > 0) {
                        msg = 'SFTP: ' + data.sftp_downloaded + ' файлов. ' + msg;
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
                    logsContainer.innerHTML = '<p class="logs__placeholder">Логи очищены</p>';
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
    btnRefreshLogs.addEventListener('click', loadLogs);

    // -------------------------------------------------------
    // ИНИЦИАЛИЗАЦИЯ ПРИ ЗАГРУЗКЕ СТРАНИЦЫ
    // -------------------------------------------------------
    
    // Загружаем настройки и логи сразу при открытии
    loadSettings();
    loadLogs();

    // Прерываем fetch при уходе со страницы — браузер не будет блокировать навигацию
    window.addEventListener('beforeunload', function() {
        if (runAbortController) runAbortController.abort();
    });

        // Запускаем автоматическое обновление логов каждые 3 секунды
        logsTimer = setInterval(loadLogs, 3000);

        // Пауза обновления при наведении мыши (чтобы можно было скопировать текст)
        logsContainer.addEventListener('mouseenter', function() {
            clearInterval(logsTimer);
        });
        logsContainer.addEventListener('mouseleave', function() {
            logsTimer = setInterval(loadLogs, 3000);
        });
});
