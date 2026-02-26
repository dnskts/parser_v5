# XML Parser v5 — Текущее состояние

**Последнее обновление:** 2026-02-26 18:00  
**Обновлено после:** Рефакторинг UUID → Utils, исправлен баг resend, добавлена страница автотестов test.php

---

## 1. Общее описание проекта

**XML Parser v5** — система обработки XML-файлов от поставщиков туристических услуг (авиабилеты, отели) с преобразованием в единый JSON-формат **ORDER** по спецификации **RSTLS** и отправкой во внешний API **1С:Предприятие**.

**Статистика проекта:**
- 14 PHP-файлов, ~3 550 строк PHP-кода
- 2 фронтенд-файла (JS + CSS), ~920 строк
- Итого: ~4 470 строк кода
- 2 парсера (MoyAgent — боевой, DemoHotel — шаблон)
- 4 тестовых fixture-файла (XML) в `tests/fixtures/`

Ключевые возможности:
- Автоматическое обнаружение парсеров (plug-and-play: положил файл — заработало)
- Обработка по расписанию (cron/планировщик задач) и вручную через веб-интерфейс
- Поддержка операций: продажа, возврат (REF/RFND), аннуляция (CANX)
- Отправка заказов в API 1С с подробным логированием
- Повторная отправка ранее обработанных заказов из веб-интерфейса
- Веб-панель управления с логами в реальном времени

---

## 2. Технологический стек

- **Backend:** PHP 7.0+ (совместим с PHP 8.x), без фреймворков
- **Frontend:** Vanilla JS (ES5, fetch API), CSS3
- **База данных:** Отсутствует — всё на файлах (JSON, XML, текстовые логи)
- **Инструменты сборки:** Отсутствуют — проект не требует сборки
- **Расширения PHP:**
  - `ext-simplexml` (встроенное) — парсинг XML
  - `ext-curl` (встроенное) — отправка HTTP-запросов в API 1С
  - `ext-json` (встроенное) — работа с JSON
  - `ext-mbstring` (рекомендуется) — работа с кириллицей
- **Внешние зависимости:** Нет (composer не используется)
- **Внешний API:** 1С:Предприятие HTTP-сервис `CRM_Exchange/Order`

---

## 3. Структура проекта

> Полная структура файлов: см. [structure.md](structure.md)

### Ключевые директории

```
parser_v5/
├── config/          — настройки приложения (settings.json)
├── core/            — ядро системы (6 файлов)
│   ├── ApiSender.php        — HTTP-клиент для 1С
│   ├── Logger.php           — логирование в app.log
│   ├── ParserInterface.php  — контракт парсеров
│   ├── ParserManager.php    — авто-обнаружение парсеров
│   ├── Processor.php        — оркестратор пайплайна
│   └── Utils.php            — общие утилиты (generateUUID)
├── parsers/         — реализации парсеров поставщиков (plug-and-play)
├── input/           — входные XML-файлы (по подпапкам поставщиков)
│   ├── moyagent/    — XML от «Мой агент»
│   │   ├── Processed/   — успешно обработанные
│   │   └── Error/       — с ошибками
│   └── demo_hotel/  — демо-XML отелей
│       ├── Processed/
│       └── Error/
├── json/            — выходные JSON-файлы (результат парсинга)
├── logs/            — логи приложения
│   ├── app.log          — основной лог (текстовый)
│   └── api_send.log     — лог отправки в 1С (JSON Lines)
├── tests/           — тестовые данные
│   └── fixtures/    — XML-фикстуры для автотестов (4 файла)
├── assets/          — фронтенд (JS, CSS)
└── .cursor/skills/  — скиллы для AI-ассистента
```

### Ключевые файлы

| Файл | Назначение |
|------|-----------|
| `process.php` | Точка входа пайплайна (cron + модуль для api.php) |
| `api.php` | AJAX API для веб-интерфейса |
| `core/Processor.php` | Оркестратор: обход папок → парсинг → сохранение JSON → отправка в 1С |
| `core/ParserManager.php` | Авто-обнаружение парсеров через рефлексию |
| `core/ApiSender.php` | HTTP-клиент для отправки в 1С |
| `core/Utils.php` | Общие утилиты (`Utils::generateUUID()`) |
| `parsers/MoyAgentParser.php` | Боевой парсер авиабилетов «Мой агент» |
| `test.php` | Страница автотестов парсеров (Web + CLI) |
| `parsers/DemoHotelParser.php` | Демо-парсер отелей (шаблон для новых парсеров) |

---

## 4. Архитектура и взаимосвязи

### 4.1. Полный pipeline обработки

```
┌─────────────────────────────────────────────────────────────────────┐
│                     ТОЧКИ ВХОДА (Entry Points)                      │
│                                                                     │
│   [cron / CLI]                        [Web UI — браузер]            │
│   php process.php                     index.php → кнопка «Запуск»   │
│        │                                    │                       │
│        ▼                                    ▼                       │
│   runProcessing(false)              fetch('api.php?action=run')     │
│   (проверяет интервал)                      │                       │
│        │                                    ▼                       │
│        │                              api.php (POST)                │
│        │                              require process.php           │
│        │                              runProcessing(true)           │
│        │                              (force — без интервала)       │
│        └──────────────┬─────────────────────┘                       │
│                       ▼                                             │
│              ┌─────────────────┐                                    │
│              │  process.php    │                                    │
│              │  runProcessing()│                                    │
│              └───────┬─────────┘                                    │
│                      │ создаёт Logger, ParserManager, Processor     │
│                      ▼                                              │
│              ┌─────────────────┐                                    │
│              │  ParserManager  │                                    │
│              │  (auto-discovery│                                    │
│              │   parsers/*.php)│                                    │
│              └───────┬─────────┘                                    │
│                      │ возвращает карту: folder → parser            │
│                      ▼                                              │
│              ┌─────────────────┐                                    │
│              │   Processor     │                                    │
│              │   ->run()       │                                    │
│              └───────┬─────────┘                                    │
│                      │                                              │
│         ┌────────────┼────────────┐                                 │
│         ▼            ▼            ▼                                  │
│   input/moyagent/ input/demo_hotel/ input/другой_поставщик/         │
│   *.xml файлы     *.xml файлы      *.xml файлы                     │
│         │            │            │                                  │
│         ▼            ▼            ▼                                  │
│   ┌──────────┐ ┌──────────────┐ ┌──────────┐                       │
│   │MoyAgent  │ │DemoHotel     │ │Новый     │                       │
│   │Parser    │ │Parser        │ │Parser    │                       │
│   │.parse()  │ │.parse()      │ │.parse()  │                       │
│   └────┬─────┘ └──────┬───────┘ └────┬─────┘                       │
│        └──────────────┬──────────────┘                              │
│                       ▼                                             │
│               Массив ORDER (PHP array)                              │
│                       │                                             │
│           ┌───────────┼───────────┐                                 │
│           ▼                       ▼                                  │
│   ┌───────────────┐     ┌──────────────┐                            │
│   │ saveJson()    │     │ ApiSender    │                            │
│   │ → json/*.json │     │ ->send()     │                            │
│   └───────────────┘     │ → HTTP POST  │                            │
│                         │   в API 1С   │                            │
│   XML перемещается      └──────┬───────┘                            │
│   в Processed/ или             │                                    │
│   Error/                       ▼                                    │
│                         logs/api_send.log                           │
│                                                                     │
│   Всё логируется в → logs/app.log                                   │
└─────────────────────────────────────────────────────────────────────┘
```

