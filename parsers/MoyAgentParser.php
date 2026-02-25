<?php
/**
 * ============================================================
 * ПАРСЕР ПОСТАВЩИКА "МОЙ АГЕНТ"
 * ============================================================
 * 
 * Этот парсер обрабатывает XML-файлы от поставщика "Мой агент".
 * Файлы содержат заказы на авиабилеты в формате <order_snapshot>.
 * 
 * Основные секции входного XML:
 * - <header>       — информация о заказе (номер, дата, валюта)
 * - <customer>     — информация о клиенте
 * - <payments>     — информация об оплате
 * - <reservations> — бронирования (номера PNR, поставщики)
 * - <products>     — продукты (авиабилеты с сегментами, таксами, комиссиями)
 * - <travel_docs>  — документы (номера билетов, даты выписки)
 * - <passengers>   — пассажиры (ФИО, документы)
 * 
 * Парсер преобразует эти данные в единый JSON-формат ORDER
 * по спецификации RSTLS (см. документацию).
 * 
 * Для добавления нового поставщика создайте аналогичный файл
 * в папке parsers/ — он будет подхвачен автоматически.
 * ============================================================
 */

// Подключаем интерфейс, который обязывает реализовать нужные методы
require_once __DIR__ . '/../core/ParserInterface.php';

class MoyAgentParser implements ParserInterface
{
    /**
     * Возвращает имя папки, где лежат XML-файлы этого поставщика.
     * Система будет искать файлы в input/moyagent/
     */
    public function getSupplierFolder()
    {
        return 'moyagent';
    }

    /**
     * Возвращает человекочитаемое название поставщика.
     * Отображается в логах и на веб-странице.
     */
    public function getSupplierName()
    {
        return 'Мой агент';
    }

