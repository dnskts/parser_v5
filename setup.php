<?php
/**
 * ============================================================
 * СТРАНИЦА НАСТРОЕК (SETUP)
 * ============================================================
 * Закрыта простым паролем из config/settings.json (setup.password).
 * Это первый уровень защиты: чтобы сотрудники не заходили сюда
 * случайно. Полноценной авторизации в системе нет.
 *
 * Что здесь есть:
 * 1. Загрузка справочников из выгрузки 1С (references/import/)
 * 2. Галки «продажи / возвраты / обмены» по каждому поставщику:
 *    снятая галка запрещает отправку этого типа услуги в 1С
 *    (разбор файлов и JSON при этом продолжают работать).
 *
 * Список поставщиков приходит от ParserManager, поэтому новый
 * парсер появляется на странице сам, без правок настроек.
 * ============================================================
 */

session_start();

// Читаем настройки, чтобы взять пароль и дату импорта справочников
$configFile = __DIR__ . '/config/settings.json';
$settings = array();
if (file_exists($configFile) && is_readable($configFile)) {
    $settingsJson = file_get_contents($configFile);
    if ($settingsJson !== false) {
        $decoded = json_decode($settingsJson, true);
        if (is_array($decoded)) {
            $settings = $decoded;
        }
    }
}

$setupConfig = (isset($settings['setup']) && is_array($settings['setup']))
    ? $settings['setup']
    : array();
$setupPassword = isset($setupConfig['password']) ? (string)$setupConfig['password'] : '';

$refsLastImportLabel = !empty($settings['references']['last_import'])
    ? $settings['references']['last_import']
    : 'не загружались';

// Выход из настроек
if (isset($_GET['logout'])) {
    unset($_SESSION['setup_auth']);
    header('Location: setup.php');
    exit;
}

// Вход по паролю. После успеха — редирект, чтобы пароль не остался
// в форме и повторное обновление страницы его не отправляло заново
$loginError = '';
if (isset($_POST['setup_password'])) {
    if ($setupPassword === '') {
        $loginError = 'Пароль не задан в config/settings.json (setup.password)';
    } elseif ((string)$_POST['setup_password'] === $setupPassword) {
        $_SESSION['setup_auth'] = true;
        header('Location: setup.php');
        exit;
    } else {
        $loginError = 'Неверный пароль';
    }
}

$isAuthorized = !empty($_SESSION['setup_auth']);
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>XML Parser — Setup</title>
    <link rel="stylesheet" href="assets/style.css">
</head>
<body>
    <header class="header header--compact">
        <div class="header__content header__content--wide">
            <div class="header__top">
                <div>
                    <h1 class="header__title">XML Parser</h1>
                </div>
                <nav class="nav">
                    <a href="index.php" class="nav__link">Панель управления</a>
                    <a href="data.php" class="nav__link">Обработанные заказы</a>
                    <a href="api_logs.php" class="nav__link">Логи API</a>
                    <a href="test.php" class="nav__link">Тесты</a>
                    <a href="setup.php" class="nav__link nav__link--active">⚙ Setup</a>
                </nav>
            </div>
        </div>
    </header>

    <main class="main main--wide">
<?php if (!$isAuthorized): ?>
        <!-- ============================================ -->
        <!-- ВХОД В НАСТРОЙКИ                             -->
        <!-- ============================================ -->
        <section class="panel panel--compact">
            <div class="panel__title-row">
                <div class="panel__title-group">
                    <h2 class="panel__title panel__title--inline">Настройки защищены паролем</h2>
                </div>
            </div>
            <form class="setup__login" method="post" action="setup.php">
                <label class="control-group__label" for="setup_password">Пароль:</label>
                <input type="password" id="setup_password" name="setup_password"
                       class="control-group__input" autocomplete="current-password" autofocus>
                <?php if ($loginError !== ''): ?>
                    <span class="setup__error"><?php echo htmlspecialchars($loginError); ?></span>
                <?php endif; ?>
                <div class="control-group__buttons">
                    <button type="submit" class="btn btn--primary">Войти</button>
                    <a href="index.php" class="btn btn--outline">Назад</a>
                </div>
            </form>
        </section>
