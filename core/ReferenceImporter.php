<?php
/**
 * ============================================================
 * ИМПОРТЁР СПРАВОЧНИКОВ ИЗ ВЫГРУЗКИ 1С
 * ============================================================
 *
 * Читает файлы выгрузки 1С из references/import/ (внутри — обычный
 * JSON с русскими именами полей) и нормализует их в справочники
 * references/*.json формата {uid, code, name}.
 *
 * Выгрузки кладутся в папку вручную (раз в месяц), формат файлов
 * не меняется. Импорт запускается кнопкой «Загрузить справочники»
 * на панели управления (api.php?action=import_references).
 * ============================================================
 */

require_once __DIR__ . '/Utils.php';

class ReferenceImporter
{
    /** @var string Путь к папке references/import/ с выгрузками 1С */
    private $importDir;

    /** @var string Путь к папке references/ с готовыми справочниками */
    private $referencesDir;

    /** @var Logger Логгер */
    private $logger;

    /** @var array|null Индекс файлов в importDir: имя в нижнем регистре => полный путь */
    private $fileIndex;

    /**
     * Конструктор.
     *
     * @param string $importDir — путь к папке с выгрузками (references/import)
     * @param string $referencesDir — путь к папке справочников (references)
     * @param Logger $logger — экземпляр логгера
     */
    public function __construct($importDir, $referencesDir, $logger)
    {
        $this->importDir = rtrim($importDir, '/\\');
        $this->referencesDir = rtrim($referencesDir, '/\\');
        $this->logger = $logger;
        $this->fileIndex = null;

        Utils::ensureDirectory($this->importDir);
        Utils::ensureDirectory($this->referencesDir);
    }

    /**
     * Карта импорта: тип справочника => имена файлов выгрузки и метод-преобразователь.
     *
     * Для каждого типа перечислено несколько допустимых имён файла —
     * берётся первое найденное в папке импорта.
     *
     * @return array
     */
    public static function getImportMap()
    {
        return array(
            'currencies' => array(
                'files' => array('Валюты.txt'),
                'mapper' => 'mapCurrency'
            ),
            'airlines' => array(
                'files' => array('АвиаКомпании.txt'),
                'mapper' => 'mapAirline'
            ),
            'airports' => array(
                'files' => array('АэропортыСтанцииЖД.txt'),
                'mapper' => 'mapAirport'
            ),
            'agents' => array(
                'files' => array('Агенты.txt', 'Пользователи.txt'),
                'mapper' => 'mapAgent'
            ),
        );
    }

    /**
     * Импорт всех справочников, для которых найдены файлы выгрузки.
     *
     * @return array — тип => результат импорта (status, file, count, skipped, message)
     */
    public function importAll()
    {
        $this->logger->info('Импорт справочников: начало (папка ' . $this->importDir . ')');

        $results = array();
        foreach (self::getImportMap() as $type => $config) {
            $results[$type] = $this->importType($type);
        }

        $imported = 0;
        foreach ($results as $result) {
            if ($result['status'] === 'ok') {
                $imported++;
            }
        }
        $this->logger->info('Импорт справочников: завершён, обновлено справочников: ' . $imported);

        return $results;
    }

    /**
     * Импорт одного справочника.
     *
     * @param string $type — тип справочника (currencies, airlines, airports, agents)
     * @return array — status (ok|skipped|error), file, count, skipped, message
     */
    public function importType($type)
    {
        $map = self::getImportMap();
        if (!isset($map[$type])) {
            return $this->result('error', '', 0, 0, "Неизвестный тип справочника: {$type}");
        }

        $config = $map[$type];
        $filePath = $this->findSourceFile($config['files']);

        if ($filePath === null) {
            $expected = implode(' или ', $config['files']);
            $this->logger->info("Импорт {$type}: файл не найден ({$expected}) — пропущен");
            return $this->result('skipped', '', 0, 0, "Файл не найден: {$expected}");
        }

        $fileName = basename($filePath);
        $rows = $this->readSourceFile($filePath);

        if ($rows === null) {
            $this->logger->error("Импорт {$type}: не удалось прочитать {$fileName} (невалидный JSON)");
            return $this->result('error', $fileName, 0, 0, 'Не удалось разобрать файл как JSON');
        }

        $mapper = $config['mapper'];
        $entries = array();
        $seenCodes = array();
        $skipped = 0;

        foreach ($rows as $row) {
            if (!is_array($row)) {
                $skipped++;
                continue;
            }

            $entry = $this->$mapper($row);
            if ($entry === null) {
                $skipped++;
                continue;
            }

            // Дубликаты по коду: оставляем первую запись
            $codeKey = mb_strtolower($entry['code'], 'UTF-8');
            if (isset($seenCodes[$codeKey])) {
                $skipped++;
                continue;
            }
            $seenCodes[$codeKey] = true;

            $entries[] = $entry;
        }

        if (!$this->writeReference($type, $entries)) {
            $this->logger->error("Импорт {$type}: не удалось записать {$type}.json");
            return $this->result('error', $fileName, 0, $skipped, "Не удалось записать {$type}.json");
        }

        $count = count($entries);
        $this->logger->success(
            "Импорт {$type}: из {$fileName} загружено записей: {$count}, пропущено: {$skipped}"
        );

        return $this->result('ok', $fileName, $count, $skipped, '');
    }

