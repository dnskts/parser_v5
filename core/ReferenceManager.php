<?php
/**
 * ============================================================
 * МЕНЕДЖЕР СПРАВОЧНИКОВ
 * ============================================================
 *
 * Загрузка справочников из JSON-файлов (references/*.json),
 * поиск UID по коду, обогащение ORDER полями UID.
 *
 * Справочники заполняются вручную или через API 1С (syncReference).
 * Подстановка UID — метод enrich(), вызывается из Processor
 * после парсинга и перед сохранением JSON.
 * ============================================================
 */

require_once __DIR__ . '/Utils.php';

class ReferenceManager
{
    /** Код МОМ агента-заглушки: уходит в 1С, когда агента нет в agents.json */
    const UNKNOWN_AGENT_CODE = '045';

    /** Имя агента-заглушки в ORDER */
    const UNKNOWN_AGENT_NAME = 'Агент не найден';

    /** @var string Путь к папке references/ */
    private $referencesDir;

    /** @var array Кеш загруженных справочников: type => array данных */
    private $cache;

    /** @var Logger Логгер */
    private $logger;

    /** @var array Настройки из config/settings.json секция references */
    private $settings;

    /**
     * Конструктор.
     *
     * @param string $referencesDir — путь к папке references/
     * @param Logger $logger — экземпляр логгера
     * @param array $settings — секция references из settings.json
     */
    public function __construct($referencesDir, $logger, $settings)
    {
        $this->referencesDir = rtrim($referencesDir, '/\\');
        $this->cache = array();
        $this->logger = $logger;
        $this->settings = is_array($settings) ? $settings : array();

        Utils::ensureDirectory($this->referencesDir);
    }

    /**
     * Загрузка справочника из JSON-файла в кеш.
     * Файл читается один раз за цикл обработки.
     *
     * @param string $type — имя справочника (suppliers, agents, airports, ...)
     * @return array — массив записей справочника
     */
    public function loadReference($type)
    {
        if (isset($this->cache[$type])) {
            return $this->cache[$type];
        }

        $filePath = $this->referencesDir . DIRECTORY_SEPARATOR . $type . '.json';

        if (!file_exists($filePath)) {
            $this->logger->warning("Справочник {$type}: файл не найден ({$filePath})");
            $this->cache[$type] = array();
            return $this->cache[$type];
        }

        $content = file_get_contents($filePath);
        if ($content === false || trim($content) === '') {
            $this->logger->warning("Справочник {$type}: файл пуст ({$filePath})");
            $this->cache[$type] = array();
            return $this->cache[$type];
        }

        $data = json_decode($content, true);
        if (!is_array($data)) {
            $this->logger->warning("Справочник {$type}: невалидный JSON ({$filePath})");
            $this->cache[$type] = array();
            return $this->cache[$type];
        }

        $this->cache[$type] = $data;
        return $this->cache[$type];
    }

    /**
     * Поиск записи в справочнике по полю code.
     *
     * @param string $type — имя справочника
     * @param string $code — значение для поиска
     * @return array|null — array('uid'=>..., 'code'=>..., 'name'=>...) или null
     */
    public function findByCode($type, $code)
    {
        $data = $this->loadReference($type);
        if (empty($data) || $code === '') {
            return null;
        }

        foreach ($data as $entry) {
            if (!isset($entry['code'])) {
                continue;
            }

            // Кроме code сверяем aliases: в 1С встречаются названия,
            // не совпадающие с кодом из файла поставщика (например «руб.» и RUB)
            $variants = array((string)$entry['code']);
            if (isset($entry['aliases']) && is_array($entry['aliases'])) {
                $variants = array_merge($variants, $entry['aliases']);
            }

            foreach ($variants as $variant) {
                if ((string)$variant === (string)$code) {
                    return array(
                        'uid'  => $this->resolveEntryUid($entry),
                        'code' => (string)$entry['code'],
                        'name' => isset($entry['name']) ? (string)$entry['name'] : (string)$entry['code']
                    );
                }
            }
        }

        return null;
    }

