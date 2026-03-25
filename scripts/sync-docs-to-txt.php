<?php
/**
 * Копирует корневые документы .md в одноимённые .txt (UTF-8, то же содержимое).
 * Запуск из корня проекта: php scripts/sync-docs-to-txt.php
 */

$root = dirname(__DIR__);
$pairs = array(
    'SisPrompt.md' => 'SisPrompt.txt',
    'CURRENT_STAGE.md' => 'CURRENT_STAGE.txt',
    'CHANGELOG_AI.md' => 'CHANGELOG_AI.txt',
    'structure.md' => 'structure.txt',
);

$updated = array();

foreach ($pairs as $srcName => $dstName) {
    $src = $root . DIRECTORY_SEPARATOR . $srcName;
    if (!is_readable($src)) {
        fwrite(STDERR, "Ошибка: не найден или недоступен файл: {$srcName}\n");
        exit(1);
    }
    $content = file_get_contents($src);
    if ($content === false) {
        fwrite(STDERR, "Ошибка: не удалось прочитать: {$srcName}\n");
        exit(1);
    }
    $dst = $root . DIRECTORY_SEPARATOR . $dstName;
    if (file_put_contents($dst, $content) === false) {
        fwrite(STDERR, "Ошибка: не удалось записать: {$dstName}\n");
        exit(1);
    }
    $updated[] = $dstName;
}

echo "Обновлены: " . implode(', ', $updated) . "\n";
exit(0);
