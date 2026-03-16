<?php
/**
 * ============================================================
 * ТОЧКА ВХОДА ДЛЯ ОБРАБОТКИ ФАЙЛОВ
 * ============================================================
 * 
 * Этот скрипт запускает обработку XML-файлов.
 * Его можно вызывать двумя способами:
 * 
 * 1. Через планировщик задач (cron / Windows Task Scheduler):
 *    php process.php
 *    
 *    В этом случае скрипт проверит, прошёл ли нужный интервал
 *    с момента последнего запуска, и запустит обработку только
 *    если время пришло.
 * 
 * 2. Из веб-интерфейса (через api.php):
 *    В этом случае скрипт подключается как модуль,
 *    а обработка запускается функцией runProcessing().
 * 
 * Настройка cron (Linux):
 *    * * * * * /usr/bin/php /путь/к/проекту/process.php
 *    (запуск каждую минуту; скрипт сам проверит интервал)
 * 
 * Настройка планировщика задач (Windows):
 *    Программа: php.exe
 *    Аргументы: E:\Projects\parser_v5\process.php
 *    Расписание: каждую 1 минуту
 * ============================================================
 */

// Определяем корневую папку проекта
// __DIR__ — это папка, где лежит этот файл (корень проекта)
// Проверка нужна, чтобы избежать ошибки при повторном подключении из api.php
if (!defined('BASE_DIR')) {
    define('BASE_DIR', __DIR__);
}

// Подключаем необходимые компоненты системы
require_once BASE_DIR . '/core/Logger.php';
require_once BASE_DIR . '/core/ParserManager.php';
require_once BASE_DIR . '/core/Processor.php';
require_once BASE_DIR . '/core/SftpSync.php';

/**
 * SFTP-синхронизация: загрузка XML с сервера поставщика в input/.
 * Вызывается перед обработкой при запуске из Web UI или CLI.
 *
 * @param bool $force — при true всегда синхронизировать; при false проверять интервал
 * @return array — downloaded (int), errors (int), files (array), skipped (bool)
 */
function runSftpSync($force = false)
{
    $configFile = BASE_DIR . '/config/settings.json';
    $logFile = BASE_DIR . '/logs/sftp_sync.log';
    $lastRunFile = BASE_DIR . '/config/sftp_last_run.txt';

    $default = array('downloaded' => 0, 'errors' => 0, 'files' => array(), 'skipped' => false);

    if (!file_exists($configFile)) {
        return array_merge($default, array('skipped' => true));
    }

    $allSettings = json_decode(file_get_contents($configFile), true);
    if (!is_array($allSettings) || !isset($allSettings['sftp'])) {
        return array_merge($default, array('skipped' => true));
    }

    $sftpConfig = $allSettings['sftp'];
    if (isset($sftpConfig['enabled']) && $sftpConfig['enabled'] === false) {
        return array_merge($default, array('skipped' => true));
    }

    if (!$force) {
        $lastRun = 0;
        if (file_exists($lastRunFile)) {
            $lastRun = (int)trim(file_get_contents($lastRunFile));
        }
        $interval = isset($sftpConfig['interval']) ? (int)$sftpConfig['interval'] : 60;
        if (time() < $lastRun + $interval) {
            return array_merge($default, array('skipped' => true));
        }
    }

    if (isset($sftpConfig['local_path']) && strpos($sftpConfig['local_path'], '/') !== 0) {
        $sftpConfig['local_path'] = BASE_DIR . '/' . $sftpConfig['local_path'];
    }

    $sync = new SftpSync($sftpConfig, $logFile);
    $result = $sync->sync();

    $configDir = dirname($lastRunFile);
    if (!is_dir($configDir)) {
        @mkdir($configDir, 0755, true);
    }
    file_put_contents($lastRunFile, (string)time(), LOCK_EX);

    return array(
        'downloaded' => $result['downloaded'],
        'errors' => $result['errors'],
        'files' => isset($result['files']) ? $result['files'] : array(),
        'skipped' => false
    );
}

/**
 * Функция запуска обработки.
 * 
 * Создаёт все необходимые объекты (логгер, менеджер парсеров, обработчик)
 * и запускает процесс обработки файлов.
 * 
 * @param bool $force — принудительный запуск без проверки интервала
 * @return array — результат обработки
 */
function runProcessing($force = false)
{
    // 1. SFTP-синхронизация (если включена в настройках)
    $sftpResult = runSftpSync($force);

    // Создаём логгер — он будет записывать события в файл logs/app.log
    $logger = new Logger(BASE_DIR . '/logs/app.log');

    // Создаём менеджер парсеров — он автоматически найдёт все парсеры в папке parsers/
    $parserManager = new ParserManager(BASE_DIR . '/parsers', $logger);

    // Создаём обработчик — он обходит папки поставщиков и вызывает парсеры
    $processor = new Processor(
        BASE_DIR . '/input',              // Папка с входными XML-файлами
        BASE_DIR . '/json',               // Папка для выходных JSON-файлов
        BASE_DIR . '/config/settings.json', // Файл настроек
        $logger,
        $parserManager
    );

    // 2. Обработка XML
    $result = $processor->run($force);

    // 3. Добавляем данные SFTP в результат
    $result['sftp_downloaded'] = $sftpResult['downloaded'];
    $result['sftp_errors'] = $sftpResult['errors'];

    return $result;
}

// Если скрипт запущен напрямую из командной строки (не подключён из другого файла)
// — выполняем обработку автоматически
if (php_sapi_name() === 'cli' && realpath($argv[0]) === realpath(__FILE__)) {
    $result = runProcessing(false);
    
    // Выводим результат в консоль
    echo "Результат обработки: " . json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL;
    
    // Код завершения: 0 = успех, 1 = ошибка
    exit($result['status'] === 'ok' || $result['status'] === 'skipped' ? 0 : 1);
}
