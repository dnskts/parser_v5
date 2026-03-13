<?php
/**
 * ============================================================
 * СТРАНИЦА ПРОСМОТРА ОБРАБОТАННЫХ ЗАКАЗОВ
 * ============================================================
 */

$jsonDir = __DIR__ . '/json';
$rows = array();
$filesTotal = 0;

if (is_dir($jsonDir)) {
    $files = glob($jsonDir . '/*.json');
    $filesTotal = count($files);
    usort($files, function ($a, $b) { return filemtime($b) - filemtime($a); });
    $files = array_slice($files, 0, 100);

    foreach ($files as $file) {
        $fileName = basename($file);
        $content = file_get_contents($file);
        $order = json_decode($content, true);

        if (!is_array($order) || !isset($order['PRODUCTS'])) {
            continue;
        }

        foreach ($order['PRODUCTS'] as $product) {
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

            $orderDate = isset($order['INVOICE_DATA']) ? formatRstlsDate($order['INVOICE_DATA']) : '';
            $issueDate = isset($product['ISSUE_DATE']) ? formatRstlsDate($product['ISSUE_DATE']) : '';
            $sourceXmlFile = isset($order['SOURCE_FILE']) ? $order['SOURCE_FILE'] : '';
            $parsedAt = isset($order['PARSED_AT']) ? $order['PARSED_AT'] : '';
            $orderUid = isset($order['UID']) ? $order['UID'] : '';
            $productUid = isset($product['UID']) ? $product['UID'] : '';
            $reservationNumber = isset($product['RESERVATION_NUMBER']) ? $product['RESERVATION_NUMBER'] : '';
            $bookingAgent = isset($product['BOOKING_AGENT']) ? formatAgent($product['BOOKING_AGENT']) : '';
            $agent = isset($product['AGENT']) ? formatAgent($product['AGENT']) : '';
            $ticketType = isset($product['TICKET_TYPE']) ? $product['TICKET_TYPE'] : '';
            $passengerAge = isset($product['PASSENGER_AGE']) ? $product['PASSENGER_AGE'] : '';
            $conjCount = isset($product['CONJ_COUNT']) ? $product['CONJ_COUNT'] : '';
            $penalty = isset($product['PENALTY']) ? (float)$product['PENALTY'] : 0;

            $flightNumbers = '';
            $fareBasis = '';
            $classes = '';
            $departureDate = '';
            $arrivalDate = '';
            if (!empty($product['COUPONS'])) {
                $fn = array(); $fb = array(); $cl = array(); $depDates = array(); $arrDates = array();
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

            $tariffRub = 0; $taxesRub = 0; $vatTotal = 0;
            if (!empty($product['TAXES'])) {
                foreach ($product['TAXES'] as $tax) {
                    $eqAmt = isset($tax['EQUIVALENT_AMOUNT']) ? (float)$tax['EQUIVALENT_AMOUNT'] : 0;
                    $code = isset($tax['CODE']) ? $tax['CODE'] : '';
                    if ($code === '') { $tariffRub += $eqAmt; } else { $taxesRub += $eqAmt; }
                    if (isset($tax['VAT_AMOUNT']) && $tax['VAT_AMOUNT'] !== null) {
                        $vatTotal += (float)$tax['VAT_AMOUNT'];
                    }
                }
            }

            $paymentTypes = array(); $relatedTicket = '';
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

            $commissionTkp = ''; $commissionRate = ''; $serviceFee = ''; $supplierFee = '';
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
                        if (mb_strpos($nameLower, 'сервисный сбор') !== false || mb_strpos($nameLower, 'ервисный сбор') !== false) {
                            $serviceFee = $eqAmt;
                        } elseif (mb_strpos($nameLower, 'сбор поставщика') !== false) {
                            $supplierFee = $eqAmt;
                        }
                    }
                }
            }

            $refundDate = ''; $refundAmount = ''; $refundFeeClient = '';
            $refundFeeVendor = ''; $refundPenaltyVendor = ''; $refundPenaltyClient = '';
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
                'file' => $fileName,
                'invoice_num' => isset($order['INVOICE_NUMBER']) ? $order['INVOICE_NUMBER'] : '',
                'invoice_date' => $orderDate,
                'client' => isset($order['CLIENT']) ? $order['CLIENT'] : '',
                'product_type' => isset($product['PRODUCT_TYPE']['NAME']) ? $product['PRODUCT_TYPE']['NAME'] : '',
                'number' => isset($product['NUMBER']) ? $product['NUMBER'] : '',
                'issue_date' => $issueDate,
                'issue_date_raw' => isset($product['ISSUE_DATE']) ? $product['ISSUE_DATE'] : '',
                'status' => isset($product['STATUS']) ? $product['STATUS'] : '',
                'traveller' => isset($product['TRAVELLER']) ? $product['TRAVELLER'] : '',
                'supplier' => isset($product['SUPPLIER']) ? $product['SUPPLIER'] : '',
                'carrier' => isset($product['CARRIER']) ? $product['CARRIER'] : '',
                'route' => $route,
                'amount' => $amountInvoice,
                'currency' => isset($product['CURRENCY']) ? $product['CURRENCY'] : '',
                'source_xml' => $sourceXmlFile,
                'parsed_at' => $parsedAt,
                'order_uid' => $orderUid,
                'product_uid' => $productUid,
                'reservation_num' => $reservationNumber,
                'booking_agent' => $bookingAgent,
                'agent' => $agent,
                'ticket_type' => $ticketType,
                'passenger_age' => $passengerAge,
                'passenger_birth_date' => isset($product['PASSENGER_BIRTH_DATE']) ? $product['PASSENGER_BIRTH_DATE'] : '',
                'passenger_gender' => isset($product['PASSENGER_GENDER']) ? $product['PASSENGER_GENDER'] : '',
                'passenger_doc_type' => isset($product['PASSENGER_DOC_TYPE']) ? $product['PASSENGER_DOC_TYPE'] : '',
                'passenger_doc_number' => isset($product['PASSENGER_DOC_NUMBER']) ? $product['PASSENGER_DOC_NUMBER'] : '',
                'conj_count' => $conjCount,
                'penalty' => $penalty,
                'flight_numbers' => $flightNumbers,
                'fare_basis' => $fareBasis,
                'classes' => $classes,
                'departure_date' => $departureDate,
                'arrival_date' => $arrivalDate,
                'tariff_rub' => $tariffRub,
                'taxes_rub' => $taxesRub,
                'vat_total' => $vatTotal,
                'payment_types' => $paymentTypesStr,
                'payment_amount' => $amountInvoice,
                'ticket_amount' => $amountTicket,
                'related_ticket' => $relatedTicket,
                'emd_name' => isset($product['EMD_NAME']) ? $product['EMD_NAME'] : '',
                'related_ticket_emd' => isset($product['RELATED_TICKET_NUMBER']) ? $product['RELATED_TICKET_NUMBER'] : '',
                'commission_tkp' => $commissionTkp,
                'commission_rate' => $commissionRate,
                'service_fee' => $serviceFee,
                'supplier_fee' => $supplierFee,
                'refund_date' => $refundDate,
                'refund_amount' => $refundAmount,
                'refund_fee_client' => $refundFeeClient,
                'refund_fee_vendor' => $refundFeeVendor,
                'refund_penalty_vendor' => $refundPenaltyVendor,
                'refund_penalty_client' => $refundPenaltyClient,
            );
        }
    }
}

