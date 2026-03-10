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
 * Обработка конъюнкций (CONJ):
 * Один билет может быть разбит на несколько air_ticket_prod с разными
 * prod_id. Связь определяется через:
 * 1. Атрибут main_prod_id в emd_ticket_doc (travel_docs) — явная связь
 * 2. Косвенные признаки: fare=0, нет сегментов, последовательный tkt_number,
 *    тот же пассажир — «скрытая конъюнкция» (V6)
 * Парсер группирует такие продукты, собирает купоны и таксы
 * с дедупликацией, финансы берёт из главного продукта (с максимальным fare).
 * 
 * Для добавления нового поставщика создайте аналогичный файл
 * в папке parsers/ — он будет подхвачен автоматически.
 * ============================================================
 */

require_once __DIR__ . '/../core/ParserInterface.php';
require_once __DIR__ . '/../core/Utils.php';

class MoyAgentParser implements ParserInterface
{
    /**
     * Возвращает имя папки, где лежат XML-файлы этого поставщика.
     */
    public function getSupplierFolder()
    {
        return 'moyagent';
    }

    /**
     * Возвращает человекочитаемое название поставщика.
     */
    public function getSupplierName()
    {
        return 'Мой агент';
    }

    /**
     * Главный метод — парсинг XML-файла "Мой агент".
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
        
        if (!file_exists($xmlFilePath) || !is_readable($xmlFilePath)) {
            throw new Exception("Файл не найден или недоступен: {$xmlFilePath}");
        }

        $xmlContent = file_get_contents($xmlFilePath);
        if ($xmlContent === false) {
            throw new Exception("Не удалось прочитать файл: {$xmlFilePath}");
        }

        $previousErrors = libxml_use_internal_errors(true);
        $xml = simplexml_load_string($xmlContent);

        if ($xml === false) {
            $errors = array();
            foreach (libxml_get_errors() as $error) {
                $errors[] = trim($error->message);
            }
            libxml_clear_errors();
            libxml_use_internal_errors($previousErrors);
            throw new Exception("Ошибка разбора XML: " . implode('; ', $errors));
        }

        libxml_use_internal_errors($previousErrors);

        if ($xml->getName() !== 'order_snapshot') {
            throw new Exception(
                "Неверный формат файла: ожидается корневой тег <order_snapshot>, "
                . "получен <{$xml->getName()}>"
            );
        }

        // -------------------------------------------------------
        // ШАГ 2: Извлечение основных данных заказа
        // -------------------------------------------------------

        $header = $xml->header;
        if (!$header) {
            throw new Exception("В файле отсутствует секция <header>");
        }

        $orderId = (string)$header['ord_id'];
        $currency = (string)$header['currency'];
        $orderTime = (string)$header['time'];

        $customer = $xml->customer;
        $clientCode = $customer ? (string)$customer['client_code'] : '';

        // -------------------------------------------------------
        // ШАГ 3: Подготовка вспомогательных данных
        // -------------------------------------------------------

        $passengersMap = $this->buildPassengersMap($xml);
        $travelDocsMap = $this->buildTravelDocsMap($xml);
        $reservationsMap = $this->buildReservationsMap($xml);
        $conjLinksMap = $this->buildConjLinksMap($xml, $travelDocsMap);

        // Получаем данные первой (основной) reservation для SUPPLIER и PNR
        $mainReservation = $this->getMainReservation($xml);

        // -------------------------------------------------------
        // ШАГ 4: Анализ типа операции и обработка продуктов
        // -------------------------------------------------------

        $orderAnalysis = $this->analyzeOrderType($xml);
        $isRefund = in_array($orderAnalysis['operation'], array('REF', 'RFND', 'CANX'));

        // Индексируем все air_ticket_prod по prod_id
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
            // ВОЗВРАТ или АННУЛЯЦИЯ
            // =============================================================

            $origProdId = $orderAnalysis['original_prod_id'];
            $refProdId  = $orderAnalysis['refund_prod_id'];
            $refundDoc  = $orderAnalysis['refund_doc'];

            $origAirTicket = isset($airTicketsByProdId[$origProdId]) 
                ? $airTicketsByProdId[$origProdId] 
                : null;

            $refAirTicket = isset($airTicketsByProdId[$refProdId]) 
                ? $airTicketsByProdId[$refProdId] 
                : $origAirTicket;

            if ($origAirTicket === null) {
                $origAirTicket = $refAirTicket;
            }

            if ($origAirTicket === null) {
                throw new Exception("Не найден air_ticket_prod для возврата");
            }

            $reservationNumber = $mainReservation ? $mainReservation['rloc'] : '';

            $psgId = $refundDoc ? $refundDoc['psgr_id'] : '';
            $passenger = isset($passengersMap[$psgId]) ? $passengersMap[$psgId] : null;
            $traveller = '';
            if ($passenger) {
                $traveller = $passenger['name'] . ' ' . $passenger['first_name'];
            }

            $ticketNumber = $refundDoc ? $refundDoc['tkt_number'] : '';
            $refundDate = $refundDoc ? $this->formatDateTime($refundDoc['tkt_date']) : '';

            $origDoc = isset($travelDocsMap[$origProdId]) ? $travelDocsMap[$origProdId] : null;
            $issueDate = $origDoc ? $this->formatDateTime($origDoc['tkt_date']) : $refundDate;

            $issuingAgentName = $origDoc ? $origDoc['issuingAgent'] : '';
            $bookingAgentName = $mainReservation ? $mainReservation['bookingAgent'] : '';

            $psgType = (string)$origAirTicket['psg_type'];
            $passengerAge = $this->mapPassengerAge($psgType);

            $relatedProdIds = $this->findRelatedProdIds($origProdId, $travelDocsMap, $conjLinksMap, $airTicketsByProdId);

            $coupons = $this->buildCouponsFromGroup($relatedProdIds, $airTicketsByProdId);
            $taxes = $this->buildTaxesFromGroup($relatedProdIds, $airTicketsByProdId);

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

            $commissions = $this->buildCommissions($origAirTicket);

            $penalty = $this->extractPenalty($refAirTicket);
            $refFare = (float)(string)$refAirTicket['fare'];
            $refTaxes = (float)(string)$refAirTicket['taxes'];
            $refundAmount = $refFare + $refTaxes - $penalty;

            $productData = array(
                'UID' => Utils::generateUUID(),
                'PRODUCT_TYPE' => array(
                    'NAME' => 'Авиабилет',
                    'CODE' => '000000001'
                ),
                'NUMBER' => $ticketNumber,
                'ISSUE_DATE' => $issueDate,
                'RESERVATION_NUMBER' => $reservationNumber,
                'BOOKING_AGENT' => array(
                    'CODE' => $bookingAgentName,
                    'NAME' => $bookingAgentName
                ),
                'AGENT' => array(
                    'CODE' => $issuingAgentName,
                    'NAME' => $issuingAgentName
                ),
                'STATUS' => 'возврат',
                'TICKET_TYPE' => 'OWN',
                'PASSENGER_AGE' => $passengerAge,
                'CONJ_COUNT' => 0,
                'PENALTY' => $penalty,
                'CARRIER' => (string)$origAirTicket['validating_carrier'],
                'SUPPLIER' => $this->getSupplierName(),
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
            // ПРОДАЖА — группируем продукты по билету
            // =============================================================

            // Шаг 1: Группировка (conj + tkt_number)
            $childProdIds = array();
            foreach ($conjLinksMap as $childId => $mainId) {
                if (isset($airTicketsByProdId[$childId])) {
                    $childProdIds[$childId] = $mainId;
                }
            }

            $ticketGroups = array();
            $assignedProdIds = array();
            $orphanIndex = 0;

            if (isset($xml->products->product)) {
                foreach ($xml->products->product as $product) {
                    if (!isset($product->air_ticket_prod)) {
                        continue;
                    }

                    $airTicket = $product->air_ticket_prod;
                    $prodId = (string)$airTicket['prod_id'];

                    if (isset($assignedProdIds[$prodId])) {
                        continue;
                    }

                    if (isset($childProdIds[$prodId])) {
                        continue;
                    }

                    $travelDoc = isset($travelDocsMap[$prodId]) ? $travelDocsMap[$prodId] : null;
                    $tktNumber = $travelDoc ? $travelDoc['tkt_number'] : null;

                    if ($tktNumber !== null && $tktNumber !== '') {
                        $groupKey = $tktNumber;
                    } else {
                        $groupKey = '__orphan_' . $orphanIndex;
                        $orphanIndex++;
                    }

                    if (!isset($ticketGroups[$groupKey])) {
                        $ticketGroups[$groupKey] = array();
                    }
                    $ticketGroups[$groupKey][] = $prodId;
                    $assignedProdIds[$prodId] = true;

                    // Добавляем child-продукты (ИСПРАВЛЕНО V6: приведение типов)
                    foreach ($childProdIds as $childId => $mainId) {
                        if ((string)$mainId === (string)$prodId && !isset($assignedProdIds[$childId])) {
                            $ticketGroups[$groupKey][] = $childId;
                            $assignedProdIds[$childId] = true;
                        }
                    }

                    // Другие prod_id с тем же tkt_number
                    if ($tktNumber !== null && $tktNumber !== '') {
                        foreach ($travelDocsMap as $otherProdId => $otherDoc) {
                            if ($otherProdId !== $prodId 
                                && $otherDoc['tkt_number'] === $tktNumber
                                && !isset($assignedProdIds[$otherProdId])
                                && isset($airTicketsByProdId[$otherProdId])) {
                                $ticketGroups[$groupKey][] = $otherProdId;
                                $assignedProdIds[$otherProdId] = true;
                            }
                        }
                    }
                }
            }

            // Шаг 2: Обработка каждой группы
            foreach ($ticketGroups as $groupKey => $prodIds) {

                $mainProdId = $prodIds[0];
                $maxFare = -1;
                foreach ($prodIds as $pid) {
                    if (isset($airTicketsByProdId[$pid])) {
                        $f = (float)(string)$airTicketsByProdId[$pid]['fare'];
                        if ($f > $maxFare) {
                            $maxFare = $f;
                            $mainProdId = $pid;
                        }
                    }
                }

                $airTicket = isset($airTicketsByProdId[$mainProdId]) ? $airTicketsByProdId[$mainProdId] : null;
                if ($airTicket === null) {
                    continue;
                }

                $travelDoc = isset($travelDocsMap[$mainProdId]) ? $travelDocsMap[$mainProdId] : null;

                $reservationNumber = $mainReservation ? $mainReservation['rloc'] : '';
                $issuingAgentName = $travelDoc ? $travelDoc['issuingAgent'] : '';
                $bookingAgentName = $mainReservation ? $mainReservation['bookingAgent'] : '';

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

                $coupons = $this->buildCouponsFromGroup($prodIds, $airTicketsByProdId);
                $conjCount = count($prodIds);
                $taxes = $this->buildTaxesFromGroup($prodIds, $airTicketsByProdId);

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
                    'UID' => Utils::generateUUID(),
                    'PRODUCT_TYPE' => array(
                        'NAME' => 'Авиабилет',
                        'CODE' => '000000001'
                    ),
                    'NUMBER' => $ticketNumber,
                    'ISSUE_DATE' => $issueDate,
                    'RESERVATION_NUMBER' => $reservationNumber,
                    'BOOKING_AGENT' => array(
                        'CODE' => $bookingAgentName,
                        'NAME' => $bookingAgentName
                    ),
                    'AGENT' => array(
                        'CODE' => $issuingAgentName,
                        'NAME' => $issuingAgentName
                    ),
                    'STATUS' => 'продажа',
                    'TICKET_TYPE' => 'OWN',
                    'PASSENGER_AGE' => $passengerAge,
                    'CONJ_COUNT' => $conjCount,
                    'PENALTY' => 0,
                    'CARRIER' => (string)$airTicket['validating_carrier'],
                    'SUPPLIER' => $this->getSupplierName(),
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

        if (empty($products)) {
            throw new Exception("В файле не найдено ни одного авиабилета (air_ticket_prod)");
        }

        // -------------------------------------------------------
        // ШАГ 5: Формируем итоговый заказ (ORDER)
        // -------------------------------------------------------

        $order = array(
            'UID' => Utils::generateUUID(),
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
     * Собирает карту документов из XML (только air_ticket_doc).
     */
    private function buildTravelDocsMap($xml)
    {
        $map = array();

        if (isset($xml->travel_docs->travel_doc)) {
            foreach ($xml->travel_docs->travel_doc as $travelDoc) {
                if (isset($travelDoc->air_ticket_doc)) {
                    $doc = $travelDoc->air_ticket_doc;
                    $prodId = (string)$doc['prod_id'];
                    $map[$prodId] = array(
                        'prod_id'      => $prodId,
                        'psgr_id'      => (string)$doc['psgr_id'],
                        'tkt_oper'     => (string)$doc['tkt_oper'],
                        'tkt_number'   => (string)$doc['tkt_number'],
                        'tkt_date'     => (string)$doc['tkt_date'],
                        'rsrv_id'      => (string)$doc['rsrv_id'],
                        'issuingAgent' => (string)$doc['issuingAgent']
                    );
                }
            }
        }

        return $map;
    }

