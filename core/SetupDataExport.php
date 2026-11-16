<?php
/**
 * ============================================================
 * ВЫГРУЗКА СЕРВЕРНЫХ ДАННЫХ ДЛЯ ПК (ZIP)
 * ============================================================
 * Собирает архив с данными вне git: справочники, settings,
 * json/json_api, input (часть или всё), при full — ещё logs.
 *
 * Режимы:
 * - data — refs, settings, json, json_api, последние 100 файлов input
 * - full — то же + весь input + logs
 * ============================================================
 */

require_once __DIR__ . '/Utils.php';

class SetupDataExport
{
    /** Сколько свежих файлов input кладём в режим data */
    const RECENT_INPUT_LIMIT = 100;

    /** @var string Корень проекта */
    private $baseDir;

    /**
     * @param string $baseDir
     */
    public function __construct($baseDir)
    {
        $this->baseDir = rtrim($baseDir, '/\\');
    }

    /**
     * Собирает ZIP.
     *
     * @param string $mode data|full
     * @return array path, filename, file_count, bytes, mode
     * @throws Exception
     */
    public function build($mode)
    {
        $mode = ($mode === 'full') ? 'full' : 'data';

        if (!class_exists('ZipArchive')) {
            throw new Exception('Для выгрузки нужен PHP-модуль ext-zip');
        }

        Utils::ensureDirectory($this->baseDir . DIRECTORY_SEPARATOR . 'temp');

        $prefix = ($mode === 'full') ? 'parser_full_' : 'parser_data_';
        $filename = $prefix . date('Ymd_His') . '.zip';
        $zipPath = $this->baseDir . DIRECTORY_SEPARATOR . 'temp' . DIRECTORY_SEPARATOR . $filename;

        if (file_exists($zipPath)) {
            @unlink($zipPath);
        }

        $zip = new ZipArchive();
        $opened = $zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        if ($opened !== true) {
            throw new Exception('Не удалось создать ZIP (код ' . $opened . ')');
        }

        $added = 0;
        $files = $this->collectFiles($mode);
        foreach ($files as $relative) {
            $absolute = $this->baseDir . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
            if (!is_file($absolute) || !is_readable($absolute)) {
                continue;
            }
            $zipName = str_replace('\\', '/', $relative);
            if ($zip->addFile($absolute, $zipName)) {
                $added++;
            }
        }

        $zip->close();

        if ($added === 0 || !file_exists($zipPath)) {
            if (file_exists($zipPath)) {
                @unlink($zipPath);
            }
            throw new Exception('Нечего упаковать: на сервере нет файлов для выгрузки');
        }

        return array(
            'path'       => $zipPath,
            'filename'   => $filename,
            'file_count' => $added,
            'bytes'      => (int)filesize($zipPath),
            'mode'       => $mode
        );
    }

    /**
     * Список относительных путей для архива.
     *
     * @param string $mode
     * @return array
     */
    private function collectFiles($mode)
    {
        $list = array();

        $list = array_merge($list, $this->filesInDir('references', array('json'), false));

        if (is_file($this->baseDir . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'settings.json')) {
            $list[] = 'config/settings.json';
        }
        if (is_file($this->baseDir . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'sftp_last_run.txt')) {
            $list[] = 'config/sftp_last_run.txt';
        }

        $list = array_merge($list, $this->filesInDir('json', array('json'), true));
        $list = array_merge($list, $this->filesInDir('json_api', array('json'), true));

        if ($mode === 'full') {
            $list = array_merge($list, $this->filesInDir('input', null, true));
            $list = array_merge($list, $this->filesInDir('logs', null, true));
        } else {
            $list = array_merge($list, $this->collectRecentInput(self::RECENT_INPUT_LIMIT));
        }

        $unique = array();
        foreach ($list as $rel) {
            $rel = str_replace('\\', '/', $rel);
            $base = basename($rel);
            if ($base === '.gitkeep' || $base === '') {
                continue;
            }
            if (!$this->isAllowedRelative($rel)) {
                continue;
            }
            $unique[$rel] = true;
        }

        return array_keys($unique);
    }

    /**
     * Последние N файлов под input/ по mtime (новые первые).
     *
     * @param int $limit
     * @return array
     */
    private function collectRecentInput($limit)
    {
        $all = $this->filesInDir('input', null, true);
        $withTime = array();
        foreach ($all as $rel) {
            $abs = $this->baseDir . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $rel);
            if (!is_file($abs)) {
                continue;
            }
            $withTime[] = array('rel' => $rel, 'mtime' => (int)@filemtime($abs));
        }

        usort($withTime, function ($a, $b) {
            if ($a['mtime'] === $b['mtime']) {
                return 0;
            }
            return ($a['mtime'] > $b['mtime']) ? -1 : 1;
        });

        $result = array();
        $n = 0;
        foreach ($withTime as $row) {
            $result[] = $row['rel'];
            $n++;
            if ($n >= $limit) {
                break;
            }
        }
        return $result;
    }

    /**
     * @param string     $subdir
     * @param array|null $exts
     * @param bool       $recursive
     * @return array
     */
    private function filesInDir($subdir, $exts, $recursive)
    {
        $root = $this->baseDir . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $subdir);
        if (!is_dir($root)) {
            return array();
        }

        $out = array();
        if ($recursive) {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
            );
            foreach ($iterator as $fileInfo) {
                if (!$fileInfo->isFile()) {
                    continue;
                }
                $abs = $fileInfo->getPathname();
                $rel = $this->toRelative($abs);
                if ($rel === null) {
                    continue;
                }
                if ($exts !== null) {
                    $ext = strtolower(pathinfo($abs, PATHINFO_EXTENSION));
                    if (!in_array($ext, $exts, true)) {
                        continue;
                    }
                }
                $out[] = $rel;
            }
        } else {
            $names = scandir($root);
            if ($names === false) {
                return array();
            }
            foreach ($names as $name) {
                if ($name === '.' || $name === '..') {
                    continue;
                }
                $abs = $root . DIRECTORY_SEPARATOR . $name;
                if (!is_file($abs)) {
                    continue;
                }
                if ($exts !== null) {
                    $ext = strtolower(pathinfo($abs, PATHINFO_EXTENSION));
                    if (!in_array($ext, $exts, true)) {
                        continue;
                    }
                }
                $out[] = $subdir . '/' . $name;
            }
        }

        return $out;
    }

    /**
     * @param string $absolute
     * @return string|null
     */
    private function toRelative($absolute)
    {
        $base = realpath($this->baseDir);
        $real = realpath($absolute);
        if ($base === false || $real === false) {
            return null;
        }
        $baseNorm = str_replace('\\', '/', $base);
        $realNorm = str_replace('\\', '/', $real);
        if (strpos($realNorm, $baseNorm . '/') !== 0 && $realNorm !== $baseNorm) {
            return null;
        }
        $rel = substr($realNorm, strlen($baseNorm) + 1);
        return ($rel === false || $rel === '') ? null : $rel;
    }

    /**
     * @param string $rel
     * @return bool
     */
    private function isAllowedRelative($rel)
    {
        $rel = ltrim(str_replace('\\', '/', $rel), '/');
        if ($rel === '' || strpos($rel, '..') !== false) {
            return false;
        }
        $allowed = array(
            'references/',
            'config/',
            'json/',
            'json_api/',
            'input/',
            'logs/'
        );
        foreach ($allowed as $prefix) {
            if (strpos($rel, $prefix) === 0) {
                return true;
            }
        }
        return false;
    }
}
