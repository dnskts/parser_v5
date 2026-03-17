<?php
/**
 * ============================================================
 * ПАРСЕР ПОСТАВЩИКА "SmartTravel" (РЖД-ЦПР)
 * ============================================================
 *
 * Обработка JSON от SmartTravel: ЖД-билеты, авиа, страховки.
 * Один файл для обоих режимов (PUSH и PULL):
 *
 * PUSH (webhook): корень содержит "OrderItem" или "OrderItemReport"
 *   → parsePushReport() → один ORDER
 *
 * PULL (API): корень содержит "Orders"
 *   → parsePullResponse() → массив ORDER-ов
 *
 * Определение режима — по наличию ключей в JSON.
 * ============================================================
 */

require_once __DIR__ . '/../core/ParserInterface.php';
require_once __DIR__ . '/../core/Utils.php';
require_once __DIR__ . '/constants/SmartTravelConstants.php';

class SmartTravelParser implements ParserInterface
{
    public function getSupplierFolder()
    {
        return 'smarttravel';
    }

    public function getSupplierName()
    {
        return 'SmartTravel';
    }

    /**
     * Главный метод парсинга.
     *
     * @param string $filePath — путь к JSON-файлу (input/smarttravel/*.json)
     * @return array — один ORDER (PUSH) или массив ORDER-ов (PULL)
     * @throws Exception — если файл невалиден
     */
    public function parse($filePath)
    {
        if (!file_exists($filePath) || !is_readable($filePath)) {
            throw new Exception('Файл не найден или недоступен: ' . basename($filePath));
        }

        $content = file_get_contents($filePath);
        if (empty($content)) {
            throw new Exception('Пустой файл: ' . basename($filePath));
        }

        $data = json_decode($content, true);
        if (!is_array($data)) {
            throw new Exception('Невалидный JSON: ' . basename($filePath) . ' — ' . json_last_error_msg());
        }

        $sourceFile = basename($filePath);

        // Определяем режим по структуре JSON
        if (isset($data['Orders'])) {
            // PULL — ответ API: {"Orders": [...]}
            return $this->parsePullResponse($data, $sourceFile);
        }

        if (isset($data['OrderItem']) || isset($data['OrderItemReport'])) {
            // PUSH — webhook: {"OrderItem": {...}, "OrderCustomers": [...], ...}
            // или XML-обёртка <OrderItemReport> → {"OrderItemReport": {...}}
            return $this->parsePushReport($data, $sourceFile);
        }

        throw new Exception('Неизвестный формат SmartTravel JSON: нет ключей Orders/OrderItem — ' . $sourceFile);
    }

    // ==========================================================
    // PUSH — один заказ от webhook
    // ==========================================================

