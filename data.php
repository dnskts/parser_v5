<?php
/**
 * ============================================================
 * СТРАНИЦА ПРОСМОТРА ОБРАБОТАННЫХ ЗАКАЗОВ
 * ============================================================
 * 
 * Эта страница читает все JSON-файлы из папки json/,
 * «разворачивает» данные (один продукт = одна строка таблицы)
 * и выводит результат в виде таблицы во всю ширину экрана.
 * 
 * Данные отображаются в формате ORDER (спецификация RSTLS):
 * заказы с продуктами — авиабилеты, ЖД-билеты, отели.
 * ============================================================
 */

// Папка, где хранятся выходные JSON-файлы
$jsonDir = __DIR__ . '/json';

// -------------------------------------------------------
// Читаем все JSON-файлы и собираем строки для таблицы
// -------------------------------------------------------

$rows = array();

if (is_dir($jsonDir)) {
    // Получаем список всех .json файлов, отсортированных по дате (новые первые)
    $files = glob($jsonDir . '/*.json');

    // Сортируем по времени изменения — новые файлы сверху
    usort($files, function ($a, $b) {
        return filemtime($b) - filemtime($a);
    });

    foreach ($files as $file) {
        $fileName = basename($file);
        $content = file_get_contents($file);
        $order = json_decode($content, true);

        // Пропускаем файлы, которые не удалось прочитать или невалидные
        if (!is_array($order) || !isset($order['PRODUCTS'])) {
            continue;
        }

        // Каждый продукт внутри заказа — отдельная строка таблицы
        foreach ($order['PRODUCTS'] as $product) {
            // Формируем маршрут из купонов (авиа) или сегментов (ЖД)
            $route = '';
            if (!empty($product['COUPONS'])) {
                $points = array();
                foreach ($product['COUPONS'] as $coupon) {
                    if (empty($points)) {
                        $points[] = isset($coupon['DEPARTURE_AIRPORT']) ? $coupon['DEPARTURE_AIRPORT'] : '';
                    }
                    $points[] = isset($coupon['ARRIVAL_AIRPORT']) ? $coupon['ARRIVAL_AIRPORT'] : '';
                }
                $route = implode(' → ', array_filter($points));
            } elseif (!empty($product['SEGMENTS'])) {
                $points = array();
                foreach ($product['SEGMENTS'] as $seg) {
                    if (empty($points)) {
                        $points[] = isset($seg['DEPARTURE_RAILWAY_STATION']) ? $seg['DEPARTURE_RAILWAY_STATION'] : '';
                    }
                    $points[] = isset($seg['ARRIVAL_RAILWAY_STATION']) ? $seg['ARRIVAL_RAILWAY_STATION'] : '';
                }
                $route = implode(' → ', array_filter($points));
            } elseif (!empty($product['HOTEL'])) {
                $route = $product['HOTEL'];
            }

            // Считаем общую сумму из платежей
            $totalAmount = 0;
            if (!empty($product['PAYMENTS'])) {
                foreach ($product['PAYMENTS'] as $payment) {
                    $totalAmount += isset($payment['EQUIVALENT_AMOUNT']) 
                        ? (float)$payment['EQUIVALENT_AMOUNT'] 
                        : (float)(isset($payment['AMOUNT']) ? $payment['AMOUNT'] : 0);
                }
            }

            // Форматируем дату из ГГГГММДДччммсс в читаемый вид
            $orderDate = isset($order['INVOICE_DATA']) ? formatRstlsDate($order['INVOICE_DATA']) : '';
            $issueDate = isset($product['ISSUE_DATE']) ? formatRstlsDate($product['ISSUE_DATE']) : '';

            $rows[] = array(
                'file'        => $fileName,
                'invoice_num' => isset($order['INVOICE_NUMBER']) ? $order['INVOICE_NUMBER'] : '',
                'invoice_date'=> $orderDate,
                'client'      => isset($order['CLIENT']) ? $order['CLIENT'] : '',
                'product_type'=> isset($product['PRODUCT_TYPE']['NAME']) ? $product['PRODUCT_TYPE']['NAME'] : '',
                'number'      => isset($product['NUMBER']) ? $product['NUMBER'] : '',
                'issue_date'  => $issueDate,
                'status'      => isset($product['STATUS']) ? $product['STATUS'] : '',
                'traveller'   => isset($product['TRAVELLER']) ? $product['TRAVELLER'] : '',
                'supplier'    => isset($product['SUPPLIER']) ? $product['SUPPLIER'] : '',
                'carrier'     => isset($product['CARRIER']) ? $product['CARRIER'] : '',
                'route'       => $route,
                'amount'      => $totalAmount,
                'currency'    => isset($product['CURRENCY']) ? $product['CURRENCY'] : '',
            );
        }
    }
}

/**
 * Форматирует дату из формата RSTLS (ГГГГММДДччммсс) в читаемый вид.
 * 
 * Вход:  "20251013121600"
 * Выход: "13.10.2025 12:16"
 * 
 * @param string $date — дата в формате ГГГГММДДччммсс
 * @return string — дата в формате ДД.ММ.ГГГГ ЧЧ:ММ
 */
