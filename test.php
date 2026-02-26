<?php
/**
 * ============================================================
 * АВТОТЕСТЫ ПАРСЕРОВ — ВЕБ-ИНТЕРФЕЙС
 * ============================================================
 * 
 * Страница для автоматического тестирования парсеров.
 * Берёт XML-файлы из папки tests/fixtures/,
 * прогоняет через парсер и проверяет результат.
 * 
 * Не отправляет данные в API, не перемещает файлы.
 * Только парсинг и проверка структуры JSON.
 * 
 * Запуск: открыть в браузере test.php
 *         или из CLI: php test.php
 * ============================================================
 */

// Подключаем необходимые модули
require_once __DIR__ . '/core/ParserInterface.php';
require_once __DIR__ . '/core/Utils.php';
require_once __DIR__ . '/parsers/MoyAgentParser.php';

// =====================================================
// НАСТРОЙКИ ТЕСТОВ
// =====================================================

// Папка с тестовыми файлами
$testDir = __DIR__ . '/tests/fixtures';

// Ожидаемые результаты для каждого файла
// Ключ — имя файла, значение — что должно быть в результате
$expectations = array(

    '125358843227.xml' => array(
        'description'    => 'Продажа, 1 билет, 2 сегмента, SVO→KZN→SVO',
        'status'         => 'продажа',
        'products_count' => 1,
        'invoice_number' => '125358843227',
        'client'         => 'MA1PA6',
        'ticket_number'  => '5552379893379',
        'traveller'      => 'SMIRNOV ALEKSEI',
        'carrier'        => 'SU',
        'coupons_count'  => 2,
        'currency'       => 'RUB',
        'fare'           => 6650.0,
        'has_refund'     => false,
    ),

    '125358829987.xml' => array(
        'description'    => 'Продажа, 3 билета, 3 пассажира, SVO→DXB→SVO',
        'status'         => 'продажа',
        'products_count' => 3,
        'invoice_number' => '125358829987',
        'client'         => 'MA1PA6',
        'ticket_number'  => '5552379660767',
        'traveller'      => 'MUSTAFINA ULIANA',
        'carrier'        => 'SU',
        'coupons_count'  => 2,
        'currency'       => 'RUB',
        'fare'           => 70640.0,
        'has_refund'     => false,
    ),

    '125358832021.xml' => array(
        'description'    => 'Продажа, 1 билет + EMD, MXP→CDG, валюта EUR→RUB',
        'status'         => 'продажа',
        'products_count' => 2,
        'invoice_number' => '125358832021',
        'client'         => 'MA1PA6',
        'ticket_number'  => '0572675175754',
        'traveller'      => 'PETROVA EVGENIYA',
        'carrier'        => 'AF',
        'coupons_count'  => 1,
        'currency'       => 'RUB',
        'fare'           => 28497.0,
        'has_refund'     => false,
    ),

    '125358832769.xml' => array(
        'description'    => 'Возврат REF, penalty 3500, SVO→OVB→SVO',
        'status'         => 'возврат',
        'products_count' => 1,
        'invoice_number' => '125358832769',
        'client'         => 'MA1PA6',
        'ticket_number'  => '5552379788609',
        'traveller'      => 'SHKULEV VIKTOR',
        'carrier'        => 'SU',
        'coupons_count'  => 2,
        'currency'       => 'RUB',
        'fare'           => 138300.0,
        'has_refund'     => true,
        'penalty'        => 3500.0,
    ),
);

// =====================================================
// ЗАПУСК ТЕСТОВ
// =====================================================

$parser = new MoyAgentParser();
$results = array();     // Результаты всех файлов
$totalTests = 0;
$passedTests = 0;
$failedTests = 0;

