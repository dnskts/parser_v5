# Структура проекта XML Parser v5

## Директории
parser_v5/
├── config/          — конфигурация приложения
├── core/            — ядро системы (6 файлов)
├── parsers/         — реализации парсеров (plug-and-play)
├── input/           — входные XML-файлы
│   ├── moyagent/    — файлы «Мой агент»
│   │   ├── Processed/   — обработанные
│   │   └── Error/       — с ошибками
│   └── demo_hotel/  — демо-файлы отелей
│       ├── Processed/
│       └── Error/
├── json/            — выходные JSON-файлы
├── logs/            — логи
├── tests/           — тестовые данные
│   └── fixtures/    — XML-фикстуры
├── assets/          — фронтенд
└── .cursor/skills/  — скиллы AI-ассистента



## Файлы

### Config
| Файл | Описание |
|------|----------|
| `config/settings.json` | Настройки: interval, last_run, api.{enabled, url, login, password, timeout} |

### Core
| Файл | Описание |
|------|----------|
| `core/ParserInterface.php` | Контракт парсера: getSupplierFolder(), getSupplierName(), parse() |
| `core/ParserManager.php` | Auto-discovery парсеров через рефлексию |
| `core/Processor.php` | Оркестратор: glob→parse→saveJson→send→move |
| `core/Logger.php` | Логирование: INFO/WARNING/ERROR/SUCCESS, ротация 5МБ |
| `core/ApiSender.php` | HTTP POST в 1С, Basic Auth, JSON Lines лог |
| `core/Utils.php` | Утилиты: generateUUID() (v4) |

### Parsers
| Файл | Описание |
|------|----------|
| `parsers/MoyAgentParser.php` | «Мой агент» авиа V5: TKT/REF/RFND/CANX, конъюнкции |
| `parsers/DemoHotelParser.php` | Демо-парсер отелей (шаблон) |

### Web UI
| Файл | Описание |
|------|----------|
| `index.php` | Панель управления (логи, обработка, настройки) |
| `data.php` | Таблица заказов (49 колонок, серверный рендеринг) |
| `api_logs.php` | Логи API (HTML + AJAX к себе) |
| `api.php` | AJAX API (logs/run/settings/clear_logs/resend) |
| `process.php` | Точка входа pipeline (CLI + модуль) |
| `test.php` | Автотесты парсеров (Web + CLI) |

### Frontend
| Файл | Описание |
|------|----------|
| `assets/app.js` | JS для index.php (372 строки) |
| `assets/style.css` | Общие стили (BEM) |

### Tests
| Файл | Описание |
|------|----------|
| `tests/fixtures/125358843227.xml` | Продажа, 1 билет, SVO→KZN→SVO (22 assertions) |
| `tests/fixtures/125358829987.xml` | Продажа, 3 билета, 3 пассажира (20 assertions) |
| `tests/fixtures/125358832021.xml` | Продажа + EMD конъюнкция, EUR→RUB (20 assertions) |
| `tests/fixtures/125358832769.xml` | Возврат REF, penalty 3500 (21 assertions) |
| `tests/fixtures/125359005865.xml` | 5 билетов + конъюнкции, SVO→AUH→SVO (26 assertions) |

## Зависимости

- PHP 7.0+ (совместим с 8.x)
- `ext-simplexml` (встроенное)
- `ext-curl` (**обязательно** — Fatal Error без него)
- `ext-json` (встроенное)
- `ext-mbstring` (рекомендуется)
- Без composer, без фреймворков, без БД