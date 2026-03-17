# CHANGELOG_AI — История изменений (AI-ассистент)

---

## 2026-03-17 — SmartTravel PUSH+PULL интеграция (ЖД-билеты)

**Запрос пользователя:** Добавить поддержку SmartTravel (РЖД-Цифровые пассажирские решения) с одновременной работой PUSH (webhook) и PULL (API polling) режимов, единым парсером для обоих, минимальными правками в существующий pipeline.

### Что было сделано

**Новые файлы (5):**
- **`core/PullSync.php`** — PULL-синхронизатор SmartTravel. GET-запрос к API с Basic Auth + HTTP-прокси (закрытый контур банка). Сохраняет ответ в `input/smarttravel/pull_*.json`. Аналог SftpSync для REST API.
- **`webhook.php`** — Универсальный приёмник PUSH-уведомлений. Принимает POST с JSON, проверяет Basic Auth (если включено), сохраняет в `input/{supplier}/webhook_*.json`, вызывает `Processor->processSingleFile()`.
- **`parsers/SmartTravelParser.php`** — Единый парсер для PUSH и PULL. Определяет режим по ключам JSON (`Orders` → PULL, `OrderItem` → PUSH). PUSH → один ORDER, PULL → массив ORDER-ов. Маппинг: бланки, маршруты, пассажиры, комиссии, возвраты.
- **`parsers/constants/SmartTravelConstants.php`** — Справочник констант: OperationType, Sex, DocumentType, CarType, BlankStatus, ServiceType, PassengerCategory (таблицы 5-12, 21 из PDF). Таблицы транслитерации из `transliteration-tables.docx` (не используются по умолчанию).
- **`tests/fixtures/smarttravel_push_railway.json`** — Фикстура: ЖД покупка, 1 бланк, Москва→С-Петербург (из PDF section 2.4).

**Изменённые файлы (5):**
- **`core/Utils.php`** — Добавлен метод `curlWithProxy($url, $options)`: универсальный cURL-запрос с Basic Auth и HTTP-прокси.
- **`core/Processor.php`** — 3 изменения: glob расширен на `*.json`; поддержка multi-order (массив ORDER-ов из одного файла); добавлен публичный метод `processSingleFile($filePath, $folder)`.
- **`process.php`** — Добавлена функция `runPullSync($force)` с проверкой интервала и обновлением `last_run`. Вызов в pipeline: SFTP → PULL → Processor.
- **`config/settings.json`** — Новая секция `smarttravel` (enabled, mode, pull, webhook) + добавление в `tab_order`.
- **`test.php`** — Подключение SmartTravelParser, отдельный блок тестов для JSON-фикстур SmartTravel (23 проверки).

### Принятые решения
- **Один парсер для двух режимов** — SmartTravelParser определяет режим по структуре JSON (ключи `Orders` vs `OrderItem`), а не по конфигурации.
- **Переключение PUSH↔PULL** — одно поле `"mode"` в settings.json; перезапуск process.php.
- **Транслитерация** — таблицы из transliteration-tables.docx включены в SmartTravelConstants, но по умолчанию не применяются. Имена хранятся на кириллице. Доступно для будущего использования.
- **PRODUCT_TYPE** — `000000001` (ЖД-билет), аналогично авиа.
- **Прокси обязателен для PULL** — закрытый контур банка.
- **Webhook URL** — `webhook.php?supplier=smarttravel` (универсальный, поддерживает любых поставщиков).

### Тесты
- Все 247 тестов проходят (224 MoyAgent + 23 SmartTravel).

### Изменённые файлы (полный список)
- `core/Utils.php`
- `core/PullSync.php` (новый)
- `core/Processor.php`
- `webhook.php` (новый)
- `parsers/SmartTravelParser.php` (новый)
- `parsers/constants/SmartTravelConstants.php` (новый)
- `process.php`
- `config/settings.json`
- `test.php`
- `tests/fixtures/smarttravel_push_railway.json` (новый)
- `CURRENT_STAGE.md`
- `CHANGELOG_AI.md`
- `structure.md`

