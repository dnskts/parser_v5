<?php
/**
 * ============================================================
 * ОТПРАВКА ЗАКАЗОВ В API 1С
 * ============================================================
 */

class ApiSender
{
    private $config;
    private $logFile;

    public function __construct($apiConfig, $logFile)
    {
        $this->config = $apiConfig;
        $this->logFile = $logFile;
        $logDir = dirname($logFile);
        if (!is_dir($logDir)) {
            mkdir($logDir, 0755, true);
        }
    }
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

        $sendData = $orderData;
        unset($sendData['SOURCE_FILE']);
        unset($sendData['PARSED_AT']);

        $jsonBody = json_encode($sendData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($jsonBody === false) {
            $msg = "Ошибка кодирования JSON: " . json_last_error_msg();
            $this->writeLog('ERROR', $jsonFileName, $sourceXml, null, null, $msg);
            return array('success' => false, 'message' => $msg, 'http_code' => null);
        }

        $invoiceNumber = isset($orderData['INVOICE_NUMBER']) ? $orderData['INVOICE_NUMBER'] : '?';
        $this->writeLog('SEND', $jsonFileName, $sourceXml, null, null,
            "Отправка заказа #{$invoiceNumber} на {$url} (" . strlen($jsonBody) . " байт)");

        $result = $this->httpPost($url, $jsonBody, $login, $password);

        $httpCode = $result['http_code'];
        $response = $result['response'];
        $error = $result['error'];
        $errno = $result['errno'];

        if ($errno !== 0) {
            $explanation = $this->explainCurlError($errno, $error, $url);
            $this->writeLog('ERROR', $jsonFileName, $sourceXml, $httpCode, $response, $explanation);
            return array('success' => false, 'message' => $explanation, 'http_code' => $httpCode);
        }

        if ($httpCode >= 200 && $httpCode < 300) {
            $explanation = "Успешно отправлено (HTTP {$httpCode}). Ответ: " . $this->truncate($response, 500);
            $this->writeLog('OK', $jsonFileName, $sourceXml, $httpCode, $response, $explanation);
            return array('success' => true, 'message' => $explanation, 'http_code' => $httpCode);
        }

        if ($httpCode === 401) {
            $explanation = "Ошибка аутентификации (HTTP 401). Неверный логин/пароль. Проверьте api.login и api.password в config/settings.json.";
        } elseif ($httpCode === 403) {
            $explanation = "Доступ запрещён (HTTP 403). У пользователя нет прав на операцию ORDER. Обратитесь к администратору 1С.";
        } elseif ($httpCode === 404) {
            $explanation = "Эндпоинт не найден (HTTP 404). URL {$url} не существует. Проверьте api.url — возможно неверный путь или сервис не опубликован.";
        } elseif ($httpCode === 500) {
            $explanation = "Внутренняя ошибка 1С (HTTP 500). Ответ: " . $this->truncate($response, 500) . ". Обратитесь к разработчику 1С.";
        } elseif ($httpCode === 502 || $httpCode === 503) {
            $explanation = "Сервер 1С временно недоступен (HTTP {$httpCode}). Повторите позже.";
        } else {
            $explanation = "Неожиданный HTTP-код: {$httpCode}. Ответ: " . $this->truncate($response, 500);
        }

        $this->writeLog('ERROR', $jsonFileName, $sourceXml, $httpCode, $response, $explanation);
        return array('success' => false, 'message' => $explanation, 'http_code' => $httpCode);
    }

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

    private function writeLog($status, $jsonFileName, $sourceXml, $httpCode, $responseBody, $message)
    {
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
    }

    private function truncate($str, $maxLen)
    {
        if ($str === null) return '';
        if (mb_strlen($str) <= $maxLen) return $str;
        return mb_substr($str, 0, $maxLen) . '...';
    }

    /**
     * Читает последние N записей лога (от новых к старым).
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

    public function clearLog()
    {
        if (file_exists($this->logFile)) {
            file_put_contents($this->logFile, '');
        }
    }
}
