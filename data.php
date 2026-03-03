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

            // Считаем суммы из платежей: реальная оплата и зачёт старого билета
            $amountInvoice = 0;
            $amountTicket = 0;
            if (!empty($product['PAYMENTS'])) {
                foreach ($product['PAYMENTS'] as $payment) {
                    $payAmount = isset($payment['EQUIVALENT_AMOUNT'])
                        ? (float)$payment['EQUIVALENT_AMOUNT']
                        : (float)(isset($payment['AMOUNT']) ? $payment['AMOUNT'] : 0);
                    if (isset($payment['TYPE']) && $payment['TYPE'] === 'TICKET') {
                        $amountTicket += $payAmount;
                    } else {
                        $amountInvoice += $payAmount;
                    }
                }
            }

            // Форматируем дату из ГГГГММДДччммсс в читаемый вид
            $orderDate = isset($order['INVOICE_DATA']) ? formatRstlsDate($order['INVOICE_DATA']) : '';
            $issueDate = isset($product['ISSUE_DATE']) ? formatRstlsDate($product['ISSUE_DATE']) : '';

            // --- Имя исходного XML-файла и дата парсинга ---
            $sourceXmlFile = isset($order['SOURCE_FILE']) ? $order['SOURCE_FILE'] : '';
            $parsedAt = isset($order['PARSED_AT']) ? $order['PARSED_AT'] : '';

            // --- Идентификация ---
            $orderUid = isset($order['UID']) ? $order['UID'] : '';
            $productUid = isset($product['UID']) ? $product['UID'] : '';
            $reservationNumber = isset($product['RESERVATION_NUMBER']) ? $product['RESERVATION_NUMBER'] : '';

            // --- Агенты (полиморфные) ---
            $bookingAgent = isset($product['BOOKING_AGENT']) ? formatAgent($product['BOOKING_AGENT']) : '';
            $agent = isset($product['AGENT']) ? formatAgent($product['AGENT']) : '';

            // --- Авиа-специфичные ---
            $ticketType = isset($product['TICKET_TYPE']) ? $product['TICKET_TYPE'] : '';
            $passengerAge = isset($product['PASSENGER_AGE']) ? $product['PASSENGER_AGE'] : '';
            $conjCount = isset($product['CONJ_COUNT']) ? $product['CONJ_COUNT'] : '';
            $penalty = isset($product['PENALTY']) ? (float)$product['PENALTY'] : 0;

            // --- Из купонов COUPONS[] ---
            // Все значения через запятую (для всех сегментов)
            $flightNumbers = '';
            $fareBasis = '';
            $classes = '';
            $departureDate = '';
            $arrivalDate = '';
            if (!empty($product['COUPONS'])) {
                $fn = array();
                $fb = array();
                $cl = array();
                $depDates = array();
                $arrDates = array();
                foreach ($product['COUPONS'] as $coupon) {
                    if (isset($coupon['FLIGHT_NUMBER'])) $fn[] = $coupon['FLIGHT_NUMBER'];
                    if (isset($coupon['FARE_BASIS'])) $fb[] = $coupon['FARE_BASIS'];
                    if (isset($coupon['CLASS'])) $cl[] = $coupon['CLASS'];
                    if (isset($coupon['DEPARTURE_DATETIME']) && $coupon['DEPARTURE_DATETIME'] !== '') {
                        $depDates[] = formatRstlsDate($coupon['DEPARTURE_DATETIME']);
                    }
                    if (isset($coupon['ARRIVAL_DATETIME']) && $coupon['ARRIVAL_DATETIME'] !== '') {
                        $arrDates[] = formatRstlsDate($coupon['ARRIVAL_DATETIME']);
                    }
                }
                $flightNumbers = implode(', ', $fn);
                $fareBasis = implode(', ', $fb);
                $classes = implode(', ', $cl);
                $departureDate = implode(', ', $depDates);
                $arrivalDate = implode(', ', $arrDates);
            }

            // --- Финансовые: из TAXES[] ---
            $tariffRub = 0;
            $taxesRub = 0;
            $vatTotal = 0;
            if (!empty($product['TAXES'])) {
                foreach ($product['TAXES'] as $tax) {
                    $eqAmt = isset($tax['EQUIVALENT_AMOUNT']) ? (float)$tax['EQUIVALENT_AMOUNT'] : 0;
                    $code = isset($tax['CODE']) ? $tax['CODE'] : '';
                    if ($code === '') {
                        $tariffRub += $eqAmt;
                    } else {
                        $taxesRub += $eqAmt;
                    }
                    if (isset($tax['VAT_AMOUNT']) && $tax['VAT_AMOUNT'] !== null) {
                        $vatTotal += (float)$tax['VAT_AMOUNT'];
                    }
                }
            }

            // --- Из PAYMENTS[] ---
            $paymentTypes = array();
            $relatedTicket = '';
            if (!empty($product['PAYMENTS'])) {
                foreach ($product['PAYMENTS'] as $payment) {
                    if (isset($payment['TYPE'])) $paymentTypes[] = $payment['TYPE'];
                    if (isset($payment['TYPE']) && $payment['TYPE'] === 'TICKET'
                        && isset($payment['RELATED_TICKET_NUMBER']) && $payment['RELATED_TICKET_NUMBER'] !== null) {
                        $relatedTicket = $payment['RELATED_TICKET_NUMBER'];
                    }
                }
            }
            $paymentTypesStr = implode(', ', $paymentTypes);

            // --- Из COMMISSIONS[] ---
            $commissionTkp = '';
            $commissionRate = '';
            $serviceFee = '';
            $supplierFee = '';
            if (!empty($product['COMMISSIONS'])) {
                foreach ($product['COMMISSIONS'] as $comm) {
                    $type = isset($comm['TYPE']) ? $comm['TYPE'] : '';
                    $name = isset($comm['NAME']) ? $comm['NAME'] : '';
                    $eqAmt = isset($comm['EQUIVALENT_AMOUNT']) ? $comm['EQUIVALENT_AMOUNT'] : '';
                    $rate = isset($comm['RATE']) ? $comm['RATE'] : '';

                    if ($type === 'VENDOR') {
                        $commissionTkp = $eqAmt;
                        $commissionRate = ($rate !== null && $rate !== '') ? $rate : '';
                    } elseif ($type === 'CLIENT') {
                        $nameLower = mb_strtolower($name, 'UTF-8');
                        if (mb_strpos($nameLower, 'ервисный сбор') !== false || mb_strpos($nameLower, 'сервисный сбор') !== false) {
                            $serviceFee = $eqAmt;
                        } elseif (mb_strpos($nameLower, 'сбор поставщика') !== false) {
                            $supplierFee = $eqAmt;
                        }
                    }
                }
            }

            // --- Блок REFUND ---
            $refundDate = '';
            $refundAmount = '';
            $refundFeeClient = '';
            $refundFeeVendor = '';
            $refundPenaltyVendor = '';
            $refundPenaltyClient = '';
            if (!empty($product['REFUND'])) {
                $r = $product['REFUND'];
                $refundDate = isset($r['DATA']) ? formatRstlsDate($r['DATA']) : '';
                $refundAmount = isset($r['EQUIVALENT_AMOUNT']) ? $r['EQUIVALENT_AMOUNT'] : '';
                $refundFeeClient = isset($r['FEE_CLIENT']) ? $r['FEE_CLIENT'] : '';
                $refundFeeVendor = isset($r['FEE_VENDOR']) ? $r['FEE_VENDOR'] : '';
                $refundPenaltyVendor = isset($r['PENALTY_VENDOR']) ? $r['PENALTY_VENDOR'] : '';
                $refundPenaltyClient = (isset($r['PENALTY_CLIENT']) && $r['PENALTY_CLIENT'] !== null) ? $r['PENALTY_CLIENT'] : '';
            }

            $rows[] = array(
                // --- Существующие 14 полей ---
                'file'             => $fileName,
                'invoice_num'      => isset($order['INVOICE_NUMBER']) ? $order['INVOICE_NUMBER'] : '',
                'invoice_date'     => $orderDate,
                'client'           => isset($order['CLIENT']) ? $order['CLIENT'] : '',
                'product_type'     => isset($product['PRODUCT_TYPE']['NAME']) ? $product['PRODUCT_TYPE']['NAME'] : '',
                'number'           => isset($product['NUMBER']) ? $product['NUMBER'] : '',
                'issue_date'       => $issueDate,
                'status'           => isset($product['STATUS']) ? $product['STATUS'] : '',
                'traveller'        => isset($product['TRAVELLER']) ? $product['TRAVELLER'] : '',
                'supplier'         => isset($product['SUPPLIER']) ? $product['SUPPLIER'] : '',
                'carrier'          => isset($product['CARRIER']) ? $product['CARRIER'] : '',
                'route'            => $route,
                'amount'           => $amountInvoice,
                'currency'         => isset($product['CURRENCY']) ? $product['CURRENCY'] : '',

                // --- Новые поля ---
                'source_xml'       => $sourceXmlFile,
                'parsed_at'        => $parsedAt,
                'order_uid'        => $orderUid,
                'product_uid'      => $productUid,
                'reservation_num'  => $reservationNumber,
                'booking_agent'    => $bookingAgent,
                'agent'            => $agent,
                'ticket_type'      => $ticketType,
                'passenger_age'    => $passengerAge,
                'conj_count'       => $conjCount,
                'penalty'          => $penalty,
                'flight_numbers'   => $flightNumbers,
                'fare_basis'       => $fareBasis,
                'classes'          => $classes,
                'departure_date'   => $departureDate,
                'arrival_date'     => $arrivalDate,
                'tariff_rub'       => $tariffRub,
                'taxes_rub'        => $taxesRub,
                'vat_total'        => $vatTotal,
                'payment_types'    => $paymentTypesStr,
                'payment_amount'   => $amountInvoice,
                'ticket_amount'    => $amountTicket,
                'related_ticket'   => $relatedTicket,
                'commission_tkp'   => $commissionTkp,
                'commission_rate'  => $commissionRate,
                'service_fee'      => $serviceFee,
                'supplier_fee'     => $supplierFee,
                'refund_date'      => $refundDate,
                'refund_amount'    => $refundAmount,
                'refund_fee_client'     => $refundFeeClient,
                'refund_fee_vendor'     => $refundFeeVendor,
                'refund_penalty_vendor' => $refundPenaltyVendor,
                'refund_penalty_client' => $refundPenaltyClient,
            );
        }
    }
}