    /**
     * Главный метод — парсинг XML-файла "Мой агент".
     * 
     * Читает XML, извлекает данные о заказе, билетах, пассажирах,
     * и возвращает массив в формате ORDER для сохранения в JSON.
     * 
     * @param string $xmlFilePath — полный путь к XML-файлу
     * @return array — данные заказа в формате ORDER (RSTLS)
     * @throws Exception — если файл повреждён или имеет неверный формат
     */
    public function parse($xmlFilePath)
    {
        // -------------------------------------------------------
        // ШАГ 1: Загрузка и проверка XML-файла
        // -------------------------------------------------------
        
        // Проверяем, что файл существует и доступен для чтения
        if (!file_exists($xmlFilePath) || !is_readable($xmlFilePath)) {
            throw new Exception("Файл не найден или недоступен: {$xmlFilePath}");
        }

        // Читаем содержимое XML-файла
        $xmlContent = file_get_contents($xmlFilePath);
        if ($xmlContent === false) {
            throw new Exception("Не удалось прочитать файл: {$xmlFilePath}");
        }

        // Отключаем вывод стандартных ошибок XML — будем обрабатывать их сами
        $previousErrors = libxml_use_internal_errors(true);

        // Пытаемся разобрать XML
        $xml = simplexml_load_string($xmlContent);

        if ($xml === false) {
            // Собираем описания ошибок XML для понятного сообщения
            $errors = array();
            foreach (libxml_get_errors() as $error) {
                $errors[] = trim($error->message);
            }
            libxml_clear_errors();
            libxml_use_internal_errors($previousErrors);
            throw new Exception("Ошибка разбора XML: " . implode('; ', $errors));
        }

        libxml_use_internal_errors($previousErrors);

        // Проверяем, что корневой тег — order_snapshot
        if ($xml->getName() !== 'order_snapshot') {
            throw new Exception(
                "Неверный формат файла: ожидается корневой тег <order_snapshot>, "
                . "получен <{$xml->getName()}>"
            );
        }

        // -------------------------------------------------------
        // ШАГ 2: Извлечение основных данных заказа
        // -------------------------------------------------------

        // Читаем атрибуты заголовка заказа
        $header = $xml->header;
        if (!$header) {
            throw new Exception("В файле отсутствует секция <header>");
        }

        $orderId = (string)$header['ord_id'];       // Номер заказа
        $currency = (string)$header['currency'];     // Валюта (например, "RUB")
        $orderTime = (string)$header['time'];        // Дата заказа ("2025-10-13 12:16:00")

        // Читаем данные клиента
        $customer = $xml->customer;
        $clientCode = $customer ? (string)$customer['client_code'] : '';

        // -------------------------------------------------------
        // ШАГ 3: Подготовка вспомогательных данных
        // -------------------------------------------------------

        // Собираем карту пассажиров: psgr_id => данные пассажира
        // Это нужно, чтобы потом по ID пассажира получить его ФИО
        $passengersMap = $this->buildPassengersMap($xml);

        // Собираем карту документов (билетов): prod_id => данные билета
        // Это нужно, чтобы по ID продукта получить номер билета и дату выписки
        $travelDocsMap = $this->buildTravelDocsMap($xml);

        // Собираем карту бронирований: supplier => данные бронирования
        // Это нужно, чтобы по коду поставщика получить номер бронирования (PNR)
        $reservationsMap = $this->buildReservationsMap($xml);

        // -------------------------------------------------------
        // ШАГ 4: Анализ типа операции и обработка продуктов
        // -------------------------------------------------------

        // Определяем тип операции всего заказа (продажа / возврат / аннуляция)
        $orderAnalysis = $this->analyzeOrderType($xml);
        $isRefund = in_array($orderAnalysis['operation'], array('REF', 'RFND', 'CANX'));

        // Индексируем все air_ticket_prod по prod_id для быстрого доступа
        $airTicketsByProdId = array();
        if (isset($xml->products->product)) {
            foreach ($xml->products->product as $product) {
                if (isset($product->air_ticket_prod)) {
                    $at = $product->air_ticket_prod;
                    $pid = (string)$at['prod_id'];
                    $airTicketsByProdId[$pid] = $at;
                }
            }
        }

        $products = array();

        if ($isRefund) {
            // =============================================================
            // ВОЗВРАТ или АННУЛЯЦИЯ — формируем ОДИН продукт
            // =============================================================

            // Определяем оригинальный и возвратный product
            $origProdId = $orderAnalysis['original_prod_id'];
            $refProdId  = $orderAnalysis['refund_prod_id'];
            $refundDoc  = $orderAnalysis['refund_doc'];

            // Оригинальный product (для купонов, такс, комиссий)
            $origAirTicket = isset($airTicketsByProdId[$origProdId]) 
                ? $airTicketsByProdId[$origProdId] 
                : null;

            // Возвратный product (для penalty и сумм возврата)
            // При CANX — возвратный и оригинальный совпадают
            $refAirTicket = isset($airTicketsByProdId[$refProdId]) 
                ? $airTicketsByProdId[$refProdId] 
                : $origAirTicket;

            // Если нет оригинального — используем возвратный
            if ($origAirTicket === null) {
                $origAirTicket = $refAirTicket;
            }

            if ($origAirTicket === null) {
                throw new Exception("Не найден air_ticket_prod для возврата");
            }

            // Данные из оригинального product
            $supplier = (string)$origAirTicket['supplier'];
            $reservation = isset($reservationsMap[$supplier]) ? $reservationsMap[$supplier] : null;
            $reservationNumber = $reservation ? $reservation['rloc'] : '';

            // Пассажир — через документ возврата
            $psgId = $refundDoc ? $refundDoc['psgr_id'] : '';
            $passenger = isset($passengersMap[$psgId]) ? $passengersMap[$psgId] : null;
            $traveller = '';
            if ($passenger) {
                $traveller = $passenger['name'] . ' ' . $passenger['first_name'];
            }

            // Номер билета и дата выписки — из документа возврата
            $ticketNumber = $refundDoc ? $refundDoc['tkt_number'] : '';
            $refundDate = $refundDoc ? $this->formatDateTime($refundDoc['tkt_date']) : '';

            // Дата выписки оригинального билета (из TKT doc, если есть)
            $origDoc = isset($travelDocsMap[$origProdId]) ? $travelDocsMap[$origProdId] : null;
            $issueDate = $origDoc ? $this->formatDateTime($origDoc['tkt_date']) : $refundDate;

            // Возрастная категория
            $psgType = (string)$origAirTicket['psg_type'];
            $passengerAge = $this->mapPassengerAge($psgType);

            // Купоны — из ОРИГИНАЛЬНОГО product
            $coupons = $this->buildCoupons($origAirTicket);

            // Таксы — из ОРИГИНАЛЬНОГО product
            $taxes = $this->buildTaxes($origAirTicket);

            // Платежи — из ОРИГИНАЛЬНОГО product (полная стоимость)
            $origFare = (float)(string)$origAirTicket['fare'];
            $origTaxes = (float)(string)$origAirTicket['taxes'];
            $origTotal = $origFare + $origTaxes;

            $payments = array(
                array(
                    'TYPE' => 'INVOICE',
                    'AMOUNT' => $origTotal,
                    'EQUIVALENT_AMOUNT' => $origTotal,
                    'RELATED_TICKET_NUMBER' => null
                )
            );

            // Комиссии — из ОРИГИНАЛЬНОГО product
            $commissions = $this->buildCommissions($origAirTicket);

            // Penalty и сумма возврата — из ВОЗВРАТНОГО product
            $penalty = $this->extractPenalty($refAirTicket);
            $refFare = (float)(string)$refAirTicket['fare'];
            $refTaxes = (float)(string)$refAirTicket['taxes'];
            $refundAmount = $refFare + $refTaxes - $penalty;

            $productData = array(
                'UID' => $this->generateUUID(),
                'PRODUCT_TYPE' => array(
                    'NAME' => 'Авиабилет',
                    'CODE' => '000000001'
                ),
                'NUMBER' => $ticketNumber,
                'ISSUE_DATE' => $issueDate,
                'RESERVATION_NUMBER' => $reservationNumber,
                'BOOKING_AGENT' => array(
                    'CODE' => (string)$origAirTicket['issuingAgent'],
                    'NAME' => ''
                ),
                'AGENT' => array(
                    'CODE' => (string)$origAirTicket['issuingAgent'],
                    'NAME' => ''
                ),
                'STATUS' => 'возврат',
                'TICKET_TYPE' => 'OWN',
                'PASSENGER_AGE' => $passengerAge,
                'CONJ_COUNT' => 0,
                'PENALTY' => $penalty,
                'CARRIER' => (string)$origAirTicket['validating_carrier'],
                'SUPPLIER' => $supplier,
                'COUPONS' => $coupons,
                'TRAVELLER' => $traveller,
                'TAXES' => $taxes,
                'CURRENCY' => $currency,
                'PAYMENTS' => $payments,
                'COMMISSIONS' => $commissions,
                'REFUND' => array(
                    'DATA' => $refundDate,
                    'AMOUNT' => $refundAmount,
                    'EQUIVALENT_AMOUNT' => $refundAmount,
                    'FEE_CLIENT' => 0,
                    'FEE_VENDOR' => 0,
                    'PENALTY_CLIENT' => $penalty,
                    'PENALTY_VENDOR' => $penalty
                )
            );

            $products[] = $productData;

        } else {
            // =============================================================
            // ПРОДАЖА — обычная обработка каждого product
            // =============================================================

            if (isset($xml->products->product)) {
                foreach ($xml->products->product as $product) {
                    // Пропускаем сервисные продукты (service_prod)
                    if (!isset($product->air_ticket_prod)) {
                        continue;
                    }

                    $airTicket = $product->air_ticket_prod;
                    $prodId = (string)$airTicket['prod_id'];

                    // Получаем документ (билет) для этого продукта
                    $travelDoc = isset($travelDocsMap[$prodId]) ? $travelDocsMap[$prodId] : null;

                    // Получаем бронирование по коду поставщика
                    $supplier = (string)$airTicket['supplier'];
                    $reservation = isset($reservationsMap[$supplier]) ? $reservationsMap[$supplier] : null;

                    // Определяем пассажира
                    $psgId = $travelDoc ? $travelDoc['psgr_id'] : '';
                    $passenger = isset($passengersMap[$psgId]) ? $passengersMap[$psgId] : null;

                    $traveller = '';
                    if ($passenger) {
                        $traveller = $passenger['name'] . ' ' . $passenger['first_name'];
                    }

                    $psgType = (string)$airTicket['psg_type'];
                    $passengerAge = $this->mapPassengerAge($psgType);

                    $ticketNumber = $travelDoc ? $travelDoc['tkt_number'] : '';
                    $issueDate = $travelDoc ? $this->formatDateTime($travelDoc['tkt_date']) : '';
                    $reservationNumber = $reservation ? $reservation['rloc'] : '';

                    $coupons = $this->buildCoupons($airTicket);
                    $taxes = $this->buildTaxes($airTicket);

                    $fare = (float)(string)$airTicket['fare'];
                    $taxesAmount = (float)(string)$airTicket['taxes'];
                    $totalAmount = $fare + $taxesAmount;

                    $payments = array(
                        array(
                            'TYPE' => 'INVOICE',
                            'AMOUNT' => $totalAmount,
                            'EQUIVALENT_AMOUNT' => $totalAmount,
                            'RELATED_TICKET_NUMBER' => null
                        )
                    );

                    $commissions = $this->buildCommissions($airTicket);

                    $productData = array(
                        'UID' => $this->generateUUID(),
                        'PRODUCT_TYPE' => array(
                            'NAME' => 'Авиабилет',
                            'CODE' => '000000001'
                        ),
                        'NUMBER' => $ticketNumber,
                        'ISSUE_DATE' => $issueDate,
                        'RESERVATION_NUMBER' => $reservationNumber,
                        'BOOKING_AGENT' => array(
                            'CODE' => (string)$airTicket['issuingAgent'],
                            'NAME' => ''
                        ),
                        'AGENT' => array(
                            'CODE' => (string)$airTicket['issuingAgent'],
                            'NAME' => ''
                        ),
                        'STATUS' => 'продажа',
                        'TICKET_TYPE' => 'OWN',
                        'PASSENGER_AGE' => $passengerAge,
                        'CONJ_COUNT' => 0,
                        'PENALTY' => 0,
                        'CARRIER' => (string)$airTicket['validating_carrier'],
                        'SUPPLIER' => $supplier,
                        'COUPONS' => $coupons,
                        'TRAVELLER' => $traveller,
                        'TAXES' => $taxes,
                        'CURRENCY' => $currency,
                        'PAYMENTS' => $payments,
                        'COMMISSIONS' => $commissions
                    );

                    $products[] = $productData;
                }
            }
        }

        // Если не нашли ни одного авиабилета
        if (empty($products)) {
            throw new Exception("В файле не найдено ни одного авиабилета (air_ticket_prod)");
        }

        // -------------------------------------------------------
        // ШАГ 5: Формируем итоговый заказ (ORDER)
        // -------------------------------------------------------

        $order = array(
            'UID' => $this->generateUUID(),
            'INVOICE_NUMBER' => $orderId,
            'INVOICE_DATA' => $this->formatDateTime($orderTime),
            'CLIENT' => $clientCode,
            'SOURCE_FILE' => basename($xmlFilePath),
            'PARSED_AT' => date('Y-m-d H:i:s'),
            'PRODUCTS' => $products
        );

        return $order;
    }