    /**
     * Парсинг PUSH-уведомления (полные данные: клиенты, бланки, маршрут).
     *
     * @param array  $data       — декодированный JSON
     * @param string $sourceFile — имя исходного файла
     * @return array — один ORDER (ассоциативный массив с ключом UID)
     */
    private function parsePushReport($data, $sourceFile)
    {
        // Извлекаем OrderItem (может быть вложен в OrderItemReport)
        $orderItem = null;
        $orderCustomers = array();
        $posSysName = '';
        $employeeName = '';

        if (isset($data['OrderItemReport'])) {
            // XML-стиль обёртки
            $report = $data['OrderItemReport'];
            $orderItem = isset($report['OrderItem']) ? $report['OrderItem'] : $report;
            $orderCustomers = isset($report['OrderCustomers']) ? $report['OrderCustomers'] : array();
            $posSysName = isset($report['PosSysName']) ? (string)$report['PosSysName'] : '';
            $employeeName = isset($report['EmployeeName']) ? (string)$report['EmployeeName'] : '';
        } else {
            $orderItem = isset($data['OrderItem']) ? $data['OrderItem'] : array();
            $orderCustomers = isset($data['OrderCustomers']) ? $data['OrderCustomers'] : array();
            $posSysName = isset($data['PosSysName']) ? (string)$data['PosSysName'] : '';
            $employeeName = isset($data['EmployeeName']) ? (string)$data['EmployeeName'] : '';
        }

        if (empty($orderItem)) {
            throw new Exception('Пустой OrderItem в PUSH: ' . $sourceFile);
        }

        // Нормализуем OrderCustomers в массив
        if (!empty($orderCustomers) && !isset($orderCustomers[0])) {
            $orderCustomers = array($orderCustomers);
        }

        // Карта клиентов: OrderCustomerId → данные пассажира
        $customersMap = $this->buildCustomersMap($orderCustomers);

        $orderId     = isset($orderItem['OrderId']) ? (string)$orderItem['OrderId'] : '';
        $createDt    = isset($orderItem['CreateDateTime']) ? $orderItem['CreateDateTime'] : '';
        $client      = !empty($posSysName) ? $posSysName : '';

        // Определяем CLIENT из атрибутов или ClientGroupSysName
        if (empty($client) && isset($orderItem['ClientGroupSysName']) && !empty($orderItem['ClientGroupSysName'])) {
            $client = (string)$orderItem['ClientGroupSysName'];
        }

        $order = array(
            'UID'            => Utils::generateUUID(),
            'INVOICE_NUMBER' => $orderId,
            'INVOICE_DATA'   => $this->formatSmartDate($createDt),
            'CLIENT'         => $client,
            'SOURCE_FILE'    => $sourceFile,
            'PARSED_AT'      => date('Y-m-d H:i:s'),
            'PRODUCTS'       => array(),
        );

        // Контакты (из первого OrderCustomer, если есть)
        if (!empty($orderCustomers)) {
            $firstCustomer = $orderCustomers[0];
            if (isset($firstCustomer['Email']) && !empty($firstCustomer['Email'])) {
                $order['CONT_EMAIL'] = (string)$firstCustomer['Email'];
            }
            if (isset($firstCustomer['Phone']) && !empty($firstCustomer['Phone'])) {
                $order['CONT_PHONE'] = (string)$firstCustomer['Phone'];
            }
        }

        // Агент
        if (!empty($employeeName)) {
            $order['CONT_NAME'] = $employeeName;
        }

        // Извлекаем бланки и клиентов позиции
        $itemBlanks    = isset($orderItem['OrderItemBlanks']) ? $orderItem['OrderItemBlanks'] : array();
        $itemCustomers = isset($orderItem['OrderItemCustomers']) ? $orderItem['OrderItemCustomers'] : array();

        if (!empty($itemBlanks) && !isset($itemBlanks[0])) {
            $itemBlanks = array($itemBlanks);
        }
        if (!empty($itemCustomers) && !isset($itemCustomers[0])) {
            $itemCustomers = array($itemCustomers);
        }

        // Карта: OrderItemBlankId → OrderItemCustomer (для связки бланк-пассажир)
        $blankCustomerMap = $this->buildBlankCustomerMap($itemCustomers);

        $operationType   = isset($orderItem['OperationType']) ? $orderItem['OperationType'] : 'Purchase';
        $operationReason = isset($orderItem['OperationReason']) ? $orderItem['OperationReason'] : '';
        $reservationNum  = isset($orderItem['ReservationNumber']) ? (string)$orderItem['ReservationNumber'] : '';
        $confirmDt       = isset($orderItem['ConfirmDateTime']) ? $orderItem['ConfirmDateTime'] : '';
        $totalAmount     = isset($orderItem['Amount']) ? (float)$orderItem['Amount'] : 0;
        $totalVat        = isset($orderItem['Vat']) ? (float)$orderItem['Vat'] : 0;

        // Маршрут (на уровне OrderItem — один маршрут для всех бланков)
        $coupons = $this->buildCouponsFromOrderItem($orderItem);

        // Перевозчик
        $carrier = '';
        if (isset($orderItem['CarrierDescription']) && !empty($orderItem['CarrierDescription'])) {
            $carrier = (string)$orderItem['CarrierDescription'];
        } elseif (isset($orderItem['CarrierMnemocode']) && !empty($orderItem['CarrierMnemocode'])) {
            $carrier = (string)$orderItem['CarrierMnemocode'];
        }

        // Клиентская и агентская комиссия (на уровне OrderItem)
        $commissions = $this->buildCommissionsFromItem($orderItem);

        // Если нет бланков — один продукт из OrderItem целиком
        if (empty($itemBlanks)) {
            $product = $this->buildProductFromOrderItem(
                $orderItem, $operationType, $reservationNum, $confirmDt,
                $carrier, $coupons, $commissions, $customersMap, $itemCustomers, $sourceFile
            );
            if ($product !== null) {
                $order['PRODUCTS'][] = $product;
            }
        } else {
            // По бланку — отдельный PRODUCT
            foreach ($itemBlanks as $blank) {
                $product = $this->buildProductFromBlank(
                    $blank, $orderItem, $operationType, $reservationNum, $confirmDt,
                    $carrier, $coupons, $commissions, $customersMap, $blankCustomerMap, $sourceFile
                );
                if ($product !== null) {
                    $order['PRODUCTS'][] = $product;
                }
            }
        }

        if (empty($order['PRODUCTS'])) {
            throw new Exception('Нет продуктов в PUSH-заказе: ' . $sourceFile);
        }

        return $order;
    }