// Сортировка: самые свежие (по дате парсинга) — сверху
usort($rows, function ($a, $b) {
    $dateA = $a['parsed_at'] ?: '0000-00-00 00:00:00';
    $dateB = $b['parsed_at'] ?: '0000-00-00 00:00:00';
    return strcmp($dateB, $dateA);
});

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

/**
 * Извлекает читаемое значение агента.
 * Поле BOOKING_AGENT и AGENT могут быть:
 * - объектом {"CODE": "020", "NAME": "Elena Vetvitskaya"}
 * - строкой UUID "c8a153d3-0175-11eb-9f32-0050569c2148"
 */
/**
 * Извлекает читаемое значение агента.
 * 
 * BOOKING_AGENT и AGENT — объект {"CODE": "...", "NAME": "..."}.
 * Если CODE и NAME совпадают — возвращаем одно значение (без дублирования).
 * Если разные — "CODE NAME".
 * Если строка — возвращаем как есть.
 */
function formatAgent($agent)
{
    if (is_array($agent) && isset($agent['CODE'])) {
        $code = trim(isset($agent['CODE']) ? $agent['CODE'] : '');
        $name = trim(isset($agent['NAME']) ? $agent['NAME'] : '');

        // Если CODE и NAME одинаковые — не дублируем
        if ($code !== '' && $code === $name) {
            return $code;
        }
        // Если есть оба и разные
        if ($code !== '' && $name !== '') {
            return $code . ' ' . $name;
        }
        // Если только одно из двух
        return ($code !== '') ? $code : $name;
    }
    if (is_string($agent)) {
        return $agent;
    }
    return '';
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
                    <a href="api_logs.php" class="nav__link">Логи API</a>
                    <a href="test.php" class="nav__link">Тесты</a>
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
                <?php if (!empty($rows)): ?>
                    <button onclick="document.querySelector('.data-table tbody').innerHTML=''; this.style.display='none'" style="margin-left:15px; padding:4px 12px; cursor:pointer">Очистить таблицу</button>
                <?php endif; ?>
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
                                <th class="data-table__th" style="width:40px"></th>
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
                                <th class="data-table__th">Исходный файл</th>
                                <th class="data-table__th data-table__td--nowrap">Дата загрузки</th>
                                <th class="data-table__th">UID заказа</th>
                                <th class="data-table__th">UID продукта</th>
                                <th class="data-table__th">Номер брони</th>
                                <th class="data-table__th">Выпис. агент</th>
                                <th class="data-table__th">Агент</th>
                                <th class="data-table__th">Тип билета</th>
                                <th class="data-table__th">Возраст</th>
                                <th class="data-table__th">Бланки</th>
                                <th class="data-table__th data-table__th--right">Штраф обмен</th>
                                <th class="data-table__th">Рейсы</th>
                                <th class="data-table__th">Fare Basis</th>
                                <th class="data-table__th">Класс</th>
                                <th class="data-table__th data-table__td--nowrap">Дата вылета</th>
                                <th class="data-table__th data-table__td--nowrap">Дата прилёта</th>
                                <th class="data-table__th data-table__th--right">Тариф (руб)</th>
                                <th class="data-table__th data-table__th--right">Таксы (руб)</th>
                                <th class="data-table__th data-table__th--right">НДС (руб)</th>
                                <th class="data-table__th">Тип платежа</th>
                                <th class="data-table__th data-table__th--right">Оплата (руб)</th>
                                <th class="data-table__th data-table__th--right">Зачёт (руб)</th>
                                <th class="data-table__th">Связ. билет</th>
                                <th class="data-table__th data-table__th--right">Комиссия ТКП</th>
                                <th class="data-table__th">Ставка %</th>
                                <th class="data-table__th data-table__th--right">Серв. сбор</th>
                                <th class="data-table__th data-table__th--right">Сбор пост.</th>
                                <th class="data-table__th data-table__td--nowrap">Дата возврата</th>
                                <th class="data-table__th data-table__th--right">Сумма возврата</th>
                                <th class="data-table__th data-table__th--right">Сбор РСТЛС</th>
                                <th class="data-table__th data-table__th--right">Сбор пост. возвр.</th>
                                <th class="data-table__th data-table__th--right">Штраф пост.</th>
                                <th class="data-table__th data-table__th--right">Штраф РСТЛС</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($rows as $i => $row): ?>
                                <tr class="data-table__row">
                                <td class="data-table__td" style="text-align:center">
    <button class="btn-resend" title="Отправить повторно в API 1С" onclick="resendJson('<?php echo htmlspecialchars($row['file'], ENT_QUOTES); ?>', this)">🔄</button>
</td>
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
                                            $statusLower = mb_strtolower($row['status'], 'UTF-8');
                                            if ($statusLower === 'возврат') {
                                                $statusClass = 'data-status--refund';
                                            } elseif ($statusLower === 'обмен') {
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
                                    <td class="data-table__td data-table__td--file"><?php echo htmlspecialchars($row['source_xml']); ?></td>
                                    <td class="data-table__td data-table__td--nowrap"><?php echo htmlspecialchars($row['parsed_at']); ?></td>
                                    <td class="data-table__td data-table__td--file" title="<?php echo htmlspecialchars($row['order_uid']); ?>"><?php echo htmlspecialchars($row['order_uid'] ? substr($row['order_uid'], 0, 8) . '...' : ''); ?></td>
                                    <td class="data-table__td data-table__td--file" title="<?php echo htmlspecialchars($row['product_uid']); ?>"><?php echo htmlspecialchars($row['product_uid'] ? substr($row['product_uid'], 0, 8) . '...' : ''); ?></td>
                                    <td class="data-table__td"><?php echo htmlspecialchars($row['reservation_num']); ?></td>
                                    <td class="data-table__td"><?php echo htmlspecialchars($row['booking_agent']); ?></td>
                                    <td class="data-table__td"><?php echo htmlspecialchars($row['agent']); ?></td>
                                    <td class="data-table__td"><?php echo htmlspecialchars($row['ticket_type']); ?></td>
                                    <td class="data-table__td"><?php echo htmlspecialchars($row['passenger_age']); ?></td>
                                    <td class="data-table__td data-table__td--right"><?php echo $row['conj_count'] !== '' ? htmlspecialchars($row['conj_count']) : ''; ?></td>
                                    <td class="data-table__td data-table__td--right"><?php echo $row['penalty'] > 0 ? number_format($row['penalty'], 2, '.', ' ') : ''; ?></td>
                                    <td class="data-table__td"><?php echo htmlspecialchars($row['flight_numbers']); ?></td>
                                    <td class="data-table__td"><?php echo htmlspecialchars($row['fare_basis']); ?></td>
                                    <td class="data-table__td"><?php echo htmlspecialchars($row['classes']); ?></td>
                                    <td class="data-table__td data-table__td--nowrap"><?php echo htmlspecialchars($row['departure_date']); ?></td>
                                    <td class="data-table__td data-table__td--nowrap"><?php echo htmlspecialchars($row['arrival_date']); ?></td>
                                    <td class="data-table__td data-table__td--right"><?php echo $row['tariff_rub'] > 0 ? number_format($row['tariff_rub'], 2, '.', ' ') : ''; ?></td>
                                    <td class="data-table__td data-table__td--right"><?php echo $row['taxes_rub'] > 0 ? number_format($row['taxes_rub'], 2, '.', ' ') : ''; ?></td>
                                    <td class="data-table__td data-table__td--right"><?php echo $row['vat_total'] > 0 ? number_format($row['vat_total'], 2, '.', ' ') : ''; ?></td>
                                    <td class="data-table__td"><?php echo htmlspecialchars($row['payment_types']); ?></td>
                                    <td class="data-table__td data-table__td--right"><?php echo number_format($row['payment_amount'], 2, '.', ' '); ?></td>
                                    <td class="data-table__td data-table__td--right"><?php echo $row['ticket_amount'] > 0 ? number_format($row['ticket_amount'], 2, '.', ' ') : ''; ?></td>
                                    <td class="data-table__td"><?php echo htmlspecialchars($row['related_ticket']); ?></td>
                                    <td class="data-table__td data-table__td--right"><?php echo is_numeric($row['commission_tkp']) ? number_format((float)$row['commission_tkp'], 2, '.', ' ') : ''; ?></td>
                                    <td class="data-table__td"><?php echo $row['commission_rate'] !== '' ? htmlspecialchars($row['commission_rate']) . '%' : ''; ?></td>
                                    <td class="data-table__td data-table__td--right"><?php echo is_numeric($row['service_fee']) ? number_format((float)$row['service_fee'], 2, '.', ' ') : ''; ?></td>
                                    <td class="data-table__td data-table__td--right"><?php echo is_numeric($row['supplier_fee']) ? number_format((float)$row['supplier_fee'], 2, '.', ' ') : ''; ?></td>
                                    <td class="data-table__td data-table__td--nowrap"><?php echo htmlspecialchars($row['refund_date']); ?></td>
                                    <td class="data-table__td data-table__td--right"><?php echo is_numeric($row['refund_amount']) ? number_format((float)$row['refund_amount'], 2, '.', ' ') : ''; ?></td>
                                    <td class="data-table__td data-table__td--right"><?php echo is_numeric($row['refund_fee_client']) ? number_format((float)$row['refund_fee_client'], 2, '.', ' ') : ''; ?></td>
                                    <td class="data-table__td data-table__td--right"><?php echo is_numeric($row['refund_fee_vendor']) ? number_format((float)$row['refund_fee_vendor'], 2, '.', ' ') : ''; ?></td>
                                    <td class="data-table__td data-table__td--right"><?php echo is_numeric($row['refund_penalty_vendor']) ? number_format((float)$row['refund_penalty_vendor'], 2, '.', ' ') : ''; ?></td>
                                    <td class="data-table__td data-table__td--right"><?php echo ($row['refund_penalty_client'] !== '') ? number_format((float)$row['refund_penalty_client'], 2, '.', ' ') : ''; ?></td>
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
    <script>
function resendJson(fileName, btn) {
    if (!confirm('Отправить ' + fileName + ' повторно в API 1С?')) return;

    btn.disabled = true;
    btn.textContent = '⏳';

    fetch('api.php?action=resend', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({file: fileName})
    })
    .then(function(r) { return r.json(); })
    .then(function(data) {
        if (data.status === 'ok') {
            btn.textContent = '✅';
            btn.title = data.message;
        } else {
            btn.textContent = '❌';
            btn.title = data.message;
            alert('Ошибка: ' + data.message);
        }
    })
    .catch(function(err) {
        btn.textContent = '❌';
        btn.title = 'Ошибка сети';
        alert('Ошибка сети: ' + err);
    })
    .finally(function() {
        btn.disabled = false;
        setTimeout(function() { btn.textContent = '🔄'; }, 5000);
    });
}
</script>

<script>
// Изменение ширины колонок перетаскиванием
document.addEventListener('DOMContentLoaded', function() {
    var table = document.querySelector('.data-table');
    if (!table) return;

    var headers = table.querySelectorAll('th');

    // 1. Зафиксировать ЕСТЕСТВЕННЫЕ ширины (до table-layout:fixed)
    var widths = [];
    headers.forEach(function(th, i) {
        widths[i] = th.offsetWidth;
    });

    // 2. Применить ширины и включить fixed layout
    headers.forEach(function(th, i) {
        th.style.width = widths[i] + 'px';
    });
    table.style.tableLayout = 'fixed';

    // 3. Добавить ресайзеры
    headers.forEach(function(th) {
        var resizer = document.createElement('div');
        resizer.style.cssText = 'position:absolute; right:0; top:0; width:5px; height:100%; cursor:col-resize; user-select:none; z-index:1;';

        th.style.position = 'relative';
        th.style.overflow = 'hidden';
        th.style.textOverflow = 'ellipsis';
        th.style.whiteSpace = 'nowrap';
        th.appendChild(resizer);

        resizer.addEventListener('mousedown', function(e) {
            var startX = e.pageX;
            var startWidth = th.offsetWidth;

            // Подсветка при перетаскивании
            resizer.style.borderRight = '2px solid #4a90d9';

            function onMouseMove(e) {
                var newWidth = startWidth + (e.pageX - startX);
                if (newWidth >= 50) {
                    th.style.width = newWidth + 'px';
                }
            }

            function onMouseUp() {
                resizer.style.borderRight = '';
                document.removeEventListener('mousemove', onMouseMove);
                document.removeEventListener('mouseup', onMouseUp);
            }

            document.addEventListener('mousemove', onMouseMove);
            document.addEventListener('mouseup', onMouseUp);
            e.preventDefault();
        });
    });
});
</script>
</body>
</html>
