---
name: update-structure
description: Keeps structure.md in sync with the project. Use when creating, renaming, or deleting files, adding or removing directories, or changing dependencies (composer.json, package.json, requirements.txt, etc.). Also use when the user asks to update or review the project structure.
---

# Update structure.md

## When to trigger

Apply this skill **every time** you:

- Create, rename, or delete a file or directory.
- Add, remove, or update a dependency (library, package, external service).
- Change the role or responsibility of an existing module.

**После внесения изменений в проект** (по правилам проекта): обновлять `CURRENT_STAGE.md`, `CHANGELOG_AI.md` и при необходимости `structure.md`. Файл `SisPrompt.md` (системный промпт для веб-нейросетей) обновлять, если изменились ключевые правила или структура (например, число фикстур, новые эндпоинты API).

**После изменения** `SisPrompt.md`, `CURRENT_STAGE.md`, `CHANGELOG_AI.md` или `structure.md`: выполнить в корне проекта `php scripts/sync-docs-to-txt.php`, чтобы обновить одноимённые файлы `*.txt` (UTF-8 копии содержимого `.md`).

## Where to write

Update the file `structure.md` in the **project root**.
If `structure.md` does not exist yet, create it using the template below.

## Format

Use **sections-style**: group entries by logical module / feature area.
Each section has a heading, a brief purpose description, and a list of files with one-line comments.

### Template

```markdown
# Project Structure
<!-- AI-context: This file describes the project layout and dependencies.
     Updated automatically when files or dependencies change. -->

## <Section Name>
<!-- Purpose: <what this group of files does> -->

- `path/to/file.ext` — <what this file is responsible for>

## Dependencies
<!-- External libraries and services the project relies on -->

- `<package-name>` (<version-or-source>) — <why it is needed>
```

## Rules

1. **Comments must be bilingual-friendly** — write in the project's primary language, but keep wording simple and unambiguous so both a human and an AI can understand the intent.
2. **One line per file** — format: `- \`relative/path\` — <short description>`.
3. **Keep sections sorted** — alphabetically by section name; files inside a section sorted alphabetically by path.
4. **Dependencies section** is always last. List every external dependency with its purpose.
5. **Remove stale entries** — if a file or dependency no longer exists, delete its line.
6. **HTML comments for AI context** — add `<!-- AI-context: ... -->` hints under section headings when the relationship between files is non-obvious.
7. Do **not** list generated/temporary files (logs, caches, build artifacts).

## Example

```markdown
# Project Structure
<!-- AI-context: PHP-based XML parser that converts booking data into JSON
     and sends it to an external API. -->

## Config
<!-- Purpose: application settings and environment configuration -->

- `config/settings.json` — runtime settings (API endpoints, paths, flags)

## Core
<!-- Purpose: framework-level classes shared across all parsers -->

- `core/ApiSender.php` — sends prepared JSON payloads to the external API
- `core/Logger.php` — PSR-compatible logger, writes to logs/app.log
- `core/ParserInterface.php` — contract that every parser must implement
- `core/ParserManager.php` — discovers and dispatches parsers by XML type
- `core/Processor.php` — orchestrates the full pipeline: read → parse → send

## Parsers
<!-- Purpose: concrete parser implementations, one per data source -->

- `parsers/DemoHotelParser.php` — parses demo hotel booking XMLs
- `parsers/MoyAgentParser.php` — parses MoyAgent airline ticket XMLs

## Web UI
<!-- Purpose: browser interface for uploading files and viewing results -->

- `index.php` — main page (upload form + processing trigger)
- `process.php` — handles form submission, runs the pipeline
- `data.php` — displays parsed JSON results
- `api.php` — REST endpoint for external integrations
- `api_logs.php` — shows API call history
- `assets/app.js` — frontend logic (AJAX, UI updates)
- `assets/style.css` — stylesheet

## Dependencies
<!-- External libraries and services the project relies on -->

- (list dependencies here when they are added)
```

## Checklist (verify before finishing)

- [ ] If any of `SisPrompt.md`, `CURRENT_STAGE.md`, `CHANGELOG_AI.md`, `structure.md` were edited, `php scripts/sync-docs-to-txt.php` was run and `*.txt` mirrors are current
- [ ] Every new / renamed / deleted file is reflected in `structure.md`
- [ ] Every added / removed dependency is reflected in the Dependencies section
- [ ] No stale entries remain
- [ ] Comments are clear to both a human reader and an AI agent
