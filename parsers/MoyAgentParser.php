<?php
/**
 * ============================================================
 * ПАРСЕР ПОСТАВЩИКА "МОЙ АГЕНТ"
 * ============================================================
 *
 * Обработка XML от "Мой агент": авиабилеты + EMD.
 *
 * Конъюнкции (CONJ) — только для авиабилетов, НЕ для EMD.
 * EMD — отдельные продукты в PRODUCTS[], тип "EMD" (код 000000002).
 * Связь с основным билетом через RELATED_TICKET_NUMBER.
 * При возврате основного билета EMD возвращаются автоматически,
 * если у них нет собственного REF-документа.
 * ============================================================
 */

require_once __DIR__ . '/../core/ParserInterface.php';
require_once __DIR__ . '/../core/Utils.php';
require_once __DIR__ . '/constants/MoyAgentConstants.php';

class MoyAgentParser implements ParserInterface
{
    public function getSupplierFolder()
    {
        return 'moyagent';
    }

    public function getSupplierName()
    {
        return 'Мой агент';
    }

    /**
     * Главный метод — парсинг XML-файла.
     */
    public function parse($xmlFilePath)
    {
        // ШАГ 1: Загрузка XML
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
                "Неверный формат: ожидается <order_snapshot>, получен <{$xml->getName()}>"
            );
        }

        // ШАГ 2: Основные данные заказа
        $header = $xml->header;
        if (!$header) {
            throw new Exception("Отсутствует секция <header>");
        }

        $orderId = (string)$header['ord_id'];
        $currency = (string)$header['currency'];
        $orderTime = (string)$header['time'];

        $customer = $xml->customer;
        $clientCode = $customer ? (string)$customer['client_code'] : '';

        // ШАГ 3: Вспомогательные данные
        $passengersMap = $this->buildPassengersMap($xml);
        $travelDocsMap = $this->buildTravelDocsMap($xml);
        $emdDocsMap = $this->buildEmdDocsMap($xml);
        $reservationsMap = $this->buildReservationsMap($xml);
        $conjLinksMap = $this->buildConjLinksMap($xml, $travelDocsMap, $emdDocsMap);
        $mainReservation = $this->getMainReservationFromMap($reservationsMap);

        // ШАГ 4: Тип операции
        $orderAnalysis = $this->analyzeOrderType($xml);
        $isRefund = in_array($orderAnalysis['operation'], array('REF', 'RFND', 'CANX'));

        // Индекс air_ticket_prod
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

        // Множество EMD prod_id
        $emdProdIds = array();
        foreach ($emdDocsMap as $emdProdId => $emdDoc) {
            $emdProdIds[$emdProdId] = true;
        }

        $products = array();

        if ($isRefund) {
            // === ВОЗВРАТ: основной авиабилет ===
            $origProdId = $orderAnalysis['original_prod_id'];
            $refProdId  = $orderAnalysis['refund_prod_id'];
            $refundDoc  = $orderAnalysis['refund_doc'];

            $origAirTicket = isset($airTicketsByProdId[$origProdId])
                ? $airTicketsByProdId[$origProdId] : null;
            $refAirTicket = isset($airTicketsByProdId[$refProdId])
                ? $airTicketsByProdId[$refProdId] : $origAirTicket;

            if ($origAirTicket === null) {
                $origAirTicket = $refAirTicket;
            }
            if ($origAirTicket === null) {
                throw new Exception("Не найден air_ticket_prod для возврата");
            }

            $reservationNumber = $mainReservation ? $mainReservation['rloc'] : '';
            $psgId = $refundDoc ? $refundDoc['psgr_id'] : '';
            $passenger = isset($passengersMap[$psgId]) ? $passengersMap[$psgId] : null;
            $traveller = $passenger ? $passenger['name'] . ' ' . $passenger['first_name'] : '';
            $ticketNumber = $refundDoc ? $refundDoc['tkt_number'] : '';
            $refundDate = $refundDoc ? $this->formatDateTime($refundDoc['tkt_date']) : '';
            $origDoc = isset($travelDocsMap[$origProdId]) ? $travelDocsMap[$origProdId] : null;
            $issueDate = $origDoc ? $this->formatDateTime($origDoc['tkt_date']) : $refundDate;
            $issuingAgentName = $origDoc ? $origDoc['issuingAgent'] : '';
            $bookingAgentName = $mainReservation ? $mainReservation['bookingAgent'] : '';
            $passengerAge = $this->mapPassengerAge((string)$origAirTicket['psg_type']);

            $relatedProdIds = $this->findRelatedProdIds(
                $origProdId, $travelDocsMap, $conjLinksMap, $airTicketsByProdId, $emdProdIds
            );

            $coupons = $this->buildCouponsFromGroup($relatedProdIds, $airTicketsByProdId);
            $taxes = $this->buildTaxesFromGroup($relatedProdIds, $airTicketsByProdId);

            $origFare = (float)(string)$origAirTicket['fare'];
            $origTaxes = (float)(string)$origAirTicket['taxes'];
            $origTotal = $origFare + $origTaxes;

            $payments = array(array(
                'TYPE' => 'INVOICE',
                'AMOUNT' => $origTotal,
                'EQUIVALENT_AMOUNT' => $origTotal,
                'RELATED_TICKET_NUMBER' => null
            ));

            $commissions = $this->buildCommissions($origAirTicket);
            $penalty = $this->extractPenalty($refAirTicket);
            $refFare = (float)(string)$refAirTicket['fare'];
            $refTaxes = (float)(string)$refAirTicket['taxes'];
            $refundAmount = $refFare + $refTaxes - $penalty;

            $passengerDocInfo = $this->buildPassengerDocInfo($passenger);

            $gdsId = $mainReservation && isset($mainReservation['crs']) ? $mainReservation['crs'] : '';
            $flightTypeRaw = $origDoc && isset($origDoc['flight_type_raw']) ? $origDoc['flight_type_raw'] : 'regular';

            $products[] = array(
                'UID' => Utils::generateUUID(),
                'PRODUCT_TYPE' => array('NAME' => 'Авиабилет', 'CODE' => '000000001'),
                'NUMBER' => $ticketNumber,
                'ISSUE_DATE' => $issueDate,
                'RESERVATION_NUMBER' => $reservationNumber,
                'BOOKING_AGENT' => array('CODE' => $bookingAgentName, 'NAME' => $bookingAgentName),
                'AGENT' => array('CODE' => $issuingAgentName, 'NAME' => $issuingAgentName),
                'STATUS' => 'возврат',
                'TICKET_TYPE' => 'OWN',
                'PASSENGER_AGE' => $passengerAge,
                'PASSENGER_BIRTH_DATE' => $passengerDocInfo['birth_date'],
                'PASSENGER_GENDER' => $passengerDocInfo['gender'],
                'PASSENGER_DOC_TYPE' => $passengerDocInfo['doc_type'],
                'PASSENGER_DOC_NUMBER' => $passengerDocInfo['doc_number'],
                'CONJ_COUNT' => 0,
                'PENALTY' => $penalty,
                'CARRIER' => (string)$origAirTicket['validating_carrier'],
                'SUPPLIER' => $this->getSupplierName(),
                'FLIGHT_TYPE' => $this->mapFlightType($flightTypeRaw),
                'GDS_ID' => $gdsId,
                'GDS_NAME' => $this->mapGdsId($gdsId),
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

            // EMD при возврате
            $emdRefundProducts = $this->buildEmdRefundProducts(
                $origProdId, $refundDate, $ticketNumber, $emdDocsMap,
                $airTicketsByProdId, $travelDocsMap, $passengersMap,
                $mainReservation, $origAirTicket, $currency
            );
            foreach ($emdRefundProducts as $ep) {
                $products[] = $ep;
            }

        } else {
            // === ПРОДАЖА: авиабилеты ===
            $childProdIds = array();
            foreach ($conjLinksMap as $childId => $mainId) {
                if (isset($airTicketsByProdId[$childId]) && !isset($emdProdIds[$childId])) {
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

                    if (isset($emdProdIds[$prodId]) || isset($assignedProdIds[$prodId]) || isset($childProdIds[$prodId])) {
                        continue;
                    }

                    $travelDoc = isset($travelDocsMap[$prodId]) ? $travelDocsMap[$prodId] : null;
                    $tktNumber = $travelDoc ? $travelDoc['tkt_number'] : null;

                    $groupKey = ($tktNumber !== null && $tktNumber !== '')
                        ? $tktNumber
                        : '__orphan_' . ($orphanIndex++);

                    if (!isset($ticketGroups[$groupKey])) {
                        $ticketGroups[$groupKey] = array();
                    }
                    $ticketGroups[$groupKey][] = $prodId;
                    $assignedProdIds[$prodId] = true;

                    foreach ($childProdIds as $childId => $mainId) {
                        if ((string)$mainId === (string)$prodId && !isset($assignedProdIds[$childId])) {
                            $ticketGroups[$groupKey][] = $childId;
                            $assignedProdIds[$childId] = true;
                        }
                    }

                    if ($tktNumber !== null && $tktNumber !== '') {
                        foreach ($travelDocsMap as $otherProdId => $otherDoc) {
                            if ($otherProdId !== $prodId
                                && $otherDoc['tkt_number'] === $tktNumber
                                && !isset($assignedProdIds[$otherProdId])
                                && isset($airTicketsByProdId[$otherProdId])
                                && !isset($emdProdIds[$otherProdId])) {
                                $ticketGroups[$groupKey][] = $otherProdId;
                                $assignedProdIds[$otherProdId] = true;
                            }
                        }
                    }
                }
            }

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
                $traveller = $passenger ? $passenger['name'] . ' ' . $passenger['first_name'] : '';
                $passengerAge = $this->mapPassengerAge((string)$airTicket['psg_type']);
                $ticketNumber = $travelDoc ? $travelDoc['tkt_number'] : '';
                $issueDate = $travelDoc ? $this->formatDateTime($travelDoc['tkt_date']) : '';

                $coupons = $this->buildCouponsFromGroup($prodIds, $airTicketsByProdId);
                $taxes = $this->buildTaxesFromGroup($prodIds, $airTicketsByProdId);
                $fare = (float)(string)$airTicket['fare'];
                $taxesAmount = (float)(string)$airTicket['taxes'];
                $totalAmount = $fare + $taxesAmount;

                $payments = array(array(
                    'TYPE' => 'INVOICE',
                    'AMOUNT' => $totalAmount,
                    'EQUIVALENT_AMOUNT' => $totalAmount,
                    'RELATED_TICKET_NUMBER' => null
                ));

                $passengerDocInfo = $this->buildPassengerDocInfo($passenger);

                $gdsId = $mainReservation && isset($mainReservation['crs']) ? $mainReservation['crs'] : '';
                $flightTypeRaw = $travelDoc && isset($travelDoc['flight_type_raw']) ? $travelDoc['flight_type_raw'] : 'regular';

                $products[] = array(
                    'UID' => Utils::generateUUID(),
                    'PRODUCT_TYPE' => array('NAME' => 'Авиабилет', 'CODE' => '000000001'),
                    'NUMBER' => $ticketNumber,
                    'ISSUE_DATE' => $issueDate,
                    'RESERVATION_NUMBER' => $reservationNumber,
                    'BOOKING_AGENT' => array('CODE' => $bookingAgentName, 'NAME' => $bookingAgentName),
                    'AGENT' => array('CODE' => $issuingAgentName, 'NAME' => $issuingAgentName),
                    'STATUS' => 'продажа',
                    'TICKET_TYPE' => 'OWN',
                    'PASSENGER_AGE' => $passengerAge,
                    'PASSENGER_BIRTH_DATE' => $passengerDocInfo['birth_date'],
                    'PASSENGER_GENDER' => $passengerDocInfo['gender'],
                    'PASSENGER_DOC_TYPE' => $passengerDocInfo['doc_type'],
                    'PASSENGER_DOC_NUMBER' => $passengerDocInfo['doc_number'],
                    'CONJ_COUNT' => count($prodIds),
                    'PENALTY' => 0,
                    'CARRIER' => (string)$airTicket['validating_carrier'],
                    'SUPPLIER' => $this->getSupplierName(),
                    'FLIGHT_TYPE' => $this->mapFlightType($flightTypeRaw),
                    'GDS_ID' => $gdsId,
                    'GDS_NAME' => $this->mapGdsId($gdsId),
                    'COUPONS' => $coupons,
                    'TRAVELLER' => $traveller,
                    'TAXES' => $taxes,
                    'CURRENCY' => $currency,
                    'PAYMENTS' => $payments,
                    'COMMISSIONS' => $this->buildCommissions($airTicket)
                );
            }

            // EMD при продаже
            $emdSaleProducts = $this->buildEmdSaleProducts(
                $emdDocsMap, $airTicketsByProdId, $travelDocsMap,
                $passengersMap, $mainReservation, $currency
            );
            foreach ($emdSaleProducts as $ep) {
                $products[] = $ep;
            }
        }

        if (empty($products)) {
            throw new Exception("В файле не найдено ни одного продукта");
        }

        // ШАГ 5: Итоговый ORDER
        return array(
            'UID' => Utils::generateUUID(),
            'INVOICE_NUMBER' => $orderId,
            'INVOICE_DATA' => $this->formatDateTime($orderTime),
            'CLIENT' => $clientCode,
            'SOURCE_FILE' => basename($xmlFilePath),
            'PARSED_AT' => date('Y-m-d H:i:s'),
            'PRODUCTS' => $products
        );
    }

        // =========================================================
    // МЕТОДЫ EMD
    // =========================================================

    /**
     * Карта EMD-документов из emd_ticket_doc.
     * rfic — категория услуги (A/C/D...), rfisc — в air_seg.
     */
    private function buildEmdDocsMap($xml)
    {
        $map = array();
        if (isset($xml->travel_docs->travel_doc)) {
            foreach ($xml->travel_docs->travel_doc as $travelDoc) {
                if (isset($travelDoc->emd_ticket_doc)) {
                    $doc = $travelDoc->emd_ticket_doc;
                    $prodId = (string)$doc['prod_id'];
                    $map[$prodId] = array(
                        'prod_id'      => $prodId,
                        'psgr_id'      => (string)$doc['psgr_id'],
                        'tkt_oper'     => (string)$doc['tkt_oper'],
                        'tkt_number'   => (string)$doc['tkt_number'],
                        'tkt_date'     => (string)$doc['tkt_date'],
                        'main_prod_id' => (string)$doc['main_prod_id'],
                        'rfic'         => (string)$doc['rfic'],
                        'rsrv_id'      => (string)$doc['rsrv_id'],
                        'issuingAgent' => (string)$doc['issuingAgent']
                    );
                }
            }
        }
        return $map;
    }

    /**
     * Извлекает RFISC из air_seg EMD-продукта.
     */
    private function extractEmdRfisc($emdAirTicket)
    {
        if ($emdAirTicket === null) {
            return '';
        }
        if (isset($emdAirTicket->air_seg)) {
            foreach ($emdAirTicket->air_seg as $seg) {
                $rfisc = (string)$seg['rfisc'];
                if ($rfisc !== '') {
                    return $rfisc;
                }
            }
        }
        return '';
    }

    /**
     * Справочник RFISC → название доп.услуги.
     */
    private function resolveEmdName($rfisc, $rfic)
    {
        $rfiscNames = array(
            '0B5' => 'Дополнительный багаж',
            '0C2' => 'Сверхнормативный багаж',
            '0B2' => 'Дополнительный багаж (до 23 кг)',
            '0B3' => 'Дополнительный багаж (до 32 кг)',
            '1AA' => 'Выбор места',
            '050' => 'Бизнес-зал',
            '0BT' => 'Спортивный инвентарь',
            '0BS' => 'Животное в салоне',
            '0GO' => 'Перевозка оружия'
        );

        $rficNames = array(
            'A' => 'Перевозка',
            'B' => 'Наземное обслуживание',
            'C' => 'Багаж',
            'D' => 'Финансы',
            'E' => 'Услуги в аэропорту',
            'F' => 'Товары',
            'G' => 'Бортовое обслуживание'
        );

        // Точное совпадение RFISC
        if ($rfisc !== '' && isset($rfiscNames[$rfisc])) {
            return $rfiscNames[$rfisc];
        }

        // RFIC-категория как fallback
        if ($rfic !== '' && isset($rficNames[$rfic])) {
            return $rficNames[$rfic];
        }

        return 'EMD';
    }

        /**
     * Получает имя агента для EMD.
     * В emd_ticket_doc issuingAgent часто содержит числовой ID (например "1").
     * В этом случае берём имя из air_ticket_doc основного билета.
     */
    private function resolveEmdAgent($emdDoc, $travelDocsMap, $mainReservation)
    {
        $emdAgent = $emdDoc['issuingAgent'];

        // Если не числовой ID — используем как есть
        if ($emdAgent !== '' && !ctype_digit($emdAgent)) {
            return $emdAgent;
        }

        // Берём из air_ticket_doc основного билета
        $mainProdId = $emdDoc['main_prod_id'];
        if (isset($travelDocsMap[$mainProdId])) {
            $mainAgent = $travelDocsMap[$mainProdId]['issuingAgent'];
            if ($mainAgent !== '' && !ctype_digit($mainAgent)) {
                return $mainAgent;
            }
        }

        // Fallback: bookingAgent из reservation
        if ($mainReservation && isset($mainReservation['bookingAgent'])) {
            $bookAgent = $mainReservation['bookingAgent'];
            if ($bookAgent !== '' && !ctype_digit($bookAgent)) {
                return $bookAgent;
            }
        }

        return $emdAgent;
    }

    /**
     * EMD-продукты при ПРОДАЖЕ.
     */
    private function buildEmdSaleProducts(
        $emdDocsMap, $airTicketsByProdId, $travelDocsMap,
        $passengersMap, $mainReservation, $currency
    ) {
        $products = array();

        foreach ($emdDocsMap as $emdProdId => $emdDoc) {
            $emdOper = strtoupper(trim($emdDoc['tkt_oper']));
            if (in_array($emdOper, array('REF', 'RFND', 'CANX'))) {
                continue;
            }

            $mainProdId = $emdDoc['main_prod_id'];
            $emdAirTicket = isset($airTicketsByProdId[$emdProdId]) ? $airTicketsByProdId[$emdProdId] : null;
            $mainAirTicket = isset($airTicketsByProdId[$mainProdId]) ? $airTicketsByProdId[$mainProdId] : null;
            $mainDoc = isset($travelDocsMap[$mainProdId]) ? $travelDocsMap[$mainProdId] : null;
            $relatedTicketNumber = $mainDoc ? $mainDoc['tkt_number'] : '';

            $psgId = $emdDoc['psgr_id'];
            $passenger = isset($passengersMap[$psgId]) ? $passengersMap[$psgId] : null;
            $traveller = $passenger ? $passenger['name'] . ' ' . $passenger['first_name'] : '';
            $passengerDocInfo = $this->buildPassengerDocInfo($passenger);

            $psgType = '';
            if ($emdAirTicket) {
                $psgType = (string)$emdAirTicket['psg_type'];
            }
            if ($psgType === '' && $mainAirTicket) {
                $psgType = (string)$mainAirTicket['psg_type'];
            }

            $carrier = '';
            if ($emdAirTicket && (string)$emdAirTicket['validating_carrier'] !== '') {
                $carrier = (string)$emdAirTicket['validating_carrier'];
            } elseif ($mainAirTicket) {
                $carrier = (string)$mainAirTicket['validating_carrier'];
            }

            $reservationNumber = $mainReservation ? $mainReservation['rloc'] : '';
            $bookingAgentName = $mainReservation ? $mainReservation['bookingAgent'] : '';

            $emdFare = $emdAirTicket ? (float)(string)$emdAirTicket['fare'] : 0;
            $emdTaxes = $emdAirTicket ? (float)(string)$emdAirTicket['taxes'] : 0;
            $emdTotal = $emdFare + $emdTaxes;

            if (trim($emdDoc['tkt_number']) === '' && $emdTotal == 0) {
                continue;
            }

            $taxes = array();
            $taxes[] = array(
                'CODE' => '', 'AMOUNT' => $emdFare,
                'EQUIVALENT_AMOUNT' => $emdFare,
                'VAT_RATE' => 0, 'VAT_AMOUNT' => 0
            );
            if ($emdAirTicket && isset($emdAirTicket->air_seg)) {
                foreach ($emdAirTicket->air_seg as $seg) {
                    if (isset($seg->air_tax)) {
                        foreach ($seg->air_tax as $tax) {
                            $taxes[] = array(
                                'CODE' => (string)$tax['code'],
                                'AMOUNT' => (float)(string)$tax['amount'],
                                'EQUIVALENT_AMOUNT' => (float)(string)$tax['amount'],
                                'VAT_RATE' => 0, 'VAT_AMOUNT' => 0
                            );
                        }
                    }
                }
            }

            $coupons = array();
            if ($emdAirTicket && isset($emdAirTicket->air_seg)) {
                foreach ($emdAirTicket->air_seg as $seg) {
                    $classRaw = (string)$seg['class'];
                    $typeIdRaw = isset($seg['type_id']) ? (string)$seg['type_id'] : '';
                    $statusRaw = isset($seg['status']) ? (string)$seg['status'] : (isset($seg['segment_status']) ? (string)$seg['segment_status'] : '');
                    $coupons[] = array(
                        'FLIGHT_NUMBER'      => (string)$seg['flight_number'],
                        'FARE_BASIS'         => (string)$seg['fare_basis'],
                        'DEPARTURE_AIRPORT'  => (string)$seg['departure_airport'],
                        'DEPARTURE_DATETIME' => $this->formatDateTime((string)$seg['departure_datetime']),
                        'ARRIVAL_AIRPORT'    => (string)$seg['arrival_airport'],
                        'ARRIVAL_DATETIME'   => $this->formatDateTime((string)$seg['arrival_datetime']),
                        'CLASS'              => $classRaw,
                        'CLASS_NAME'         => $this->mapCabinClass($classRaw),
                        'TYPE_ID'            => $typeIdRaw,
                        'TYPE_ID_NAME'       => $typeIdRaw !== '' ? $this->mapTypeId($typeIdRaw) : '',
                        'SEGMENT_STATUS'     => $statusRaw,
                        'SEGMENT_STATUS_NAME'=> $statusRaw !== '' ? $this->mapSegmentStatus($statusRaw) : ''
                    );
                }
            }

            $commissions = $emdAirTicket ? $this->buildCommissions($emdAirTicket) : array();

            // RFISC из air_seg, RFIC из emd_ticket_doc
            $rfisc = $this->extractEmdRfisc($emdAirTicket);
            $rfic = $emdDoc['rfic'];
            $emdName = $this->resolveEmdName($rfisc, $rfic);
            $emdAgent = $this->resolveEmdAgent($emdDoc, $travelDocsMap, $mainReservation);

            $gdsId = $mainReservation && isset($mainReservation['crs']) ? $mainReservation['crs'] : '';
            $flightTypeRaw = $mainDoc && isset($mainDoc['flight_type_raw']) ? $mainDoc['flight_type_raw'] : 'regular';

            $products[] = array(
                'UID' => Utils::generateUUID(),
                'PRODUCT_TYPE' => array('NAME' => 'EMD', 'CODE' => '000000002'),
                'NUMBER' => $emdDoc['tkt_number'],
                'ISSUE_DATE' => $this->formatDateTime($emdDoc['tkt_date']),
                'RESERVATION_NUMBER' => $reservationNumber,
                'BOOKING_AGENT' => array('CODE' => $bookingAgentName, 'NAME' => $bookingAgentName),
                'AGENT' => array('CODE' => $emdAgent, 'NAME' => $emdAgent),
                'STATUS' => 'продажа',
                'TICKET_TYPE' => 'OWN',
                'PASSENGER_AGE' => $this->mapPassengerAge($psgType),
                'PASSENGER_BIRTH_DATE' => $passengerDocInfo['birth_date'],
                'PASSENGER_GENDER' => $passengerDocInfo['gender'],
                'PASSENGER_DOC_TYPE' => $passengerDocInfo['doc_type'],
                'PASSENGER_DOC_NUMBER' => $passengerDocInfo['doc_number'],
                'CONJ_COUNT' => 0,
                'PENALTY' => 0,
                'CARRIER' => $carrier,
                'SUPPLIER' => $this->getSupplierName(),
                'FLIGHT_TYPE' => $this->mapFlightType($flightTypeRaw),
                'GDS_ID' => $gdsId,
                'GDS_NAME' => $this->mapGdsId($gdsId),
                'RELATED_TICKET_NUMBER' => $relatedTicketNumber,
                'EMD_NAME' => $emdName,
                'COUPONS' => $coupons,
                'TRAVELLER' => $traveller,
                'TAXES' => $taxes,
                'CURRENCY' => $currency,
                'PAYMENTS' => array(array(
                    'TYPE' => 'INVOICE', 'AMOUNT' => $emdTotal,
                    'EQUIVALENT_AMOUNT' => $emdTotal,
                    'RELATED_TICKET_NUMBER' => null
                )),
                'COMMISSIONS' => $commissions
            );
        }

        return $products;
    }

    /**
     * EMD-продукты при ВОЗВРАТЕ основного билета.
     */
    private function buildEmdRefundProducts(
        $origProdId, $refundDate, $mainTicketNumber, $emdDocsMap,
        $airTicketsByProdId, $travelDocsMap, $passengersMap,
        $mainReservation, $origAirTicket, $currency
    ) {
        $products = array();

        $emdRefByNumber = array();
        foreach ($emdDocsMap as $epid => $edoc) {
            $op = strtoupper(trim($edoc['tkt_oper']));
            if (in_array($op, array('REF', 'RFND', 'CANX'))) {
                $emdRefByNumber[$edoc['tkt_number']] = $edoc;
            }
        }

        foreach ($emdDocsMap as $emdProdId => $emdDoc) {
            if (strtoupper(trim($emdDoc['tkt_oper'])) !== 'TKT') {
                continue;
            }
            if ((string)$emdDoc['main_prod_id'] !== (string)$origProdId) {
                continue;
            }

            $emdAT = isset($airTicketsByProdId[$emdProdId]) ? $airTicketsByProdId[$emdProdId] : null;
            $mainDoc = isset($travelDocsMap[$origProdId]) ? $travelDocsMap[$origProdId] : null;
            $relatedTkt = $mainDoc ? $mainDoc['tkt_number'] : $mainTicketNumber;

            $psgId = $emdDoc['psgr_id'];
            $psg = isset($passengersMap[$psgId]) ? $passengersMap[$psgId] : null;
            $traveller = $psg ? $psg['name'] . ' ' . $psg['first_name'] : '';
            $passengerDocInfo = $this->buildPassengerDocInfo($psg);

            $psgType = '';
            if ($emdAT) { $psgType = (string)$emdAT['psg_type']; }
            if ($psgType === '' && $origAirTicket) { $psgType = (string)$origAirTicket['psg_type']; }

            $carrier = '';
            if ($emdAT && (string)$emdAT['validating_carrier'] !== '') {
                $carrier = (string)$emdAT['validating_carrier'];
            } elseif ($origAirTicket) {
                $carrier = (string)$origAirTicket['validating_carrier'];
            }

            $resNum = $mainReservation ? $mainReservation['rloc'] : '';
            $bookAgent = $mainReservation ? $mainReservation['bookingAgent'] : '';

            $emdFare = $emdAT ? (float)(string)$emdAT['fare'] : 0;
            $emdTax = $emdAT ? (float)(string)$emdAT['taxes'] : 0;
            $emdTotal = $emdFare + $emdTax;

            if (trim($emdDoc['tkt_number']) === '' && $emdTotal == 0) {
                continue;
            }

            $taxes = array();
            $taxes[] = array('CODE'=>'','AMOUNT'=>$emdFare,'EQUIVALENT_AMOUNT'=>$emdFare,'VAT_RATE'=>0,'VAT_AMOUNT'=>0);
            if ($emdAT && isset($emdAT->air_seg)) {
                foreach ($emdAT->air_seg as $seg) {
                    if (isset($seg->air_tax)) {
                        foreach ($seg->air_tax as $tax) {
                            $taxes[] = array(
                                'CODE'=>(string)$tax['code'],'AMOUNT'=>(float)(string)$tax['amount'],
                                'EQUIVALENT_AMOUNT'=>(float)(string)$tax['amount'],'VAT_RATE'=>0,'VAT_AMOUNT'=>0
                            );
                        }
                    }
                }
            }

            $coupons = array();
            if ($emdAT && isset($emdAT->air_seg)) {
                foreach ($emdAT->air_seg as $seg) {
                    $classRaw = (string)$seg['class'];
                    $typeIdRaw = isset($seg['type_id']) ? (string)$seg['type_id'] : '';
                    $statusRaw = isset($seg['status']) ? (string)$seg['status'] : (isset($seg['segment_status']) ? (string)$seg['segment_status'] : '');
                    $coupons[] = array(
                        'FLIGHT_NUMBER'=>(string)$seg['flight_number'],
                        'FARE_BASIS'=>(string)$seg['fare_basis'],
                        'DEPARTURE_AIRPORT'=>(string)$seg['departure_airport'],
                        'DEPARTURE_DATETIME'=>$this->formatDateTime((string)$seg['departure_datetime']),
                        'ARRIVAL_AIRPORT'=>(string)$seg['arrival_airport'],
                        'ARRIVAL_DATETIME'=>$this->formatDateTime((string)$seg['arrival_datetime']),
                        'CLASS'=>$classRaw,
                        'CLASS_NAME'=>$this->mapCabinClass($classRaw),
                        'TYPE_ID'=>$typeIdRaw,
                        'TYPE_ID_NAME'=>$typeIdRaw !== '' ? $this->mapTypeId($typeIdRaw) : '',
                        'SEGMENT_STATUS'=>$statusRaw,
                        'SEGMENT_STATUS_NAME'=>$statusRaw !== '' ? $this->mapSegmentStatus($statusRaw) : ''
                    );
                }
            }

            $emdTktNum = $emdDoc['tkt_number'];
            $hasOwnRef = isset($emdRefByNumber[$emdTktNum]);

            if ($hasOwnRef) {
                $refDoc = $emdRefByNumber[$emdTktNum];
                $refEmdAT = isset($airTicketsByProdId[$refDoc['prod_id']]) ? $airTicketsByProdId[$refDoc['prod_id']] : null;
                $emdRefDate = $this->formatDateTime($refDoc['tkt_date']);
                $emdPen = $refEmdAT ? $this->extractPenalty($refEmdAT) : 0;
                $rFare = $refEmdAT ? (float)(string)$refEmdAT['fare'] : $emdFare;
                $rTax = $refEmdAT ? (float)(string)$refEmdAT['taxes'] : $emdTax;
                $emdRefAmt = $rFare + $rTax - $emdPen;
            } else {
                $emdRefDate = $refundDate;
                $emdPen = 0;
                $emdRefAmt = $emdTotal;
            }

            $comms = $emdAT ? $this->buildCommissions($emdAT) : array();
            $rfisc = $this->extractEmdRfisc($emdAT);
            $emdName = $this->resolveEmdName($rfisc, $emdDoc['rfic']);
            $emdAgent = $this->resolveEmdAgent($emdDoc, $travelDocsMap, $mainReservation);

            $gdsId = $mainReservation && isset($mainReservation['crs']) ? $mainReservation['crs'] : '';
            $flightTypeRaw = $mainDoc && isset($mainDoc['flight_type_raw']) ? $mainDoc['flight_type_raw'] : 'regular';

            $products[] = array(
                'UID'=>Utils::generateUUID(),
                'PRODUCT_TYPE'=>array('NAME'=>'EMD','CODE'=>'000000002'),
                'NUMBER'=>$emdTktNum,
                'ISSUE_DATE'=>$this->formatDateTime($emdDoc['tkt_date']),
                'RESERVATION_NUMBER'=>$resNum,
                'BOOKING_AGENT'=>array('CODE'=>$bookAgent,'NAME'=>$bookAgent),
                'AGENT'=>array('CODE'=>$emdAgent,'NAME'=>$emdAgent),
                'STATUS'=>'возврат',
                'TICKET_TYPE'=>'OWN',
                'PASSENGER_AGE'=>$this->mapPassengerAge($psgType),
                'PASSENGER_BIRTH_DATE'=>$passengerDocInfo['birth_date'],
                'PASSENGER_GENDER'=>$passengerDocInfo['gender'],
                'PASSENGER_DOC_TYPE'=>$passengerDocInfo['doc_type'],
                'PASSENGER_DOC_NUMBER'=>$passengerDocInfo['doc_number'],
                'CONJ_COUNT'=>0,
                'FLIGHT_TYPE'=>$this->mapFlightType($flightTypeRaw),
                'GDS_ID'=>$gdsId,
                'GDS_NAME'=>$this->mapGdsId($gdsId),
                'PENALTY'=>$emdPen,
                'CARRIER'=>$carrier,
                'SUPPLIER'=>$this->getSupplierName(),
                'RELATED_TICKET_NUMBER'=>$relatedTkt,
                'EMD_NAME'=>$emdName,
                'COUPONS'=>$coupons,
                'TRAVELLER'=>$traveller,
                'TAXES'=>$taxes,
                'CURRENCY'=>$currency,
                'PAYMENTS'=>array(array('TYPE'=>'INVOICE','AMOUNT'=>$emdTotal,'EQUIVALENT_AMOUNT'=>$emdTotal,'RELATED_TICKET_NUMBER'=>null)),
                'COMMISSIONS'=>$comms,
                'REFUND'=>array(
                    'DATA'=>$emdRefDate,'AMOUNT'=>$emdRefAmt,'EQUIVALENT_AMOUNT'=>$emdRefAmt,
                    'FEE_CLIENT'=>0,'FEE_VENDOR'=>0,'PENALTY_CLIENT'=>$emdPen,'PENALTY_VENDOR'=>$emdPen
                )
            );
        }
        return $products;
    }

    // =========================================================
    // ВСПОМОГАТЕЛЬНЫЕ МЕТОДЫ
    // =========================================================

    private function buildPassengersMap($xml)
    {
        $map = array();
        if (isset($xml->passengers->passenger)) {
            foreach ($xml->passengers->passenger as $p) {
                $id = (string)$p['psgr_id'];
                $map[$id] = array('psgr_id'=>$id,'psgr_type'=>(string)$p['psgr_type'],
                    'first_name'=>(string)$p['first_name'],'name'=>(string)$p['name'],
                    'gender'=>(string)$p['gender'],'birth_date'=>(string)$p['birth_date'],
                    'doc_type'=>(string)$p['doc_type'],'doc_number'=>(string)$p['doc_number'],
                    'doc_country'=>(string)$p['doc_country'],'doc_expire'=>(string)$p['doc_expire']);
            }
        }
        return $map;
    }

    private function buildTravelDocsMap($xml)
    {
        $map = array();
        if (isset($xml->travel_docs->travel_doc)) {
            foreach ($xml->travel_docs->travel_doc as $td) {
                if (isset($td->air_ticket_doc)) {
                    $d = $td->air_ticket_doc;
                    $pid = (string)$d['prod_id'];
                    $tktCharter = (string)$d['tkt_charter'];
                    $flightTypeRaw = isset($d['flight_type']) && (string)$d['flight_type'] !== ''
                        ? (string)$d['flight_type']
                        : ($tktCharter === '1' ? 'charter' : 'regular');
                    $map[$pid] = array('prod_id'=>$pid,'psgr_id'=>(string)$d['psgr_id'],
                        'tkt_oper'=>(string)$d['tkt_oper'],'tkt_number'=>(string)$d['tkt_number'],
                        'tkt_date'=>(string)$d['tkt_date'],'rsrv_id'=>(string)$d['rsrv_id'],
                        'issuingAgent'=>(string)$d['issuingAgent'],'flight_type_raw'=>$flightTypeRaw);
                }
            }
        }
        return $map;
    }

    private function buildConjLinksMap($xml, $travelDocsMap, $emdDocsMap)
    {
        $map = array();
        $airTickets = array();
        if (isset($xml->products->product)) {
            foreach ($xml->products->product as $product) {
                if (isset($product->air_ticket_prod)) {
                    $at = $product->air_ticket_prod;
                    $pid = (string)$at['prod_id'];
                    $sc = 0;
                    if (isset($at->air_seg)) { foreach ($at->air_seg as $s) { $sc++; } }
                    $airTickets[$pid] = array('prod_id'=>$pid,'fare'=>(float)(string)$at['fare'],
                        'taxes'=>(float)(string)$at['taxes'],'psg_type'=>(string)$at['psg_type'],'seg_count'=>$sc);
                }
            }
        }

        foreach ($airTickets as $pid => $info) {
            if (isset($emdDocsMap[$pid])) { continue; }
            if ($info['seg_count'] > 0 || $info['fare'] > 0) { continue; }
            $childDoc = isset($travelDocsMap[(string)$pid]) ? $travelDocsMap[(string)$pid] : null;
            if ($childDoc === null) { continue; }
            $ctn = $childDoc['tkt_number'];
            $cpid = $childDoc['psgr_id'];
            if ($ctn === '' || !is_numeric($ctn)) { continue; }

            $bestMain = null; $bestDiff = 999;
            foreach ($airTickets as $cPid => $cInfo) {
                if ((string)$cPid === (string)$pid) { continue; }
                if (isset($emdDocsMap[$cPid]) || isset($map[$cPid])) { continue; }
                if ($cInfo['seg_count'] === 0 || $cInfo['fare'] <= 0) { continue; }
                $cDoc = isset($travelDocsMap[(string)$cPid]) ? $travelDocsMap[(string)$cPid] : null;
                if ($cDoc === null || $cDoc['psgr_id'] !== $cpid) { continue; }
                $cctn = $cDoc['tkt_number'];
                if ($cctn === '' || !is_numeric($cctn)) { continue; }
                $diff = abs((float)$ctn - (float)$cctn);
                if ($diff >= 1 && $diff <= 9 && $diff < $bestDiff) {
                    $bestDiff = $diff; $bestMain = $cPid;
                }
            }
            if ($bestMain !== null) { $map[(string)$pid] = (string)$bestMain; }
        }
        return $map;
    }

    private function buildReservationsMap($xml)
    {
        $map = array();
        if (isset($xml->reservations->reservation)) {
            foreach ($xml->reservations->reservation as $r) {
                $s = (string)$r['supplier'];
                $map[$s] = array('rloc'=>(string)$r['rloc'],'rsrv_id'=>(string)$r['rsrv_id'],
                    'crs'=>(string)$r['crs'],'crs_currency'=>(string)$r['crs_currency'],
                    'supplier'=>$s,'bookingAgent'=>(string)$r['bookingAgent']);
            }
        }
        return $map;
    }

    /**
     * Первая бронь из карты (поставщик → данные).
     * Используется buildReservationsMap(), чтобы не парсить XML повторно.
     */
    private function getMainReservationFromMap($reservationsMap)
    {
        if (empty($reservationsMap)) {
            return null;
        }
        return reset($reservationsMap);
    }

    private function getMainReservation($xml)
    {
        if (isset($xml->reservations->reservation)) {
            foreach ($xml->reservations->reservation as $r) {
                return array('rloc'=>(string)$r['rloc'],'rsrv_id'=>(string)$r['rsrv_id'],
                    'crs'=>(string)$r['crs'],'crs_currency'=>(string)$r['crs_currency'],
                    'supplier'=>(string)$r['supplier'],'bookingAgent'=>(string)$r['bookingAgent']);
            }
        }
        return null;
    }

    private function findRelatedProdIds($mainProdId, $travelDocsMap, $conjLinksMap, $airTicketsByProdId, $emdProdIds)
    {
        $ids = array($mainProdId);
        $seen = array($mainProdId => true);
        $md = isset($travelDocsMap[$mainProdId]) ? $travelDocsMap[$mainProdId] : null;
        $tn = $md ? $md['tkt_number'] : null;

        if ($tn !== null && $tn !== '') {
            foreach ($travelDocsMap as $pid => $doc) {
                if (isset($seen[$pid]) || isset($emdProdIds[$pid])) { continue; }
                if ($doc['tkt_number'] !== $tn) { continue; }
                if (strtoupper(trim($doc['tkt_oper'])) !== 'TKT') { continue; }
                if (isset($airTicketsByProdId[$pid])) { $ids[] = $pid; $seen[$pid] = true; }
            }
        }
        foreach ($conjLinksMap as $cid => $mid) {
            if ((string)$mid === (string)$mainProdId && !isset($seen[$cid]) && !isset($emdProdIds[$cid])) {
                if (isset($airTicketsByProdId[$cid])) { $ids[] = $cid; $seen[$cid] = true; }
            }
        }
        return $ids;
    }

    private function analyzeOrderType($xml)
    {
        $r = array('operation'=>'TKT','refund_doc'=>null,'refund_prod_id'=>null,'original_prod_id'=>null);
        if (!isset($xml->travel_docs->travel_doc)) { return $r; }

        $docs = array();
        foreach ($xml->travel_docs->travel_doc as $td) {
            if (isset($td->air_ticket_doc)) {
                $d = $td->air_ticket_doc;
                $docs[] = array('prod_id'=>(string)$d['prod_id'],'psgr_id'=>(string)$d['psgr_id'],
                    'tkt_oper'=>strtoupper(trim((string)$d['tkt_oper'])),'tkt_number'=>(string)$d['tkt_number'],
                    'tkt_date'=>(string)$d['tkt_date'],'rsrv_id'=>(string)$d['rsrv_id']);
            }
        }
        foreach ($docs as $d) {
            if (in_array($d['tkt_oper'], array('REF','CANX','RFND'))) {
                $r['operation'] = $d['tkt_oper'];
                $r['refund_doc'] = $d;
                $r['refund_prod_id'] = $d['prod_id'];
                $refTktNumber = $d['tkt_number'];
                foreach ($docs as $d2) {
                    if ($d2['tkt_oper'] === 'TKT' && $d2['tkt_number'] === $refTktNumber) {
                        $r['original_prod_id'] = $d2['prod_id'];
                        break;
                    }
                }
                if ($r['original_prod_id'] === null) {
                    $r['original_prod_id'] = $d['prod_id'];
                }
                break;
            }
        }
        return $r;
    }

    private function extractPenalty($airTicket)
    {
        $p = 0;
        if (isset($airTicket->air_seg)) {
            foreach ($airTicket->air_seg as $seg) {
                if (isset($seg->air_tax)) {
                    foreach ($seg->air_tax as $tax) {
                        if (strtoupper((string)$tax['code']) === 'PEN') { $p += (float)(string)$tax['amount']; }
                    }
                }
            }
        }
        return $p;
    }

    private function buildCouponsFromGroup($prodIds, $airTicketsByProdId)
    {
        $c = array(); $seen = array();
        foreach ($prodIds as $pid) {
            if (!isset($airTicketsByProdId[$pid])) { continue; }
            $at = $airTicketsByProdId[$pid];
            if (isset($at->air_seg)) {
                foreach ($at->air_seg as $s) {
                    $k = (string)$s['flight_number'].'|'.(string)$s['departure_airport'].'|'.(string)$s['arrival_airport'].'|'.(string)$s['departure_datetime'];
                    if (isset($seen[$k])) { continue; } $seen[$k] = true;
                    $classRaw = (string)$s['class'];
                    $typeIdRaw = isset($s['type_id']) ? (string)$s['type_id'] : '';
                    $statusRaw = isset($s['status']) ? (string)$s['status'] : (isset($s['segment_status']) ? (string)$s['segment_status'] : '');
                    $c[] = array('FLIGHT_NUMBER'=>(string)$s['flight_number'],'FARE_BASIS'=>(string)$s['fare_basis'],
                        'DEPARTURE_AIRPORT'=>(string)$s['departure_airport'],'DEPARTURE_DATETIME'=>$this->formatDateTime((string)$s['departure_datetime']),
                        'ARRIVAL_AIRPORT'=>(string)$s['arrival_airport'],'ARRIVAL_DATETIME'=>$this->formatDateTime((string)$s['arrival_datetime']),
                        'CLASS'=>$classRaw,'CLASS_NAME'=>$this->mapCabinClass($classRaw),
                        'TYPE_ID'=>$typeIdRaw,'TYPE_ID_NAME'=>$typeIdRaw !== '' ? $this->mapTypeId($typeIdRaw) : '',
                        'SEGMENT_STATUS'=>$statusRaw,'SEGMENT_STATUS_NAME'=>$statusRaw !== '' ? $this->mapSegmentStatus($statusRaw) : '');
                }
            }
        }
        return $c;
    }

    private function buildTaxesFromGroup($prodIds, $airTicketsByProdId)
    {
        $taxes = array();
        $mp = $prodIds[0]; $mf = -1;
        foreach ($prodIds as $pid) {
            if (isset($airTicketsByProdId[$pid])) {
                $f = (float)(string)$airTicketsByProdId[$pid]['fare'];
                if ($f > $mf) { $mf = $f; $mp = $pid; }
            }
        }
        $mat = isset($airTicketsByProdId[$mp]) ? $airTicketsByProdId[$mp] : null;
        $fare = $mat ? (float)(string)$mat['fare'] : 0;
        $taxes[] = array('CODE'=>'','AMOUNT'=>$fare,'EQUIVALENT_AMOUNT'=>$fare,'VAT_RATE'=>0,'VAT_AMOUNT'=>0);

        $seen = array();
        foreach ($prodIds as $pid) {
            if (!isset($airTicketsByProdId[$pid])) { continue; }
            $at = $airTicketsByProdId[$pid];
            if (!isset($at->air_seg)) { continue; }
            foreach ($at->air_seg as $seg) {
                if (!isset($seg->air_tax)) { continue; }
                $fn=(string)$seg['flight_number']; $dd=(string)$seg['departure_datetime'];
                $da=(string)$seg['departure_airport']; $aa=(string)$seg['arrival_airport'];
                foreach ($seg->air_tax as $tax) {
                    $code=(string)$tax['code']; $amt=(float)(string)$tax['amount'];
                    $k=$code.'|'.$amt.'|'.$fn.'|'.$da.'|'.$aa.'|'.$dd;
                    if (isset($seen[$k])) { continue; } $seen[$k]=true;
                    $taxes[]=array('CODE'=>$code,'AMOUNT'=>$amt,'EQUIVALENT_AMOUNT'=>$amt,'VAT_RATE'=>0,'VAT_AMOUNT'=>0);
                }
            }
        }
        return $taxes;
    }

    private function buildCommissions($airTicket)
    {
        $c = array();
        $sf = (float)(string)$airTicket['service_fee'];
        if ($sf > 0) { $c[] = array('TYPE'=>'CLIENT','NAME'=>'Сбор поставщика','AMOUNT'=>$sf,'EQUIVALENT_AMOUNT'=>$sf,'RATE'=>null); }
        $vt = 0;
        if (isset($airTicket->fees->fee)) {
            foreach ($airTicket->fees->fee as $fee) {
                if ((string)$fee['type'] === 'commission') { $vt += (float)(string)$fee['amount']; }
            }
        }
        if ($vt > 0) { $c[] = array('TYPE'=>'VENDOR','NAME'=>'Комиссия поставщика','AMOUNT'=>$vt,'EQUIVALENT_AMOUNT'=>$vt,'RATE'=>null); }
        return $c;
    }

    private function formatDateTime($dateTime)
    {
        if (empty($dateTime)) { return ''; }
        $ts = strtotime($dateTime);
        if ($ts === false) { return preg_replace('/[^0-9]/', '', $dateTime); }
        return date('YmdHis', $ts);
    }

    private function mapTicketStatus($tktOper)
    {
        $m = array('TKT'=>'продажа','REF'=>'возврат','RFND'=>'возврат','CANX'=>'возврат','EXCH'=>'обмен');
        $tktOper = strtoupper(trim($tktOper));
        return isset($m[$tktOper]) ? $m[$tktOper] : 'продажа';
    }

    private function mapPassengerAge($psgType)
    {
        $m = MoyAgentConstants::getPassengerTypes();
        $psgType = strtolower(trim($psgType));
        return isset($m[$psgType]) ? $m[$psgType] : 'ADULT';
    }

    /**
     * Маппинг IATA-кода типа документа в читаемое название.
     */
    private function mapDocType($docTypeCode)
    {
        $m = MoyAgentConstants::getDocTypes();
        return isset($m[$docTypeCode]) ? $m[$docTypeCode] : $docTypeCode;
    }

    /**
     * Маппинг кода пола в читаемое название.
     */
    private function mapGender($genderCode)
    {
        $m = MoyAgentConstants::getGenderMap();
        return isset($m[$genderCode]) ? $m[$genderCode] : $genderCode;
    }

    /**
     * Маппинг класса обслуживания (E,B,F,W,A) в название.
     */
    private function mapCabinClass($code)
    {
        $m = MoyAgentConstants::getCabinClassMap();
        $code = strtoupper(trim($code));
        return isset($m[$code]) ? $m[$code] : $code;
    }

    /**
     * Маппинг type_id (1-6) в название класса перелёта.
     */
    private function mapTypeId($id)
    {
        $m = MoyAgentConstants::getTypeIdMap();
        $id = (string)$id;
        return isset($m[$id]) ? $m[$id] : $id;
    }

    /**
     * Маппинг типа перелёта в читаемое название.
     */
    private function mapFlightType($code)
    {
        $m = MoyAgentConstants::getFlightTypeMap();
        $code = strtolower(trim($code));
        return isset($m[$code]) ? $m[$code] : $code;
    }

    /**
     * Маппинг GDS ID (crs) в название.
     */
    private function mapGdsId($crs)
    {
        $m = MoyAgentConstants::getGdsIdMap();
        $crs = (string)$crs;
        return isset($m[$crs]) ? $m[$crs] : $crs;
    }

    /**
     * Маппинг статуса сегмента в читаемое название.
     */
    private function mapSegmentStatus($code)
    {
        $m = MoyAgentConstants::getSegmentStatusMap();
        $code = strtoupper(trim($code));
        return isset($m[$code]) ? $m[$code] : $code;
    }

    /**
     * Извлечение данных документа пассажира из passengersMap.
     * Возвращает массив с полями birth_date, gender, doc_type, doc_number.
     */
    private function buildPassengerDocInfo($passenger)
    {
        if ($passenger === null) {
            return array('birth_date' => '', 'gender' => '', 'doc_type' => '', 'doc_number' => '');
        }

        $birthDate = '';
        if (isset($passenger['birth_date']) && $passenger['birth_date'] !== '') {
            $ts = strtotime($passenger['birth_date']);
            if ($ts !== false) {
                $birthDate = date('d.m.Y', $ts);
            }
        }

        return array(
            'birth_date' => $birthDate,
            'gender'     => $this->mapGender(isset($passenger['gender']) ? $passenger['gender'] : ''),
            'doc_type'   => $this->mapDocType(isset($passenger['doc_type']) ? $passenger['doc_type'] : ''),
            'doc_number' => isset($passenger['doc_number']) ? $passenger['doc_number'] : ''
        );
    }
}