function formatRstlsDate($date)
{
    if (strlen($date) < 12) {
        return $date;
    }
    $year   = substr($date, 0, 4);
    $month  = substr($date, 4, 2);
    $day    = substr($date, 6, 2);
    $hour   = substr($date, 8, 2);
    $minute = substr($date, 10, 2);
    return "{$day}.{$month}.{$year} {$hour}:{$minute}";
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>XML Parser — Обработанные заказы</title>
    <link rel="stylesheet" href="assets/style.css">
</head>
<body>
    <!-- ============================================ -->
    <!-- ШАПКА С НАВИГАЦИЕЙ                           -->
    <!-- ============================================ -->
    <header class="header">
        <div class="header__content header__content--wide">
            <div class="header__top">
                <div>
                    <h1 class="header__title">XML Parser</h1>
                    <p class="header__subtitle">Обработанные заказы</p>
                </div>
                <nav class="nav">
                    <a href="index.php" class="nav__link">Панель управления</a>
                    <a href="data.php" class="nav__link nav__link--active">Обработанные заказы</a>
                </nav>
            </div>
        </div>
    </header>

    <!-- ============================================ -->
    <!-- ОСНОВНОЕ СОДЕРЖИМОЕ — ТАБЛИЦА ЗАКАЗОВ        -->
    <!-- ============================================ -->
    <main class="main main--wide">
        <section class="panel">
            <div class="panel__title-row">
                <h2 class="panel__title panel__title--inline">
                    Данные из JSON-файлов
                </h2>
                <span class="panel__count">
                    Найдено записей: <?php echo count($rows); ?>
                </span>
            </div>

            <?php if (empty($rows)): ?>
                <!-- Сообщение, если данных нет -->
                <div class="data-empty">
                    <p>Нет обработанных файлов.</p>
                    <p>Положите XML-файлы в папку input/ и запустите обработку 
                       на <a href="index.php">панели управления</a>.</p>
                </div>
            <?php else: ?>
                <!-- Обёртка для горизонтальной прокрутки на узких экранах -->
                <div class="data-table-wrapper">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th class="data-table__th">#</th>
                                <th class="data-table__th">Файл</th>
                                <th class="data-table__th">Номер заказа</th>
                                <th class="data-table__th">Дата заказа</th>
                                <th class="data-table__th">Клиент</th>
                                <th class="data-table__th">Тип продукта</th>
                                <th class="data-table__th">Номер билета</th>
                                <th class="data-table__th">Дата выписки</th>
                                <th class="data-table__th">Статус</th>
                                <th class="data-table__th">Пассажир</th>
                                <th class="data-table__th">Поставщик</th>
                                <th class="data-table__th">Перевозчик</th>
                                <th class="data-table__th">Маршрут</th>
                                <th class="data-table__th data-table__th--right">Сумма</th>
                                <th class="data-table__th">Валюта</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($rows as $i => $row): ?>
                                <tr class="data-table__row">
                                    <td class="data-table__td data-table__td--num"><?php echo $i + 1; ?></td>
                                    <td class="data-table__td data-table__td--file" title="<?php echo htmlspecialchars($row['file']); ?>">
                                        <?php echo htmlspecialchars($row['file']); ?>
                                    </td>
                                    <td class="data-table__td"><?php echo htmlspecialchars($row['invoice_num']); ?></td>
                                    <td class="data-table__td data-table__td--nowrap"><?php echo htmlspecialchars($row['invoice_date']); ?></td>
                                    <td class="data-table__td"><?php echo htmlspecialchars($row['client']); ?></td>
                                    <td class="data-table__td"><?php echo htmlspecialchars($row['product_type']); ?></td>
                                    <td class="data-table__td"><?php echo htmlspecialchars($row['number']); ?></td>
                                    <td class="data-table__td data-table__td--nowrap"><?php echo htmlspecialchars($row['issue_date']); ?></td>
                                    <td class="data-table__td">
                                        <?php
                                            // Определяем CSS-класс для значка статуса
                                            $statusClass = 'data-status--sale';
                                            if ($row['status'] === 'возврат') {
                                                $statusClass = 'data-status--refund';
                                            } elseif ($row['status'] === 'обмен') {
                                                $statusClass = 'data-status--exchange';
                                            }
                                        ?>
                                        <span class="data-status <?php echo $statusClass; ?>">
                                            <?php echo htmlspecialchars($row['status']); ?>
                                        </span>
                                    </td>
                                    <td class="data-table__td"><?php echo htmlspecialchars($row['traveller']); ?></td>
                                    <td class="data-table__td"><?php echo htmlspecialchars($row['supplier']); ?></td>
                                    <td class="data-table__td"><?php echo htmlspecialchars($row['carrier']); ?></td>
                                    <td class="data-table__td data-table__td--route"><?php echo htmlspecialchars($row['route']); ?></td>
                                    <td class="data-table__td data-table__td--right">
                                        <?php echo number_format($row['amount'], 2, '.', ' '); ?>
                                    </td>
                                    <td class="data-table__td"><?php echo htmlspecialchars($row['currency']); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </section>
    </main>

    <footer class="footer">
        <p>XML Parser v5 — Система обработки файлов поставщиков</p>
    </footer>
</body>
</html>