<?php else: ?>
        <!-- ============================================ -->
        <!-- СПРАВОЧНИКИ                                  -->
        <!-- ============================================ -->
        <section class="panel panel--compact">
            <div class="panel__title-row">
                <div class="panel__title-group">
                    <div class="panel__title-text">
                        <h2 class="panel__title panel__title--inline">Справочники</h2>
                        <span id="refs-last-import" class="panel__count"
                              title="Дата последнего импорта из references/import/">
                            Последний импорт: <?php echo htmlspecialchars($refsLastImportLabel); ?>
                        </span>
                    </div>
                </div>
                <div class="panel__title-actions">
                    <a href="setup.php?logout=1" class="btn btn--small btn--outline">Выйти из настроек</a>
                </div>
            </div>
            <div class="control-group__buttons">
                <button id="btn-import-refs" class="btn btn--secondary"
                        title="Прочитать выгрузку 1С из references/import/ и обновить справочники">
                    📚 Загрузить справочники
                </button>
            </div>
        </section>

        <!-- ============================================ -->
        <!-- ОТПРАВКА В 1С ПО ПОСТАВЩИКАМ                 -->
        <!-- ============================================ -->
        <section class="panel panel--compact">
            <div class="panel__title-row">
                <div class="panel__title-group">
                    <h2 class="panel__title panel__title--inline">Отправка в 1С по поставщикам</h2>
                </div>
            </div>
            <p class="setup__hint">
                Снятая галка — заказы этого типа услуги в 1С не отправляются.
                Разбор файлов, JSON в json/ и json_api/ и перенос в Processed/ работают всегда.
                Пропущенные отправки видны в логах API со статусом SKIP.
            </p>
            <div id="setup-suppliers" class="setup__grid">
                <p class="logs__placeholder">Загрузка настроек...</p>
            </div>
            <div class="control-group__buttons">
                <button id="btn-setup-save" class="btn btn--primary">Сохранить</button>
            </div>
        </section>

        <div class="panel__status">
            <span id="setup-status" class="status-badge status-badge--idle">Ожидание</span>
        </div>

        <!-- ============================================ -->
        <!-- ЖУРНАЛ НАСТРОЕК                              -->
        <!-- ============================================ -->
        <section class="logs">
            <div class="logs__header">
                <h2 class="logs__title">Журнал настроек</h2>
                <button id="btn-refresh-logs" class="btn btn--small btn--outline">
                    ↻ Обновить
                </button>
            </div>
            <div id="setup-logs" class="logs__container logs__container--compact">
                <p class="logs__placeholder">Загрузка логов...</p>
            </div>
        </section>
<?php endif; ?>
    </main>

    <footer class="footer">
        <p>XML Parser v5 — Система обработки файлов поставщиков by Denis Kuritsyn</p>
    </footer>