---

## 2026-03-16 — MoyAgent: контакты клиента, отчество и документ, discount/supplier_code, багаж/перевозчики сегментов + колонки data.php + тесты

**Запрос пользователя:** Добавить в JSON и таблицу: контакты клиента (с префиксом Cont), ФИО с отчеством в TRAVELLER, поля пассажира middle_name/doc_country/doc_expire, поля билета discount/supplier_code, а также bag_allowance и carrier сегментов. Протянуть до `data.php` через `core/DataTableHelpers.php` и обновить `test.php`.

### Что было сделано
- **parsers/MoyAgentParser.php**
  - `TRAVELLER` теперь собирается как «Фамилия Имя Отчество» (если отчество есть).
  - В продукт добавлены поля: `PASSENGER_MIDDLE_NAME`, `PASSENGER_DOC_COUNTRY`, `PASSENGER_DOC_EXPIRE`.
  - На уровень заказа добавлены: `CONT_EMAIL`, `CONT_PHONE`, `CONT_NAME`.
  - В продукт добавлены: `DISCOUNT`, `SUPPLIER_CODE`, `BAG_ALLOWANCE`, `SEG_CARRIERS` (агрегаты по сегментам).
- **core/DataTableHelpers.php** — новые поля прокинуты в строки таблицы (`cont_*`, `supplier_code`, `discount`, `bag_allowance`, `seg_carriers`, `passenger_*`).
- **data.php** — добавлено 10 колонок (итого 70), обновлён `renderRow()` и `colspan`-заглушки.
- **test.php** — расширены ожидания и добавлены проверки новых полей (контакты, отчество/документ, supplier_code/discount, багаж/перевозчики сегментов).

### Изменённые файлы
- `parsers/MoyAgentParser.php`
- `core/DataTableHelpers.php`
- `data.php`
- `test.php`
- `CURRENT_STAGE.md`
- `CHANGELOG_AI.md`

---

## 2026-03-16 — Реализация плана nextstep: retry 1С, SFTP статус, вкладки data.php, DataTableHelpers, тесты, Processor

**Запрос пользователя:** Реализовать план из nextstep: retry при отправке в 1С (с опцией 0), статус SFTP в панели, «Загрузить ещё» и вкладки по парсерам на data.php, динамический список фикстур в test.php, логирование времени парсинга, обновить SKILL и nextstep, документацию.

