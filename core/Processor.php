<?php
/**
 * ============================================================
 * ОСНОВНОЙ ОБРАБОТЧИК ФАЙЛОВ
 * ============================================================
 * 
 * Этот файл содержит главную логику работы системы:
 * 
 * 1. Обходит папки всех зарегистрированных поставщиков (input/имя_поставщика/)
 * 2. Находит XML-файлы, которые ещё не были обработаны
 * 3. Вызывает соответствующий парсер для каждого файла
 * 4. Сохраняет результат в формате JSON в папку json/
 * 5. Перемещает обработанный XML-файл:
 *    - В подпапку Processed/ — если обработка прошла успешно
 *    - В подпапку Error/ — если произошла ошибка
 * 
 * Также здесь реализована проверка интервала обработки:
 * система запускает обработку только если прошло достаточно
 * времени с момента предыдущего запуска.
 * ============================================================
 */

// Подключаем все необходимые компоненты системы
require_once __DIR__ . '/Logger.php';
require_once __DIR__ . '/ParserManager.php';
require_once __DIR__ . '/ApiSender.php';
require_once __DIR__ . '/Utils.php';

class Processor
{
    /** @var string Путь к папке с входными XML-файлами (input/) */
    private $inputDir;

    /** @var string Путь к папке для выходных JSON-файлов (json/) */
    private $outputDir;

    /** @var string Путь к файлу настроек (config/settings.json) */
    private $configFile;

    /** @var Logger Объект логгера */
    private $logger;

    /** @var ParserManager Менеджер парсеров */
    private $parserManager;

    /** @var ApiSender Отправщик заказов в API 1С */
    private $apiSender;

    /**
     * Создание обработчика.
     * 
     * @param string $inputDir   — путь к папке input/
     * @param string $outputDir  — путь к папке json/
     * @param string $configFile — путь к файлу настроек
     * @param Logger $logger     — объект логгера
     * @param ParserManager $parserManager — менеджер парсеров
     */
    public function __construct($inputDir, $outputDir, $configFile, $logger, $parserManager)
    {
        $this->inputDir = $inputDir;
        $this->outputDir = $outputDir;
        $this->configFile = $configFile;
        $this->logger = $logger;
        $this->parserManager = $parserManager;

        // Создаём папку для JSON, если её ещё нет
        Utils::ensureDirectory($this->outputDir);

        // Инициализируем отправщик в API 1С
        $settings = $this->loadSettings();
        $apiConfig = isset($settings['api']) ? $settings['api'] : array();
        $apiLogFile = dirname($this->configFile) . '/../logs/api_send.log';
        $this->apiSender = new ApiSender($apiConfig, $apiLogFile);
    }