    /**
     * Строит карту связей конъюнкций.
     * 
     * Два этапа определения:
     * 
     * Этап 1 (явный): emd_ticket_doc с атрибутом main_prod_id
     * Этап 2 (косвенный, V6): air_ticket_prod без сегментов, fare=0,
     *   тот же пассажир, последовательный номер билета (±1..9)
     * 
     * ВАЖНО: PHP автоматически конвертирует числовые строковые ключи
     * массива в integer ("0" → 0, "2" → 2). Поэтому при записи в $map
     * и при сравнении используется явное приведение (string).
     * 
     * @param SimpleXMLElement $xml
     * @param array $travelDocsMap — карта документов (нужна для этапа 2)
     * @return array — карта [child_prod_id => main_prod_id]
     */
    private function buildConjLinksMap($xml, $travelDocsMap)
    {
        $map = array();

        // -------------------------------------------------------
        // Этап 1: Явные связи через emd_ticket_doc[@main_prod_id]
        // -------------------------------------------------------

        if (isset($xml->travel_docs->travel_doc)) {
            foreach ($xml->travel_docs->travel_doc as $travelDoc) {
                if (isset($travelDoc->emd_ticket_doc)) {
                    $doc = $travelDoc->emd_ticket_doc;
                    $childProdId = (string)$doc['prod_id'];
                    $mainProdId  = (string)$doc['main_prod_id'];

                    if ($mainProdId !== '') {
                        $map[$childProdId] = $mainProdId;
                    }
                }
            }
        }

        // -------------------------------------------------------
        // Этап 2: Скрытые конъюнкции (V6)
        // air_ticket_prod без сегментов, fare=0, последовательный tkt_number
        // -------------------------------------------------------

        $airTickets = array();
        if (isset($xml->products->product)) {
            foreach ($xml->products->product as $product) {
                if (isset($product->air_ticket_prod)) {
                    $at = $product->air_ticket_prod;
                    $pid = (string)$at['prod_id'];

                    $segCount = 0;
                    if (isset($at->air_seg)) {
                        foreach ($at->air_seg as $seg) {
                            $segCount++;
                        }
                    }

                    $airTickets[$pid] = array(
                        'prod_id'   => $pid,
                        'fare'      => (float)(string)$at['fare'],
                        'taxes'     => (float)(string)$at['taxes'],
                        'psg_type'  => (string)$at['psg_type'],
                        'seg_count' => $segCount
                    );
                }
            }
        }

        foreach ($airTickets as $pid => $info) {
            // Уже привязан через emd_ticket_doc
            if (isset($map[$pid])) {
                continue;
            }

            // Не похож на конъюнкцию — есть сегменты или ненулевой fare
            if ($info['seg_count'] > 0 || $info['fare'] > 0) {
                continue;
            }

            $childDoc = isset($travelDocsMap[(string)$pid]) ? $travelDocsMap[(string)$pid] : null;
            if ($childDoc === null) {
                continue;
            }
            $childTktNumber = $childDoc['tkt_number'];
            $childPsgrId = $childDoc['psgr_id'];

            if ($childTktNumber === '' || !is_numeric($childTktNumber)) {
                continue;
            }

            $bestMainPid = null;
            $bestDiff = 999;

            foreach ($airTickets as $candidatePid => $candidateInfo) {
                if ((string)$candidatePid === (string)$pid) {
                    continue;
                }

                if (isset($map[$candidatePid])) {
                    continue;
                }

                if ($candidateInfo['seg_count'] === 0 || $candidateInfo['fare'] <= 0) {
                    continue;
                }

                $candidateDoc = isset($travelDocsMap[(string)$candidatePid]) ? $travelDocsMap[(string)$candidatePid] : null;
                if ($candidateDoc === null) {
                    continue;
                }
                if ($candidateDoc['psgr_id'] !== $childPsgrId) {
                    continue;
                }

                $candidateTktNumber = $candidateDoc['tkt_number'];
                if ($candidateTktNumber === '' || !is_numeric($candidateTktNumber)) {
                    continue;
                }

                $diff = abs((float)$childTktNumber - (float)$candidateTktNumber);
                if ($diff >= 1 && $diff <= 9 && $diff < $bestDiff) {
                    $bestDiff = $diff;
                    $bestMainPid = $candidatePid;
                }
            }

            // ИСПРАВЛЕНО V6: явное приведение к string для ключей массива
            if ($bestMainPid !== null) {
                $map[(string)$pid] = (string)$bestMainPid;
            }
        }

        return $map;
    }

