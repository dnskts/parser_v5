<?php
/**
 * ============================================================
 * SFTP-СИНХРОНИЗАТОР — ТОЧКА ВХОДА
 * ============================================================
 * 
 * Автономный модуль для копирования XML-файлов с SFTP-сервера
 * поставщика в локальную папку input/ для дальнейшей обработки.
 * 
 * Настройки берутся из config/settings.json (секция "sftp").
 * 
 * Запуск:
 *   CLI (cron):  php sftp_sync.php
 *   Браузер:     http://server/parser_v5/sftp_sync.php
 *   Force:       http://server/parser_v5/sftp_sync.php?force=1
 * 
 * Cron (каждую минуту):
 *   * * * * * php /path/to/parser_v5/sftp_sync.php && sleep 5 && php /path/to/parser_v5/process.php
 * 
 * ============================================================
 */

// Корневая папка проекта
define('BASE_DIR', __DIR__);

// Подключаем класс синхронизации
require_once BASE_DIR . '/core/SftpSync.php';

// -------------------------------------------------------
// Чтение настроек из config/settings.json
// -------------------------------------------------------

$configFile = BASE_DIR . '/config/settings.json';
$logFile = BASE_DIR . '/logs/sftp_sync.log';
$lastRunFile = BASE_DIR . '/config/sftp_last_run.txt';

if (!file_exists($configFile)) {
    $msg = 'Файл настроек не найден: ' . $configFile;
    logAndExit($msg, $logFile);
}

$allSettings = json_decode(file_get_contents($configFile), true);
if (!is_array($allSettings) || !isset($allSettings['sftp'])) {
    $msg = 'Секция "sftp" не найдена в settings.json';
    logAndExit($msg, $logFile);
}

$sftpConfig = $allSettings['sftp'];

// Проверяем что SFTP включён
if (isset($sftpConfig['enabled']) && $sftpConfig['enabled'] === false) {
    exit(0);
}

// Преобразуем относительный local_path в абсолютный
if (isset($sftpConfig['local_path']) && strpos($sftpConfig['local_path'], '/') !== 0) {
    $sftpConfig['local_path'] = BASE_DIR . '/' . $sftpConfig['local_path'];
}

// -------------------------------------------------------
// Определяем режим запуска
// -------------------------------------------------------

$isCli = (php_sapi_name() === 'cli');
$isForce = $isCli 
    ? (isset($argv[1]) && $argv[1] === '--force') 
    : (isset($_GET['force']) && $_GET['force'] === '1');

// Заголовки для браузера
if (!$isCli) {
    header('Content-Type: text/plain; charset=utf-8');
}

// -------------------------------------------------------
// Проверка интервала
// -------------------------------------------------------

if (!$isForce) {
    $lastRun = 0;
    if (file_exists($lastRunFile)) {
        $lastRun = (int)trim(file_get_contents($lastRunFile));
    }

    $interval = isset($sftpConfig['interval']) ? (int)$sftpConfig['interval'] : 60;
    $nextRun = $lastRun + $interval;
    $now = time();

    if ($now < $nextRun) {
        $waitSec = $nextRun - $now;
        if (!$isCli) {
            echo 'Интервал не прошёл. Следующий запуск через ' . $waitSec . " сек.\n";
        }
        exit(0);
    }
}

// -------------------------------------------------------
// Запуск синхронизации
// -------------------------------------------------------

$sync = new SftpSync($sftpConfig, $logFile);
$result = $sync->sync();

// Обновляем время последнего запуска
$configDir = dirname($lastRunFile);
if (!is_dir($configDir)) {
    @mkdir($configDir, 0755, true);
}
file_put_contents($lastRunFile, (string)time(), LOCK_EX);

// -------------------------------------------------------
// Вывод результата
// -------------------------------------------------------

$output = array(
    'status'     => ($result['errors'] === 0) ? 'ok' : 'partial',
    'downloaded' => $result['downloaded'],
    'errors'     => $result['errors'],
    'files'      => $result['files'],
    'timestamp'  => date('Y-m-d H:i:s'),
);

if ($isCli) {
    echo '[' . $output['timestamp'] . '] SFTP sync: ' 
         . $output['downloaded'] . ' downloaded, ' 
         . $output['errors'] . ' errors' . "\n";
    if (!empty($output['files'])) {
        foreach ($output['files'] as $f) {
            echo '  + ' . $f . "\n";
        }
    }
} else {
    echo json_encode($output, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
}

// -------------------------------------------------------
// Вспомогательная функция
// -------------------------------------------------------

/**
 * Записать ошибку в лог и завершить выполнение
 *
 * @param string $message Сообщение об ошибке
 * @param string $logFile Путь к файлу лога
 */
function logAndExit($message, $logFile)
{
    $line = '[' . date('Y-m-d H:i:s') . '] [ERROR] ' . $message . "\n";
    $logDir = dirname($logFile);
    if (!is_dir($logDir)) {
        @mkdir($logDir, 0755, true);
    }
    file_put_contents($logFile, $line, FILE_APPEND | LOCK_EX);

    if (php_sapi_name() !== 'cli') {
        header('Content-Type: text/plain; charset=utf-8');
        echo $message . "\n";
    } else {
        fwrite(STDERR, $message . "\n");
    }
    exit(1);
}