    /**
     * Запуск обработки всех файлов.
     * 
     * Это главный метод, который вызывается при каждом запуске обработки
     * (по таймеру, через cron или вручную).
     * 
     * @param bool $force — если true, обработка запускается принудительно,
     *                       без проверки интервала (для ручного запуска)
     * @return array — результат обработки: количество обработанных файлов и ошибок
     */
    public function run($force = false)
    {
        // Проверяем, прошёл ли нужный интервал с момента последней обработки
        if (!$force && !$this->isIntervalPassed()) {
            return array(
                'status' => 'skipped',
                'message' => 'Интервал обработки ещё не прошёл'
            );
        }

        $this->logger->info('========== Начата обработка файлов ==========');

        // Счётчики для итогового отчёта
        $totalProcessed = 0;
        $totalErrors = 0;
        $totalFiles = 0;

        // Получаем список всех зарегистрированных папок поставщиков
        $folders = $this->parserManager->getRegisteredFolders();

        if (empty($folders)) {
            $this->logger->warning('Нет зарегистрированных парсеров — нечего обрабатывать');
            $this->updateLastRunTime();
            return array(
                'status' => 'ok',
                'processed' => 0,
                'errors' => 0,
                'total' => 0
            );
        }
        
        $apiAvailable = $this->apiSender->isAvailable();
        if (!$apiAvailable) {
            $this->logger->info('API 1С недоступен — отправка пропущена');
        }

        // Обходим папку каждого поставщика
        foreach ($folders as $folder) {
            $supplierDir = $this->inputDir . DIRECTORY_SEPARATOR . $folder;

            // Проверяем, существует ли папка поставщика
            if (!is_dir($supplierDir)) {
                $this->logger->warning("Папка поставщика не найдена: {$supplierDir}");
                continue;
            }

            // Создаём подпапки Processed и Error, если их нет
            $this->ensureSubfolders($supplierDir);

            // Получаем парсер для этого поставщика
            $parser = $this->parserManager->getParser($folder);
            if ($parser === null) {
                $this->logger->error("Парсер для папки '{$folder}' не найден");
                continue;
            }

            // Ищем все файлы в папке поставщика (XML + JSON, только в корне)
            $xmlFiles = glob($supplierDir . DIRECTORY_SEPARATOR . '*.xml');
            $jsonInputFiles = glob($supplierDir . DIRECTORY_SEPARATOR . '*.json');
            $allInputFiles = array_merge(
                is_array($xmlFiles) ? $xmlFiles : array(),
                is_array($jsonInputFiles) ? $jsonInputFiles : array()
            );

            if (empty($allInputFiles)) {
                $this->logger->info("Поставщик \"{$parser->getSupplierName()}\": нет новых файлов");
                continue;
            }

            $this->logger->info(
                "Поставщик \"{$parser->getSupplierName()}\": найдено " 
                . count($allInputFiles) . " файл(ов)"
            );

            // Обрабатываем каждый файл
            foreach ($allInputFiles as $xmlFile) {
                $totalFiles++;
                $fileName = basename($xmlFile);

                try {
                    $this->logger->info("Обработка файла: {$fileName}");

                    $t0 = microtime(true);
                    $orderData = $parser->parse($xmlFile);
                    $t1 = microtime(true);
                    $this->logger->info("Время парсинга {$fileName}: " . round($t1 - $t0, 2) . " с");

                    // Один ORDER (есть UID) или массив ORDER-ов (PULL-режим SmartTravel)
                    if (isset($orderData['UID'])) {
                        $ordersList = array($orderData);
                    } else {
                        $ordersList = array_values($orderData);
                    }

                    $savedJsonFiles = array();
                    foreach ($ordersList as $singleOrder) {
                        $jsonFileName = $this->saveJson($singleOrder, $fileName, $folder);
                        $savedJsonFiles[] = $jsonFileName;

                        if ($apiAvailable) {
                            try {
                                $apiResult = $this->apiSender->send($singleOrder, $jsonFileName, $fileName);
                                if ($apiResult['success']) {
                                    $this->logger->info("API 1С: {$apiResult['message']}");
                                } else {
                                    $this->logger->warning("API 1С: {$apiResult['message']}");
                                }
                            } catch (Exception $apiEx) {
                                $this->logger->warning(
                                    "API 1С: исключение при отправке {$jsonFileName}: " . $apiEx->getMessage()
                                );
                            }
                        }
                    }

                    $this->moveFile(
                        $xmlFile,
                        $supplierDir . DIRECTORY_SEPARATOR . 'Processed' . DIRECTORY_SEPARATOR . $fileName
                    );

                    $jsonList = implode(', ', $savedJsonFiles);
                    $this->logger->success(
                        "Файл {$fileName} успешно обработан -> JSON: {$jsonList}"
                    );
                    $totalProcessed++;
                } catch (Exception $e) {
                    // Если произошла ошибка — перемещаем файл в папку Error
                    $this->logger->error(
                        "Ошибка при обработке файла {$fileName}: " . $e->getMessage()
                    );

                    $this->moveFile(
                        $xmlFile,
                        $supplierDir . DIRECTORY_SEPARATOR . 'Error' . DIRECTORY_SEPARATOR . $fileName
                    );

                    $totalErrors++;
                }
            }
        }

        // Обновляем время последнего запуска
        $this->updateLastRunTime();

        $this->logger->info(
            "========== Обработка завершена: обработано {$totalProcessed}, "
            . "ошибок {$totalErrors}, всего файлов {$totalFiles} =========="
        );

        return array(
            'status' => 'ok',
            'processed' => $totalProcessed,
            'errors' => $totalErrors,
            'total' => $totalFiles
        );
    }