    /**
     * UID записи справочника с учётом профиля среды.
     * При references.uid_profile = "test" берётся uid_test (если задан), иначе uid.
     *
     * @param array $entry — запись справочника
     * @return string
     */
    private function resolveEntryUid($entry)
    {
        $profile = isset($this->settings['uid_profile'])
            ? strtolower(trim((string)$this->settings['uid_profile']))
            : 'prod';

        if ($profile === 'test') {
            $uidTest = isset($entry['uid_test']) ? trim((string)$entry['uid_test']) : '';
            if ($uidTest !== '') {
                return $uidTest;
            }
        }

        return isset($entry['uid']) ? (string)$entry['uid'] : '';
    }

    /**
     * Поиск агента по ФИО.
     *
     * В выгрузке 1С у агентов нет UID — только код, а ФИО записано полностью
     * («Перекрестова Елизавета Евгеньевна»), тогда как в заказе приходит
     * короткая форма («Елизавета Перекрестова»). Поэтому поиск двухступенчатый:
     * сначала точное совпадение, затем — вхождение слов заказа в ФИО из 1С.
     *
     * @param string $fio — ФИО из заказа
     * @param bool &$ambiguous — по ссылке: true, если совпадений больше одного
     * @return array|null — array('code'=>..., 'name'=>...) или null
     */
    public function findAgentByName($fio, &$ambiguous = false)
    {
        $ambiguous = false;

        $fio = trim($fio);
        if ($fio === '') {
            return null;
        }

        $data = $this->loadReference('agents');
        if (empty($data)) {
            return null;
        }

        $needle = self::normalizeName($fio);
        if ($needle === '') {
            return null;
        }
        $needleTokens = explode(' ', $needle);

        $candidates = array();

        foreach ($data as $entry) {
            if (!isset($entry['code'])) {
                continue;
            }

            $entryName = isset($entry['name']) ? (string)$entry['name'] : '';
            $variants = array($entryName);
            if (isset($entry['aliases']) && is_array($entry['aliases'])) {
                $variants = array_merge($variants, $entry['aliases']);
            }

            foreach ($variants as $variant) {
                $normalized = self::normalizeName((string)$variant);
                if ($normalized === '') {
                    continue;
                }

                // Точное совпадение — сразу возвращаем
                if ($normalized === $needle) {
                    return array(
                        'code' => (string)$entry['code'],
                        'name' => $entryName !== '' ? $entryName : (string)$entry['code']
                    );
                }

                // Все слова из заказа входят в ФИО из 1С (без отчества, порядок не важен)
                if (count(array_diff($needleTokens, explode(' ', $normalized))) === 0) {
                    $candidates[(string)$entry['code']] = array(
                        'code' => (string)$entry['code'],
                        'name' => $entryName !== '' ? $entryName : (string)$entry['code']
                    );
                    break;
                }
            }
        }

        if (count($candidates) === 1) {
            return reset($candidates);
        }
        if (count($candidates) > 1) {
            $ambiguous = true;
        }

        return null;
    }

    /**
     * Приведение ФИО к сравнимому виду: нижний регистр, «ё» в «е»,
     * знаки препинания в пробелы, схлопывание пробелов.
     *
     * @param string $value — исходная строка
     * @return string
     */
    private static function normalizeName($value)
    {
        $value = mb_strtolower(trim($value), 'UTF-8');
        $value = str_replace('ё', 'е', $value);
        $value = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $value);

