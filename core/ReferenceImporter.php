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
            'cities' => array(
                'files' => array('Города.txt'),
                'mapper' => 'mapCity'
            ),
            'countries' => array(
                'files' => array('Страны.txt'),
                'mapper' => 'mapCountry'
            ),
        );
    }

    /**
     * Справочники, которые собираются из «Контрагенты.txt».
     *
     * Файл выгрузки весит десятки мегабайт, поэтому целиком в справочник
     * он не переносится — из него выбираются только нужные контрагенты
     * по наименованию (белый список ниже + ручные записи с пустым uid).
     *
     * @return array — тип справочника => массив array('code' => ..., 'name' => ...)
     */
    public static function getContractorRefMap()
    {
        return array(
            // code = имя папки парсера в input/ (по нему ищет ReferenceManager::enrich)
            'suppliers' => array(
                array('code' => 'moyagent',    'name' => 'Мой Агент ООО'),
                array('code' => 'smarttravel', 'name' => 'РЖД - ЦИФРОВЫЕ ПАССАЖИРСКИЕ РЕШЕНИЯ'),
            ),
            // Справочный UID «РС ТЛС ООО»; в ORDER.CLIENT не подставляется (там null)
            'clients' => array(
                array('code' => 'rstls', 'name' => 'РС ТЛС ООО'),
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

        // Поставщики и клиенты — выборка из «Контрагенты.txt» (файл слишком велик для целиком)
        foreach ($this->importContractorRefs() as $type => $contractorResult) {
            $results[$type] = $contractorResult;
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

    /**
     * Город: код — «КодМОМ» (IATA-код города PLD или числовой код 1552997),
     * если он пуст — внутренний «Код» 1С. Ссылка на страну сохраняется как UID.
     *
     * @param array $row — запись выгрузки
     * @return array|null
     */
    private function mapCity($row)
    {
        $code = self::extractLocationCode(self::value($row, 'КодМОМ'));
        if ($code === '') {
            $code = trim(self::value($row, 'Код'));
        }
        if ($code === '') {
            return null;
        }

        $name = trim(self::value($row, 'Наименование'));

        return array(
            'uid'         => trim(self::value($row, 'UID')),
            'code'        => $code,
            'name'        => $name !== '' ? $name : $code,
            'country_uid' => trim(self::value($row, 'Страна'))
        );
    }

    /**
     * Страна: код — «КодАльфа2» (RU), при пустом — «КодАльфа3» (RUS).
     * Записи без буквенного кода пропускаются: поле «Код» у них равно «--»
     * (это устаревшие дубли наименований), искать по такому коду невозможно.
     *
     * @param array $row — запись выгрузки
     * @return array|null
     */
    private function mapCountry($row)
    {
        $code = strtoupper(trim(self::value($row, 'КодАльфа2')));
        if ($code === '') {
            $code = strtoupper(trim(self::value($row, 'КодАльфа3')));
        }
        if ($code === '') {
            return null;
        }

        $name = trim(self::value($row, 'Наименование'));
        $fullName = trim(self::value($row, 'НаименованиеПолное'));

        return array(
            'uid'       => trim(self::value($row, 'UID')),
            'code'      => $code,
            'name'      => $name !== '' ? $name : $code,
            'full_name' => $fullName
        );
    }

    // ============================================================
    // ВЫБОРКА КОНТРАГЕНТОВ (suppliers, clients)
    // ============================================================

    /**
     * Сборка справочников suppliers.json и clients.json из «Контрагенты.txt».
     *
     * Из выгрузки берутся только контрагенты, чьи наименования перечислены
     * в getContractorRefMap(), плюс наименования ручных записей с пустым uid —
     * им UID дозаполняется. Остальные записи существующих справочников
     * сохраняются как есть, чтобы ручные правки не терялись.
     *
     * @return array — тип справочника => результат импорта (как в importType)
     */
    public function importContractorRefs()
    {
        $refMap = self::getContractorRefMap();
        $results = array();

        $filePath = $this->findSourceFile(array('Контрагенты.txt'));
        if ($filePath === null) {
            foreach ($refMap as $type => $ignored) {
                $this->logger->info("Импорт {$type}: файл не найден (Контрагенты.txt) — пропущен");
                $results[$type] = $this->result('skipped', '', 0, 0, 'Файл не найден: Контрагенты.txt');
            }
            return $results;
        }

        $fileName = basename($filePath);

        // Текущее содержимое справочников (ручные правки) и список искомых наименований
        $existing = array();
        $wanted = array();
        $lookup = array();

        foreach ($refMap as $type => $builtin) {
            $existing[$type] = $this->readReference($type);
            $wanted[$type] = array();

            foreach ($builtin as $item) {
                $wanted[$type][$item['code']] = $item['name'];
            }

            // Ручные записи без UID: имя берём из справочника, UID дозаполним из выгрузки
            foreach ($existing[$type] as $entry) {
                if (!isset($entry['code']) || (string)$entry['code'] === '') {
                    continue;
                }
                $code = (string)$entry['code'];
                $name = isset($entry['name']) ? trim((string)$entry['name']) : '';
                $uid = isset($entry['uid']) ? trim((string)$entry['uid']) : '';
                if ($name !== '' && $uid === '' && !isset($wanted[$type][$code])) {
                    $wanted[$type][$code] = $name;
                }
            }

            foreach ($wanted[$type] as $code => $name) {
                $key = self::normalizeRefName($name);
                if ($key === '') {
                    continue;
                }
                if (!isset($lookup[$key])) {
                    $lookup[$key] = array();
                }
                $lookup[$key][] = array('type' => $type, 'code' => $code);
            }
        }

        // Один проход по файлу: собираем только те записи, что нужны
        $found = array();
        $rowsTotal = $this->streamJsonRows($filePath, function ($row) use ($lookup, &$found) {
            $key = ReferenceImporter::normalizeRefName(isset($row['Наименование']) ? $row['Наименование'] : '');
            if ($key === '' || !isset($lookup[$key])) {
                return;
            }
            foreach ($lookup[$key] as $target) {
                // Совпадений по наименованию может быть несколько — берём первое
                if (!isset($found[$target['type']][$target['code']])) {
                    $found[$target['type']][$target['code']] = $row;
                }
            }
        });

        if ($rowsTotal === false) {
            foreach ($refMap as $type => $ignored) {
                $this->logger->error("Импорт {$type}: не удалось прочитать {$fileName}");
                $results[$type] = $this->result('error', $fileName, 0, 0, "Не удалось прочитать {$fileName}");
            }
            return $results;
        }

        foreach ($refMap as $type => $ignored) {
            $typeFound = isset($found[$type]) ? $found[$type] : array();
            $results[$type] = $this->writeContractorRef(
                $type, $existing[$type], $wanted[$type], $typeFound, $fileName
            );
        }

        return $results;
    }

    /**
     * Слияние найденных контрагентов с текущим справочником и запись файла.
     *
     * @param string $type — тип справочника (suppliers, clients)
     * @param array $existing — текущие записи справочника
     * @param array $wanted — код => искомое наименование
     * @param array $found — код => запись выгрузки 1С
     * @param string $fileName — имя файла выгрузки (для логов)
     * @return array — результат импорта (как в importType)
     */
    private function writeContractorRef($type, $existing, $wanted, $found, $fileName)
    {
        $entries = array();
        $byCode = array();

        // Сохраняем существующие записи (в том числе добавленные вручную)
        foreach ($existing as $entry) {
            if (!is_array($entry) || !isset($entry['code']) || (string)$entry['code'] === '') {
                continue; // подсказку и мусор не переносим — подсказка добавляется заново ниже
            }
            $code = (string)$entry['code'];
            $byCode[$code] = count($entries);
            $entries[] = $entry;
        }

        $missing = array();

        foreach ($wanted as $code => $name) {
            if (!isset($found[$code])) {
                $missing[] = $name;
            }

            $row = isset($found[$code]) ? $found[$code] : array();
            $uid = trim(self::value($row, 'UID'));
            $rowName = trim(self::value($row, 'Наименование'));

            $entry = array(
                'uid'      => $uid,
                'code'     => $code,
                'name'     => $rowName !== '' ? $rowName : $name,
                'code_1c'  => trim(self::value($row, 'Код')),
                'code_mom' => trim(self::value($row, 'КодМОМ')),
                'inn'      => trim(self::value($row, 'ИНН'))
            );

            if (isset($byCode[$code])) {
                // Обновляем существующую запись, не теряя добавленные вручную поля
                $entries[$byCode[$code]] = array_merge($entries[$byCode[$code]], $entry);
            } else {
                $byCode[$code] = count($entries);
                $entries[] = $entry;
            }
        }

        $count = count($entries);

        // Подсказка для ручного дополнения — первым элементом массива.
        // Поля code у неё нет, поэтому ReferenceManager её игнорирует.
        array_unshift($entries, self::getRefHint($type));

        if (!$this->writeReference($type, $entries)) {
            $this->logger->error("Импорт {$type}: не удалось записать {$type}.json");
            return $this->result('error', $fileName, 0, count($missing), "Не удалось записать {$type}.json");
        }

        if (!empty($missing)) {
            $this->logger->warning(
                "Импорт {$type}: в {$fileName} не найдены контрагенты: " . implode(', ', $missing)
                . ' — UID оставлен пустым'
            );
        }

        $this->logger->success(
            "Импорт {$type}: из {$fileName} загружено записей: {$count}, без UID: " . count($missing)
        );

        return $this->result('ok', $fileName, $count, count($missing), '');
    }

    /**
     * Служебная запись-подсказка для справочников, которые дополняются вручную.
     *
     * @param string $type — тип справочника (suppliers, clients)
     * @return array
     */
    private static function getRefHint($type)
    {
        $what = $type === 'clients' ? 'клиента' : 'поставщика';
        $codeHint = $type === 'clients'
            ? 'code = условный код клиента (rstls — «РС ТЛС ООО», справочно)'
            : 'code = имя папки парсера в input/ (moyagent, smarttravel, ...)';

        return array(
            '_hint' => 'Как добавить ' . $what . ' вручную: скопируйте блок _template в конец массива, '
                . $codeHint . ', name = наименование контрагента из 1С точно как в выгрузке, '
                . 'uid оставьте пустым — он подставится сам при следующем нажатии «Загрузить справочники». '
                . 'Для тестовой базы 1С заполните uid_test и в config/settings.json поставьте references.uid_profile = "test". '
                . 'Записи без поля code справочником игнорируются, поэтому эту подсказку удалять не нужно.',
            '_source' => 'references/import/Контрагенты.txt — поля UID, Код, Наименование, ИНН, КодМОМ',
            '_template' => array(
                'uid'      => '',
                'uid_test' => '',
                'code'     => $type === 'clients' ? 'код_клиента' : 'имя_папки_парсера',
                'name'     => 'Наименование контрагента из 1С'
            )
        );
    }

    /**
     * Приведение наименования контрагента к сравнимому виду:
     * нижний регистр, «ё» в «е», знаки препинания и лишние пробелы убираются.
     * В выгрузке 1С встречаются висячие пробелы («Мой Агент ООО ») и дефисы.
     *
     * @param string $value — исходное наименование
     * @return string
     */
    public static function normalizeRefName($value)
    {
        $value = mb_strtolower(trim((string)$value), 'UTF-8');
        $value = str_replace('ё', 'е', $value);
        $value = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $value);

        return trim(preg_replace('/\s+/u', ' ', $value));
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
     * Потоковое чтение выгрузки: объекты JSON выдаются по одному в callback.
     *
     * Нужно для «Контрагенты.txt» — файл на десятки мегабайт, целиком
     * в память его загружать не нужно. Границы объектов определяются по
     * балансу фигурных скобок с учётом строк и экранирования.
     *
     * @param string $filePath — полный путь к файлу выгрузки
     * @param callable $callback — вызывается для каждой записи (array $row)
     * @return int|false — количество разобранных записей или false при ошибке чтения
     */
    private function streamJsonRows($filePath, $callback)
    {
        $handle = @fopen($filePath, 'rb');
        if ($handle === false) {
            return false;
        }

        $buffer = '';
        $depth = 0;
        $inString = false;
        $escaped = false;
        $atStart = true;
        $total = 0;

        while (!feof($handle)) {
            $chunk = fread($handle, 262144);
            if ($chunk === false || $chunk === '') {
                break;
            }

            // BOM снимаем только в начале файла (1С выгружает UTF-8 с сигнатурой)
            if ($atStart) {
                if (substr($chunk, 0, 3) === "\xEF\xBB\xBF") {
                    $chunk = substr($chunk, 3);
                }
                $atStart = false;
            }

            $len = strlen($chunk);
            for ($i = 0; $i < $len; $i++) {
                $char = $chunk[$i];

                if ($depth > 0) {
                    $buffer .= $char;
                }

                if ($inString) {
                    if ($escaped) {
                        $escaped = false;
                    } elseif ($char === '\\') {
                        $escaped = true;
                    } elseif ($char === '"') {
                        $inString = false;
                    }
                    continue;
                }

                if ($char === '"') {
                    $inString = true;
                } elseif ($char === '{') {
                    if ($depth === 0) {
                        $buffer = '{';
                    }
                    $depth++;
                } elseif ($char === '}') {
                    $depth--;
                    if ($depth === 0) {
                        $row = json_decode($buffer, true);
                        $buffer = '';
                        if (is_array($row)) {
                            $total++;
                            call_user_func($callback, $row);
                        }
                    }
                }
            }
        }

        fclose($handle);

        return $total;
    }

    /**
     * Чтение существующего справочника references/{type}.json.
     * Нужно, чтобы при импорте не потерять записи, добавленные вручную.
     *
     * @param string $type — тип справочника
     * @return array — записи справочника (пустой массив, если файла нет)
     */
    private function readReference($type)
    {
        $filePath = $this->referencesDir . DIRECTORY_SEPARATOR . $type . '.json';
        if (!file_exists($filePath)) {
            return array();
        }

        $content = file_get_contents($filePath);
        if ($content === false || trim($content) === '') {
            return array();
        }

        $data = json_decode($content, true);

        return is_array($data) ? $data : array();
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
