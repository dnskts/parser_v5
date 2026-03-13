<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>XML Parser — Панель управления</title>
    <!-- Подключаем стили оформления -->
    <link rel="stylesheet" href="assets/style.css">
</head>
<body>
    <!-- ============================================ -->
    <!-- ШАПКА СТРАНИЦЫ                               -->
    <!-- ============================================ -->
    <header class="header header--compact">
        <div class="header__content header__content--wide">
            <div class="header__top">
                <div>
                    <h1 class="header__title">XML Parser</h1>
                </div>
                <!-- Навигация между страницами -->
                <nav class="nav">
                    <a href="index.php" class="nav__link nav__link--active">Панель управления</a>
                    <a href="data.php" class="nav__link">Обработанные заказы</a>
                    <a href="api_logs.php" class="nav__link">Логи API</a>
                    <a href="test.php" class="nav__link">Тесты</a>
                </nav>
            </div>
        </div>
    </header>

    <main class="main main--wide">
        <!-- ============================================ -->
        <!-- ПАНЕЛЬ УПРАВЛЕНИЯ                            -->
        <!-- Здесь можно настроить интервал обработки     -->
        <!-- и запустить обработку вручную                -->
        <!-- ============================================ -->
        <section class="panel">
            
            <div class="panel__controls">
                <!-- Блок настройки интервала -->
                <div class="control-group">
                    <label class="control-group__label" for="interval">
                        Интервал обработки (секунды):
                    </label>
                    <div class="control-group__input-row">
                        <input 
                            type="number" 
                            id="interval" 
                            class="control-group__input" 
                            min="10" 
                            max="86400" 
                            value="60"
                            title="Минимум 10 секунд, максимум 86400 (сутки)"
                        >
                        <button id="btn-save-interval" class="btn btn--secondary">
                            Сохранить
                        </button>
                    </div>
                </div>

                <!-- Кнопки действий -->
                <div class="control-group">
                    <div class="control-group__buttons">
                        <button id="btn-run" class="btn btn--primary">
                            ▶ Запустить обработку
                        </button>
                        <button id="btn-toggle-auto" class="btn btn--outline">
                            ⏱ Вкл. автообработку
                        </button>
                        <button id="btn-clear-logs" class="btn btn--danger">
                            🗑 Очистить логи
                        </button>
                    </div>
                </div>
            </div>

            <!-- Строка статуса -->
            <div class="panel__status">
                <span id="status-text" class="status-badge status-badge--idle">
                    Ожидание
                </span>
                <span id="auto-status" class="status-badge status-badge--off">
                    Авто: выкл
                </span>
                <span id="last-run" class="panel__last-run"></span>
            </div>
        </section>

        <!-- ============================================ -->
        <!-- БЛОК ЛОГОВ                                   -->
        <!-- Здесь отображаются записи из журнала событий -->
        <!-- Логи обновляются автоматически каждые 3 сек  -->
        <!-- ============================================ -->
        <section class="logs">
            <div class="logs__header">
                <h2 class="logs__title">Журнал событий</h2>
                <button id="btn-refresh-logs" class="btn btn--small btn--outline">
                    ↻ Обновить
                </button>
            </div>
            <div id="logs-container" class="logs__container">
                <p class="logs__placeholder">Загрузка логов...</p>
            </div>
        </section>
    </main>

    <!-- ============================================ -->
    <!-- ПОДВАЛ СТРАНИЦЫ                              -->
    <!-- ============================================ -->
    <footer class="footer">
        <p>XML Parser v5 — Система обработки файлов поставщиков by Denis Kuritsyn</p>
    </footer>

    <!-- Подключаем скрипт логики интерфейса -->
    <script src="assets/app.js"></script>
</body>
</html>
