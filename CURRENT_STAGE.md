# XML Parser v5 — Текущее состояние

**Последнее обновление:** 2026-03-16
**Обновлено после:** Аудит проекта: structure.md, nextstep.md, комментарии ApiSender, порядок комментариев в test.php

---

## 1. Общее описание проекта

**XML Parser v5** — система обработки XML-файлов от поставщиков туристических услуг (авиабилеты, отели) с преобразованием в единый JSON-формат **ORDER** по спецификации **RSTLS** и отправкой во внешний API **1С:Предприятие**.

**Статистика проекта:**
- 16 PHP-файлов, ~4 000 строк PHP-кода
- 2 фронтенд-файла (JS + CSS), ~920 строк
- Итого: ~4 920 строк кода
- 2 парсера (MoyAgent — боевой, DemoHotel — шаблон)
- 7 тестовых fixture-файлов (XML) в `tests/fixtures/` для MoyAgentParser

Ключевые возможности:
- Автоматическое обнаружение парсеров (plug-and-play)
- Обработка по расписанию (cron) и вручную через веб-интерфейс
- Поддержка операций: продажа, возврат (REF/RFND), аннуляция (CANX)
- Группировка конъюнкционных бланков (emd_ticket_doc + air_ticket_doc)
- Отправка заказов в API 1С с логированием
- Повторная отправка из веб-интерфейса
- **SFTP-синхронизация встроена в обработку:** загрузка XML с сервера поставщика при каждом запуске (кнопка «Запустить» и автообработка)
- Данные документов пассажиров: дата рождения, пол, тип документа, номер документа
- Справочник констант (parsers/constants/): типы пассажиров, пол, классы, type_id, GDS, типы перелёта, статусы сегментов
- Веб-панель управления с логами в реальном времени

---

## 2. Технологический стек

- **Backend:** PHP 7.0+ (совместим с PHP 8.x), без фреймворков
- **Frontend:** Vanilla JS (ES5, fetch API), CSS3
- **База данных:** Отсутствует — файлы (JSON, XML, текстовые логи)
- **Расширения PHP:** `ext-simplexml`, `ext-curl` (с поддержкой SFTP/libssh2), `ext-json`, `ext-mbstring`
- **Внешние зависимости:** Нет (composer не используется)
- **Внешний API:** 1С:Предприятие HTTP-сервис `CRM_Exchange/Order`
- **SFTP:** cURL + libssh2 (встроенная поддержка, без ext-ssh2)

---

## 3. Структура проекта
parser_v5/
├── config/
│   ├── settings.json         — интервал, last_run, api, sftp (все настройки)
│   └── sftp_last_run.txt     — timestamp последней SFTP-синхронизации
├── core/
│   ├── ApiSender.php         — HTTP POST в 1С, Basic Auth, лог в api_send.log
│   ├── Logger.php            — app.log (INFO/WARNING/ERROR/SUCCESS), ротация 5МБ→.old
│   ├── ParserInterface.php   — контракт: getSupplierFolder(), getSupplierName(), parse()
│   ├── ParserManager.php     — auto-discovery: сканирует parsers/.php, рефлексия
│   ├── Processor.php         — оркестратор: glob(.xml)→parse→saveJson→send→move
│   ├── SftpSync.php          — SFTP-клиент: подключение, листинг, скачивание, перемещение
│   └── Utils.php             — Utils::generateUUID() (v4)
├── parsers/
│   ├── constants/
│   │   └── MoyAgentConstants.php — справочник констант и маппингов
│   ├── MoyAgentParser.php    — «Мой агент» авиа V5 (TKT/REF/RFND/CANX + конъюнкции)
│   └── DemoHotelParser.php   — шаблон отелей
├── input/{supplier}/         — XML (подпапки Processed/, Error/)
├── json/                     — результаты ({folder}{xmlName}{Ymd_His}.json)
├── logs/                     — app.log + api_send.log + sftp_sync.log
├── tests/fixtures/           — 5 XML-фикстур для MoyAgentParser
├── index.php                 — панель управления (app.js, AJAX)
├── data.php                  — таблица заказов (серверный рендеринг, 60 колонок)
├── api_logs.php              — логи API (HTML + AJAX к себе)
├── api.php                   — AJAX API (logs/run/settings/clear_logs/clear_json/resend)
├── process.php               — точка входа pipeline (CLI cron + require из api.php)
├── sftp_sync.php             — точка входа SFTP-синхронизации (CLI cron + браузер)
├── test.php                  — автотесты парсеров (Web + CLI)
└── assets/                   — app.js (только для index.php), style.css (общий)



---

## 4. Архитектура и взаимосвязи

