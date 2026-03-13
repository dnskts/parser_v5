Ты работаешь с проектом XML Parser v5 — PHP-система обработки XML от поставщиков туристических услуг (авиабилеты, отели), преобразование в JSON-формат ORDER (спецификация RSTLS), отправка в API 1С:Предприятие.

Стек
PHP 7.0+ (совместим с 8.x), без фреймворков/composer/БД. Vanilla JS (ES5), CSS3 (BEM). Расширения: simplexml, curl (+ SFTP/libssh2), json, mbstring. Код на PHP 7.0 (array(), без type hints). Комментарии на русском. JSON с JSON_UNESCAPED_UNICODE.

Структура
config/settings.json — interval, last_run, api{url,login,password,timeout,enabled}, sftp{enabled,host,port,login,password,remote_path,local_path,interval}
config/sftp_last_run.txt — timestamp последней SFTP-синхронизации
core/ — ApiSender(POST 1С), Logger(app.log, ротация 5МБ), ParserInterface(контракт), ParserManager(auto-discovery), Processor(glob→parse→save→send→move), SftpSync(cURL+SFTP), Utils(UUID v4)
parsers/ — MoyAgentParser(авиа TKT/REF/RFND/CANX, конъюнкции+скрытые), MoyAgentConstants(справочник), DemoHotelParser(шаблон)
input/{supplier}/ — XML + Processed/ + Error/
json/ — результаты JSON
logs/ — app.log + api_send.log(JSON Lines) + sftp_sync.log
index.php — панель управления (app.js, AJAX к api.php, автообработка в localStorage)
data.php — таблица заказов (60 колонок, серверный рендеринг, resend 🔄, фильтр, сортировка, XLSX)
api_logs.php — логи API (HTML + AJAX к себе)
api.php — AJAX API (logs/run/settings/clear_logs/clear_json/resend)
process.php — pipeline: runSftpSync + Processor (CLI cron + require из api.php)
sftp_sync.php — SFTP standalone (CLI + браузер, для отдельного запуска)
test.php — автотесты (6 фикстур, 132 assertions)

Pipeline (единый)
process.php и кнопка «Запустить»: runSftpSync() → Processor.run(). SFTP встроен в pipeline — при каждом запуске сначала загрузка XML с SFTP в input/moyagent/, затем обработка. sftp_sync.php — только для standalone.

Формат ORDER
Корень: UID(v4), INVOICE_NUMBER, INVOICE_DATA(YYYYMMDDHHmmss), CLIENT, PRODUCTS[]. Служебные SOURCE_FILE, PARSED_AT удаляются перед 1С. Product: UID, PRODUCT_TYPE{NAME,CODE}, NUMBER, STATUS(продажа/возврат/обмен), TRAVELLER, COUPONS[], TAXES[](первый CODE=""=тариф), PAYMENTS[], COMMISSIONS[], REFUND(возвраты). PAYMENTS из xml->payments: tkt_fop ПК/БИЛЕТ→TYPE=TICKET, RELATED_TICKET_NUMBER. EXCH в analyzeOrderType. Типы: 000000001=Авиабилет, 000000003=Отельный билет.

Критические правила
- В начале: читай CURRENT_STAGE.md и structure.md. После изменений: обновляй CURRENT_STAGE.md, CHANGELOG_AI.md, structure.md.
- Новый парсер: parsers/XxxParser.php implements ParserInterface + input/folder/
- UUID только Utils::generateUUID()
- Processor привязан к glob(*.xml)
- Нет retry 1С; переотправка через 🔄 в data.php
- app.js только для index.php; data.php/api_logs.php — встроенные скрипты
- SFTP встроен в runProcessing(); sftp_sync.php — standalone
- settings.json: секции api и sftp, модифицируется автоматически
- MoyAgent: SUPPLIER — getSupplierName(); AGENT — air_ticket_doc[@issuingAgent]; BOOKING_AGENT — reservation[@bookingAgent]; RESERVATION_NUMBER — reservation[@rloc] через getMainReservation()
- Конъюнкции: emd_ticket_doc[@main_prod_id] + скрытые (fare=0, seg_count=0, tkt_number ±1..9)
- data.php formatAgent(): CODE===NAME → одно значение. Даты — все сегменты через запятую
- EMD без номера и суммой 0 пропускаются
- Пассажир: PASSENGER_BIRTH_DATE, PASSENGER_GENDER, PASSENGER_DOC_TYPE, PASSENGER_DOC_NUMBER
- PHP: строковые ключи "0"→0; при сравнении — (string)
- ApiSender.isAvailable(): HEAD 2с, кеш на цикл
- Фикстуры: имена по ord_id (125358843227, 125359052102 и т.д.)
- input/, json/ в .gitignore
- SFTP-путь ~/remote_path/. Перемещение: SFTP→Processed (SftpSync) + локально→Processed/Error (Processor)
- ⚠️ Сетевой доступ к SFTP 10.4.175.11:22 пока не открыт