    // ============================================================
    // ПРЕОБРАЗОВАТЕЛИ ЗАПИСЕЙ ВЫГРУЗКИ 1С
    // ============================================================

    /**
     * Названия валют из 1С, не совпадающие с буквенным кодом ISO.
     * Парсеры отдают ISO-код (RUB), в выгрузке 1С может быть «руб.».
     *
     * @return array — название в нижнем регистре => буквенный код ISO
     */
    private static function getCurrencyAliases()
    {
        return array(
            'руб.' => 'RUB',
            'руб'  => 'RUB',
        );
    }

    /**
     * Валюта: код — буквенный ISO из «Наименование» (RUB, USD).
     * В выгрузке встречаются лишние пробелы (" AMD "), поэтому trim обязателен.
     *
     * @param array $row — запись выгрузки
     * @return array|null
     */
    private function mapCurrency($row)
    {
        $code = trim(self::value($row, 'Наименование'));
        if ($code === '') {
            return null;
        }

        $name = trim(self::value($row, 'НаименованиеПолное'));
        if ($name === '') {
            $name = $code;
        }

        $aliases = array();
        $aliasMap = self::getCurrencyAliases();
        $aliasKey = mb_strtolower($code, 'UTF-8');
        if (isset($aliasMap[$aliasKey])) {
            $aliases[] = $aliasMap[$aliasKey];
        }

        return array(
            'uid'     => trim(self::value($row, 'UID')),
            'code'    => $code,
            'name'    => $name,
            'aliases' => $aliases
        );
    }

    /**
     * Авиакомпания: код — двухбуквенный IATA из «КодБуквенный» (SU, LH).
     * Записи без буквенного кода пропускаются — по ним поиск невозможен.
     *
     * @param array $row — запись выгрузки
     * @return array|null
     */
    private function mapAirline($row)
    {
        $code = strtoupper(trim(self::value($row, 'КодБуквенный')));
        if ($code === '') {
            return null;
        }

        $name = trim(self::value($row, 'Наименование'));

        return array(
            'uid'  => trim(self::value($row, 'UID')),
            'code' => $code,
            'name' => $name !== '' ? $name : $code
        );
    }

    /**
     * Аэропорт или ЖД-вокзал: код берётся из «КодМОМ».
     * В выгрузке встречаются три формы: "airport ZRH", " ZIA" и "LOS",
     * у ЖД-вокзалов — числовой код станции ("2024713").
     *
     * @param array $row — запись выгрузки
     * @return array|null
     */
    private function mapAirport($row)
    {
        $code = self::extractLocationCode(self::value($row, 'КодМОМ'));
        if ($code === '') {
            return null;
        }

        $name = trim(self::value($row, 'Наименование'));

        return array(
            'uid'  => trim(self::value($row, 'UID')),
            'code' => $code,
            'name' => $name !== '' ? $name : $code
        );
    }

    /**
     * Агент: в выгрузке нет UID, нужен только код из «КодМОМ».
     * Поиск в enrich() идёт по ФИО, поэтому кроме name сохраняются
     * альтернативные написания (латиница) в aliases.
     *
     * @param array $row — запись выгрузки
     * @return array|null
     */
    private function mapAgent($row)
    {
        $code = trim(self::value($row, 'КодМОМ'));
        if ($code === '') {
            return null;
        }

        $fullName = trim(self::value($row, 'ФизическоеЛицо'));
        $login = trim(self::value($row, 'Наименование'));

        if ($fullName === '') {
            $fullName = $login;
        }
        if ($fullName === '') {
            return null;
        }

        $aliases = array();
        if ($login !== '' && $login !== $fullName) {
            $aliases[] = $login;
        }

        return array(
            'uid'     => '',
            'code'    => $code,
            'name'    => $fullName,
            'aliases' => $aliases
        );
    }

    // ============================================================
    // ЧТЕНИЕ И ЗАПИСЬ ФАЙЛОВ
    // ============================================================