### 4.1. Полный pipeline обработки (с SFTP)
[cron] php process.php    [Web UI] index.php → кнопка «Запуск» / автообработка
│                              │
▼                              ▼
runProcessing($force)         fetch('api.php?action=run')
│                              │
│  1. runSftpSync($force) ──────┘  (если sftp.enabled)
│     SftpSync → cURL+SFTP → листинг → скачать → переместить в Processed на SFTP
│     XML-файлы в input/moyagent/
│
│  2. Processor->run($force)
└──────────┬───────────────────┘
▼
┌─────────────────┐
│  ParserManager  │  auto-discovery parsers/.php
└───────┬─────────┘
▼
┌─────────────────┐
│   Processor     │  glob(.xml) → parse → saveJson → send → move
└───────┬─────────┘
│
┌───────┼────────────┐
▼       ▼            ▼
MoyAgent  DemoHotel    Новый
Parser    Parser       Parser
│       │            │
└───────┼────────────┘
▼
Массив ORDER (PHP array)
│
┌───────┼────────┐
▼                ▼
json/*.json      ApiSender→1С
│
api_send.log



### 4.2. Потоки данных Web UI
index.php ─── assets/app.js ─── assets/style.css
│  fetch('api.php?action=logs')        → app.log
│  fetch('api.php?action=run')         → process.php
│  fetch('api.php?action=settings')    → settings.json
│  fetch('api.php?action=clear_logs')  → app.log

data.php ─── серверный рендеринг + JS ─── style.css
│  fetch('api.php?action=resend')       → повторная отправка
│  fetch('api.php?action=clear_json')   → удаление всех JSON из json/

api_logs.php ─── встроенный JS ─── style.css
│  AJAX к самому себе (?action=get_logs/clear_logs/get_settings)

sftp_sync.php ─── standalone (CLI + браузер) ─── для ручного/отдельного запуска
│  SFTP также встроен в runProcessing() — вызывается при каждом «Запустить» и автообработке



### 4.3. Ключевые архитектурные решения

1. **Auto-discovery парсеров:** `ParserManager` сканирует `parsers/*.php`, сравнивает `get_declared_classes()` до/после, проверяет `implementsInterface('ParserInterface')`
2. **Файловое хранилище:** JSON, XML, текстовые логи — без СУБД
3. **Двойная точка входа:** `process.php` — CLI (cron) и модуль (require из api.php)
4. **Три раздельных лога:** `app.log` (текстовый), `api_send.log` (JSON Lines), `sftp_sync.log` (текстовый)
5. **Интервальный контроль:** cron проверяет `last_run + interval`, UI — `force=true`
6. **SFTP через cURL:** используется встроенная поддержка SFTP в ext-curl (libssh2), без ext-ssh2 и без внешних библиотек
7. **SFTP встроен в runProcessing:** при каждом запуске (Web UI или CLI) сначала runSftpSync(), затем Processor. sftp_sync.php остаётся для standalone-запуска

### 4.4. Processor.run() — детальная логика
Проверка интервала: if (!$force && !isIntervalPassed()) → return
Получение папок: $folders = ParserManager->getRegisteredFolders()
Проверка API: $apiAvailable = ApiSender->isAvailable() (HEAD, 2с)
Цикл по папкам → glob("input/$folder/*.xml") → цикл по XML:
try:
result = $parser->parse(xmlFile)
saveJson($result, $folder, $xmlFile)
moveFile($xmlFile, "Processed/")
if ($apiAvailable): ApiSender->send(…)
catch:
moveFile($xmlFile, "Error/")
Logger->error(…)
updateLastRunTime() → settings.json.last_run = time()



### 4.5. Logger

- Формат: `[Y-m-d H:i:s] [LEVEL] message`
- Уровни: INFO, WARNING, ERROR, SUCCESS
- Ротация: >5 МБ → `app.log.old` (1 архив)
- Запись: `FILE_APPEND | LOCK_EX`

---

## 5. Формат ORDER / RSTLS

### 5.1. Структура ORDER

```json
{
  "UID": "8fd8578c-c002-4e73-891d-278373b59ef4",
  "INVOICE_NUMBER": "125359005865",
  "INVOICE_DATA": "20260226155022",
  "CLIENT": "MA1PA6",
  "SOURCE_FILE": "125359005865.xml",
  "PARSED_AT": "2026-03-03 12:19:06",
  "PRODUCTS": [
    {
      "UID": "a1b2c3d4-...",
      "PRODUCT_TYPE": { "NAME": "Авиабилет", "CODE": "000000001" },
      "NUMBER": "6076506222015",
      "ISSUE_DATE": "20260226152252",
      "RESERVATION_NUMBER": "G1ZXKP",
      "BOOKING_AGENT": { "CODE": "Валерия Подунай", "NAME": "Валерия Подунай" },
      "AGENT": { "CODE": "Валерия Подунай", "NAME": "Валерия Подунай" },
      "STATUS": "продажа",
      "TICKET_TYPE": "OWN",
      "PASSENGER_AGE": "ADULT",
      "CONJ_COUNT": 2,
      "PENALTY": 0,
      "CARRIER": "EY",
      "SUPPLIER": "Мой агент",
      "COUPONS": [
        {
          "FLIGHT_NUMBER": "842",
          "FARE_BASIS": "DKN0AC2R",
          "DEPARTURE_AIRPORT": "SVO",
          "DEPARTURE_DATETIME": "20260405124000",
          "ARRIVAL_AIRPORT": "AUH",
          "ARRIVAL_DATETIME": "20260405191000",
          "CLASS": "D"
        }
      ],
      "TRAVELLER": "MAKAROV KONSTANTIN",
      "TAXES": [
        { "CODE": "", "AMOUNT": 787970, "EQUIVALENT_AMOUNT": 787970, "VAT_RATE": 0, "VAT_AMOUNT": 0 },
        { "CODE": "RI", "AMOUNT": 3182, "EQUIVALENT_AMOUNT": 3182, "VAT_RATE": 0, "VAT_AMOUNT": 0 }
      ],
      "CURRENCY": "RUB",
      "PAYMENTS": [
        { "TYPE": "INVOICE", "AMOUNT": 834132, "EQUIVALENT_AMOUNT": 834132, "RELATED_TICKET_NUMBER": null }
      ],
      "COMMISSIONS": [],
      "REFUND": { "только для возвратов" }
    }
  ]
}
5.2. Обязательные поля
Уровень	Поле	Тип	Описание
Корень	UID	UUID v4	Уникальный ID заказа
Корень	INVOICE_NUMBER	string	Номер заказа
Корень	INVOICE_DATA	YYYYMMDDHHmmss	Дата заказа
Корень	CLIENT	string	Код клиента
Корень	PRODUCTS	array	Массив продуктов (≥1)
Product	UID	UUID v4	Уникальный ID продукта
Product	PRODUCT_TYPE	{NAME, CODE}	Тип продукта
Product	NUMBER	string	Номер билета
Product	STATUS	string	продажа / возврат / обмен
Product	TRAVELLER	string	ФАМИЛИЯ ИМЯ
Product	SUPPLIER	string	Из getSupplierName()
Product	RESERVATION_NUMBER	string	PNR из reservation[@rloc]
Product	BOOKING_AGENT	{CODE, NAME}	Из reservation[@bookingAgent]
Product	AGENT	{CODE, NAME}	Из air_ticket_doc[@issuingAgent]
Product	TAXES	array	Первый (CODE="") = тариф
Product	PAYMENTS	array	Платежи
5.3. Служебные поля (удаляются перед отправкой в 1С)
SOURCE_FILE, PARSED_AT

5.4. Блок REFUND (только возвраты)
json

"REFUND": {
  "DATA": "20251115120000",
  "AMOUNT": 14500.00,
  "EQUIVALENT_AMOUNT": 14500.00,
  "FEE_CLIENT": 0,
  "FEE_VENDOR": 0,
  "PENALTY_CLIENT": 3000.00,
  "PENALTY_VENDOR": 3000.00
}
5.5. Коды типов продуктов
CODE	NAME
000000001	Авиабилет
000000003	Отельный билет
6. Парсеры
6.0. ParserInterface — контракт
Метод	Возвращает	Описание
getSupplierFolder()	string	Имя папки в input/
getSupplierName()	string	Название поставщика
parse($xmlFilePath)	array	Массив ORDER. При ошибке — Exception
Создание нового парсера: создать parsers/XxxParser.php implements ParserInterface + input/folder/ — auto-discovery подхватит.

6.1. MoyAgentParser V5 — «Мой агент» (авиабилеты)
Файл: parsers/MoyAgentParser.php
Папка: input/moyagent/
Формат XML: <order_snapshot>
Операции: TKT, REF, RFND, CANX
Маппинг полей XML → JSON (V5):

JSON-поле	Источник в XML
SUPPLIER	getSupplierName() → "Мой агент"
RESERVATION_NUMBER	reservation[@rloc] через getMainReservation()
BOOKING_AGENT	reservation[@bookingAgent]
AGENT	air_ticket_doc[@issuingAgent]
CARRIER	air_ticket_prod[@validating_carrier]
NUMBER	air_ticket_doc[@tkt_number]
TRAVELLER	passenger[@name] + [@first_name]
Конъюнкции (V4+):

XML-структура: один билет = несколько air_ticket_prod:


prod_id=0: supplier="607", fare=787970, 2 сегмента (основной)
prod_id=1: supplier="BSP_RU1", fare=0, 1 сегмент (конъюнкция)
emd_ticket_doc prod_id="1" main_prod_id="0" ← связь!
Алгоритм:

buildConjLinksMap() → карта child_prod_id → main_prod_id
Child-продукты пропускаются, присоединяются к main-группе
buildCouponsFromGroup() / buildTaxesFromGroup() с дедупликацией
Финансы из main-продукта (maxFare)
Все методы:

Метод	Версия	Описание
parse()	V1	Главный метод парсинга
buildPassengersMap()	V1	Карта psgr_id → данные (+ doc_type, doc_number, doc_country, doc_expire)
buildTravelDocsMap()	V5	Карта prod_id → данные (+ issuingAgent, flight_type_raw)
buildReservationsMap()	V5	Карта supplier → данные (+ bookingAgent)
buildConjLinksMap()	V4	Карта child_prod_id → main_prod_id
getMainReservation()	V5	Первая reservation из XML
findRelatedProdIds()	V4	Все связанные prod_id (для возвратов)
buildCouponsFromGroup()	V4	Купоны из группы с дедупликацией
buildTaxesFromGroup()	V4	Таксы из группы с дедупликацией
buildCommissions()	V1	service_fee + fees/fee[@type=commission]
extractPenalty()	V1	Сумма air_tax[@code=PEN]
analyzeOrderType()	V1	Определяет TKT/REF/RFND/CANX
formatDateTime()	V1	"2025-10-13 12:16:00" → "20251013121600"
mapPassengerAge()	V6	Типы пассажиров через MoyAgentConstants (adt, chd, inf, src, yth, ins)
mapDocType()	V6	IATA-код типа документа → название (MoyAgentConstants)
mapGender()	V6	M, F, MI, FI → мужчина, женщина и т.д. (MoyAgentConstants)
mapCabinClass()	V6	E,B,F,W,A → ECONOMY, BUSINESS, FIRST и т.д.
mapTypeId()	V6	1–6 → Эконом, Бизнес, Первый и т.д.
mapFlightType()	V6	regular, charter, lowcost и т.д. → русские названия
mapGdsId()	V6	crs (число) → название GDS
mapSegmentStatus()	V6	Код статуса сегмента → название
buildPassengerDocInfo()	V6	Извлечение 4 полей документа пассажира (birth_date, gender, doc_type, doc_number)
mapTicketStatus()	V1	TKT→продажа, REF→возврат

Конъюнкции V6: скрытые конъюнкции
Проблема: Некоторые заказы «Мой агент» содержат конъюнкционные бланки, которые не связаны через emd_ticket_doc[@main_prod_id]. Вместо этого они оформлены как обычные air_ticket_prod + air_ticket_doc с операцией TKT.

Признаки скрытой конъюнкции:

fare="0", taxes="0"
Нет дочерних <air_seg> (0 сегментов)
Тот же psgr_id что у основного продукта
tkt_number отличается на 1–9 от основного
Примеры:


Заказ 125359082621: prod_id=0 (tkt=...777, fare=45595, 4 сегмента)
                    prod_id=2 (tkt=...778, fare=0, 0 сегментов) ← скрытая конъюнкция

Заказ 125359052102: prod_id=0 (tkt=...454, fare=30125, 4 сегмента)
                    prod_id=2 (tkt=...455, fare=0, 0 сегментов) ← скрытая конъюнкция
Алгоритм определения (этап 2 в buildConjLinksMap):

Для каждого air_ticket_prod с fare=0 и seg_count=0
Если не привязан через этап 1 (emd_ticket_doc)
Ищем кандидата: тот же psgr_id, fare > 0, есть сегменты, tkt_number ± 1..9
Выбираем ближайший по номеру билета
Нюанс PHP: числовые строковые ключи массива автоматически конвертируются в integer ("0" → 0). При сравнении используется явное (string) приведение.

Тест: 125359052102.xml — тест-кейс 6 в test.php

6.2. DemoHotelParser — шаблон
Файл: parsers/DemoHotelParser.php (202 строки)
Папка: input/demo_hotel/
Формат XML: <hotel_order>
Служит шаблоном для новых парсеров
7. API Sender — отправка в 1С
7.1. Параметры
json

{
  "api": {
    "enabled": true,
    "url": "http://10.30.88.24/TRADE/hs/CRM_Exchange/Order",
    "login": "api_user",
    "password": "api_password",
    "timeout": 5
  }
}
7.2. Формат отправки
Метод: POST, Auth: Basic Auth
Заголовки: Content-Type: application/json; charset=utf-8, Accept: application/json
Тело: JSON ORDER (без SOURCE_FILE, PARSED_AT)
SSL: верификация отключена
7.3. Проверка доступности
isAvailable() — HEAD-запрос, timeout 2с. Вызывается перед циклом обработки. Результат кешируется на весь цикл.

7.4. Логирование
Каждая отправка → строка в logs/api_send.log (JSON Lines):

json

{"timestamp":"2026-02-26 13:30:00","file":"order.json","source_xml":"order.xml","status":"success","http_code":200,"response":"OK","duration":0.45}
7.5. Повторная отправка (resend)
api.php?action=resend (POST) → читает JSON из json/, удаляет служебные поля, отправляет в ApiSender.

8. SFTP-синхронизатор
8.1. Назначение
Автоматическое копирование XML-файлов с SFTP-сервера поставщика («Мой агент») в локальную папку input/moyagent/ для дальнейшей обработки парсером.

8.2. Архитектура
core/SftpSync.php (~280 строк) — класс SFTP-клиента на основе cURL
sftp_sync.php (~110 строк) — автономная точка входа (CLI + браузер)
Транспорт: cURL + протокол SFTP (libssh2), без ext-ssh2, без внешних библиотек
Аутентификация: по паролю (CURLSSH_AUTH_PASSWORD)
Проверка host key: отключена (внутренняя сеть, CURLOPT_SSH_KNOWNHOSTS → /dev/null)
8.3. Настройки (секция sftp в settings.json)
json

{
  "sftp": {
    "enabled": true,
    "host": "10.4.175.11",
    "port": 22,
    "login": "myagent",
    "password": "Kur123",
    "remote_path": "ma-files",
    "local_path": "input/moyagent",
    "interval": 60
  }
}
Параметр	Тип	Описание
enabled	bool	Включить/выключить синхронизацию
host	string	IP-адрес SFTP-сервера
port	int	Порт SSH (по умолчанию 22)
login	string	Имя пользователя SFTP
password	string	Пароль SFTP
remote_path	string	Папка на SFTP (относительный путь от ~/)
local_path	string	Локальная папка (относительный или абсолютный путь)
interval	int	Интервал между синхронизациями (секунды)
8.4. Логика синхронизации

1. Проверить интервал (sftp_last_run.txt + interval)
2. Прочитать настройки из settings.json → секция sftp
3. Подключиться к SFTP (testConnection)
4. Получить список *.xml файлов (listRemoteXmlFiles)
5. Для каждого файла:
   a. Скачать в local_path/ (downloadFile)
   b. Проверить: файл существует, размер > 0
   c. Переместить на SFTP в remote_path/Processed/ (moveToProcessed)
6. Обновить sftp_last_run.txt
7. Записать в logs/sftp_sync.log
8.5. Методы SftpSync
Метод	Описание
__construct($config, $logFile)	Настройка подключения, формирование baseUrl
sync()	Основной метод: листинг → скачать → переместить
testConnection()	HEAD-запрос к SFTP для проверки связи
listRemoteXmlFiles()	CURLOPT_DIRLISTONLY, фильтр *.xml
downloadFile($remote, $local)	CURLOPT_FILE → fwrite в локальный файл
moveToProcessed($fileName)	CURLOPT_QUOTE → rename на SFTP
buildRemoteUrl($suffix)	Формирование sftp://user@host:port/~/path/
createCurlHandle($url)	Общие настройки cURL: auth, known_hosts, SSH_AUTH_TYPES
8.6. Перемещение файлов (двухуровневое)

SFTP-сервер                          Сервер парсера
ma-files/                            input/moyagent/
├── order123.xml ──── скачать ────→  ├── order123.xml
│                                    │
├── Processed/                       ├── Processed/
│   └── order123.xml ← переместить   │   └── order123.xml ← после парсинга
│       (SftpSync)                   │       (Processor)
│                                    │
                                     ├── Error/
                                     │   └── bad.xml ← если ошибка парсинга
                                     │       (Processor)
На SFTP: SftpSync перемещает скачанный файл в ma-files/Processed/
Локально: Processor перемещает обработанный файл в input/moyagent/Processed/ (успех) или input/moyagent/Error/ (ошибка) — без изменений
8.7. Запуск
**Встроенный pipeline (без cron):** кнопка «Запустить» и автообработка в index.php вызывают api.php?action=run → runSftpSync + runProcessing (SFTP + обработка в одном запросе).

bash

# CLI (cron) — один скрипт: SFTP + обработка
* * * * * php /path/to/parser_v5/process.php

# Standalone SFTP (если нужен отдельный запуск)
* * * * * php /path/to/parser_v5/sftp_sync.php
http://server/parser_v5/sftp_sync.php?force=1
php sftp_sync.php --force
8.8. Логирование
Отдельный файл logs/sftp_sync.log, формат как app.log:


[2026-03-10 14:07:26] [INFO] Начало синхронизации SFTP
[2026-03-10 14:07:26] [INFO] Сервер: 10.4.175.11, папка: ma-files
[2026-03-10 14:07:27] [INFO] Соединение с SFTP установлено
[2026-03-10 14:07:27] [INFO] Найдено файлов на SFTP: 3
[2026-03-10 14:07:28] [SUCCESS] Скачан: order123.xml (45.2 KB)
[2026-03-10 14:07:28] [INFO] Перемещён на SFTP в Processed/: order123.xml
[2026-03-10 14:07:29] [INFO] Синхронизация завершена. Скачано: 3, ошибок: 0
Ротация: >5 МБ → sftp_sync.log.old

8.9. Текущий статус
✅ Код реализован и протестирован (подключение работает)
⚠️ Сетевой доступ не открыт: сервер парсера (127.0.0.1) не может достучаться до SFTP-сервера (10.4.175.11) — все порты timeout
⏳ Требуется: администратор сети должен открыть доступ с сервера парсера к 10.4.175.11:22
9. Web UI
9.1. index.php — Панель управления
Логи в реальном времени (polling 3с)
Кнопка «Запустить обработку»
Тумблер автообработки
Настройки API (url, login, password, timeout, enabled)
JS: assets/app.js (372 строки)
9.2. data.php — Обработанные заказы
Серверный рендеринг, 60 колонок
Кнопка 🔄 для повторной отправки
Resizable-столбцы (drag-resize)
Фильтр: поле поиска по всем колонкам (клиентская фильтрация)
Сортировка: по дате выписки билета или дате загрузки (↑/↓)
Выгрузка в XLSX: кнопка «Выгрузить в XLSX» — экспорт видимых строк (SheetJS CDN)
formatRstlsDate() — "20251013121600" → "13.10.2025 12:16"
formatAgent() — антидубль (V5): CODE===NAME → одно значение
Даты вылета/прилёта: все сегменты через запятую (V5)
60 колонок:

#	Колонка	Источник
1	🔄	UI
2	#	счётчик
3	Файл	имя JSON
4	Номер заказа	INVOICE_NUMBER
5	Дата заказа	INVOICE_DATA
6	Клиент	CLIENT
7	Тип продукта	PRODUCT_TYPE.NAME
8	Номер билета	NUMBER
9	Дата выписки	ISSUE_DATE
10	Статус	STATUS
11	Пассажир	TRAVELLER
12	Поставщик	SUPPLIER
13	Перевозчик	CARRIER
14	Маршрут	COUPONS[].AIRPORTS
15	Сумма	sum(PAYMENTS[].EQUIVALENT_AMOUNT)
16	Валюта	CURRENCY
17	Исходный файл	SOURCE_FILE
18	Дата загрузки	PARSED_AT
19	UID заказа	UID (8 символов)
20	UID продукта	PRODUCT.UID (8 символов)
21	Номер брони	RESERVATION_NUMBER
22	Выпис. агент	BOOKING_AGENT (formatAgent)
23	Агент	AGENT (formatAgent)
24	Тип билета	TICKET_TYPE
25	Возраст	PASSENGER_AGE
26	Дата рождения	PASSENGER_BIRTH_DATE
27	Пол	PASSENGER_GENDER
28	Тип документа	PASSENGER_DOC_TYPE
29	Номер документа	PASSENGER_DOC_NUMBER
30	Бланки	CONJ_COUNT
31	Штраф обмен	PENALTY
32	Рейсы	COUPONS[].FLIGHT_NUMBER через запятую
33	Fare Basis	COUPONS[].FARE_BASIS через запятую
34	Класс	COUPONS[].CLASS через запятую
35	Класс обслуж.	COUPONS[].CLASS_NAME
36	Класс перелёта	COUPONS[].TYPE_ID_NAME
37	Тип перелёта	FLIGHT_TYPE
38	GDS ID	GDS_ID (reservation crs)
39	GDS	GDS_NAME
40	Дата вылета	все COUPONS[].DEPARTURE_DATETIME через запятую
41	Дата прилёта	все COUPONS[].ARRIVAL_DATETIME через запятую
42	Тариф (руб)	TAXES[] CODE=""
43	Таксы (руб)	TAXES[] CODE≠""
44	НДС (руб)	sum(TAXES[].VAT_AMOUNT)
45	Тип платежа	PAYMENTS[].TYPE
46	Оплата (руб)	PAYMENTS[] INVOICE
47	Зачёт (руб)	PAYMENTS[] TICKET
48	Связ. билет	PAYMENTS[].RELATED_TICKET_NUMBER
49	Комиссия ТКП	COMMISSIONS[] VENDOR
50	Ставка %	COMMISSIONS[] VENDOR.RATE
51	Серв. сбор	COMMISSIONS[] CLIENT ~"сервисный сбор"
52	Сбор пост.	COMMISSIONS[] CLIENT ~"сбор поставщика"
53	Дата возврата	REFUND.DATA
54	Сумма возврата	REFUND.EQUIVALENT_AMOUNT
55	Сбор РСТЛС	REFUND.FEE_CLIENT
56	Сбор пост. возвр.	REFUND.FEE_VENDOR
57	Штраф пост.	REFUND.PENALTY_VENDOR
58	Штраф РСТЛС	REFUND.PENALTY_CLIENT
9.3. api_logs.php — Логи API
Двухрежимная: HTML-страница + AJAX к самой себе
Читает logs/api_send.log (JSON Lines)
Не использует app.js — встроенные скрипты
9.4. test.php — Автотесты
Web + CLI (php_sapi_name() === 'cli')
Тестирует MoyAgentParser на 6 фикстурах из tests/fixtures/
7 фикстур, assertions по каждому файлу
Вспомогательная функция addCheck() — DRY вместо копипаста
PASS-блоки свёрнуты по умолчанию, FAIL — развёрнуты
Badge показывает количество проверок: PASS (22)
Фикстуры и покрытие:

Файл	Описание	Ключевые проверки
125358843227.xml	Продажа, 1 билет, SVO→KZN→SVO	Базовые + даты + CLIENT/VENDOR комиссии
125358829987.xml	Продажа, 3 билета, 3 пассажира	all_travellers, all_tickets
125358832021.xml	Продажа + EMD (2 продукта: авиа + EMD)	2 продукта, даты
125358832769.xml	Возврат REF (2 продукта: авиа + EMD возврат)	REFUND, PENALTY, REFUND.AMOUNT
125359005865.xml	5 авиа + 5 EMD = 10 продуктов, SVO→AUH→SVO	BOOKING_AGENT, AGENT, all_travellers, all_tickets
125358954718.xml	Возврат + 2 EMD (номера и ненулевая сумма), penalty 5060	REFUND, 3 продукта
125359052102.xml	Скрытая конъюнкция (без emd_ticket_doc), VKO→TIV	CONJ_COUNT=2
Категории проверок:

Категория	Проверки
Базовые (все файлы)	Парсинг, UID заказа, INVOICE_NUMBER, CLIENT, кол-во продуктов
Первый продукт	Статус, номер билета, пассажир, перевозчик, купоны, валюта, тариф, UID продукта, JSON
V5: маппинг	SUPPLIER, RESERVATION_NUMBER, CONJ_COUNT
V5: агенты	BOOKING_AGENT, AGENT (только 125359005865)
V5: даты	first_dep_dt, first_arr_dt, last_dep_dt, last_arr_dt
V5: комиссии	CLIENT комиссия + сумма, VENDOR комиссия + сумма
Возвраты	REFUND, PENALTY, REFUND.AMOUNT
Multi-passenger	all_travellers, all_tickets
9.5. api.php — AJAX API
action	Метод	Описание
logs	GET	Последние N строк app.log
run	POST	runSftpSync + runProcessing (SFTP + обработка, force=true)
settings	GET/POST	Чтение/запись settings.json
clear_logs	POST	Очистка app.log
clear_json	POST	Удаление всех *.json из json/
resend	POST	Повторная отправка JSON в 1С
10. Текущее состояние
Реализовано (✅)
✅ Ядро: Processor, ParserManager, Logger, ParserInterface, Utils
✅ Парсер «Мой агент» V5 — конъюнкции, маппинг полей
✅ Демо-парсер отелей (шаблон)
✅ Отправка в API 1С (HTTP POST, Basic Auth)
✅ Веб-интерфейс (панель, таблица 60 колонок, логи API, тесты)
✅ Повторная отправка (кнопка 🔄), очистка JSON (кнопка «Очистить таблицу»)
✅ Resizable-столбцы в data.php
✅ Автотесты (test.php, 6 фикстур, 132 assertions)
✅ SFTP-синхронизатор встроен в обработку — при «Запустить» и автообработке (cURL+SFTP)
В ожидании (⏳)
⏳ Сетевой доступ к SFTP-серверу — администратор сети должен открыть порт 22 с сервера парсера к 10.4.175.11
Известные проблемы (⚠️)
⚠️ Нет retry-логики при отправке в 1С
⚠️ data.php — серверный рендеринг — может быть медленным
⚠️ SFTP-сервер 10.4.175.11 недоступен с сервера парсера (все порты timeout)
11. Последние изменения
Дата	Действие	Файлы
2026-03-13	Зачёт и Связ.билет: buildPaymentsFromXml из xml->payments, tkt_fop ПК/БИЛЕТ→TYPE=TICKET, EXCH в analyzeOrderType	parsers/MoyAgentParser.php, MoyAgentConstants.php
2026-03-13	SFTP timeout 5→2с (CONNECTTIMEOUT=1), автообработка сохраняется при навигации (localStorage), AbortController для fetch	core/SftpSync.php, assets/app.js
2026-03-13	SFTP встроен в pipeline: runSftpSync в runProcessing, кнопка «Запустить» и автообработка забирают XML с SFTP	process.php, assets/app.js
2026-03-13	Кнопка «Очистить таблицу» удаляет все JSON из json/ (api.php clear_json, data.php)	api.php, data.php
2026-03-13	Справочник MoyAgentConstants, маппинги (типы пассажиров, пол, классы, type_id, GDS, тип перелёта), FLIGHT_TYPE/GDS в JSON, 6 колонок в data.php (55→60)	parsers/constants/MoyAgentConstants.php, parsers/MoyAgentParser.php, data.php
2026-03-13	4 поля пассажира (дата рождения, пол, тип документа, номер документа) из XML в JSON и таблицу (51→55 колонок)	parsers/MoyAgentParser.php, data.php
2026-03-13	Футер привязан к правому нижнему углу экрана (position: fixed)	assets/style.css
2026-03-13	Исправлены тесты + фикстура 125358954718 (7 фикстур, 153 assertions)	test.php, tests/fixtures/
2026-03-13	Сводка тестов в одну строку, кнопка справа	test.php
2026-03-13	Максимально минимальные отступы на test.php	test.php
2026-03-13	Минимальные отступы во всём интерфейсе	assets/style.css
2026-03-13	Единый компактный стиль на всех страницах (compact header + wide layout)	style.css, index.php, api_logs.php, test.php
2026-03-13	EMD без номера и с нулевой суммой пропускаются (не попадают в JSON)	parsers/MoyAgentParser.php
2026-03-13	Удалено поле EMD_VALUE (EMD значение) из JSON-продуктов и таблицы	parsers/MoyAgentParser.php, data.php
2026-03-13	data.php: фильтр по таблице, сортировка (дата выписки/загрузки), выгрузка в XLSX (SheetJS)	data.php
2026-03-13	Исправления MoyAgentParser: original_prod_id по tkt_number, использование reservationsMap, тесты под EMD как отдельные продукты	parsers/MoyAgentParser.php, test.php
2026-03-10	SFTP-синхронизатор: cURL+SFTP, автономный модуль	core/SftpSync.php, sftp_sync.php
2026-03-10	Настройки SFTP в settings.json (секция sftp)	config/settings.json
2026-03-03	Тесты обновлены под V5 (5 фикстур, ~109 assertions)	test.php
2026-03-03	MoyAgentParser V5: конъюнкции + маппинг	MoyAgentParser.php
2026-03-03	data.php: formatAgent, даты сегментов	data.php
2026-02-26	Автотесты test.php (4 фикстуры)	test.php
2026-02-26	Рефакторинг UUID → Utils.php	Utils.php, парсеры
2026-02-26	Исправлен баг resend (dead code)	api.php
2026-02-26	Resizable-столбцы, ext-curl	data.php, style.css
12. Соглашения по коду
PHP: 7.0 синтаксис — array() вместо [], без type hints
UUID: только через Utils::generateUUID(), require core/Utils.php
Комментарии: на русском
JSON: JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT
Новый парсер: parsers/XxxParser.php implements ParserInterface + input/folder/
CSS: BEM-нотация
JS: ES5 (совместимость), fetch API
13. Безопасность и деплой
input/ и json/ — в .gitignore
config/settings.json — модифицируется автоматически (last_run), содержит пароли
config/sftp_last_run.txt — модифицируется автоматически
API и SFTP пароли хранятся в settings.json открытым текстом
SSL верификация отключена в ApiSender (внутренняя сеть)
SSH host key проверка отключена в SftpSync (внутренняя сеть)
ext-curl обязателен (с поддержкой SFTP/libssh2 для синхронизации)
14. Контекст для AI-ассистента
Критические правила
UUID — только Utils::generateUUID(), require core/Utils.php
Processor привязан к glob(*.xml) — другие форматы потребуют рефакторинга
Нет retry при отправке в 1С; ручная переотправка через 🔄 в data.php
app.js обслуживает только index.php; data.php и api_logs.php имеют встроенные скрипты
SFTP встроен в runProcessing(); sftp_sync.php — standalone для отдельного запуска
ext-curl обязателен (+ поддержка SFTP через libssh2)
settings.json модифицируется автоматически (last_run), содержит секции api и sftp
SUPPLIER берётся из getSupplierName(), НЕ из air_ticket_prod[@supplier]
AGENT берётся из air_ticket_doc[@issuingAgent], НЕ из air_ticket_prod[@issuingAgent]
BOOKING_AGENT берётся из reservation[@bookingAgent]
RESERVATION_NUMBER берётся из reservation[@rloc] через getMainReservation()
Конъюнкции группируются через emd_ticket_doc[@main_prod_id]
data.php formatAgent() — антидубль: если CODE===NAME → одно значение
data.php даты — все сегменты через запятую, не первый/последний
SFTP вызывается из runProcessing() перед Processor; единый pipeline при «Запустить» и автообработке
SFTP-путь относительный (от домашней директории пользователя, формат ~/remote_path/)
Тестовые фикстуры — реальные имена
Фикстуры названы по номерам заказов (ord_id из XML), не по типу теста:

125358843227 — простая продажа (без конъюнкций)
125358829987 — мульти-пассажир (3 билета)
125358832021 — конъюнкция EMD (2 air_ticket_prod → 1 PRODUCT)
125358832769 — возврат REF с penalty
125359005865 — 5 конъюнкций (10 air_ticket_prod → 5 PRODUCTS), ключевой тест V5