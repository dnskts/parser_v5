<?php
/**
 * Копирует документы docs/*.md в одноимённые docs/*.txt (UTF-8, то же содержимое).
 * Запуск из корня проекта: php scripts/sync-docs-to-txt.php
 */

$root = dirname(__DIR__);
$docDir = $root . DIRECTORY_SEPARATOR . 'docs';
$pairs = array(
    'SisPrompt.md' => 'SisPrompt.txt',
    'CURRENT_STAGE.md' => 'CURRENT_STAGE.txt',
    'CHANGELOG_AI.md' => 'CHANGELOG_AI.txt',
    'structure.md' => 'structure.txt',
);

$updated = array();

foreach ($pairs as $srcName => $dstName) {
    $src = $docDir . DIRECTORY_SEPARATOR . $srcName;
    if (!is_readable($src)) {
        fwrite(STDERR, "Ошибка: не найден или недоступен файл: docs/{$srcName}\n");
        exit(1);
    }
    $content = file_get_contents($src);
    if ($content === false) {
        fwrite(STDERR, "Ошибка: не удалось прочитать: docs/{$srcName}\n");
        exit(1);
    }
    $dst = $docDir . DIRECTORY_SEPARATOR . $dstName;
    if (file_put_contents($dst, $content) === false) {
        fwrite(STDERR, "Ошибка: не удалось записать: docs/{$dstName}\n");
        exit(1);
    }
    $updated[] = 'docs/' . $dstName;
}

echo "Обновлены: " . implode(', ', $updated) . "\n";
exit(0);