    /**
     * Построение PRODUCT из бланка (OrderItemBlank).
     */
    private function buildProductFromBlank(
        $blank, $orderItem, $operationType, $reservationNum, $confirmDt,
        $carrier, $coupons, $commissions, $customersMap, $blankCustomerMap, $sourceFile
    ) {
        $blankNumber = isset($blank['BlankNumber']) ? (string)$blank['BlankNumber'] : '';
        $blankAmount = isset($blank['Amount']) ? (float)$blank['Amount'] : 0;
        $blankId     = isset($blank['OrderItemBlankId']) ? $blank['OrderItemBlankId'] : 0;

        // Пропуск бланков без номера и с суммой 0
        if (empty($blankNumber) && $blankAmount == 0) {
            return null;
        }

        // Находим пассажира для этого бланка
        $traveller = '';
        $passengerData = array();
        if (isset($blankCustomerMap[$blankId])) {
            $itemCust = $blankCustomerMap[$blankId];
            $custId = isset($itemCust['OrderCustomerId']) ? $itemCust['OrderCustomerId'] : 0;
            if (isset($customersMap[$custId])) {
                $passengerData = $customersMap[$custId];
            }
            // Категория пассажира
            $category = isset($itemCust['Category']) ? $itemCust['Category'] : 'Adult';
        } else {
            $category = 'Adult';
        }

        if (!empty($passengerData)) {
            $lastName   = isset($passengerData['LastName']) ? $passengerData['LastName'] : '';
            $firstName  = isset($passengerData['FirstName']) ? $passengerData['FirstName'] : '';
            $traveller  = trim(mb_strtoupper($lastName . ' ' . $firstName, 'UTF-8'));
        }

        $status = SmartTravelConstants::mapOperationType($operationType);

        $product = array(
            'UID'                => Utils::generateUUID(),
            'PRODUCT_TYPE'       => array('NAME' => 'ЖД-билет', 'CODE' => '000000001'),
            'NUMBER'             => $blankNumber,
            'ISSUE_DATE'         => $this->formatSmartDate($confirmDt),
            'RESERVATION_NUMBER' => $reservationNum,
            'STATUS'             => $status,
            'TICKET_TYPE'        => 'OWN',
            'PASSENGER_AGE'      => SmartTravelConstants::mapPassengerCategory($category),
            'TRAVELLER'          => $traveller,
            'SUPPLIER'           => $this->getSupplierName(),
            'CARRIER'            => $carrier,
            'CONJ_COUNT'         => 1,
            'PENALTY'            => 0,
            'CURRENCY'           => 'RUB',
            'COUPONS'            => $coupons,
            'TAXES'              => $this->buildTaxesFromBlank($blank, $orderItem),
            'PAYMENTS'           => array(
                array(
                    'TYPE'              => 'INVOICE',
                    'AMOUNT'            => $blankAmount,
                    'EQUIVALENT_AMOUNT' => $blankAmount,
                    'RELATED_TICKET_NUMBER' => null,
                ),
            ),
            'COMMISSIONS'        => $commissions,
        );

        // Данные пассажира
        if (!empty($passengerData)) {
            if (isset($passengerData['BirthDate']) && !empty($passengerData['BirthDate'])) {
                $product['PASSENGER_BIRTH_DATE'] = $this->formatSmartDate($passengerData['BirthDate']);
            }
            $product['PASSENGER_GENDER'] = SmartTravelConstants::mapGender(
                isset($passengerData['Sex']) ? $passengerData['Sex'] : 'NoValue'
            );
            if (isset($passengerData['DocumentType']) && !empty($passengerData['DocumentType'])) {
                $product['PASSENGER_DOC_TYPE'] = SmartTravelConstants::mapDocType($passengerData['DocumentType']);
            }
            if (isset($passengerData['DocumentNumber']) && !empty($passengerData['DocumentNumber'])) {
                $product['PASSENGER_DOC_NUMBER'] = (string)$passengerData['DocumentNumber'];
            }
            if (isset($passengerData['MiddleName']) && !empty($passengerData['MiddleName'])) {
                $product['PASSENGER_MIDDLE_NAME'] = mb_strtoupper($passengerData['MiddleName'], 'UTF-8');
            }
            if (isset($passengerData['CitizenshipCode']) && !empty($passengerData['CitizenshipCode'])) {
                $product['PASSENGER_DOC_COUNTRY'] = (string)$passengerData['CitizenshipCode'];
            }
            if (isset($passengerData['DocumentValidTill']) && !empty($passengerData['DocumentValidTill'])) {
                $formatted = $this->formatSmartDate($passengerData['DocumentValidTill']);
                if (!empty($formatted) && strpos($formatted, '9999') !== 0) {
                    $product['PASSENGER_DOC_EXPIRE'] = $formatted;
                }
            }
        }

        // BOOKING_AGENT / AGENT
        $product['BOOKING_AGENT'] = array('CODE' => $carrier, 'NAME' => $carrier);
        $product['AGENT']         = array('CODE' => $carrier, 'NAME' => $carrier);

        // Блок REFUND для возвратов
        if ($operationType === 'Return') {
            $product['REFUND'] = $this->buildRefundBlock($orderItem, $blankAmount);
        }

        // Дополнительные поля ЖД
        if (isset($orderItem['TrainNumber'])) {
            $product['TRAIN_NUMBER'] = (string)$orderItem['TrainNumber'];
        }
        if (isset($orderItem['CarNumber'])) {
            $product['CAR_NUMBER'] = (string)$orderItem['CarNumber'];
        }
        if (isset($orderItem['ServiceClass'])) {
            $product['SERVICE_CLASS'] = (string)$orderItem['ServiceClass'];
        }
        if (isset($orderItem['CarType'])) {
            $product['CAR_TYPE'] = SmartTravelConstants::mapCarType($orderItem['CarType']);
        }

        return $product;
    }