### Что было сделано
- **ApiSender:** В send() добавлен цикл retry при HTTP 5xx и таймауте cURL (errno 28). Параметры api.retry_attempts (0 = текущее поведение, одна попытка) и api.retry_delay_sec в settings.json. При 0 повторных попыток нет. Вынесена explanationForHttpCode() для 4xx/5xx.
- **process.php:** В результат runProcessing() добавлены sftp_skipped и sftp_status (пропущено/ошибки: N/скачано: N). **app.js:** сообщение после run отображает data.sftp_status.
- **core/DataTableHelpers.php:** Новый файл с formatRstlsDate(), formatAgent(), buildRowsFromJsonFile($filePath). Используется в api data_rows и вынесена общая логика строк таблицы.
- **api.php:** Добавлен action=data_rows (GET: supplier, offset, limit, sort, dir). glob по supplier_*.json, сортировка по filemtime, срез файлов, для каждого buildRowsFromJsonFile, объединение строк, сортировка по sort/dir, ответ rows, total_files, has_more.
- **data.php:** Переработан: список поставщиков из ParserManager (вкладки), данные через AJAX (data_rows). Первая загрузка — первый поставщик, offset 0, limit 50; переключение вкладок — кеш в памяти или запрос; кнопка «Загрузить ещё» — следующий fileOffset. Рендер строк в JS (renderRow), фильтр, сортировка, XLSX, resend, очистка таблицы сохранены. Добавлены стили .data-tabs, .data-tab, .data-load-more, .data-table__td--empty.
- **Processor:** Перед и после parser->parse() замер microtime(true), запись в app.log: «Время парсинга {fileName}: X с».
- **test.php:** Список тестов формируется из glob(tests/fixtures/*.xml). Для каждого файла: если нет в expectations — запись в results с no_expectations=true, описание «Нет ожиданий», без провала. В HTML и CLI выводится предупреждение. Добавлены стили test-file__header--warn, test-file__badge--warn.
- **SKILL update-structure:** В раздел When to trigger добавлено правило: после изменений обновлять CURRENT_STAGE.md, CHANGELOG_AI.md, structure.md; SisPrompt.md — при изменении ключевых правил или структуры.
- **nextstep.md:** Удалены выполненные пункты (1.1 retry+SFTP, 1.2 производительность, 1.4 фикстуры+время парсинга). Добавлены 10 новых шагов в раздел 2 (защита паролей, доступ api.php, несколько SFTP, мониторинг логов, алерты SFTP, тесты retry, документация cache_index и др.).
- **CURRENT_STAGE.md, structure.md, SisPrompt.md:** Обновлены под новую структуру (DataTableHelpers, data_rows, вкладки, retry, sftp_status, тесты, время парсинга).

### Изменённые/созданные файлы
- config/settings.json — api.retry_attempts, api.retry_delay_sec
- core/ApiSender.php — retry в send(), explanationForHttpCode()
- core/Processor.php — лог времени парсинга
- core/DataTableHelpers.php — новый
- process.php — sftp_skipped, sftp_status
- assets/app.js — вывод sftp_status
- api.php — action=data_rows
- data.php — полная переработка (вкладки, data_rows, «Загрузить ещё»)
- assets/style.css — .data-tabs, .data-tab, .data-load-more, .data-table__td--empty
- test.php — динамический список фикстур, no_expectations
- .cursor/skills/update-structure/SKILL.md — правило обновления доков
- nextstep.md — удалены сделанные пункты, 10 новых шагов
- CURRENT_STAGE.md, structure.md, SisPrompt.md, CHANGELOG_AI.md

### Принятые решения
- retry_attempts=0 сохраняет прежнее поведение (одна попытка в send()). isAvailable() не менялся.
- Вкладки data.php получают список из ParserManager; данные по вкладке кешируются в JS (loadedData[supplier]).
- «Загрузить ещё» передаёт fileOffset (смещение по файлам), а не по строкам; limit=50 файлов за запрос.

---

## 2026-03-16 — tab_order для вкладок и переименование «Мой агент» → «МА авиа»

**Запрос пользователя:** Вынести порядок вкладок на странице data.php в config/settings.json и сделать так, чтобы по умолчанию открывалась вкладка с «МА авиа» (бывший «Мой агент»), а также переименовать соответствующего поставщика.

### Что было сделано
- **config/settings.json:** Добавлен верхнеуровневый ключ `tab_order` — массив имён папок поставщиков (`getSupplierFolder()`), определяющий порядок вкладок на data.php. По умолчанию: `["moyagent", "demo_hotel"]`.
- **data.php:** При инициализации теперь читается `config/settings.json`, из него берётся `tab_order`. Список `$suppliers` после загрузки из `ParserManager` переупорядочивается согласно `tab_order`: первые элементы массива — поставщики из настроек в указанном порядке, остальные идут в конце (по алфавиту по имени папки). Активной вкладкой по умолчанию становится первый элемент отсортированного `$suppliers`, причём класс `data-tab--active` ставится по `folder`, а не по ссылочному сравнению массива.
- **parsers/MoyAgentParser.php:** Метод `getSupplierName()` обновлён: теперь возвращает строку `"МА авиа"` вместо `"Мой агент"`, так что во всех UI-элементах поставщик отображается как «МА авиа», при этом `getSupplierFolder()` по-прежнему возвращает `"moyagent"` (совместимо с `tab_order` и входной папкой `input/moyagent/`).
- **Документация:** В `CURRENT_STAGE.md` уточнено назначение `config/settings.json` (добавлены `tab_order`, `data_column_order`), а описание `MoyAgentParser.php` обновлено на «МА авиа» (с сохранением ссылки на происхождение «Мой агент»).

### Изменённые файлы
- `config/settings.json` — добавлен ключ `tab_order`.
- `data.php` — чтение настроек, сортировка `$suppliers` по `tab_order`, выбор активной вкладки по `folder`.
- `parsers/MoyAgentParser.php` — `getSupplierName()` теперь возвращает `"МА авиа"`.
- `CURRENT_STAGE.md` — обновлена шапка (последнее обновление) и описание settings.json/MoyAgentParser.

### Принятые решения
- Порядок вкладок управляется только через `tab_order` в `settings.json`, чтобы не плодить дополнительные точки конфигурации.
- Для определения активной вкладки используется логика «первый в `$suppliers` после сортировки»; JS продолжает опираться на `suppliers[0].folder`, что автоматически подхватывает изменения порядка.

---

## 2026-03-16 — Аудит проекта: документация, комментарии, nextstep.md

**Запрос пользователя:** Проанализировать проект, найти лишний код и ошибки, обновить документацию, описать все файлы в structure.md, проверить комментарии на русском, обновить системный промпт при необходимости, создать nextstep.md с планом улучшений и подробной инструкцией по подключению поставщиков через API.

### Что было сделано
- **Анализ:** Просмотр core/, parsers/, веб-файлов, тестов. Лишнего кода не выявлено; явных ошибок в логике нет. Исправлен порядок комментариев в test.php: блок «Тест 6» перенесён над массивом 125359052102.xml, блок «Тест 7» — над 125358954718.xml.
- **core/ApiSender.php:** Добавлены русскоязычные комментарии к классу и всем методам (назначение класса, isAvailable, send, httpPost, explainCurlError, writeLog, truncate, getLogEntries, clearLog) — понятным языком для непрограммиста.
- **structure.md:** Полностью переработан: подробное описание всех директорий и каждого файла (config, core, parsers, веб-точки входа, assets, tests/fixtures, logs, документация). Указаны назначение, формат и связи файлов.
- **nextstep.md:** Создан новый файл. Раздел 1 — план улучшений и оптимизации (retry 1С, проверка SFTP, производительность data.php, безопасность паролей и api.php, тесты). Раздел 2 — рекомендуемые следующие шаги с пояснениями. Раздел 3 — подробная инструкция по подключению новых поставщиков: различие «файловый поставщик» и «поставщик по HTTP API», пошаговое описание (папка input/, парсер, три метода, при API — модуль получения данных и сохранение в файлы), чек-лист.
- **SisPrompt.md:** Обновлено упоминание тестов: «6 фикстур, 132 assertions» → «7 фикстур, MoyAgentParser».
- **CURRENT_STAGE.md:** Обновлены дата и причина обновления; число фикстур 6→7; в таблицу фикстур добавлена строка 125358954718.xml; формулировка про assertions смягчена.

### Изменённые файлы
- `core/ApiSender.php` — комментарии на русском
- `test.php` — порядок комментариев Тест 6 / Тест 7
- `structure.md` — полное описание всех файлов
- `nextstep.md` — новый файл (план улучшений + подключение поставщиков)
- `SisPrompt.md` — количество фикстур
- `CURRENT_STAGE.md` — дата, фикстуры, таблица
- `CHANGELOG_AI.md` — эта запись

### Принятые решения
- Системный промпт (SisPrompt.md) не переписывался целиком — контекст уже достаточный; обновлено только число фикстур.
- Под «подключением поставщика через API» в nextstep.md понимаются оба варианта: 1) поставщик отдаёт данные по HTTP API (нужен модуль забора + парсер); 2) поставщик отдаёт файлы (достаточно парсера и папки input/).

---

## 2026-03-13 — Зачёт и Связ.билет при обмене (PAYMENTS из XML)

**Запрос пользователя:** Заполнять колонки «Зачёт» и «Связ. билет» при обмене.

### Что было сделано
- **MoyAgentConstants** — `getTicketCreditFopCodes()`: ПК, БИЛЕТ, ТКЕТ, TKT, EXCH (tkt_fop = зачёт по билету)
- **MoyAgentParser** — `buildPaymentsFromXml()`: парсит `xml->payments->payment`, при pay_oper=PAY и tkt_fop в списке зачёта формирует TYPE='TICKET' с RELATED_TICKET_NUMBER
- **analyzeOrderType** — добавлены EXCH и refund_ticket_number; при обмене возвращается номер возвращённого билета
- **Обмен (EXCH)** — при EXCH строятся и REF-продукт, и новый TKT-продукт; для нового билета используется buildPaymentsFromXml с refund_ticket_number
- **Fallback** — при отсутствии payments или без зачёта — один INVOICE (как раньше)

### Изменённые файлы
- `parsers/constants/MoyAgentConstants.php` — getTicketCreditFopCodes()
- `parsers/MoyAgentParser.php` — buildPaymentsFromXml, analyzeOrderType, ветка обмена

### Принятые решения
- tkt_fop в payment: ПК, БИЛЕТ, ТКЕТ, TKT, EXCH — зачёт по билету
- RELATED_TICKET_NUMBER: из payment[@tkt_number] или refund_ticket_number при обмене

---

## 2026-03-13 — SFTP timeout и автообработка при навигации

**Запрос пользователя:** Автообработка сбрасывалась при переходе на другую страницу; навигация блокировалась на 5 секунд из-за SFTP timeout.

### Что было сделано
- **core/SftpSync.php** — `testConnection()`: CONNECTTIMEOUT=1с, TIMEOUT=2с (было: только TIMEOUT=5с). Блокировка однопоточного PHP dev-сервера снижена с 5с до ~1с
- **assets/app.js** — состояние автообработки сохраняется в `localStorage('parser_auto_enabled')`; при загрузке `loadSettings` восстанавливает таймер
- **assets/app.js** — `AbortController` для fetch `api.php?action=run`; при `beforeunload` запрос прерывается, навигация не блокируется

### Изменённые файлы
- `core/SftpSync.php` — CURLOPT_CONNECTTIMEOUT + уменьшен TIMEOUT
- `assets/app.js` — localStorage, AbortController, восстановление авто

### Принятые решения
- На внутренней сети CONNECTTIMEOUT=1с достаточен: если сервер доступен, соединение <100мс
- localStorage надёжнее серверного хранения — работает без дополнительных запросов

---

## 2026-03-13 — SFTP встроен в панель обработки

**Запрос пользователя:** Встроить SFTP-синхронизацию так, чтобы работала через кнопку «Запустить обработку» и автообработку (по той же кнопке, что и забор XML).

### Что было сделано
- **process.php** — добавлена функция `runSftpSync($force)`: читает settings.json (секция sftp), при enabled и при force/интервале вызывает SftpSync->sync(), обновляет sftp_last_run.txt
- **runProcessing()** — в начале вызывает runSftpSync($force), затем Processor->run(); в результат добавляет sftp_downloaded, sftp_errors
- **assets/app.js** — при успешном ответе показывает «SFTP: N файлов. Готово: ...» если sftp_downloaded > 0

### Изменённые файлы
- `process.php` — runSftpSync(), интеграция в runProcessing()
- `assets/app.js` — отображение sftp_downloaded в статусе
- `CURRENT_STAGE.md` — pipeline, 8.7, контекст AI
- `structure.md` — описание process.php
- `CHANGELOG_AI.md` — эта запись

### Принятые решения
- SFTP вызывается перед Processor в каждом runProcessing(); sftp_sync.php остаётся для standalone-запуска
- При sftp.enabled=false или отсутствии секции sftp — runSftpSync возвращает skipped, обработка продолжается

---

## 2026-03-13 — Кнопка «Очистить таблицу» удаляет JSON-файлы

**Запрос пользователя:** При нажатии на «Очистить таблицу» удалять все JSON-файлы из папки json/.

### Что было сделано
- **api.php** — новый action `clear_json` (POST): удаляет все `*.json` из `json/`, возвращает количество удалённых файлов
- **data.php** — кнопка «Очистить таблицу» вызывает API `clear_json`, подтверждение, перезагрузка страницы после успеха

### Изменённые файлы
- `api.php` — case 'clear_json'
- `data.php` — id="btnClearTable", fetch + location.reload()

---

## 2026-03-13 — Справочник констант и маппинги для MoyAgentParser

**Запрос пользователя:** Подправить константы, добавить справочник, типы пассажиров/пол/классы/type_id/типы перелёта/GDS/статусы в JSON и таблицу.

### Что было сделано
- **parsers/constants/MoyAgentConstants.php** — новый справочник: типы пассажиров (adt, chd, inf, src, yth, ins), пол (M, F, MI, FI), типы документов, классы обслуживания (E,B,F,W,A), type_id (1–6), типы перелёта, статусы сегментов, GDS ID, статусы заказов
- **MoyAgentParser** — подключение справочника, mapPassengerAge/mapGender/mapDocType через константы; новые mapCabinClass, mapTypeId, mapFlightType, mapGdsId, mapSegmentStatus
- **Купоны** — COUPONS: CLASS (raw), CLASS_NAME, TYPE_ID, TYPE_ID_NAME, SEGMENT_STATUS, SEGMENT_STATUS_NAME
- **Product** — FLIGHT_TYPE (из tkt_charter), GDS_ID, GDS_NAME (из reservation crs)
- **buildTravelDocsMap** — добавить flight_type_raw из tkt_charter или flight_type
- **data.php** — 6 новых колонок: Класс обслуж., Класс перелёта, Тип перелёта, GDS ID, GDS (60 колонок, индексы 0..59)

### Изменённые файлы
- `parsers/constants/MoyAgentConstants.php` — новый
- `parsers/MoyAgentParser.php` — справочник, маппинги, купоны, product
- `data.php` — $rows[], thead, tbody
- `structure.md` — parsers/constants/, колонки

---

## 2026-03-13 — Футер привязан к правому нижнему углу экрана

**Запрос пользователя:** Футер должен быть привязан к правому нижнему углу экрана.

### Что было сделано
- Добавлены `position: fixed`, `bottom: 0`, `right: 0` к `.footer` — футер закреплён в правом нижнем углу viewport.

### Изменённые файлы
- `assets/style.css` — `.footer`

---

## 2026-03-13 — Исправлены тесты + добавлена фикстура 125358954718

**Запрос пользователя:** Разобраться почему упали тесты, добавить новый тест для 125358954718.xml.

### Причина падения
Изменение «EMD без номера и с нулевой суммой пропускаются» корректно фильтрует EMD, но ожидания в тестах не были обновлены.

### Что было сделано
- **125358832769.xml** — `products_count` 2 → 1 (EMD с пустым tkt_number и fare=0 фильтруется)
- **125359005865.xml** — `products_count` 10 → 5, убраны 5 дубликатов EMD из `all_travellers` и `all_tickets`
- **Новый тест 125358954718.xml** — возврат + 2 EMD (багаж + место) с номерами и ненулевыми суммами, penalty 5060
  - 3 продукта: 1 авиа-возврат + 2 EMD-возврата
  - BOOKING_AGENT/AGENT = Ольга Никифорова

### Результат
- 7 фикстур, 153 теста, 0 упало

### Изменённые файлы
- `test.php` — исправлены ожидания тестов 4 и 5, добавлен тест 7
- `tests/fixtures/125358954718.xml` — новая фикстура

---

## 2026-03-13 — Сводка тестов в одну строку с кнопкой справа

**Запрос пользователя:** Разместить блоки (тестов/прошло/упало) и кнопку перезапуска в одну строку, надписи справа от цифр, кнопку справа.

### Что было сделано
- Создан `.test-summary-row` — flex-контейнер для сводки и кнопки
- Карточки: число и подпись в одной строке (flex, gap 6px)
- Кнопка «Перезапустить тесты» справа (`margin-left: auto`)

### Изменённые файлы
- `test.php` — HTML и inline-стили

---

## 2026-03-13 — Максимально минимальные отступы на test.php

**Запрос пользователя:** Максимально уменьшить все отступы на странице test.php.

### Что было сделано
- Добавлен `panel--compact` к секции панели
- Добавлен `body.page-test` для изоляции стилей
- В inline-стилях test.php все padding/margin сведены к минимуму:
  - main: 4px 8px
  - panel: 6px 8px
  - test-summary: gap 8px, margin 8px
  - test-summary__card: 6px 10px
  - test-file: margin 6px, header padding 4px 8px
  - test-checks: th/td 4px 6px
  - test-meta, test-actions: margin 6px

### Изменённые файлы
- `test.php` — panel--compact, body.page-test, переработан inline <style>

---

## 2026-03-13 — Минимальные отступы

**Запрос пользователя:** Уменьшить отступы до минимума.

### Что было сделано
- Сокращены все padding и margin в интерфейсе:
  - Шапка: 4px 12px (было 6px 24px)
  - main: margin 8px, padding 0 12px (было 24px, 0 24px)
  - Панели и логи: padding 8–12px (было 24px)
  - Заголовки панелей: margin/padding 6–8px (было 12–20px)
  - Кнопки, инпуты, footer — уменьшены пропорционально
  - Таблица: ячейки 6px 8px (было 8px 12px)

### Изменённые файлы
- `assets/style.css` — все блоки с padding/margin

---

## 2026-03-13 — Единый компактный стиль на всех страницах

**Запрос пользователя:** Применить стиль data.php (компактная шапка, контент во всю ширину) на все остальные страницы.

### Что было сделано
- Из `.main` убран `flex: 1` — контейнер больше не растягивается на всю высоту viewport.
- На `index.php`, `api_logs.php`, `test.php` добавлены модификаторы, которые ранее были только на `data.php`:
  - `header--compact` — компактная шапка (6px вместо 24px)
  - `header__content--wide` — шапка без ограничения ширины
  - `main--wide` — контент без ограничения ширины

### Изменённые файлы
- `assets/style.css` — `.main`: убран `flex: 1`
- `index.php` — добавлены `header--compact`, `header__content--wide`, `main--wide`
- `api_logs.php` — добавлены `header--compact`, `header__content--wide`, `main--wide`
- `test.php` — добавлены `header--compact`, `header__content--wide`, `main--wide`

---

## 2026-03-13 — EMD без номера и с нулевой суммой пропускаются

**Запрос пользователя:** Если у EMD нет своего номера и сумма по нему равна нулю — не вносить в JSON

### Что было сделано
- В `buildEmdSaleProducts()` и `buildEmdRefundProducts()` добавлена проверка: если `tkt_number` пустой **и** `emdTotal == 0`, EMD пропускается (`continue`)

### Изменённые файлы
- `parsers/MoyAgentParser.php` — добавлен фильтр в оба метода формирования EMD-продуктов

### Принятые решения
- Условие через AND: EMD пропускается только когда **оба** признака совпадают (нет номера + нулевая сумма)

---

## 2026-03-13 — Удалено поле EMD_VALUE (EMD значение)

**Запрос пользователя:** Убрать поле «EMD значение» из таблицы и JSON

### Что было сделано
- Удалён ключ `EMD_VALUE` из массива EMD-продукта в `buildEmdSaleProducts()` и `buildEmdRefundProducts()`
- Удалён маппинг `emd_value` из формирования строк таблицы в `data.php`
- Удалён заголовок столбца `<th>EMD значение</th>` и ячейка `<td>emd_value</td>` из HTML-таблицы

### Изменённые файлы
- `parsers/MoyAgentParser.php` — удалён `'EMD_VALUE' => $rfisc` из двух методов (продажа и возврат EMD)
- `data.php` — удалены маппинг, заголовок и ячейка столбца «EMD значение»

### Принятые решения
- Поле `EMD_NAME` (EMD услуга) оставлено — убрано только `EMD_VALUE`
- RFISC-код (`$rfisc`) по-прежнему извлекается и используется в `resolveEmdName()` для определения имени EMD-услуги

---

## 2026-03-13 — data.php: фильтр, сортировка, выгрузка в XLSX

**Запрос пользователя:** Добавить в data.php фильтрацию по таблице, выгрузку в xlsx, сортировку по дате выписки и дате загрузки.

### Реализовано

1. **Фильтр** — поле ввода над таблицей; при вводе текста остаются только строки, содержащие это значение (поиск по всем колонкам, без учёта регистра). Счётчик «Показано: N из M».

2. **Сортировка** — переключатели «Дата выписки» и «Дата загрузки» с направлением ↑/↓. URL-параметры `?sort=issue_date|parsed_at&dir=asc|desc`. Добавлено поле `issue_date_raw` (YmdHis) для корректной сортировки.

3. **Выгрузка в XLSX** — кнопка «Выгрузить в XLSX». Используется SheetJS (xlsx) с CDN. Экспортируются только видимые строки (с учётом фильтра). Имя файла: `orders_YYYY-MM-DD.xlsx`.

### Изменённые файлы

- `data.php` — toolbar с фильтром, сортировкой, кнопкой экспорта; скрипты фильтрации и экспорта; подключение SheetJS.

---

## 2026-03-13 — Анализ проекта и исправление ошибок парсера «Мой агент»

**Запрос пользователя:** Проанализировать проект и найти ошибки, особенно в коде парсера «Мой агент».

### Найденные и исправленные ошибки

1. **analyzeOrderType() — неверный original_prod_id при возврате**  
   При нескольких документах TKT в заказе в качестве `original_prod_id` брался первый TKT, а не тот, чей номер билета совпадает с REF. В заказах с несколькими билетами это могло приводить к привязке возврата не к тому билету.  
   **Исправление:** поиск TKT-документа по совпадению `tkt_number` с REF-документом; при отсутствии совпадения — fallback на `refund_prod_id`.

2. **Неиспользуемая переменная reservationsMap**  
   `buildReservationsMap($xml)` вызывался и результат сохранялся в `$reservationsMap`, но не использовался; первая бронь бралась через повторный обход XML в `getMainReservation($xml)`.  
   **Исправление:** введён `getMainReservationFromMap($reservationsMap)`, в `parse()` используется он; двойной обход XML по бронированию убран.

3. **Расхождение тестов с текущей логикой EMD**  
   В коде и CURRENT_STAGE заложено: EMD — отдельные продукты в `PRODUCTS[]`. В тестах ожидалось: 1 продукт с CONJ_COUNT=2 (авиа+EMD в одном), 5 продуктов для 125359005865 и т.д.  
   **Исправление:** обновлены ожидания в test.php под модель «EMD как отдельные продукты» (125358832021: 2 продукта, 125358832769: 2, 125359005865: 10 продуктов, all_travellers/all_tickets с учётом дублей и пустых номеров EMD).

### Результат

- Все 132 теста проходят (6 фикстур).
- Логика возврата корректно привязывает REF к исходному билету по номеру.
- Нет лишнего обхода XML при получении основной брони.

### Изменённые файлы

- `parsers/MoyAgentParser.php` — analyzeOrderType(), getMainReservationFromMap(), использование reservationsMap.
- `test.php` — ожидания для 125358832021, 125358832769, 125359005865.

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