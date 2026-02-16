<?php
/**
 * ============================================================
 * ДЕМО-ПАРСЕР ПОСТАВЩИКА ОТЕЛЕЙ
 * ============================================================
 * 
 * Этот файл — пример того, как добавить нового поставщика.
 * Он показывает минимальную структуру парсера для обработки
 * XML-файлов с заказами на отели.
 * 
 * ЧТО НУЖНО СДЕЛАТЬ, ЧТОБЫ ДОБАВИТЬ НОВОГО ПОСТАВЩИКА:
 * 
 * 1. Скопируйте этот файл в папку parsers/ и переименуйте
 *    (например, AmadeusParser.php)
 * 
 * 2. Переименуйте класс внутри файла (например, AmadeusParser)
 * 
 * 3. Измените метод getSupplierFolder() — укажите имя папки,
 *    откуда будут браться XML-файлы (например, "amadeus")
 * 
 * 4. Измените метод getSupplierName() — укажите название поставщика
 * 
 * 5. Реализуйте метод parse() — напишите логику преобразования
 *    XML в формат ORDER (по образцу MoyAgentParser.php)
 * 
 * 6. Создайте папку input/имя_поставщика/ с подпапками
 *    Processed/ и Error/
 * 
 * Всё! Система автоматически обнаружит новый парсер при следующем
 * запуске и начнёт обрабатывать файлы из его папки.
 * ============================================================
 */

require_once __DIR__ . '/../core/ParserInterface.php';

class DemoHotelParser implements ParserInterface
{
    /**
     * Имя папки для XML-файлов этого поставщика.
     * Файлы будут искаться в input/demo_hotel/
     */
    public function getSupplierFolder()
    {
        return 'demo_hotel';
    }

    /**
     * Название поставщика для отображения в логах.
     */
    public function getSupplierName()
    {
        return 'Демо-отели';
    }

    /**
     * Парсинг XML-файла с заказом на отель.
     * 
     * Это демо-версия парсера, обрабатывающая простой XML вида:
     * <hotel_order>
     *   <order id="..." date="..." client="..."/>
     *   <booking hotel="..." room="..." checkin="..." checkout="..."
     *           guests="..." rate="..." total="..." currency="..."
     *           traveller="..." supplier="..." status="..."/>
     * </hotel_order>
     * 
     * @param string $xmlFilePath — путь к XML-файлу
     * @return array — данные заказа в формате ORDER
     * @throws Exception — если файл не удалось обработать
     */
    public function parse($xmlFilePath)
    {
        // Проверяем существование файла
        if (!file_exists($xmlFilePath) || !is_readable($xmlFilePath)) {
            throw new Exception("Файл не найден или недоступен: {$xmlFilePath}");
        }

        // Загружаем XML
        $previousErrors = libxml_use_internal_errors(true);
        $xml = simplexml_load_file($xmlFilePath);

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

        // Проверяем корневой тег
        if ($xml->getName() !== 'hotel_order') {
            throw new Exception(
                "Неверный формат: ожидается <hotel_order>, получен <{$xml->getName()}>"
            );
        }

        // Извлекаем данные заказа
        $order = $xml->order;
        if (!$order) {
            throw new Exception("В файле отсутствует секция <order>");
        }

        $orderId = (string)$order['id'];
        $orderDate = (string)$order['date'];
        $clientId = (string)$order['client'];

        // Обрабатываем бронирования отелей
        $products = array();

        if (isset($xml->booking)) {
            foreach ($xml->booking as $booking) {
                $rate = (float)(string)$booking['rate'];
                $total = (float)(string)$booking['total'];
                $currency = (string)$booking['currency'];

                $products[] = array(
                    'UID' => $this->generateUUID(),
                    'PRODUCT_TYPE' => array(
                        'NAME' => 'Отельный билет',
                        'CODE' => '000000003'
                    ),
                    'NUMBER' => $orderId,
                    'ISSUE_DATE' => $this->formatDate($orderDate),
                    'RESERVATION_NUMBER' => $orderId,
                    'BOOKING_AGENT' => array('CODE' => '', 'NAME' => ''),
                    'AGENT' => array('CODE' => '', 'NAME' => ''),
                    'STATUS' => (string)$booking['status'],
                    'PENALTY' => 0,
                    'SUPPLIER' => (string)$booking['supplier'],
                    'HOTEL' => (string)$booking['hotel'],
                    'ROOMS' => array(
                        array(
                            'ROOM_SIZE' => (string)$booking['room'],
                            'NUMBER_OF_PEOPLE' => (int)(string)$booking['guests'],
                            'CHECK_IN_DATE' => $this->formatDate((string)$booking['checkin']),
                            'CHECK_OUT_DATE' => $this->formatDate((string)$booking['checkout']),
                            'EQUIVALENT_RATE' => $rate,
                            'EQUIVALENT_AMOUNT' => $total,
                            'AMOUNT' => $total
                        )
                    ),
                    'TRAVELLER' => (string)$booking['traveller'],
                    'TAXES' => array(),
                    'CURRENCY' => $currency,
                    'PAYMENTS' => array(
                        array(
                            'TYPE' => 'INVOICE',
                            'AMOUNT' => $total,
                            'EQUIVALENT_AMOUNT' => $total,
                            'RELATED_TICKET_NUMBER' => null
                        )
                    ),
                    'COMMISSIONS' => array()
                );
            }
        }

        if (empty($products)) {
            throw new Exception("В файле не найдено ни одного бронирования отеля");
        }

        return array(
            'UID' => $this->generateUUID(),
            'INVOICE_NUMBER' => $orderId,
            'INVOICE_DATA' => $this->formatDate($orderDate),
            'CLIENT' => $clientId,
            'PRODUCTS' => $products
        );
    }

    /**
     * Преобразует дату в формат ГГГГММДДччммсс.
     */
    private function formatDate($date)
    {
        if (empty($date)) {
            return '';
        }
        $timestamp = strtotime($date);
        if ($timestamp === false) {
            return preg_replace('/[^0-9]/', '', $date);
        }
        return date('YmdHis', $timestamp);
    }

    /**
     * Генерирует UUID v4.
     */
    private function generateUUID()
    {
        if (function_exists('random_bytes')) {
            $data = random_bytes(16);
        } else {
            $data = openssl_random_pseudo_bytes(16);
        }
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}