    /**
     * Собирает карту бронирований из XML.
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
                    'supplier'     => $supplier,
                    'bookingAgent' => (string)$reservation['bookingAgent']
                );
            }
        }

        return $map;
    }

    /**
     * Получает основную reservation из XML.
     */
    private function getMainReservation($xml)
    {
        if (isset($xml->reservations->reservation)) {
            foreach ($xml->reservations->reservation as $reservation) {
                return array(
                    'rloc'         => (string)$reservation['rloc'],
                    'rsrv_id'      => (string)$reservation['rsrv_id'],
                    'crs'          => (string)$reservation['crs'],
                    'crs_currency' => (string)$reservation['crs_currency'],
                    'supplier'     => (string)$reservation['supplier'],
                    'bookingAgent' => (string)$reservation['bookingAgent']
                );
            }
        }

        return null;
    }

    /**
     * Находит все связанные prod_id для одного билета (для возвратов).
     */
    private function findRelatedProdIds($mainProdId, $travelDocsMap, $conjLinksMap, $airTicketsByProdId)
    {
        $prodIds = array($mainProdId);
        $seen = array($mainProdId => true);

        $mainDoc = isset($travelDocsMap[$mainProdId]) ? $travelDocsMap[$mainProdId] : null;
        $tktNumber = $mainDoc ? $mainDoc['tkt_number'] : null;

        if ($tktNumber !== null && $tktNumber !== '') {
            foreach ($travelDocsMap as $prodId => $doc) {
                if (isset($seen[$prodId])) {
                    continue;
                }
                if ($doc['tkt_number'] !== $tktNumber) {
                    continue;
                }
                $oper = strtoupper(trim($doc['tkt_oper']));
                if ($oper !== 'TKT') {
                    continue;
                }
                if (isset($airTicketsByProdId[$prodId])) {
                    $prodIds[] = $prodId;
                    $seen[$prodId] = true;
                }
            }
        }

        // По conjLinksMap (ИСПРАВЛЕНО V6: приведение типов)
        foreach ($conjLinksMap as $childId => $linkedMainId) {
            if ((string)$linkedMainId === (string)$mainProdId && !isset($seen[$childId])) {
                if (isset($airTicketsByProdId[$childId])) {
                    $prodIds[] = $childId;
                    $seen[$childId] = true;
                }
            }
        }

        return $prodIds;
    }