    // =========================================================
    // ВСПОМОГАТЕЛЬНЫЕ МЕТОДЫ
    // =========================================================

    /**
     * Собирает карту пассажиров из XML.
     * 
     * Возвращает массив, где ключ — ID пассажира (psgr_id),
     * а значение — массив с его данными (имя, фамилия и т.д.)
     * 
     * @param SimpleXMLElement $xml — корневой элемент XML
     * @return array — карта пассажиров
     */
    private function buildPassengersMap($xml)
    {
        $map = array();

        if (isset($xml->passengers->passenger)) {
            foreach ($xml->passengers->passenger as $passenger) {
                $id = (string)$passenger['psgr_id'];
                $map[$id] = array(
                    'psgr_id'    => $id,
                    'psgr_type'  => (string)$passenger['psgr_type'],
                    'first_name' => (string)$passenger['first_name'],
                    'name'       => (string)$passenger['name'],
                    'gender'     => (string)$passenger['gender'],
                    'birth_date' => (string)$passenger['birth_date']
                );
            }
        }

        return $map;
    }

    /**
     * Собирает карту документов (билетов) из XML.
     * 
     * Возвращает массив, где ключ — ID продукта (prod_id),
     * а значение — данные билета (номер, дата выписки, операция).
     * 
     * Если на один prod_id несколько документов — хранится последний.
     * Дополнительно строится список ВСЕХ документов для определения
     * типа операции всего заказа (REF/CANX имеют приоритет над TKT).
     * 
     * @param SimpleXMLElement $xml — корневой элемент XML
     * @return array — карта документов
     */
    private function buildTravelDocsMap($xml)
    {
        $map = array();

        if (isset($xml->travel_docs->travel_doc)) {
            foreach ($xml->travel_docs->travel_doc as $travelDoc) {
                // Внутри travel_doc может быть air_ticket_doc
                if (isset($travelDoc->air_ticket_doc)) {
                    $doc = $travelDoc->air_ticket_doc;
                    $prodId = (string)$doc['prod_id'];
                    $map[$prodId] = array(
                        'prod_id'    => $prodId,
                        'psgr_id'    => (string)$doc['psgr_id'],
                        'tkt_oper'   => (string)$doc['tkt_oper'],
                        'tkt_number' => (string)$doc['tkt_number'],
                        'tkt_date'   => (string)$doc['tkt_date'],
                        'rsrv_id'    => (string)$doc['rsrv_id']
                    );
                }
            }
        }

        return $map;
    }

