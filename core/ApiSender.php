<?php
/**
 * ============================================================
 * ОТПРАВКА ЗАКАЗОВ В API 1С
 * ============================================================
 *
 * Класс отвечает за отправку обработанных заказов (в формате JSON)
 * во внешнюю систему 1С:Предприятие по протоколу HTTP.
 *
 * Что делает класс:
 * - Проверяет доступность API (метод isAvailable)
 * - Отправляет данные заказа методом POST с базовой авторизацией
 * - Удаляет служебные поля (SOURCE_FILE, PARSED_AT) перед отправкой
 * - Сворачивает справочники (CLIENT, SUPPLIER, CARRIER, CURRENCY, аэропорты)
 *   в плоский UID-строку — формат, который принимает HTTP-сервис 1С
 * - Записывает каждую попытку отправки в лог (JSON Lines)
 * - Возвращает понятные сообщения об ошибках (сеть, таймаут, HTTP-код)
 *
 * Настройки берутся из config/settings.json, секция "api".
 * ============================================================
 */

require_once __DIR__ . '/Utils.php';

class ApiSender
{
    /** Код таксы «тариф» в 1С: парсеры отдают эту строку с пустым CODE */
    const FARE_TAX_CODE = 'Тариф';

    /** @var array Настройки API: url, login, password, timeout, enabled */
    private $config;

    /** @var string Путь к файлу лога отправок (logs/api_send.log) */
    private $logFile;

    /** @var bool Права на файл лога уже проверялись в этом запросе */
    private $logOwnershipChecked = false;

    /** @var string|null Папка json_api/ для payload-копий (null — не сохранять) */
    private $apiJsonDir;

    /**
     * Создание отправителя.
     *
     * @param array  $apiConfig  — настройки из settings.json (api)
     * @param string $logFile    — путь к файлу лога (JSON Lines)
     * @param string|null $apiJsonDir — папка json_api/ для копий payload
     */
    public function __construct($apiConfig, $logFile, $apiJsonDir = null)
    {
        $this->config = $apiConfig;
        $this->logFile = $logFile;
        $logDir = dirname($logFile);
        Utils::ensureDirectory($logDir);

        $this->apiJsonDir = $apiJsonDir;
        if ($this->apiJsonDir !== null) {
            Utils::ensureDirectory($this->apiJsonDir);
        }
    }

    /**
     * Собирает payload для 1С и сохраняет его в json_api/ под тем же именем,
     * что и ORDER в json/. Файл нужен, чтобы видеть и править в браузере
     * ровно то, что уходит в 1С.
     *
     * @param array  $orderData    — ORDER после enrich()
     * @param string $jsonFileName — имя файла (как в json/)
     * @return array|null — payload или null, если папка не задана / запись не удалась
     */
    public function writeApiPayload($orderData, $jsonFileName)
    {
        if ($this->apiJsonDir === null) {
            return null;
        }

        $payload = $this->prepareForApi($orderData);
        $content = json_encode(
            $payload,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );

        if ($content === false) {
            return null;
        }

        $filePath = $this->apiJsonDir . DIRECTORY_SEPARATOR . $jsonFileName;
        if (file_put_contents($filePath, $content, LOCK_EX) === false) {
            return null;
        }

        Utils::ensureOwnership($filePath);

        return $payload;
    }

