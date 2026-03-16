# Структура проекта XML Parser v5

Документ описывает все директории и файлы проекта. Используется для ориентации в коде и для контекста AI-ассистентов.

---

## Директории

| Путь | Назначение |
|------|------------|
| `config/` | Конфигурация: настройки приложения, интервал обработки, API 1С, SFTP, timestamp последней SFTP-синхронизации |
| `core/` | Ядро системы: логгер, менеджер парсеров, обработчик (Processor), отправка в API, SFTP-клиент, утилиты, интерфейс парсера |
| `parsers/` | Реализации парсеров (plug-and-play). Каждый парсер — один PHP-файл, реализующий ParserInterface |
| `parsers/constants/` | Справочники констант и маппингов для парсеров (например, типы пассажиров, пол, классы обслуживания) |
| `input/` | Входные XML-файлы. Внутри — подпапки по поставщителям (moyagent, demo_hotel и т.д.). В каждой поставщике: корень (новые файлы), Processed/, Error/ |
| `json/` | Выходные JSON-файлы после обработки. Имя: `{папка_поставщика}_{имя_xml}_{Ymd_His}.json` |
| `logs/` | Логи: app.log (основной), api_send.log (отправки в 1С, JSON Lines), sftp_sync.log (SFTP) |
| `tests/` | Тесты. В tests/fixtures/ — XML-фикстуры для автотестов парсеров |
| `assets/` | Фронтенд: общие стили (style.css), скрипт панели (app.js), библиотека для выгрузки XLSX |
| `.cursor/` | Настройки и скиллы Cursor IDE (в т.ч. update-structure, context-keeper) |

---

## Файлы конфигурации (config/)

| Файл | Описание |
|------|----------|
| `config/settings.json` | Главный файл настроек. Содержит: `interval` (секунды между запусками), `last_run` (timestamp последней обработки), секцию `api` (enabled, url, login, password, timeout), секцию `sftp` (enabled, host, port, login, password, remote_path, local_path, interval), опционально `data_column_order` (порядок колонок на data.php). Файл автоматически обновляется при каждом запуске обработки и при сохранении настроек из веб-интерфейса. |
| `config/sftp_last_run.txt` | Одна строка — timestamp последней успешной SFTP-синхронизации. Обновляется после каждого запуска SftpSync (из process.php или sftp_sync.php). Используется для проверки интервала между синхронизациями. |

---

## Ядро системы (core/)

| Файл | Описание |
|------|----------|
| `core/ParserInterface.php` | Интерфейс (контракт) парсера. Описывает три метода: `getSupplierFolder()` — имя папки в input/, `getSupplierName()` — человекочитаемое название поставщика, `parse($xmlFilePath)` — разбор XML и возврат массива ORDER. Любой новый парсер должен реализовать этот интерфейс. |
| `core/ParserManager.php` | Менеджер парсеров с авто-обнаружением. При создании сканирует папку parsers/, подключает все *.php, через рефлексию находит классы, реализующие ParserInterface, создаёт экземпляры и строит карту «имя папки → парсер». Файлы в parsers/constants/ не сканируются (подключаются из парсеров). Методы: getParser($folder), getRegisteredFolders(), getAllParsers(). |
| `core/Processor.php` | Оркестратор обработки. Читает настройки из config/settings.json, проверяет интервал (isIntervalPassed), для каждой папки поставщика: glob(*.xml) → вызов парсера → saveJson → перемещение XML в Processed/ или Error/ → при доступном API отправка через ApiSender. Создаёт подпапки Processed и Error при необходимости. Обновляет last_run после цикла. |
| `core/Logger.php` | Логгер в файл logs/app.log. Уровни: info, warning, error, success. Формат строки: `[Y-m-d H:i:s] [LEVEL] message`. Ротация: при размере файла >5 МБ переименование в app.log.old. Методы: info(), warning(), error(), success(), getLastLines($lines), clear(). |
| `core/ApiSender.php` | Отправка заказов в API 1С по HTTP. Конструктор принимает конфиг api и путь к logs/api_send.log. Методы: isAvailable() — проверка доступности (HEAD, таймаут 2–3 с), send($orderData, $jsonFileName, $sourceXml) — удаление SOURCE_FILE и PARSED_AT, POST JSON, Basic Auth, запись в лог (JSON Lines), getLogEntries($limit), clearLog(). При ошибках возвращает понятные сообщения (сеть, 401, 404, 500 и т.д.). |
| `core/SftpSync.php` | SFTP-клиент на cURL (libssh2). Конструктор: конфиг (host, port, login, password, remote_path, local_path), путь к sftp_sync.log. Методы: sync() — листинг *.xml, скачивание в local_path, перемещение на SFTP в Processed/; testConnection(), listRemoteXmlFiles(), downloadFile(), moveToProcessed(). Путь на SFTP: ~/remote_path/. Логирование и ротация лога (>5 МБ → .old). |
| `core/Utils.php` | Утилиты. Единственный публичный метод: generateUUID() — генерирует UUID версии 4 (RFC 4122). Используется парсерами для полей UID заказа и продуктов. Требование проекта: UUID создавать только через этот класс. |