    /**
     * Анализирует все travel_doc и определяет тип операции заказа.
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

        foreach ($allDocs as $doc) {
            if ($doc['tkt_oper'] === 'REF' || $doc['tkt_oper'] === 'CANX' || $doc['tkt_oper'] === 'RFND') {
                $result['operation'] = $doc['tkt_oper'];
                $result['refund_doc'] = $doc;
                $result['refund_prod_id'] = $doc['prod_id'];
                break;
            }
        }

        foreach ($allDocs as $doc) {
            if ($doc['tkt_oper'] === 'TKT') {
                $result['original_prod_id'] = $doc['prod_id'];
                break;
            }
        }

        if ($result['refund_prod_id'] !== null && $result['original_prod_id'] === null) {
            $result['original_prod_id'] = $result['refund_prod_id'];
        }

        return $result;
    }

    /**
     * Извлекает сумму штрафа (PENALTY) из air_ticket_prod.
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
     * Собирает УНИКАЛЬНЫЕ купоны из группы продуктов.
     */
    private function buildCouponsFromGroup($prodIds, $airTicketsByProdId)
    {
        $coupons = array();
        $seen = array();

        foreach ($prodIds as $prodId) {
            if (!isset($airTicketsByProdId[$prodId])) {
                continue;
            }
            $airTicket = $airTicketsByProdId[$prodId];
            if (isset($airTicket->air_seg)) {
                foreach ($airTicket->air_seg as $seg) {
                    $flightNumber = (string)$seg['flight_number'];
                    $depDatetime  = (string)$seg['departure_datetime'];
                    $depAirport   = (string)$seg['departure_airport'];
                    $arrAirport   = (string)$seg['arrival_airport'];

                    $dedupeKey = $flightNumber . '|' . $depAirport . '|' . $arrAirport . '|' . $depDatetime;

                    if (isset($seen[$dedupeKey])) {
                        continue;
                    }
                    $seen[$dedupeKey] = true;

                    $coupons[] = array(
                        'FLIGHT_NUMBER'      => $flightNumber,
                        'FARE_BASIS'         => (string)$seg['fare_basis'],
                        'DEPARTURE_AIRPORT'  => $depAirport,
                        'DEPARTURE_DATETIME' => $this->formatDateTime($depDatetime),
                        'ARRIVAL_AIRPORT'    => $arrAirport,
                        'ARRIVAL_DATETIME'   => $this->formatDateTime((string)$seg['arrival_datetime']),
                        'CLASS'              => (string)$seg['class']
                    );
                }
            }
        }

        return $coupons;
    }

