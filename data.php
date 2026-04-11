<?php
/**
 * ============================================================
 * СТРАНИЦА ПРОСМОТРА ОБРАБОТАННЫХ ЗАКАЗОВ
 * ============================================================
 * Вкладки по парсерам (поставщикам), данные подгружаются через API (data_rows) и «Загрузить ещё».
 */

$suppliers = array();
$settings = array();

// Читаем настройки из config/settings.json (tab_order и др.)
$configFile = __DIR__ . '/config/settings.json';
if (file_exists($configFile) && is_readable($configFile)) {
    $settingsJson = file_get_contents($configFile);
    if ($settingsJson !== false) {
        $decoded = json_decode($settingsJson, true);
        if (is_array($decoded)) {
            $settings = $decoded;
        }
    }
}
require_once __DIR__ . '/core/Logger.php';
require_once __DIR__ . '/core/ParserManager.php';
$logger = new Logger(__DIR__ . '/logs/app.log');
$parserManager = new ParserManager(__DIR__ . '/parsers', $logger);
foreach ($parserManager->getRegisteredFolders() as $folder) {
    $parser = $parserManager->getParser($folder);
    if ($parser) {
        $suppliers[] = array('folder' => $folder, 'name' => $parser->getSupplierName());
    }
}

// Переупорядочиваем список вкладок согласно настройке tab_order (если задана)
if (!empty($suppliers) && isset($settings['tab_order']) && is_array($settings['tab_order'])) {
    $tabOrder = $settings['tab_order'];
    $orderMap = array();
    foreach ($tabOrder as $index => $folderName) {
        $orderMap[$folderName] = $index;
    }
    usort($suppliers, function ($a, $b) use ($orderMap) {
        $aKey = isset($orderMap[$a['folder']]) ? $orderMap[$a['folder']] : PHP_INT_MAX;
        $bKey = isset($orderMap[$b['folder']]) ? $orderMap[$b['folder']] : PHP_INT_MAX;
        if ($aKey === $bKey) {
            return strcmp($a['folder'], $b['folder']);
        }
        return ($aKey < $bKey) ? -1 : 1;
    });
}
$suppliersJson = json_encode($suppliers, JSON_UNESCAPED_UNICODE);
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
                        <span id="panelCount" class="panel__count">Загрузка...</span>
                    </div>
                    <div class="panel__toolbar-filter">
                        <input type="text" id="filterInput" class="panel__toolbar-input" placeholder="Поиск по всем колонкам...">
                        <span id="filterCount" class="panel__toolbar-count"></span>
                    </div>
                </div>
                <div class="panel__title-actions">
                    <div class="panel__toolbar panel__toolbar--inline">
                        <div class="panel__toolbar-sort">
                            <span>Сортировка:</span>
                            <a href="#" id="sortIssueDate" class="panel__toolbar-sort-link">Дата выписки</a>
                            <a href="#" id="sortParsedAt" class="panel__toolbar-sort-link panel__toolbar-sort-link--active">Дата загрузки</a>
                        </div>
                        <button id="btnExportXlsx" class="panel__toolbar-export">Выгрузить в XLSX</button>
                    </div>
                    <button id="btnClearTable" class="panel__toolbar-clear btn btn--secondary">Очистить таблицу</button>
                </div>
            </div>

            <?php if (empty($suppliers)): ?>
                <div class="data-empty">
                    <p>Нет зарегистрированных парсеров.</p>
                    <p>Добавьте парсер в папку parsers/ и создайте папку в input/.</p>
                </div>
            <?php else: ?>
                <?php $defaultFolder = $suppliers[0]['folder']; ?>
                <div class="data-tabs">
                    <?php foreach ($suppliers as $s): ?>
                        <button type="button" class="data-tab<?php echo $s['folder'] === $defaultFolder ? ' data-tab--active' : ''; ?>" data-supplier="<?php echo htmlspecialchars($s['folder']); ?>"><?php echo htmlspecialchars($s['name']); ?></button>
                    <?php endforeach; ?>
                </div>
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
                                <th class="data-table__th" data-col-index="36" draggable="true">Класс обслуж.</th>
                                <th class="data-table__th" data-col-index="37" draggable="true">Класс перелёта</th>
                                <th class="data-table__th" data-col-index="38" draggable="true">Тип перелёта</th>
                                <th class="data-table__th" data-col-index="39" draggable="true">GDS ID</th>
                                <th class="data-table__th" data-col-index="40" draggable="true">GDS</th>
                                <th class="data-table__th data-table__td--nowrap" data-col-index="41" draggable="true">Дата вылета</th>
                                <th class="data-table__th data-table__td--nowrap" data-col-index="42" draggable="true">Дата прилёта</th>
                                <th class="data-table__th data-table__th--right" data-col-index="43" draggable="true">Тариф (руб)</th>
                                <th class="data-table__th data-table__th--right" data-col-index="44" draggable="true">Таксы (руб)</th>
                                <th class="data-table__th data-table__th--right" data-col-index="45" draggable="true">НДС (руб)</th>
                                <th class="data-table__th" data-col-index="46" draggable="true">Тип платежа</th>
                                <th class="data-table__th data-table__th--right" data-col-index="47" draggable="true">Оплата (руб)</th>
                                <th class="data-table__th data-table__th--right" data-col-index="48" draggable="true">Зачёт (руб)</th>
                                <th class="data-table__th" data-col-index="49" draggable="true">Связ. билет</th>
                                <th class="data-table__th data-table__th--right" data-col-index="50" draggable="true">Комиссия ТКП</th>
                                <th class="data-table__th" data-col-index="51" draggable="true">Ставка %</th>
                                <th class="data-table__th data-table__th--right" data-col-index="52" draggable="true">Серв. сбор</th>
                                <th class="data-table__th data-table__th--right" data-col-index="53" draggable="true">Сбор пост.</th>
                                <th class="data-table__th data-table__td--nowrap" data-col-index="54" draggable="true">Дата возврата</th>
                                <th class="data-table__th data-table__th--right" data-col-index="55" draggable="true">Сумма возврата</th>
                                <th class="data-table__th data-table__th--right" data-col-index="56" draggable="true">Сбор РСТЛС</th>
                                <th class="data-table__th data-table__th--right" data-col-index="57" draggable="true">Сбор пост. возвр.</th>
                                <th class="data-table__th data-table__th--right" data-col-index="58" draggable="true">Штраф пост.</th>
                                <th class="data-table__th data-table__th--right" data-col-index="59" draggable="true">Штраф РСТЛС</th>
                                <th class="data-table__th" data-col-index="60" draggable="true">Cont. e-mail</th>
                                <th class="data-table__th" data-col-index="61" draggable="true">Cont. телефон</th>
                                <th class="data-table__th" data-col-index="62" draggable="true">Cont. имя</th>
                                <th class="data-table__th" data-col-index="63" draggable="true">Поставщик (код)</th>
                                <th class="data-table__th" data-col-index="64" draggable="true">Перевозчики (сегм.)</th>
                                <th class="data-table__th" data-col-index="65" draggable="true">Багаж</th>
                                <th class="data-table__th data-table__th--right" data-col-index="66" draggable="true">Discount</th>
                                <th class="data-table__th" data-col-index="67" draggable="true">Отчество</th>
                                <th class="data-table__th" data-col-index="68" draggable="true">Страна док.</th>
                                <th class="data-table__th" data-col-index="69" draggable="true">Срок действия док.</th>
                            </tr>
                        </thead>
                        <tbody id="dataTableBody">
                            <tr><td colspan="70" class="data-table__td data-table__td--empty">Загрузка...</td></tr>
                        </tbody>
                    </table>
                </div>
                <div class="data-load-more">
                    <button type="button" id="btnLoadMore" class="btn btn--outline" style="display:none">Загрузить ещё</button>
                </div>
            <?php endif; ?>
        </section>
    </main>

    <footer class="footer">
        <p>XML Parser v5 — Система обработки файлов поставщиков by Denis Kuritsyn</p>
    </footer>

    <script>
    (function() {
        var suppliers = <?php echo $suppliersJson; ?>;
        if (suppliers.length === 0) return;

        var tbody = document.getElementById('dataTableBody');
        var panelCount = document.getElementById('panelCount');
        var btnLoadMore = document.getElementById('btnLoadMore');
        var currentSupplier = suppliers[0].folder;
        var currentOffset = 0;
        var sortBy = 'parsed_at';
        var sortDir = 'desc';
        var loadedData = {};
        var PAGE_SIZE = 50;

        function esc(s) {
            if (s === undefined || s === null) return '';
            s = String(s);
            var div = document.createElement('div');
            div.textContent = s;
            return div.innerHTML;
        }
        function statusClass(status) {
            if (!status) return 'data-status--sale';
            var lower = status.toLowerCase();
            if (lower === 'возврат') return 'data-status--refund';
            if (lower === 'обмен') return 'data-status--exchange';
            return 'data-status--sale';
        }
        function numFmt(v) {
            if (v === undefined || v === null || v === '') return '';
            var n = parseFloat(v);
            if (isNaN(n)) return esc(v);
            return n.toLocaleString('ru-RU', {minimumFractionDigits: 2, maximumFractionDigits: 2});
        }
        function renderRow(row, index) {
            var statusCls = statusClass(row.status);
            var orderUidShort = row.order_uid ? row.order_uid.substring(0, 8) + '...' : '';
            var productUidShort = row.product_uid ? row.product_uid.substring(0, 8) + '...' : '';
            var penaltyVal = parseFloat(row.penalty) || 0;
            var refundPc = (row.refund_penalty_client !== '' && row.refund_penalty_client !== undefined) ? parseFloat(row.refund_penalty_client) : NaN;
            return '<tr class="data-table__row">' +
                '<td class="data-table__td" data-col-index="0" style="text-align:center"><button class="btn-resend" title="Отправить повторно в API 1С" data-file="' + esc(row.file) + '" onclick="resendJson(this.getAttribute(\'data-file\'), this)">🔄</button></td>' +
                '<td class="data-table__td data-table__td--num" data-col-index="1">' + (index + 1) + '</td>' +
                '<td class="data-table__td data-table__td--file" data-col-index="2" title="' + esc(row.file) + '">' + esc(row.file) + '</td>' +
                '<td class="data-table__td" data-col-index="3">' + esc(row.invoice_num) + '</td>' +
                '<td class="data-table__td data-table__td--nowrap" data-col-index="4">' + esc(row.invoice_date) + '</td>' +
                '<td class="data-table__td" data-col-index="5">' + esc(row.client) + '</td>' +
                '<td class="data-table__td" data-col-index="6">' + esc(row.product_type) + '</td>' +
                '<td class="data-table__td" data-col-index="7">' + esc(row.emd_name) + '</td>' +
                '<td class="data-table__td" data-col-index="8">' + esc(row.related_ticket_emd) + '</td>' +
                '<td class="data-table__td" data-col-index="9">' + esc(row.number) + '</td>' +
                '<td class="data-table__td data-table__td--nowrap" data-col-index="10">' + esc(row.issue_date) + '</td>' +
                '<td class="data-table__td" data-col-index="11"><span class="data-status ' + statusCls + '">' + esc(row.status) + '</span></td>' +
                '<td class="data-table__td" data-col-index="12">' + esc(row.traveller) + '</td>' +
                '<td class="data-table__td" data-col-index="13">' + esc(row.supplier) + '</td>' +
                '<td class="data-table__td" data-col-index="14">' + esc(row.carrier) + '</td>' +
                '<td class="data-table__td data-table__td--route" data-col-index="15">' + esc(row.route) + '</td>' +
                '<td class="data-table__td data-table__td--right" data-col-index="16">' + numFmt(row.amount) + '</td>' +
                '<td class="data-table__td" data-col-index="17">' + esc(row.currency) + '</td>' +
                '<td class="data-table__td data-table__td--file" data-col-index="18">' + esc(row.source_xml) + '</td>' +
                '<td class="data-table__td data-table__td--nowrap" data-col-index="19">' + esc(row.parsed_at) + '</td>' +
                '<td class="data-table__td data-table__td--file" data-col-index="20" title="' + esc(row.order_uid) + '">' + esc(orderUidShort) + '</td>' +
                '<td class="data-table__td data-table__td--file" data-col-index="21" title="' + esc(row.product_uid) + '">' + esc(productUidShort) + '</td>' +
                '<td class="data-table__td" data-col-index="22">' + esc(row.reservation_num) + '</td>' +
                '<td class="data-table__td" data-col-index="23">' + esc(row.booking_agent) + '</td>' +
                '<td class="data-table__td" data-col-index="24">' + esc(row.agent) + '</td>' +
                '<td class="data-table__td" data-col-index="25">' + esc(row.ticket_type) + '</td>' +
                '<td class="data-table__td" data-col-index="26">' + esc(row.passenger_age) + '</td>' +
                '<td class="data-table__td data-table__td--nowrap" data-col-index="27">' + esc(row.passenger_birth_date) + '</td>' +
                '<td class="data-table__td" data-col-index="28">' + esc(row.passenger_gender) + '</td>' +
                '<td class="data-table__td" data-col-index="29">' + esc(row.passenger_doc_type) + '</td>' +
                '<td class="data-table__td" data-col-index="30">' + esc(row.passenger_doc_number) + '</td>' +
                '<td class="data-table__td data-table__td--right" data-col-index="31">' + (row.conj_count !== '' ? esc(row.conj_count) : '') + '</td>' +
                '<td class="data-table__td data-table__td--right" data-col-index="32">' + (penaltyVal > 0 ? numFmt(row.penalty) : '') + '</td>' +
                '<td class="data-table__td" data-col-index="33">' + esc(row.flight_numbers) + '</td>' +
                '<td class="data-table__td" data-col-index="34">' + esc(row.fare_basis) + '</td>' +
                '<td class="data-table__td" data-col-index="35">' + esc(row.classes) + '</td>' +
                '<td class="data-table__td" data-col-index="36">' + esc(row.classes_name) + '</td>' +
                '<td class="data-table__td" data-col-index="37">' + esc(row.type_id_name) + '</td>' +
                '<td class="data-table__td" data-col-index="38">' + esc(row.flight_type) + '</td>' +
                '<td class="data-table__td" data-col-index="39">' + esc(row.gds_id) + '</td>' +
                '<td class="data-table__td" data-col-index="40">' + esc(row.gds_name) + '</td>' +
                '<td class="data-table__td data-table__td--nowrap" data-col-index="41">' + esc(row.departure_date) + '</td>' +
                '<td class="data-table__td data-table__td--nowrap" data-col-index="42">' + esc(row.arrival_date) + '</td>' +
                '<td class="data-table__td data-table__td--right" data-col-index="43">' + (parseFloat(row.tariff_rub) > 0 ? numFmt(row.tariff_rub) : '') + '</td>' +
                '<td class="data-table__td data-table__td--right" data-col-index="44">' + (parseFloat(row.taxes_rub) > 0 ? numFmt(row.taxes_rub) : '') + '</td>' +
                '<td class="data-table__td data-table__td--right" data-col-index="45">' + (parseFloat(row.vat_total) > 0 ? numFmt(row.vat_total) : '') + '</td>' +
                '<td class="data-table__td" data-col-index="46">' + esc(row.payment_types) + '</td>' +
                '<td class="data-table__td data-table__td--right" data-col-index="47">' + numFmt(row.payment_amount) + '</td>' +
                '<td class="data-table__td data-table__td--right" data-col-index="48">' + (parseFloat(row.ticket_amount) > 0 ? numFmt(row.ticket_amount) : '') + '</td>' +
                '<td class="data-table__td" data-col-index="49">' + esc(row.related_ticket) + '</td>' +
                '<td class="data-table__td data-table__td--right" data-col-index="50">' + (isNaN(parseFloat(row.commission_tkp)) ? '' : numFmt(row.commission_tkp)) + '</td>' +
                '<td class="data-table__td" data-col-index="51">' + (row.commission_rate !== '' ? esc(row.commission_rate) + '%' : '') + '</td>' +
                '<td class="data-table__td data-table__td--right" data-col-index="52">' + (isNaN(parseFloat(row.service_fee)) ? '' : numFmt(row.service_fee)) + '</td>' +
                '<td class="data-table__td data-table__td--right" data-col-index="53">' + (isNaN(parseFloat(row.supplier_fee)) ? '' : numFmt(row.supplier_fee)) + '</td>' +
                '<td class="data-table__td data-table__td--nowrap" data-col-index="54">' + esc(row.refund_date) + '</td>' +
                '<td class="data-table__td data-table__td--right" data-col-index="55">' + (isNaN(parseFloat(row.refund_amount)) ? '' : numFmt(row.refund_amount)) + '</td>' +
                '<td class="data-table__td data-table__td--right" data-col-index="56">' + (isNaN(parseFloat(row.refund_fee_client)) ? '' : numFmt(row.refund_fee_client)) + '</td>' +
                '<td class="data-table__td data-table__td--right" data-col-index="57">' + (isNaN(parseFloat(row.refund_fee_vendor)) ? '' : numFmt(row.refund_fee_vendor)) + '</td>' +
                '<td class="data-table__td data-table__td--right" data-col-index="58">' + (isNaN(parseFloat(row.refund_penalty_vendor)) ? '' : numFmt(row.refund_penalty_vendor)) + '</td>' +
                '<td class="data-table__td data-table__td--right" data-col-index="59">' + (isNaN(refundPc) ? '' : numFmt(row.refund_penalty_client)) + '</td>' +
                '<td class="data-table__td" data-col-index="60">' + esc(row.cont_email) + '</td>' +
                '<td class="data-table__td" data-col-index="61">' + esc(row.cont_phone) + '</td>' +
                '<td class="data-table__td" data-col-index="62">' + esc(row.cont_name) + '</td>' +
                '<td class="data-table__td" data-col-index="63">' + esc(row.supplier_code) + '</td>' +
                '<td class="data-table__td" data-col-index="64">' + esc(row.seg_carriers) + '</td>' +
                '<td class="data-table__td" data-col-index="65">' + esc(row.bag_allowance) + '</td>' +
                '<td class="data-table__td data-table__td--right" data-col-index="66">' + numFmt(row.discount) + '</td>' +
                '<td class="data-table__td" data-col-index="67">' + esc(row.passenger_middle_name) + '</td>' +
                '<td class="data-table__td" data-col-index="68">' + esc(row.passenger_doc_country) + '</td>' +
                '<td class="data-table__td" data-col-index="69">' + esc(row.passenger_doc_expire) + '</td>' +
                '</tr>';
        }

        function loadData(supplier, offset, append) {
            var url = 'api.php?action=data_rows&supplier=' + encodeURIComponent(supplier) + '&offset=' + offset + '&limit=' + PAGE_SIZE + '&sort=' + sortBy + '&dir=' + sortDir;
            if (!append) tbody.innerHTML = '<tr><td colspan="70" class="data-table__td data-table__td--empty">Загрузка...</td></tr>';
            fetch(url)
                .then(function(r) { return r.json(); })
                .then(function(data) {
                    if (data.status !== 'ok' || !data.rows) {
                        tbody.innerHTML = '<tr><td colspan="70" class="data-table__td data-table__td--empty">Ошибка загрузки</td></tr>';
                        panelCount.textContent = 'Ошибка';
                        btnLoadMore.style.display = 'none';
                        return;
                    }
                    if (!loadedData[supplier]) loadedData[supplier] = { rows: [], fileOffset: 0, total_files: 0, has_more: false };
                    if (append) {
                        loadedData[supplier].rows = loadedData[supplier].rows.concat(data.rows);
                    } else {
                        loadedData[supplier].rows = data.rows;
                    }
                    loadedData[supplier].fileOffset = offset + PAGE_SIZE;
                    loadedData[supplier].total_files = data.total_files;
                    loadedData[supplier].has_more = data.has_more;
                    var rows = loadedData[supplier].rows;
                    if (rows.length === 0) {
                        tbody.innerHTML = '<tr><td colspan="70" class="data-table__td data-table__td--empty">Нет записей</td></tr>';
                    } else {
                        var html = '';
                        for (var i = 0; i < rows.length; i++) html += renderRow(rows[i], i);
                        tbody.innerHTML = html;
                    }
                    panelCount.textContent = 'Записей: ' + rows.length + (data.total_files > 0 ? ' (файлов: ' + data.total_files + ')' : '');
                    btnLoadMore.style.display = data.has_more ? 'inline-block' : 'none';
                    if (document.getElementById('filterInput').value.trim()) {
                        document.getElementById('filterInput').dispatchEvent(new Event('input'));
                    }
                })
                .catch(function() {
                    tbody.innerHTML = '<tr><td colspan="70" class="data-table__td data-table__td--empty">Ошибка сети</td></tr>';
                    panelCount.textContent = 'Ошибка';
                    btnLoadMore.style.display = 'none';
                });
        }

        function switchTab(folder) {
            currentSupplier = folder;
            document.querySelectorAll('.data-tab').forEach(function(btn) {
                btn.classList.toggle('data-tab--active', btn.getAttribute('data-supplier') === folder);
            });
            if (loadedData[folder]) {
                var rows = loadedData[folder].rows;
                if (rows.length === 0) {
                    tbody.innerHTML = '<tr><td colspan="70" class="data-table__td data-table__td--empty">Нет записей</td></tr>';
                } else {
                    var html = '';
                    for (var i = 0; i < rows.length; i++) html += renderRow(rows[i], i);
                    tbody.innerHTML = html;
                }
                panelCount.textContent = 'Записей: ' + rows.length + (loadedData[folder].total_files > 0 ? ' (файлов: ' + loadedData[folder].total_files + ')' : '');
                btnLoadMore.style.display = loadedData[folder].has_more ? 'inline-block' : 'none';
            } else {
                loadData(folder, 0, false);
            }
        }

        document.querySelectorAll('.data-tab').forEach(function(btn) {
            btn.addEventListener('click', function() {
                switchTab(this.getAttribute('data-supplier'));
            });
        });
        btnLoadMore.addEventListener('click', function() {
            var nextFileOffset = loadedData[currentSupplier] ? loadedData[currentSupplier].fileOffset : 0;
            loadData(currentSupplier, nextFileOffset, true);
        });

        var sortIssue = document.getElementById('sortIssueDate');
        var sortParsed = document.getElementById('sortParsedAt');
        function updateSortLinks() {
            sortIssue.textContent = 'Дата выписки' + (sortBy === 'issue_date' ? (sortDir === 'asc' ? ' ↑' : ' ↓') : '');
            sortParsed.textContent = 'Дата загрузки' + (sortBy === 'parsed_at' ? (sortDir === 'asc' ? ' ↑' : ' ↓') : '');
            sortIssue.classList.toggle('panel__toolbar-sort-link--active', sortBy === 'issue_date');
            sortParsed.classList.toggle('panel__toolbar-sort-link--active', sortBy === 'parsed_at');
        }
        sortIssue.addEventListener('click', function(e) {
            e.preventDefault();
            sortBy = 'issue_date';
            sortDir = sortDir === 'asc' ? 'desc' : 'asc';
            updateSortLinks();
            loadedData[currentSupplier] = null;
            loadData(currentSupplier, 0, false);
        });
        sortParsed.addEventListener('click', function(e) {
            e.preventDefault();
            sortBy = 'parsed_at';
            sortDir = sortDir === 'asc' ? 'desc' : 'asc';
            updateSortLinks();
            loadedData[currentSupplier] = null;
            loadData(currentSupplier, 0, false);
        });
        updateSortLinks();

        loadData(currentSupplier, 0, false);

        document.getElementById('filterInput').addEventListener('input', function() {
            var q = this.value.trim().toLowerCase();
            var rows = tbody.querySelectorAll('tr');
            var visible = 0;
            rows.forEach(function(tr) {
                if (tr.querySelector('td[colspan="70"]')) return;
                var text = tr.textContent.toLowerCase();
                var show = !q || text.indexOf(q) !== -1;
                tr.style.display = show ? '' : 'none';
                if (show) visible++;
            });
            document.getElementById('filterCount').textContent = 'Показано: ' + visible + ' из ' + rows.length;
        });

        tbody.addEventListener('click', function(e) {
            var tr = e.target.closest('tr');
            if (!tr || e.target.closest('.btn-resend')) return;
            tr.classList.toggle('data-table__row--selected');
        });
    })();
    </script>
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
    var btnClearTable = document.getElementById('btnClearTable');
    if (btnClearTable) {
        btnClearTable.addEventListener('click', function() {
            if (!confirm('Удалить все JSON-файлы из папки json/? Это действие нельзя отменить.')) return;
            btnClearTable.disabled = true;
            btnClearTable.textContent = '⏳';
            fetch('api.php?action=clear_json', { method: 'POST' })
                .then(function(r) { return r.json(); })
                .then(function(data) {
                    if (data.status === 'ok') {
                        location.reload();
                    } else {
                        alert('Ошибка: ' + (data.message || 'Неизвестная ошибка'));
                    }
                })
                .catch(function(err) {
                    alert('Ошибка сети: ' + err);
                })
                .finally(function() {
                    btnClearTable.disabled = false;
                    btnClearTable.textContent = 'Очистить таблицу';
                });
        });
    }

    var table = document.querySelector('.data-table');
    if (!table) return;
    var thead = table.querySelector('thead');
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

    var btnExport = document.getElementById('btnExportXlsx');
    if (btnExport) {
        btnExport.addEventListener('click', function() {
            function doExport() {
                if (typeof XLSX === 'undefined') { alert('Библиотека XLSX не загружена'); return; }
                var tableEl = document.querySelector('.data-table');
                if (!tableEl) return;
                var clone = tableEl.cloneNode(true);
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
                var urls = ['assets/xlsx.full.min.js', 'https://cdn.jsdelivr.net/npm/xlsx@0.18.5/dist/xlsx.full.min.js', 'https://unpkg.com/xlsx@0.18.5/dist/xlsx.full.min.js'];
                function tryLoad(i) {
                    if (i >= urls.length) {
                        btnExport.disabled = false;
                        btnExport.textContent = 'Выгрузить в XLSX';
                        alert('Не удалось загрузить библиотеку XLSX.');
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
});
    </script>
</body>
</html>