---

## Парсеры (parsers/)

| Файл | Описание |
|------|----------|
| `parsers/MoyAgentParser.php` | Парсер поставщика «Мой агент» (авиабилеты + EMD). Папка: input/moyagent/. Формат XML: корень order_snapshot. Операции: TKT (продажа), REF/RFND/CANX (возврат/аннуляция), EXCH (обмен). Поддерживает конъюнкции (emd_ticket_doc main_prod_id и скрытые конъюнкции по признакам fare=0, 0 сегментов, tkt_number ±1..9). EMD — отдельные продукты в PRODUCTS[]. Использует MoyAgentConstants для маппинга типов пассажиров, пола, документов, классов, GDS, типа перелёта и т.д. Поля PAYMENTS строятся из xml->payments (зачёт по билету — TYPE TICKET, RELATED_TICKET_NUMBER). |
| `parsers/constants/MoyAgentConstants.php` | Справочник констант для MoyAgentParser. Статические методы возвращают массивы маппингов: getPassengerTypes(), getGenderMap(), getDocTypes(), getCabinClassMap(), getTypeIdMap(), getFlightTypeMap(), getGdsMap(), getSegmentStatusMap(), getTicketCreditFopCodes() и др. Используются для преобразования кодов из XML в читаемые названия и коды RSTLS. |
| `parsers/DemoHotelParser.php` | Демо-парсер отелей (шаблон для новых парсеров). Папка: input/demo_hotel/. Формат XML: корень hotel_order, секции order и booking. Возвращает ORDER с PRODUCT_TYPE код 000000003 (Отельный билет). Содержит пошаговые комментарии, как добавить нового поставщика. |

---

## Веб-интерфейс и точки входа

| Файл | Описание |
|------|----------|
| `index.php` | Панель управления. HTML-страница с блоком настроек (интервал, кнопки «Запустить», «Вкл. автообработку», «Очистить логи»), блоком логов и навигацией. Подключает assets/style.css и assets/app.js. Логи и настройки загружаются через AJAX (api.php). |
| `data.php` | Страница обработанных заказов. Читает до 100 последних JSON из json/, разворачивает каждый заказ по продуктам (PRODUCTS), формирует строки таблицы (60 колонок). Содержит PHP-функции formatRstlsDate() и formatAgent(). Сортировка по дате выписки или дате загрузки (GET sort, dir), фильтр по всем колонкам (JS), кнопка «Выгрузить в XLSX» (SheetJS), кнопка «Очистить таблицу» (clear_json), кнопка 🔄 для повторной отправки (resend). Подключает style.css и встроенный JS. |
| `api_logs.php` | Страница логов отправки в API 1С. Работает в двух режимах: при запросе с ?action=get_logs/get_settings/clear_logs отдаёт JSON; без параметров — HTML-страница с таблицей записей из logs/api_send.log. Встроенный JS: загрузка логов и настроек, кнопки «Обновить» и «Очистить логи», автообновление логов каждые 10 с. Подключает style.css. |
| `api.php` | AJAX API для веб-интерфейса. Один вход: GET-параметр action. Действия: logs (GET) — последние 200 строк app.log; run (POST) — runProcessing(true), т.е. SFTP + обработка принудительно; settings (GET/POST) — чтение/запись config/settings.json (interval, data_column_order); clear_logs (POST); clear_json (POST) — удаление всех *.json из json/; resend (POST) — тело JSON с полем file (имя .json), чтение файла из json/, отправка через ApiSender. Все ответы в JSON, заголовки CORS. Подключает process.php (для runProcessing) и Logger. |
| `process.php` | Точка входа конвейера обработки. Определяет BASE_DIR при первом подключении. Содержит функции: runSftpSync($force) — чтение sftp из settings.json, проверка интервала по sftp_last_run.txt, вызов SftpSync->sync(), обновление sftp_last_run.txt; runProcessing($force) — runSftpSync + создание Logger, ParserManager, Processor, processor->run($force), дополнение результата полями sftp_downloaded, sftp_errors. При запуске из CLI (php process.php) вызывает runProcessing(false) и выводит результат в консоль. Из веб-интерфейса подключается из api.php при action=run. |
| `sftp_sync.php` | Автономная точка входа только для SFTP-синхронизации. Читает config/settings.json, секцию sftp; при enabled и (force или истёк интервал) создаёт SftpSync и вызывает sync(), обновляет sftp_last_run.txt. Запуск: CLI (php sftp_sync.php [--force]) или браузер (sftp_sync.php?force=1). Вывод: в CLI — текст, в браузере — JSON с полями status, downloaded, errors, files, timestamp. Вспомогательная функция logAndExit() для вывода ошибки в лог и завершения с кодом 1. |
| `test.php` | Автотесты парсеров (MoyAgentParser). Подключает ParserInterface, Utils, MoyAgentParser. Массив expectations: для каждого XML из tests/fixtures/ заданы ожидаемые значения (статус, количество продуктов, номер заказа, пассажир, перевозчик, купоны, комиссии, возвраты и т.д.). Скрипт прогоняет фикстуры через parser->parse(), сравнивает результат с expectations, считает passed/failed. Режимы: веб (HTML с блоками PASS/FAIL и сводкой) и CLI (php test.php). Не изменяет файлы и не отправляет данные в API. |

