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
 * Версия: V6 (добавлен тест скрытых конъюнкций)
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

    // -------------------------------------------------
    // Тест 1: Простая продажа, 1 билет, 2 сегмента
    // Без конъюнкций (только service_prod),
    // есть комиссии (fees), есть service_fee
    // reservation без bookingAgent
    // issuingAgent="1" (числовой, не ФИО)
    // -------------------------------------------------
    '125358843227.xml' => array(
        'description'        => 'Продажа, 1 билет, 2 сегмента, SVO→KZN→SVO',
        'status'             => 'продажа',
        'products_count'     => 1,
        'invoice_number'     => '125358843227',
        'client'             => 'MA1PA6',
        'ticket_number'      => '5552379893379',
        'traveller'          => 'SMIRNOV ALEKSEI',
        'carrier'            => 'SU',
        'coupons_count'      => 2,
        'currency'           => 'RUB',
        'fare'               => 6650.0,
        'has_refund'         => false,
        // V5: новые проверки
        'supplier'           => 'МА авиа',
        'reservation_number' => '8C37F9',
        'conj_count'         => 1,
        // Даты первого и последнего купона
        'first_dep_dt'       => '20260210180500',
        'first_arr_dt'       => '20260210194500',
        'last_dep_dt'        => '20260211153500',
        'last_arr_dt'        => '20260211172000',
        // Комиссии: service_fee=299.18 → CLIENT "Сбор поставщика"
        'has_client_commission' => true,
        'client_commission_amount' => 299.18,
        // VENDOR комиссии из fees (сумма: 1+0+300+0+1 = 302)
        'has_vendor_commission' => true,
        'vendor_commission_amount' => 302.0,
    ),

    // -------------------------------------------------
    // Тест 2: Продажа, 3 билета, 3 пассажира
    // Без конъюнкций, каждый пассажир — отдельный air_ticket_prod
    // service_fee=299.73, есть fees (комиссии)
    // -------------------------------------------------
    '125358829987.xml' => array(
        'description'        => 'Продажа, 3 билета, 3 пассажира, SVO→DXB→SVO',
        'status'             => 'продажа',
        'products_count'     => 3,
        'invoice_number'     => '125358829987',
        'client'             => 'MA1PA6',
        'ticket_number'      => '5552379660767',
        'traveller'          => 'MUSTAFINA ULIANA',
        'carrier'            => 'SU',
        'coupons_count'      => 2,
        'currency'           => 'RUB',
        'fare'               => 70640.0,
        'has_refund'         => false,
        // V5
        'supplier'           => 'МА авиа',
        'reservation_number' => '8XGWB4',
        'conj_count'         => 1,
        // Все 3 пассажира и билета
        'all_travellers'     => array(
            'MUSTAFINA ULIANA',
            'PETROV NIKOLAI',
            'VLASOV OLEG',
        ),
        'all_tickets'        => array(
            '5552379660767',
            '5552379660766',
            '5552379660768',
        ),
    ),

    // -------------------------------------------------
    // Тест 3: Продажа с EMD (отдельный продукт)
    // prod_id=0 (основной, fare=28497) + prod_id=1 (emd, fare=2027.63)
    // emd_ticket_doc prod_id="1" main_prod_id="0"
    // EMD — отдельный продукт в PRODUCTS[] (не группируем с авиа)
    // -------------------------------------------------
    '125358832021.xml' => array(
        'description'        => 'Продажа, 1 билет + EMD (конъюнкция), MXP→CDG, EUR→RUB',
        'status'             => 'продажа',
        'products_count'     => 2,
        'invoice_number'     => '125358832021',
        'client'             => 'MA1PA6',
        'ticket_number'      => '0572675175754',
        'traveller'          => 'PETROVA EVGENIYA',
        'carrier'            => 'AF',
        'coupons_count'      => 1,
        'currency'           => 'RUB',
        'fare'               => 28497.0,
        'has_refund'         => false,
        'supplier'           => 'МА авиа',
        'reservation_number' => 'FV2R0F',
        'conj_count'         => 1,
        'first_dep_dt'       => '20260207113500',
        'first_arr_dt'       => '20260207131000',
    ),

    // -------------------------------------------------
    // Тест 4: Возврат REF
    // prod_id=0 (TKT, fare=138300) + prod_id=1 (emd, main_prod_id=0)
    // prod_id=3 (REF, fare=138300, penalty PEN=3500)
    // Парсер выдаёт: 1 авиа-возврат + 1 EMD-возврат = 2 продукта
    // -------------------------------------------------
    '125358832769.xml' => array(
        'description'        => 'Возврат REF, penalty 3500, SVO→OVB→SVO',
        'status'             => 'возврат',
        'products_count'     => 1,
        'invoice_number'     => '125358832769',
        'client'             => 'MA1PA6',
        'ticket_number'      => '5552379788609',
        'traveller'          => 'SHKULEV VIKTOR',
        'carrier'            => 'SU',
        'coupons_count'      => 2,
        'currency'           => 'RUB',
        'fare'               => 138300.0,
        'has_refund'         => true,
        'penalty'            => 3500.0,
        // V5
        'supplier'           => 'МА авиа',
        'reservation_number' => '8XMK4C',
        // REFUND.AMOUNT = fare + taxes - penalty = 138300 + 1502 - 3500 = 136302
        'refund_amount'      => 136302.0,
    ),

    // -------------------------------------------------
    // Тест 5: Продажа, 5 пассажиров + 5 EMD (отдельные продукты)
    // 10 air_ticket_prod → 5 авиа PRODUCTS + 5 EMD PRODUCTS = 10
    // EMD — отдельные продукты; all_travellers/all_tickets по первому продукту (авиа)
    // -------------------------------------------------
    '125359005865.xml' => array(
        'description'        => 'Продажа, 5 билетов + конъюнкции (emd), SVO→AUH→SVO',
        'status'             => 'продажа',
        'products_count'     => 5,
        'invoice_number'     => '125359005865',
        'client'             => 'MA1PA6',
        'ticket_number'      => '6076506222015',
        'traveller'          => 'MAKAROV KONSTANTIN',
        'carrier'            => 'EY',
        'coupons_count'      => 2,
        'currency'           => 'RUB',
        'fare'               => 787970.0,
        'has_refund'         => false,
        'supplier'           => 'МА авиа',
        'reservation_number' => 'G1ZXKP',
        'booking_agent'      => 'Валерия Подунай',
        'agent'              => 'Валерия Подунай',
        'conj_count'         => 1,
        'first_dep_dt'       => '20260405124000',
        'first_arr_dt'       => '20260405191000',
        'last_dep_dt'        => '20260411141500',
        'last_arr_dt'        => '20260411190500',
        'all_travellers'     => array(
            'MAKAROV KONSTANTIN',
            'BAIBOLOVA DINA',
            'MAKAROVA TATIANA',
            'MAKAROVA EKATERINA',
            'MAKAROVA EKATERINA',
        ),
        'all_tickets'        => array(
            '6076506222015',
            '6076506222017',
            '6076506222018',
            '6076506222014',
            '6076506222016',
        ),
    ),

    // -------------------------------------------------
    // Тест 7: Возврат + 2 EMD с номерами и ненулевой суммой
    // prod_id=0 (TKT, fare=39190) + prod_id=1 (emd, fare=4254, tkt=5554590327709)
    // + prod_id=2 (emd, fare=5885, tkt=5554590328700)
    // prod_id=4 (REF, penalty PEN=5060)
    // EMD НЕ фильтруются (есть номер и ненулевая сумма)
    // Парсер: 1 авиа-возврат + 2 EMD-возврата = 3 продукта
    // -------------------------------------------------
    '125358954718.xml' => array(
        'description'        => 'Возврат + 2 EMD (багаж + место), penalty 5060, SVO→EVN',
        'status'             => 'возврат',
        'products_count'     => 3,
        'invoice_number'     => '125358954718',
        'client'             => 'MA1PA6',
        'ticket_number'      => '5552381291066',
        'traveller'          => 'AMIRKHANYAN ASYA',
        'carrier'            => 'SU',
        'coupons_count'      => 2,
        'currency'           => 'RUB',
        'fare'               => 39190.0,
        'has_refund'         => true,
        'penalty'            => 5060.0,
        'refund_amount'      => 16526.0,
        'supplier'           => 'МА авиа',
        'reservation_number' => '93261Z',
        'booking_agent'      => 'Ольга Никифорова',
        'agent'              => 'Ольга Никифорова',
    ),

    // -------------------------------------------------
    // Тест 6: Продажа со скрытой конъюнкцией (V6)
    // prod_id=0 (основной, fare=30125, 4 сегмента, tkt=2352294341454)
    // prod_id=1 (service_prod СБ_ПК — игнорируется)
    // prod_id=2 (fare=0, 0 сегментов, tkt=2352294341455)
    // Нет emd_ticket_doc — конъюнкция определяется по косвенным
    // признакам: fare=0, нет сегментов, тот же psgr_id,
    // последовательный tkt_number (разница 1)
    // Группировка: 2 air_ticket_prod → 1 PRODUCT
    // КЛЮЧЕВОЙ ТЕСТ НА СКРЫТЫЕ КОНЪЮНКЦИИ V6
    // -------------------------------------------------
    '125359052102.xml' => array(
        'description'        => 'Продажа, 1 билет + скрытая конъюнкция (без emd), VKO→TIV, 4 сегмента',
        'status'             => 'продажа',
        'products_count'     => 1,
        'invoice_number'     => '125359052102',
        'client'             => 'MA1PA6',
        'ticket_number'      => '2352294341454',
        'traveller'          => 'ISAIKINA OLGA',
        'carrier'            => 'TK',
        'coupons_count'      => 4,
        'currency'           => 'RUB',
        'fare'               => 30125.0,
        'has_refund'         => false,
        // V6: скрытая конъюнкция — 2 air_ticket_prod → 1 PRODUCT
        'supplier'           => 'МА авиа',
        'reservation_number' => 'TMZJ3A',
        'booking_agent'      => 'Инна Дмитриева',
        'agent'              => 'Инна Дмитриева',
        'conj_count'         => 2,
        // Даты: 4 сегмента VKO→IST→TIV ... SJJ→IST→VKO
        'first_dep_dt'       => '20260418011500',
        'first_arr_dt'       => '20260418052500',
        'last_dep_dt'        => '20260427010500',
        'last_arr_dt'        => '20260427050000',
        // Комиссии: service_fee=0, fees КОМСА_САЙТ=1456.08
        'has_client_commission' => false,
        'has_vendor_commission' => true,
        'vendor_commission_amount' => 1456.08,
    ),
);