    /**
     * Построение PRODUCT из OrderItem (когда нет отдельных бланков).
     */
    private function buildProductFromOrderItem(
        $orderItem, $operationType, $reservationNum, $confirmDt,
        $carrier, $coupons, $commissions, $customersMap, $itemCustomers, $sourceFile
    ) {
        $amount = isset($orderItem['Amount']) ? (float)$orderItem['Amount'] : 0;
        $number = !empty($reservationNum) ? $reservationNum : (isset($orderItem['OrderItemId']) ? (string)$orderItem['OrderItemId'] : '');

        // Пропуск пустых
        if (empty($number) && $amount == 0) {
            return null;
        }

        // Пассажир из первого OrderItemCustomer
        $traveller = '';
        $category = 'Adult';
        $passengerData = array();

        if (!empty($itemCustomers)) {
            $firstItemCust = $itemCustomers[0];
            $custId = isset($firstItemCust['OrderCustomerId']) ? $firstItemCust['OrderCustomerId'] : 0;
            if (isset($customersMap[$custId])) {
                $passengerData = $customersMap[$custId];
                $lastName  = isset($passengerData['LastName']) ? $passengerData['LastName'] : '';
                $firstName = isset($passengerData['FirstName']) ? $passengerData['FirstName'] : '';
                $traveller = trim(mb_strtoupper($lastName . ' ' . $firstName, 'UTF-8'));
            }
            $category = isset($firstItemCust['Category']) ? $firstItemCust['Category'] : 'Adult';
        }

        $status = SmartTravelConstants::mapOperationType($operationType);

        $product = array(
            'UID'                => Utils::generateUUID(),
            'PRODUCT_TYPE'       => array('NAME' => 'ЖД-билет', 'CODE' => '000000001'),
            'NUMBER'             => $number,
            'ISSUE_DATE'         => $this->formatSmartDate($confirmDt),
            'RESERVATION_NUMBER' => $reservationNum,
            'STATUS'             => $status,
            'TICKET_TYPE'        => 'OWN',
            'PASSENGER_AGE'      => SmartTravelConstants::mapPassengerCategory($category),
            'TRAVELLER'          => $traveller,
            'SUPPLIER'           => $this->getSupplierName(),
            'CARRIER'            => $carrier,
            'CONJ_COUNT'         => 1,
            'PENALTY'            => 0,
            'CURRENCY'           => 'RUB',
            'COUPONS'            => $coupons,
            'TAXES'              => array(
                array('CODE' => '', 'AMOUNT' => $amount, 'EQUIVALENT_AMOUNT' => $amount, 'VAT_RATE' => 0, 'VAT_AMOUNT' => 0),
            ),
            'PAYMENTS'           => array(
                array('TYPE' => 'INVOICE', 'AMOUNT' => $amount, 'EQUIVALENT_AMOUNT' => $amount, 'RELATED_TICKET_NUMBER' => null),
            ),
            'COMMISSIONS'        => $commissions,
        );

        if (!empty($passengerData)) {
            if (isset($passengerData['BirthDate']) && !empty($passengerData['BirthDate'])) {
                $product['PASSENGER_BIRTH_DATE'] = $this->formatSmartDate($passengerData['BirthDate']);
            }
            $product['PASSENGER_GENDER'] = SmartTravelConstants::mapGender(
                isset($passengerData['Sex']) ? $passengerData['Sex'] : 'NoValue'
            );
            if (isset($passengerData['DocumentType'])) {
                $product['PASSENGER_DOC_TYPE'] = SmartTravelConstants::mapDocType($passengerData['DocumentType']);
            }
            if (isset($passengerData['DocumentNumber'])) {
                $product['PASSENGER_DOC_NUMBER'] = (string)$passengerData['DocumentNumber'];
            }
            if (isset($passengerData['MiddleName']) && !empty($passengerData['MiddleName'])) {
                $product['PASSENGER_MIDDLE_NAME'] = mb_strtoupper($passengerData['MiddleName'], 'UTF-8');
            }
        }

        $product['BOOKING_AGENT'] = array('CODE' => $carrier, 'NAME' => $carrier);
        $product['AGENT']         = array('CODE' => $carrier, 'NAME' => $carrier);

        if ($operationType === 'Return') {
            $product['REFUND'] = $this->buildRefundBlock($orderItem, $amount);
        }

        return $product;
    }