        return trim(preg_replace('/\s+/u', ' ', $value));
    }

    /**
     * Построение объекта {UID, CODE, NAME} для поля ORDER.
     * Если запись найдена — UID из справочника, иначе UID="" и WARNING.
     *
     * @param string $type — имя справочника
     * @param string $code — код для поиска
     * @param string $fileName — имя файла (для лога)
     * @param array &$warnings — массив предупреждений (по ссылке)
     * @return array — array('UID'=>..., 'CODE'=>..., 'NAME'=>...)
     */
    private function buildRefObject($type, $code, $fileName, &$warnings)
    {
        $found = $this->findByCode($type, $code);

        if ($found !== null) {
            return array(
                'UID'  => $found['uid'],
                'CODE' => $found['code'],
                'NAME' => $found['name']
            );
        }

        $msg = "Справочник {$type}: UID не найден для code '{$code}' (файл: {$fileName})";
        $this->logger->warning($msg);
        $warnings[] = $msg;

        return array(
            'UID'  => '',
            'CODE' => $code,
            'NAME' => $code
        );
    }

    /**
     * Построение объекта агента для поля ORDER.
     *
     * В отличие от остальных справочников UID не подставляется — в выгрузке 1С
     * его нет. В CODE попадает код агента из 1С, в NAME остаётся ФИО из заказа.
     * Если агента в справочнике нет, уходит заглушка UNKNOWN_AGENT_CODE: ФИО
     * в CODE 1С не принимает — ищет по нему агента и отклоняет заказ.
     *
     * @param array $agent — исходный объект AGENT или BOOKING_AGENT из заказа
     * @param string $fileName — имя файла (для лога)
     * @param array &$warnings — массив предупреждений (по ссылке)
     * @return array — array('CODE'=>..., 'NAME'=>...)
     */
    private function buildAgentObject($agent, $fileName, &$warnings)
    {
        $fio = isset($agent['CODE']) ? trim((string)$agent['CODE']) : '';
        $name = isset($agent['NAME']) && trim((string)$agent['NAME']) !== ''
            ? (string)$agent['NAME']
            : $fio;

        if ($fio === '') {
            return array('CODE' => self::UNKNOWN_AGENT_CODE, 'NAME' => self::UNKNOWN_AGENT_NAME);
        }

        $ambiguous = false;
        $found = $this->findAgentByName($fio, $ambiguous);

        if ($found !== null) {
            return array('CODE' => $found['code'], 'NAME' => $name);
        }

        $msg = $ambiguous
            ? "Справочник agents: несколько совпадений для '{$fio}', уходит код "
                . self::UNKNOWN_AGENT_CODE . " (файл: {$fileName})"
            : "Справочник agents: код не найден для '{$fio}', уходит код "
                . self::UNKNOWN_AGENT_CODE . " (файл: {$fileName})";
        $this->logger->warning($msg);
        $warnings[] = $msg;

        return array('CODE' => self::UNKNOWN_AGENT_CODE, 'NAME' => self::UNKNOWN_AGENT_NAME);
    }

    /**
     * Обогащение ORDER полями UID из справочников.
     *
     * @param array $order — ORDER (ассоциативный массив)
     * @param string $supplierFolder — folder парсера (moyagent, smarttravel)
     * @param string $fileName — имя исходного файла (для логов)
     * @return array — array('order' => $enrichedOrder, 'warnings' => array(...))
     */
    public function enrich($order, $supplierFolder, $fileName)
    {
        $warnings = array();

        // --- CLIENT ---
        // Код клиента из файла поставщика (MA1PA6 у «Мой агент», PosSysName
        // у SmartTravel) в 1С не используется. Контрагент заказа определяется
        // на стороне 1С, поэтому по ТЗ передаём null (поле его допускает).
        $order['CLIENT'] = null;

        if (!isset($order['PRODUCTS']) || !is_array($order['PRODUCTS'])) {
            return array('order' => $order, 'warnings' => $warnings);
        }

        foreach ($order['PRODUCTS'] as $pIdx => $product) {
            // --- SUPPLIER ---
            if (isset($product['SUPPLIER'])) {
                $order['PRODUCTS'][$pIdx]['SUPPLIER'] = $this->buildRefObject(
                    'suppliers', $supplierFolder, $fileName, $warnings
                );
            }

            // --- CARRIER ---
            // Парсеры отдают перевозчика строкой (IATA «SU» у авиа) —
            // приводим к объекту {UID, CODE, NAME} из справочника airlines
            if (isset($product['CARRIER'])) {
                $carrierValue = $product['CARRIER'];
                if (is_string($carrierValue) && trim($carrierValue) !== '') {
                    $order['PRODUCTS'][$pIdx]['CARRIER'] = $this->buildRefObject(
                        'airlines', trim($carrierValue), $fileName, $warnings
                    );
                } elseif (is_array($carrierValue) && isset($carrierValue['CODE'])) {
                    $found = $this->findByCode('airlines', $carrierValue['CODE']);
                    $order['PRODUCTS'][$pIdx]['CARRIER']['UID'] = $found !== null ? $found['uid'] : '';
                }
            }

            // --- AGENT ---
            if (isset($product['AGENT']) && is_array($product['AGENT']) && isset($product['AGENT']['CODE'])) {
                $order['PRODUCTS'][$pIdx]['AGENT'] = $this->buildAgentObject(
                    $product['AGENT'], $fileName, $warnings
                );
            }

            // --- BOOKING_AGENT ---
            if (isset($product['BOOKING_AGENT']) && is_array($product['BOOKING_AGENT']) && isset($product['BOOKING_AGENT']['CODE'])) {
                $order['PRODUCTS'][$pIdx]['BOOKING_AGENT'] = $this->buildAgentObject(
                    $product['BOOKING_AGENT'], $fileName, $warnings
                );
            }

            // --- CURRENCY ---
            if (isset($product['CURRENCY'])) {
                $currencyValue = $product['CURRENCY'];
                if (is_string($currencyValue)) {
                    $order['PRODUCTS'][$pIdx]['CURRENCY'] = $this->buildRefObject(
                        'currencies', $currencyValue, $fileName, $warnings
                    );
                } elseif (is_array($currencyValue) && isset($currencyValue['CODE'])) {
                    $found = $this->findByCode('currencies', $currencyValue['CODE']);
                    if ($found !== null) {
                        $order['PRODUCTS'][$pIdx]['CURRENCY']['UID'] = $found['uid'];
                    } else {
                        $order['PRODUCTS'][$pIdx]['CURRENCY']['UID'] = '';
                        $msg = "Справочник currencies: UID не найден для code '{$currencyValue['CODE']}' (файл: {$fileName})";
                        $this->logger->warning($msg);
                        $warnings[] = $msg;
                    }
                }
            }

            // --- COUPONS ---
            // CARRIER берём из исходного продукта: он мог быть как строкой, так и объектом
            $carrierCode = '';
            if (isset($product['CARRIER'])) {
                $carrierCode = is_array($product['CARRIER'])
                    ? (isset($product['CARRIER']['CODE']) ? trim((string)$product['CARRIER']['CODE']) : '')
                    : trim((string)$product['CARRIER']);
            }

            if (isset($product['COUPONS']) && is_array($product['COUPONS'])) {
                foreach ($product['COUPONS'] as $cIdx => $coupon) {
                    // DEPARTURE_AIRPORT
                    if (isset($coupon['DEPARTURE_AIRPORT']) && is_string($coupon['DEPARTURE_AIRPORT'])) {
                        $order['PRODUCTS'][$pIdx]['COUPONS'][$cIdx]['DEPARTURE_AIRPORT'] = $this->buildRefObject(
                            'airports', $coupon['DEPARTURE_AIRPORT'], $fileName, $warnings
                        );
                    }

                    // ARRIVAL_AIRPORT
                    if (isset($coupon['ARRIVAL_AIRPORT']) && is_string($coupon['ARRIVAL_AIRPORT'])) {
                        $order['PRODUCTS'][$pIdx]['COUPONS'][$cIdx]['ARRIVAL_AIRPORT'] = $this->buildRefObject(
                            'airports', $coupon['ARRIVAL_AIRPORT'], $fileName, $warnings
                        );
                    }

                    // AIRLINE — создаём из product-level CARRIER
                    if ($carrierCode !== '') {
                        $order['PRODUCTS'][$pIdx]['COUPONS'][$cIdx]['AIRLINE'] = $this->buildRefObject(
                            'airlines', $carrierCode, $fileName, $warnings
                        );
                    }

                    // SERVICE_CLASS — создаём из coupon CLASS
                    $classCode = isset($coupon['CLASS']) ? (string)$coupon['CLASS'] : '';
                    if ($classCode !== '') {
                        $order['PRODUCTS'][$pIdx]['COUPONS'][$cIdx]['SERVICE_CLASS'] = $this->buildRefObject(
                            'service_classes', $classCode, $fileName, $warnings
                        );
                    }
                }
            }
        }

        return array('order' => $order, 'warnings' => $warnings);
    }

    /**
     * Синхронизация одного справочника через API 1С.
     * На текущем этапе — заглушка: если api_url пуст, WARNING и return false.
     *
     * @param string $type — имя справочника
     * @return bool — true если синхронизация успешна
     */
    public function syncReference($type)
    {
        $apiUrl = isset($this->settings['api_url']) ? trim($this->settings['api_url']) : '';
        if ($apiUrl === '') {
            $this->logger->warning("Синхронизация справочника {$type}: api_url не задан");
            return false;
        }

        $types = isset($this->settings['types']) ? $this->settings['types'] : array();
        if (!isset($types[$type])) {
            $this->logger->warning("Синхронизация справочника {$type}: тип не найден в настройках");
            return false;
        }

        $typeConfig = $types[$type];
        if (isset($typeConfig['enabled']) && $typeConfig['enabled'] === false) {
            $this->logger->info("Синхронизация справочника {$type}: отключена в настройках");
            return false;
        }

        $endpoint = isset($typeConfig['endpoint']) ? $typeConfig['endpoint'] : '';
        $url = rtrim($apiUrl, '/') . $endpoint;

        $login = isset($this->settings['api_login']) ? $this->settings['api_login'] : '';
        $password = isset($this->settings['api_password']) ? $this->settings['api_password'] : '';
        $timeout = isset($this->settings['api_timeout']) ? (int)$this->settings['api_timeout'] : 30;

        $this->logger->info("Синхронизация справочника {$type}: запрос к {$url}");

        $result = Utils::curlWithProxy($url, array(
            'method' => 'GET',
            'auth_login' => $login,
            'auth_password' => $password,
            'timeout' => $timeout,
            'headers' => array('Accept: application/json')
        ));

        if ($result['http_code'] !== 200 || $result['error'] !== '') {
            $this->logger->warning("Синхронизация справочника {$type}: ошибка HTTP {$result['http_code']}, {$result['error']}");
            return false;
        }

        $data = json_decode($result['body'], true);
        if (!is_array($data)) {
            $this->logger->warning("Синхронизация справочника {$type}: невалидный JSON в ответе");
            return false;
        }

        $filePath = $this->referencesDir . DIRECTORY_SEPARATOR . $type . '.json';
        $jsonContent = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        $written = file_put_contents($filePath, $jsonContent, LOCK_EX);

        if ($written === false) {
            $this->logger->warning("Синхронизация справочника {$type}: не удалось записать файл {$filePath}");
            return false;
        }

        Utils::ensureOwnership($filePath);

        // Сбрасываем кеш для этого типа
        unset($this->cache[$type]);

        $this->logger->info("Синхронизация справочника {$type}: успешно, записей: " . count($data));
        return true;
    }

    /**
     * Синхронизация всех включённых справочников.
     *
     * @return array — результат: type => bool (успех/неудача)
     */
    public function syncAll()
    {
        $results = array();
        $types = $this->getAvailableTypes();

        foreach ($types as $type) {
            $typeConfig = isset($this->settings['types'][$type]) ? $this->settings['types'][$type] : array();
            $enabled = isset($typeConfig['enabled']) ? $typeConfig['enabled'] : true;
            if ($enabled) {
                $results[$type] = $this->syncReference($type);
            } else {
                $results[$type] = false;
            }
        }

        return $results;
    }

    /**
     * Возвращает массив имён доступных типов справочников.
     *
     * @return array — например array('suppliers', 'agents', 'airports', ...)
     */
    public function getAvailableTypes()
    {
        $types = isset($this->settings['types']) ? $this->settings['types'] : array();
        if (!empty($types)) {
            return array_keys($types);
        }

        return array(
            'suppliers', 'clients', 'agents', 'airports', 'airlines',
            'service_classes', 'currencies', 'cities', 'countries'
        );
    }
}