$sortBy = isset($_GET['sort']) ? $_GET['sort'] : 'parsed_at';
$sortDir = isset($_GET['dir']) && $_GET['dir'] === 'asc' ? 'asc' : 'desc';
$allowedSort = array('parsed_at', 'issue_date');
if (!in_array($sortBy, $allowedSort)) { $sortBy = 'parsed_at'; }

usort($rows, function ($a, $b) use ($sortBy, $sortDir) {
    $key = ($sortBy === 'issue_date') ? 'issue_date_raw' : 'parsed_at';
    $va = $a[$key] ?: ($sortBy === 'parsed_at' ? '0000-00-00 00:00:00' : '00000000000000');
    $vb = $b[$key] ?: ($sortBy === 'parsed_at' ? '0000-00-00 00:00:00' : '00000000000000');
    $cmp = strcmp($va, $vb);
    return ($sortDir === 'asc') ? $cmp : -$cmp;
});

function formatRstlsDate($date)
{
    if (strlen($date) < 12) { return $date; }
    return substr($date,6,2).'.'.substr($date,4,2).'.'.substr($date,0,4).' '.substr($date,8,2).':'.substr($date,10,2);
}

function formatAgent($agent)
{
    if (is_array($agent) && isset($agent['CODE'])) {
        $code = trim(isset($agent['CODE']) ? $agent['CODE'] : '');
        $name = trim(isset($agent['NAME']) ? $agent['NAME'] : '');
        if ($code !== '' && $code === $name) { return $code; }
        if ($code !== '' && $name !== '') { return $code . ' ' . $name; }
        return ($code !== '') ? $code : $name;
    }
    if (is_string($agent)) { return $agent; }
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
    <header class="header header--compact">
        <div class="header__content header__content--wide">
            <div class="header__top">
                <div>
                    <h1 class="header__title">XML Parser</h1>
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

    <main class="main main--wide">
        <section class="panel panel--compact">
            <div class="panel__title-row">
                <div class="panel__title-group">
                    <div class="panel__title-text">
                        <h2 class="panel__title panel__title--inline">Данные из JSON-файлов</h2>
                        <span class="panel__count">Найдено записей: <?php echo count($rows); ?><?php if ($filesTotal > 100): ?> (последние 100 из <?php echo $filesTotal; ?> файлов)<?php endif; ?></span>
                    </div>
                    <?php if (!empty($rows)): ?>
                    <div class="panel__toolbar-filter">
                        
                        <input type="text" id="filterInput" class="panel__toolbar-input" placeholder="Поиск по всем колонкам...">
                        <span id="filterCount" class="panel__toolbar-count"></span>
                    </div>
                    <?php endif; ?>
                </div>
                <?php if (!empty($rows)): ?>
                <div class="panel__title-actions">
                    <div class="panel__toolbar panel__toolbar--inline">
                        <div class="panel__toolbar-sort">
                            <span>Сортировка:</span>
                            <a href="?sort=issue_date&dir=<?php echo ($sortBy==='issue_date'&&$sortDir==='asc')?'desc':'asc'; ?>" class="panel__toolbar-sort-link<?php echo $sortBy==='issue_date'?' panel__toolbar-sort-link--active':''; ?>">Дата выписки <?php echo $sortBy==='issue_date'?($sortDir==='asc'?'↑':'↓'):''; ?></a>
                            <a href="?sort=parsed_at&dir=<?php echo ($sortBy==='parsed_at'&&$sortDir==='asc')?'desc':'asc'; ?>" class="panel__toolbar-sort-link<?php echo $sortBy==='parsed_at'?' panel__toolbar-sort-link--active':''; ?>">Дата загрузки <?php echo $sortBy==='parsed_at'?($sortDir==='asc'?'↑':'↓'):''; ?></a>
                        </div>
                        <button id="btnExportXlsx" class="panel__toolbar-export">Выгрузить в XLSX</button>
                    </div>
                    <button class="panel__toolbar-clear btn btn--secondary" onclick="document.querySelector('.data-table tbody').innerHTML=''; this.style.display='none'">Очистить таблицу</button>
                </div>
                <?php endif; ?>
            </div>

            <?php if (empty($rows)): ?>
                <div class="data-empty">
                    <p>Нет обработанных файлов.</p>
                    <p>Положите XML-файлы в папку input/ и запустите обработку на <a href="index.php">панели управления</a>.</p>
                </div>
            <?php else: ?>
                <div class="data-table-wrapper">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th class="data-table__th" data-col-index="0" draggable="true" style="width:40px"></th>
                                <th class="data-table__th" data-col-index="1" draggable="true">#</th>
                                <th class="data-table__th" data-col-index="2" draggable="true">Файл</th>
                                <th class="data-table__th" data-col-index="3" draggable="true">Номер заказа</th>
                                <th class="data-table__th" data-col-index="4" draggable="true">Дата заказа</th>
                                <th class="data-table__th" data-col-index="5" draggable="true">Клиент</th>
                                <th class="data-table__th" data-col-index="6" draggable="true">Тип продукта</th>
                                <th class="data-table__th" data-col-index="7" draggable="true">EMD услуга</th>
                                <th class="data-table__th" data-col-index="8" draggable="true">Осн. билет</th>
                                <th class="data-table__th" data-col-index="9" draggable="true">Номер билета</th>
                                <th class="data-table__th" data-col-index="10" draggable="true">Дата выписки</th>
                                <th class="data-table__th" data-col-index="11" draggable="true">Статус</th>
                                <th class="data-table__th" data-col-index="12" draggable="true">Пассажир</th>
                                <th class="data-table__th" data-col-index="13" draggable="true">Поставщик</th>
                                <th class="data-table__th" data-col-index="14" draggable="true">Перевозчик</th>
                                <th class="data-table__th" data-col-index="15" draggable="true">Маршрут</th>
                                <th class="data-table__th data-table__th--right" data-col-index="16" draggable="true">Сумма</th>
                                <th class="data-table__th" data-col-index="17" draggable="true">Валюта</th>
                                <th class="data-table__th" data-col-index="18" draggable="true">Исходный файл</th>
                                <th class="data-table__th data-table__td--nowrap" data-col-index="19" draggable="true">Дата загрузки</th>
                                <th class="data-table__th" data-col-index="20" draggable="true">UID заказа</th>
                                <th class="data-table__th" data-col-index="21" draggable="true">UID продукта</th>
                                <th class="data-table__th" data-col-index="22" draggable="true">Номер брони</th>
                                <th class="data-table__th" data-col-index="23" draggable="true">Выпис. агент</th>
                                <th class="data-table__th" data-col-index="24" draggable="true">Агент</th>
                                <th class="data-table__th" data-col-index="25" draggable="true">Тип билета</th>
                                <th class="data-table__th" data-col-index="26" draggable="true">Возраст</th>
                                <th class="data-table__th data-table__td--nowrap" data-col-index="27" draggable="true">Дата рождения</th>
                                <th class="data-table__th" data-col-index="28" draggable="true">Пол</th>
                                <th class="data-table__th" data-col-index="29" draggable="true">Тип документа</th>
                                <th class="data-table__th" data-col-index="30" draggable="true">Номер документа</th>
                                <th class="data-table__th" data-col-index="31" draggable="true">Бланки</th>
                                <th class="data-table__th data-table__th--right" data-col-index="32" draggable="true">Штраф обмен</th>
                                <th class="data-table__th" data-col-index="33" draggable="true">Рейсы</th>
                                <th class="data-table__th" data-col-index="34" draggable="true">Fare Basis</th>
                                <th class="data-table__th" data-col-index="35" draggable="true">Класс</th>
                                <th class="data-table__th data-table__td--nowrap" data-col-index="36" draggable="true">Дата вылета</th>
                                <th class="data-table__th data-table__td--nowrap" data-col-index="37" draggable="true">Дата прилёта</th>
                                <th class="data-table__th data-table__th--right" data-col-index="38" draggable="true">Тариф (руб)</th>
                                <th class="data-table__th data-table__th--right" data-col-index="39" draggable="true">Таксы (руб)</th>
                                <th class="data-table__th data-table__th--right" data-col-index="40" draggable="true">НДС (руб)</th>
                                <th class="data-table__th" data-col-index="41" draggable="true">Тип платежа</th>
                                <th class="data-table__th data-table__th--right" data-col-index="42" draggable="true">Оплата (руб)</th>
                                <th class="data-table__th data-table__th--right" data-col-index="43" draggable="true">Зачёт (руб)</th>
                                <th class="data-table__th" data-col-index="44" draggable="true">Связ. билет</th>
                                <th class="data-table__th data-table__th--right" data-col-index="45" draggable="true">Комиссия ТКП</th>
                                <th class="data-table__th" data-col-index="46" draggable="true">Ставка %</th>
                                <th class="data-table__th data-table__th--right" data-col-index="47" draggable="true">Серв. сбор</th>
                                <th class="data-table__th data-table__th--right" data-col-index="48" draggable="true">Сбор пост.</th>
                                <th class="data-table__th data-table__td--nowrap" data-col-index="49" draggable="true">Дата возврата</th>
                                <th class="data-table__th data-table__th--right" data-col-index="50" draggable="true">Сумма возврата</th>
                                <th class="data-table__th data-table__th--right" data-col-index="51" draggable="true">Сбор РСТЛС</th>
                                <th class="data-table__th data-table__th--right" data-col-index="52" draggable="true">Сбор пост. возвр.</th>
                                <th class="data-table__th data-table__th--right" data-col-index="53" draggable="true">Штраф пост.</th>
                                <th class="data-table__th data-table__th--right" data-col-index="54" draggable="true">Штраф РСТЛС</th>
                            </tr>
                        </thead>
                        <tbody>

                        <?php foreach ($rows as $i => $row): ?>
                                <tr class="data-table__row">
                                    <td class="data-table__td" data-col-index="0" style="text-align:center">
                                        <button class="btn-resend" title="Отправить повторно в API 1С" onclick="resendJson('<?php echo htmlspecialchars($row['file'], ENT_QUOTES); ?>', this)">🔄</button>
                                    </td>
                                    <td class="data-table__td data-table__td--num" data-col-index="1"><?php echo $i + 1; ?></td>
                                    <td class="data-table__td data-table__td--file" data-col-index="2" title="<?php echo htmlspecialchars($row['file']); ?>"><?php echo htmlspecialchars($row['file']); ?></td>
                                    <td class="data-table__td" data-col-index="3"><?php echo htmlspecialchars($row['invoice_num']); ?></td>
                                    <td class="data-table__td data-table__td--nowrap" data-col-index="4"><?php echo htmlspecialchars($row['invoice_date']); ?></td>
                                    <td class="data-table__td" data-col-index="5"><?php echo htmlspecialchars($row['client']); ?></td>
                                    <td class="data-table__td" data-col-index="6"><?php echo htmlspecialchars($row['product_type']); ?></td>
                                    <td class="data-table__td" data-col-index="7"><?php echo htmlspecialchars($row['emd_name']); ?></td>
                                    <td class="data-table__td" data-col-index="8"><?php echo htmlspecialchars($row['related_ticket_emd']); ?></td>
                                    <td class="data-table__td" data-col-index="9"><?php echo htmlspecialchars($row['number']); ?></td>
                                    <td class="data-table__td data-table__td--nowrap" data-col-index="10"><?php echo htmlspecialchars($row['issue_date']); ?></td>
                                    <td class="data-table__td" data-col-index="11">
                                        <?php
                                            $statusClass = 'data-status--sale';
                                            $statusLower = mb_strtolower($row['status'], 'UTF-8');
                                            if ($statusLower === 'возврат') { $statusClass = 'data-status--refund'; }
                                            elseif ($statusLower === 'обмен') { $statusClass = 'data-status--exchange'; }
                                        ?>
                                        <span class="data-status <?php echo $statusClass; ?>"><?php echo htmlspecialchars($row['status']); ?></span>
                                    </td>
                                    <td class="data-table__td" data-col-index="12"><?php echo htmlspecialchars($row['traveller']); ?></td>
                                    <td class="data-table__td" data-col-index="13"><?php echo htmlspecialchars($row['supplier']); ?></td>
                                    <td class="data-table__td" data-col-index="14"><?php echo htmlspecialchars($row['carrier']); ?></td>
                                    <td class="data-table__td data-table__td--route" data-col-index="15"><?php echo htmlspecialchars($row['route']); ?></td>
                                    <td class="data-table__td data-table__td--right" data-col-index="16"><?php echo number_format($row['amount'], 2, '.', ' '); ?></td>
                                    <td class="data-table__td" data-col-index="17"><?php echo htmlspecialchars($row['currency']); ?></td>
                                    <td class="data-table__td data-table__td--file" data-col-index="18"><?php echo htmlspecialchars($row['source_xml']); ?></td>
                                    <td class="data-table__td data-table__td--nowrap" data-col-index="19"><?php echo htmlspecialchars($row['parsed_at']); ?></td>
                                    <td class="data-table__td data-table__td--file" data-col-index="20" title="<?php echo htmlspecialchars($row['order_uid']); ?>"><?php echo htmlspecialchars($row['order_uid'] ? substr($row['order_uid'], 0, 8) . '...' : ''); ?></td>
                                    <td class="data-table__td data-table__td--file" data-col-index="21" title="<?php echo htmlspecialchars($row['product_uid']); ?>"><?php echo htmlspecialchars($row['product_uid'] ? substr($row['product_uid'], 0, 8) . '...' : ''); ?></td>
                                    <td class="data-table__td" data-col-index="22"><?php echo htmlspecialchars($row['reservation_num']); ?></td>
                                    <td class="data-table__td" data-col-index="23"><?php echo htmlspecialchars($row['booking_agent']); ?></td>
                                    <td class="data-table__td" data-col-index="24"><?php echo htmlspecialchars($row['agent']); ?></td>
                                    <td class="data-table__td" data-col-index="25"><?php echo htmlspecialchars($row['ticket_type']); ?></td>
                                    <td class="data-table__td" data-col-index="26"><?php echo htmlspecialchars($row['passenger_age']); ?></td>
                                    <td class="data-table__td data-table__td--nowrap" data-col-index="27"><?php echo htmlspecialchars($row['passenger_birth_date']); ?></td>
                                    <td class="data-table__td" data-col-index="28"><?php echo htmlspecialchars($row['passenger_gender']); ?></td>
                                    <td class="data-table__td" data-col-index="29"><?php echo htmlspecialchars($row['passenger_doc_type']); ?></td>
                                    <td class="data-table__td" data-col-index="30"><?php echo htmlspecialchars($row['passenger_doc_number']); ?></td>
                                    <td class="data-table__td data-table__td--right" data-col-index="31"><?php echo $row['conj_count'] !== '' ? htmlspecialchars($row['conj_count']) : ''; ?></td>
                                    <td class="data-table__td data-table__td--right" data-col-index="32"><?php echo $row['penalty'] > 0 ? number_format($row['penalty'], 2, '.', ' ') : ''; ?></td>
                                    <td class="data-table__td" data-col-index="33"><?php echo htmlspecialchars($row['flight_numbers']); ?></td>
                                    <td class="data-table__td" data-col-index="34"><?php echo htmlspecialchars($row['fare_basis']); ?></td>
                                    <td class="data-table__td" data-col-index="35"><?php echo htmlspecialchars($row['classes']); ?></td>
                                    <td class="data-table__td data-table__td--nowrap" data-col-index="36"><?php echo htmlspecialchars($row['departure_date']); ?></td>
                                    <td class="data-table__td data-table__td--nowrap" data-col-index="37"><?php echo htmlspecialchars($row['arrival_date']); ?></td>
                                    <td class="data-table__td data-table__td--right" data-col-index="38"><?php echo $row['tariff_rub'] > 0 ? number_format($row['tariff_rub'], 2, '.', ' ') : ''; ?></td>
                                    <td class="data-table__td data-table__td--right" data-col-index="39"><?php echo $row['taxes_rub'] > 0 ? number_format($row['taxes_rub'], 2, '.', ' ') : ''; ?></td>
                                    <td class="data-table__td data-table__td--right" data-col-index="40"><?php echo $row['vat_total'] > 0 ? number_format($row['vat_total'], 2, '.', ' ') : ''; ?></td>
                                    <td class="data-table__td" data-col-index="41"><?php echo htmlspecialchars($row['payment_types']); ?></td>
                                    <td class="data-table__td data-table__td--right" data-col-index="42"><?php echo number_format($row['payment_amount'], 2, '.', ' '); ?></td>
                                    <td class="data-table__td data-table__td--right" data-col-index="43"><?php echo $row['ticket_amount'] > 0 ? number_format($row['ticket_amount'], 2, '.', ' ') : ''; ?></td>
                                    <td class="data-table__td" data-col-index="44"><?php echo htmlspecialchars($row['related_ticket']); ?></td>
                                    <td class="data-table__td data-table__td--right" data-col-index="45"><?php echo is_numeric($row['commission_tkp']) ? number_format((float)$row['commission_tkp'], 2, '.', ' ') : ''; ?></td>
                                    <td class="data-table__td" data-col-index="46"><?php echo $row['commission_rate'] !== '' ? htmlspecialchars($row['commission_rate']) . '%' : ''; ?></td>
                                    <td class="data-table__td data-table__td--right" data-col-index="47"><?php echo is_numeric($row['service_fee']) ? number_format((float)$row['service_fee'], 2, '.', ' ') : ''; ?></td>
                                    <td class="data-table__td data-table__td--right" data-col-index="48"><?php echo is_numeric($row['supplier_fee']) ? number_format((float)$row['supplier_fee'], 2, '.', ' ') : ''; ?></td>
                                    <td class="data-table__td data-table__td--nowrap" data-col-index="49"><?php echo htmlspecialchars($row['refund_date']); ?></td>
                                    <td class="data-table__td data-table__td--right" data-col-index="50"><?php echo is_numeric($row['refund_amount']) ? number_format((float)$row['refund_amount'], 2, '.', ' ') : ''; ?></td>
                                    <td class="data-table__td data-table__td--right" data-col-index="51"><?php echo is_numeric($row['refund_fee_client']) ? number_format((float)$row['refund_fee_client'], 2, '.', ' ') : ''; ?></td>
                                    <td class="data-table__td data-table__td--right" data-col-index="52"><?php echo is_numeric($row['refund_fee_vendor']) ? number_format((float)$row['refund_fee_vendor'], 2, '.', ' ') : ''; ?></td>
                                    <td class="data-table__td data-table__td--right" data-col-index="53"><?php echo is_numeric($row['refund_penalty_vendor']) ? number_format((float)$row['refund_penalty_vendor'], 2, '.', ' ') : ''; ?></td>
                                    <td class="data-table__td data-table__td--right" data-col-index="54"><?php echo ($row['refund_penalty_client'] !== '') ? number_format((float)$row['refund_penalty_client'], 2, '.', ' ') : ''; ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </section>
    </main>

    <footer class="footer">
        <p>XML Parser v5 — Система обработки файлов поставщиков by Denis Kuritsyn</p>
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
document.addEventListener('DOMContentLoaded', function() {
    var filterInput = document.getElementById('filterInput');
    var filterCount = document.getElementById('filterCount');
    var tbody = document.querySelector('.data-table tbody');
    if (filterInput && tbody) {
        filterInput.addEventListener('input', function() {
            var q = this.value.trim().toLowerCase();
            var rows = tbody.querySelectorAll('tr');
            var visible = 0;
            rows.forEach(function(tr) {
                var text = tr.textContent.toLowerCase();
                var show = !q || text.indexOf(q) !== -1;
                tr.style.display = show ? '' : 'none';
                if (show) visible++;
            });
            filterCount.textContent = 'Показано: ' + visible + ' из ' + rows.length;
        });
    }

    var btnExport = document.getElementById('btnExportXlsx');
    if (btnExport) {
        btnExport.addEventListener('click', function() {
            function doExport() {
                if (typeof XLSX === 'undefined') { alert('Библиотека XLSX не загружена'); return; }
                var table = document.querySelector('.data-table');
                if (!table) return;
                var clone = table.cloneNode(true);
                var cloneBody = clone.querySelector('tbody');
                if (cloneBody) {
                    var rows = cloneBody.querySelectorAll('tr');
                    rows.forEach(function(tr) {
                        if (tr.style.display === 'none') tr.remove();
                    });
                }
                var wb = XLSX.utils.table_to_book(clone, {sheet: 'Заказы', raw: true});
                XLSX.writeFile(wb, 'orders_' + new Date().toISOString().slice(0, 10) + '.xlsx');
            }
            if (typeof XLSX !== 'undefined') {
                doExport();
            } else {
                btnExport.disabled = true;
                btnExport.textContent = 'Загрузка...';
                var localUrl = new URL('assets/xlsx.full.min.js', window.location.href).href;
                var urls = [
                    localUrl,
                    'https://cdn.jsdelivr.net/npm/xlsx@0.18.5/dist/xlsx.full.min.js',
                    'https://unpkg.com/xlsx@0.18.5/dist/xlsx.full.min.js'
                ];
                function tryLoad(i) {
                    if (i >= urls.length) {
                        btnExport.disabled = false;
                        btnExport.textContent = 'Выгрузить в XLSX';
                        alert('Не удалось загрузить библиотеку XLSX. Проверьте подключение к интернету.');
                        return;
                    }
                    var s = document.createElement('script');
                    s.src = urls[i];
                    s.onload = function() { btnExport.disabled = false; btnExport.textContent = 'Выгрузить в XLSX'; doExport(); };
                    s.onerror = function() { tryLoad(i + 1); };
                    document.head.appendChild(s);
                }
                tryLoad(0);
            }
        });
    }

    var table = document.querySelector('.data-table');
    if (!table) return;

    function getColumnOrder() {
        var ths = table.querySelectorAll('thead th');
        var order = [];
        for (var i = 0; i < ths.length; i++) {
            order.push(parseInt(ths[i].getAttribute('data-col-index'), 10));
        }
        return order;
    }
    function applyColumnOrder(order) {
        if (!order || order.length === 0) return;
        var theadRow = table.querySelector('thead tr');
        var ths = Array.prototype.slice.call(theadRow.querySelectorAll('th'));
        var thByIndex = {};
        ths.forEach(function(th) { thByIndex[parseInt(th.getAttribute('data-col-index'), 10)] = th; });
        order.forEach(function(colIdx) {
            if (thByIndex[colIdx]) theadRow.appendChild(thByIndex[colIdx]);
        });
        table.querySelectorAll('tbody tr').forEach(function(tr) {
            var tds = Array.prototype.slice.call(tr.querySelectorAll('td'));
            var tdByIndex = {};
            tds.forEach(function(td) { tdByIndex[parseInt(td.getAttribute('data-col-index'), 10)] = td; });
            order.forEach(function(colIdx) {
                if (tdByIndex[colIdx]) tr.appendChild(tdByIndex[colIdx]);
            });
        });
    }
    function saveColumnOrder() {
        fetch('api.php?action=settings', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({data_column_order: getColumnOrder()})
        }).catch(function() {});
    }

    fetch('api.php?action=settings')
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (data.status === 'ok' && data.settings && data.settings.data_column_order && Array.isArray(data.settings.data_column_order)) {
                var order = data.settings.data_column_order;
                if (order.length === table.querySelectorAll('thead th').length) {
                    applyColumnOrder(order);
                }
            }
        })
        .catch(function() {});

    var thead = table.querySelector('thead');
    if (thead) {
        thead.addEventListener('dragstart', function(e) {
            var th = e.target.closest('th');
            if (!th) return;
            e.dataTransfer.setData('text/plain', th.getAttribute('data-col-index'));
            e.dataTransfer.effectAllowed = 'move';
            th.style.opacity = '0.5';
        });
        thead.addEventListener('dragend', function(e) {
            var th = e.target.closest('th');
            if (th) th.style.opacity = '';
        });
        thead.addEventListener('dragover', function(e) {
            var th = e.target.closest('th');
            if (!th) return;
            e.preventDefault();
            e.dataTransfer.dropEffect = 'move';
            thead.querySelectorAll('th').forEach(function(t) { t.classList.remove('drag-over'); });
            th.classList.add('drag-over');
        });
        thead.addEventListener('dragleave', function(e) {
            if (!e.target.closest('th')) thead.querySelectorAll('th').forEach(function(t) { t.classList.remove('drag-over'); });
        });
        thead.addEventListener('drop', function(e) {
            e.preventDefault();
            thead.querySelectorAll('th').forEach(function(t) { t.classList.remove('drag-over'); });
            var fromIdx = parseInt(e.dataTransfer.getData('text/plain'), 10);
            var toTh = e.target.closest('th');
            if (!toTh || isNaN(fromIdx)) return;
            var theadRow = table.querySelector('thead tr');
            var fromTh = theadRow.querySelector('th[data-col-index="' + fromIdx + '"]');
            if (!fromTh) return;
            var fromPos = Array.prototype.indexOf.call(theadRow.children, fromTh);
            var toPos = Array.prototype.indexOf.call(theadRow.children, toTh);
            if (fromPos === toPos) return;
            var ref = fromPos < toPos ? theadRow.children[toPos + 1] : theadRow.children[toPos];
            theadRow.insertBefore(fromTh, ref);
            table.querySelectorAll('tbody tr').forEach(function(tr) {
                var fromTd = tr.querySelector('td[data-col-index="' + fromIdx + '"]');
                var refTd = tr.children[toPos];
                if (fromTd && refTd) tr.insertBefore(fromTd, refTd);
            });
            saveColumnOrder();
        });
    }

    setTimeout(function() {
    var headers = table.querySelectorAll('th');
    var widths = [];
    headers.forEach(function(th, i) {
        var idx = parseInt(th.getAttribute('data-col-index'), 10);
        widths[i] = (idx === 0) ? 40 : 50;
    });
    headers.forEach(function(th, i) { th.style.width = widths[i] + 'px'; });
    table.style.tableLayout = 'fixed';
    headers.forEach(function(th) {
        var resizer = document.createElement('div');
        resizer.style.cssText = 'position:absolute;right:0;top:0;width:5px;height:100%;cursor:col-resize;user-select:none;z-index:1;';
        th.style.position = 'sticky';
        th.style.overflow = 'hidden';
        th.style.textOverflow = 'ellipsis';
        th.style.whiteSpace = 'nowrap';
        th.appendChild(resizer);
        var minWidth = (parseInt(th.getAttribute('data-col-index'), 10) === 0) ? 40 : 50;
        resizer.addEventListener('mousedown', function(e) {
            var startX = e.pageX;
            var startWidth = th.offsetWidth;
            resizer.style.borderRight = '2px solid #4a90d9';
            function onMouseMove(e) {
                var newWidth = startWidth + (e.pageX - startX);
                if (newWidth >= minWidth) { th.style.width = newWidth + 'px'; }
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
    }, 0);
});
    </script>
</body>
</html>