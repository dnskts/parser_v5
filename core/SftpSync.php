<?php
/**
 * ============================================================
 * SFTP-СИНХРОНИЗАТОР — КЛАСС
 * ============================================================
 * 
 * Скачивает XML-файлы с удалённого SFTP-сервера в локальную папку.
 * Использует ext-curl с поддержкой протокола SFTP.
 * 
 * Логика:
 * 1. Подключается к SFTP (cURL + пароль)
 * 2. Получает список *.xml файлов
 * 3. Скачивает каждый в локальную папку
 * 4. Перемещает скачанный файл в Processed/ на SFTP
 * 5. Логирует каждый шаг
 * 
 * Требования:
 * - ext-curl с поддержкой SFTP (libssh2)
 * - PHP 7.0+
 * 
 * ============================================================
 */

class SftpSync
{
    /** @var array Конфигурация подключения */
    private $config;

    /** @var string Путь к файлу лога */
    private $logFile;

    /** @var string Базовый URL для SFTP (sftp://user@host:port) */
    private $baseUrl;

    /**
     * Конструктор
     *
     * @param array $config Массив настроек:
     *   - host (string) — адрес SFTP-сервера
     *   - port (int) — порт (по умолчанию 22)
     *   - login (string) — имя пользователя
     *   - password (string) — пароль
     *   - remote_path (string) — путь к папке на SFTP (относительный)
     *   - local_path (string) — локальная папка для сохранения
     * @param string $logFile Путь к файлу лога
     */
    public function __construct($config, $logFile)
    {
        $this->config = array_merge(array(
            'host' => '',
            'port' => 22,
            'login' => '',
            'password' => '',
            'remote_path' => '',
            'local_path' => '',
        ), $config);

        $this->logFile = $logFile;

        // Формируем базовый URL для SFTP
        // Относительный путь: sftp://user@host:port/~/remote_path/
        $port = (int)$this->config['port'];
        $this->baseUrl = 'sftp://' . $this->config['login'] . '@' 
                        . $this->config['host'] . ':' . $port;
    }

    /**
     * Основной метод синхронизации
     * 
     * Получает список файлов с SFTP, скачивает новые,
     * перемещает обработанные в Processed/ на SFTP.
     *
     * @return array Результат: downloaded (int), errors (int), files (array)
     */
    public function sync()
    {
        $result = array(
            'downloaded' => 0,
            'errors' => 0,
            'files' => array(),
        );

        $this->log('INFO', 'Начало синхронизации SFTP');
        $this->log('INFO', 'Сервер: ' . $this->config['host'] . ', папка: ' . $this->config['remote_path']);

        // Проверяем локальную папку
        $localPath = $this->config['local_path'];
        if (!is_dir($localPath)) {
            if (!mkdir($localPath, 0755, true)) {
                $this->log('ERROR', 'Не удалось создать локальную папку: ' . $localPath);
                return $result;
            }
        }

        // Проверяем соединение
        if (!$this->testConnection()) {
            $this->log('ERROR', 'Не удалось подключиться к SFTP-серверу');
            return $result;
        }
        $this->log('INFO', 'Соединение с SFTP установлено');

        // Получаем список XML-файлов
        $remoteFiles = $this->listRemoteXmlFiles();
        if ($remoteFiles === false) {
            $this->log('ERROR', 'Ошибка получения списка файлов с SFTP');
            return $result;
        }

        if (empty($remoteFiles)) {
            $this->log('INFO', 'Новых XML-файлов на SFTP не найдено');
            return $result;
        }

        $this->log('INFO', 'Найдено файлов на SFTP: ' . count($remoteFiles));

        // Обрабатываем каждый файл
        foreach ($remoteFiles as $fileName) {
            $localFilePath = $localPath . '/' . $fileName;

            // Скачиваем файл
            $downloaded = $this->downloadFile($fileName, $localFilePath);
            if (!$downloaded) {
                $this->log('ERROR', 'Ошибка скачивания: ' . $fileName);
                $result['errors']++;
                continue;
            }

            // Проверяем что файл скачался нормально
            if (!file_exists($localFilePath) || filesize($localFilePath) === 0) {
                $this->log('ERROR', 'Файл пустой или не создан: ' . $fileName);
                @unlink($localFilePath);
                $result['errors']++;
                continue;
            }

            $fileSize = $this->formatFileSize(filesize($localFilePath));
            $this->log('SUCCESS', 'Скачан: ' . $fileName . ' (' . $fileSize . ')');

            // Перемещаем на SFTP в Processed/
            $moved = $this->moveToProcessed($fileName);
            if ($moved) {
                $this->log('INFO', 'Перемещён на SFTP в Processed/: ' . $fileName);
            } else {
                $this->log('WARNING', 'Не удалось переместить на SFTP в Processed/: ' . $fileName);
                // Файл уже скачан локально — продолжаем работу
            }

            $result['downloaded']++;
            $result['files'][] = $fileName;
        }

        $this->log('INFO', 'Синхронизация завершена. Скачано: ' . $result['downloaded'] 
                         . ', ошибок: ' . $result['errors']);

        return $result;
    }