    // ==========================================================
    // PULL — массив заказов от API
    // ==========================================================

    /**
     * Парсинг PULL-ответа (краткие данные без клиентов и бланков).
     *
     * @param array  $data       — декодированный JSON с ключом "Orders"
     * @param string $sourceFile — имя исходного файла
     * @return array — массив ORDER-ов (числовые ключи)
     */
    private function parsePullResponse($data, $sourceFile)
    {
        $orders = $data['Orders'];
        if (!is_array($orders) || empty($orders)) {
            throw new Exception('Пустой массив Orders в PULL: ' . $sourceFile);
        }

        $result = array();

        foreach ($orders as $shortOrder) {
            $orderId    = isset($shortOrder['OrderId']) ? (string)$shortOrder['OrderId'] : '';
            $created    = isset($shortOrder['Created']) ? $shortOrder['Created'] : '';
            $confirmed  = isset($shortOrder['Confirmed']) ? $shortOrder['Confirmed'] : '';
            $amount     = isset($shortOrder['Amount']) ? (float)$shortOrder['Amount'] : 0;
            $posSysName = isset($shortOrder['PosSysName']) ? (string)$shortOrder['PosSysName'] : '';

            $order = array(
                'UID'            => Utils::generateUUID(),
                'INVOICE_NUMBER' => $orderId,
                'INVOICE_DATA'   => $this->formatSmartDate(!empty($confirmed) && strpos($confirmed, '0001') !== 0 ? $confirmed : $created),
                'CLIENT'         => $posSysName,
                'SOURCE_FILE'    => $sourceFile,
                'PARSED_AT'      => date('Y-m-d H:i:s'),
                'PRODUCTS'       => array(),
            );

            // Контакты
            if (isset($shortOrder['ContactPhone']) && !empty($shortOrder['ContactPhone'])) {
                $order['CONT_PHONE'] = (string)$shortOrder['ContactPhone'];
            }
            if (isset($shortOrder['ContactEmails']) && is_array($shortOrder['ContactEmails']) && !empty($shortOrder['ContactEmails'])) {
                $order['CONT_EMAIL'] = (string)$shortOrder['ContactEmails'][0];
            }

            // Позиции заказа
            $orderItems = isset($shortOrder['OrderItems']) ? $shortOrder['OrderItems'] : array();
            if (!empty($orderItems) && !isset($orderItems[0])) {
                $orderItems = array($orderItems);
            }

            foreach ($orderItems as $item) {
                $itemAmount      = isset($item['Amount']) ? (float)$item['Amount'] : 0;
                $itemReservation = isset($item['ReservationNumber']) ? (string)$item['ReservationNumber'] : '';
                $itemOperation   = isset($item['OperationType']) ? $item['OperationType'] : 'Purchase';
                $itemConfirmDt   = isset($item['ConfirmDateTime']) ? $item['ConfirmDateTime'] : '';
                $itemDepartureDt = isset($item['DepartureDateTime']) ? $item['DepartureDateTime'] : '';

                $number = !empty($itemReservation) ? $itemReservation : (isset($item['OrderItemId']) ? (string)$item['OrderItemId'] : '');

                $product = array(
                    'UID'                => Utils::generateUUID(),
                    'PRODUCT_TYPE'       => array('NAME' => 'ЖД-билет', 'CODE' => '000000001'),
                    'NUMBER'             => $number,
                    'ISSUE_DATE'         => $this->formatSmartDate($itemConfirmDt),
                    'RESERVATION_NUMBER' => $itemReservation,
                    'STATUS'             => SmartTravelConstants::mapOperationType($itemOperation),
                    'TICKET_TYPE'        => 'OWN',
                    'PASSENGER_AGE'      => 'ADULT',
                    'TRAVELLER'          => '',
                    'SUPPLIER'           => $this->getSupplierName(),
                    'CARRIER'            => '',
                    'CONJ_COUNT'         => 1,
                    'PENALTY'            => 0,
                    'CURRENCY'           => 'RUB',
                    'COUPONS'            => array(),
                    'TAXES'              => array(
                        array('CODE' => '', 'AMOUNT' => $itemAmount, 'EQUIVALENT_AMOUNT' => $itemAmount, 'VAT_RATE' => 0, 'VAT_AMOUNT' => 0),
                    ),
                    'PAYMENTS'           => array(
                        array('TYPE' => 'INVOICE', 'AMOUNT' => $itemAmount, 'EQUIVALENT_AMOUNT' => $itemAmount, 'RELATED_TICKET_NUMBER' => null),
                    ),
                    'COMMISSIONS'        => $this->buildCommissionsFromItem($item),
                    'BOOKING_AGENT'      => array('CODE' => '', 'NAME' => ''),
                    'AGENT'              => array('CODE' => '', 'NAME' => ''),
                );

                // Купон из DepartureDateTime (если есть)
                if (!empty($itemDepartureDt)) {
                    $product['COUPONS'][] = array(
                        'DEPARTURE_DATETIME' => $this->formatSmartDate($itemDepartureDt),
                    );
                }

                // Блок REFUND для возвратов
                if ($itemOperation === 'Return') {
                    $product['REFUND'] = $this->buildRefundBlock($item, $itemAmount);
                }

                $order['PRODUCTS'][] = $product;
            }

            if (!empty($order['PRODUCTS'])) {
                $result[] = $order;
            }
        }

        if (empty($result)) {
            throw new Exception('Нет обработанных заказов в PULL: ' . $sourceFile);
        }

        return $result;
    }