### 4.2. Потоки данных Web UI

```
┌─────────────────────────────────────────────────────────────┐
│                          БРАУЗЕР                             │
│                                                             │
│  index.php ──── assets/app.js ──── assets/style.css         │
│  (главная)      (AJAX-логика)      (стили — общие)          │
│      │                                                      │
│      │  fetch('api.php?action=logs')     → читает app.log   │
│      │  fetch('api.php?action=run')      → вызывает process │
│      │  fetch('api.php?action=settings') → читает/пишет     │
│      │  fetch('api.php?action=clear_logs')→ чистит app.log  │
│      │                                                      │
│  data.php ──── серверный рендеринг (без AJAX) ──── style.css│
│  (таблица)      читает json/*.json напрямую                 │
│      │                                                      │
│      │  fetch('api.php?action=resend')   → повторная        │
│      │                                     отправка в 1С    │
│      │                                                      │
│  api_logs.php ──── встроенный JS (без app.js) ──── style.css│
│  (логи API)     AJAX к самому себе:                         │
│                 api_logs.php?action=get_logs                 │
│                 api_logs.php?action=clear_logs               │
│                 api_logs.php?action=get_settings             │
│                 читает logs/api_send.log                     │
└─────────────────────────────────────────────────────────────┘
```

### 4.3. Ключевые архитектурные решения

1. **Auto-discovery парсеров через рефлексию:**  
   `ParserManager` при инициализации сканирует `parsers/*.php`, подключает каждый файл, сравнивает `get_declared_classes()` до и после, находит новые классы, проверяет `ReflectionClass::implementsInterface('ParserInterface')`. Для нового поставщика достаточно создать один PHP-файл.

2. **Файловое хранилище вместо БД:**  
   Все данные — в файлах: настройки в JSON, логи в текстовых файлах, результаты в JSON. Упрощает деплой (не нужна СУБД), но ограничивает масштабирование.

3. **Двойная точка входа:**  
   `process.php` работает и как CLI-скрипт (cron), и как модуль (require из `api.php`). Определение режима — через `php_sapi_name() === 'cli'`.

4. **Два раздельных лога:**  
   `logs/app.log` — текстовый, для основного пайплайна (Logger). `logs/api_send.log` — JSON Lines, для отправки в 1С (ApiSender). Разделение позволяет независимо просматривать и ротировать.

5. **Интервальный контроль:**  
   При запуске через cron `Processor.isIntervalPassed()` проверяет `settings.json → last_run` + `interval`. При ручном запуске из UI — `force=true`, интервал игнорируется.

### 4.4. Processor — детальная логика run()

**Файл:** `core/Processor.php`, метод `run()` (строки 87–227)

Пошаговый алгоритм:

```
1. Проверка интервала
   if (!$force && !isIntervalPassed()) → return (пропуск)

2. Получение зарегистрированных папок
   $folders = ParserManager->getRegisteredFolders()
   // Возвращает карту: ['moyagent' => MoyAgentParser, 'demo_hotel' => DemoHotelParser]

3. Проверка доступности API 1С
   $apiAvailable = ApiSender->isAvailable()
   // HEAD-запрос, CURLOPT_CONNECTTIMEOUT = 2, CURLOPT_TIMEOUT = 3
   // true если http_code > 0

4. Цикл по папкам поставщиков
   foreach ($folders as $folder => $parser):
     a. Проверка существования input/$folder/
     b. Создание подпапок: ensureSubfolders()
        → input/$folder/Processed/ (0755)
        → input/$folder/Error/ (0755)
     c. Поиск XML: glob("input/$folder/*.xml")
     
     d. Цикл по XML-файлам
        foreach ($xmlFiles as $xmlFile):
          try:
            i.   $result = $parser->parse($xmlFile)
            ii.  $jsonFile = saveJson($result, $folder, $xmlFile)
                 // Формат имени: {folder}_{xmlBaseName}_{Ymd_His}.json
                 // При коллизии: добавляется _{Ymd_His} к имени
            iii. moveFile($xmlFile, "input/$folder/Processed/")
                 // При коллизии имени: добавляется _{Ymd_His}
            iv.  if ($apiAvailable && api.enabled):
                   ApiSender->send($result, $jsonFile, basename($xmlFile))
          catch (Exception):
            v.   moveFile($xmlFile, "input/$folder/Error/")
            vi.  Logger->error("Ошибка: " + exception message)

5. Обновление времени
   updateLastRunTime() → settings.json.last_run = time()
   // Выполняется ВСЕГДА, даже если файлов не было
```

**Формат имени JSON-файла:** `{supplierFolder}_{xmlBaseName}_{Ymd_His}.json`  
Пример: `moyagent_мой_агент_авиа_продажа_20260216_132617.json`

### 4.5. Logger — система логирования

**Файл:** `core/Logger.php`