    /**
     * Проверка соединения с SFTP-сервером
     *
     * @return bool true если сервер доступен
     */
    public function testConnection()
    {
        $url = $this->buildRemoteUrl('/');
        $ch = $this->createCurlHandle($url);
        curl_setopt($ch, CURLOPT_NOBODY, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 5);

        curl_exec($ch);
        $error = curl_error($ch);
        $errno = curl_errno($ch);
        curl_close($ch);

        return ($errno === 0);
    }

    /**
     * Получение списка XML-файлов из удалённой папки
     *
     * @return array|false Массив имён файлов или false при ошибке
     */
    public function listRemoteXmlFiles()
    {
        $url = $this->buildRemoteUrl('/');
        $ch = $this->createCurlHandle($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 15);

        // CURLOPT_DIRLISTONLY — получить только имена файлов
        curl_setopt($ch, CURLOPT_DIRLISTONLY, true);

        $response = curl_exec($ch);
        $error = curl_error($ch);
        $errno = curl_errno($ch);
        curl_close($ch);

        if ($errno !== 0) {
            $this->log('ERROR', 'cURL ошибка при листинге: [' . $errno . '] ' . $error);
            return false;
        }

        if (empty($response)) {
            return array();
        }

        // Разбираем список файлов (каждый на новой строке)
        $allFiles = array_filter(array_map('trim', explode("\n", $response)));

        // Фильтруем только *.xml файлы (без учёта регистра)
        $xmlFiles = array();
        foreach ($allFiles as $file) {
            if (preg_match('/\.xml$/i', $file)) {
                $xmlFiles[] = $file;
            }
        }

        return $xmlFiles;
    }

    /**
     * Скачивание файла с SFTP на локальный диск
     *
     * @param string $remoteFileName Имя файла на SFTP
     * @param string $localFilePath Полный локальный путь для сохранения
     * @return bool true если файл успешно скачан
     */
    public function downloadFile($remoteFileName, $localFilePath)
    {
        $url = $this->buildRemoteUrl('/' . $remoteFileName);

        // Открываем локальный файл для записи
        $fp = @fopen($localFilePath, 'wb');
        if ($fp === false) {
            $this->log('ERROR', 'Не удалось создать локальный файл: ' . $localFilePath);
            return false;
        }

        $ch = $this->createCurlHandle($url);
        curl_setopt($ch, CURLOPT_FILE, $fp);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);

        curl_exec($ch);
        $error = curl_error($ch);
        $errno = curl_errno($ch);
        curl_close($ch);
        fclose($fp);

        if ($errno !== 0) {
            $this->log('ERROR', 'cURL ошибка при скачивании ' . $remoteFileName . ': [' . $errno . '] ' . $error);
            @unlink($localFilePath);
            return false;
        }

