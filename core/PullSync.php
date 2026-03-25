<?php
/**
 * ============================================================
 * PULL-СИНХРОНИЗАТОР SmartTravel
 * ============================================================
 *
 * Запрашивает данные о заказах с API SmartTravel (GET + Basic Auth),
 * поддерживает HTTP-прокси (закрытый контур банка).
 * Сохраняет ответ в input/smarttravel/pull_{дата}.json
 * для последующей обработки Processor-ом.
 *
 * Аналог SftpSync, но для REST API SmartTravel.
 * ============================================================
 */

require_once __DIR__ . '/Utils.php';

class PullSync
{
    /** @var array Конфигурация SmartTravel (секция smarttravel из settings.json) */
    private $config;

    /** @var string Путь к файлу лога */
    private $logFile;

    /** @var string Локальная папка для сохранения */
    private $localPath;

    /**
     * @param array  $config   — секция smarttravel из settings.json
     * @param string $logFile  — путь к logs/pull_sync.log
     * @param string $localPath — путь к input/smarttravel/
     */
    public function __construct($config, $logFile, $localPath)
    {
        $this->config = $config;
        $this->logFile = $logFile;
        $this->localPath = $localPath;
    }

    /**
     * Выполняет запрос к API и сохраняет результат.
     *
     * @return array — downloaded (int), errors (int), files (array), message (string)
     */
    public function sync()
    {
        $result = array('downloaded' => 0, 'errors' => 0, 'files' => array(), 'message' => '');

        $pull = isset($this->config['pull']) ? $this->config['pull'] : array();
        $url  = isset($pull['url']) ? $pull['url'] : '';

        if (empty($url)) {
            $this->log('ERROR', 'URL API SmartTravel не указан в настройках');
            $result['errors'] = 1;
            $result['message'] = 'URL не указан';
            return $result;
        }

        // Формируем параметры запроса: дата = 31 день назад (максимальная глубина по документации)
        $dateFrom = date('d.m.Y', strtotime('-31 days'));
        $requestUrl = $url . '?date=' . urlencode($dateFrom);
        $this->log('INFO', "Запрос к SmartTravel API: {$requestUrl}");

        // Создаём локальную папку, если не существует
        Utils::ensureDirectory($this->localPath);

        $curlOptions = array(
            'method'         => 'GET',
            'auth_login'     => isset($pull['login']) ? $pull['login'] : '',
            'auth_password'  => isset($pull['password']) ? $pull['password'] : '',
            'timeout'        => 120,
            'ssl_verify'     => false,
            'headers'        => array('Accept: application/json'),
        );

        // Прокси (обязателен для закрытого контура банка)
        if (!empty($pull['proxy'])) {
            $curlOptions['proxy']          = $pull['proxy'];
            $curlOptions['proxy_login']    = isset($pull['proxy_login']) ? $pull['proxy_login'] : '';
            $curlOptions['proxy_password'] = isset($pull['proxy_pass']) ? $pull['proxy_pass'] : '';
        }

        $response = Utils::curlWithProxy($requestUrl, $curlOptions);

        if (!empty($response['error'])) {
            $this->log('ERROR', 'Ошибка cURL: ' . $response['error']);
            $result['errors'] = 1;
            $result['message'] = 'cURL: ' . $response['error'];
            return $result;
        }

        if ($response['http_code'] !== 200) {
            $this->log('ERROR', "HTTP {$response['http_code']}: " . mb_substr($response['body'], 0, 500));
            $result['errors'] = 1;
            $result['message'] = "HTTP {$response['http_code']}";
            return $result;
        }

        $data = json_decode($response['body'], true);
        if (!is_array($data) || !isset($data['Orders'])) {
            $this->log('ERROR', 'Ответ не содержит ключ Orders');
            $result['errors'] = 1;
            $result['message'] = 'Нет ключа Orders в ответе';
            return $result;
        }

        $ordersCount = count($data['Orders']);
        if ($ordersCount === 0) {
            $this->log('INFO', 'Нет новых заказов за период с ' . $dateFrom);
            $result['message'] = 'Нет новых заказов';
            return $result;
        }

        // Сохраняем ответ в файл
        $fileName = 'pull_' . date('Ymd_His') . '.json';
        $filePath = $this->localPath . DIRECTORY_SEPARATOR . $fileName;
        $jsonContent = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $written = file_put_contents($filePath, $jsonContent, LOCK_EX);

        if ($written === false) {
            $this->log('ERROR', "Не удалось сохранить файл: {$filePath}");
            $result['errors'] = 1;
            $result['message'] = 'Ошибка записи файла';
            return $result;
        }

        Utils::ensureOwnership($filePath);

        $sizeKb = round($written / 1024, 1);
        $this->log('SUCCESS', "Сохранён {$fileName} ({$sizeKb} KB, заказов: {$ordersCount}), время запроса: {$response['duration']}с");

        $result['downloaded'] = 1;
        $result['files'] = array($fileName);
        $result['message'] = "Скачано: {$ordersCount} заказов";
        return $result;
    }

    /**
     * Запись в лог pull_sync.log (формат как app.log).
     *
     * @param string $level   — INFO, WARNING, ERROR, SUCCESS
     * @param string $message — текст сообщения
     */
    private function log($level, $message)
    {
        $dir = dirname($this->logFile);
        Utils::ensureDirectory($dir);

        $isNewFile = !file_exists($this->logFile);

        // Ротация: >5 МБ → .old
        if (file_exists($this->logFile) && filesize($this->logFile) > 5 * 1024 * 1024) {
            @rename($this->logFile, $this->logFile . '.old');
            Utils::ensureOwnership($this->logFile . '.old');
            $isNewFile = true;
        }

        $line = '[' . date('Y-m-d H:i:s') . '] [' . $level . '] ' . $message . PHP_EOL;
        file_put_contents($this->logFile, $line, FILE_APPEND | LOCK_EX);

        if ($isNewFile) {
            Utils::ensureOwnership($this->logFile);
        }
    }
}
