<?php
/**
 * ============================================================
 * ДИАГНОСТИКА СЕТИ И ПРАВ ЗАПИСИ
 * ============================================================
 * Лёгкие проверки для страницы Setup: доступность 1С / SFTP /
 * SmartTravel и пробная запись в рабочие папки.
 * Полную синхронизацию и отправку заказа не делает.
 * ============================================================
 */

require_once __DIR__ . '/Utils.php';
require_once __DIR__ . '/ApiSender.php';
require_once __DIR__ . '/SftpSync.php';

class SetupDiagnostics
{
    /** Имя временного файла пробы записи (создаётся и сразу удаляется) */
    const PROBE_NAME = '.setup_write_probe';

    /** @var string Корень проекта */
    private $baseDir;

    /**
     * @param string $baseDir — корень parser_v5
     */
    public function __construct($baseDir)
    {
        $this->baseDir = rtrim($baseDir, '/\\');
    }

    /**
     * Запускает все проверки.
     *
     * @param array $settings — содержимое config/settings.json
     * @return array checks, ok, fail, skip
     */
    public function run($settings)
    {
        if (!is_array($settings)) {
            $settings = array();
        }

        $checks = array();
        $checks[] = $this->checkApi($settings);
        $checks[] = $this->checkSftp($settings);
        $checks[] = $this->checkSmartTravel($settings);

        foreach ($this->writeTargets() as $id => $relative) {
            $checks[] = $this->checkWrite($id, $relative);
        }

        $ok = 0;
        $fail = 0;
        $skip = 0;
        foreach ($checks as $check) {
            if ($check['status'] === 'ok') {
                $ok++;
            } elseif ($check['status'] === 'fail') {
                $fail++;
            } else {
                $skip++;
            }
        }

        return array(
            'checks' => $checks,
            'ok'     => $ok,
            'fail'   => $fail,
            'skip'   => $skip
        );
    }

    /**
     * Папки, куда парсер пишет сам. Ключ — id проверки, значение — путь от корня.
     *
     * @return array
     */
    private function writeTargets()
    {
        return array(
            'write_config'       => 'config',
            'write_logs'         => 'logs',
            'write_json'         => 'json',
            'write_json_api'     => 'json_api',
            'write_moyagent'     => 'input/moyagent',
            'write_smarttravel'  => 'input/smarttravel',
            'write_references'   => 'references',
            'write_import'       => 'references/import'
        );
    }

    /**
     * HEAD к API 1С. Выключенный API — skip, а не ошибка.
     *
     * @param array $settings
     * @return array
     */
    private function checkApi($settings)
    {
        $api = (isset($settings['api']) && is_array($settings['api'])) ? $settings['api'] : array();
        $enabled = !empty($api['enabled']);
        $url = isset($api['url']) ? trim((string)$api['url']) : '';

        if (!$enabled || $url === '') {
            return $this->row('api', 'API 1С', 'skip', 0, 'выключено или URL не задан');
        }

        $started = microtime(true);
        $sender = new ApiSender($api, $this->baseDir . '/logs/api_send.log', null);
        $available = $sender->isAvailable();
        $ms = $this->elapsedMs($started);

        if ($available) {
            return $this->row('api', 'API 1С', 'ok', $ms, 'сервер ответил (' . $this->shortHost($url) . ')');
        }

        return $this->row('api', 'API 1С', 'fail', $ms, 'нет ответа (' . $this->shortHost($url) . ')');
    }

    /**
     * Короткое SFTP-подключение без листинга и скачивания.
     *
     * @param array $settings
     * @return array
     */
    private function checkSftp($settings)
    {
        $sftp = (isset($settings['sftp']) && is_array($settings['sftp'])) ? $settings['sftp'] : array();
        if (empty($sftp['enabled'])) {
            return $this->row('sftp', 'SFTP Мой агент', 'skip', 0, 'выключено');
        }

        $host = isset($sftp['host']) ? trim((string)$sftp['host']) : '';
        $port = isset($sftp['port']) ? (int)$sftp['port'] : 22;
        $target = ($host !== '' ? $host : 'хост не задан') . ':' . $port;

        $started = microtime(true);
        $sync = new SftpSync($sftp, $this->baseDir . '/logs/sftp_sync.log');
        $ok = $sync->testConnection();
        $ms = $this->elapsedMs($started);

        if ($ok) {
            return $this->row('sftp', 'SFTP Мой агент', 'ok', $ms, 'соединение установлено (' . $target . ')');
        }

        return $this->row('sftp', 'SFTP Мой агент', 'fail', $ms, 'нет соединения (' . $target . ')');
    }

