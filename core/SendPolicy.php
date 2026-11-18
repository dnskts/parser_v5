<?php
/**
 * ============================================================
 * ПОЛИТИКА ОТПРАВКИ ЗАКАЗОВ В 1С
 * ============================================================
 *
 * Для каждого поставщика можно включать и выключать отправку
 * отдельных типов услуг: продажи, возвраты, обмены.
 * Настройки задаются на странице setup.php и хранятся в
 * config/settings.json:
 *
 *   "setup": {
 *       "suppliers": {
 *           "moyagent": { "sale": true, "refund": false, "exchange": true }
 *       }
 *   }
 *
 * Если поставщика в настройках нет (например, только что добавили
 * новый парсер), разрешены все типы — поведение как до доработки.
 *
 * Политика влияет ТОЛЬКО на HTTP-отправку в 1С: разбор файла,
 * json/, json_api/ и перенос в Processed/ работают всегда.
 * ============================================================
 */

class SendPolicy
{
    /** @var array Ключ типа услуги => значение STATUS в заказе */
    private static $typeStatuses = array(
        'sale'     => 'продажа',
        'refund'   => 'возврат',
        'exchange' => 'обмен'
    );

    /** @var array Ключ типа услуги => подпись для веб-интерфейса */
    private static $typeLabels = array(
        'sale'     => 'Продажи',
        'refund'   => 'Возвраты',
        'exchange' => 'Обмены'
    );

    /** @var array Настройки поставщиков: папка => array(тип => bool) */
    private $suppliers = array();

    /**
     * @param array $setupConfig — секция setup из settings.json
     */
    public function __construct($setupConfig)
    {
        if (is_array($setupConfig)
            && isset($setupConfig['suppliers'])
            && is_array($setupConfig['suppliers'])
        ) {
            $this->suppliers = $setupConfig['suppliers'];
        }
    }

    /**
     * Ключи типов услуг: sale, refund, exchange.
     *
     * @return array
     */
    public static function getTypes()
    {
        return array_keys(self::$typeStatuses);
    }

    /**
     * Подписи типов для интерфейса: ключ => название.
     *
     * @return array
     */
    public static function getTypeLabels()
    {
        return self::$typeLabels;
    }

    /**
     * STATUS продукта → ключ типа услуги.
     *
     * @param string $status — «продажа», «возврат» или «обмен»
     * @return string — ключ типа или '' для незнакомого статуса
     */
    public static function statusToType($status)
    {
        $needle = trim((string)$status);
        if ($needle === '') {
            return '';
        }
        if (function_exists('mb_strtolower')) {
            $needle = mb_strtolower($needle, 'UTF-8');
        }

        foreach (self::$typeStatuses as $type => $statusName) {
            if ($needle === $statusName) {
                return $type;
            }
        }
        return '';
    }

    /**
     * Разрешена ли отправка этого типа услуги для поставщика.
     *
     * @param string $folder — папка поставщика
     * @param string $type — ключ типа (sale, refund, exchange)
     * @return bool
     */
    public function isTypeEnabled($folder, $type)
    {
        if (!isset($this->suppliers[$folder]) || !is_array($this->suppliers[$folder])) {
            return true;
        }

        $flags = $this->suppliers[$folder];
        if (!isset($flags[$type])) {
            return true;
        }
        return (bool)$flags[$type];
    }

    /**
     * Галки поставщика с подставленными значениями по умолчанию.
     * Используется страницей setup.php для отрисовки чекбоксов.
     *
     * @param string $folder — папка поставщика
     * @return array — тип => bool
     */
    public function getSupplierFlags($folder)
    {
        $flags = array();
        foreach (self::getTypes() as $type) {
            $flags[$type] = $this->isTypeEnabled($folder, $type);
        }
        return $flags;
    }

    /**
     * Можно ли отправить заказ в 1С.
     *
     * Заказ уходит в 1С одним документом, поэтому отключённый тип
     * хотя бы у одного продукта блокирует отправку всего заказа.
     * Незнакомый STATUS не блокирует: лучше отправить, чем потерять.
     *
     * @param string $folder — папка поставщика (moyagent, smarttravel…)
     * @param array $orderData — заказ в формате ORDER
     * @return array — allowed (bool), blocked_type (string), blocked_status (string)
     */
    public function checkOrder($folder, $orderData)
    {
        $result = array('allowed' => true, 'blocked_type' => '', 'blocked_status' => '');

        if ($folder === ''
            || !is_array($orderData)
            || !isset($orderData['PRODUCTS'])
            || !is_array($orderData['PRODUCTS'])
        ) {
            return $result;
        }

        foreach ($orderData['PRODUCTS'] as $product) {
            if (!is_array($product) || !isset($product['STATUS'])) {
                continue;
            }

            $type = self::statusToType($product['STATUS']);
            if ($type === '' || $this->isTypeEnabled($folder, $type)) {
                continue;
            }

            $result['allowed'] = false;
            $result['blocked_type'] = $type;
            $result['blocked_status'] = self::$typeStatuses[$type];
            return $result;
        }

        return $result;
    }

    /**
     * Папка поставщика по имени JSON-файла.
     *
     * Имена файлов: {папка}_{имя_исходного}_{Ymd_His}.json. В именах папок
     * бывает подчёркивание (demo_hotel), поэтому берём самое длинное совпадение.
     *
     * @param string $fileName — имя JSON-файла
     * @param array|null $folders — список папок; null — поставщики из настроек
     * @return string — папка или '' если определить не удалось
     */
    public function detectFolder($fileName, $folders = null)
    {
        if (!is_array($folders) || empty($folders)) {
            $folders = array_keys($this->suppliers);
        }

        $fileName = (string)$fileName;
        $match = '';
        foreach ($folders as $folder) {
            $prefix = $folder . '_';
            if (strpos($fileName, $prefix) === 0 && strlen($folder) > strlen($match)) {
                $match = $folder;
            }
        }
        return $match;
    }

    /**
     * Приводит присланные из интерфейса галки к виду для settings.json.
     * Берутся только папки зарегистрированных парсеров.
     *
     * @param array $rawSuppliers — папка => array(тип => значение)
     * @param array $allowedFolders — папки зарегистрированных парсеров
     * @return array — папка => array(тип => bool)
     */
    public static function normalizeSuppliers($rawSuppliers, $allowedFolders)
    {
        if (!is_array($rawSuppliers)) {
            $rawSuppliers = array();
        }

        $normalized = array();
        foreach ($allowedFolders as $folder) {
            $flags = isset($rawSuppliers[$folder]) && is_array($rawSuppliers[$folder])
                ? $rawSuppliers[$folder]
                : array();

            $row = array();
            foreach (self::getTypes() as $type) {
                // Интерфейс присылает все три ключа; отсутствующий считаем включённым
                $row[$type] = isset($flags[$type]) ? (bool)$flags[$type] : true;
            }
            $normalized[$folder] = $row;
        }

        return $normalized;
    }
}