**Формат строки в `logs/app.log`:**
```
[2026-02-26 13:26:17] [INFO] Начало обработки
[2026-02-26 13:26:17] [SUCCESS] Обработано файлов: 3
[2026-02-26 13:26:18] [WARNING] API 1С недоступен
[2026-02-26 13:26:18] [ERROR] Ошибка парсинга: invalid XML
```

**Уровни логирования:** INFO, WARNING, ERROR, SUCCESS

**Публичные методы:**
| Метод | Описание |
|-------|----------|
| `info($msg)` | Информационное сообщение |
| `warning($msg)` | Предупреждение |
| `error($msg)` | Ошибка |
| `success($msg)` | Успешная операция |
| `getLastLines($n)` | Последние N строк лога (для UI, по умолчанию 200) |
| `clear()` | Полная очистка лога |

**Механизм ротации:**
- Триггер: размер файла превышает 5 242 880 байт (5 МБ)
- Действие: `app.log` переименовывается в `app.log.old`
- Если `app.log.old` уже существует — он удаляется (перезаписывается)
- Хранится только 1 архив (формат имени: `{filename}.old`, т.е. `app.log.old`)

**Запись в файл:** `file_put_contents()` с флагами `FILE_APPEND | LOCK_EX` (атомарная запись с блокировкой)

---

## 5. Формат ORDER / RSTLS

### 5.1. Что такое ORDER и RSTLS

- **ORDER** — внутренний формат JSON-заказа, который генерируют парсеры
- **RSTLS** — спецификация (стандарт), которой соответствует формат ORDER
- Все парсеры обязаны возвращать массив в формате ORDER
- JSON отправляется в 1С как есть (за исключением полей `SOURCE_FILE` и `PARSED_AT`, которые удаляются перед отправкой)

### 5.2. Структура ORDER (полная)

```json
{
  "UID": "8fd8578c-c002-4e73-891d-278373b59ef4",
  "INVOICE_NUMBER": "125358044001",
  "INVOICE_DATA": "20251013121600",
  "CLIENT": "SOFI",
  "SOURCE_FILE": "мой агент авиа продажа.xml",
  "PARSED_AT": "2026-02-16 13:26:17",
  "PRODUCTS": [
    {
      "UID": "a1b2c3d4-...",
      "PRODUCT_TYPE": {
        "NAME": "Авиабилет",
        "CODE": "000000001"
      },
      "NUMBER": "2982412850777",
      "ISSUE_DATE": "20251009135200",
      "RESERVATION_NUMBER": "XJKLMN",
      "BOOKING_AGENT": { "CODE": "020", "NAME": "" },
      "AGENT": { "CODE": "020", "NAME": "" },
      "STATUS": "продажа",
      "TICKET_TYPE": "OWN",
      "PASSENGER_AGE": "ADULT",
      "CONJ_COUNT": 0,
      "PENALTY": 0,
      "CARRIER": "SU",
      "SUPPLIER": "1H",
      "COUPONS": [
        {
          "FLIGHT_NUMBER": "SU1234",
          "FARE_BASIS": "YOWRT",
          "DEPARTURE_AIRPORT": "SVO",
          "DEPARTURE_DATETIME": "20251120080000",
          "ARRIVAL_AIRPORT": "LED",
          "ARRIVAL_DATETIME": "20251120093000",
          "CLASS": "Y"
        }
      ],
      "TRAVELLER": "IVANOV IVAN",
      "TAXES": [
        { "CODE": "", "AMOUNT": 15000.00, "EQUIVALENT_AMOUNT": 15000.00, "VAT_RATE": 0, "VAT_AMOUNT": 0 },
        { "CODE": "YQ", "AMOUNT": 2800.00, "EQUIVALENT_AMOUNT": 2800.00, "VAT_RATE": 0, "VAT_AMOUNT": 0 },
        { "CODE": "RI", "AMOUNT": 185.00, "EQUIVALENT_AMOUNT": 185.00, "VAT_RATE": 0, "VAT_AMOUNT": 0 }
      ],
      "CURRENCY": "RUB",
      "PAYMENTS": [
        { "TYPE": "INVOICE", "AMOUNT": 17985.00, "EQUIVALENT_AMOUNT": 17985.00, "RELATED_TICKET_NUMBER": null }
      ],
      "COMMISSIONS": [
        { "TYPE": "CLIENT", "NAME": "Сбор поставщика", "AMOUNT": 150.00, "EQUIVALENT_AMOUNT": 150.00, "RATE": null },
        { "TYPE": "VENDOR", "NAME": "Комиссия поставщика", "AMOUNT": 300.00, "EQUIVALENT_AMOUNT": 300.00, "RATE": null }
      ]
    }
  ]
}
```

### 5.3. Обязательные поля ORDER

| Уровень | Поле | Тип | Описание |
|---------|------|-----|----------|
| Корень | `UID` | string (UUID v4) | Уникальный ID заказа |
| Корень | `INVOICE_NUMBER` | string | Номер заказа |
| Корень | `INVOICE_DATA` | string (YYYYMMDDHHmmss) | Дата заказа |
| Корень | `CLIENT` | string | Код клиента |
| Корень | `PRODUCTS` | array | Массив продуктов (>=1) |
| Product | `UID` | string (UUID v4) | Уникальный ID продукта |
| Product | `PRODUCT_TYPE` | object | `{NAME, CODE}` — тип продукта |
| Product | `NUMBER` | string | Номер билета |
| Product | `STATUS` | string | `"продажа"` / `"возврат"` / `"обмен"` |
| Product | `TRAVELLER` | string | ФИО пассажира (ФАМИЛИЯ ИМЯ) |
| Product | `TAXES` | array | Массив такс (первый элемент — тариф с пустым CODE) |
| Product | `PAYMENTS` | array | Массив платежей |

### 5.4. Служебные поля (не отправляются в 1С)

- `SOURCE_FILE` — имя исходного XML-файла (удаляется в `ApiSender.send()`)
- `PARSED_AT` — дата и время парсинга (удаляется в `ApiSender.send()`)

### 5.5. Блок TAXES — особенность