    /**
     * Анализирует все travel_doc в файле и определяет тип операции заказа.
     * 
     * Логика приоритетов:
     * - Если есть хотя бы один REF или CANX → заказ является возвратом
     * - Иначе → продажа
     * 
     * Возвращает массив:
     * - 'operation'     => 'REF', 'CANX' или 'TKT'
     * - 'refund_doc'    => данные travel_doc с REF/CANX (или null)
     * - 'refund_prod_id'=> prod_id возвратного продукта (или null)
     * - 'original_prod_id' => prod_id оригинального продукта TKT (или null)
     * 
     * @param SimpleXMLElement $xml — корневой элемент XML
     * @return array — результат анализа
     */
    private function analyzeOrderType($xml)
    {
        $result = array(
            'operation'        => 'TKT',
            'refund_doc'       => null,
            'refund_prod_id'   => null,
            'original_prod_id' => null
        );

        if (!isset($xml->travel_docs->travel_doc)) {
            return $result;
        }

        $allDocs = array();
        foreach ($xml->travel_docs->travel_doc as $travelDoc) {
            if (isset($travelDoc->air_ticket_doc)) {
                $doc = $travelDoc->air_ticket_doc;
                $allDocs[] = array(
                    'prod_id'    => (string)$doc['prod_id'],
                    'psgr_id'    => (string)$doc['psgr_id'],
                    'tkt_oper'   => strtoupper(trim((string)$doc['tkt_oper'])),
                    'tkt_number' => (string)$doc['tkt_number'],
                    'tkt_date'   => (string)$doc['tkt_date'],
                    'rsrv_id'    => (string)$doc['rsrv_id']
                );
            }
        }

        // Ищем REF или CANX среди всех документов (приоритет над TKT)
        foreach ($allDocs as $doc) {
            if ($doc['tkt_oper'] === 'REF' || $doc['tkt_oper'] === 'CANX' || $doc['tkt_oper'] === 'RFND') {
                $result['operation'] = $doc['tkt_oper'];
                $result['refund_doc'] = $doc;
                $result['refund_prod_id'] = $doc['prod_id'];
                break;
            }
        }

        // Ищем оригинальный TKT
        foreach ($allDocs as $doc) {
            if ($doc['tkt_oper'] === 'TKT') {
                $result['original_prod_id'] = $doc['prod_id'];
                break;
            }
        }

        // Если есть возврат, но нет отдельного оригинала — оригинал = возврат
        // (сценарий CANX: один product, один doc с CANX)
        if ($result['refund_prod_id'] !== null && $result['original_prod_id'] === null) {
            $result['original_prod_id'] = $result['refund_prod_id'];
        }

        return $result;
    }

