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

require_once __DIR__ . '/Utils.php';

class Logger
{
    /** @var string Путь к файлу логов */
    private $logFile;

    /** @var int Максимальный размер файла логов в байтах (5 МБ) */
    private $maxFileSize = 5242880;

    /** @var bool Права на файл лога уже проверялись в этом запросе */
    private $ownershipChecked = false;

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
        Utils::ensureDirectory($logDir);
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
        $isNewFile = !file_exists($this->logFile);

        // Если файл логов слишком большой — архивируем его
        if (file_exists($this->logFile) && filesize($this->logFile) > $this->maxFileSize) {
            $oldFile = $this->logFile . '.old';
            // Удаляем предыдущий архив, если он есть
            if (file_exists($oldFile)) {
                unlink($oldFile);
            }
            rename($this->logFile, $oldFile);
            Utils::ensureOwnership($oldFile);
            $isNewFile = true;
        }

        // Формируем строку лога с текущей датой и временем
        $timestamp = date('Y-m-d H:i:s');
        $logLine = "[{$timestamp}] [{$level}] {$message}" . PHP_EOL;

        // Записываем в файл (FILE_APPEND — добавляем в конец, не перезаписываем)
        // LOCK_EX — блокируем файл на время записи, чтобы не было конфликтов
        file_put_contents($this->logFile, $logLine, FILE_APPEND | LOCK_EX);

        // Права проверяем на новом файле и один раз за запрос: старый лог мог
        // остаться с прежними правами, а chmod на каждую строку — лишняя работа
        if ($isNewFile || !$this->ownershipChecked) {
            $this->ownershipChecked = true;
            Utils::ensureOwnership($this->logFile);
        }
    }

    /**
     * Читает последние N строк из файла логов.
     *
     * @param int $lines — количество строк, которые нужно вернуть
     * @return array — массив строк лога
     */
    public function getLastLines($lines = 100)
    {
        $chunk = $this->getLinesChunk(0, $lines);
        return isset($chunk['lines']) ? $chunk['lines'] : array();
    }

    /**
     * Читает чанк строк с конца файла (без загрузки всего файла в память).
     *
     * @param int $offsetFromEnd — сколько строк пропустить с конца (0 = самые новые)
     * @param int $limit — сколько строк вернуть
     * @return array — array('lines'=>array(...), 'has_more'=>bool, 'offset'=>int, 'limit'=>int)
     *                 lines в хронологическом порядке (старые → новые)
     */
    public function getLinesChunk($offsetFromEnd = 0, $limit = 100)
    {
        $offsetFromEnd = max(0, (int)$offsetFromEnd);
        $limit = max(1, min(500, (int)$limit));

        $empty = array(
            'lines' => array(),
            'has_more' => false,
            'offset' => $offsetFromEnd,
            'limit' => $limit
        );

        if (!file_exists($this->logFile) || filesize($this->logFile) === 0) {
            return $empty;
        }

        $need = $offsetFromEnd + $limit + 1; // +1 чтобы узнать has_more
        $collected = $this->readLastNonEmptyLines($need);

        $totalCollected = count($collected);
        $hasMore = $totalCollected > ($offsetFromEnd + $limit);

        // collected: newest first (index 0 = newest)
        // skip offsetFromEnd newest, then take limit, then reverse to chronological
        $slice = array_slice($collected, $offsetFromEnd, $limit);
        $slice = array_reverse($slice);

        return array(
            'lines' => $slice,
            'has_more' => $hasMore,
            'offset' => $offsetFromEnd,
            'limit' => $limit
        );
    }

    /**
     * Читает с конца файла до $need непустых строк. Возвращает newest-first.
     *
     * @param int $need
     * @return array
     */
    private function readLastNonEmptyLines($need)
    {
        $need = max(1, (int)$need);
        $fh = @fopen($this->logFile, 'rb');
        if ($fh === false) {
            return array();
        }

        $buffer = '';
        $lines = array();
        $chunkSize = 8192;
        $pos = filesize($this->logFile);

        while ($pos > 0 && count($lines) < $need) {
            $read = ($pos >= $chunkSize) ? $chunkSize : $pos;
            $pos -= $read;
            fseek($fh, $pos);
            $chunk = fread($fh, $read);
            if ($chunk === false) {
                break;
            }
            $buffer = $chunk . $buffer;

            $parts = preg_split("/\r\n|\n|\r/", $buffer);
            // first element may be incomplete (start of file mid-line) — keep in buffer
            $buffer = array_shift($parts);

            for ($i = count($parts) - 1; $i >= 0; $i--) {
                $line = $parts[$i];
                if ($line === '') {
                    continue;
                }
                $lines[] = $line;
                if (count($lines) >= $need) {
                    break 2;
                }
            }
        }

        if ($buffer !== '' && count($lines) < $need) {
            $lines[] = $buffer;
        }

        fclose($fh);
        return $lines;
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