    /**
     * Поиск файла выгрузки в папке импорта.
     * Сначала проверяется точное имя, затем — поиск без учёта регистра
     * (имена файлов из 1С могут отличаться регистром).
     *
     * @param array $fileNames — допустимые имена файла
     * @return string|null — полный путь или null
     */
    private function findSourceFile($fileNames)
    {
        foreach ($fileNames as $fileName) {
            $direct = $this->importDir . DIRECTORY_SEPARATOR . $fileName;
            if (file_exists($direct)) {
                return $direct;
            }
        }

        $index = $this->buildFileIndex();
        foreach ($fileNames as $fileName) {
            $key = mb_strtolower($fileName, 'UTF-8');
            if (isset($index[$key])) {
                return $index[$key];
            }
        }

        return null;
    }

    /**
     * Индекс файлов папки импорта: имя в нижнем регистре => полный путь.
     *
     * @return array
     */
    private function buildFileIndex()
    {
        if ($this->fileIndex !== null) {
            return $this->fileIndex;
        }

        $this->fileIndex = array();

        $items = @scandir($this->importDir);
        if ($items === false) {
            return $this->fileIndex;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $this->importDir . DIRECTORY_SEPARATOR . $item;
            if (is_file($path)) {
                $this->fileIndex[mb_strtolower($item, 'UTF-8')] = $path;
            }
        }

        return $this->fileIndex;
    }

    /**
     * Чтение файла выгрузки: снятие BOM, разбор JSON,
     * при неудаче — попытка перекодировать из CP1251.
     *
     * @param string $filePath — полный путь к файлу
     * @return array|null — массив записей или null при ошибке разбора
     */
    private function readSourceFile($filePath)
    {
        $content = file_get_contents($filePath);
        if ($content === false || trim($content) === '') {
            return null;
        }

        // Снимаем BOM (1С часто выгружает UTF-8 с сигнатурой)
        if (substr($content, 0, 3) === "\xEF\xBB\xBF") {
            $content = substr($content, 3);
        }

        $data = json_decode($content, true);

        // Файл может быть выгружен в CP1251 — пробуем перекодировать
        if (!is_array($data)) {
            $converted = @mb_convert_encoding($content, 'UTF-8', 'CP1251');
            if ($converted !== false) {
                $data = json_decode($converted, true);
            }
        }

        return is_array($data) ? $data : null;
    }

    /**
     * Запись готового справочника в references/{type}.json.
     *
     * @param string $type — тип справочника
     * @param array $entries — нормализованные записи
     * @return bool
     */
    private function writeReference($type, $entries)
    {
        $filePath = $this->referencesDir . DIRECTORY_SEPARATOR . $type . '.json';
        $json = json_encode($entries, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

        if ($json === false) {
            return false;
        }

        if (file_put_contents($filePath, $json, LOCK_EX) === false) {
            return false;
        }

        Utils::ensureOwnership($filePath);
        return true;
    }

    // ============================================================
    // ВСПОМОГАТЕЛЬНЫЕ МЕТОДЫ
    // ============================================================

    /**
     * Безопасное чтение поля записи выгрузки.
     *
     * @param array $row — запись
     * @param string $key — имя поля
     * @return string
     */
    private static function value($row, $key)
    {
        return isset($row[$key]) ? (string)$row[$key] : '';
    }

    /**
     * Извлечение кода локации из «КодМОМ».
     * Если значение содержит пробел — берётся последнее слово
     * ("airport ZRH" => "ZRH"). Трёхбуквенные коды приводятся к верхнему регистру.
     *
     * @param string $raw — исходное значение поля
     * @return string
     */
    private static function extractLocationCode($raw)
    {
        $code = trim($raw);
        if ($code === '') {
            return '';
        }

        if (strpos($code, ' ') !== false) {
            $parts = preg_split('/\s+/', $code);
            $code = trim(end($parts));
        }

        if (preg_match('/^[A-Za-z]{2,3}$/', $code)) {
            $code = strtoupper($code);
        }

        return $code;
    }

    /**
     * Формирование результата импорта одного справочника.
     *
     * @param string $status — ok, skipped или error
     * @param string $file — имя обработанного файла
     * @param int $count — количество загруженных записей
     * @param int $skipped — количество пропущенных записей
     * @param string $message — пояснение (для skipped и error)
     * @return array
     */
    private function result($status, $file, $count, $skipped, $message)
    {
        return array(
            'status'  => $status,
            'file'    => $file,
            'count'   => $count,
            'skipped' => $skipped,
            'message' => $message
        );
    }
}