        return true;
    }

    /**
     * Перемещение файла в папку Processed/ на SFTP-сервере
     *
     * Использует SFTP-команду rename через cURL QUOTE.
     *
     * @param string $fileName Имя файла для перемещения
     * @return bool true если перемещение успешно
     */
    public function moveToProcessed($fileName)
    {
        // Формируем пути для rename
        $remotePath = $this->config['remote_path'];
        $fromPath = $remotePath . '/' . $fileName;
        $toPath = $remotePath . '/Processed/' . $fileName;

        // Используем CURLOPT_POSTQUOTE — команды выполняются после основной операции
        $url = $this->buildRemoteUrl('/');
        $ch = $this->createCurlHandle($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_NOBODY, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);

        // Команда rename через QUOTE
        curl_setopt($ch, CURLOPT_QUOTE, array(
            'rename "' . $fromPath . '" "' . $toPath . '"'
        ));

        curl_exec($ch);
        $error = curl_error($ch);
        $errno = curl_errno($ch);
        curl_close($ch);

        if ($errno !== 0) {
            $this->log('ERROR', 'cURL ошибка при перемещении ' . $fileName . ': [' . $errno . '] ' . $error);
            return false;
        }

        return true;
    }

    /**
     * Формирование полного URL для SFTP-ресурса
     * 
     * Относительный путь: используем ~/path (домашняя папка пользователя)
     *
     * @param string $suffix Суффикс пути (например, '/file.xml')
     * @return string Полный SFTP URL
     */
    private function buildRemoteUrl($suffix)
    {
        // Для относительного пути используем формат:
        // sftp://user@host:port/~/remote_path/suffix
        // Символ ~ означает домашнюю директорию пользователя
        $remotePath = trim($this->config['remote_path'], '/');
        $suffix = ltrim($suffix, '/');

        $url = $this->baseUrl . '/~/' . $remotePath . '/';
        if (!empty($suffix)) {
            $url .= $suffix;
        }

        return $url;
    }

    /**
     * Создание настроенного cURL-хэндла для SFTP
     *
     * @param string $url SFTP URL
     * @return resource cURL handle
     */
    private function createCurlHandle($url)
    {
        $ch = curl_init();

        curl_setopt($ch, CURLOPT_URL, $url);

        // Аутентификация по паролю
        curl_setopt($ch, CURLOPT_USERPWD, $this->config['login'] . ':' . $this->config['password']);

        // Отключаем проверку host key (внутренняя сеть)
        curl_setopt($ch, CURLOPT_SSH_HOST_PUBLIC_KEY_MD5, '');

        // Разрешаем все методы аутентификации SSH
        if (defined('CURLSSH_AUTH_PASSWORD')) {
            curl_setopt($ch, CURLOPT_SSH_AUTH_TYPES, CURLSSH_AUTH_PASSWORD);
        }

        // Отключаем проверку known_hosts
        // Значение 1 = CURLKHSTAT_FINE_ADD_TO_FILE (принять и запомнить)
        if (defined('CURLOPT_SSH_KNOWNHOSTS')) {
            curl_setopt($ch, CURLOPT_SSH_KNOWNHOSTS, '/dev/null');
        }

        return $ch;
    }

    /**
     * Запись в лог-файл
     *
     * @param string $level Уровень (INFO, WARNING, ERROR, SUCCESS)
     * @param string $message Сообщение
     */
    private function log($level, $message)
    {
        $timestamp = date('Y-m-d H:i:s');
        $line = '[' . $timestamp . '] [' . $level . '] ' . $message . "\n";

        // Ротация лога (5 МБ)
        if (file_exists($this->logFile) && filesize($this->logFile) > 5 * 1024 * 1024) {
            @rename($this->logFile, $this->logFile . '.old');
        }

        // Создаём папку если нет
        $logDir = dirname($this->logFile);
        if (!is_dir($logDir)) {
            @mkdir($logDir, 0755, true);
        }

        file_put_contents($this->logFile, $line, FILE_APPEND | LOCK_EX);
    }

    /**
     * Форматирование размера файла в человекочитаемый вид
     *
     * @param int $bytes Размер в байтах
     * @return string Например: "12.5 KB"
     */
    private function formatFileSize($bytes)
    {
        if ($bytes >= 1048576) {
            return round($bytes / 1048576, 1) . ' MB';
        }
        if ($bytes >= 1024) {
            return round($bytes / 1024, 1) . ' KB';
        }
        return $bytes . ' B';
    }
}