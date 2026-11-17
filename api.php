<?php
/**
 * ============================================================
 * API-ЭНДПОИНТ ДЛЯ ВЕБ-ИНТЕРФЕЙСА
 * ============================================================
 * 
 * Этот файл обрабатывает AJAX-запросы от веб-страницы.
 * Все запросы приходят с параметром "action", который определяет,
 * что именно нужно сделать.
 * 
 * Доступные действия:
 * 
 * - action=logs         — чанк app.log (GET: offset, limit, channel=files|runs|service)
 * - action=run          — запустить обработку файлов вручную (POST)
 * - action=settings     — получить настройки (GET) или сохранить (POST)
 * - action=clear_logs   — очистить файл логов (POST)
 * - action=clear_json   — удалить все JSON-файлы из json/ и json_api/ (POST)
 * - action=resend       — повторная отправка JSON в API 1С (POST: file, source=json|api)
 * - action=api_json     — payload в формате 1С из json_api/ (GET: file)
 * - action=save_api_json — сохранить отредактированный payload (POST: file, content)
 * - action=data_rows    — порция строк таблицы по поставщику (GET: supplier, offset, limit, sort, dir)
 * - action=import_references — импорт справочников: multipart .txt и/или папка references/import/ (POST, сессия Setup)
 * - action=setup_get    — поставщики и галки отправки в 1С (GET, только из setup.php)
 * - action=setup_save   — сохранение галок отправки в 1С (POST, только из setup.php)
 * - action=setup_logs   — журнал настроек: строки app.log по импорту справочников,
 *                         сохранению галок и пропускам отправки (GET, только из setup.php)
 * - action=setup_diagnostics — сеть (1С, SFTP, SmartTravel) и права записи (GET, только из setup.php)
 * - action=setup_export_data — ZIP с данными сервера для ПК (GET mode=data|full, только из setup.php)
 * 
 * Все ответы возвращаются в формате JSON.
 * 
 * ============================================================
 */

// Определяем корневую папку проекта
define('BASE_DIR', __DIR__);

// Подключаем модуль обработки (он содержит функцию runProcessing)
require_once BASE_DIR . '/process.php';
require_once BASE_DIR . '/core/Logger.php';

// Устанавливаем заголовки для JSON-ответа
header('Content-Type: application/json; charset=utf-8');

// Разрешаем запросы с той же страницы (защита от CORS-ошибок)
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST');
header('Access-Control-Allow-Headers: Content-Type');

// Получаем действие из параметра запроса
$action = isset($_GET['action']) ? $_GET['action'] : '';

// Определяем метод HTTP-запроса (GET или POST)
$method = $_SERVER['REQUEST_METHOD'];

// Путь к файлу настроек
$configFile = BASE_DIR . '/config/settings.json';

// Создаём логгер для чтения логов
$logger = new Logger(BASE_DIR . '/logs/app.log');

// -------------------------------------------------------
// Обрабатываем запрос в зависимости от действия
// -------------------------------------------------------

