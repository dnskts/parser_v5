<?php
/**
 * ============================================================
 * ВСПОМОГАТЕЛЬНЫЕ ФУНКЦИИ ДЛЯ СТРАНИЦЫ ДАННЫХ (data.php) И API data_rows
 * ============================================================
 *
 * Содержит общую логику формирования строк таблицы из JSON-файлов заказов,
 * чтобы её использовали и data.php (серверный рендеринг), и api.php?action=data_rows.
 */

/**
 * Форматирует дату из формата RSTLS (ГГГГММДДччммсс) в вид ДД.ММ.ГГГГ чч:мм.
 *
 * @param string $date
 * @return string
 */
function formatRstlsDate($date)
{
    if (strlen($date) < 12) {
        return $date;
    }
    return substr($date, 6, 2) . '.' . substr($date, 4, 2) . '.' . substr($date, 0, 4) . ' ' . substr($date, 8, 2) . ':' . substr($date, 10, 2);
}

/**
 * Форматирует агента (BOOKING_AGENT / AGENT): убирает дубль CODE === NAME.
 *
 * @param array|string $agent
 * @return string
 */
function formatAgent($agent)
{
    if (is_array($agent) && isset($agent['CODE'])) {
        $code = trim(isset($agent['CODE']) ? $agent['CODE'] : '');
        $name = trim(isset($agent['NAME']) ? $agent['NAME'] : '');
        if ($code !== '' && $code === $name) {
            return $code;
        }
        if ($code !== '' && $name !== '') {
            return $code . ' ' . $name;
        }
        return ($code !== '') ? $code : $name;
    }
    if (is_string($agent)) {
        return $agent;
    }
    return '';
}

/**
 * Строит массив строк таблицы из одного JSON-файла заказа.
 * Один продукт (PRODUCTS[]) = одна строка.
 *
 * @param string $filePath Полный путь к JSON-файлу
 * @return array Массив строк (каждая строка — ассоциативный массив с ключами как в data.php)
 */
function buildRowsFromJsonFile($filePath)
{
    $rows = array();
    $fileName = basename($filePath);
    $content = @file_get_contents($filePath);
    if ($content === false) {
        return $rows;
    }
    $order = json_decode($content, true);
    if (!is_array($order) || !isset($order['PRODUCTS'])) {
        return $rows;
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
        $classesName = '';
        $typeId = '';
        $typeIdName = '';
        $departureDate = '';
        $arrivalDate = '';
        if (!empty($product['COUPONS'])) {
            $fn = array();
            $fb = array();
            $cl = array();
            $clName = array();
            $tid = array();
            $tidName = array();
            $depDates = array();
            $arrDates = array();
            foreach ($product['COUPONS'] as $coupon) {
                if (isset($coupon['FLIGHT_NUMBER'])) $fn[] = $coupon['FLIGHT_NUMBER'];
                if (isset($coupon['FARE_BASIS'])) $fb[] = $coupon['FARE_BASIS'];
                if (isset($coupon['CLASS'])) $cl[] = $coupon['CLASS'];
                if (isset($coupon['CLASS_NAME']) && $coupon['CLASS_NAME'] !== '') $clName[] = $coupon['CLASS_NAME'];
                if (isset($coupon['TYPE_ID']) && $coupon['TYPE_ID'] !== '') $tid[] = $coupon['TYPE_ID'];
                if (isset($coupon['TYPE_ID_NAME']) && $coupon['TYPE_ID_NAME'] !== '') $tidName[] = $coupon['TYPE_ID_NAME'];
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
            $classesName = implode(', ', array_unique($clName));
            $typeId = implode(', ', array_unique($tid));
            $typeIdName = implode(', ', array_unique($tidName));
            $departureDate = implode(', ', $depDates);
            $arrivalDate = implode(', ', $arrDates);
        }

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
                    if (mb_strpos($nameLower, 'сервисный сбор') !== false || mb_strpos($nameLower, 'ервисный сбор') !== false) {
                        $serviceFee = $eqAmt;
                    } elseif (mb_strpos($nameLower, 'сбор поставщика') !== false) {
                        $supplierFee = $eqAmt;
                    }
                }
            }
        }

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
            'file' => $fileName,
            'invoice_num' => isset($order['INVOICE_NUMBER']) ? $order['INVOICE_NUMBER'] : '',
            'invoice_date' => $orderDate,
            'client' => isset($order['CLIENT']) ? $order['CLIENT'] : '',
            'cont_email' => isset($order['CONT_EMAIL']) ? $order['CONT_EMAIL'] : '',
            'cont_phone' => isset($order['CONT_PHONE']) ? $order['CONT_PHONE'] : '',
            'cont_name' => isset($order['CONT_NAME']) ? $order['CONT_NAME'] : '',
            'product_type' => isset($product['PRODUCT_TYPE']['NAME']) ? $product['PRODUCT_TYPE']['NAME'] : '',
            'number' => isset($product['NUMBER']) ? $product['NUMBER'] : '',
            'issue_date' => $issueDate,
            'issue_date_raw' => isset($product['ISSUE_DATE']) ? $product['ISSUE_DATE'] : '',
            'status' => isset($product['STATUS']) ? $product['STATUS'] : '',
            'traveller' => isset($product['TRAVELLER']) ? $product['TRAVELLER'] : '',
            'supplier' => isset($product['SUPPLIER']) ? $product['SUPPLIER'] : '',
            'supplier_code' => isset($product['SUPPLIER_CODE']) ? $product['SUPPLIER_CODE'] : '',
            'carrier' => isset($product['CARRIER']) ? $product['CARRIER'] : '',
            'seg_carriers' => isset($product['SEG_CARRIERS']) ? $product['SEG_CARRIERS'] : '',
            'bag_allowance' => isset($product['BAG_ALLOWANCE']) ? $product['BAG_ALLOWANCE'] : '',
            'route' => $route,
            'discount' => isset($product['DISCOUNT']) ? $product['DISCOUNT'] : '',
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
            'passenger_middle_name' => isset($product['PASSENGER_MIDDLE_NAME']) ? $product['PASSENGER_MIDDLE_NAME'] : '',
            'passenger_doc_country' => isset($product['PASSENGER_DOC_COUNTRY']) ? $product['PASSENGER_DOC_COUNTRY'] : '',
            'passenger_doc_expire' => isset($product['PASSENGER_DOC_EXPIRE']) ? $product['PASSENGER_DOC_EXPIRE'] : '',
            'conj_count' => $conjCount,
            'penalty' => $penalty,
            'flight_numbers' => $flightNumbers,
            'fare_basis' => $fareBasis,
            'classes' => $classes,
            'classes_name' => $classesName,
            'type_id' => $typeId,
            'type_id_name' => $typeIdName,
            'flight_type' => isset($product['FLIGHT_TYPE']) ? $product['FLIGHT_TYPE'] : '',
            'gds_id' => isset($product['GDS_ID']) ? $product['GDS_ID'] : '',
            'gds_name' => isset($product['GDS_NAME']) ? $product['GDS_NAME'] : '',
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

    return $rows;
}