    /**
     * Короткий GET к SmartTravel. Любой HTTP-код > 0 значит, что сеть и прокси живы.
     *
     * @param array $settings
     * @return array
     */
    private function checkSmartTravel($settings)
    {
        $smart = (isset($settings['smarttravel']) && is_array($settings['smarttravel']))
            ? $settings['smarttravel']
            : array();
        $mode = isset($smart['mode']) ? (string)$smart['mode'] : '';
        $pull = (isset($smart['pull']) && is_array($smart['pull'])) ? $smart['pull'] : array();
        $url = isset($pull['url']) ? trim((string)$pull['url']) : '';

        if (empty($smart['enabled']) || $mode !== 'pull' || $url === '') {
            return $this->row('smarttravel', 'SmartTravel PULL', 'skip', 0, 'выключено, режим не pull или URL пуст');
        }

        $started = microtime(true);
        $result = Utils::curlWithProxy($url, array(
            'method'         => 'GET',
            'timeout'        => 3,
            'auth_login'     => isset($pull['login']) ? $pull['login'] : '',
            'auth_password'  => isset($pull['password']) ? $pull['password'] : '',
            'proxy'          => isset($pull['proxy']) ? $pull['proxy'] : '',
            'proxy_login'    => isset($pull['proxy_login']) ? $pull['proxy_login'] : '',
            'proxy_password' => isset($pull['proxy_pass']) ? $pull['proxy_pass'] : ''
        ));
        $ms = $this->elapsedMs($started);

        $httpCode = isset($result['http_code']) ? (int)$result['http_code'] : 0;
        $host = $this->shortHost($url);
        if ($httpCode > 0) {
            return $this->row(
                'smarttravel',
                'SmartTravel PULL',
                'ok',
                $ms,
                'HTTP ' . $httpCode . ' — сеть отвечает (' . $host . ')'
            );
        }

        $error = isset($result['error']) && $result['error'] !== ''
            ? $result['error']
            : 'нет ответа';
        return $this->row('smarttravel', 'SmartTravel PULL', 'fail', $ms, $error . ' (' . $host . ')');
    }

    /**
     * Создаёт временный файл в папке, пишет байт и удаляет.
     * Папку не создаёт: отсутствие — это fail.
     *
     * @param string $id
     * @param string $relative
     * @return array
     */
    private function checkWrite($id, $relative)
    {
        $title = 'Запись: ' . $relative . '/';
        $dir = $this->baseDir . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);

        if (!is_dir($dir)) {
            return $this->row($id, $title, 'fail', 0, 'папка отсутствует');
        }

        $started = microtime(true);
        $probe = $dir . DIRECTORY_SEPARATOR . self::PROBE_NAME;
        $written = @file_put_contents($probe, '1');
        if ($written === false) {
            $ms = $this->elapsedMs($started);
            $hint = is_writable($dir) ? '' : ', is_writable=нет';
            return $this->row($id, $title, 'fail', $ms, 'нет права записи' . $hint);
        }

        $removed = @unlink($probe);
        $ms = $this->elapsedMs($started);
        if (!$removed && file_exists($probe)) {
            return $this->row($id, $title, 'fail', $ms, 'файл записан, но не удалён');
        }

        return $this->row($id, $title, 'ok', $ms, 'запись и удаление прошли');
    }

    /**
     * @param string $id
     * @param string $title
     * @param string $status ok|fail|skip
     * @param int    $ms
     * @param string $message
     * @return array
     */
    private function row($id, $title, $status, $ms, $message)
    {
        return array(
            'id'      => $id,
            'title'   => $title,
            'status'  => $status,
            'ms'      => (int)$ms,
            'message' => $message
        );
    }

    /**
     * @param float $started microtime(true) на старте
     * @return int
     */
    private function elapsedMs($started)
    {
        return (int)round((microtime(true) - $started) * 1000);
    }

    /**
     * Хост (и порт) без логина, пароля и пути.
     *
     * @param string $url
     * @return string
     */
    private function shortHost($url)
    {
        $parts = parse_url($url);
        if (!is_array($parts) || empty($parts['host'])) {
            return $url;
        }
        $host = $parts['host'];
        if (!empty($parts['port'])) {
            $host .= ':' . $parts['port'];
        }
        return $host;
    }
}