    // ==========================================================
    // ВСПОМОГАТЕЛЬНЫЕ МЕТОДЫ
    // ==========================================================

    /**
     * Карта клиентов заказа: OrderCustomerId → данные пассажира.
     */
    private function buildCustomersMap($orderCustomers)
    {
        $map = array();
        if (!is_array($orderCustomers)) {
            return $map;
        }
        foreach ($orderCustomers as $customer) {
            $id = isset($customer['OrderCustomerId']) ? $customer['OrderCustomerId'] : 0;
            $map[$id] = $customer;
        }
        return $map;
    }

    /**
     * Карта: OrderItemBlankId → OrderItemCustomer.
     * Связь бланка с пассажиром через OrderItemBlankId.
     */
    private function buildBlankCustomerMap($itemCustomers)
    {
        $map = array();
        if (!is_array($itemCustomers)) {
            return $map;
        }
        foreach ($itemCustomers as $cust) {
            $blankId = isset($cust['OrderItemBlankId']) ? $cust['OrderItemBlankId'] : 0;
            if ($blankId) {
                $map[$blankId] = $cust;
            }
        }
        return $map;
    }

    /**
     * Купоны (маршрут) из OrderItem — станции, даты, поезд, вагон.
     */
    private function buildCouponsFromOrderItem($orderItem)
    {
        $coupon = array();

        if (isset($orderItem['OriginLocationName']) && !empty($orderItem['OriginLocationName'])) {
            $coupon['DEPARTURE_CITY'] = (string)$orderItem['OriginLocationName'];
        }
        if (isset($orderItem['OriginLocationCode']) && !empty($orderItem['OriginLocationCode'])) {
            $coupon['DEPARTURE_AIRPORT'] = (string)$orderItem['OriginLocationCode'];
        }
        if (isset($orderItem['DepartureDateTime']) && !empty($orderItem['DepartureDateTime'])) {
            $coupon['DEPARTURE_DATETIME'] = $this->formatSmartDate($orderItem['DepartureDateTime']);
        }
        if (isset($orderItem['DestinationLocationName']) && !empty($orderItem['DestinationLocationName'])) {
            $coupon['ARRIVAL_CITY'] = (string)$orderItem['DestinationLocationName'];
        }
        if (isset($orderItem['DestinationLocationCode']) && !empty($orderItem['DestinationLocationCode'])) {
            $coupon['ARRIVAL_AIRPORT'] = (string)$orderItem['DestinationLocationCode'];
        }
        if (isset($orderItem['ArrivalDateTime']) && !empty($orderItem['ArrivalDateTime'])) {
            $coupon['ARRIVAL_DATETIME'] = $this->formatSmartDate($orderItem['ArrivalDateTime']);
        }

        // ЖД-специфичные поля
        if (isset($orderItem['TrainNumber']) && !empty($orderItem['TrainNumber'])) {
            $coupon['FLIGHT_NUMBER'] = (string)$orderItem['TrainNumber'];
        }
        if (isset($orderItem['CarNumber']) && !empty($orderItem['CarNumber'])) {
            $coupon['CAR_NUMBER'] = (string)$orderItem['CarNumber'];
        }
        if (isset($orderItem['ServiceClass']) && !empty($orderItem['ServiceClass'])) {
            $coupon['CLASS'] = (string)$orderItem['ServiceClass'];
        }
        if (isset($orderItem['ServiceClassNameRu']) && !empty($orderItem['ServiceClassNameRu'])) {
            $coupon['CLASS_NAME'] = (string)$orderItem['ServiceClassNameRu'];
        }

        if (empty($coupon)) {
            return array();
        }
        return array($coupon);
    }