<?php if ($isAuthorized): ?>
    <script>
    (function() {
        'use strict';

        var statusEl = document.getElementById('setup-status');
        var suppliersEl = document.getElementById('setup-suppliers');
        var btnSave = document.getElementById('btn-setup-save');
        var btnImportRefs = document.getElementById('btn-import-refs');
        var refsLabel = document.getElementById('refs-last-import');
        var logsEl = document.getElementById('setup-logs');
        var btnRefreshLogs = document.getElementById('btn-refresh-logs');

        /** Типы услуг: ключ -> подпись. Приходят от сервера (SendPolicy) */
        var typeLabels = {};

        /** Сколько строк журнала тянуть за один запрос */
        var logsPageSize = 60;

        /** Уже показанные строки — защита от дублей при опросе */
        var knownLogLines = {};
        var logsLoadedCount = 0;
        var logsHasMore = true;
        var logsLoading = false;
        var logsLoadingOlder = false;
        var logsTimer = null;

        /**
         * Строка статуса внизу страницы.
         *
         * @param {string} type — idle, running, success, error
         * @param {string} text — текст сообщения
         */
        function setStatus(type, text) {
            statusEl.className = 'status-badge status-badge--' + type;
            statusEl.textContent = text;
        }

        // ---------------------------------------------------
        // ЖУРНАЛ НАСТРОЕК
        // ---------------------------------------------------

        /**
         * CSS-класс строки журнала по её уровню.
         *
         * @param {string} line — строка лога
         * @returns {string}
         */
        function getLogLineClass(line) {
            if (line.indexOf('[ERROR]') !== -1)   return 'log-line--error';
            if (line.indexOf('[WARNING]') !== -1) return 'log-line--warning';
            // история изменения галок — своим цветом
            if (line.indexOf('Setup: ') !== -1)   return 'log-line--setup';
            if (line.indexOf('[SUCCESS]') !== -1) return 'log-line--success';
            return 'log-line--info';
        }

        function createLogLineEl(line) {
            var div = document.createElement('div');
            div.className = 'log-line ' + getLogLineClass(line);
            div.textContent = line;
            return div;
        }

        function removeLogsPlaceholder() {
            var ph = logsEl.querySelector('.logs__placeholder');
            if (ph) {
                logsEl.innerHTML = '';
            }
        }

        function showLogsMessage(text) {
            logsEl.innerHTML = '';
            var p = document.createElement('p');
            p.className = 'logs__placeholder';
            p.textContent = text;
            logsEl.appendChild(p);
        }

        function resetLogs() {
            logsEl.innerHTML = '';
            knownLogLines = {};
            logsLoadedCount = 0;
            logsHasMore = true;
        }

        /**
         * Добавляет строку в конец окна (новые записи).
         *
         * @param {string} line
         * @returns {boolean} — false, если строка уже показана
         */
        function appendLogLine(line) {
            if (knownLogLines[line]) {
                return false;
            }
            knownLogLines[line] = 1;
            removeLogsPlaceholder();
            logsEl.appendChild(createLogLineEl(line));
            return true;
        }

        /**
         * Добавляет строку в начало окна (история при прокрутке вверх).
         *
         * @param {string} line
         * @returns {boolean} — false, если строка уже показана
         */
        function prependLogLine(line) {
            if (knownLogLines[line]) {
                return false;
            }
            knownLogLines[line] = 1;
            removeLogsPlaceholder();
            if (logsEl.firstChild) {
                logsEl.insertBefore(createLogLineEl(line), logsEl.firstChild);
            } else {
                logsEl.appendChild(createLogLineEl(line));
            }
            return true;
        }

        function isNearBottom() {
            return (logsEl.scrollHeight - logsEl.scrollTop - logsEl.clientHeight) < 40;
        }

        /** Останавливает опрос (например, после истечения сессии). */
        function stopLogPoll() {
            if (logsTimer) {
                clearInterval(logsTimer);
                logsTimer = null;
            }
        }

        /**
         * Загрузка журнала: при fullReset — заново, иначе добавляет только новые строки.
         *
         * @param {boolean} fullReset
         */
        function loadLogs(fullReset) {
            if (logsLoading) {
                return;
            }
            logsLoading = true;

            if (fullReset) {
                resetLogs();
            }

            fetch('api.php?action=setup_logs&offset=0&limit=' + logsPageSize)
                .then(function(response) {
                    if (response.status === 403) {
                        stopLogPoll();
                        showLogsMessage('Сессия истекла — войдите на странице Setup заново');
                        return null;
                    }
                    return response.json();
                })
                .then(function(data) {
                    if (!data || data.status !== 'ok') {
                        return;
                    }
                    if (!data.logs || data.logs.length === 0) {
                        if (logsLoadedCount === 0) {
                            showLogsMessage('Событий по настройкам пока нет');
                        }
                        return;
                    }

                    if (fullReset || logsLoadedCount === 0) {
                        resetLogs();
                        for (var i = 0; i < data.logs.length; i++) {
                            appendLogLine(data.logs[i]);
                        }
                        // считаем строки, отданные сервером, а не добавленные:
                        // одинаковые строки схлопываются, а offset на сервере
                        // считает все подходящие строки
                        logsLoadedCount = data.logs.length;
                        logsHasMore = !!data.has_more;
                        logsEl.scrollTop = logsEl.scrollHeight;
                        return;
                    }

                    // опрос: добавляем только новые строки в хвосте
                    var stick = isNearBottom();
                    var added = 0;
                    for (var j = 0; j < data.logs.length; j++) {
                        if (appendLogLine(data.logs[j])) {
                            added++;
                        }
                    }
                    if (added > 0 && stick) {
                        logsEl.scrollTop = logsEl.scrollHeight;
                    }
                })
                .catch(function(error) {
                    console.error('Ошибка загрузки журнала:', error);
                })
                .finally(function() {
                    logsLoading = false;
                });
        }

        /** Подгружает более старые записи при прокрутке вверх. */
        function loadOlderLogs() {
            // свой флаг: опрос новых строк не должен блокировать историю
            if (logsLoadingOlder || !logsHasMore || logsLoadedCount === 0) {
                return;
            }
            logsLoadingOlder = true;

            var heightBefore = logsEl.scrollHeight;

            fetch('api.php?action=setup_logs&offset=' + logsLoadedCount + '&limit=' + logsPageSize)
                .then(function(response) {
                    if (response.status === 403) {
                        stopLogPoll();
                        return null;
                    }
                    return response.json();
                })
                .then(function(data) {
                    if (!data || data.status !== 'ok' || !data.logs || data.logs.length === 0) {
                        logsHasMore = false;
                        return;
                    }

                    // строки приходят в хронологическом порядке — вставляем с конца
                    for (var i = data.logs.length - 1; i >= 0; i--) {
                        prependLogLine(data.logs[i]);
                    }
                    logsLoadedCount += data.logs.length;
                    logsHasMore = !!data.has_more;

                    // сохраняем позицию прокрутки, чтобы окно не «прыгало»
                    logsEl.scrollTop = logsEl.scrollHeight - heightBefore;
                })
                .catch(function(error) {
                    console.error('Ошибка загрузки истории журнала:', error);
                })
                .finally(function() {
                    logsLoadingOlder = false;
                });
        }

        function onLogScroll() {
            if (logsEl.scrollTop <= 8) {
                loadOlderLogs();
            }
        }

        /**
         * Отрисовывает карточки поставщиков с галками типов услуг.
         *
         * @param {Array} suppliers — folder, name, flags
         */
        function renderSuppliers(suppliers) {
            suppliersEl.innerHTML = '';

            if (!suppliers || !suppliers.length) {
                suppliersEl.innerHTML = '<p class="logs__placeholder">Парсеры не найдены</p>';
                return;
            }

            for (var i = 0; i < suppliers.length; i++) {
                var supplier = suppliers[i];

                var card = document.createElement('div');
                card.className = 'setup__card';
                card.setAttribute('data-folder', supplier.folder);

                var title = document.createElement('div');
                title.className = 'setup__card-title';
                title.textContent = supplier.name || supplier.folder;
                card.appendChild(title);

                var folder = document.createElement('div');
                folder.className = 'setup__folder';
                folder.textContent = supplier.folder;
                card.appendChild(folder);

                var flags = document.createElement('div');
                flags.className = 'setup__flags';

                for (var key in typeLabels) {
                    if (!typeLabels.hasOwnProperty(key)) {
                        continue;
                    }

                    var label = document.createElement('label');
                    label.className = 'setup__flag';

                    var checkbox = document.createElement('input');
                    checkbox.type = 'checkbox';
                    checkbox.setAttribute('data-type', key);
                    checkbox.checked = !(supplier.flags && supplier.flags[key] === false);

                    label.appendChild(checkbox);
                    label.appendChild(document.createTextNode(typeLabels[key]));
                    flags.appendChild(label);
                }

                card.appendChild(flags);
                suppliersEl.appendChild(card);
            }
        }

        /**
         * Собирает состояние галок со страницы для отправки на сервер.
         *
         * @returns {Object} — folder -> { sale: bool, refund: bool, exchange: bool }
         */
        function collectSuppliers() {
            var result = {};
            var cards = suppliersEl.getElementsByClassName('setup__card');

            for (var i = 0; i < cards.length; i++) {
                var folder = cards[i].getAttribute('data-folder');
                var boxes = cards[i].getElementsByTagName('input');
                var flags = {};

                for (var j = 0; j < boxes.length; j++) {
                    var type = boxes[j].getAttribute('data-type');
                    if (type) {
                        flags[type] = boxes[j].checked;
                    }
                }
                result[folder] = flags;
            }
            return result;
        }

        /**
         * Обрабатывает ответ setup_get / setup_save.
         *
         * @param {Object} data — ответ сервера
         */
        function applySetupData(data) {
            if (!data || data.status !== 'ok') {
                setStatus('error', (data && data.message) ? data.message : 'Не удалось получить настройки');
                return;
            }
            typeLabels = data.types || {};
            renderSuppliers(data.suppliers);
        }

        /** Загружает поставщиков и их галки. */
        function loadSetup() {
            setStatus('running', 'Загрузка настроек...');

            fetch('api.php?action=setup_get')
                .then(function(response) { return response.json(); })
                .then(function(data) {
                    applySetupData(data);
                    if (data && data.status === 'ok') {
                        setStatus('idle', 'Ожидание');
                    }
                })
                .catch(function(error) {
                    setStatus('error', 'Ошибка связи с сервером');
                    console.error('Ошибка загрузки настроек:', error);
                });
        }

        /** Сохраняет галки в config/settings.json. */
        function saveSetup() {
            btnSave.disabled = true;
            setStatus('running', 'Сохранение...');

            fetch('api.php?action=setup_save', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ suppliers: collectSuppliers() })
            })
                .then(function(response) { return response.json(); })
                .then(function(data) {
                    applySetupData(data);
                    if (data && data.status === 'ok') {
                        setStatus('success', data.message || 'Настройки сохранены');
                    }
                })
                .catch(function(error) {
                    setStatus('error', 'Ошибка связи с сервером');
                    console.error('Ошибка сохранения настроек:', error);
                })
                .finally(function() {
                    btnSave.disabled = false;
                    loadLogs(false);
                });
        }

        /**
         * Читает выгрузку 1С из references/import/ и обновляет
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
                        return;
                    }
                    if (!result.data) {
                        setStatus('error', 'Сервер вернул не-JSON (HTTP ' + result.httpStatus + ')');
                        return;
                    }
                    if (result.data.status === 'ok') {
                        setStatus('success', result.data.message || 'Справочники обновлены');
                        if (result.data.last_import) {
                            refsLabel.textContent = 'Последний импорт: ' + result.data.last_import;
                        }
                    } else {
                        setStatus('error', result.data.message || 'Ошибка загрузки справочников');
                    }
                })
                .catch(function(error) {
                    setStatus('error', 'Ошибка связи с сервером: ' + (error && error.message ? error.message : error));
                    console.error('Ошибка загрузки справочников:', error);
                })
                .finally(function() {
                    btnImportRefs.disabled = false;
                    loadLogs(false);
                });
        }

        btnSave.addEventListener('click', saveSetup);
        btnImportRefs.addEventListener('click', importReferences);
        btnRefreshLogs.addEventListener('click', function() { loadLogs(true); });
        logsEl.addEventListener('scroll', onLogScroll);

        loadSetup();
        loadLogs(true);
        logsTimer = setInterval(function() { loadLogs(false); }, 3000);
    })();
    </script>
<?php endif; ?>
</body>
</html>