    /**
     * Извлекает сумму штрафа (PENALTY) из возвратного product.
     * 
     * Штраф хранится как такса с code="PEN" внутри air_seg.
     * 
     * @param SimpleXMLElement $airTicket — элемент <air_ticket_prod> возвратного продукта
     * @return float — сумма штрафа (0 если штрафа нет)
     */
    private function extractPenalty($airTicket)
    {
        $penalty = 0;

        if (isset($airTicket->air_seg)) {
            foreach ($airTicket->air_seg as $seg) {
                if (isset($seg->air_tax)) {
                    foreach ($seg->air_tax as $tax) {
                        if (strtoupper((string)$tax['code']) === 'PEN') {
                            $penalty += (float)(string)$tax['amount'];
                        }
                    }
                }
            }
        }

        return $penalty;
    }

    /**
     * Собирает карту бронирований из XML.
     * 
     * Возвращает массив, где ключ — код поставщика (supplier),
     * а значение — данные бронирования (номер PNR, валюта CRS и т.д.)
     * 
     * @param SimpleXMLElement $xml — корневой элемент XML
     * @return array — карта бронирований
     */
    private function buildReservationsMap($xml)
    {
        $map = array();

        if (isset($xml->reservations->reservation)) {
            foreach ($xml->reservations->reservation as $reservation) {
                $supplier = (string)$reservation['supplier'];
                $map[$supplier] = array(
                    'rloc'         => (string)$reservation['rloc'],
                    'rsrv_id'      => (string)$reservation['rsrv_id'],
                    'crs'          => (string)$reservation['crs'],
                    'crs_currency' => (string)$reservation['crs_currency'],
                    'supplier'     => $supplier
                );
            }
        }

        return $map;
    }