Первый элемент массива `TAXES` — это **тариф** (fare), а не такса. Он имеет пустой `CODE`:
```json
{ "CODE": "", "AMOUNT": 15000.00, ... }
```
Остальные элементы — реальные таксы с кодами (YQ, RI, PEN и т.д.).

### 5.6. Блок REFUND (только для возвратов)

Добавляется к продукту, когда `STATUS = "возврат"`:
```json
"REFUND": {
  "DATA": "20251115120000",
  "AMOUNT": 14500.00,
  "EQUIVALENT_AMOUNT": 14500.00,
  "FEE_CLIENT": 0,
  "FEE_VENDOR": 0,
  "PENALTY_CLIENT": 3000.00,
  "PENALTY_VENDOR": 3000.00
}
```
- `AMOUNT` = fare возврата + taxes возврата - penalty
- `PENALTY_CLIENT` / `PENALTY_VENDOR` — штрафы (берутся из таксы с `CODE="PEN"`)

### 5.7. Коды типов продуктов

| CODE | NAME |
|------|------|
| `000000001` | Авиабилет |
| `000000003` | Отельный билет |

---

## 6. Парсеры

### 6.0. ParserInterface — контракт парсера

**Файл:** `core/ParserInterface.php`

Все парсеры обязаны реализовать интерфейс `ParserInterface`. Именно по нему `ParserManager` определяет, что класс является парсером.

| Метод | Сигнатура | Возвращает | Описание |
|-------|-----------|-----------|----------|
| `getSupplierFolder()` | `public function getSupplierFolder(): string` | `string` | Имя папки поставщика в `input/` (например `"moyagent"`, `"demo_hotel"`). Используется `Processor` для привязки парсера к директории. |
| `getSupplierName()` | `public function getSupplierName(): string` | `string` | Человекочитаемое название поставщика для логов (например `"Мой агент"`, `"Демо-отель"`). |
| `parse()` | `public function parse(string $xmlFilePath): array` | `array` | Принимает абсолютный путь к XML-файлу, возвращает массив ORDER (см. секцию 5). При ошибке бросает `Exception`. |

**Как добавить нового поставщика:**
1. Создать файл `parsers/НовыйParser.php`
2. Определить класс `НовыйParser implements ParserInterface`
3. Реализовать все 3 метода
4. Создать папку `input/имя_из_getSupplierFolder()/`
5. Подпапки `Processed/` и `Error/` создадутся автоматически

### 6.1. MoyAgentParser — «Мой агент» (авиабилеты)

- **Файл:** `parsers/MoyAgentParser.php`
- **Класс:** `MoyAgentParser implements ParserInterface`
- **Папка входных данных:** `input/moyagent/`
- **Формат входного XML:** корневой тег `<order_snapshot>`, секции `<header>`, `<customer>`, `<payments>`, `<reservations>`, `<products>`, `<travel_docs>`, `<passengers>`
- **Поддерживаемые операции:** TKT (продажа), REF (возврат), RFND (возврат), CANX (аннуляция)

**Специфическая логика — `analyzeOrderType()`:**
1. Перебирает все `<travel_doc> → <air_ticket_doc>`, читает атрибут `tkt_oper`
2. Если находит REF, RFND или CANX — заказ считается **возвратом**
3. Определяет `refund_prod_id` (возвратный продукт) и `original_prod_id` (оригинальный TKT)
4. При CANX: один product, один doc — `original = refund`

**Логика формирования продукта при возврате:**
- Купоны (COUPONS) берутся из **оригинального** product
- Таксы (TAXES) берутся из **оригинального** product
- Платежи (PAYMENTS) — полная стоимость из **оригинального** product
- Комиссии (COMMISSIONS) — из **оригинального** product
- PENALTY — из **возвратного** product (такса с `code="PEN"`)
- REFUND.AMOUNT — fare + taxes возврата - penalty

**Вспомогательные методы:**
- `buildPassengersMap()` — карта `psgr_id → {name, first_name, ...}`
- `buildTravelDocsMap()` — карта `prod_id → {tkt_number, tkt_date, tkt_oper, ...}`
- `buildReservationsMap()` — карта `supplier → {rloc, crs, ...}`
- `buildCoupons()` — из `<air_seg>` формирует массив COUPONS
- `buildTaxes()` — из `fare` + `<air_tax>` формирует массив TAXES
- `buildCommissions()` — из `service_fee` + `<fees><fee type="commission">` формирует COMMISSIONS
- `extractPenalty()` — суммирует все `<air_tax code="PEN">`
- `mapPassengerAge()` — `adt→ADULT`, `chd→CHILD`, `inf→INFANT`
- `mapTicketStatus()` — `TKT→продажа`, `REF/RFND/CANX→возврат`, `EXCH→обмен`
- `formatDateTime()` — `"2025-10-13 12:16:00"` → `"20251013121600"`
- `generateUUID()` — UUID v4

### 6.2. DemoHotelParser — Демо-отели (шаблон)

- **Файл:** `parsers/DemoHotelParser.php`
- **Класс:** `DemoHotelParser implements ParserInterface`
- **Папка входных данных:** `input/demo_hotel/`
- **Формат входного XML:** корневой тег `<hotel_order>`, секции `<order>`, `<booking>`
- **Поддерживаемые операции:** только продажа (статус берётся из атрибута `booking.status`)

**Особенности:**
- Значительно проще MoyAgentParser — служит шаблоном для новых парсеров
- Формирует продукт с типом `Отельный билет` (CODE `000000003`)
- Добавляет блок `ROOMS[]` с данными бронирования (room_size, check_in, check_out, amount)
- Не имеет логики возвратов

---

## 7. API Sender — отправка в 1С

### 7.1. Параметры подключения

Хранятся в `config/settings.json → api`:
```json
{
  "api": {
    "enabled": true,
    "url": "http://10.30.88.24/TRADE/hs/CRM_Exchange/Order",
    "login": "api_user",
    "password": "api_password",
    "timeout": 5
  }
}
```

| Поле | Описание |
|------|----------|
| `enabled` | Включена ли отправка (false = только сохранение JSON) |
| `url` | URL HTTP-сервиса 1С |
| `login` | Логин для Basic Auth |
| `password` | Пароль для Basic Auth |
| `timeout` | Таймаут HTTP-запроса в секундах |

