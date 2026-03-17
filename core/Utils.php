<?php
/**
 * ============================================================
 * ОБЩИЕ УТИЛИТЫ
 * ============================================================
 * 
 * Вспомогательные функции, используемые несколькими модулями.
 * Подключается там, где нужны общие утилиты (парсеры и др.)
 * ============================================================
 */

class Utils
{
    /**
     * Генерирует уникальный идентификатор UUID версии 4 (RFC 4122).
     * 
     * UUID выглядит как: 8fd8578c-c002-4e73-891d-278373b59ef4
     * Каждый вызов генерирует новый уникальный идентификатор.
     * 
     * Работает как на PHP 7, так и на PHP 8.
     * 
     * @return string — UUID в формате xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx
     */
    public static function generateUUID()
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

    /**
     * Универсальный cURL-запрос с поддержкой прокси и Basic Auth.
     *
     * @param string $url — адрес запроса
     * @param array $options — параметры:
     *   method (GET|POST), headers (array), body (string),
     *   auth_login, auth_password,
     *   proxy, proxy_login, proxy_password,
     *   timeout (int, по умолчанию 30), ssl_verify (bool, по умолчанию false)
     * @return array — http_code, body, error, duration
     */
    public static function curlWithProxy($url, $options = array())
    {
        $method    = isset($options['method']) ? strtoupper($options['method']) : 'GET';
        $timeout   = isset($options['timeout']) ? (int)$options['timeout'] : 30;
        $sslVerify = isset($options['ssl_verify']) ? (bool)$options['ssl_verify'] : false;

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, min($timeout, 10));
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, $sslVerify);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, $sslVerify ? 2 : 0);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);

        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            if (isset($options['body'])) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, $options['body']);
            }
        }

        if (isset($options['headers']) && is_array($options['headers'])) {
            curl_setopt($ch, CURLOPT_HTTPHEADER, $options['headers']);
        }

        // Basic Auth
        if (!empty($options['auth_login'])) {
            curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
            curl_setopt($ch, CURLOPT_USERPWD,
                $options['auth_login'] . ':' . (isset($options['auth_password']) ? $options['auth_password'] : '')
            );
        }

        // Прокси
        if (!empty($options['proxy'])) {
            curl_setopt($ch, CURLOPT_PROXY, $options['proxy']);
            if (!empty($options['proxy_login'])) {
                curl_setopt($ch, CURLOPT_PROXYUSERPWD,
                    $options['proxy_login'] . ':' . (isset($options['proxy_password']) ? $options['proxy_password'] : '')
                );
            }
        }

        $t0 = microtime(true);
        $body = curl_exec($ch);
        $duration = round(microtime(true) - $t0, 3);

        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        return array(
            'http_code' => $httpCode,
            'body'      => $body !== false ? $body : '',
            'error'     => $error,
            'duration'  => $duration
        );
    }
}