    /**
     * Формирует массив купонов (COUPONS) из сегментов перелёта.
     * 
     * Каждый сегмент перелёта (air_seg) превращается в один купон,
     * содержащий номер рейса, аэропорты, даты и класс.
     * 
     * @param SimpleXMLElement $airTicket — элемент <air_ticket_prod>
     * @return array — массив купонов
     */
    private function buildCoupons($airTicket)
    {
        $coupons = array();

        if (isset($airTicket->air_seg)) {
            foreach ($airTicket->air_seg as $seg) {
                $coupons[] = array(
                    'FLIGHT_NUMBER'      => (string)$seg['flight_number'],
                    'FARE_BASIS'         => (string)$seg['fare_basis'],
                    'DEPARTURE_AIRPORT'  => (string)$seg['departure_airport'],
                    'DEPARTURE_DATETIME' => $this->formatDateTime((string)$seg['departure_datetime']),
                    'ARRIVAL_AIRPORT'    => (string)$seg['arrival_airport'],
                    'ARRIVAL_DATETIME'   => $this->formatDateTime((string)$seg['arrival_datetime']),
                    'CLASS'              => (string)$seg['class']
                );
            }
        }

        return $coupons;
    }

    /**
     * Формирует массив такс (TAXES) из данных билета.
     * 
     * Первым элементом всегда идёт тариф (fare) — это основная стоимость билета.
     * Затем добавляются отдельные таксы из элементов <air_tax>.
     * 
     * @param SimpleXMLElement $airTicket — элемент <air_ticket_prod>
     * @return array — массив такс
     */
    private function buildTaxes($airTicket)
    {
        $taxes = array();

        // Первая такса — это всегда тариф (основная стоимость билета)
        $fare = (float)(string)$airTicket['fare'];
        $taxes[] = array(
            'CODE'              => '',
            'AMOUNT'            => $fare,
            'EQUIVALENT_AMOUNT' => $fare,
            'VAT_RATE'          => 0,
            'VAT_AMOUNT'        => 0
        );

        // Добавляем отдельные таксы из каждого сегмента перелёта
        if (isset($airTicket->air_seg)) {
            foreach ($airTicket->air_seg as $seg) {
                if (isset($seg->air_tax)) {
                    foreach ($seg->air_tax as $tax) {
                        $amount = (float)(string)$tax['amount'];
                        $taxes[] = array(
                            'CODE'              => (string)$tax['code'],
                            'AMOUNT'            => $amount,
                            'EQUIVALENT_AMOUNT' => $amount,
                            'VAT_RATE'          => 0,
                            'VAT_AMOUNT'        => 0
                        );
                    }
                }
            }
        }

        return $taxes;
    }