switch ($action) {

    /**
     * ПОЛУЧЕНИЕ ЛОГОВ
     *
     * Чанк с конца app.log. GET: offset (пропуск с конца, 0=новые), limit (1..500).
     */
    case 'logs':
        $offset = isset($_GET['offset']) ? (int)$_GET['offset'] : 0;
        $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 100;
        $channel = isset($_GET['channel']) ? trim((string)$_GET['channel']) : '';
        if ($channel !== '') {
            $allowedChannels = array('files' => true, 'runs' => true, 'service' => true);
            if (!isset($allowedChannels[$channel])) {
                http_response_code(400);
                echo json_encode(array(
                    'status' => 'error',
                    'message' => 'Неизвестный channel (files|runs|service)'
                ), JSON_UNESCAPED_UNICODE);
                break;
            }
            $chunk = $logger->getChannelLinesChunk($channel, $offset, $limit);
            echo json_encode(array(
                'status'   => 'ok',
                'channel'  => $channel,
                'logs'     => $chunk['lines'],
                'offset'   => $chunk['offset'],
                'limit'    => $chunk['limit'],
                'has_more' => $chunk['has_more'],
                'scanned'  => isset($chunk['scanned']) ? $chunk['scanned'] : 0
            ), JSON_UNESCAPED_UNICODE);
            break;
        }
        // Без channel — прежнее поведение (сырой чанк), на случай внешних вызовов
        $chunk = $logger->getLinesChunk($offset, $limit);
        echo json_encode(array(
            'status'   => 'ok',
            'logs'     => $chunk['lines'],
            'offset'   => $chunk['offset'],
            'limit'    => $chunk['limit'],
            'has_more' => $chunk['has_more']
        ), JSON_UNESCAPED_UNICODE);
        break;

    /**
     * ЗАПУСК ОБРАБОТКИ ВРУЧНУЮ
     * 
     * Запускает обработку файлов немедленно (без проверки интервала).
     * Принимает только POST-запросы для безопасности.
     */
    case 'run':
        if ($method !== 'POST') {
            http_response_code(405);
            echo json_encode(array(
                'status' => 'error',
                'message' => 'Требуется POST-запрос'
            ), JSON_UNESCAPED_UNICODE);
            break;
        }

        // Запускаем обработку принудительно (force = true)
        $result = runProcessing(true);
        echo json_encode($result, JSON_UNESCAPED_UNICODE);
        break;

    /**
     * НАСТРОЙКИ
     * 
     * GET  — возвращает текущие настройки (интервал обработки и т.д.)
     * POST — сохраняет новые настройки
     */
    case 'settings':
        if ($method === 'GET') {
            // Читаем текущие настройки из файла
            $settings = array('interval' => 60, 'last_run' => 0);
            if (file_exists($configFile)) {
                $content = file_get_contents($configFile);
                $decoded = json_decode($content, true);
                if (is_array($decoded)) {
                    $settings = array_merge($settings, $decoded);
                }
            }
            echo json_encode(array(
                'status'   => 'ok',
                'settings' => $settings
            ), JSON_UNESCAPED_UNICODE);

        } elseif ($method === 'POST') {
            // Получаем данные из тела запроса (JSON)
            $input = json_decode(file_get_contents('php://input'), true);

            if (!is_array($input)) {
                http_response_code(400);
                echo json_encode(array(
                    'status' => 'error',
                    'message' => 'Неверный формат данных'
                ), JSON_UNESCAPED_UNICODE);
                break;
            }

            // Читаем текущие настройки
            $settings = array('interval' => 60, 'last_run' => 0);
            if (file_exists($configFile)) {
                $content = file_get_contents($configFile);
                $decoded = json_decode($content, true);
                if (is_array($decoded)) {
                    $settings = array_merge($settings, $decoded);
                }
            }

            // Обновляем интервал, если он передан
            if (isset($input['interval'])) {
                $interval = (int)$input['interval'];
                // Минимальный интервал — 10 секунд, максимальный — 86400 (сутки)
                $settings['interval'] = max(10, min(86400, $interval));
            }

            // Порядок колонок таблицы data.php (только визуал, JSON не меняется)
            if (isset($input['data_column_order']) && is_array($input['data_column_order'])) {
                $settings['data_column_order'] = array_values($input['data_column_order']);
            }

            // Сохраняем настройки в файл
            $configDir = dirname($configFile);
            Utils::ensureDirectory($configDir);
            file_put_contents(
                $configFile,
                json_encode($settings, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE),
                LOCK_EX
            );
            Utils::ensureOwnership($configFile);

            $logger->info("Настройки обновлены: интервал = {$settings['interval']} сек.");

            echo json_encode(array(
                'status'   => 'ok',
                'settings' => $settings,
                'message'  => 'Настройки сохранены'
            ), JSON_UNESCAPED_UNICODE);
        }
        break;

    /**
     * ОЧИСТКА ЛОГОВ
     * 
     * Удаляет все записи из файла логов.
     * Принимает только POST-запросы.
     */
    case 'clear_logs':
        if ($method !== 'POST') {
            http_response_code(405);
            echo json_encode(array(
                'status' => 'error',
                'message' => 'Требуется POST-запрос'
            ), JSON_UNESCAPED_UNICODE);
            break;
        }

        $logger->clear();
        echo json_encode(array(
            'status' => 'ok',
            'message' => 'Логи очищены'
        ), JSON_UNESCAPED_UNICODE);
        break;

    /**
     * УДАЛЕНИЕ ВСЕХ JSON-ФАЙЛОВ
     *
     * Удаляет все *.json файлы из папки json/.
     * Принимает только POST-запросы.
     */
    case 'clear_json':
        if ($method !== 'POST') {
            http_response_code(405);
            echo json_encode(array(
                'status' => 'error',
                'message' => 'Требуется POST-запрос'
            ), JSON_UNESCAPED_UNICODE);
            break;
        }

        // json/ — заказы для таблицы, json_api/ — их копии в формате 1С.
        // Считаем только заказы, чтобы число совпадало со строками таблицы
        $deleted = 0;
        foreach (array(BASE_DIR . '/json', BASE_DIR . '/json_api') as $jsonDir) {
            if (!is_dir($jsonDir)) {
                continue;
            }
            $isOrdersDir = (basename($jsonDir) === 'json');
            foreach (glob($jsonDir . '/*.json') as $file) {
                if (is_file($file) && unlink($file) && $isOrdersDir) {
                    $deleted++;
                }
            }
        }

        echo json_encode(array(
            'status' => 'ok',
            'message' => "Удалено файлов: {$deleted}",
            'deleted' => $deleted
        ), JSON_UNESCAPED_UNICODE);
        break;

    /**
     * ПОВТОРНАЯ ОТПРАВКА JSON В API 1С
     *
     * source = json (по умолчанию) — берётся заказ из json/, payload собирается
     * заново и обновляется копия в json_api/. Кнопка 🔄 на странице data.php.
     * source = api — отправляется json_api/ как есть (то, что видно и правится
     * в окне «JSON для 1С»); prepareForApi() на готовом payload идемпотентен.
     */
    case 'resend':
        if ($method !== 'POST') {
            http_response_code(405);
            echo json_encode(array(
                'status' => 'error',
                'message' => 'Требуется POST-запрос'
            ), JSON_UNESCAPED_UNICODE);
            break;
        }

        $input = json_decode(file_get_contents('php://input'), true);
        $jsonFile = isset($input['file']) ? $input['file'] : '';
        $fromApiDir = (isset($input['source']) && $input['source'] === 'api');

        if (empty($jsonFile) || !preg_match('/^[\w\-\.]+\.json$/', $jsonFile)) {
            http_response_code(400);
            echo json_encode(array(
                'status' => 'error',
                'message' => 'Некорректное имя файла'
            ), JSON_UNESCAPED_UNICODE);
            break;
        }

        $jsonPath = BASE_DIR . ($fromApiDir ? '/json_api/' : '/json/') . $jsonFile;
        if (!file_exists($jsonPath)) {
            http_response_code(404);
            echo json_encode(array(
                'status' => 'error',
                'message' => 'Файл не найден: ' . $jsonFile
            ), JSON_UNESCAPED_UNICODE);
            break;
        }

        $orderData = json_decode(file_get_contents($jsonPath), true);
        if (!is_array($orderData)) {
            http_response_code(400);
            echo json_encode(array(
                'status' => 'error',
                'message' => 'Невалидный JSON'
            ), JSON_UNESCAPED_UNICODE);
            break;
        }

        require_once BASE_DIR . '/core/ApiSender.php';
        require_once BASE_DIR . '/core/SendPolicy.php';
        $resendSettings = json_decode(file_get_contents($configFile), true);
        $apiConfig = isset($resendSettings['api']) ? $resendSettings['api'] : array();
        $apiSender = new ApiSender($apiConfig, BASE_DIR . '/logs/api_send.log', BASE_DIR . '/json_api');

        // Отправка из json/ — копия в json_api/ должна показывать последнее
        // отправленное; отредактированный payload перезаписывать не нужно
        if (!$fromApiDir) {
            $apiSender->writeApiPayload($orderData, $jsonFile);
        }

        $sourceXml = isset($orderData['SOURCE_FILE']) ? $orderData['SOURCE_FILE'] : '';

        // Ручная переотправка тоже подчиняется галкам поставщика (страница Setup).
        // Папка поставщика в заказе не хранится — определяем её по имени файла
        $resendPolicy = new SendPolicy(
            isset($resendSettings['setup']) ? $resendSettings['setup'] : array()
        );
        $resendFolder = $resendPolicy->detectFolder($jsonFile);
        $resendCheck = $resendPolicy->checkOrder($resendFolder, $orderData);

        if (!$resendCheck['allowed']) {
            $skipMessage = "Отправка отключена для типа «{$resendCheck['blocked_status']}»"
                . " (поставщик {$resendFolder}). Включите её на странице Setup.";
            $apiSender->logSkipped($jsonFile, $sourceXml, $skipMessage);
            echo json_encode(array(
                'status' => 'error',
                'message' => $skipMessage,
                'http_code' => null
            ), JSON_UNESCAPED_UNICODE);
            break;
        }

        $sendResult = $apiSender->send($orderData, $jsonFile, $sourceXml);

        echo json_encode(array(
            'status' => $sendResult['success'] ? 'ok' : 'error',
            'message' => $sendResult['message'],
            'http_code' => $sendResult['http_code']
        ), JSON_UNESCAPED_UNICODE);
        break;

    /**
     * JSON В ФОРМАТЕ 1С (окно «JSON для 1С» на странице data.php)
     *
     * GET  ?file=… — отдаёт json_api/<file>. Для заказов, обработанных до
     *                появления папки, payload собирается из json/<file>.
     * POST {file, content} — сохраняет отредактированный payload.
     */
    case 'api_json':
        $jsonFile = isset($_GET['file']) ? $_GET['file'] : '';
        if (empty($jsonFile) || !preg_match('/^[\w\-\.]+\.json$/', $jsonFile)) {
            http_response_code(400);
            echo json_encode(array('status' => 'error', 'message' => 'Некорректное имя файла'), JSON_UNESCAPED_UNICODE);
            break;
        }

        $apiJsonPath = BASE_DIR . '/json_api/' . $jsonFile;
        if (file_exists($apiJsonPath)) {
            echo json_encode(array(
                'status'  => 'ok',
                'file'    => $jsonFile,
                'content' => file_get_contents($apiJsonPath)
            ), JSON_UNESCAPED_UNICODE);
            break;
        }

        $orderPath = BASE_DIR . '/json/' . $jsonFile;
        if (!file_exists($orderPath)) {
            http_response_code(404);
            echo json_encode(array('status' => 'error', 'message' => 'Файл не найден: ' . $jsonFile), JSON_UNESCAPED_UNICODE);
            break;
        }

        $orderData = json_decode(file_get_contents($orderPath), true);
        if (!is_array($orderData)) {
            http_response_code(400);
            echo json_encode(array('status' => 'error', 'message' => 'Невалидный JSON заказа'), JSON_UNESCAPED_UNICODE);
            break;
        }

        require_once BASE_DIR . '/core/ApiSender.php';
        $apiJsonSettings = json_decode(file_get_contents($configFile), true);
        $apiConfig = isset($apiJsonSettings['api']) ? $apiJsonSettings['api'] : array();
        $apiSender = new ApiSender($apiConfig, BASE_DIR . '/logs/api_send.log', BASE_DIR . '/json_api');
        $apiSender->writeApiPayload($orderData, $jsonFile);

        if (!file_exists($apiJsonPath)) {
            http_response_code(500);
            echo json_encode(array('status' => 'error', 'message' => 'Не удалось создать payload для ' . $jsonFile), JSON_UNESCAPED_UNICODE);
            break;
        }

        echo json_encode(array(
            'status'  => 'ok',
            'file'    => $jsonFile,
            'content' => file_get_contents($apiJsonPath)
        ), JSON_UNESCAPED_UNICODE);
        break;

    /**
     * СОХРАНЕНИЕ ОТРЕДАКТИРОВАННОГО JSON ДЛЯ 1С
     */
    case 'save_api_json':
        if ($method !== 'POST') {
            http_response_code(405);
            echo json_encode(array('status' => 'error', 'message' => 'Требуется POST-запрос'), JSON_UNESCAPED_UNICODE);
            break;
        }

        $input = json_decode(file_get_contents('php://input'), true);
        $jsonFile = isset($input['file']) ? $input['file'] : '';
        $content = isset($input['content']) ? (string)$input['content'] : '';

        if (empty($jsonFile) || !preg_match('/^[\w\-\.]+\.json$/', $jsonFile)) {
            http_response_code(400);
            echo json_encode(array('status' => 'error', 'message' => 'Некорректное имя файла'), JSON_UNESCAPED_UNICODE);
            break;
        }

        $decoded = json_decode($content, true);
        if (!is_array($decoded)) {
            http_response_code(400);
            echo json_encode(array(
                'status' => 'error',
                'message' => 'JSON не разобран: ' . json_last_error_msg()
            ), JSON_UNESCAPED_UNICODE);
            break;
        }

        $apiJsonDir = BASE_DIR . '/json_api';
        Utils::ensureDirectory($apiJsonDir);
        $apiJsonPath = $apiJsonDir . '/' . $jsonFile;

        // Перезаписываем нормализованным JSON: отступы и кириллица как у парсера
        $normalized = json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (file_put_contents($apiJsonPath, $normalized, LOCK_EX) === false) {
            http_response_code(500);
            echo json_encode(array('status' => 'error', 'message' => 'Не удалось записать ' . $jsonFile), JSON_UNESCAPED_UNICODE);
            break;
        }
        Utils::ensureOwnership($apiJsonPath);

        echo json_encode(array(
            'status'  => 'ok',
            'message' => 'Сохранено',
            'content' => $normalized
        ), JSON_UNESCAPED_UNICODE);
        break;

    /**
     * ПОРЦИЯ СТРОК ТАБЛИЦЫ ДАННЫХ (для вкладок и «Загрузить ещё»)
     * GET: supplier (обязательно), offset (0), limit (50), sort (parsed_at|issue_date), dir (asc|desc)
     */
    case 'data_rows':
        $supplier = isset($_GET['supplier']) ? trim($_GET['supplier']) : '';
        if ($supplier === '' || !preg_match('/^[a-z0-9_]+$/i', $supplier)) {
            http_response_code(400);
            echo json_encode(array('status' => 'error', 'message' => 'Некорректный параметр supplier'), JSON_UNESCAPED_UNICODE);
            break;
        }
        $offset = isset($_GET['offset']) ? (int)$_GET['offset'] : 0;
        $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 50;
        $limit = max(1, min(100, $limit));
        $sortBy = isset($_GET['sort']) ? $_GET['sort'] : 'parsed_at';
        $sortDir = isset($_GET['dir']) && $_GET['dir'] === 'asc' ? 'asc' : 'desc';
        if (!in_array($sortBy, array('parsed_at', 'issue_date'))) {
            $sortBy = 'parsed_at';
        }

        $jsonDir = BASE_DIR . '/json';
        $allFiles = array();
        if (is_dir($jsonDir)) {
            $allFiles = glob($jsonDir . '/' . $supplier . '_*.json');
            if ($allFiles === false) {
                $allFiles = array();
            }
            usort($allFiles, function ($a, $b) use ($sortDir) {
                $cmp = filemtime($b) - filemtime($a);
                return ($sortDir === 'asc') ? -$cmp : $cmp;
            });
        }
        $totalFiles = count($allFiles);
        $filesSlice = array_slice($allFiles, $offset, $limit);
        $rows = array();
        require_once BASE_DIR . '/core/DataTableHelpers.php';
        foreach ($filesSlice as $filePath) {
            $rows = array_merge($rows, buildRowsFromJsonFile($filePath));
        }
        if ($sortBy === 'issue_date') {
            usort($rows, function ($a, $b) use ($sortDir) {
                $va = isset($a['issue_date_raw']) ? $a['issue_date_raw'] : '00000000000000';
                $vb = isset($b['issue_date_raw']) ? $b['issue_date_raw'] : '00000000000000';
                $cmp = strcmp($va, $vb);
                return ($sortDir === 'asc') ? $cmp : -$cmp;
            });
        } else {
            usort($rows, function ($a, $b) use ($sortDir) {
                $va = isset($a['parsed_at']) ? $a['parsed_at'] : '0000-00-00 00:00:00';
                $vb = isset($b['parsed_at']) ? $b['parsed_at'] : '0000-00-00 00:00:00';
                $cmp = strcmp($va, $vb);
                return ($sortDir === 'asc') ? $cmp : -$cmp;
            });
        }
        $hasMore = ($offset + $limit) < $totalFiles;
        echo json_encode(array(
            'status' => 'ok',
            'rows' => $rows,
            'total_files' => $totalFiles,
            'has_more' => $hasMore,
        ), JSON_UNESCAPED_UNICODE);
        break;

    /**
     * СИНХРОНИЗАЦИЯ СПРАВОЧНИКОВ ВРУЧНУЮ
     *
     * Запускает syncAll() для всех включённых справочников.
     * Принимает только POST-запросы.
     */
    case 'sync_references':
        if ($method !== 'POST') {
            http_response_code(405);
            echo json_encode(array(
                'status' => 'error',
                'message' => 'Требуется POST-запрос'
            ), JSON_UNESCAPED_UNICODE);
            break;
        }

        require_once BASE_DIR . '/core/ReferenceManager.php';
        $refSettingsAll = json_decode(file_get_contents($configFile), true);
        $refConfig = isset($refSettingsAll['references']) ? $refSettingsAll['references'] : array();
        $refManager = new ReferenceManager(BASE_DIR . '/references', $logger, $refConfig);
        $syncResult = $refManager->syncAll();

        echo json_encode(array(
            'status' => 'ok',
            'message' => 'Синхронизация справочников завершена',
            'results' => $syncResult
        ), JSON_UNESCAPED_UNICODE);
        break;

    /**
     * ИМПОРТ СПРАВОЧНИКОВ ИЗ ВЫГРУЗКИ 1С
     *
     * Читает файлы выгрузки из references/import/ и нормализует их
     * в references/*.json. Принимает только POST-запросы.
     * После успешного импорта удаляет *.txt из import/ и пишет
     * references.last_import в settings.json.
     */
    case 'import_references':
        if ($method !== 'POST') {
            http_response_code(405);
            echo json_encode(array(
                'status' => 'error',
                'message' => 'Требуется POST-запрос'
            ), JSON_UNESCAPED_UNICODE);
            break;
        }

        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        if (empty($_SESSION['setup_auth'])) {
            http_response_code(403);
            echo json_encode(array(
                'status' => 'error',
                'message' => 'Нужно войти на странице Setup'
            ), JSON_UNESCAPED_UNICODE);
            break;
        }

        // Сразу в лог — если этой строки нет, клик до PHP не дошёл
        $logger->info('API: запрос import_references');

        // Контрагенты.txt ~25 МБ: без лимита прокси/PHP часто рвут запрос молча
        @set_time_limit(300);
        @ini_set('memory_limit', '512M');

        require_once BASE_DIR . '/core/ReferenceImporter.php';
        $importDir = BASE_DIR . '/references/import';
        Utils::ensureDirectory($importDir);

        // Гибрид: multipart .txt с диска пользователя и/или уже лежащие в import/
        $uploadedCount = 0;
        $uploadErrors = array();
        $uploadMax = ini_get('upload_max_filesize');
        $postMax = ini_get('post_max_size');
        if (!empty($_FILES['files'])) {
            $filesBag = $_FILES['files'];
            // Нормализуем к списку файлов (один или multiple)
            $names = isset($filesBag['name']) ? $filesBag['name'] : array();
            if (!is_array($names)) {
                $names = array($names);
                $filesBag = array(
                    'name' => array($filesBag['name']),
                    'type' => array(isset($filesBag['type']) ? $filesBag['type'] : ''),
                    'tmp_name' => array(isset($filesBag['tmp_name']) ? $filesBag['tmp_name'] : ''),
                    'error' => array(isset($filesBag['error']) ? $filesBag['error'] : UPLOAD_ERR_NO_FILE),
                    'size' => array(isset($filesBag['size']) ? $filesBag['size'] : 0)
                );
            }
            for ($fi = 0; $fi < count($names); $fi++) {
                $err = isset($filesBag['error'][$fi]) ? (int)$filesBag['error'][$fi] : UPLOAD_ERR_NO_FILE;
                if ($err === UPLOAD_ERR_NO_FILE) {
                    continue;
                }
                if ($err === UPLOAD_ERR_INI_SIZE || $err === UPLOAD_ERR_FORM_SIZE) {
                    $uploadErrors[] = basename((string)$names[$fi])
                        . ' превышает лимит PHP (upload_max_filesize='
                        . $uploadMax . ', post_max_size=' . $postMax . ')';
                    continue;
                }
                if ($err !== UPLOAD_ERR_OK) {
                    $uploadErrors[] = basename((string)$names[$fi]) . ' — код ошибки upload ' . $err;
                    continue;
                }
                $base = basename((string)$names[$fi]);
                $ext = strtolower(pathinfo($base, PATHINFO_EXTENSION));
                if ($ext !== 'txt') {
                    $uploadErrors[] = $base . ' — допускается только .txt';
                    continue;
                }
                $dest = $importDir . DIRECTORY_SEPARATOR . $base;
                $tmp = isset($filesBag['tmp_name'][$fi]) ? $filesBag['tmp_name'][$fi] : '';
                if ($tmp === '' || !is_uploaded_file($tmp) || !@move_uploaded_file($tmp, $dest)) {
                    $uploadErrors[] = $base . ' — не удалось сохранить на сервер';
                    continue;
                }
                Utils::ensureOwnership($dest);
                $uploadedCount++;
            }
        }

        if (!empty($uploadErrors) && $uploadedCount === 0) {
            http_response_code(400);
            echo json_encode(array(
                'status' => 'error',
                'message' => implode('; ', $uploadErrors),
                'upload_max_filesize' => $uploadMax,
                'post_max_size' => $postMax
            ), JSON_UNESCAPED_UNICODE);
            break;
        }

        $existingTxt = glob($importDir . DIRECTORY_SEPARATOR . '*.txt');
        if ($uploadedCount === 0 && (empty($existingTxt) || !is_array($existingTxt))) {
            http_response_code(400);
            echo json_encode(array(
                'status' => 'error',
                'message' => 'Нет файлов: выберите .txt с диска или положите выгрузки в references/import/',
                'upload_max_filesize' => $uploadMax,
                'post_max_size' => $postMax
            ), JSON_UNESCAPED_UNICODE);
            break;
        }

        $importer = new ReferenceImporter(
            $importDir,
            BASE_DIR . '/references',
            $logger
        );

        try {
            $importResult = $importer->importAll();
        } catch (Exception $e) {
            $logger->error('API: import_references — ' . $e->getMessage());
            http_response_code(500);
            echo json_encode(array(
                'status' => 'error',
                'message' => 'Ошибка импорта: ' . $e->getMessage()
            ), JSON_UNESCAPED_UNICODE);
            break;
        }

        // Короткая сводка для строки статуса в интерфейсе
        $importedTypes = 0;
        $importedRows = 0;
        $failedTypes = array();
        foreach ($importResult as $refType => $refInfo) {
            if ($refInfo['status'] === 'ok') {
                $importedTypes++;
                $importedRows += $refInfo['count'];
            } elseif ($refInfo['status'] === 'error') {
                $failedTypes[] = $refType;
            }
        }

        $cleaned = 0;
        $lastImport = null;
        if ($importedTypes > 0) {
            // Выгрузки больше не нужны — снимаем, чтобы не импортировать повторно по ошибке
            $cleaned = $importer->cleanupImportFiles();

            $lastImport = date('Y-m-d H:i:s');
            if (file_exists($configFile) && is_readable($configFile)) {
                $settingsData = json_decode(file_get_contents($configFile), true);
                if (!is_array($settingsData)) {
                    $settingsData = array();
                }
                if (!isset($settingsData['references']) || !is_array($settingsData['references'])) {
                    $settingsData['references'] = array();
                }
                $settingsData['references']['last_import'] = $lastImport;
                $encoded = json_encode($settingsData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                if ($encoded !== false && file_put_contents($configFile, $encoded, LOCK_EX) !== false) {
                    Utils::ensureOwnership($configFile);
                } else {
                    $logger->warning('API: import_references — не удалось записать references.last_import');
                }
            }
        }

        $importMessage = "Справочников обновлено: {$importedTypes}, записей: {$importedRows}";
        if ($uploadedCount > 0) {
            $importMessage .= ", загружено с диска: {$uploadedCount}";
        }
        if ($cleaned > 0) {
            $importMessage .= ", удалено файлов выгрузки: {$cleaned}";
        }
        if (!empty($failedTypes)) {
            $importMessage .= '. Ошибки: ' . implode(', ', $failedTypes);
        }
        if (!empty($uploadErrors)) {
            $importMessage .= '. Upload: ' . implode('; ', $uploadErrors);
        }

        echo json_encode(array(
            'status'               => 'ok',
            'message'              => $importMessage,
            'imported'             => $importedTypes,
            'rows'                 => $importedRows,
            'uploaded'             => $uploadedCount,
            'cleaned'              => $cleaned,
            'last_import'          => $lastImport,
            'results'              => $importResult,
            'upload_max_filesize'  => $uploadMax,
            'post_max_size'        => $postMax
        ), JSON_UNESCAPED_UNICODE);
        break;

    /**
     * НАСТРОЙКИ ОТПРАВКИ В 1С ПО ПОСТАВЩИКАМ (страница setup.php)
     *
     * setup_get  (GET)  — список зарегистрированных парсеров и их галки
     * setup_save (POST) — сохранение галок в settings.json и ответ со свежим списком
     *
     * Доступно только при активной сессии setup.php (вход по паролю).
     */
    case 'setup_get':
    case 'setup_save':
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        if (empty($_SESSION['setup_auth'])) {
            http_response_code(403);
            echo json_encode(array(
                'status' => 'error',
                'message' => 'Нужно войти на странице Setup'
            ), JSON_UNESCAPED_UNICODE);
            break;
        }

        require_once BASE_DIR . '/core/ParserManager.php';
        require_once BASE_DIR . '/core/SendPolicy.php';

        $setupSettings = array();
        if (file_exists($configFile) && is_readable($configFile)) {
            $setupSettings = json_decode(file_get_contents($configFile), true);
            if (!is_array($setupSettings)) {
                $setupSettings = array();
            }
        }

        // Список поставщиков берём у менеджера парсеров: новый парсер
        // появляется в настройках сам, руками ничего добавлять не нужно
        $setupParserManager = new ParserManager(BASE_DIR . '/parsers', $logger);
        $setupFolders = $setupParserManager->getRegisteredFolders();

        $setupMessage = '';
        if ($action === 'setup_save') {
            if ($method !== 'POST') {
                http_response_code(405);
                echo json_encode(array(
                    'status' => 'error',
                    'message' => 'Требуется POST-запрос'
                ), JSON_UNESCAPED_UNICODE);
                break;
            }

            $setupInput = json_decode(file_get_contents('php://input'), true);
            $rawSuppliers = (is_array($setupInput) && isset($setupInput['suppliers']))
                ? $setupInput['suppliers']
                : array();

            if (!isset($setupSettings['setup']) || !is_array($setupSettings['setup'])) {
                $setupSettings['setup'] = array();
            }
            $existingSuppliers = (isset($setupSettings['setup']['suppliers'])
                && is_array($setupSettings['setup']['suppliers']))
                ? $setupSettings['setup']['suppliers']
                : array();

            // Что именно поменялось — считаем до перезаписи настроек
            $normalizedSuppliers = SendPolicy::normalizeSuppliers($rawSuppliers, $setupFolders);
            $setupTypeLabels = SendPolicy::getTypeLabels();
            $setupChanges = array();

            foreach ($normalizedSuppliers as $changedFolder => $changedFlags) {
                $parserForLog = $setupParserManager->getParser($changedFolder);
                $supplierTitle = $parserForLog ? $parserForLog->getSupplierName() : $changedFolder;

                foreach ($changedFlags as $changedType => $isEnabled) {
                    // Нет записи в настройках = галка стояла (дефолт SendPolicy)
                    $wasEnabled = isset($existingSuppliers[$changedFolder][$changedType])
                        ? (bool)$existingSuppliers[$changedFolder][$changedType]
                        : true;
                    if ($wasEnabled === (bool)$isEnabled) {
                        continue;
                    }

                    $typeTitle = isset($setupTypeLabels[$changedType])
                        ? $setupTypeLabels[$changedType]
                        : $changedType;
                    $setupChanges[] = $isEnabled
                        ? "Setup: «{$supplierTitle}» — поставлена галка «{$typeTitle}», заказы этого типа отправляются в 1С"
                        : "Setup: «{$supplierTitle}» — снята галка «{$typeTitle}», заказы этого типа в 1С не отправляются";
                }
            }

            // Папки отключённых парсеров не теряем — перекрываем только актуальные
            $setupSettings['setup']['suppliers'] = array_merge($existingSuppliers, $normalizedSuppliers);

            $setupEncoded = json_encode(
                $setupSettings,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            );
            if ($setupEncoded === false
                || file_put_contents($configFile, $setupEncoded, LOCK_EX) === false
            ) {
                $logger->error('API: setup_save — не удалось записать settings.json');
                http_response_code(500);
                echo json_encode(array(
                    'status' => 'error',
                    'message' => 'Не удалось записать config/settings.json'
                ), JSON_UNESCAPED_UNICODE);
                break;
            }

            Utils::ensureOwnership($configFile);

            if (empty($setupChanges)) {
                $logger->info('Setup: настройки отправки сохранены, изменений нет');
            } else {
                foreach ($setupChanges as $changeLine) {
                    $logger->info($changeLine);
                }
            }

            $setupMessage = empty($setupChanges)
                ? 'Настройки сохранены (без изменений)'
                : 'Настройки сохранены, изменений: ' . count($setupChanges);
        }

        $setupPolicy = new SendPolicy(
            isset($setupSettings['setup']) ? $setupSettings['setup'] : array()
        );

        $setupSuppliers = array();
        foreach ($setupFolders as $setupFolder) {
            $setupParser = $setupParserManager->getParser($setupFolder);
            $setupSuppliers[] = array(
                'folder' => $setupFolder,
                'name'   => $setupParser ? $setupParser->getSupplierName() : $setupFolder,
                'flags'  => $setupPolicy->getSupplierFlags($setupFolder)
            );
        }

        echo json_encode(array(
            'status'    => 'ok',
            'message'   => $setupMessage,
            'types'     => SendPolicy::getTypeLabels(),
            'suppliers' => $setupSuppliers
        ), JSON_UNESCAPED_UNICODE);
        break;

    /**
     * ВЫГРУЗКА ДАННЫХ ДЛЯ ПК (страница setup.php)
     *
     * GET mode=data|full (по умолчанию data). Сессия setup, иначе 403.
     * Ответ — ZIP-файл (не JSON).
     * data: refs, settings, json, json_api, последние 100 файлов input
     * full: то же + весь input + logs
     */
    case 'setup_export_data':
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        if (empty($_SESSION['setup_auth'])) {
            http_response_code(403);
            echo json_encode(array(
                'status' => 'error',
                'message' => 'Нужно войти на странице Setup'
            ), JSON_UNESCAPED_UNICODE);
            break;
        }

        @set_time_limit(300);
        @ini_set('memory_limit', '512M');

        $exportMode = isset($_GET['mode']) ? (string)$_GET['mode'] : 'data';
        if ($exportMode !== 'full') {
            $exportMode = 'data';
        }

        require_once BASE_DIR . '/core/SetupDataExport.php';
        try {
            $exporter = new SetupDataExport(BASE_DIR);
            $exportResult = $exporter->build($exportMode);
        } catch (Exception $e) {
            $logger->warning('Setup: выгрузка данных — ' . $e->getMessage());
            http_response_code(500);
            echo json_encode(array(
                'status' => 'error',
                'message' => $e->getMessage()
            ), JSON_UNESCAPED_UNICODE);
            break;
        }

        $sizeKb = round($exportResult['bytes'] / 1024, 1);
        $logger->info(
            'Setup: выгрузка данных для ПК (' . $exportResult['mode'] . ') — '
            . $exportResult['file_count'] . ' файлов, ' . $sizeKb . ' КБ'
        );

        // Сбрасываем JSON-заголовок, отданный в начале api.php
        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="' . $exportResult['filename'] . '"');
        header('Content-Length: ' . $exportResult['bytes']);
        header('Cache-Control: no-store');

        readfile($exportResult['path']);
        @unlink($exportResult['path']);
        exit;

    /**
     * ДИАГНОСТИКА СЕТИ И ПРАВ (страница setup.php)
     *
     * Лёгкие проверки: HEAD к 1С, короткое SFTP-подключение, GET к SmartTravel,
     * пробная запись в рабочие папки. Заказ в 1С не отправляется.
     * GET. Без сессии setup — HTTP 403.
     */
    case 'setup_diagnostics':
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        if (empty($_SESSION['setup_auth'])) {
            http_response_code(403);
            echo json_encode(array(
                'status' => 'error',
                'message' => 'Нужно войти на странице Setup'
            ), JSON_UNESCAPED_UNICODE);
            break;
        }

        $diagSettings = array();
        if (file_exists($configFile) && is_readable($configFile)) {
            $diagSettings = json_decode(file_get_contents($configFile), true);
            if (!is_array($diagSettings)) {
                $diagSettings = array();
            }
        }

        require_once BASE_DIR . '/core/SetupDiagnostics.php';
        $diag = new SetupDiagnostics(BASE_DIR);
        $diagResult = $diag->run($diagSettings);

        $logger->info(
            'Setup: диагностика — OK: ' . $diagResult['ok']
            . ', FAIL: ' . $diagResult['fail']
            . ', SKIP: ' . $diagResult['skip']
        );

        echo json_encode(array(
            'status' => 'ok',
            'checks' => $diagResult['checks'],
            'ok'     => $diagResult['ok'],
            'fail'   => $diagResult['fail'],
            'skip'   => $diagResult['skip']
        ), JSON_UNESCAPED_UNICODE);
        break;

    /**
     * ЖУРНАЛ НАСТРОЕК (страница setup.php)
     *
     * Чанк app.log только со строками по теме настроек: импорт справочников,
     * сохранение галок, пропуски отправки в 1С.
     * GET: offset (пропуск подходящих строк с конца, 0=новые), limit (1..200).
     *
     * Отдельное действие, а не часть блока setup_get/setup_save: там создаётся
     * ParserManager, который на каждом вызове пишет в app.log «Загружен парсер…»,
     * а здесь опрос идёт раз в несколько секунд — журнал засорялся бы сам собой.
     */
    case 'setup_logs':
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        if (empty($_SESSION['setup_auth'])) {
            http_response_code(403);
            echo json_encode(array(
                'status' => 'error',
                'message' => 'Нужно войти на странице Setup'
            ), JSON_UNESCAPED_UNICODE);
            break;
        }

        $setupLogsOffset = isset($_GET['offset']) ? (int)$_GET['offset'] : 0;
        $setupLogsLimit = isset($_GET['limit']) ? (int)$_GET['limit'] : 60;
        $setupLogsLimit = max(1, min(200, $setupLogsLimit));

        // Чем глубже уходим в историю, тем больше строк лога просматриваем
        $setupLogsMaxScan = min(50000, 4000 + $setupLogsOffset * 150);

        $setupLogsChunk = $logger->getFilteredLinesChunk(
            array('setup', 'отправка отключена', 'import_references', 'импорт', 'справочник'),
            $setupLogsOffset,
            $setupLogsLimit,
            $setupLogsMaxScan
        );

        echo json_encode(array(
            'status'   => 'ok',
            'logs'     => $setupLogsChunk['lines'],
            'offset'   => $setupLogsChunk['offset'],
            'limit'    => $setupLogsChunk['limit'],
            'has_more' => $setupLogsChunk['has_more']
        ), JSON_UNESCAPED_UNICODE);
        break;

    /**
     * НЕИЗВЕСТНОЕ ДЕЙСТВИЕ
     */
    default:
        http_response_code(400);
        echo json_encode(array(
            'status' => 'error',
            'message' => 'Неизвестное действие: ' . $action
        ), JSON_UNESCAPED_UNICODE);
        break;
}