// =====================================================
// ЗАПУСК ТЕСТОВ
// =====================================================

$parser = new MoyAgentParser();
$results = array();
$totalTests = 0;
$passedTests = 0;
$failedTests = 0;

/**
 * Вспомогательная функция — добавляет проверку.
 */
function addCheck(&$fileResult, &$totalTests, &$passedTests, &$failedTests, $name, $ok, $expected, $actual)
{
    $totalTests++;
    if ($ok) {
        $passedTests++;
    } else {
        $failedTests++;
    }
    $fileResult['checks'][] = array(
        'name'     => $name,
        'ok'       => $ok,
        'expected' => is_array($expected) ? implode(', ', $expected) : (string)$expected,
        'actual'   => is_array($actual) ? implode(', ', $actual) : (string)$actual,
    );
}

// Список всех XML-фикстур в папке (динамически)
$fixtureFiles = array();
if (is_dir($testDir)) {
    $glob = glob($testDir . '/*.xml');
    if ($glob !== false) {
        foreach ($glob as $path) {
            $fixtureFiles[] = basename($path);
        }
    }
}

foreach ($fixtureFiles as $fileName) {
    $filePath = $testDir . '/' . $fileName;
    $expected = isset($expectations[$fileName]) ? $expectations[$fileName] : null;

    // Файл есть в папке, но нет в expectations — помечаем как «нет ожиданий», не считаем провалом
    if ($expected === null) {
        $results[] = array(
            'file'        => $fileName,
            'description' => 'Нет ожиданий',
            'checks'      => array(),
            'error'       => null,
            'no_expectations' => true,
        );
        continue;
    }

    $fileResult = array(
        'file'        => $fileName,
        'description' => $expected['description'],
        'checks'      => array(),
        'error'       => null,
        'no_expectations' => false,
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

    // =============================================
    // БАЗОВЫЕ ПРОВЕРКИ (уровень ORDER)
    // =============================================

    // 1. Парсинг успешен
    addCheck($fileResult, $totalTests, $passedTests, $failedTests,
        'Парсинг', ($data !== null), 'без ошибок', $data !== null ? 'OK' : 'FAIL');

    // 2. UID заказа
    $uid = isset($data['UID']) ? $data['UID'] : '';
    addCheck($fileResult, $totalTests, $passedTests, $failedTests,
        'UID заказа', (!empty($uid) && strlen($uid) === 36), 'UUID (36 символов)', $uid);

    // 3. INVOICE_NUMBER
    $actual = isset($data['INVOICE_NUMBER']) ? $data['INVOICE_NUMBER'] : '';
    addCheck($fileResult, $totalTests, $passedTests, $failedTests,
        'INVOICE_NUMBER', ($actual === $expected['invoice_number']), $expected['invoice_number'], $actual);

    // 4. CLIENT
    $actual = isset($data['CLIENT']) ? $data['CLIENT'] : '';
    addCheck($fileResult, $totalTests, $passedTests, $failedTests,
        'CLIENT', ($actual === $expected['client']), $expected['client'], $actual);

    // 5. Количество продуктов
    $actual = isset($data['PRODUCTS']) ? count($data['PRODUCTS']) : 0;
    addCheck($fileResult, $totalTests, $passedTests, $failedTests,
        'Продуктов', ($actual === $expected['products_count']), $expected['products_count'], $actual);

    // =============================================
    // ПРОВЕРКИ ПЕРВОГО ПРОДУКТА
    // =============================================

    $p = isset($data['PRODUCTS'][0]) ? $data['PRODUCTS'][0] : array();

    // 6. Статус
    $actual = isset($p['STATUS']) ? $p['STATUS'] : '';
    addCheck($fileResult, $totalTests, $passedTests, $failedTests,
        'Статус', ($actual === $expected['status']), $expected['status'], $actual);

    // 7. Номер билета
    $actual = isset($p['NUMBER']) ? $p['NUMBER'] : '';
    addCheck($fileResult, $totalTests, $passedTests, $failedTests,
        'Номер билета', ($actual === $expected['ticket_number']), $expected['ticket_number'], $actual);

    // 8. Пассажир
    $actual = isset($p['TRAVELLER']) ? $p['TRAVELLER'] : '';
    addCheck($fileResult, $totalTests, $passedTests, $failedTests,
        'Пассажир', ($actual === $expected['traveller']), $expected['traveller'], $actual);

    // 9. Перевозчик
    $actual = isset($p['CARRIER']) ? $p['CARRIER'] : '';
    addCheck($fileResult, $totalTests, $passedTests, $failedTests,
        'Перевозчик', ($actual === $expected['carrier']), $expected['carrier'], $actual);

    // 10. Купоны
    $actual = isset($p['COUPONS']) ? count($p['COUPONS']) : 0;
    addCheck($fileResult, $totalTests, $passedTests, $failedTests,
        'Купонов', ($actual === $expected['coupons_count']), $expected['coupons_count'], $actual);

    // 11. Валюта
    $actual = isset($p['CURRENCY']) ? $p['CURRENCY'] : '';
    addCheck($fileResult, $totalTests, $passedTests, $failedTests,
        'Валюта', ($actual === $expected['currency']), $expected['currency'], $actual);

    // 12. Тариф (первая такса = fare, CODE="")
    $actual = (isset($p['TAXES'][0]['AMOUNT'])) ? (float)$p['TAXES'][0]['AMOUNT'] : 0;
    addCheck($fileResult, $totalTests, $passedTests, $failedTests,
        'Тариф (fare)', (abs($actual - $expected['fare']) < 0.01), $expected['fare'], $actual);

    // 13. UID продукта
    $puid = isset($p['UID']) ? $p['UID'] : '';
    addCheck($fileResult, $totalTests, $passedTests, $failedTests,
        'UID продукта', (strlen($puid) === 36), 'UUID (36 символов)', $puid);

    // 14. JSON сериализация
    $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    $jsonOk = ($json !== false && json_last_error() === JSON_ERROR_NONE);
    addCheck($fileResult, $totalTests, $passedTests, $failedTests,
        'JSON сериализация', $jsonOk, 'валидный JSON', $jsonOk ? strlen($json) . ' байт' : json_last_error_msg());

    // =============================================
    // V5: SUPPLIER
    // =============================================

    if (isset($expected['supplier'])) {
        $actual = isset($p['SUPPLIER']) ? $p['SUPPLIER'] : '';
        addCheck($fileResult, $totalTests, $passedTests, $failedTests,
            'SUPPLIER', ($actual === $expected['supplier']), $expected['supplier'], $actual);
    }

    // =============================================
    // V5: RESERVATION_NUMBER
    // =============================================

    if (isset($expected['reservation_number'])) {
        $actual = isset($p['RESERVATION_NUMBER']) ? $p['RESERVATION_NUMBER'] : '';
        addCheck($fileResult, $totalTests, $passedTests, $failedTests,
            'RESERVATION_NUMBER', ($actual === $expected['reservation_number']), $expected['reservation_number'], $actual);
    }

    // =============================================
    // V5: CONJ_COUNT
    // =============================================

    if (isset($expected['conj_count'])) {
        $actual = isset($p['CONJ_COUNT']) ? (int)$p['CONJ_COUNT'] : 0;
        addCheck($fileResult, $totalTests, $passedTests, $failedTests,
            'CONJ_COUNT', ($actual === $expected['conj_count']), $expected['conj_count'], $actual);
    }

    // =============================================
    // V5: BOOKING_AGENT
    // =============================================

    if (isset($expected['booking_agent'])) {
        $actual = '';
        if (isset($p['BOOKING_AGENT'])) {
            $ba = $p['BOOKING_AGENT'];
            $actual = is_array($ba) ? (isset($ba['NAME']) ? $ba['NAME'] : '') : (string)$ba;
        }
        addCheck($fileResult, $totalTests, $passedTests, $failedTests,
            'BOOKING_AGENT', ($actual === $expected['booking_agent']), $expected['booking_agent'], $actual);
    }

    // =============================================
    // V5: AGENT
    // =============================================

    if (isset($expected['agent'])) {
        $actual = '';
        if (isset($p['AGENT'])) {
            $ag = $p['AGENT'];
            $actual = is_array($ag) ? (isset($ag['NAME']) ? $ag['NAME'] : '') : (string)$ag;
        }
        addCheck($fileResult, $totalTests, $passedTests, $failedTests,
            'AGENT', ($actual === $expected['agent']), $expected['agent'], $actual);
    }

    // =============================================
    // V5: ДАТЫ ВЫЛЕТА/ПРИЛЁТА
    // =============================================

    if (isset($expected['first_dep_dt']) && !empty($p['COUPONS'])) {
        $firstCoupon = $p['COUPONS'][0];
        $lastCoupon = end($p['COUPONS']);

        $actual = isset($firstCoupon['DEPARTURE_DATETIME']) ? $firstCoupon['DEPARTURE_DATETIME'] : '';
        addCheck($fileResult, $totalTests, $passedTests, $failedTests,
            'Вылет (1-й сегм.)', ($actual === $expected['first_dep_dt']), $expected['first_dep_dt'], $actual);

        if (isset($expected['first_arr_dt'])) {
            $actual = isset($firstCoupon['ARRIVAL_DATETIME']) ? $firstCoupon['ARRIVAL_DATETIME'] : '';
            addCheck($fileResult, $totalTests, $passedTests, $failedTests,
                'Прилёт (1-й сегм.)', ($actual === $expected['first_arr_dt']), $expected['first_arr_dt'], $actual);
        }

        if (isset($expected['last_dep_dt'])) {
            $actual = isset($lastCoupon['DEPARTURE_DATETIME']) ? $lastCoupon['DEPARTURE_DATETIME'] : '';
            addCheck($fileResult, $totalTests, $passedTests, $failedTests,
                'Вылет (посл. сегм.)', ($actual === $expected['last_dep_dt']), $expected['last_dep_dt'], $actual);
        }

        if (isset($expected['last_arr_dt'])) {
            $actual = isset($lastCoupon['ARRIVAL_DATETIME']) ? $lastCoupon['ARRIVAL_DATETIME'] : '';
            addCheck($fileResult, $totalTests, $passedTests, $failedTests,
                'Прилёт (посл. сегм.)', ($actual === $expected['last_arr_dt']), $expected['last_arr_dt'], $actual);
        }
    }

    // =============================================
    // V5: КОМИССИИ — CLIENT (service_fee)
    // =============================================

    if (isset($expected['has_client_commission']) && $expected['has_client_commission']) {
        $foundClient = false;
        $clientAmount = 0;
        if (!empty($p['COMMISSIONS'])) {
            foreach ($p['COMMISSIONS'] as $comm) {
                if (isset($comm['TYPE']) && $comm['TYPE'] === 'CLIENT') {
                    $foundClient = true;
                    $clientAmount = isset($comm['EQUIVALENT_AMOUNT']) ? (float)$comm['EQUIVALENT_AMOUNT'] : 0;
                    break;
                }
            }
        }
        addCheck($fileResult, $totalTests, $passedTests, $failedTests,
            'Комиссия CLIENT', $foundClient, 'присутствует', $foundClient ? 'есть' : 'нет');

        if (isset($expected['client_commission_amount'])) {
            addCheck($fileResult, $totalTests, $passedTests, $failedTests,
                'CLIENT сумма', (abs($clientAmount - $expected['client_commission_amount']) < 0.01),
                $expected['client_commission_amount'], $clientAmount);
        }
    }

    // =============================================
    // V5: КОМИССИИ — VENDOR (fees)
    // =============================================

    if (isset($expected['has_vendor_commission']) && $expected['has_vendor_commission']) {
        $foundVendor = false;
        $vendorAmount = 0;
        if (!empty($p['COMMISSIONS'])) {
            foreach ($p['COMMISSIONS'] as $comm) {
                if (isset($comm['TYPE']) && $comm['TYPE'] === 'VENDOR') {
                    $foundVendor = true;
                    $vendorAmount = isset($comm['EQUIVALENT_AMOUNT']) ? (float)$comm['EQUIVALENT_AMOUNT'] : 0;
                    break;
                }
            }
        }
        addCheck($fileResult, $totalTests, $passedTests, $failedTests,
            'Комиссия VENDOR', $foundVendor, 'присутствует', $foundVendor ? 'есть' : 'нет');

        if (isset($expected['vendor_commission_amount'])) {
            addCheck($fileResult, $totalTests, $passedTests, $failedTests,
                'VENDOR сумма', (abs($vendorAmount - $expected['vendor_commission_amount']) < 0.01),
                $expected['vendor_commission_amount'], $vendorAmount);
        }
    }

    // =============================================
    // REFUND (только для возвратов)
    // =============================================

    if ($expected['has_refund']) {
        $hasRefund = isset($p['REFUND']);
        addCheck($fileResult, $totalTests, $passedTests, $failedTests,
            'Блок REFUND', $hasRefund, 'присутствует', $hasRefund ? 'есть' : 'нет');

        if (isset($expected['penalty'])) {
            $actual = isset($p['PENALTY']) ? (float)$p['PENALTY'] : 0;
            addCheck($fileResult, $totalTests, $passedTests, $failedTests,
                'PENALTY', (abs($actual - $expected['penalty']) < 0.01), $expected['penalty'], $actual);
        }

        if (isset($expected['refund_amount']) && $hasRefund) {
            $actual = isset($p['REFUND']['AMOUNT']) ? (float)$p['REFUND']['AMOUNT'] : 0;
            addCheck($fileResult, $totalTests, $passedTests, $failedTests,
                'REFUND.AMOUNT', (abs($actual - $expected['refund_amount']) < 0.01),
                $expected['refund_amount'], $actual);
        }
    }

    // =============================================
    // V5: ПРОВЕРКА ВСЕХ ПРОДУКТОВ (multi-passenger)
    // =============================================

    if (isset($expected['all_travellers'])) {
        $actualTravellers = array();
        foreach ($data['PRODUCTS'] as $prod) {
            $actualTravellers[] = isset($prod['TRAVELLER']) ? $prod['TRAVELLER'] : '';
        }
        $ok = ($actualTravellers == $expected['all_travellers']);
        addCheck($fileResult, $totalTests, $passedTests, $failedTests,
            'Все пассажиры', $ok, $expected['all_travellers'], $actualTravellers);
    }

    if (isset($expected['all_tickets'])) {
        $actualTickets = array();
        foreach ($data['PRODUCTS'] as $prod) {
            $actualTickets[] = isset($prod['NUMBER']) ? $prod['NUMBER'] : '';
        }
        $ok = ($actualTickets == $expected['all_tickets']);
        addCheck($fileResult, $totalTests, $passedTests, $failedTests,
            'Все номера билетов', $ok, $expected['all_tickets'], $actualTickets);
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
    echo " АВТОТЕСТЫ ПАРСЕРА MoyAgentParser V6\n";
    echo " " . date('Y-m-d H:i:s') . "\n";
    echo "============================================================\n\n";

    foreach ($results as $r) {
        echo "--- {$r['file']} ---\n";
        echo "    {$r['description']}\n";

        if (!empty($r['no_expectations'])) {
            echo "    \xE2\x9A\xA0 Нет ожиданий (добавьте в \$expectations)\n\n";
            continue;
        }
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
    echo " Файлов: " . count($results) . " | Тестов: {$totalTests}";
    echo " | Прошло: {$passedTests} | Упало: {$failedTests}\n";
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
        /* Минимальные отступы для страницы тестов */
        .page-test .main { margin: 4px auto; padding: 0 8px; }
        .page-test .main .panel { padding: 6px 8px; margin-bottom: 6px; }
        .page-test .main .panel__title { margin-bottom: 4px; padding-bottom: 4px; }
        .test-summary-row {
            display: flex;
            align-items: center;
            gap: 12px;
            margin-bottom: 8px;
            flex-wrap: wrap;
        }

        .test-summary {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
        }

        .test-summary__card {
            display: flex;
            align-items: center;
            gap: 6px;
            padding: 6px 10px;
            border-radius: 4px;
            font-size: 16px;
            font-weight: 600;
        }

        .test-summary__num {
            flex-shrink: 0;
        }

        .test-summary__label {
            font-size: 12px;
            font-weight: 400;
            opacity: 0.85;
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

        .test-summary__btn {
            margin-left: auto;
        }

        .test-file {
            background: #fff;
            border: 1px solid #e0e0e0;
            border-radius: 4px;
            margin-bottom: 6px;
            overflow: hidden;
        }

        .test-file__header {
            padding: 4px 8px;
            font-weight: 600;
            font-size: 14px;
            display: flex;
            align-items: center;
            gap: 6px;
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

        .test-file__header--warn {
            background: #fff8e1;
            border-left: 4px solid #f57c00;
        }

        .test-file__icon {
            font-size: 16px;
            flex-shrink: 0;
        }

        .test-file__name {
            flex: 1;
        }

        .test-file__desc {
            color: #757575;
            font-weight: 400;
            font-size: 12px;
        }

        .test-file__badge {
            font-size: 11px;
            padding: 2px 6px;
            border-radius: 8px;
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

        .test-file__badge--warn {
            background: #ffe0b2;
            color: #e65100;
        }

        .test-file__body {
            padding: 0 8px 6px;
        }

        .test-checks {
            width: 100%;
            border-collapse: collapse;
            font-size: 12px;
        }

        .test-checks th {
            text-align: left;
            padding: 4px 6px;
            background: #fafafa;
            border-bottom: 1px solid #e0e0e0;
            font-weight: 600;
            color: #616161;
        }

        .test-checks td {
            padding: 4px 6px;
            border-bottom: 1px solid #f0f0f0;
        }

        .test-checks tr:last-child td {
            border-bottom: none;
        }

        .test-checks__icon {
            width: 24px;
            text-align: center;
            font-size: 14px;
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
            padding: 6px 8px;
            color: #c62828;
            font-size: 12px;
        }

        .test-meta {
            color: #9e9e9e;
            font-size: 11px;
            margin-bottom: 6px;
        }
    </style>
</head>
<body class="page-test">
    <header class="header header--compact">
        <div class="header__content header__content--wide">
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

    <main class="main main--wide">
        <section class="panel panel--compact">
            <h2 class="panel__title">Результаты тестов — MoyAgentParser V6</h2>

            <div class="test-meta">
                Запуск: <?php echo date('Y-m-d H:i:s'); ?> &nbsp;|&nbsp;
                Папка: <code><?php echo htmlspecialchars($testDir); ?></code> &nbsp;|&nbsp;
                Файлов: <?php echo count($results); ?>
            </div>

            <div class="test-summary-row">
                <div class="test-summary">
                    <div class="test-summary__card test-summary__card--total">
                        <span class="test-summary__num"><?php echo $totalTests; ?></span>
                        <span class="test-summary__label">тестов</span>
                    </div>
                    <div class="test-summary__card test-summary__card--passed">
                        <span class="test-summary__num">✅ <?php echo $passedTests; ?></span>
                        <span class="test-summary__label">прошло</span>
                    </div>
                    <div class="test-summary__card <?php echo $failedTests > 0 ? 'test-summary__card--failed' : 'test-summary__card--passed'; ?>">
                        <span class="test-summary__num"><?php echo $failedTests > 0 ? '❌' : '✅'; ?> <?php echo $failedTests; ?></span>
                        <span class="test-summary__label">упало</span>
                    </div>
                </div>
                <a href="test.php" class="btn btn--primary test-summary__btn">↻ Перезапустить тесты</a>
            </div>

            <?php foreach ($results as $r): ?>
                <?php
                    if (!empty($r['no_expectations'])) {
                        $fileStatus = 'warn';
                        $fileIcon = '⚠️';
                        $badgeText = 'Нет ожиданий';
                    } elseif ($r['error']) {
                        $fileStatus = 'error';
                        $fileIcon = '⚠️';
                        $badgeText = 'ОШИБКА';
                    } else {
                        $hasFailures = false;
                        $checkCount = count($r['checks']);
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
                            $badgeText = "PASS ({$checkCount})";
                        }
                    }
                ?>
                <div class="test-file">
                    <div class="test-file__header test-file__header--<?php echo $fileStatus; ?>"
                         onclick="toggleBody(this)">
                        <span class="test-file__icon"><?php echo $fileIcon; ?></span>
                        <span class="test-file__name">
                            <?php echo htmlspecialchars($r['file']); ?>
                            <span class="test-file__desc">&mdash; <?php echo htmlspecialchars($r['description']); ?></span>
                        </span>
                        <span class="test-file__badge test-file__badge--<?php echo $fileStatus; ?>">
                            <?php echo $badgeText; ?>
                        </span>
                    </div>

                    <?php if (!empty($r['no_expectations'])): ?>
                        <div class="test-file__body" style="display:none">
                            <p class="test-file__error" style="color:#f57c00">Добавьте ожидания в массив $expectations в test.php для этого файла.</p>
                        </div>
                    <?php elseif ($r['error']): ?>
                        <div class="test-file__error">
                            <?php echo htmlspecialchars($r['error']); ?>
                        </div>
                    <?php else: ?>
                        <div class="test-file__body" style="<?php echo $fileStatus === 'pass' ? 'display:none' : ''; ?>">
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
        <p>XML Parser v5 — Система обработки файлов поставщиков by Denis Kuritsyn</p>
    </footer>

    <script>
        function toggleBody(header) {
            var body = header.parentElement.querySelector('.test-file__body, .test-file__error');
            if (body) {
                body.style.display = (body.style.display === 'none') ? '' : 'none';
            }
        }
    </script>
</body>
</html>