foreach ($expectations as $fileName => $expected) {
    $filePath = $testDir . '/' . $fileName;
    $fileResult = array(
        'file'        => $fileName,
        'description' => $expected['description'],
        'checks'      => array(),
        'error'       => null,
    );

    // Проверяем что тестовый файл существует
    if (!file_exists($filePath)) {
        $fileResult['error'] = "Файл не найден: {$filePath}";
        $results[] = $fileResult;
        continue;
    }

    // Пробуем распарсить
    $data = null;
    try {
        $data = $parser->parse($filePath);
    } catch (Exception $e) {
        $fileResult['error'] = "Ошибка парсинга: " . $e->getMessage();
        $results[] = $fileResult;
        continue;
    }

    // --- Проверки ---

    // Парсинг успешен
    $totalTests++;
    $ok = ($data !== null);
    if ($ok) $passedTests++; else $failedTests++;
    $fileResult['checks'][] = array('name' => 'Парсинг', 'ok' => $ok, 'expected' => 'без ошибок', 'actual' => $ok ? 'OK' : 'FAIL');

    // UID заказа
    $totalTests++;
    $ok = (!empty($data['UID']) && strlen($data['UID']) === 36);
    if ($ok) $passedTests++; else $failedTests++;
    $fileResult['checks'][] = array('name' => 'UID заказа', 'ok' => $ok, 'expected' => 'UUID (36 символов)', 'actual' => isset($data['UID']) ? $data['UID'] : '—');

    // INVOICE_NUMBER
    $totalTests++;
    $actual = isset($data['INVOICE_NUMBER']) ? $data['INVOICE_NUMBER'] : '';
    $ok = ($actual === $expected['invoice_number']);
    if ($ok) $passedTests++; else $failedTests++;
    $fileResult['checks'][] = array('name' => 'INVOICE_NUMBER', 'ok' => $ok, 'expected' => $expected['invoice_number'], 'actual' => $actual);

    // CLIENT
    $totalTests++;
    $actual = isset($data['CLIENT']) ? $data['CLIENT'] : '';
    $ok = ($actual === $expected['client']);
    if ($ok) $passedTests++; else $failedTests++;
    $fileResult['checks'][] = array('name' => 'CLIENT', 'ok' => $ok, 'expected' => $expected['client'], 'actual' => $actual);

    // Количество продуктов
    $totalTests++;
    $actual = isset($data['PRODUCTS']) ? count($data['PRODUCTS']) : 0;
    $ok = ($actual === $expected['products_count']);
    if ($ok) $passedTests++; else $failedTests++;
    $fileResult['checks'][] = array('name' => 'Продуктов', 'ok' => $ok, 'expected' => $expected['products_count'], 'actual' => $actual);

    // Берём первый продукт для детальных проверок
    $p = isset($data['PRODUCTS'][0]) ? $data['PRODUCTS'][0] : array();

    // Статус
    $totalTests++;
    $actual = isset($p['STATUS']) ? $p['STATUS'] : '';
    $ok = ($actual === $expected['status']);
    if ($ok) $passedTests++; else $failedTests++;
    $fileResult['checks'][] = array('name' => 'Статус', 'ok' => $ok, 'expected' => $expected['status'], 'actual' => $actual);

    // Номер билета
    $totalTests++;
    $actual = isset($p['NUMBER']) ? $p['NUMBER'] : '';
    $ok = ($actual === $expected['ticket_number']);
    if ($ok) $passedTests++; else $failedTests++;
    $fileResult['checks'][] = array('name' => 'Номер билета', 'ok' => $ok, 'expected' => $expected['ticket_number'], 'actual' => $actual);

    // Пассажир
    $totalTests++;
    $actual = isset($p['TRAVELLER']) ? $p['TRAVELLER'] : '';
    $ok = ($actual === $expected['traveller']);
    if ($ok) $passedTests++; else $failedTests++;
    $fileResult['checks'][] = array('name' => 'Пассажир', 'ok' => $ok, 'expected' => $expected['traveller'], 'actual' => $actual);

    // Перевозчик
    $totalTests++;
    $actual = isset($p['CARRIER']) ? $p['CARRIER'] : '';
    $ok = ($actual === $expected['carrier']);
    if ($ok) $passedTests++; else $failedTests++;
    $fileResult['checks'][] = array('name' => 'Перевозчик', 'ok' => $ok, 'expected' => $expected['carrier'], 'actual' => $actual);

    // Купоны
    $totalTests++;
    $actual = isset($p['COUPONS']) ? count($p['COUPONS']) : 0;
    $ok = ($actual === $expected['coupons_count']);
    if ($ok) $passedTests++; else $failedTests++;
    $fileResult['checks'][] = array('name' => 'Купонов', 'ok' => $ok, 'expected' => $expected['coupons_count'], 'actual' => $actual);

    // Валюта
    $totalTests++;
    $actual = isset($p['CURRENCY']) ? $p['CURRENCY'] : '';
    $ok = ($actual === $expected['currency']);
    if ($ok) $passedTests++; else $failedTests++;
    $fileResult['checks'][] = array('name' => 'Валюта', 'ok' => $ok, 'expected' => $expected['currency'], 'actual' => $actual);

    // Тариф (первая такса = fare)
    $totalTests++;
    $actual = (isset($p['TAXES'][0]['AMOUNT'])) ? (float)$p['TAXES'][0]['AMOUNT'] : 0;
    $ok = (abs($actual - $expected['fare']) < 0.01);
    if ($ok) $passedTests++; else $failedTests++;
    $fileResult['checks'][] = array('name' => 'Тариф (fare)', 'ok' => $ok, 'expected' => $expected['fare'], 'actual' => $actual);

    // UID продукта
    $totalTests++;
    $actual = isset($p['UID']) ? $p['UID'] : '';
    $ok = (strlen($actual) === 36);
    if ($ok) $passedTests++; else $failedTests++;
    $fileResult['checks'][] = array('name' => 'UID продукта', 'ok' => $ok, 'expected' => 'UUID (36 символов)', 'actual' => $actual);

    // JSON сериализация
    $totalTests++;
    $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    $ok = ($json !== false && json_last_error() === JSON_ERROR_NONE);
    if ($ok) $passedTests++; else $failedTests++;
    $fileResult['checks'][] = array('name' => 'JSON сериализация', 'ok' => $ok, 'expected' => 'валидный JSON', 'actual' => $ok ? strlen($json) . ' байт' : json_last_error_msg());

    // REFUND (только для возвратов)
    if ($expected['has_refund']) {
        $totalTests++;
        $ok = isset($p['REFUND']);
        if ($ok) $passedTests++; else $failedTests++;
        $fileResult['checks'][] = array('name' => 'Блок REFUND', 'ok' => $ok, 'expected' => 'присутствует', 'actual' => $ok ? 'есть' : 'нет');

        if (isset($expected['penalty'])) {
            $totalTests++;
            $actual = isset($p['PENALTY']) ? (float)$p['PENALTY'] : 0;
            $ok = (abs($actual - $expected['penalty']) < 0.01);
            if ($ok) $passedTests++; else $failedTests++;
            $fileResult['checks'][] = array('name' => 'PENALTY', 'ok' => $ok, 'expected' => $expected['penalty'], 'actual' => $actual);
        }
    }

    $results[] = $fileResult;
}