    /**
     * Обработка одного файла (для webhook и PullSync).
     *
     * @param string $filePath — полный путь к файлу
     * @param string $folder   — имя папки поставщика (e.g. 'smarttravel')
     * @return array — processed (int), errors (int), json_files (array)
     */
    public function processSingleFile($filePath, $folder)
    {
        $result = array('processed' => 0, 'errors' => 0, 'json_files' => array());
        $fileName = basename($filePath);
        $supplierDir = $this->inputDir . DIRECTORY_SEPARATOR . $folder;

        $this->ensureSubfolders($supplierDir);

        $parser = $this->parserManager->getParser($folder);
        if ($parser === null) {
            $this->logger->error("processSingleFile: парсер для '{$folder}' не найден");
            $result['errors'] = 1;
            return $result;
        }

        $apiAvailable = $this->apiSender->isAvailable();

        try {
            $this->logger->info("Обработка файла (single): {$fileName}");
            $orderData = $parser->parse($filePath);

            if (isset($orderData['UID'])) {
                $ordersList = array($orderData);
            } else {
                $ordersList = array_values($orderData);
            }

            foreach ($ordersList as $singleOrder) {
                $jsonFileName = $this->saveJson($singleOrder, $fileName, $folder);
                $result['json_files'][] = $jsonFileName;

                if ($apiAvailable) {
                    try {
                        $this->apiSender->send($singleOrder, $jsonFileName, $fileName);
                    } catch (Exception $apiEx) {
                        $this->logger->warning("API 1С (single): " . $apiEx->getMessage());
                    }
                }
            }

            $this->moveFile(
                $filePath,
                $supplierDir . DIRECTORY_SEPARATOR . 'Processed' . DIRECTORY_SEPARATOR . $fileName
            );

            $result['processed'] = count($ordersList);
            $this->logger->success("processSingleFile: {$fileName} -> " . count($ordersList) . " заказ(ов)");

        } catch (Exception $e) {
            $this->logger->error("processSingleFile: ошибка {$fileName}: " . $e->getMessage());
            $this->moveFile(
                $filePath,
                $supplierDir . DIRECTORY_SEPARATOR . 'Error' . DIRECTORY_SEPARATOR . $fileName
            );
            $result['errors'] = 1;
        }

        return $result;
    }

    /**
     * Сохраняет данные заказа в JSON-файл.
     * 
     * JSON-файл сохраняется в папку json/ с именем, основанным на
     * имени исходного XML-файла и текущем времени.
     * 
     * @param array  $data     — данные заказа в формате ORDER
     * @param string $sourceFileName — имя исходного XML-файла
     * @param string $folder   — имя папки поставщика
     * @return string — имя созданного JSON-файла
     * @throws Exception — если не удалось сохранить файл
     */
    private function saveJson($data, $sourceFileName, $folder)
    {
        // Формируем имя JSON-файла: поставщик_исходноеимя_дата.json
        $baseName = pathinfo($sourceFileName, PATHINFO_FILENAME);
        $timestamp = date('Ymd_His');
        $jsonFileName = "{$folder}_{$baseName}_{$timestamp}.json";
        $jsonFilePath = $this->outputDir . DIRECTORY_SEPARATOR . $jsonFileName;

        // Преобразуем массив PHP в красиво отформатированный JSON
        // JSON_PRETTY_PRINT — делает JSON читаемым (с отступами)
        // JSON_UNESCAPED_UNICODE — сохраняет кириллицу без кодирования
        // JSON_UNESCAPED_SLASHES — не экранирует слеши
        $jsonContent = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if ($jsonContent === false) {
            throw new Exception("Не удалось преобразовать данные в JSON: " . json_last_error_msg());
        }

        // Записываем JSON в файл
        $result = file_put_contents($jsonFilePath, $jsonContent, LOCK_EX);

        if ($result === false) {
            throw new Exception("Не удалось сохранить JSON-файл: {$jsonFilePath}");
        }

        Utils::ensureOwnership($jsonFilePath);

        return $jsonFileName;
    }

