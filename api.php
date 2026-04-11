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
 * - action=logs         — получить последние записи лога (GET)
 * - action=run          — запустить обработку файлов вручную (POST)
 * - action=settings     — получить настройки (GET) или сохранить (POST)
 * - action=clear_logs   — очистить файл логов (POST)
 * - action=clear_json   — удалить все JSON-файлы из json/ (POST)
 * - action=resend       — повторная отправка JSON в API 1С (POST)
 * - action=data_rows    — порция строк таблицы по поставщику (GET: supplier, offset, limit, sort, dir)
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
     * Возвращает последние 200 строк из файла логов.
     * Используется для отображения на веб-странице.
     */
    case 'logs':
        $lines = $logger->getLastLines(200);
        echo json_encode(array(
            'status' => 'ok',
            'logs'   => $lines
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

        $jsonDir = BASE_DIR . '/json';
        $deleted = 0;
        if (is_dir($jsonDir)) {
            $files = glob($jsonDir . '/*.json');
            foreach ($files as $file) {
                if (is_file($file) && unlink($file)) {
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
     * Читает ранее сохранённый JSON-файл из json/ и отправляет
     * его повторно в API 1С. Используется кнопкой 🔄 на странице data.php.
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

        if (empty($jsonFile) || !preg_match('/^[\w\-\.]+\.json$/', $jsonFile)) {
            http_response_code(400);
            echo json_encode(array(
                'status' => 'error',
                'message' => 'Некорректное имя файла'
            ), JSON_UNESCAPED_UNICODE);
            break;
        }

        $jsonPath = BASE_DIR . '/json/' . $jsonFile;
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
        $resendSettings = json_decode(file_get_contents($configFile), true);
        $apiConfig = isset($resendSettings['api']) ? $resendSettings['api'] : array();
        $apiSender = new ApiSender($apiConfig, BASE_DIR . '/logs/api_send.log');

        $sourceXml = isset($orderData['SOURCE_FILE']) ? $orderData['SOURCE_FILE'] : '';
        $sendResult = $apiSender->send($orderData, $jsonFile, $sourceXml);

        echo json_encode(array(
            'status' => $sendResult['success'] ? 'ok' : 'error',
            'message' => $sendResult['message'],
            'http_code' => $sendResult['http_code']
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