    /**
     * Проверяет, доступен ли сервер API 1С.
     * Выполняет лёгкий запрос (HEAD), не отправляя данные.
     * Результат можно использовать, чтобы не слать заказы при недоступном API.
     *
     * @return bool true, если API включён в настройках и сервер отвечает
     */
    public function isAvailable()
    {
        $enabled = isset($this->config['enabled']) ? (bool)$this->config['enabled'] : false;
        $url = isset($this->config['url']) ? $this->config['url'] : '';
    
        if (!$enabled || empty($url)) {
            return false;
        }
    
        $ch = curl_init();
        curl_setopt_array($ch, array(
            CURLOPT_URL            => rtrim($url, '/'),
            CURLOPT_NOBODY         => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 2,
            CURLOPT_TIMEOUT        => 3,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false
        ));
        curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
    
        return $httpCode > 0;
    }
    /**
     * Отправляет JSON заказа в API 1С.
     * Перед отправкой удаляет служебные поля SOURCE_FILE и PARSED_AT.
     *
     * @param array  $orderData    — массив ORDER
     * @param string $jsonFileName — имя JSON-файла (для лога)
     * @param string $sourceXml    — имя исходного XML (для лога)
     * @return array — ['success' => bool, 'message' => string, 'http_code' => int|null]
     */
    public function send($orderData, $jsonFileName, $sourceXml = '')
    {
        $enabled = isset($this->config['enabled']) ? (bool)$this->config['enabled'] : false;

        if (!$enabled) {
            $msg = "Отправка в API отключена в настройках (api.enabled = false). JSON сохранён только в файл.";
            $this->writeLog('SKIP', $jsonFileName, $sourceXml, null, null, $msg);
            return array('success' => true, 'message' => $msg, 'http_code' => null);
        }

        $url = isset($this->config['url']) ? $this->config['url'] : '';
        if (empty($url)) {
            $msg = "URL API не задан в настройках (api.url). Отправка невозможна.";
            $this->writeLog('ERROR', $jsonFileName, $sourceXml, null, null, $msg);
            return array('success' => false, 'message' => $msg, 'http_code' => null);
        }

        $login = isset($this->config['login']) ? $this->config['login'] : '';
        $password = isset($this->config['password']) ? $this->config['password'] : '';
        if (empty($login) || empty($password)) {
            $msg = "Логин или пароль API не заданы в настройках (api.login, api.password).";
            $this->writeLog('ERROR', $jsonFileName, $sourceXml, null, null, $msg);
            return array('success' => false, 'message' => $msg, 'http_code' => null);
        }

        // В json/ хранится полный ORDER с объектами {UID,CODE,NAME} для таблицы.
        // В 1С уходят только UID (как в прежнем *_export.json).
        $sendData = $this->prepareForApi($orderData);

        $jsonBody = json_encode($sendData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($jsonBody === false) {
            $msg = "Ошибка кодирования JSON: " . json_last_error_msg();
            $this->writeLog('ERROR', $jsonFileName, $sourceXml, null, null, $msg);
            return array('success' => false, 'message' => $msg, 'http_code' => null);
        }

        $invoiceNumber = isset($orderData['INVOICE_NUMBER']) ? $orderData['INVOICE_NUMBER'] : '?';
        $this->writeLog('SEND', $jsonFileName, $sourceXml, null, null,
            "Отправка заказа #{$invoiceNumber} на {$url} (" . strlen($jsonBody) . " байт)");

        $retryAttempts = isset($this->config['retry_attempts']) ? (int)$this->config['retry_attempts'] : 0;
        $retryDelaySec = isset($this->config['retry_delay_sec']) ? (int)$this->config['retry_delay_sec'] : 2;
        $maxTries = ($retryAttempts > 0) ? ($retryAttempts + 1) : 1;

        $lastResult = null;
        $lastExplanation = '';

        for ($try = 1; $try <= $maxTries; $try++) {
            if ($try > 1) {
                $this->writeLog('SEND', $jsonFileName, $sourceXml, null, null,
                    "Попытка {$try} из {$maxTries}");
                sleep($retryDelaySec);
            }

            $result = $this->httpPost($url, $jsonBody, $login, $password);
            $lastResult = $result;

            $httpCode = $result['http_code'];
            $response = $result['response'];
            $error = $result['error'];
            $errno = $result['errno'];

            if ($errno !== 0) {
                $lastExplanation = $this->explainCurlError($errno, $error, $url);
                $isRetryable = ($errno === 28);
                if (!$isRetryable || $try >= $maxTries) {
                    $this->writeLog('ERROR', $jsonFileName, $sourceXml, $httpCode, $response, $lastExplanation);
                    return array('success' => false, 'message' => $lastExplanation, 'http_code' => $httpCode);
                }
                continue;
            }

            if ($httpCode >= 200 && $httpCode < 300) {
                $explanation = "Успешно отправлено (HTTP {$httpCode}). Ответ: " . $this->truncate($response, 500);
                $this->writeLog('OK', $jsonFileName, $sourceXml, $httpCode, $response, $explanation);
                return array('success' => true, 'message' => $explanation, 'http_code' => $httpCode);
            }

            if ($httpCode >= 400 && $httpCode < 500) {
                $explanation = $this->explanationForHttpCode($httpCode, $url, $response);
                $this->writeLog('ERROR', $jsonFileName, $sourceXml, $httpCode, $response, $explanation);
                return array('success' => false, 'message' => $explanation, 'http_code' => $httpCode);
            }

            $lastExplanation = $this->explanationForHttpCode($httpCode, $url, $response);
            $isRetryable = ($httpCode >= 500 || $httpCode === 0);
            if (!$isRetryable || $try >= $maxTries) {
                $this->writeLog('ERROR', $jsonFileName, $sourceXml, $httpCode, $response, $lastExplanation);
                return array('success' => false, 'message' => $lastExplanation, 'http_code' => $httpCode);
            }
        }

        $this->writeLog('ERROR', $jsonFileName, $sourceXml,
            isset($lastResult['http_code']) ? $lastResult['http_code'] : null,
            isset($lastResult['response']) ? $lastResult['response'] : null,
            $lastExplanation);
        return array('success' => false, 'message' => $lastExplanation, 'http_code' => isset($lastResult['http_code']) ? $lastResult['http_code'] : null);
    }

    /**
     * Готовит ORDER к отправке в 1С: убирает служебные поля и сворачивает
     * справочники в плоский UID (строка), как в прежнем формате *_export.json.
     *
     * В файлах json/ объекты {UID,CODE,NAME} сохраняются — они нужны таблице
     * data.php. В HTTP-тело уходит только UID.
     *
     * @param array $orderData — ORDER после enrich()
     * @return array — копия заказа для POST
     */
    private function prepareForApi($orderData)
    {
        $data = $orderData;
        unset($data['SOURCE_FILE']);
        unset($data['PARSED_AT']);

        // CLIENT после enrich() — null; если пришёл объект справочника,
        // берём UID. Пустое значение отправляем как null: ТЗ его допускает.
        if (isset($data['CLIENT'])) {
            $uid = $this->flattenRefToUid($data['CLIENT']);
            $data['CLIENT'] = $uid !== '' ? $uid : null;
        } else {
            $data['CLIENT'] = null;
        }

        if (!isset($data['PRODUCTS']) || !is_array($data['PRODUCTS'])) {
            return $data;
        }

        foreach ($data['PRODUCTS'] as $pIdx => $product) {
            // 1С ждёт номер билета без трёхзначного кода авиакомпании
            if (isset($product['NUMBER'])) {
                $data['PRODUCTS'][$pIdx]['NUMBER'] = $this->stripTicketPrefix($product['NUMBER']);
            }
            if (isset($product['RELATED_TICKET_NUMBER'])) {
                $data['PRODUCTS'][$pIdx]['RELATED_TICKET_NUMBER'] = $this->stripTicketPrefix(
                    $product['RELATED_TICKET_NUMBER']
                );
            }
            if (isset($product['PAYMENTS']) && is_array($product['PAYMENTS'])) {
                foreach ($product['PAYMENTS'] as $payIdx => $payment) {
                    if (isset($payment['RELATED_TICKET_NUMBER'])) {
                        $data['PRODUCTS'][$pIdx]['PAYMENTS'][$payIdx]['RELATED_TICKET_NUMBER'] =
                            $this->stripTicketPrefix($payment['RELATED_TICKET_NUMBER']);
                    }
                }
            }

            // Тариф парсеры отдают таксой с пустым кодом, в 1С у него код «Тариф»
            if (isset($product['TAXES']) && is_array($product['TAXES'])) {
                foreach ($product['TAXES'] as $tIdx => $tax) {
                    $code = isset($tax['CODE']) ? trim((string)$tax['CODE']) : '';
                    if ($code === '') {
                        $data['PRODUCTS'][$pIdx]['TAXES'][$tIdx]['CODE'] = self::FARE_TAX_CODE;
                    }
                }
            }

            if (isset($product['SUPPLIER'])) {
                $data['PRODUCTS'][$pIdx]['SUPPLIER'] = $this->flattenRefToUid($product['SUPPLIER']);
            }
            if (isset($product['CARRIER'])) {
                $data['PRODUCTS'][$pIdx]['CARRIER'] = $this->flattenRefToUid($product['CARRIER']);
            }
            if (isset($product['CURRENCY'])) {
                $data['PRODUCTS'][$pIdx]['CURRENCY'] = $this->flattenRefToUid($product['CURRENCY']);
            }

            // AGENT / BOOKING_AGENT: в 1С нет UID агентов — оставляем {CODE, NAME}
            if (isset($product['AGENT']) && is_array($product['AGENT'])) {
                $agent = $product['AGENT'];
                unset($agent['UID']);
                $data['PRODUCTS'][$pIdx]['AGENT'] = $agent;
            }
            if (isset($product['BOOKING_AGENT']) && is_array($product['BOOKING_AGENT'])) {
                $booking = $product['BOOKING_AGENT'];
                unset($booking['UID']);
                $data['PRODUCTS'][$pIdx]['BOOKING_AGENT'] = $booking;
            }

            if (!isset($product['COUPONS']) || !is_array($product['COUPONS'])) {
                continue;
            }

            foreach ($product['COUPONS'] as $cIdx => $coupon) {
                if (isset($coupon['DEPARTURE_AIRPORT'])) {
                    $data['PRODUCTS'][$pIdx]['COUPONS'][$cIdx]['DEPARTURE_AIRPORT'] = $this->flattenRefToUid(
                        $coupon['DEPARTURE_AIRPORT']
                    );
                }
                if (isset($coupon['ARRIVAL_AIRPORT'])) {
                    $data['PRODUCTS'][$pIdx]['COUPONS'][$cIdx]['ARRIVAL_AIRPORT'] = $this->flattenRefToUid(
                        $coupon['ARRIVAL_AIRPORT']
                    );
                }

                // 1С ждёт DEPARTURE_DATETIME / ARRIVAL_DATETIME (как в *_export.json),
                // а парсер кладёт раздельную пару DATE + TIME
                $data['PRODUCTS'][$pIdx]['COUPONS'][$cIdx]['DEPARTURE_DATETIME'] = $this->buildDateTimeField(
                    $coupon, 'DEPARTURE'
                );
                $data['PRODUCTS'][$pIdx]['COUPONS'][$cIdx]['ARRIVAL_DATETIME'] = $this->buildDateTimeField(
                    $coupon, 'ARRIVAL'
                );

                // Лишние для 1С поля купона (остаются в json/ для таблицы)
                unset(
                    $data['PRODUCTS'][$pIdx]['COUPONS'][$cIdx]['DEPARTURE_DATE'],
                    $data['PRODUCTS'][$pIdx]['COUPONS'][$cIdx]['DEPARTURE_TIME'],
                    $data['PRODUCTS'][$pIdx]['COUPONS'][$cIdx]['ARRIVAL_DATE'],
                    $data['PRODUCTS'][$pIdx]['COUPONS'][$cIdx]['ARRIVAL_TIME'],
                    $data['PRODUCTS'][$pIdx]['COUPONS'][$cIdx]['AIRLINE'],
                    $data['PRODUCTS'][$pIdx]['COUPONS'][$cIdx]['SERVICE_CLASS'],
                    $data['PRODUCTS'][$pIdx]['COUPONS'][$cIdx]['CLASS_NAME'],
                    $data['PRODUCTS'][$pIdx]['COUPONS'][$cIdx]['TYPE_ID'],
                    $data['PRODUCTS'][$pIdx]['COUPONS'][$cIdx]['TYPE_ID_NAME'],
                    $data['PRODUCTS'][$pIdx]['COUPONS'][$cIdx]['SEGMENT_STATUS'],
                    $data['PRODUCTS'][$pIdx]['COUPONS'][$cIdx]['SEGMENT_STATUS_NAME']
                );
            }
        }

        return $data;
    }

    /**
     * Убирает трёхзначный код авиакомпании из номера билета:
     * «5551234567890» / «555-1234567890» → «1234567890».
     * Номера другого формата (ЖД-бланки, пустые значения) не трогаются.
     *
     * @param mixed $number — номер билета
     * @return mixed
     */
    private function stripTicketPrefix($number)
    {
        if (!is_string($number) && !is_numeric($number)) {
            return $number;
        }

        $value = trim((string)$number);
        if (preg_match('/^\d{3}-?(\d{10})$/', $value, $m)) {
            return $m[1];
        }

        return $number;
    }

    /**
     * Собирает YYYYMMDDHHmmss для купона: готовый *_DATETIME или склейка DATE+TIME.
     *
     * @param array $coupon — купон из ORDER
     * @param string $prefix — DEPARTURE или ARRIVAL
     * @return string
     */
    private function buildDateTimeField($coupon, $prefix)
    {
        $dtKey = $prefix . '_DATETIME';
        if (isset($coupon[$dtKey]) && trim((string)$coupon[$dtKey]) !== '') {
            return (string)$coupon[$dtKey];
        }

        $date = isset($coupon[$prefix . '_DATE']) ? trim((string)$coupon[$prefix . '_DATE']) : '';
        $time = isset($coupon[$prefix . '_TIME']) ? trim((string)$coupon[$prefix . '_TIME']) : '';

        if ($date !== '' && $time !== '') {
            return $date . $time;
        }

        return $date !== '' ? $date : '';
    }

    /**
     * Объект справочника {UID,CODE,NAME} → строка UID.
     * Если значение уже строка (UID или код до enrich) — возвращается как есть.
     *
     * @param mixed $value — объект или строка
     * @return string
     */
    private function flattenRefToUid($value)
    {
        if (is_array($value)) {
            return isset($value['UID']) ? (string)$value['UID'] : '';
        }
        if (is_string($value) || is_numeric($value)) {
            return (string)$value;
        }
        return '';
    }

    /**
     * Формирует пояснение по HTTP-коду ответа (4xx, 5xx).
     */
    private function explanationForHttpCode($httpCode, $url, $response)
    {
        if ($httpCode === 401) {
            return "Ошибка аутентификации (HTTP 401). Неверный логин/пароль. Проверьте api.login и api.password в config/settings.json.";
        }
        if ($httpCode === 403) {
            return "Доступ запрещён (HTTP 403). У пользователя нет прав на операцию ORDER. Обратитесь к администратору 1С.";
        }
        if ($httpCode === 404) {
            return "Эндпоинт не найден (HTTP 404). URL {$url} не существует. Проверьте api.url — возможно неверный путь или сервис не опубликован.";
        }
        if ($httpCode === 500) {
            return "Внутренняя ошибка 1С (HTTP 500). Ответ: " . $this->truncate($response, 500) . ". Обратитесь к разработчику 1С.";
        }
        if ($httpCode === 502 || $httpCode === 503) {
            return "Сервер 1С временно недоступен (HTTP {$httpCode}). Повторите позже.";
        }
        return "Неожиданный HTTP-код: {$httpCode}. Ответ: " . $this->truncate($response, 500);
    }

    /**
     * Выполняет HTTP POST-запрос к API с телом JSON и базовой авторизацией.
     *
     * @param string $url      — адрес API
     * @param string $jsonBody  — тело запроса (JSON-строка)
     * @param string $login    — логин для Basic Auth
     * @param string $password — пароль для Basic Auth
     * @return array — response (строка ответа), http_code, error, errno
     */
    private function httpPost($url, $jsonBody, $login, $password)
    {
        $timeout = isset($this->config['timeout']) ? (int)$this->config['timeout'] : 30;

        $ch = curl_init();
        curl_setopt_array($ch, array(
            CURLOPT_URL            => $url,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $jsonBody,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_HTTPHEADER     => array(
                'Content-Type: application/json; charset=utf-8',
                'Accept: application/json'
            ),
            CURLOPT_USERPWD        => $login . ':' . $password,
            CURLOPT_HTTPAUTH       => CURLAUTH_BASIC,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
        ));

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        $errno = curl_errno($ch);
        curl_close($ch);

        return array(
            'response'  => $response !== false ? $response : '',
            'http_code' => (int)$httpCode,
            'error'     => $error,
            'errno'     => $errno
        );
    }

    /**
     * Преобразует код ошибки cURL в понятное сообщение на русском.
     *
     * @param int    $errno — код ошибки cURL
     * @param string $error — текст ошибки от cURL
     * @param string $url   — URL, к которому шёл запрос
     * @return string — сообщение для пользователя
     */
    private function explainCurlError($errno, $error, $url)
    {
        switch ($errno) {
            case 6:
                return "DNS-ошибка: хост из URL {$url} не найден. "
                     . "На домашнем ПК — это нормально (сервер 1С в рабочей сети). "
                     . "На рабочем сервере — проверьте URL.";
            case 7:
                return "Не удалось подключиться к {$url}. Сервер не отвечает. "
                     . "Причины: 1) Домашний ПК без доступа к рабочей сети — нормально. "
                     . "2) Сервер 1С выключен. 3) Порт заблокирован. 4) Неверный IP.";
            case 28:
                return "Таймаут подключения к {$url}. Сервер не ответил вовремя. "
                     . "Попробуйте увеличить api.timeout в settings.json.";
            case 35:
                return "Ошибка SSL к {$url}. Если API по HTTP — проверьте URL.";
            default:
                return "Ошибка сети cURL #{$errno}: {$error}. URL: {$url}. "
                     . "На домашнем ПК — сервер 1С просто недоступен.";
        }
    }

    /**
     * Добавляет одну строку в лог отправок (JSON Lines).
     *
     * @param string      $status       — статус: SEND, OK, ERROR, SKIP
     * @param string      $jsonFileName — имя JSON-файла
     * @param string      $sourceXml    — имя исходного XML
     * @param int|null    $httpCode     — HTTP-код ответа
     * @param string|null $responseBody — тело ответа (обрезается)
     * @param string      $message      — пояснение
     */
    private function writeLog($status, $jsonFileName, $sourceXml, $httpCode, $responseBody, $message)
    {
        $isNewFile = !file_exists($this->logFile);

        $entry = array(
            'timestamp'  => date('Y-m-d H:i:s'),
            'status'     => $status,
            'json_file'  => $jsonFileName,
            'source_xml' => $sourceXml,
            'http_code'  => $httpCode,
            'response'   => $this->truncate($responseBody, 1000),
            'message'    => $message
        );
        $line = json_encode($entry, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
        file_put_contents($this->logFile, $line, FILE_APPEND | LOCK_EX);

        // Права — на новом файле и один раз за запрос (старый лог мог остаться
        // с прежними правами, а chmod на каждую строку не нужен)
        if ($isNewFile || !$this->logOwnershipChecked) {
            $this->logOwnershipChecked = true;
            Utils::ensureOwnership($this->logFile);
        }
    }

    /**
     * Обрезает строку до заданной длины; если длиннее — добавляет "..." в конец.
     *
     * @param string|null $str    — исходная строка
     * @param int         $maxLen — максимальная длина
     * @return string
     */
    private function truncate($str, $maxLen)
    {
        if ($str === null) return '';
        if (mb_strlen($str) <= $maxLen) return $str;
        return mb_substr($str, 0, $maxLen) . '...';
    }

    /**
     * Читает последние N записей лога отправок (от новых к старым).
     * Используется на странице api_logs.php для отображения таблицы.
     *
     * @param int $limit — сколько записей вернуть (по умолчанию 500)
     * @return array — массив записей, каждая запись — массив с полями (timestamp, status, json_file, source_xml, http_code, response, message)
     */
    public function getLogEntries($limit = 500)
    {
        if (!file_exists($this->logFile)) return array();
        $lines = file($this->logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false) return array();
        $lines = array_slice($lines, -$limit);
        $lines = array_reverse($lines);
        $entries = array();
        foreach ($lines as $line) {
            $entry = json_decode($line, true);
            if (is_array($entry)) $entries[] = $entry;
        }
        return $entries;
    }

    /**
     * Очищает файл лога отправок (остаётся пустой файл).
     */
    public function clearLog()
    {
        if (file_exists($this->logFile)) {
            file_put_contents($this->logFile, '');
        }
    }
}