    /**
     * Формирует массив комиссий (COMMISSIONS) из данных билета.
     * 
     * Из XML берутся два типа комиссий:
     * 1. Сбор поставщика (service_fee) — записывается как комиссия типа "CLIENT"
     * 2. Комиссия от поставщика — сумма всех fee с type="commission",
     *    записывается как комиссия типа "VENDOR"
     * 
     * @param SimpleXMLElement $airTicket — элемент <air_ticket_prod>
     * @return array — массив комиссий
     */
    private function buildCommissions($airTicket)
    {
        $commissions = array();

        // Комиссия типа "CLIENT" — сбор поставщика (service_fee)
        $serviceFee = (float)(string)$airTicket['service_fee'];
        if ($serviceFee > 0) {
            $commissions[] = array(
                'TYPE'              => 'CLIENT',
                'NAME'              => 'Сбор поставщика',
                'AMOUNT'            => $serviceFee,
                'EQUIVALENT_AMOUNT' => $serviceFee,
                'RATE'              => null
            );
        }

        // Комиссия типа "VENDOR" — сумма всех fee с type="commission"
        $vendorTotal = 0;
        if (isset($airTicket->fees->fee)) {
            foreach ($airTicket->fees->fee as $fee) {
                $feeType = (string)$fee['type'];
                if ($feeType === 'commission') {
                    $vendorTotal += (float)(string)$fee['amount'];
                }
            }
        }

        if ($vendorTotal > 0) {
            $commissions[] = array(
                'TYPE'              => 'VENDOR',
                'NAME'              => 'Комиссия поставщика',
                'AMOUNT'            => $vendorTotal,
                'EQUIVALENT_AMOUNT' => $vendorTotal,
                'RATE'              => null
            );
        }

        return $commissions;
    }

    /**
     * Преобразует дату из формата "Мой агент" в формат RSTLS.
     * 
     * Входной формат:  "2025-10-13 12:16:00"
     * Выходной формат: "20251013121600" (ГГГГММДДччммсс)
     * 
     * @param string $dateTime — дата и время в формате поставщика
     * @return string — дата и время в формате ГГГГММДДччммсс
     */
    private function formatDateTime($dateTime)
    {
        if (empty($dateTime)) {
            return '';
        }

        // Пытаемся разобрать дату с помощью PHP
        $timestamp = strtotime($dateTime);

        if ($timestamp === false) {
            // Если не удалось разобрать — просто убираем все нечисловые символы
            return preg_replace('/[^0-9]/', '', $dateTime);
        }

        // Форматируем в нужный формат: ГГГГММДДччммсс
        return date('YmdHis', $timestamp);
    }

    /**
     * Преобразует тип операции с билетом в статус для ORDER.
     * 
     * В XML "Мой агент" тип операции указан в поле tkt_oper:
     * - TKT  — выписка билета (продажа)
     * - RFND — возврат билета
     * - EXCH — обмен билета
     * 
     * @param string $tktOper — тип операции из XML
     * @return string — статус для JSON ("продажа", "возврат", "обмен")
     */
    private function mapTicketStatus($tktOper)
    {
        $statusMap = array(
            'TKT'  => 'продажа',
            'REF'  => 'возврат',
            'RFND' => 'возврат',
            'CANX' => 'возврат',
            'EXCH' => 'обмен'
        );

        $tktOper = strtoupper(trim($tktOper));
        return isset($statusMap[$tktOper]) ? $statusMap[$tktOper] : 'продажа';
    }

    /**
     * Преобразует тип пассажира из формата поставщика в формат ORDER.
     * 
     * В XML "Мой агент" тип указан кратко: adt, chd, inf
     * В ORDER нужен полный вариант: ADULT, CHILD, INFANT
     * 
     * @param string $psgType — тип пассажира из XML (adt, chd, inf)
     * @return string — тип для JSON (ADULT, CHILD, INFANT)
     */
    private function mapPassengerAge($psgType)
    {
        $ageMap = array(
            'adt' => 'ADULT',
            'chd' => 'CHILD',
            'inf' => 'INFANT',
            'ins' => 'INFANT'
        );

        $psgType = strtolower(trim($psgType));
        return isset($ageMap[$psgType]) ? $ageMap[$psgType] : 'ADULT';
    }

    /**
     * Генерирует уникальный идентификатор UUID версии 4.
     * 
     * UUID выглядит как: 8fd8578c-c002-4e73-891d-278373b59ef4
     * Каждый вызов генерирует новый уникальный идентификатор.
     * 
     * Работает как на PHP 7, так и на PHP 8.
     * 
     * @return string — UUID в формате xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx
     */
    private function generateUUID()
    {
        // Генерируем 16 случайных байт
        if (function_exists('random_bytes')) {
            $data = random_bytes(16);
        } else {
            // Запасной вариант для старых версий PHP
            $data = openssl_random_pseudo_bytes(16);
        }

        // Устанавливаем версию UUID (4) и вариант (RFC 4122)
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40);  // Версия 4
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80);  // Вариант RFC 4122

        // Форматируем в стандартный вид UUID
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}