// =====================================================
// ОПРЕДЕЛЯЕМ РЕЖИМ ВЫВОДА: CLI или WEB
// =====================================================

$isCli = (php_sapi_name() === 'cli');

if ($isCli) {
    // ----- КОНСОЛЬНЫЙ ВЫВОД -----
    echo "============================================================\n";
    echo " АВТОТЕСТЫ ПАРСЕРА MoyAgentParser\n";
    echo " " . date('Y-m-d H:i:s') . "\n";
    echo "============================================================\n\n";

    foreach ($results as $r) {
        echo "--- {$r['file']} ---\n";
        echo "    {$r['description']}\n";

        if ($r['error']) {
            echo "    \xE2\x9D\x8C {$r['error']}\n\n";
            continue;
        }

        foreach ($r['checks'] as $c) {
            $icon = $c['ok'] ? "\xE2\x9C\x85" : "\xE2\x9D\x8C";
            $line = "    {$icon} {$c['name']}: {$c['actual']}";
            if (!$c['ok']) {
                $line .= " (ожидалось: {$c['expected']})";
            }
            echo $line . "\n";
        }
        echo "\n";
    }

    echo "============================================================\n";
    echo " Тестов: {$totalTests} | Прошло: {$passedTests} | Упало: {$failedTests}\n";
    echo "============================================================\n";
    exit($failedTests > 0 ? 1 : 0);
}