    /**
     * Перемещает файл из одного места в другое.
     * 
     * Если файл с таким именем уже есть в целевой папке,
     * к имени добавляется временная метка, чтобы не потерять данные.
     * 
     * @param string $source      — откуда переместить
     * @param string $destination — куда переместить
     */
    private function moveFile($source, $destination)
    {
        // Если файл с таким именем уже существует — добавляем к имени дату
        if (file_exists($destination)) {
            $pathInfo = pathinfo($destination);
            $timestamp = date('Ymd_His');
            $destination = $pathInfo['dirname'] . DIRECTORY_SEPARATOR 
                         . $pathInfo['filename'] . "_{$timestamp}." . $pathInfo['extension'];
        }

        // Перемещаем файл
        if (!rename($source, $destination)) {
            $this->logger->error(
                "Не удалось переместить файл: {$source} -> {$destination}"
            );
        } else {
            Utils::ensureOwnership($destination);
        }
    }

    /**
     * Создаёт подпапки Processed и Error внутри папки поставщика,
     * если они ещё не существуют.
     * 
     * @param string $supplierDir — путь к папке поставщика
     */
    private function ensureSubfolders($supplierDir)
    {
        $processed = $supplierDir . DIRECTORY_SEPARATOR . 'Processed';
        $error = $supplierDir . DIRECTORY_SEPARATOR . 'Error';

        Utils::ensureDirectory($processed);
        Utils::ensureDirectory($error);
    }

    /**
     * Проверяет, прошёл ли заданный интервал с момента последней обработки.
     * 
     * Читает время последнего запуска и интервал из файла настроек.
     * Если прошло достаточно времени — возвращает true.
     * 
     * @return bool — true, если можно запускать обработку
     */
    private function isIntervalPassed()
    {
        $settings = $this->loadSettings();

        // Интервал в секундах (по умолчанию 60 секунд = 1 минута)
        $interval = isset($settings['interval']) ? (int)$settings['interval'] : 60;

        // Время последнего запуска (0 = никогда не запускалась)
        $lastRun = isset($settings['last_run']) ? (int)$settings['last_run'] : 0;

        // Текущее время минус время последнего запуска >= интервал
        return (time() - $lastRun) >= $interval;
    }

    /**
     * Обновляет время последнего запуска в файле настроек.
     */
    private function updateLastRunTime()
    {
        $settings = $this->loadSettings();
        $settings['last_run'] = time();
        $this->saveSettings($settings);
    }

    /**
     * Читает настройки из JSON-файла конфигурации.
     * 
     * @return array — массив настроек
     */
    private function loadSettings()
    {
        if (!file_exists($this->configFile)) {
            return array('interval' => 60, 'last_run' => 0);
        }

        $content = file_get_contents($this->configFile);
        $settings = json_decode($content, true);

        if (!is_array($settings)) {
            return array('interval' => 60, 'last_run' => 0);
        }

        return $settings;
    }

    /**
     * Сохраняет настройки в JSON-файл конфигурации.
     * 
     * @param array $settings — массив настроек
     */
    private function saveSettings($settings)
    {
        $dir = dirname($this->configFile);
        Utils::ensureDirectory($dir);

        file_put_contents(
            $this->configFile,
            json_encode($settings, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE),
            LOCK_EX
        );
        Utils::ensureOwnership($this->configFile);
    }
}
