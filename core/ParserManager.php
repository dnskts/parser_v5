<?php
/**
 * ============================================================
 * МЕНЕДЖЕР ПАРСЕРОВ (АВТО-ОБНАРУЖЕНИЕ)
 * ============================================================
 * 
 * Этот файл отвечает за автоматический поиск и загрузку всех
 * парсеров поставщиков из папки parsers/.
 * 
 * Как это работает:
 * 1. При создании менеджер сканирует папку parsers/
 * 2. Находит все .php файлы
 * 3. Подключает каждый файл и создаёт экземпляр класса
 * 4. Проверяет, что класс реализует интерфейс ParserInterface
 * 5. Строит карту: "имя_папки" => объект_парсера
 * 
 * Благодаря этому для добавления нового поставщика достаточно:
 * - Создать один PHP-файл в папке parsers/
 * - Создать папку input/имя_поставщика/ с подпапками Processed и Error
 * 
 * Никакие другие файлы менять не нужно!
 * ============================================================
 */

// Подключаем интерфейс парсера, чтобы можно было проверять его реализацию
require_once __DIR__ . '/ParserInterface.php';

class ParserManager
{
    /** @var string Путь к папке с парсерами */
    private $parsersDir;

    /** 
     * @var array Карта парсеров: ключ — имя папки поставщика, значение — объект парсера
     * Например: ['moyagent' => MoyAgentParser, 'demo_hotel' => DemoHotelParser]
     */
    private $parsers = array();

    /** @var Logger Объект логгера для записи событий */
    private $logger;

    /**
     * Создание менеджера парсеров.
     * 
     * Сразу при создании запускается поиск и загрузка всех парсеров.
     * 
     * @param string $parsersDir — путь к папке с парсерами (например, "parsers/")
     * @param Logger $logger     — объект логгера
     */
    public function __construct($parsersDir, $logger)
    {
        $this->parsersDir = $parsersDir;
        $this->logger = $logger;

        // Запускаем автоматический поиск и загрузку парсеров
        $this->discoverParsers();
    }

    /**
     * Автоматический поиск и загрузка парсеров из папки parsers/.
     * 
     * Метод сканирует папку, подключает каждый .php файл,
     * находит в нём класс, реализующий ParserInterface,
     * и добавляет его в карту парсеров.
     */
    private function discoverParsers()
    {
        // Проверяем, существует ли папка с парсерами
        if (!is_dir($this->parsersDir)) {
            $this->logger->error("Папка с парсерами не найдена: {$this->parsersDir}");
            return;
        }

        // Получаем список всех .php файлов в папке parsers/
        $files = glob($this->parsersDir . '/*.php');

        if (empty($files)) {
            $this->logger->warning("В папке parsers/ не найдено ни одного парсера");
            return;
        }

        foreach ($files as $file) {
            // Запоминаем, какие классы уже были загружены ДО подключения файла
            $classesBefore = get_declared_classes();

            // Подключаем файл парсера
            require_once $file;

            // Находим, какие НОВЫЕ классы появились после подключения файла
            $classesAfter = get_declared_classes();
            $newClasses = array_diff($classesAfter, $classesBefore);

            foreach ($newClasses as $className) {
                // Проверяем, реализует ли новый класс интерфейс ParserInterface
                // Это гарантирует, что класс имеет все нужные методы
                $reflection = new ReflectionClass($className);
                if ($reflection->implementsInterface('ParserInterface') && !$reflection->isAbstract()) {
                    // Создаём экземпляр парсера
                    $parser = new $className();
                    
                    // Получаем имя папки, за которую отвечает этот парсер
                    $folder = $parser->getSupplierFolder();
                    
                    // Добавляем парсер в карту
                    $this->parsers[$folder] = $parser;
                    
                    $this->logger->info(
                        "Загружен парсер: {$parser->getSupplierName()} "
                        . "(папка: {$folder}, файл: " . basename($file) . ")"
                    );
                }
            }
        }

        // Выводим итоговую информацию
        $count = count($this->parsers);
        $this->logger->info("Всего загружено парсеров: {$count}");
    }

    /**
     * Возвращает парсер для указанной папки поставщика.
     * 
     * @param string $folder — имя папки (например, "moyagent")
     * @return ParserInterface|null — объект парсера или null, если парсер не найден
     */
    public function getParser($folder)
    {
        if (isset($this->parsers[$folder])) {
            return $this->parsers[$folder];
        }
        return null;
    }

    /**
     * Возвращает список всех зарегистрированных папок поставщиков.
     * 
     * Используется при обработке — система обходит все эти папки
     * и ищет в них XML-файлы для обработки.
     * 
     * @return array — массив имён папок (например, ['moyagent', 'demo_hotel'])
     */
    public function getRegisteredFolders()
    {
        return array_keys($this->parsers);
    }

    /**
     * Возвращает все загруженные парсеры.
     * 
     * @return array — карта: имя_папки => объект_парсера
     */
    public function getAllParsers()
    {
        return $this->parsers;
    }
}
