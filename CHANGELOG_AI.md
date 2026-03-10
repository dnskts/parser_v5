# CHANGELOG_AI — История изменений (AI-ассистент)

---

## 2026-03-10 — SFTP-синхронизатор: автоматическое копирование XML с сервера поставщика

**Запрос пользователя:** Настроить копирование XML-файлов с SFTP-сервера поставщика (sftp://myagent@10.4.175.11/ma-files) в локальную папку проекта. Файлы после скачивания перемещать в Processed на SFTP.

### Диагностика сервера

1. **diag.php** — проверка окружения PHP:
   - PHP 7.4.33, Linux
   - cURL 7.29.0, **libssh2 1.4.3** — протокол SFTP поддерживается ✅
   - ext-ssh2: НЕТ (не нужен — используем cURL)
   - Решение: **cURL + SFTP** — ноль новых зависимостей

2. **diag_sftp.php** — проверка сетевого доступа:
   - Все 6 вариантов URL → **Connection timed out**
   - Все порты (22, 2222, 2200, 21, 990) → закрыты/timeout
   - Сервер парсера (127.0.0.1) не имеет сетевого маршрута до 10.4.175.11
   - **Вывод:** требуется открытие доступа администратором сети

### Что было сделано

**Новые файлы:**

1. **`core/SftpSync.php`** (~280 строк) — класс SFTP-клиента:
   - Транспорт: cURL + протокол SFTP (libssh2, без ext-ssh2)
   - Аутентификация: по паролю (CURLSSH_AUTH_PASSWORD)
   - Host key проверка: отключена (внутренняя сеть, CURLOPT_SSH_KNOWNHOSTS → /dev/null)
   - Методы: `sync()`, `testConnection()`, `listRemoteXmlFiles()`, `downloadFile()`, `moveToProcessed()`, `buildRemoteUrl()`, `createCurlHandle()`
   - Относительный путь через `~/remote_path/`
   - Собственный лог с ротацией 5 МБ

2. **`sftp_sync.php`** (~110 строк) — автономная точка входа:
   - Читает настройки из `config/settings.json` секция `sftp`
   - Контроль интервала через `config/sftp_last_run.txt`
   - Режимы: CLI (cron), браузер, force (--force / ?force=1)
   - Преобразование относительного local_path в абсолютный
   - Вывод: CLI — текстовый, браузер — JSON

**Изменённые файлы:**

3. **`config/settings.json`** — добавлена секция `sftp`:
   ```json
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
Архитектурные решения
Подход «синхронизация» вместо «прямого чтения»: SFTP → скачать в локальную папку → Processor работает как раньше. Processor.php не изменён.
cURL вместо ext-ssh2/phpseclib: на сервере cURL собран с libssh2 — ноль новых зависимостей.
Настройки в settings.json (не в коде): единое место для всех кредов (api + sftp), консистентно с проектом.
Двухуровневое перемещение: SftpSync → Processed на SFTP; Processor → Processed/Error локально.
Автономный модуль: sftp_sync.php не связан с Web UI, отдельный лог sftp_sync.log.
Pipeline стал двухэтапным

Cron: sftp_sync.php && sleep 5 && process.php

Этап 1: SFTP → input/moyagent/ (скачать + переместить на SFTP в Processed/)
Этап 2: input/moyagent/ → parse → json/ + Processed/Error/ + API 1С
Текущий статус
✅ Код реализован, синтаксически корректен
✅ cURL подключается к SFTP без ошибок PHP
⚠️ Сетевой доступ не открыт — 10.4.175.11:22 недоступен с сервера парсера (все порты timeout)
⏳ Требуется: администратор сети открывает порт 22
Изменённые файлы
core/SftpSync.php — новый файл, SFTP-клиент
sftp_sync.php — новый файл, точка входа
config/settings.json — добавлена секция sftp
CURRENT_STAGE.md — добавлена секция 8 (SFTP), обновлены секции 1–4, 10–14
structure.md — добавлены SftpSync.php, sftp_sync.php, sftp_last_run.txt, sftp_sync.log
CHANGELOG_AI.md — эта запись
2026-03-03 — MoyAgentParser V5: исправлен маппинг полей + конъюнкции; data.php: даты и агенты
Запрос пользователя: Привести парсер и таблицу в соответствие с форматом 1С (по скриншоту ручного заполнения).

Что было сделано
MoyAgentParser.php — крупная переработка (V3 → V4 → V5):

Группировка конъюнкций (V4):

Новый метод buildConjLinksMap() — парсит emd_ticket_doc[@main_prod_id] из travel_docs, строит карту child_prod_id → main_prod_id
Новый метод findRelatedProdIds() — собирает все связанные prod_id для возвратов (по tkt_number + conjLinks)
Продажа: child-продукты (с main_prod_id) присоединяются к main-продукту вместо создания orphan-записей
Результат: 10 PRODUCTS → 5 PRODUCTS (было по 2 на пассажира — основной + конъюнкция с пустыми данными)
Исправлен маппинг полей (V5):

SUPPLIER: было 607 (из air_ticket_prod[@supplier]) → стало Мой агент (из getSupplierName())
RESERVATION_NUMBER: было "" (не находил по ключу 607 в reservationsMap) → стало G1ZXKP (из reservation[@rloc] через новый метод getMainReservation())
BOOKING_AGENT: было {CODE:"1", NAME:""} (числовой ID из air_ticket_prod) → стало {CODE:"Валерия Подунай", NAME:"Валерия Подунай"} (из reservation[@bookingAgent])
AGENT: было {CODE:"1", NAME:""} → стало {CODE:"Валерия Подунай", NAME:"Валерия Подунай"} (из air_ticket_doc[@issuingAgent])
Новые/изменённые методы:

getMainReservation($xml) — новый, возвращает первую reservation из XML
buildConjLinksMap($xml) — новый, карта связей конъюнкций из emd_ticket_doc
findRelatedProdIds() — новый, поиск всех связанных prod_id для возвратов
buildTravelDocsMap() — изменён, добавлено поле issuingAgent (ФИО агента)
buildReservationsMap() — изменён, добавлено поле bookingAgent (ФИО бронирующего)
buildCouponsFromGroup() — из V4, собирает купоны из группы prod_id с дедупликацией
buildTaxesFromGroup() — из V4, собирает таксы из группы prod_id с дедупликацией
*Удалённые методы (заменены на FromGroup):

buildCoupons() → buildCouponsFromGroup()
buildTaxes() → buildTaxesFromGroup()
data.php — два исправления:

formatAgent() — исправлено дублирование: если CODE и NAME совпадают, возвращается одно значение. Было "Валерия Подунай Валерия Подунай" → стало "Валерия Подунай"

Даты вылета/прилёта — теперь все сегменты через запятую (было: первый/последний купон). Было "05.04.2026 12:40" / "11.04.2026 19:05" → стало "05.04.2026 12:40, 11.04.2026 14:15" / "05.04.2026 19:10, 11.04.2026 19:05"

Изменённые файлы
parsers/MoyAgentParser.php — V5: конъюнкции, маппинг полей, новые методы
data.php — formatAgent() антидубль, даты всех сегментов через запятую
CURRENT_STAGE.md — обновлена документация парсера и таблицы
CHANGELOG_AI.md — эта запись
2026-02-26 18:00 — Обновление документации после трёх коммитов
Запрос пользователя: Обновить документацию проекта по результатам git diff.

Что было сделано
Зафиксированы три значительных изменения, внесённых в код проекта:

Исправлен баг resend в api.php (коммит e367530):

Dead code if ($action === 'resend') (строки 186–221) перенесён в case 'resend': внутри switch
Добавлена проверка метода (POST, иначе HTTP 405)
Добавлены HTTP-коды ошибок: 400 (некорректное имя / невалидный JSON), 404 (файл не найден)
Использует $configFile вместо хардкода пути к settings.json
Кнопка 🔄 в data.php теперь работает корректно
Рефакторинг generateUUID():

Создан core/Utils.php с классом Utils и статическим методом generateUUID()
MoyAgentParser.php: удалён приватный метод (31 строка), заменён на Utils::generateUUID() (3 вызова)
DemoHotelParser.php: удалён приватный метод (16 строк), заменён на Utils::generateUUID() (2 вызова)
Добавлена страница автотестов test.php (652 строки):

Веб-интерфейс + CLI-режим (php_sapi_name() === 'cli')
Тестирует MoyAgentParser на 4 фикстурах из tests/fixtures/
~15 assertions на файл: парсинг, UID, INVOICE_NUMBER, CLIENT, продукты, статус, билет, пассажир, перевозчик, купоны, валюта, тариф, UID продукта, JSON-сериализация, REFUND+PENALTY
Фикстуры: продажа (1 билет), продажа (3 билета), продажа+EMD (EUR→RUB), возврат REF
Навигация обновлена: добавлена ссылка «Тесты» → test.php в index.php, data.php, api_logs.php
Обновлённая документация
CURRENT_STAGE.md — секции 1 (статистика), 3 (структура), 7.5 (resend), 8.4-8.6 (UI), 9 (статус), 10 (лог), 11 (соглашения), 13 (AI-контекст)
structure.md — добавлены core/Utils.php, test.php, секция Tests с 4 фикстурами
CHANGELOG_AI.md — эта запись
[2025-02-26 18:00] Рефакторинг: вынесен generateUUID() в core/Utils.php
Изменено
core/Utils.php — создан новый файл с классом Utils и статическим методом generateUUID()
parsers/MoyAgentParser.php — удалён приватный метод generateUUID(), заменён на Utils::generateUUID() (3 вызова), добавлен require_once для Utils.php
parsers/DemoHotelParser.php — удалён приватный метод generateUUID(), заменён на Utils::generateUUID() (2 вызова), добавлен require_once для Utils.php
Причина
Одинаковый код генерации UUID дублировался в двух парсерах. При добавлении нового поставщика пришлось бы копировать в третий раз. Вынесено в общую утилиту — теперь новые парсеры просто вызывают Utils::generateUUID().

Файлы затронуты
core/Utils.php — новый файл
parsers/MoyAgentParser.php — удалён дубль, подключён Utils
parsers/DemoHotelParser.php — удалён дубль, подключён Utils
[2026-02-26 17:00] Исправлен баг resend — повторная отправка в API 1С
Исправлено
api.php: блок if ($action === 'resend') был dead code — располагался между break; (case 'clear_logs') и default: в switch, никогда не выполнялся
Перенесён в полноценный case 'resend': с проверкой POST, HTTP-кодами ошибок (400, 404, 405)
Кнопка 🔄 на странице data.php теперь работает корректно
Файлы затронуты
api.php — resend перенесён из dead code в case внутри switch
[2026-02-26] Resizable-столбцы в таблице заказов + исправление ext-curl
Добавлено
data.php: интерактивное изменение ширины столбцов таблицы перетаскиванием (drag-resize)
JS: замер естественных ширин → фиксация → table-layout: fixed → создание ресайзеров
CSS: .data-table-wrapper { overflow-x: auto }, .data-table { width: max-content }
Минимальная ширина столбца 50px, подсветка при перетаскивании
Исправлено
Ошибка «ОШИБКА СВЯЗИ С СЕРВЕРОМ» — причина: отсутствие расширения ext-curl в PHP. ApiSender::isAvailable() вызывал curl_init() после успешной обработки файлов, PHP падал с Fatal Error, вместо JSON-ответа браузер получал HTML-ошибку
Решение: включение extension=curl в php.ini
Файлы затронуты
data.php — добавлен JS-блок resizable columns
assets/style.css — добавлены стили для .data-table-wrapper и .data-table
php.ini — раскомментирован extension=curl
2026-02-26 14:00 — Финализация документации проекта
Запрос пользователя: Добавить пункты 13–14 в секцию 13 CURRENT_STAGE.md, исправить context-keeper.md, создать .cursorrules, обновить CHANGELOG_AI.md.

Что было сделано
Секция 13 CURRENT_STAGE.md — добавлены:

Пункт 13: Processor жёстко привязан к glob("*.xml") — при добавлении парсеров не-XML потребуется рефакторинг
Пункт 14: Следствие бага resend + отсутствие retry = риск потери данных, описан workaround через cURL
.cursor/skills/context-keeper.md — исправлены шаблоны:

Шаблон подтверждения контекста (строки 14–24) обёрнут в ```
Шаблон структуры CURRENT_STAGE.md (строки 56–108) обёрнут в ```markdown
Шаблон записи CHANGELOG_AI.md (строки 117–129) обёрнут в ```markdown
Ранее шаблоны были «голым» текстом и могли интерпретироваться как реальные markdown-заголовки
Создан .cursorrules в корне проекта — правила для AI-ассистента: читать CURRENT_STAGE.md и structure.md перед работой, обновлять контекст после каждого изменения

Изменённые файлы
CURRENT_STAGE.md — добавлены пункты 13 и 14 в секцию 13, обновлён заголовок и таблица изменений
.cursor/skills/context-keeper.md — 3 шаблона обёрнуты в code-блоки
.cursorrules — создан (новый файл)
CHANGELOG_AI.md — добавлена эта запись
2026-02-26 13:00 — Корректировки CURRENT_STAGE.md
Запрос пользователя: Обновить CURRENT_STAGE.md — 6 исправлений и дополнений по результатам ревью.

Что было сделано
Исправлен баг resend (секции 7.5, 8.2, 8.4, 9, 13):

Предыдущее описание утверждало, что код if ($action === 'resend') в api.php строки 186–221 «работает корректно» — это было неверно
Код расположен между break; (строка 184) и default: (строка 225) внутри switch ($action) — он никогда не выполняется (dead code)
action=resend всегда попадает в default: → HTTP 400 «Неизвестное действие»
Кнопка 🔄 в data.php полностью нерабочая
Квалификация изменена с «архитектурный quirk» на «критический баг»
Добавлена секция 6.0 — ParserInterface (контракт парсера):

Описаны все 3 метода интерфейса с сигнатурами
Добавлена инструкция по созданию нового парсера
Добавлена секция 4.5 — Logger (система логирования):

Формат строки: [Y-m-d H:i:s] [LEVEL] message
4 уровня: INFO, WARNING, ERROR, SUCCESS
Ротация: при >5 МБ → app.log.old (только 1 архив)
Запись с FILE_APPEND | LOCK_EX
Исправлено количество колонок в data.php (секция 8.2):

Было: «50 колонок» → Стало: «49 колонок»
Добавлена таблица всех 49 колонок с источниками данных
Добавлена секция 4.4 — Processor.run() (детальный алгоритм):

Пошаговый алгоритм из 5 этапов
Формат имени JSON-файла
Логика обработки коллизий имён
Добавлена статистика проекта (секция 1):

12 PHP-файлов, ~2 900 строк PHP
2 фронтенд-файла, ~920 строк
Итого ~3 800 строк, 2 парсера
Изменённые файлы
CURRENT_STAGE.md — 6 корректировок и дополнений
CHANGELOG_AI.md — добавлена эта запись
2026-02-26 10:30 — Инициализация файлов контекста
Запрос пользователя: Проанализировать весь проект целиком и создать файл CURRENT_STAGE.md по скиллу context-keeper. Файл должен быть настолько подробным, чтобы новый AI-ассистент мог сразу продолжить работу.

Что было сделано
Полный анализ всех 16 файлов проекта
Создан CURRENT_STAGE.md с 13 секциями (расширенная структура по скиллу context-keeper)
Создан CHANGELOG_AI.md (этот файл)
Обновлён structure.md — добавлена зависимость ext-curl
Проанализированные файлы
Core (5 файлов):

core/ParserInterface.php — интерфейс с 3 методами: getSupplierFolder(), getSupplierName(), parse()
core/ParserManager.php — auto-discovery через get_declared_classes() diff + ReflectionClass
core/Processor.php — оркестратор: обход папок → парсинг → saveJson → moveFile → apiSender.send()
core/Logger.php — 4 уровня (INFO/WARNING/ERROR/SUCCESS), ротация при >5МБ, getLastLines()
core/ApiSender.php — HTTP POST с Basic Auth через cURL, JSON Lines лог, explainCurlError()
Parsers (2 файла):

parsers/MoyAgentParser.php — 827 строк, парсер авиабилетов «Мой агент» (TKT/REF/RFND/CANX), analyzeOrderType()
parsers/DemoHotelParser.php — 202 строки, демо-парсер отелей (шаблон)
Web UI (5 файлов):

index.php — HTML главной страницы (панель управления)
process.php — точка входа пайплайна (CLI + модуль), функция runProcessing()
api.php — AJAX API (logs/run/settings/clear_logs/resend)
data.php — серверный рендеринг таблицы заказов (49 колонок), функции formatRstlsDate(), formatAgent()
api_logs.php — двухрежимная страница (HTML + AJAX API к самой себе)
Frontend (2 файла):

assets/app.js — 372 строки, обслуживает только index.php (логи, обработка, автообработка, настройки)
assets/style.css — общие стили (BEM)
Конфигурация (2 файла):

config/settings.json — interval, last_run, api.{enabled, url, login, password, timeout}
.gitignore — исключает input/ и json/
Найденные архитектурные quirks
generateUUID() дублируется в MoyAgentParser (стр. 810–826) и DemoHotelParser (стр. 191–200) — идентичный код, кандидат на вынос в core/Utils.php
⚠️ БАГ: Действие resend в api.php — dead code (стр. 186–221) — if блок между break; и default:, никогда не выполняется (см. запись 2026-02-26 13:00)
data.php — единственная страница с серверным рендерингом (без AJAX для данных), остальные используют AJAX
api_logs.php — двухрежимная (HTML-страница + JSON API в одном файле), не использует app.js
assets/app.js обслуживает только index.php — data.php и api_logs.php имеют встроенные скрипты
ext-curl не был указан в зависимостях structure.md и README, хотя обязателен для ApiSender
Нет retry-логики при отправке в 1С — только ручная повторная отправка через кнопку в data.php
config/settings.json модифицируется автоматически (last_run) при каждой обработке — не стоит коммитить
Принятые решения
Структура CURRENT_STAGE.md расширена до 13 секций (вместо 9 по шаблону скилла) для полноты описания
Добавлены ASCII-диаграммы потоков данных
Подробно описан формат ORDER/RSTLS с примером JSON
Для каждого парсера описана специфическая логика
В секции «Контекст для AI» перечислены все найденные quirks с номерами строк
Изменённые файлы
CURRENT_STAGE.md — создан (новый файл)
CHANGELOG_AI.md — создан (новый файл)
structure.md — добавлена зависимость ext-curl