---

## Фронтенд (assets/)

| Файл | Описание |
|------|----------|
| `assets/style.css` | Общие стили проекта (BEM-нотация): шапка, навигация, панель, кнопки, логи, таблица данных, футер, компактный layout. Используется на index.php, data.php, api_logs.php, test.php. |
| `assets/app.js` | Логика только для index.php. Загрузка настроек и логов (api.php?action=settings, action=logs), ручной запуск обработки (action=run, POST), автообработка по таймеру с сохранением состояния в localStorage (parser_auto_enabled), очистка логов (action=clear_logs). Использует AbortController для прерывания fetch при уходе со страницы. Форматирование даты и подсветка строк лога по уровню. |
| `assets/xlsx.full.min.js` | Минифицированная библиотека SheetJS для экспорта таблицы в XLSX. Подключается на data.php как запасной вариант (fallback), если CDN недоступен. |

---

## Тесты (tests/)

| Файл | Описание |
|------|----------|
| `tests/fixtures/125358843227.xml` | Фикстура: продажа, 1 билет, 2 сегмента SVO→KZN→SVO. Проверки: базовые поля, комиссии CLIENT/VENDOR, даты купонов. |
| `tests/fixtures/125358829987.xml` | Фикстура: продажа, 3 билета, 3 пассажира. Проверки: all_travellers, all_tickets. |
| `tests/fixtures/125358832021.xml` | Фикстура: продажа с EMD (отдельный продукт), MXP→CDG, EUR→RUB. |
| `tests/fixtures/125358832769.xml` | Фикстура: возврат REF, штраф 3500. Проверки: REFUND, PENALTY, refund_amount. |
| `tests/fixtures/125359005865.xml` | Фикстура: 5 авиа + 5 EMD, SVO→AUH→SVO. Проверки: BOOKING_AGENT, AGENT, all_travellers, all_tickets. |
| `tests/fixtures/125358954718.xml` | Фикстура: возврат + 2 EMD с номерами и ненулевой суммой, penalty 5060. |
| `tests/fixtures/125359052102.xml` | Фикстура: продажа со скрытой конъюнкцией (без emd_ticket_doc), VKO→TIV. Проверки: conj_count=2. |

---

## Логи (logs/)

| Файл | Формат | Описание |
|------|--------|----------|
| `logs/app.log` | Текстовый, строки вида `[Y-m-d H:i:s] [LEVEL] message` | Основной лог: запуски обработки, результаты парсинга, ошибки, предупреждения. Ротация при размере >5 МБ. |
| `logs/api_send.log` | JSON Lines (каждая строка — JSON-объект) | Каждая попытка отправки в API 1С: timestamp, status, json_file, source_xml, http_code, response, message. |
| `logs/sftp_sync.log` | Текстовый, тот же формат что app.log | Лог SFTP-синхронизации: подключение, список файлов, скачивание, перемещение. Ротация >5 МБ. |

---

## Документация и прочее (корень проекта)

| Файл | Описание |
|------|----------|
| `CURRENT_STAGE.md` | Текущее состояние проекта: описание, стек, структура, архитектура, формат ORDER, парсеры, API, SFTP, веб-интерфейс, известные проблемы, последние изменения. Обновляется после значимых правок (контекст для разработки и AI). |
| `CHANGELOG_AI.md` | История изменений, внесённых с участием AI: дата, запрос пользователя, что сделано, изменённые файлы, принятые решения. |
| `structure.md` | Этот файл — подробное описание структуры проекта и всех файлов. |
| `SisPrompt.md` | Системный промпт для веб-версии нейросетей: краткий контекст проекта, стек, структура, pipeline, формат ORDER, критические правила. Используется, чтобы нейросеть была в контексте проекта. |
| `nextstep.md` | План улучшений и оптимизации, следующие шаги с пояснениями, подробная инструкция по подключению новых поставщиков через API. |
| `README.md` | Краткое описание проекта для людей (если есть). |
| `migration.md` | Документ миграции (если есть). |
| `.gitignore` | Исключения для Git: обычно input/, json/, логи, конфиги с паролями, .cursor/debug*.log. |

---

## Зависимости

- **PHP:** 7.0+ (совместим с 8.x). Синтаксис без type hints, array() вместо [].
- **Расширения PHP:** ext-simplexml (встроенное), ext-curl (обязательно; с поддержкой SFTP/libssh2 для SftpSync), ext-json (встроенное), ext-mbstring (рекомендуется).
- **Внешние:** Без Composer, без фреймворков, без БД. Фронтенд: vanilla JS (ES5), fetch API, CSS3.