    /**
     * Собирает таксы из группы продуктов с дедупликацией.
     */
    private function buildTaxesFromGroup($prodIds, $airTicketsByProdId)
    {
        $taxes = array();

        $mainProdId = $prodIds[0];
        $maxFare = -1;
        foreach ($prodIds as $pid) {
            if (isset($airTicketsByProdId[$pid])) {
                $f = (float)(string)$airTicketsByProdId[$pid]['fare'];
                if ($f > $maxFare) {
                    $maxFare = $f;
                    $mainProdId = $pid;
                }
            }
        }

        $mainAirTicket = isset($airTicketsByProdId[$mainProdId]) ? $airTicketsByProdId[$mainProdId] : null;
        $fare = $mainAirTicket ? (float)(string)$mainAirTicket['fare'] : 0;

        $taxes[] = array(
            'CODE'              => '',
            'AMOUNT'            => $fare,
            'EQUIVALENT_AMOUNT' => $fare,
            'VAT_RATE'          => 0,
            'VAT_AMOUNT'        => 0
        );

        $seen = array();

        foreach ($prodIds as $prodId) {
            if (!isset($airTicketsByProdId[$prodId])) {
                continue;
            }
            $airTicket = $airTicketsByProdId[$prodId];

            if (!isset($airTicket->air_seg)) {
                continue;
            }

            foreach ($airTicket->air_seg as $seg) {
                $flightNumber = (string)$seg['flight_number'];
                $depDatetime  = (string)$seg['departure_datetime'];
                $depAirport   = (string)$seg['departure_airport'];
                $arrAirport   = (string)$seg['arrival_airport'];

                if (!isset($seg->air_tax)) {
                    continue;
                }

                foreach ($seg->air_tax as $tax) {
                    $code   = (string)$tax['code'];
                    $amount = (float)(string)$tax['amount'];

                    $dedupeKey = $code . '|' . $amount . '|' . $flightNumber 
                               . '|' . $depAirport . '|' . $arrAirport . '|' . $depDatetime;

                    if (isset($seen[$dedupeKey])) {
                        continue;
                    }
                    $seen[$dedupeKey] = true;

                    $taxes[] = array(
                        'CODE'              => $code,
                        'AMOUNT'            => $amount,
                        'EQUIVALENT_AMOUNT' => $amount,
                        'VAT_RATE'          => 0,
                        'VAT_AMOUNT'        => 0
                    );
                }
            }
        }

        return $taxes;
    }

    /**
     * Формирует массив комиссий из данных билета.
     */
    private function buildCommissions($airTicket)
    {
        $commissions = array();

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
     * Преобразует дату: "2025-10-13 12:16:00" → "20251013121600"
     */
    private function formatDateTime($dateTime)
    {
        if (empty($dateTime)) {
            return '';
        }

        $timestamp = strtotime($dateTime);

        if ($timestamp === false) {
            return preg_replace('/[^0-9]/', '', $dateTime);
        }

        return date('YmdHis', $timestamp);
    }

    /**
     * Преобразует тип операции в статус ORDER.
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
     * Преобразует тип пассажира: adt→ADULT, chd→CHILD, inf→INFANT
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
}