// =====================================================
// HTML-ВЫВОД (ВЕБ-ИНТЕРФЕЙС)
// =====================================================
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>XML Parser — Автотесты</title>
    <link rel="stylesheet" href="assets/style.css">
    <style>
        /* ========================================== */
        /* Стили страницы тестов                      */
        /* ========================================== */

        .test-summary {
            display: flex;
            gap: 20px;
            margin-bottom: 24px;
            flex-wrap: wrap;
        }

        .test-summary__card {
            padding: 16px 24px;
            border-radius: 8px;
            font-size: 18px;
            font-weight: 600;
            min-width: 140px;
            text-align: center;
        }

        .test-summary__card--total {
            background: #e3f2fd;
            color: #1565c0;
        }

        .test-summary__card--passed {
            background: #e8f5e9;
            color: #2e7d32;
        }

        .test-summary__card--failed {
            background: #ffebee;
            color: #c62828;
        }

        .test-summary__card--allfailed {
            background: #ffebee;
            color: #c62828;
        }

        .test-summary__card--allpassed {
            background: #e8f5e9;
            color: #2e7d32;
        }

        .test-summary__label {
            font-size: 12px;
            font-weight: 400;
            display: block;
            margin-top: 4px;
            opacity: 0.7;
        }

        /* Блок файла */
        .test-file {
            background: #fff;
            border: 1px solid #e0e0e0;
            border-radius: 8px;
            margin-bottom: 16px;
            overflow: hidden;
        }

        .test-file__header {
            padding: 12px 20px;
            font-weight: 600;
            font-size: 15px;
            display: flex;
            align-items: center;
            gap: 10px;
            cursor: pointer;
            user-select: none;
        }

        .test-file__header:hover {
            background: #f5f5f5;
        }

        .test-file__header--pass {
            background: #f1f8e9;
            border-left: 4px solid #4caf50;
        }

        .test-file__header--fail {
            background: #fce4ec;
            border-left: 4px solid #e53935;
        }

        .test-file__header--error {
            background: #fff3e0;
            border-left: 4px solid #ff9800;
        }

        .test-file__icon {
            font-size: 18px;
            flex-shrink: 0;
        }

        .test-file__name {
            flex: 1;
        }

        .test-file__desc {
            color: #757575;
            font-weight: 400;
            font-size: 13px;
        }

        .test-file__badge {
            font-size: 12px;
            padding: 2px 10px;
            border-radius: 12px;
            font-weight: 600;
        }

        .test-file__badge--pass {
            background: #c8e6c9;
            color: #2e7d32;
        }

        .test-file__badge--fail {
            background: #ffcdd2;
            color: #c62828;
        }

        .test-file__badge--error {
            background: #ffe0b2;
            color: #e65100;
        }

        /* Таблица проверок */
        .test-file__body {
            padding: 0 20px 16px;
        }

        .test-checks {
            width: 100%;
            border-collapse: collapse;
            font-size: 13px;
        }

        .test-checks th {
            text-align: left;
            padding: 6px 12px;
            background: #fafafa;
            border-bottom: 1px solid #e0e0e0;
            font-weight: 600;
            color: #616161;
        }

        .test-checks td {
            padding: 5px 12px;
            border-bottom: 1px solid #f0f0f0;
        }

        .test-checks tr:last-child td {
            border-bottom: none;
        }

        .test-checks__icon {
            width: 30px;
            text-align: center;
            font-size: 15px;
        }

        .test-checks__name {
            width: 160px;
        }

        .test-checks__expected {
            color: #9e9e9e;
        }

        .test-checks__actual--fail {
            color: #c62828;
            font-weight: 600;
        }

        .test-file__error {
            padding: 12px 20px;
            color: #c62828;
            font-size: 13px;
        }

        /* Кнопка перезапуска */
        .test-actions {
            margin-bottom: 20px;
        }

        /* Время выполнения */
        .test-meta {
            color: #9e9e9e;
            font-size: 12px;
            margin-bottom: 20px;
        }
    </style>