### 7.2. Формат отправки

- **Метод:** POST
- **URL:** значение `api.url` из settings.json
- **Аутентификация:** HTTP Basic Auth (`login:password`)
- **Заголовки:**
  - `Content-Type: application/json; charset=utf-8`
  - `Accept: application/json`
- **Тело:** JSON массив ORDER (без полей `SOURCE_FILE` и `PARSED_AT`)
- **SSL:** верификация отключена (`CURLOPT_SSL_VERIFYPEER = false`)

### 7.3. Проверка доступности

Перед обработкой файлов `Processor` вызывает `ApiSender.isAvailable()`:
- Делает HEAD-запрос с `CURLOPT_CONNECTTIMEOUT = 2`, `CURLOPT_TIMEOUT = 3`
- Если `http_code > 0` — сервер доступен
- Если недоступен — файлы всё равно обрабатываются и JSON сохраняется, но отправка пропускается

### 7.4. Обработка ошибок

**Retry-логика отсутствует.** Если отправка не удалась:
- JSON-файл сохранён, XML перемещён в `Processed/`
- В `api_send.log` записывается ошибка с подробным пояснением
- Ошибка отправки **не влияет** на статус обработки файла

Подробные пояснения ошибок через `explainCurlError()`:
- cURL #6 (DNS) → «хост не найден» (с пометкой, что на домашнем ПК это нормально)
- cURL #7 (Connection refused) → «сервер не отвечает»
- cURL #28 (Timeout) → «увеличьте api.timeout»
- HTTP 401 → «неверный логин/пароль»
- HTTP 404 → «эндпоинт не найден, проверьте URL»
- HTTP 500 → «внутренняя ошибка 1С»

### 7.5. Повторная отправка (resend)

> **✅ Исправлено 2026-02-26.** Код перенесён из dead code в `case 'resend':` внутри switch.

- Кнопка 🔄 рядом с каждой строкой в `data.php` → `fetch('api.php?action=resend', {file: 'имя.json'})`
- `api.php` (`case 'resend':`) проверяет метод (POST), валидирует имя файла (regex), проверяет существование в `json/`, декодирует JSON, создаёт `ApiSender`, вызывает `send()`
- Возвращает HTTP-коды: 405 (не POST), 400 (некорректное имя / невалидный JSON), 404 (файл не найден), 200 (результат отправки)

### 7.6. Формат api_send.log

Файл `logs/api_send.log` — JSON Lines (одна JSON-строка на запись):
```json
{"timestamp":"2026-02-16 13:26:17","status":"OK","json_file":"moyagent_file_20260216_132617.json","source_xml":"file.xml","http_code":200,"response":"...","message":"Успешно отправлено (HTTP 200). Ответ: ..."}
```

| Поле | Описание |
|------|----------|
| `timestamp` | Дата и время записи |
| `status` | `SEND` / `OK` / `ERROR` / `SKIP` |
| `json_file` | Имя JSON-файла |
| `source_xml` | Имя исходного XML |
| `http_code` | HTTP-код ответа (null если не было запроса) |
| `response` | Тело ответа (обрезается до 1000 символов) |
| `message` | Человекочитаемое пояснение |

---

## 8. Web UI — веб-интерфейс

### 8.1. index.php — Панель управления (главная)

- **Что показывает:** управление обработкой + журнал событий в реальном времени
- **Фронтенд:** `assets/app.js` + `assets/style.css`
- **API-эндпоинты (через app.js):**
  - `GET api.php?action=logs` — получение последних 200 строк `app.log` (каждые 3 сек)
  - `POST api.php?action=run` — ручной запуск обработки
  - `GET/POST api.php?action=settings` — чтение/сохранение интервала
  - `POST api.php?action=clear_logs` — очистка лога
- **Функции UI:**
  - Настройка интервала (10–86400 сек)
  - Кнопка «Запустить обработку» (force=true)
  - Автообработка — `setInterval` в браузере, пока страница открыта
  - Логи окрашиваются по уровню: INFO (серый), SUCCESS (зелёный), WARNING (жёлтый), ERROR (красный)
  - При наведении мыши на блок логов — автообновление приостанавливается

### 8.2. data.php — Обработанные заказы

- **Что показывает:** таблица всех заказов из `json/*.json` (49 колонок)
- **Рендеринг:** серверный (PHP читает файлы, формирует HTML, отдаёт браузеру)
- **AJAX не используется** (кроме кнопки повторной отправки resend — **⚠️ сломана**)
- **Фронтенд:** встроенный `<script>` для функции `resendJson()` + `assets/style.css`
- **API-эндпоинт:**
  - `POST api.php?action=resend` — повторная отправка JSON в 1С (**⚠️ dead code, не работает**)
- **Логика:**
  - Читает все `json/*.json`, раскрывает `PRODUCTS[]` (один продукт = одна строка)
  - Извлекает все поля ORDER: идентификация, маршрут, финансы, комиссии, возвраты
  - Сортирует по `PARSED_AT` (новые сверху)
