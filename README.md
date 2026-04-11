# XML Parser v5

Система обработки XML-файлов от поставщиков туристических услуг 
с преобразованием в JSON (формат ORDER/RSTLS) и отправкой в API 1С.

## Требования
- PHP 7.0+ (ext-simplexml, ext-curl, ext-json)

## Установка
1. Склонировать репозиторий
2. Настроить `config/settings.json`
3. Создать папки поставщиков в `input/`

## Запуск
php -S localhost:8080        # веб-интерфейс
php process.php              # обработка из CLI

## Структура
- `docs/` — документация и AI-контекст (CURRENT_STAGE, structure, changelog, промпт)
- `core/` — ядро системы
- `parsers/` — парсеры поставщиков (plug-and-play)
- `input/` — входные XML
- `json/` — выходные JSON

## Добавление нового поставщика
1. Создать `parsers/НовыйParser.php` (implements ParserInterface)
2. Создать `input/имя_папки/`
3. Готово — парсер обнаружится автоматически

## Документация
- Подробная документация: [docs/CURRENT_STAGE.md](docs/CURRENT_STAGE.md)
- Структура проекта: [docs/structure.md](docs/structure.md)