</head>
<body>
    <header class="header">
        <div class="header__content">
            <div class="header__top">
                <div>
                    <h1 class="header__title">XML Parser</h1>
                    <p class="header__subtitle">Автоматическое тестирование парсеров</p>
                </div>
                <nav class="nav">
                    <a href="index.php" class="nav__link">Панель управления</a>
                    <a href="data.php" class="nav__link">Обработанные заказы</a>
                    <a href="api_logs.php" class="nav__link">Логи API</a>
                    <a href="test.php" class="nav__link nav__link--active">Тесты</a>
                </nav>
            </div>
        </div>
    </header>

    <main class="main">
        <section class="panel">
            <h2 class="panel__title">Результаты тестов</h2>

            <!-- Время и кнопка -->
            <div class="test-meta">
                Запуск: <?php echo date('Y-m-d H:i:s'); ?> &nbsp;|&nbsp;
                Папка: <code><?php echo htmlspecialchars($testDir); ?></code>
            </div>

            <div class="test-actions">
                <a href="test.php" class="btn btn--primary">↻ Перезапустить тесты</a>
            </div>

            <!-- Сводка -->
            <div class="test-summary">
                <div class="test-summary__card test-summary__card--total">
                    <?php echo $totalTests; ?>
                    <span class="test-summary__label">тестов</span>
                </div>
                <div class="test-summary__card test-summary__card--passed">
                    ✅ <?php echo $passedTests; ?>
                    <span class="test-summary__label">прошло</span>
                </div>
                <div class="test-summary__card <?php echo $failedTests > 0 ? 'test-summary__card--failed' : 'test-summary__card--passed'; ?>">
                    <?php echo $failedTests > 0 ? '❌' : '✅'; ?> <?php echo $failedTests; ?>
                    <span class="test-summary__label">упало</span>
                </div>
            </div>

            <!-- Результаты по файлам -->
            <?php foreach ($results as $r): ?>
                <?php
                    // Определяем статус файла
                    if ($r['error']) {
                        $fileStatus = 'error';
                        $fileIcon = '⚠️';
                        $badgeText = 'ОШИБКА';
                    } else {
                        $hasFailures = false;
                        foreach ($r['checks'] as $c) {
                            if (!$c['ok']) { $hasFailures = true; break; }
                        }
                        if ($hasFailures) {
                            $fileStatus = 'fail';
                            $fileIcon = '❌';
                            $badgeText = 'FAIL';
                        } else {
                            $fileStatus = 'pass';
                            $fileIcon = '✅';
                            $badgeText = 'PASS';
                        }
                    }
                ?>
                <div class="test-file">
                    <div class="test-file__header test-file__header--<?php echo $fileStatus; ?>"
                         onclick="this.parentElement.querySelector('.test-file__body, .test-file__error').classList.toggle('hidden')">
                        <span class="test-file__icon"><?php echo $fileIcon; ?></span>
                        <span class="test-file__name">
                            <?php echo htmlspecialchars($r['file']); ?>
                            <span class="test-file__desc">&mdash; <?php echo htmlspecialchars($r['description']); ?></span>
                        </span>
                        <span class="test-file__badge test-file__badge--<?php echo $fileStatus; ?>">
                            <?php echo $badgeText; ?>
                        </span>
                    </div>

                    <?php if ($r['error']): ?>
                        <div class="test-file__error">
                            <?php echo htmlspecialchars($r['error']); ?>
                        </div>
                    <?php else: ?>
                        <div class="test-file__body <?php echo $fileStatus === 'pass' ? 'hidden' : ''; ?>">
                            <table class="test-checks">
                                <thead>
                                    <tr>
                                        <th class="test-checks__icon"></th>
                                        <th class="test-checks__name">Проверка</th>
                                        <th>Ожидалось</th>
                                        <th>Получено</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($r['checks'] as $c): ?>
                                        <tr>
                                            <td class="test-checks__icon">
                                                <?php echo $c['ok'] ? '✅' : '❌'; ?>
                                            </td>
                                            <td class="test-checks__name">
                                                <?php echo htmlspecialchars($c['name']); ?>
                                            </td>
                                            <td class="test-checks__expected">
                                                <?php echo htmlspecialchars($c['expected']); ?>
                                            </td>
                                            <td class="<?php echo $c['ok'] ? '' : 'test-checks__actual--fail'; ?>">
                                                <?php echo htmlspecialchars($c['actual']); ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>

        </section>
    </main>

    <footer class="footer">
        <p>XML Parser v5 — Система обработки файлов поставщиков</p>
    </footer>

    <script>
        // Класс hidden для сворачивания/разворачивания блоков
        document.querySelectorAll('.hidden').forEach(function(el) {
            el.style.display = 'none';
        });

        // Переключение видимости при клике на заголовок
        document.querySelectorAll('.test-file__header').forEach(function(header) {
            header.addEventListener('click', function() {
                var body = this.parentElement.querySelector('.test-file__body, .test-file__error');
                if (body) {
                    if (body.style.display === 'none') {
                        body.style.display = '';
                    } else {
                        body.style.display = 'none';
                    }
                }
            });
        });
    </script>
</body>
</html>