- **Интерактивное изменение ширины столбцов:**
- Реализовано перетаскивание границ столбцов (drag-resize) на чистом JS
- Невидимый разделитель (5px) на правом краю каждого `<th>`
- При зажатии мыши — плавное изменение ширины, подсветка границы (синяя линия)
- Минимальная ширина столбца: 50px
- Порядок инициализации: замер естественных ширин → фиксация → `table-layout: fixed` → создание ресайзеров
- Горизонтальная прокрутка через `.data-table-wrapper { overflow-x: auto }

**Все 49 колонок таблицы:**

| # | Колонка | Источник данных |
|---|---------|----------------|
| 1 | 🔄 (кнопка resend) | UI |
| 2 | # (порядковый номер) | счётчик |
| 3 | Файл | имя JSON-файла |
| 4 | Номер заказа | `ORDER.INVOICE_NUMBER` |
| 5 | Дата заказа | `ORDER.INVOICE_DATA` |
| 6 | Клиент | `ORDER.CLIENT` |
| 7 | Тип продукта | `PRODUCT.PRODUCT_TYPE.NAME` |
| 8 | Номер билета | `PRODUCT.NUMBER` |
| 9 | Дата выписки | `PRODUCT.ISSUE_DATE` |
| 10 | Статус | `PRODUCT.STATUS` |
| 11 | Пассажир | `PRODUCT.TRAVELLER` |
| 12 | Поставщик | `PRODUCT.SUPPLIER` |
| 13 | Перевозчик | `PRODUCT.CARRIER` |
| 14 | Маршрут | из `COUPONS[].DEPARTURE/ARRIVAL_AIRPORT` |
| 15 | Сумма | сумма `PAYMENTS[].EQUIVALENT_AMOUNT` (тип INVOICE) |
| 16 | Валюта | `PRODUCT.CURRENCY` |
| 17 | Исходный файл | `ORDER.SOURCE_FILE` |
| 18 | Дата загрузки | `ORDER.PARSED_AT` |
| 19 | UID заказа | `ORDER.UID` (усечён до 8 символов) |
| 20 | UID продукта | `PRODUCT.UID` (усечён до 8 символов) |
| 21 | Номер брони | `PRODUCT.RESERVATION_NUMBER` |
| 22 | Выпис. агент | `PRODUCT.BOOKING_AGENT` |
| 23 | Агент | `PRODUCT.AGENT` |
| 24 | Тип билета | `PRODUCT.TICKET_TYPE` |
| 25 | Возраст | `PRODUCT.PASSENGER_AGE` |
| 26 | Бланки | `PRODUCT.CONJ_COUNT` |
| 27 | Штраф обмен | `PRODUCT.PENALTY` |
| 28 | Рейсы | `COUPONS[].FLIGHT_NUMBER` (через запятую) |
| 29 | Fare Basis | `COUPONS[].FARE_BASIS` (через запятую) |
| 30 | Класс | `COUPONS[].CLASS` (через запятую) |
| 31 | Дата вылета | первый `COUPONS[0].DEPARTURE_DATETIME` |
| 32 | Дата прилёта | последний `COUPONS[-1].ARRIVAL_DATETIME` |
| 33 | Тариф (руб) | `TAXES[]` где `CODE=""` |
| 34 | Таксы (руб) | `TAXES[]` где `CODE!=""` |
| 35 | НДС (руб) | сумма `TAXES[].VAT_AMOUNT` |
| 36 | Тип платежа | `PAYMENTS[].TYPE` (через запятую) |
| 37 | Оплата (руб) | сумма `PAYMENTS[]` (тип INVOICE) |
| 38 | Зачёт (руб) | сумма `PAYMENTS[]` (тип TICKET) |
| 39 | Связ. билет | `PAYMENTS[].RELATED_TICKET_NUMBER` (тип TICKET) |
| 40 | Комиссия ТКП | `COMMISSIONS[]` TYPE=VENDOR |
| 41 | Ставка % | `COMMISSIONS[]` TYPE=VENDOR, RATE |
| 42 | Серв. сбор | `COMMISSIONS[]` TYPE=CLIENT, NAME~"сервисный сбор" |
| 43 | Сбор пост. | `COMMISSIONS[]` TYPE=CLIENT, NAME~"сбор поставщика" |
| 44 | Дата возврата | `REFUND.DATA` |
| 45 | Сумма возврата | `REFUND.EQUIVALENT_AMOUNT` |
| 46 | Сбор РСТЛС | `REFUND.FEE_CLIENT` |
| 47 | Сбор пост. возвр. | `REFUND.FEE_VENDOR` |
| 48 | Штраф пост. | `REFUND.PENALTY_VENDOR` |
| 49 | Штраф РСТЛС | `REFUND.PENALTY_CLIENT` |

### 8.3. api_logs.php — Логи отправки API

- **Что показывает:** таблица записей из `logs/api_send.log` + статус подключения к 1С
- **Работает в двух режимах:**
  - Без `?action` → отдаёт HTML-страницу
  - С `?action=get_logs|clear_logs|get_settings` → отдаёт JSON (AJAX)
- **Фронтенд:** встроенный `<script>` (не использует app.js) + `assets/style.css` + inline `<style>`
- **AJAX к самому себе:**
  - `GET api_logs.php?action=get_logs` — последние 500 записей (каждые 10 сек)
  - `GET api_logs.php?action=clear_logs` — очистка api_send.log
  - `GET api_logs.php?action=get_settings` — статус API (enabled, url)

### 8.4. api.php — API-эндпоинт

- **Назначение:** обработка AJAX-запросов от всех страниц
- **Формат ответа:** JSON
- **Действия (параметр `action` в URL):**

| action | Метод | Описание |
|--------|-------|----------|
| `logs` | GET | Последние 200 строк app.log |
| `run` | POST | Запуск обработки (force=true) |
| `settings` | GET | Получение настроек |
| `settings` | POST | Сохранение настроек (JSON body) |
| `clear_logs` | POST | Очистка app.log |
| `resend` | POST | Повторная отправка JSON в 1С (✅ исправлено) |

### 8.5. test.php — Автотесты парсеров

- **Что показывает:** результаты автоматического тестирования парсеров
- **Тестовые данные:** XML-фикстуры из `tests/fixtures/` (4 файла)
- **Тестируемый парсер:** `MoyAgentParser` (продажи, мульти-билет, EMD, возврат)
- **Режимы работы:**
  - **Web** — HTML-страница с цветными карточками (pass/fail/error), сворачиваемые блоки проверок
  - **CLI** — `php test.php` → текстовый вывод с emoji (exit code 0/1 для CI)
- **Проверки на каждый файл (~15 assertions):** парсинг, UID заказа, INVOICE_NUMBER, CLIENT, кол-во продуктов, статус, номер билета, пассажир, перевозчик, купоны, валюта, тариф, UID продукта, JSON-сериализация, REFUND + PENALTY (для возвратов)
- **Фронтенд:** встроенный `<style>` + `<script>` (не использует app.js), подключает `assets/style.css`
- **Безопасность:** не отправляет данные в API, не перемещает файлы — только парсинг и проверка структуры

**Тестовые фикстуры (`tests/fixtures/`):**

| Файл | Сценарий |
|------|----------|
| `125358843227.xml` | Продажа, 1 билет, 2 сегмента (SVO→KZN→SVO) |
| `125358829987.xml` | Продажа, 3 билета, 3 пассажира (SVO→DXB→SVO) |
| `125358832021.xml` | Продажа, 1 билет + EMD, валюта EUR→RUB (MXP→CDG) |
| `125358832769.xml` | Возврат REF, penalty 3500 (SVO→OVB→SVO) |

### 8.6. Навигация

Все четыре страницы имеют общую шапку с навигацией:
- «Панель управления» → `index.php`
- «Обработанные заказы» → `data.php`
- «Логи API» → `api_logs.php`
- «Тесты» → `test.php`

---

## 9. Текущее состояние разработки

### Что реализовано (✅)

- ✅ **Ядро системы** — `Processor`, `ParserManager`, `Logger`, `ParserInterface`, `Utils` → `core/`
- ✅ **Парсер «Мой агент»** — полная поддержка продаж, возвратов (REF/RFND), аннуляций (CANX) → `parsers/MoyAgentParser.php`
- ✅ **Демо-парсер отелей** — шаблон для новых парсеров → `parsers/DemoHotelParser.php`
- ✅ **Отправка в API 1С** — HTTP POST с Basic Auth, подробное логирование ошибок → `core/ApiSender.php`
- ✅ **Веб-интерфейс** — панель управления, таблица заказов (49 колонок), логи API, автотесты
- ✅ **Повторная отправка** — кнопка 🔄 в `data.php` → `api.php?action=resend` → `ApiSender.send()`
- ✅ **Автообработка** — по таймеру в браузере + проверка интервала при cron
- ✅ **Ротация логов** — app.log автоматически архивируется при превышении 5 МБ
- ✅ **Resizable-столбцы в data.php** — изменение ширины столбцов таблицы перетаскиванием (drag-resize, vanilla JS)
- ✅ **`generateUUID()` вынесен в Utils** — единый `Utils::generateUUID()` вместо дубликатов в парсерах → `core/Utils.php`
- ✅ **Баг resend исправлен** — dead code перенесён в `case 'resend':` внутри switch в `api.php`
- ✅ **Автотесты парсеров** — страница `test.php` (Web + CLI), 4 фикстуры в `tests/fixtures/`, ~15 assertions на файл

### В процессе (🔄)

- Нет активных задач

### Запланировано (📋)

- 📋 Добавить поддержку новых поставщиков по мере необходимости
- 📋 Расширить тесты: добавить DemoHotelParser, тесты для edge cases

### Известные проблемы (⚠️)

- ⚠️ **Нет retry-логики** при отправке в 1С — если сервер недоступен, заказ не отправляется повторно автоматически (но ручная переотправка через кнопку 🔄 теперь работает)
- ⚠️ **`data.php` — серверный рендеринг** — при большом количестве JSON-файлов страница может загружаться медленно

---

## 10. Последние изменения (краткий лог)

| Дата | Действие | Затронутые файлы | Детали |
|------|----------|-----------------|--------|
| 2026-02-26 | Добавлена страница автотестов | `test.php`, `tests/fixtures/*.xml`, `index.php`, `data.php`, `api_logs.php` | Веб+CLI тестирование MoyAgentParser (4 fixture, ~15 assertions/файл), навигация обновлена на всех страницах |
| 2026-02-26 | Рефакторинг UUID | `core/Utils.php`, `parsers/MoyAgentParser.php`, `parsers/DemoHotelParser.php` | `generateUUID()` вынесен из парсеров в `Utils::generateUUID()`, удалены дубликаты |
| 2026-02-26 | Исправлен баг resend в api.php | `api.php` | Dead code `if ($action === 'resend')` перенесён в `case 'resend':` внутри switch, добавлены HTTP-коды ошибок |
| 2026-02-26 | Resizable-столбцы в data.php, исправление ext-curl | `data.php`, `assets/style.css`, `php.ini` | Drag-resize столбцов таблицы (vanilla JS), включение ext-curl для работы ApiSender |
| 2026-02-26 | Финализация документации | `CURRENT_STAGE.md`, `context-keeper.md`, `.cursorrules`, `CHANGELOG_AI.md` | Пункты 13–14, шаблоны в code-блоки, .cursorrules |
| 2026-02-26 | Корректировки CURRENT_STAGE.md | `CURRENT_STAGE.md`, `CHANGELOG_AI.md` | Исправлен баг resend (dead code), секции ParserInterface/Logger/Processor.run(), статистика, 49 колонок |
| 2026-02-26 | Инициализация CURRENT_STAGE.md | `CURRENT_STAGE.md`, `CHANGELOG_AI.md` | Полный анализ проекта, создание файлов контекста |
| 2026-02-26 | Создан скилл update-structure | `.cursor/skills/update-structure/SKILL.md`, `structure.md` | Скилл для автоматического обновления structure.md |

> Полная история изменений: см. [CHANGELOG_AI.md](CHANGELOG_AI.md)

---

## 11. Важные соглашения и правила

1. **Именование парсеров:** `<НазваниеПоставщика>Parser.php` в папке `parsers/`, класс `<НазваниеПоставщика>Parser implements ParserInterface`
2. **Именование папок поставщиков:** `input/<snake_case>/` с подпапками `Processed/` и `Error/`
3. **Формат дат в ORDER:** строка `YYYYMMDDHHmmss` (например `"20251013121600"`)
4. **UUID:** версия 4 (RFC 4122), генерируется через `Utils::generateUUID()` из `core/Utils.php`
5. **Кодировка:** UTF-8 везде, JSON с `JSON_UNESCAPED_UNICODE`
6. **Язык комментариев в коде:** русский
7. **PHP-совместимость:** код написан на PHP 7.0 (`array()` вместо `[]`, нет type hints в параметрах)
8. **CSS-методология:** BEM-подобная (`.header__title`, `.panel__controls`, `.btn--primary`)

---

## 12. Конфигурация и окружение

### Запуск проекта

```bash
# Встроенный сервер PHP
cd d:\Project\parser_v5
php -S localhost:8080

# Запуск обработки из командной строки
php process.php

⚠️ ext-curl ОБЯЗАТЕЛЕН — без него Processor.php падает с Fatal Error 
при вызове ApiSender.isAvailable(). Обработка файлов проходит, 
но PHP умирает до отправки JSON-ответа в браузер, и UI показывает 
"Ошибка связи с сервером". 

Решение: раскомментировать extension=curl в php.ini, 
перезапустить PHP-сервер.

# Cron (Linux) — каждую минуту
* * * * * /usr/bin/php /path/to/parser_v5/process.php
```

### config/settings.json — все поля

```json
{
  "interval": 15,
  "last_run": 1772047563,
  "api": {
    "enabled": true,
    "url": "http://10.30.88.24/TRADE/hs/CRM_Exchange/Order",
    "login": "api_user",
    "password": "api_password",
    "timeout": 5
  }
}
```

| Поле | Тип | Описание | Диапазон |
|------|-----|----------|----------|
| `interval` | int | Интервал обработки в секундах | 10–86400 |
| `last_run` | int | Unix timestamp последнего запуска | автоматически |
| `api.enabled` | bool | Включена ли отправка в 1С | true/false |
| `api.url` | string | URL HTTP-сервиса 1С | — |
| `api.login` | string | Логин Basic Auth | — |
| `api.password` | string | Пароль Basic Auth | — |
| `api.timeout` | int | Таймаут HTTP-запроса (сек) | рекомендуется 5–30 |

### Директории — какие создаются автоматически

| Директория | Создаётся автоматически? | Кем |
|-----------|------------------------|-----|
| `json/` | ✅ Да | `Processor.__construct()` |
| `logs/` | ✅ Да | `Logger.__construct()`, `ApiSender.__construct()` |
| `input/<supplier>/Processed/` | ✅ Да | `Processor.ensureSubfolders()` |
| `input/<supplier>/Error/` | ✅ Да | `Processor.ensureSubfolders()` |
| `input/<supplier>/` | ❌ Нет | Нужно создать вручную |
| `config/` | ✅ Да | `Processor.saveSettings()`, `api.php` |
| `parsers/` | ❌ Нет | Должна существовать |

### Права на директории

Все директории создаются с правами `0755`. Веб-сервер должен иметь права на запись в: `json/`, `logs/`, `config/`, `input/*/Processed/`, `input/*/Error/`.

---

## 13. Контекст для AI-ассистента

### Критически важные знания

1. **Auto-discovery парсеров.** `ParserManager` автоматически загружает все классы из `parsers/*.php`, реализующие `ParserInterface`. Для нового поставщика достаточно:
   - Создать `parsers/НовыйParser.php` с классом, реализующим `ParserInterface`
   - Создать `input/имя_папки/` (подпапки Processed/Error создадутся сами)
   - Больше ничего менять не нужно

2. **Не забудь обновить `structure.md`** при создании/удалении файлов (скилл `update-structure`)

3. **Не забудь обновить `CURRENT_STAGE.md`** после каждого значимого изменения (скилл `context-keeper`)

### Архитектурные quirks (особенности)

4. **~~`generateUUID()` дублируется~~ — ИСПРАВЛЕНО (2026-02-26).** Вынесен в `core/Utils.php` как `Utils::generateUUID()`. Оба парсера теперь вызывают `Utils::generateUUID()`. Новые парсеры обязаны подключать `require_once __DIR__ . '/../core/Utils.php'` и использовать `Utils::generateUUID()`, а не создавать свою реализацию.

5. **~~`resend` в `api.php` — dead code~~ — ИСПРАВЛЕНО (2026-02-26).** Блок `if ($action === 'resend')` перенесён в `case 'resend':` внутри switch. Добавлена проверка метода (POST, иначе HTTP 405), валидация имени файла (regex), HTTP-коды ошибок (400, 404). Кнопка 🔄 в `data.php` теперь работает корректно.

6. **`data.php` — полностью серверный рендеринг.** Не использует AJAX для загрузки данных (в отличие от index.php и api_logs.php). При каждом обновлении страницы PHP заново читает все JSON-файлы. Единственный AJAX — кнопка повторной отправки `resendJson()`.

7. **`api_logs.php` работает в двух режимах:**
   - Без параметра `action` — отдаёт полную HTML-страницу
   - С параметром `action` — работает как JSON API (аналогично api.php)
   - Имеет собственный встроенный JS (не использует `assets/app.js`)

8. **`assets/app.js` обслуживает только `index.php`.** Страницы `data.php` и `api_logs.php` имеют свои встроенные `<script>` блоки.

9. **При изменении формата ORDER** — необходимо обновить:
   - Парсер(ы), генерирующие данные
   - `data.php` — таблицу отображения (PHP + HTML)
   - `ApiSender.send()` — если меняются служебные поля

10. **Файл `config/settings.json` модифицируется автоматически** — после каждой обработки обновляется `last_run`. Не удалять и не коммитить (можно добавить в .gitignore).

11. **Файлы `input/` и `json/` уже в `.gitignore`** — они не попадают в репозиторий.

12. **`ext-curl` обязателен** для работы `ApiSender` — это расширение PHP используется для HTTP-запросов к 1С, но не было указано в README.

13. **Processor жёстко привязан к XML-файлам** — в методе `run()` используется `glob("input/{$folder}/*.xml")`. Если в будущем появится парсер для CSV, JSON или другого формата — потребуется рефакторинг Processor: либо добавить метод `getSupportedExtensions()` в `ParserInterface`, либо параметризировать glob-паттерн.

14. **Нет автоматического retry.** Если API 1С недоступен — JSON сохраняется, но не отправляется. Ручная переотправка через кнопку 🔄 в `data.php` теперь работает (баг исправлен).

15. **`test.php` — только MoyAgentParser.** Текущие автотесты покрывают только `MoyAgentParser` (4 fixture-файла). `DemoHotelParser` и edge cases не тестируются. При добавлении нового парсера нужно добавить фикстуры в `tests/fixtures/` и расширить массив `$expectations` в `test.php`.

16. **Новые парсеры должны подключать `core/Utils.php`** — `require_once __DIR__ . '/../core/Utils.php'` и использовать `Utils::generateUUID()` вместо собственной реализации.