    /**
     * Таксы из бланка.
     * TAXES[0] (CODE="") = стоимость (тариф), TAXES[1] (CODE="VAT") = НДС
     */
    private function buildTaxesFromBlank($blank, $orderItem)
    {
        $blankAmount = isset($blank['Amount']) ? (float)$blank['Amount'] : 0;
        $vat = isset($orderItem['Vat']) ? (float)$orderItem['Vat'] : 0;

        $taxes = array(
            array(
                'CODE'              => '',
                'AMOUNT'            => $blankAmount,
                'EQUIVALENT_AMOUNT' => $blankAmount,
                'VAT_RATE'          => 0,
                'VAT_AMOUNT'        => 0,
            ),
        );

        // НДС из FiscalData бланка (если есть)
        $blankVat = 0;
        if (isset($blank['FiscalData']) && is_array($blank['FiscalData'])) {
            $fiscalData = $blank['FiscalData'];
            if (!isset($fiscalData[0])) {
                $fiscalData = array($fiscalData);
            }
            foreach ($fiscalData as $fd) {
                if (isset($fd['FiscalLines']) && is_array($fd['FiscalLines'])) {
                    $lines = $fd['FiscalLines'];
                    if (!isset($lines[0])) {
                        $lines = array($lines);
                    }
                    foreach ($lines as $line) {
                        if (isset($line['Vat'])) {
                            $blankVat += (float)$line['Vat'];
                        }
                    }
                }
            }
        }

        $actualVat = $blankVat > 0 ? $blankVat : $vat;
        if ($actualVat > 0) {
            $taxes[] = array(
                'CODE'              => 'VAT',
                'AMOUNT'            => $actualVat,
                'EQUIVALENT_AMOUNT' => $actualVat,
                'VAT_RATE'          => 0,
                'VAT_AMOUNT'        => $actualVat,
            );
        }

        return $taxes;
    }

