<?php
/**
 * ============================================================
 * СИСТЕМА ЛОГИРОВАНИЯ
 * ============================================================
 * 
 * Этот файл отвечает за запись логов (журнала событий) в файл.
 * Логи помогают отслеживать, что происходит в системе:
 * какие файлы обработаны, какие ошибки возникли и когда.
 * 
 * Все записи сохраняются в файл logs/app.log и отображаются
 * на веб-странице в реальном времени.
 * 
 * Уровни логирования:
 * - INFO    — обычная информация (файл обработан, процесс запущен)
 * - WARNING — предупреждение (что-то необычное, но не критичное)
 * - ERROR   — ошибка (файл не удалось обработать)
 * - SUCCESS — успешная операция (файл успешно преобразован)
 * ============================================================
 */

class Logger
{
    /** @var string Путь к файлу логов */
    private $logFile;

    /** @var int Максимальный размер файла логов в байтах (5 МБ) */
    private $maxFileSize = 5242880;

    /**
     * Создание логгера.
     * 
     * При создании проверяем, существует ли папка для логов.
     * Если нет — создаём её автоматически.
     * 
     * @param string $logFile — путь к файлу логов
     */
    public function __construct($logFile)
    {
        $this->logFile = $logFile;

        // Создаём папку для логов, если она ещё не существует
        $logDir = dirname($logFile);
        if (!is_dir($logDir)) {
            mkdir($logDir, 0755, true);
        }
    }

    /**
     * Записывает информационное сообщение в лог.
     * 
     * @param string $message — текст сообщения
     */
    public function info($message)
    {
        $this->write('INFO', $message);
    }

    /**
     * Записывает предупреждение в лог.
     * 
     * @param string $message — текст сообщения
     */
    public function warning($message)
    {
        $this->write('WARNING', $message);
    }

    /**
     * Записывает сообщение об ошибке в лог.
     * 
     * @param string $message — текст сообщения
     */
    public function error($message)
    {
        $this->write('ERROR', $message);
    }

    /**
     * Записывает сообщение об успехе в лог.
     * 
     * @param string $message — текст сообщения
     */
    public function success($message)
    {
        $this->write('SUCCESS', $message);
    }

    /**
     * Основной метод записи в лог-файл.
     * 
     * Формат каждой строки: [дата время] [УРОВЕНЬ] сообщение
     * Например: [2025-10-13 12:16:00] [INFO] Начата обработка файлов
     * 
     * Если файл логов стал слишком большим (больше 5 МБ),
     * старый файл переименовывается в .old, а новый создаётся с нуля.
     * 
     * @param string $level   — уровень логирования (INFO, ERROR и т.д.)
     * @param string $message — текст сообщения
     */
    private function write($level, $message)
    {
        // Если файл логов слишком большой — архивируем его
        if (file_exists($this->logFile) && filesize($this->logFile) > $this->maxFileSize) {
            $oldFile = $this->logFile . '.old';
            // Удаляем предыдущий архив, если он есть
            if (file_exists($oldFile)) {
                unlink($oldFile);
            }
            rename($this->logFile, $oldFile);
        }

        // Формируем строку лога с текущей датой и временем
        $timestamp = date('Y-m-d H:i:s');
        $logLine = "[{$timestamp}] [{$level}] {$message}" . PHP_EOL;

        // Записываем в файл (FILE_APPEND — добавляем в конец, не перезаписываем)
        // LOCK_EX — блокируем файл на время записи, чтобы не было конфликтов
        file_put_contents($this->logFile, $logLine, FILE_APPEND | LOCK_EX);
    }

    /**
     * Читает последние N строк из файла логов.
     * 
     * Используется для отображения логов на веб-странице.
     * Возвращает строки в хронологическом порядке (от старых к новым).
     * 
     * @param int $lines — количество строк, которые нужно вернуть
     * @return array — массив строк лога
     */
    public function getLastLines($lines = 100)
    {
        // Если файл логов не существует — возвращаем пустой массив
        if (!file_exists($this->logFile)) {
            return array();
        }

        // Читаем весь файл и разбиваем на строки
        $content = file($this->logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        
        if ($content === false) {
            return array();
        }

        // Возвращаем только последние N строк
        return array_slice($content, -$lines);
    }

    /**
     * Очищает файл логов.
     * 
     * Используется для ручной очистки через веб-интерфейс.
     */
    public function clear()
    {
        if (file_exists($this->logFile)) {
            file_put_contents($this->logFile, '');
        }
    }
}
