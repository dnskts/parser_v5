<?php
/**
 * ============================================================
 * WEBHOOK — ПРИЁМНИК PUSH-УВЕДОМЛЕНИЙ
 * ============================================================
 *
 * Универсальный эндпоинт для приёма данных от поставщиков
 * по технологии Push (HTTP POST callback).
 *
 * URL: webhook.php?supplier=smarttravel
 *
 * Логика:
 * 1. Принимает POST-запрос с JSON-телом
 * 2. Проверяет Basic Auth (если включено в настройках)
 * 3. Сохраняет в input/{supplier}/webhook_{timestamp}.json
 * 4. Вызывает Processor->processSingleFile() для обработки
 * 5. Возвращает JSON-ответ
 *
 * Лог: logs/webhook.log
 * ============================================================
 */

if (!defined('BASE_DIR')) {
    define('BASE_DIR', __DIR__);
}

require_once BASE_DIR . '/core/Utils.php';
require_once BASE_DIR . '/core/Logger.php';
require_once BASE_DIR . '/core/ParserManager.php';
require_once BASE_DIR . '/core/Processor.php';

// Заголовки ответа
header('Content-Type: application/json; charset=utf-8');

$logFile = BASE_DIR . '/logs/webhook.log';

/**
 * Запись в лог webhook.log
 */
function webhookLog($level, $message)
{
    global $logFile;
    $dir = dirname($logFile);
    Utils::ensureDirectory($dir);

    $isNewFile = !file_exists($logFile);

    if (file_exists($logFile) && filesize($logFile) > 5 * 1024 * 1024) {
        @rename($logFile, $logFile . '.old');
        Utils::ensureOwnership($logFile . '.old');
        $isNewFile = true;
    }

    $line = '[' . date('Y-m-d H:i:s') . '] [' . $level . '] ' . $message . PHP_EOL;
    file_put_contents($logFile, $line, FILE_APPEND | LOCK_EX);

    if ($isNewFile) {
        Utils::ensureOwnership($logFile);
    }
}

// Только POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(array('status' => 'error', 'message' => 'Требуется POST-запрос'), JSON_UNESCAPED_UNICODE);
    exit;
}

// Параметр supplier обязателен
$supplier = isset($_GET['supplier']) ? trim($_GET['supplier']) : '';
if (empty($supplier)) {
    http_response_code(400);
    webhookLog('ERROR', 'Не указан параметр supplier');
    echo json_encode(array('status' => 'error', 'message' => 'Не указан параметр supplier'), JSON_UNESCAPED_UNICODE);
    exit;
}

// Загружаем настройки
$configFile = BASE_DIR . '/config/settings.json';
$settings = array();
if (file_exists($configFile)) {
    $settings = json_decode(file_get_contents($configFile), true);
    if (!is_array($settings)) {
        $settings = array();
    }
}

// Проверяем Basic Auth (если включено для данного поставщика)
$supplierSettings = isset($settings[$supplier]) ? $settings[$supplier] : array();
$webhookSettings = isset($supplierSettings['webhook']) ? $supplierSettings['webhook'] : array();

if (!empty($webhookSettings['basic_auth_enabled'])) {
    $authHeader = '';
    if (isset($_SERVER['HTTP_AUTHORIZATION'])) {
        $authHeader = $_SERVER['HTTP_AUTHORIZATION'];
    } elseif (isset($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
        $authHeader = $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
    }

    $authenticated = false;
    if (strpos($authHeader, 'Basic ') === 0) {
        $decoded = base64_decode(substr($authHeader, 6));
        $expectedLogin = isset($webhookSettings['basic_login']) ? $webhookSettings['basic_login'] : '';
        $expectedPass  = isset($webhookSettings['basic_pass']) ? $webhookSettings['basic_pass'] : '';
        if ($decoded === $expectedLogin . ':' . $expectedPass) {
            $authenticated = true;
        }
    }

    if (!$authenticated) {
        http_response_code(401);
        header('WWW-Authenticate: Basic realm="Webhook"');
        webhookLog('ERROR', "Неверная авторизация для supplier={$supplier}");
        echo json_encode(array('status' => 'error', 'message' => 'Unauthorized'), JSON_UNESCAPED_UNICODE);
        exit;
    }
}

// Читаем тело запроса
$rawBody = file_get_contents('php://input');
if (empty($rawBody)) {
    http_response_code(400);
    webhookLog('ERROR', "Пустое тело запроса, supplier={$supplier}");
    echo json_encode(array('status' => 'error', 'message' => 'Пустое тело запроса'), JSON_UNESCAPED_UNICODE);
    exit;
}

// Валидируем JSON
$bodyData = json_decode($rawBody, true);
if (!is_array($bodyData)) {
    http_response_code(400);
    webhookLog('ERROR', "Невалидный JSON, supplier={$supplier}");
    echo json_encode(array('status' => 'error', 'message' => 'Невалидный JSON'), JSON_UNESCAPED_UNICODE);
    exit;
}

// Сохраняем в input/{supplier}/
$inputDir = BASE_DIR . '/input/' . $supplier;
Utils::ensureDirectory($inputDir);

$timestamp = date('Ymd_His') . '_' . substr(microtime(true) * 1000 % 1000, 0, 3);
$fileName = "webhook_{$timestamp}.json";
$filePath = $inputDir . DIRECTORY_SEPARATOR . $fileName;

$jsonContent = json_encode($bodyData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
$written = file_put_contents($filePath, $jsonContent, LOCK_EX);

if ($written === false) {
    http_response_code(500);
    webhookLog('ERROR', "Не удалось сохранить файл: {$filePath}");
    echo json_encode(array('status' => 'error', 'message' => 'Ошибка сохранения файла'), JSON_UNESCAPED_UNICODE);
    exit;
}

Utils::ensureOwnership($filePath);

$sizeKb = round($written / 1024, 1);
webhookLog('INFO', "Принят webhook: supplier={$supplier}, файл={$fileName} ({$sizeKb} KB)");

// Немедленная обработка через Processor
$processed = false;
$processMessage = '';
try {
    $logger = new Logger(BASE_DIR . '/logs/app.log');
    $parserManager = new ParserManager(BASE_DIR . '/parsers', $logger);

    $parser = $parserManager->getParser($supplier);
    if ($parser === null) {
        webhookLog('WARNING', "Парсер для supplier={$supplier} не найден, файл сохранён для обработки позже");
        $processMessage = 'Парсер не найден, файл сохранён';
    } else {
        $processor = new Processor(
            BASE_DIR . '/input',
            BASE_DIR . '/json',
            $configFile,
            $logger,
            $parserManager
        );
        $processResult = $processor->processSingleFile($filePath, $supplier);
        $processed = true;
        $processMessage = 'Обработано: ' . (isset($processResult['processed']) ? $processResult['processed'] : 0) . ' заказов';
        webhookLog('SUCCESS', "Обработан {$fileName}: {$processMessage}");
    }
} catch (Exception $e) {
    $processMessage = $e->getMessage();
    webhookLog('ERROR', "Ошибка обработки {$fileName}: {$processMessage}");
}

echo json_encode(array(
    'status' => 'ok',
    'file' => $fileName,
    'size_kb' => $sizeKb,
    'processed' => $processed,
    'message' => $processMessage
), JSON_UNESCAPED_UNICODE);