    /**
     * Комиссии из ClientFeeCalculation и AgentFeeCalculation.
     */
    private function buildCommissionsFromItem($item)
    {
        $commissions = array();

        // Клиентская комиссия (CLIENT)
        $clientFee = isset($item['ClientFeeCalculation']) ? $item['ClientFeeCalculation'] : null;
        if (is_array($clientFee)) {
            $charge = isset($clientFee['Charge']) ? (float)$clientFee['Charge'] : 0;
            if ($charge > 0) {
                $commissions[] = array(
                    'TYPE'   => 'CLIENT',
                    'NAME'   => 'Сбор клиента',
                    'AMOUNT' => $charge,
                    'RATE'   => null,
                );
            }
            $profit = isset($clientFee['Profit']) ? (float)$clientFee['Profit'] : 0;
            if ($profit > 0) {
                $commissions[] = array(
                    'TYPE'   => 'CLIENT',
                    'NAME'   => 'Вознаграждение клиента',
                    'AMOUNT' => $profit,
                    'RATE'   => null,
                );
            }
        }

        // Агентская комиссия (VENDOR)
        $agentFee = isset($item['AgentFeeCalculation']) ? $item['AgentFeeCalculation'] : null;
        if (is_array($agentFee)) {
            $charge = isset($agentFee['Charge']) ? (float)$agentFee['Charge'] : 0;
            if ($charge > 0) {
                $commissions[] = array(
                    'TYPE'   => 'VENDOR',
                    'NAME'   => 'Агентская комиссия',
                    'AMOUNT' => $charge,
                    'RATE'   => null,
                );
            }
        }

        return $commissions;
    }

    /**
     * Блок REFUND для возвратов.
     */
    private function buildRefundBlock($orderItem, $amount)
    {
        $confirmDt = isset($orderItem['ConfirmDateTime']) ? $orderItem['ConfirmDateTime'] : '';

        return array(
            'DATA'             => $this->formatSmartDate($confirmDt),
            'AMOUNT'           => $amount,
            'EQUIVALENT_AMOUNT'=> $amount,
            'FEE_CLIENT'       => 0,
            'FEE_VENDOR'       => 0,
            'PENALTY_CLIENT'   => 0,
            'PENALTY_VENDOR'   => 0,
        );
    }

    /**
     * Конвертация ISO datetime SmartTravel → формат RSTLS (YYYYMMDDHHmmss).
     * "2016-10-27T18:50:04" → "20161027185004"
     * "1976-10-12T00:00:00" → "19761012000000"
     */
    private function formatSmartDate($isoDate)
    {
        if (empty($isoDate)) {
            return '';
        }

        // Убираем миллисекунды, если есть
        $isoDate = preg_replace('/\.\d+$/', '', (string)$isoDate);

        $ts = strtotime($isoDate);
        if ($ts === false || $ts < 0) {
            return '';
        }

        return date('YmdHis', $ts);
    }
}
