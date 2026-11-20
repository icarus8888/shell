<?php
declare(strict_types=1);

/*
 * SoloFileManager — single-file PHP file manager (release build).
 * Layout: config → helpers → actions → HTML + inlined CSS/JS.
 * Built from the SoloFileManager development sources; do not edit by hand unless necessary.
 */

/** Product version (semver). Shown in UI / server info. */
const SOLOFILEMANAGER_VERSION = '1.3.1';

// Set max execution time to 1 day (86400 seconds)
@set_time_limit(86400);

ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');
error_reporting(E_ALL);

// =========================
// Config (edit as needed)
// =========================

// Initial/home directory when opening the file manager. Default: this script's directory.
$ROOT_DIR = str_replace('\\', '/', realpath(__DIR__) ?: __DIR__);

// Allow accessing all directories on the server (true = unrestricted, false = lock to $ROOT_DIR).
$ALLOW_ALL_DIRECTORIES = true;

// Rename gate: require renaming away from this filename on first run.
$DEFAULT_FILENAME = 'solofilemanager.php';

// Auth disabled (login feature removed).
$ENABLE_AUTH = false;

// Password hash for login. Prefer changing via the in-app "Change password" UI after setup.
// Default password is: admin (you will be asked to change it). Manual fallback:
//   password_hash('your-strong-password', PASSWORD_DEFAULT) → paste into $PASSWORD_HASH below.
$PASSWORD_HASH = '$2y$10$gfAs1ZRrZH6GElppZp3UV.Zt8N7R.KnGFJ0X02Ow6ZhMNtGihJvlK';

// Folder tree depth (avoid excessive recursion).
$MAX_TREE_DEPTH = 12;

// Items to exclude from listings (hide this script and common repo docs if present).
$EXCLUDE_NAMES = [
    '.', '..',
    'solofilemanager.php',
    'README.md', 'SECURITY.md', '.gitignore', 'LICENSE',
];

/**
 * Heavy file ops (compress, extract, copy, move, delete-stream, folder-size): OS shell vs PHP.
 *
 * - 'auto' (default): try OS commands when exec() is available, fall back to PHP on failure.
 * - 'php': PHP only (no exec-based shell for these actions).
 * - 'os': OS only when exec() is available; no PHP fallback. If exec() is disabled, these actions error.
 *
 * Aliases for 'os': 'shell'
 * Can also be changed from Configuration in the UI (password) when the script file is writable.
 */
$FM_FILE_OPS_MODE = 'auto';

/**
 * Below this estimated item count, progress UIs stay indeterminate (no n/total detail).
 * At or above: show detailed NDJSON progress only when work runs via PHP with granular callbacks; OS paths stay indeterminate.
 * Set to 0 to disable the "small job" band (only PHP vs OS rules apply).
 */
$FM_VERBOSE_PROGRESS_MIN_ITEMS = 800;

/** Hidden trash folder under root (mirror layout: ROOT/rel -> ROOT/.trash/rel). */
$FM_TRASH_BASENAME = '.trash';

/**
 * Terminal-here (standard): allowlisted shell commands in a folder.
 * Toggle from Terminal settings in the UI (password) when the script file is writable; trusted hosts only.
 */
$FM_ENABLE_TERMINAL_HERE = true;
/** Manual mode: typed commands through a strict safe parser. Also settable from Terminal settings. */
$FM_ENABLE_TERMINAL_MANUAL = true;
/**
 * Advanced terminal mode: raw user commands.
 * Also settable from Terminal settings — use only on trusted/private environments.
 */
$FM_ENABLE_TERMINAL_ADVANCED = true;

$isLinux = (strtoupper(substr(PHP_OS, 0, 3)) === 'LIN');
$isMacOS = (strtoupper(substr(PHP_OS, 0, 3)) === 'DAR');
$isWindows = (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN');
/** Linux or macOS: Unix-style shell paths for zip/tar/unzip. */
$isUnixLikeShell = $isLinux || $isMacOS;

$serverCapabilities = fmServerCapabilities();

// =========================
// Universal helpers & capability probe
// =========================

/**
 * Normalize a filesystem path to a consistent format.
 *
 * @param string $p The input path to normalize.
 * @return string The normalized path, with consistent slashes.
 */
function normPath(string $p): string {
    $p = str_replace('\\', '/', $p);
    return preg_replace('~/+~', '/', $p) ?: $p;
}

/**
 * Safe realpath
 * 
 * @param string $p
 * @return string
 */
function safeRealpath(string $p): string {
    $rp = realpath($p);
    return normPath($rp !== false ? $rp : $p);
}

/**
 * Resolve a directory path from client `in` parameter (supports both relative and absolute paths).
 *
 * @param string $dirIn
 * @param string $rootDir
 * @return string
 */
function fmResolveDir(string $dirIn, string $rootDir): string {
    $dirIn = str_replace('\\', '/', trim($dirIn));
    if ($dirIn === '') {
        return safeRealpath($rootDir);
    }
    if ($dirIn[0] === '/' || preg_match('#^[A-Za-z]:(/|$)#', $dirIn)) {
        if (preg_match('#^[A-Za-z]:$#', $dirIn)) {
            $dirIn .= '/';
        }
        return safeRealpath($dirIn);
    }
    return safeRealpath(rtrim(normPath($rootDir), '/') . '/' . trim($dirIn, '/'));
}

/**
 * Check if a path is allowed (inside root, or any valid path when $ALLOW_ALL_DIRECTORIES is enabled).
 * 
 * @param string $path
 * @param string $root
 * @return bool
 */
function pathInsideRoot(string $path, string $root): bool {
    global $ALLOW_ALL_DIRECTORIES;
    $path = safeRealpath($path);
    if ($path === '' || strpos($path, "\0") !== false) {
        return false;
    }
    if (!empty($ALLOW_ALL_DIRECTORIES)) {
        return true;
    }
    $root = safeRealpath($root);
    if ($root === '') {
        return false;
    }
    $path = rtrim($path, '/');
    $root = rtrim($root, '/');
    return $path === $root || strpos($path . '/', $root . '/') === 0;
}

/**
 * Normalize a client-provided relative path for uploads (nested folders via "a/b/c.txt").
 * Rejects "..", absolute paths, and invalid filename characters per segment.
 * 
 * @param string $rel
 * @return ?string
 */
function fmSanitizeUploadRelativePath(string $rel): ?string {
    $rel = str_replace('\\', '/', $rel);
    $rel = trim($rel, '/');
    if ($rel === '') {
        return null;
    }
    $parts = explode('/', $rel);
    $clean = [];
    foreach ($parts as $part) {
        if ($part === '' || $part === '.') {
            continue;
        }
        if ($part === '..') {
            return null;
        }
        if (preg_match('/[\\\\\/:*?"<>|]/', $part)) {
            return null;
        }
        $clean[] = $part;
    }
    if ($clean === []) {
        return null;
    }
    return implode('/', $clean);
}

/**
 * Parse chmod octal string (e.g. "755", "0755") to integer mode 0–0777.
 */
function fmParseChmodOctal(string $s): ?int {
    $s = trim($s);
    if ($s === '') {
        return null;
    }
    if (!preg_match('/^0?[0-7]{3}$/', $s)) {
        return null;
    }
    return octdec($s);
}

/**
 * Apply chmod to a single path (file or directory).
 */
function fmApplyChmod(string $path, int $mode, string $rootDir): bool {
    if (!pathInsideRoot($path, $rootDir) || !file_exists($path)) {
        return false;
    }
    return @chmod($path, $mode & 0777);
}

/**
 * Walk a directory tree and apply separate modes to folders and/or files.
 * The root $path itself is chmod'd with $folderMode when provided.
 */
function fmApplyChmodTree(
    string $path,
    ?int $folderMode,
    ?int $fileMode,
    bool $recFolders,
    bool $recFiles,
    string $rootDir
): bool {
    if (!pathInsideRoot($path, $rootDir) || !is_dir($path)) {
        return false;
    }
    if ($folderMode !== null) {
        if (!@chmod($path, $folderMode & 0777)) {
            return false;
        }
    }
    if (!$recFolders && !$recFiles) {
        return true;
    }
    try {
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );
    } catch (Exception $e) {
        return false;
    }
    foreach ($it as $f) {
        $p = $f->getPathname();
        if (!pathInsideRoot($p, $rootDir)) {
            return false;
        }
        if ($f->isDir()) {
            if ($recFolders && $folderMode !== null && !@chmod($p, $folderMode & 0777)) {
                return false;
            }
        } elseif ($f->isFile()) {
            if ($recFiles && $fileMode !== null && !@chmod($p, $fileMode & 0777)) {
                return false;
            }
        }
    }
    return true;
}

/**
 * Bulk rename in one directory using two-phase temp names. $pairs: [[fromBasename, toBasename], ...].
 */
function fmBulkRenamePairs(string $dir, string $rootDir, array $pairs): array {
    $dir = safeRealpath($dir);
    if (!pathInsideRoot($dir, $rootDir) || !is_dir($dir)) {
        return ['ok' => [], 'errors' => ['Invalid folder']];
    }

    $work = [];
    $seenFrom = [];
    foreach ($pairs as $p) {
        if (!isset($p[0], $p[1])) {
            continue;
        }
        $from = basename(str_replace('\\', '/', (string)$p[0]));
        $to = basename(str_replace('\\', '/', (string)$p[1]));
        if ($from === '' || $to === '') {
            return ['ok' => [], 'errors' => ['Empty name in batch']];
        }
        if ($from === $to) {
            continue;
        }
        if (preg_match('/[\\\\\/:*?"<>|]/', $from) || preg_match('/[\\\\\/:*?"<>|]/', $to)) {
            return ['ok' => [], 'errors' => ['Invalid characters in a name']];
        }
        if (isset($seenFrom[$from])) {
            return ['ok' => [], 'errors' => ['Duplicate source in batch: ' . $from]];
        }
        $seenFrom[$from] = true;
        $work[] = ['from' => $from, 'to' => $to];
    }

    if ($work === []) {
        return ['ok' => [], 'errors' => []];
    }

    $tos = array_column($work, 'to');
    if (count(array_unique($tos)) < count($tos)) {
        return ['ok' => [], 'errors' => ['Duplicate target names in batch']];
    }

    $fromKeys = array_flip(array_column($work, 'from'));
    foreach ($work as $w) {
        $fullTo = $dir . '/' . $w['to'];
        if (file_exists($fullTo) && !isset($fromKeys[$w['to']])) {
            return ['ok' => [], 'errors' => ['Target already exists: ' . $w['to']]];
        }
        $fullFrom = $dir . '/' . $w['from'];
        if (!file_exists($fullFrom)) {
            return ['ok' => [], 'errors' => ['Not found: ' . $w['from']]];
        }
    }

    $tmps = [];
    $nWork = count($work);
    for ($i = 0; $i < $nWork; $i++) {
        $tmp = '';
        for ($tries = 0; $tries < 50; $tries++) {
            $tmp = '__fm_br_k_' . bin2hex(random_bytes(6)) . '_' . $i;
            if (!file_exists($dir . '/' . $tmp)) {
                break;
            }
        }
        if ($tmp === '' || file_exists($dir . '/' . $tmp)) {
            return ['ok' => [], 'errors' => ['Could not allocate temp name']];
        }
        $tmps[$i] = $tmp;
    }

    foreach ($work as $i => $w) {
        $fp = $dir . '/' . $w['from'];
        $tp = $dir . '/' . $tmps[$i];
        if (!@rename($fp, $tp)) {
            return ['ok' => [], 'errors' => ['Failed renaming ' . $w['from']]];
        }
    }

    $okNames = [];
    foreach ($work as $i => $w) {
        $tp = $dir . '/' . $tmps[$i];
        $dest = $dir . '/' . $w['to'];
        if (!@rename($tp, $dest)) {
            return ['ok' => $okNames, 'errors' => ['Failed final rename to ' . $w['to']]];
        }
        $okNames[] = $w['from'];
    }

    return ['ok' => $okNames, 'errors' => []];
}

function fmNormalizeUploadedFiles(string $key): array {
    if (empty($_FILES[$key]) || !isset($_FILES[$key]['name'])) {
        return [];
    }
    $f = $_FILES[$key];
    if (!is_array($f['name'])) {
        return [[
            'name'     => (string)$f['name'],
            'tmp_name' => (string)$f['tmp_name'],
            'error'    => (int)$f['error'],
        ]];
    }
    $out = [];
    $n = count($f['name']);
    for ($i = 0; $i < $n; $i++) {
        $out[] = [
            'name'     => (string)$f['name'][$i],
            'tmp_name' => (string)$f['tmp_name'][$i],
            'error'    => (int)$f['error'][$i],
        ];
    }
    return $out;
}

/**
 * Download a remote URL directly into $destDir.
 *
 * @return array{status:string,msg?:string,name?:string,size?:int}
 */
function fmDownloadFromUrl(string $url, string $destDir, string $customName = ''): array {
    $url = trim($url);
    if ($url === '' || !filter_var($url, FILTER_VALIDATE_URL)) {
        return ['status' => 'error', 'msg' => 'Invalid URL'];
    }
    $scheme = strtolower((string)parse_url($url, PHP_URL_SCHEME));
    if (!in_array($scheme, ['http', 'https'], true)) {
        return ['status' => 'error', 'msg' => 'Only http:// and https:// URLs are supported'];
    }
    if (!is_dir($destDir) || !is_writable($destDir)) {
        return ['status' => 'error', 'msg' => 'Destination folder is not writable'];
    }

    $name = trim($customName);
    if ($name === '') {
        $urlPath = (string)parse_url($url, PHP_URL_PATH);
        $name = $urlPath !== '' ? rawurldecode(basename($urlPath)) : '';
    }
    $name = basename(str_replace('\\', '/', $name));
    $name = preg_replace('/[\\\\\/:*?"<>|]/', '_', $name) ?: '';
    if ($name === '' || $name === '.' || $name === '..') {
        $name = 'download_' . date('Ymd_His');
    }

    $destPath = rtrim(normPath($destDir), '/') . '/' . $name;
    if (is_dir($destPath)) {
        return ['status' => 'error', 'msg' => 'A folder with that name already exists: ' . $name];
    }

    $ok = false;
    $errMsg = '';

    // 1. Try cURL extension
    if (function_exists('curl_init')) {
        $fp = @fopen($destPath, 'wb');
        if ($fp === false) {
            return ['status' => 'error', 'msg' => 'Could not create file: ' . $name];
        }
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_FILE           => $fp,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 5,
            CURLOPT_CONNECTTIMEOUT => 30,
            CURLOPT_TIMEOUT        => 300,
            CURLOPT_USERAGENT      => 'SoloFileManager/' . SOLOFILEMANAGER_VERSION,
            CURLOPT_FAILONERROR    => true,
        ]);
        $res = curl_exec($ch);
        $curlErr = curl_error($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        fclose($fp);
        if ($res !== false) {
            $ok = true;
        } else {
            @unlink($destPath);
            $errMsg = $curlErr !== '' ? $curlErr : ('HTTP error ' . $httpCode);
        }
    }

    // 2. Fallback to PHP streams (allow_url_fopen)
    if (!$ok && ini_get('allow_url_fopen')) {
        $ctx = stream_context_create([
            'http' => [
                'method'          => 'GET',
                'follow_location' => 1,
                'max_redirects'   => 5,
                'timeout'         => 120,
                'header'          => "User-Agent: SoloFileManager/" . SOLOFILEMANAGER_VERSION . "\r\n",
            ],
        ]);
        $in = @fopen($url, 'rb', false, $ctx);
        if ($in !== false) {
            $out = @fopen($destPath, 'wb');
            if ($out !== false) {
                $copied = @stream_copy_to_stream($in, $out);
                fclose($out);
                if ($copied !== false) {
                    $ok = true;
                } else {
                    @unlink($destPath);
                    $errMsg = 'Failed while writing downloaded stream';
                }
            } else {
                $errMsg = 'Could not open destination file for writing';
            }
            fclose($in);
        } else {
            $errMsg = $errMsg !== '' ? $errMsg : 'Could not open remote URL';
        }
    }

    // 3. Fallback to OS curl/wget if exec() is available
    if (!$ok && fmIsExecAvailable()) {
        $outCmd = [];
        $rc = 1;
        @exec('curl -fsSL --max-time 300 -o ' . escapeshellarg($destPath) . ' ' . escapeshellarg($url) . ' 2>&1', $outCmd, $rc);
        if ($rc === 0 && is_file($destPath)) {
            $ok = true;
        } else {
            @unlink($destPath);
            @exec('wget -q --timeout=300 -O ' . escapeshellarg($destPath) . ' ' . escapeshellarg($url) . ' 2>&1', $outCmd2, $rc2);
            if ($rc2 === 0 && is_file($destPath)) {
                $ok = true;
            } else {
                @unlink($destPath);
                $errMsg = $errMsg !== '' ? $errMsg : trim(implode("\n", $outCmd));
            }
        }
    }

    if (!$ok) {
        return ['status' => 'error', 'msg' => 'Download failed' . ($errMsg !== '' ? ': ' . $errMsg : ' (cURL and allow_url_fopen unavailable)')];
    }

    clearstatcache(true, $destPath);
    return [
        'status' => 'success',
        'msg'    => 'Uploaded from URL',
        'name'   => $name,
        'size'   => (int)(@filesize($destPath) ?: 0),
    ];
}

function fmIniSizeToBytes(string $value): int {
    if (!preg_match('/^\s*(-?\d+)\s*([kmg]?)/i', $value, $m)) return 0;
    $n = (int)$m[1];
    if ($n <= 0) return 0;
    $mult = ['' => 1, 'k' => 1024, 'm' => 1048576, 'g' => 1073741824];
    return $n * $mult[strtolower($m[2])];
}

function fmHumanBytes(int $bytes): string {
    if ($bytes >= 1073741824) return round($bytes / 1073741824, 2) . ' GB';
    if ($bytes >= 1048576) return round($bytes / 1048576, 1) . ' MB';
    if ($bytes >= 1024) return round($bytes / 1024, 1) . ' KB';
    return $bytes . ' B';
}

function fmUploadErrorMessage(int $code): string {
    switch ($code) {
        case UPLOAD_ERR_INI_SIZE:
            return 'larger than the server limit upload_max_filesize (' . ini_get('upload_max_filesize') . ')';
        case UPLOAD_ERR_FORM_SIZE:
            return 'larger than the form allows';
        case UPLOAD_ERR_PARTIAL:
            return 'only part of the file arrived (connection interrupted) — try again';
        case UPLOAD_ERR_NO_FILE:
            return 'no file data was received';
        case UPLOAD_ERR_NO_TMP_DIR:
            return 'the server has no temporary folder for uploads (check upload_tmp_dir in php.ini)';
        case UPLOAD_ERR_CANT_WRITE:
            return 'the server could not write the file to disk (disk full or no permission)';
        case UPLOAD_ERR_EXTENSION:
            return 'a PHP extension stopped the upload';
        default:
            return 'upload error ' . $code;
    }
}

function jsonOut($data): void {
    header('Content-Type: application/json; charset=utf-8');
    $json = json_encode($data, JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        $json = json_encode([
            'status' => 'error',
            'msg' => 'Could not encode JSON response: ' . json_last_error_msg(),
        ], JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            $json = '{"status":"error","msg":"Could not encode JSON response."}';
        }
    }
    echo $json;
    exit;
}

function fmUtf8SafeText(string $text): string {
    if ($text === '') {
        return '';
    }
    if (preg_match('//u', $text)) {
        return $text;
    }
    if (function_exists('mb_convert_encoding')) {
        $converted = @mb_convert_encoding($text, 'UTF-8', 'Windows-1251,Windows-1252,CP866,ISO-8859-1,UTF-8');
        if (is_string($converted) && $converted !== '' && preg_match('//u', $converted)) {
            return $converted;
        }
    }
    if (function_exists('iconv')) {
        $converted = @iconv('CP866', 'UTF-8//IGNORE', $text);
        if (is_string($converted) && $converted !== '' && preg_match('//u', $converted)) {
            return $converted;
        }
        $converted = @iconv('Windows-1251', 'UTF-8//IGNORE', $text);
        if (is_string($converted) && $converted !== '' && preg_match('//u', $converted)) {
            return $converted;
        }
    }
    return @preg_replace('/[^\x09\x0A\x0D\x20-\x7E]/', '?', $text) ?: '';
}

function fmIsExecAvailable(): bool {
    if (!function_exists('exec')) {
        return false;
    }
    $disabled = ini_get('disable_functions');
    if ($disabled === false || $disabled === '') {
        return true;
    }
    $list = array_map('trim', explode(',', strtolower($disabled)));
    return !in_array('exec', $list, true);
}

function fmFileOpsMode(): string {
    global $FM_FILE_OPS_MODE;
    if (!isset($FM_FILE_OPS_MODE)) {
        return 'auto';
    }
    $m = strtolower(trim((string)$FM_FILE_OPS_MODE));
    if ($m === 'shell') {
        $m = 'os';
    }
    return in_array($m, ['auto', 'php', 'os'], true) ? $m : 'auto';
}

function fmFileOpsTryShell(): bool {
    global $serverCapabilities;
    if (fmFileOpsMode() === 'php') {
        return false;
    }
    return $serverCapabilities['exec_available'];
}

function fmFileOpsAllowPhpFallback(): bool {
    return fmFileOpsMode() !== 'os';
}

function fmVerboseProgressMinItems(): int {
    global $FM_VERBOSE_PROGRESS_MIN_ITEMS;
    $n = isset($FM_VERBOSE_PROGRESS_MIN_ITEMS) ? (int)$FM_VERBOSE_PROGRESS_MIN_ITEMS : 800;
    return max(0, $n);
}

function fmStreamProgressMeta(int $estimatedTotal, bool $granularPhpProgress): array {
    $th = fmVerboseProgressMinItems();
    if ($th === 0) {
        $verbose = $granularPhpProgress;
    } elseif ($estimatedTotal < $th) {
        $verbose = false;
    } else {
        $verbose = $granularPhpProgress;
    }
    return ['verbose_progress' => $verbose, 'verbose_progress_threshold' => $th];
}

function fmServerCapabilities(): array {
    static $cached = null;
    if ($cached !== null) {
        return $cached;
    }
    global $isLinux, $isWindows, $isMacOS;
    $execOk = fmIsExecAvailable();
    $shellZip = false;
    $shellTar = false;
    $shellGzip = false;
    $shellUnzip = false;
    if ($execOk) {
        @exec('zip -v 2>&1', $oz, $rz);
        $shellZip = ($rz === 0);
        @exec('tar --version 2>&1', $ot, $rt);
        $shellTar = ($rt === 0);
        @exec('gzip --version 2>&1', $og, $rg);
        $shellGzip = ($rg === 0);
        @exec('unzip -v 2>&1', $ou, $ru);
        $shellUnzip = ($ru === 0);
    }
    $cached = [
        'exec_available' => $execOk,
        'zip_archive'    => class_exists('ZipArchive'),
        'phar'           => class_exists('Phar'),
        'phar_data'      => class_exists('PharData'),
        'is_linux'       => $isLinux,
        'is_windows'     => $isWindows,
        'is_macos'       => $isMacOS,
        'shell_zip'      => $shellZip,
        'shell_tar'      => $shellTar,
        'shell_gzip'     => $shellGzip,
        'shell_unzip'    => $shellUnzip,
    ];
    return $cached;
}

function fm_ob_implicit_flush_enable(): void {
    if (PHP_VERSION_ID >= 80000) {
        ob_implicit_flush(true);
    } else {
        ob_implicit_flush(1);
    }
}

function randomName(int $minLen = 30, int $maxLen = 40): string {
    $len = mt_rand($minLen, $maxLen);

    $alphabet = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
    $alphaLen = strlen($alphabet);
    $s = '';
    for ($i = 0; $i < $len; $i++) {
        $s .= $alphabet[mt_rand(0, $alphaLen - 1)];
    }
    return $s;
}

function detectCms(string $rootDir): string {
    if (!is_dir($rootDir) || !is_readable($rootDir)) {
        return 'unknown';
    }

    try {
        if (is_file($rootDir . '/wp-config.php') && is_dir($rootDir . '/wp-admin') && is_dir($rootDir . '/wp-content')) {
            return 'wordpress';
        }
        if (is_file($rootDir . '/configuration.php') && is_dir($rootDir . '/administrator')) {
            return 'joomla';
        }
        if (
            (is_file($rootDir . '/app/config/parameters.php') || is_file($rootDir . '/config/settings.inc.php'))
        ) {
            return 'prestashop';
        }
    } catch (Throwable $e) {
        return 'unknown';
    }

    return 'unknown';
}

function ensureSession(): void {
    if (session_status() !== PHP_SESSION_ACTIVE) {
        if (PHP_VERSION_ID >= 70300 && !headers_sent()) {
            $https = !empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off';
            session_set_cookie_params(['httponly' => true, 'samesite' => 'Lax', 'secure' => $https] + session_get_cookie_params());
        }
        session_start();
    }
}

function fmCsrfToken(): string {
    ensureSession();
    if (empty($_SESSION['fm_csrf']) || !is_string($_SESSION['fm_csrf'])) {
        $_SESSION['fm_csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['fm_csrf'];
}

function fmCsrfRequestIsValid(): bool {
    ensureSession();
    $expected = $_SESSION['fm_csrf'] ?? '';
    if (!is_string($expected) || $expected === '') return false;
    $sent = (string)($_SERVER['HTTP_X_CSRF_TOKEN'] ?? $_POST['csrf_token'] ?? '');
    return $sent !== '' && hash_equals($expected, $sent);
}

function isAuthed(bool $enableAuth): bool {
    return true;
}

function fmPasswordIsDefault(string $passwordHash): bool {
    return false;
}

function fmWriteScriptContents(string $newSrc): array {
    $path = __FILE__;
    if (!is_writable($path)) {
        return ['ok' => false, 'reason' => 'not_writable'];
    }
    $dir = dirname($path);
    $tmp = $dir . DIRECTORY_SEPARATOR . '.solofilemanager-write-' . bin2hex(random_bytes(8)) . '.tmp';
    if (@file_put_contents($tmp, $newSrc, LOCK_EX) === false) {
        return ['ok' => false, 'reason' => 'write_failed'];
    }
    $replaced = @rename($tmp, $path);
    if (!$replaced) {
        if (@copy($tmp, $path)) {
            @unlink($tmp);
            return ['ok' => true];
        }
        @unlink($tmp);
        return ['ok' => false, 'reason' => 'replace_failed'];
    }
    return ['ok' => true];
}

function fmEditScriptWithCallback(string $pattern, callable $replacer): array {
    $path = __FILE__;
    if (!is_readable($path)) {
        return ['ok' => false, 'reason' => 'read_failed'];
    }
    if (!is_writable($path)) {
        return ['ok' => false, 'reason' => 'not_writable'];
    }
    $src = @file_get_contents($path);
    if ($src === false || $src === '') {
        return ['ok' => false, 'reason' => 'read_failed'];
    }
    $count = 0;
    $newSrc = preg_replace_callback($pattern, $replacer, $src, 1, $count);
    if ($newSrc === null || $count !== 1) {
        return ['ok' => false, 'reason' => 'pattern_failed'];
    }
    if ($newSrc === $src) {
        return ['ok' => false, 'reason' => 'unchanged'];
    }
    return fmWriteScriptContents($newSrc);
}

function fmWritePasswordHashToScript(string $newHash): array {
    if ($newHash === '' || !preg_match('/^\$2[ayb]\$\d{2}\$[A-Za-z0-9\.\/]{53}$/', $newHash)) {
        return ['ok' => false, 'reason' => 'invalid_hash'];
    }
    return fmEditScriptWithCallback(
        '/(\$PASSWORD_HASH\s*=\s*)([\'"])([^\'"]*)\2(\s*;)/',
        static function (array $m) use ($newHash): string {
            $escaped = str_replace(['\\', '\''], ['\\\\', '\\\''], $newHash);
            return $m[1] . '\'' . $escaped . '\'' . $m[4];
        }
    );
}

function fmNormalizeTerminalFlags(bool $here, bool $manual, bool $advanced): array {
    if ($manual || $advanced) {
        $here = true;
    }
    if (!$here) {
        $manual = false;
        $advanced = false;
    }
    return ['here' => $here, 'manual' => $manual, 'advanced' => $advanced];
}

function fmWriteTerminalFlagsToScript(bool $here, bool $manual, bool $advanced): array {
    $flags = fmNormalizeTerminalFlags($here, $manual, $advanced);
    $path = __FILE__;
    if (!is_readable($path)) {
        return ['ok' => false, 'reason' => 'read_failed'];
    }
    if (!is_writable($path)) {
        return ['ok' => false, 'reason' => 'not_writable'];
    }
    $src = @file_get_contents($path);
    if ($src === false || $src === '') {
        return ['ok' => false, 'reason' => 'read_failed'];
    }
    $map = [
        'FM_ENABLE_TERMINAL_HERE'     => $flags['here'],
        'FM_ENABLE_TERMINAL_MANUAL'   => $flags['manual'],
        'FM_ENABLE_TERMINAL_ADVANCED' => $flags['advanced'],
    ];
    foreach ($map as $name => $val) {
        $literal = $val ? 'true' : 'false';
        $count = 0;
        $src = preg_replace_callback(
            '/(\$' . $name . '\s*=\s*)(true|false)(\s*;)/i',
            static function (array $m) use ($literal): string {
                return $m[1] . $literal . $m[3];
            },
            $src,
            1,
            $count
        );
        if ($src === null || $count !== 1) {
            return ['ok' => false, 'reason' => 'pattern_failed'];
        }
    }
    return fmWriteScriptContents($src);
}

function fmSetTerminalSettings(
    string $password,
    bool $here,
    bool $manual,
    bool $advanced,
    string $passwordHash,
    bool $authEnabled,
    bool $curHere,
    bool $curManual,
    bool $curAdvanced
): array {
    $flags = fmNormalizeTerminalFlags($here, $manual, $advanced);
    if (
        $curHere === $flags['here']
        && $curManual === $flags['manual']
        && $curAdvanced === $flags['advanced']
    ) {
        return [
            'status'   => 'success',
            'saved'    => true,
            'here'     => $flags['here'],
            'manual'   => $flags['manual'],
            'advanced' => $flags['advanced'],
            'msg'      => 'Terminal settings are unchanged.',
        ];
    }
    $write = fmWriteTerminalFlagsToScript($flags['here'], $flags['manual'], $flags['advanced']);
    $lines =
        '$FM_ENABLE_TERMINAL_HERE = ' . ($flags['here'] ? 'true' : 'false') . ";\n" .
        '$FM_ENABLE_TERMINAL_MANUAL = ' . ($flags['manual'] ? 'true' : 'false') . ";\n" .
        '$FM_ENABLE_TERMINAL_ADVANCED = ' . ($flags['advanced'] ? 'true' : 'false') . ';';
    if (!empty($write['ok'])) {
        return [
            'status'   => 'success',
            'saved'    => true,
            'here'     => $flags['here'],
            'manual'   => $flags['manual'],
            'advanced' => $flags['advanced'],
            'msg'      => 'Terminal settings saved in this PHP file. Reloading…',
        ];
    }
    $reason = (string)($write['reason'] ?? 'write_failed');
    $hint = 'Could not update this PHP file automatically';
    if ($reason === 'not_writable' || $reason === 'replace_failed' || $reason === 'write_failed') {
        $hint .= ' (check write permissions on the script file)';
    } elseif ($reason === 'pattern_failed') {
        $hint .= ' (could not find terminal settings in the script)';
    }
    return [
        'status'   => 'success',
        'saved'    => false,
        'here'     => $flags['here'],
        'manual'   => $flags['manual'],
        'advanced' => $flags['advanced'],
        'lines'    => $lines,
        'file'     => pathinfo(__FILE__, PATHINFO_BASENAME),
        'msg'      => $hint . '. Set these lines near the top of the script, save, then reload:',
    ];
}

function fmNormalizeFileOpsModeString(string $mode): string {
    $m = strtolower(trim($mode));
    if ($m === 'shell') {
        $m = 'os';
    }
    return in_array($m, ['auto', 'php', 'os'], true) ? $m : 'auto';
}

function fmWriteFileOpsModeToScript(string $mode): array {
    $mode = fmNormalizeFileOpsModeString($mode);
    return fmEditScriptWithCallback(
        '/(\$FM_FILE_OPS_MODE\s*=\s*)([\'"])([^\'"]*)\2(\s*;)/',
        static function (array $m) use ($mode): string {
            return $m[1] . '\'' . $mode . '\'' . $m[4];
        }
    );
}

function fmSetFileOpsMode(
    string $password,
    string $mode,
    string $passwordHash,
    bool $authEnabled,
    string $currentMode,
    bool $execAvailable
): array {
    $mode = fmNormalizeFileOpsModeString($mode);
    if ($mode === 'os' && !$execAvailable) {
        return [
            'status' => 'error',
            'msg'    => 'OS-only mode needs exec(). Choose Auto or PHP only, or enable exec() on the server.',
        ];
    }
    $current = fmNormalizeFileOpsModeString($currentMode);
    if ($current === $mode) {
        return [
            'status' => 'success',
            'saved'  => true,
            'mode'   => $mode,
            'msg'    => 'File ops mode is unchanged.',
        ];
    }
    $write = fmWriteFileOpsModeToScript($mode);
    $line = '$FM_FILE_OPS_MODE = \'' . $mode . '\';';
    if (!empty($write['ok'])) {
        return [
            'status' => 'success',
            'saved'  => true,
            'mode'   => $mode,
            'msg'    => 'File ops mode saved in this PHP file. Reloading…',
        ];
    }
    $reason = (string)($write['reason'] ?? 'write_failed');
    $hint = 'Could not update this PHP file automatically';
    if ($reason === 'not_writable' || $reason === 'replace_failed' || $reason === 'write_failed') {
        $hint .= ' (check write permissions on the script file)';
    } elseif ($reason === 'pattern_failed') {
        $hint .= ' (could not find $FM_FILE_OPS_MODE in the script)';
    }
    return [
        'status' => 'success',
        'saved'  => false,
        'mode'   => $mode,
        'line'   => $line,
        'file'   => pathinfo(__FILE__, PATHINFO_BASENAME),
        'msg'    => $hint . '. Set this line near the top of the script, save, then reload:',
    ];
}

function fmChangePassword(string $currentPass, string $newPass, string $confirmPass, string $passwordHash): array {
    $isDefault = fmPasswordIsDefault($passwordHash);
    if (!$isDefault) {
        if ($currentPass === '' || !password_verify($currentPass, $passwordHash)) {
            usleep(200000);
            return ['status' => 'error', 'msg' => 'Current password is incorrect'];
        }
    }
    if (strlen($newPass) < 8) {
        return ['status' => 'error', 'msg' => 'New password must be at least 8 characters'];
    }
    if ($newPass !== $confirmPass) {
        return ['status' => 'error', 'msg' => 'New password and confirmation do not match'];
    }
    if ($newPass === 'admin') {
        return ['status' => 'error', 'msg' => 'Choose a password other than the default "admin"'];
    }
    if (!$isDefault && password_verify($newPass, $passwordHash)) {
        return ['status' => 'error', 'msg' => 'New password must be different from the current password'];
    }
    $newHash = password_hash($newPass, PASSWORD_DEFAULT);
    if ($newHash === false || $newHash === '') {
        return ['status' => 'error', 'msg' => 'Could not hash the new password'];
    }
    $write = fmWritePasswordHashToScript($newHash);
    if (!empty($write['ok'])) {
        return [
            'status' => 'success',
            'saved'  => true,
            'msg'    => 'Password updated in this PHP file. Reloading…',
        ];
    }
    $reason = (string)($write['reason'] ?? 'write_failed');
    $hint = 'Could not update this PHP file automatically';
    if ($reason === 'not_writable' || $reason === 'replace_failed' || $reason === 'write_failed') {
        $hint .= ' (check write permissions on the script file)';
    } elseif ($reason === 'pattern_failed') {
        $hint .= ' (could not find $PASSWORD_HASH in the script)';
    }
    return [
        'status' => 'success',
        'saved'  => false,
        'hash'   => $newHash,
        'file'   => pathinfo(__FILE__, PATHINFO_BASENAME),
        'msg'    => $hint . '. Copy the hash below into $PASSWORD_HASH near the top of the script, save, then reload.',
    ];
}

function requireAuthOrExit(bool $enableAuth): void {
    if (isAuthed($enableAuth)) return;
    http_response_code(401);
    jsonOut(['status' => 'error', 'msg' => 'Unauthorized']);
}

function fmTrashBasename(): string {
    global $FM_TRASH_BASENAME;
    $b = isset($FM_TRASH_BASENAME) ? (string)$FM_TRASH_BASENAME : '.trash';
    $b = trim($b, '/');
    return $b !== '' ? basename($b) : '.trash';
}

function fmTrashRootPath(string $rootDir): string {
    $r = rtrim(normPath(safeRealpath($rootDir)), '/');
    return $r . '/' . fmTrashBasename();
}

function fmRelFromRoot(string $absPath, string $rootDir): string {
    $p = rtrim(normPath(safeRealpath($absPath)), '/');
    $r = rtrim(normPath(safeRealpath($rootDir)), '/');
    if ($p === $r) {
        return '';
    }
    $prefix = $r . '/';
    if (strpos($p, $prefix) !== 0) {
        return $p;
    }
    return substr($p, strlen($prefix));
}

function fmIsUnderTrashTree(string $path, string $rootDir): bool {
    $p = rtrim(normPath(safeRealpath($path)), '/');
    $T = rtrim(normPath(fmTrashRootPath($rootDir)), '/');
    if ($p === $T) {
        return true;
    }
    return strpos($p, $T . '/') === 0;
}

const FM_TRASH_HTACCESS_HEAD = '# SoloFileManager Trash';
const FM_TRASH_OLD_MARKER = '.solofm-trash-v2';

function fmTrashFilesDir(string $rootDir): string {
    return fmTrashRootPath($rootDir) . '/files';
}

function fmTrashInfoDir(string $rootDir): string {
    return fmTrashRootPath($rootDir) . '/info';
}

function fmTrashIdIsValid(string $id): bool {
    if ($id === '' || $id === '.' || $id === '..' || $id === 'index.html') {
        return false;
    }
    return !preg_match('/[\\\\\/:*?"<>|]/', $id);
}

function fmTrashNewId(): string {
    try {
        $rand = bin2hex(random_bytes(4));
    } catch (Exception $e) {
        $rand = substr(md5(uniqid('', true)), 0, 8);
    }
    return date('Ymd-His') . '-' . $rand;
}

function fmTrashWriteGuards(string $trashRoot): void {
    if (!fmTrashHasOwnHtaccess($trashRoot)) {
        @file_put_contents($trashRoot . '/.htaccess',
            FM_TRASH_HTACCESS_HEAD . ": removed files must not be reachable from the web.\n" .
            "<IfModule mod_authz_core.c>\n    Require all denied\n</IfModule>\n" .
            "<IfModule !mod_authz_core.c>\n    Order deny,allow\n    Deny from all\n</IfModule>\n"
        );
    }
    foreach ([$trashRoot, $trashRoot . '/info'] as $dir) {
        if (is_dir($dir) && !is_file($dir . '/index.html')) {
            @file_put_contents($dir . '/index.html', '');
        }
    }
}

function fmTrashHasOwnHtaccess(string $trashRoot): bool {
    $head = @file_get_contents($trashRoot . '/.htaccess', false, null, 0, strlen(FM_TRASH_HTACCESS_HEAD));
    return $head === FM_TRASH_HTACCESS_HEAD;
}

function fmTrashIsCurrentLayout(string $trashRoot): bool {
    return fmTrashHasOwnHtaccess($trashRoot) || is_file($trashRoot . '/' . FM_TRASH_OLD_MARKER);
}

function fmTrashEnsure(string $rootDir): bool {
    $T = fmTrashRootPath($rootDir);
    if (!is_dir($T) && !@mkdir($T, 0755, true)) {
        return false;
    }
    if (!fmTrashIsCurrentLayout($T)) {
        fmTrashMigrateLegacy($rootDir);
    }
    foreach ([fmTrashFilesDir($rootDir), fmTrashInfoDir($rootDir)] as $d) {
        if (!is_dir($d)) {
            @mkdir($d, 0755, true);
        }
    }
    fmTrashWriteGuards($T);
    if (!fmTrashHasOwnHtaccess($T)) {
        @file_put_contents($T . '/' . FM_TRASH_OLD_MARKER, "SoloFileManager trash layout (files/ + info/)\n");
    } elseif (is_file($T . '/' . FM_TRASH_OLD_MARKER)) {
        @unlink($T . '/' . FM_TRASH_OLD_MARKER);
    }
    return is_dir(fmTrashFilesDir($rootDir)) && is_dir(fmTrashInfoDir($rootDir));
}

function fmTrashCount(string $rootDir): int {
    $T = fmTrashRootPath($rootDir);
    if (!is_dir($T)) {
        return 0;
    }
    if (!fmTrashIsCurrentLayout($T)) {
        fmTrashEnsure($rootDir);
    }
    $list = @scandir(fmTrashFilesDir($rootDir));
    return $list === false ? 0 : count(array_filter($list, 'fmTrashIdIsValid'));
}

function fmTrashCleanupIfEmpty(string $rootDir): bool {
    $T = fmTrashRootPath($rootDir);
    if (!is_dir($T)) {
        return true;
    }
    $filesDir = fmTrashFilesDir($rootDir);
    $infoDir  = fmTrashInfoDir($rootDir);
    $own      = ['files', 'info', '.htaccess', 'index.html', FM_TRASH_OLD_MARKER];
    if (!fmTrashIsCurrentLayout($T)) {
        return false;
    }
    foreach (@scandir($T) ?: [] as $n) {
        if ($n !== '.' && $n !== '..' && !in_array($n, $own, true)) {
            return false;
        }
    }
    foreach (@scandir($filesDir) ?: [] as $n) {
        if ($n !== '.' && $n !== '..') {
            return false;
        }
    }
    $records = [];
    foreach (@scandir($infoDir) ?: [] as $n) {
        if ($n === '.' || $n === '..' || $n === 'index.html') {
            continue;
        }
        if (substr($n, -5) !== '.json') {
            return false;
        }
        $mt = @filemtime($infoDir . '/' . $n);
        if ($mt === false || time() - $mt <= 60) {
            return false;
        }
        $records[] = $infoDir . '/' . $n;
    }
    foreach ($records as $f) {
        @unlink($f);
    }
    @unlink($infoDir . '/index.html');
    @rmdir($infoDir);
    @rmdir($filesDir);
    foreach (['.htaccess', 'index.html', FM_TRASH_OLD_MARKER] as $f) {
        @unlink($T . '/' . $f);
    }
    return @rmdir($T) || !is_dir($T);
}

function fmTrashStore(string $src, ?string $originalRel, string $rootDir, ?int $deletedAt = null, ?string $name = null): ?string {
    $filesDir = fmTrashFilesDir($rootDir);
    $infoDir  = fmTrashInfoDir($rootDir);
    do {
        $id = fmTrashNewId();
    } while (file_exists($filesDir . '/' . $id) || file_exists($infoDir . '/' . $id . '.json'));
    $isDir = is_dir($src) && !is_link($src);
    $info = [
        'name'          => $name ?? basename($src),
        'original_path' => $originalRel,
        'type'          => $isDir ? 'folder' : 'file',
        'size'          => $isDir ? null : (int)@filesize($src),
        'deleted_at'    => $deletedAt ?? time(),
    ];
    $json = json_encode($info, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    $infoFile = $infoDir . '/' . $id . '.json';
    if ($json === false || @file_put_contents($infoFile, $json) === false) {
        return null;
    }
    if (!@rename($src, $filesDir . '/' . $id)) {
        @unlink($infoFile);
        return null;
    }
    return $id;
}

function fmTrashMigrateLegacy(string $rootDir): void {
    $T = fmTrashRootPath($rootDir);
    $entries = @scandir($T);
    if ($entries === false) {
        return;
    }
    $legacy = array_values(array_filter($entries, function ($e) {
        return $e !== '.' && $e !== '..';
    }));
    if (!$legacy) {
        return;
    }
    $stage = $T . '/.legacy-' . date('Ymd-His');
    if (!@mkdir($stage, 0755)) {
        return;
    }
    foreach ($legacy as $e) {
        @rename($T . '/' . $e, $stage . '/' . $e);
    }
    @mkdir(fmTrashFilesDir($rootDir), 0755, true);
    @mkdir(fmTrashInfoDir($rootDir), 0755, true);
    fmTrashMigrateLegacyDir($stage, '', $rootDir);
    fmTrashRemoveEmptyDirs($stage);
    if (is_dir($stage)) {
        fmTrashStore($stage, null, $rootDir, null, 'Old trash (not converted)');
    }
}

function fmTrashMigrateLegacyDir(string $dir, string $rel, string $rootDir): void {
    $rootR = rtrim(normPath(safeRealpath($rootDir)), '/');
    $list = @scandir($dir);
    if ($list === false) {
        return;
    }
    foreach ($list as $name) {
        if ($name === '.' || $name === '..') {
            continue;
        }
        $src = $dir . '/' . $name;
        $childRel = $rel === '' ? $name : $rel . '/' . $name;
        if (is_dir($src) && !is_link($src) && is_dir($rootR . '/' . $childRel)) {
            fmTrashMigrateLegacyDir($src, $childRel, $rootDir);
            continue;
        }
        $mt = @filemtime($src);
        fmTrashStore($src, $childRel, $rootDir, $mt !== false ? (int)$mt : null);
    }
}

function fmTrashRemoveEmptyDirs(string $dir): bool {
    if (!is_dir($dir) || is_link($dir)) {
        return false;
    }
    $list = @scandir($dir);
    if ($list === false) {
        return false;
    }
    $empty = true;
    foreach ($list as $n) {
        if ($n === '.' || $n === '..') {
            continue;
        }
        if (!fmTrashRemoveEmptyDirs($dir . '/' . $n)) {
            $empty = false;
        }
    }
    return $empty && @rmdir($dir);
}

function fmTrashItemRow(string $id, array $meta, string $filesDir): array {
    $full  = $filesDir . '/' . $id;
    $isDir = is_dir($full);
    $name  = isset($meta['name']) && (string)$meta['name'] !== '' ? basename((string)$meta['name']) : $id;
    $orig  = isset($meta['original_path']) && is_string($meta['original_path']) && $meta['original_path'] !== ''
        ? $meta['original_path'] : null;
    $perms = @fileperms($full);
    $mtime = @filemtime($full);
    return [
        'id'            => $id,
        'name'          => $name,
        'original_path' => $orig,
        'type'          => $isDir ? 'folder' : 'file',
        'size'          => $isDir ? null : (int)(@filesize($full) ?: 0),
        'deleted_at'    => isset($meta['deleted_at']) ? (int)$meta['deleted_at'] : ($mtime !== false ? $mtime : null),
        'ext'           => $isDir ? '' : (string)pathinfo($name, PATHINFO_EXTENSION),
        'permissions'   => $perms !== false ? substr(sprintf('%o', $perms), -4) : '',
    ];
}

function fmTrashList(string $rootDir): array {
    $filesDir = fmTrashFilesDir($rootDir);
    $infoDir  = fmTrashInfoDir($rootDir);
    $items = [];
    $seen  = [];
    foreach (@scandir($infoDir) ?: [] as $f) {
        if (substr($f, -5) !== '.json') {
            continue;
        }
        $id = substr($f, 0, -5);
        if (!fmTrashIdIsValid($id)) {
            continue;
        }
        if (!file_exists($filesDir . '/' . $id)) {
            $mt = @filemtime($infoDir . '/' . $f);
            if ($mt !== false && time() - $mt > 60) {
                @unlink($infoDir . '/' . $f);
            }
            continue;
        }
        $meta = json_decode((string)@file_get_contents($infoDir . '/' . $f), true);
        $items[] = fmTrashItemRow($id, is_array($meta) ? $meta : [], $filesDir);
        $seen[$id] = true;
    }
    foreach (@scandir($filesDir) ?: [] as $id) {
        if (isset($seen[$id]) || !fmTrashIdIsValid($id)) {
            continue;
        }
        $items[] = fmTrashItemRow($id, [], $filesDir);
    }
    return $items;
}

function fmTrashRestoreFreeName(string $dir, string $name, bool $isDir): string {
    $dot = $isDir ? false : strrpos($name, '.');
    if ($dot === false || $dot === 0) {
        $base = $name;
        $ext  = '';
    } else {
        $base = substr($name, 0, $dot);
        $ext  = substr($name, $dot);
    }
    for ($i = 1; $i < 1000; $i++) {
        $candidate = $base . ($i === 1 ? ' (restored)' : ' (restored ' . $i . ')') . $ext;
        if (!file_exists($dir . '/' . $candidate)) {
            return $candidate;
        }
    }
    return $base . ' (restored ' . fmTrashNewId() . ')' . $ext;
}

function fmTrashRestoreItem(string $id, string $rootDir): array {
    global $ALLOW_ALL_DIRECTORIES;
    if (!fmTrashIdIsValid($id)) {
        return ['ok' => false, 'msg' => 'invalid item'];
    }
    $src      = fmTrashFilesDir($rootDir) . '/' . $id;
    $infoFile = fmTrashInfoDir($rootDir) . '/' . $id . '.json';
    if (!file_exists($src)) {
        return ['ok' => false, 'msg' => 'no longer in Trash'];
    }
    $meta = is_file($infoFile) ? json_decode((string)@file_get_contents($infoFile), true) : null;
    $rawOrig = is_array($meta) && isset($meta['original_path']) && is_string($meta['original_path'])
        ? str_replace('\\', '/', $meta['original_path']) : '';

    $isAbsOrig = !empty($ALLOW_ALL_DIRECTORIES) && ($rawOrig !== '' && ($rawOrig[0] === '/' || preg_match('#^[A-Za-z]:/#', $rawOrig)));
    if ($isAbsOrig) {
        $targetPath = $rawOrig;
        $parent = dirname($targetPath);
        $name = basename($targetPath);
    } else {
        $orig = $rawOrig !== '' ? fmSanitizeUploadRelativePath($rawOrig) : null;
        if ($orig === null) {
            return ['ok' => false, 'msg' => 'original location is unknown (delete it forever instead)'];
        }
        $segments = explode('/', $orig);
        if ($segments[0] === fmTrashBasename()) {
            return ['ok' => false, 'msg' => 'invalid original location'];
        }
        $rootR  = rtrim(normPath(safeRealpath($rootDir)), '/');
        $parent = dirname($rootR . '/' . $orig);
        $name   = basename($orig);
    }

    if (!is_dir($parent) && !@mkdir($parent, 0755, true)) {
        return ['ok' => false, 'msg' => 'could not recreate folder ' . fmRelFromRoot($parent, $rootDir)];
    }
    if (!pathInsideRoot($parent, $rootDir) || fmIsUnderTrashTree($parent, $rootDir)) {
        return ['ok' => false, 'msg' => 'invalid original location'];
    }
    $isDir   = is_dir($src);
    $renamed = false;
    if (file_exists($parent . '/' . $name)) {
        $name    = fmTrashRestoreFreeName($parent, $name, $isDir);
        $renamed = true;
    }
    if (!@rename($src, $parent . '/' . $name)) {
        return ['ok' => false, 'msg' => 'could not move it back (permission denied or file in use)'];
    }
    @unlink($infoFile);
    return [
        'ok'      => true,
        'name'    => $name,
        'path'    => normPath($parent . '/' . $name),
        'parent'  => normPath($parent),
        'renamed' => $renamed,
        'type'    => $isDir ? 'folder' : 'file',
    ];
}

function fmTrashStats(string $rootDir, int $maxFiles = 5000, float $maxSeconds = 3.0): array {
    $out = ['exists' => false, 'items' => 0, 'files' => 0, 'bytes' => 0, 'truncated' => false];
    if (!is_dir(fmTrashRootPath($rootDir))) {
        return $out;
    }
    fmTrashEnsure($rootDir);
    if (fmTrashCleanupIfEmpty($rootDir)) {
        return $out;
    }
    $out['exists'] = true;
    $filesDir = fmTrashFilesDir($rootDir);
    $top = @scandir($filesDir);
    if ($top === false) {
        return $out;
    }
    $out['items'] = count(array_filter($top, 'fmTrashIdIsValid'));
    if ($out['items'] === 0) {
        return $out;
    }
    $deadline = microtime(true) + $maxSeconds;
    try {
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($filesDir, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::LEAVES_ONLY,
            RecursiveIteratorIterator::CATCH_GET_CHILD
        );
        foreach ($it as $f) {
            if (!$f->isFile()) {
                continue;
            }
            $out['files']++;
            $sz = @$f->getSize();
            if ($sz !== false) {
                $out['bytes'] += (int)$sz;
            }
            if ($out['files'] >= $maxFiles || microtime(true) > $deadline) {
                $out['truncated'] = true;
                break;
            }
        }
    } catch (Exception $e) {
        $out['truncated'] = true;
    }
    return $out;
}

function fmTerminalCommandMap(): array {
    global $isWindows;
    if ($isWindows) {
        return [
            'pwd' => ['label' => 'Print working directory', 'cmd' => 'cd'],
            'list' => ['label' => 'List files (dir)', 'cmd' => 'dir'],
            'phpv' => ['label' => 'PHP version', 'cmd' => 'php -v'],
            'git_status' => ['label' => 'Git status', 'cmd' => 'git status --short --branch'],
        ];
    }
    return [
        'pwd' => ['label' => 'Print working directory', 'cmd' => 'pwd'],
        'list' => ['label' => 'List files (ls -la)', 'cmd' => 'ls -la'],
        'phpv' => ['label' => 'PHP version', 'cmd' => 'php -v'],
        'git_status' => ['label' => 'Git status', 'cmd' => 'git status --short --branch'],
    ];
}

function fmBuildManualTerminalCommand(string $input, bool $isWindows): array {
    $raw = trim($input);
    if ($raw === '') {
        return ['status' => 'error', 'msg' => 'Command is required'];
    }
    if (strlen($raw) > 180) {
        return ['status' => 'error', 'msg' => 'Command is too long'];
    }
    if (preg_match('/[\r\n;&|`<>]/', $raw)) {
        return ['status' => 'error', 'msg' => 'Command contains blocked shell characters'];
    }
    $parts = preg_split('/\s+/', $raw) ?: [];
    if (count($parts) === 0) {
        return ['status' => 'error', 'msg' => 'Command is required'];
    }
    $cmd = strtolower((string)array_shift($parts));
    $args = array_values($parts);

    if ($cmd === 'whoami') {
        if (count($args) !== 0) return ['status' => 'error', 'msg' => 'whoami accepts no arguments'];
        return ['status' => 'success', 'exe' => 'whoami', 'args' => [], 'label' => 'whoami'];
    }
    if ($cmd === 'php') {
        $ok = (
            $args === ['-v'] ||
            $args === ['--version'] ||
            $args === ['-m'] ||
            $args === ['--ini']
        );
        if (!$ok) {
            return ['status' => 'error', 'msg' => 'Allowed php commands: php -v, php -m, php --ini'];
        }
        return ['status' => 'success', 'exe' => 'php', 'args' => $args, 'label' => 'php ' . implode(' ', $args)];
    }
    if ($cmd === 'git') {
        $ok = false;
        if ($args === ['status'] || $args === ['status', '--short'] || $args === ['status', '--branch'] || $args === ['status', '--short', '--branch']) {
            $ok = true;
        } elseif ($args === ['rev-parse', '--is-inside-work-tree']) {
            $ok = true;
        } elseif ($args === ['branch', '--show-current']) {
            $ok = true;
        } elseif (count($args) === 4 && $args[0] === 'log' && $args[1] === '--oneline' && $args[2] === '-n') {
            $n = (int)$args[3];
            if ((string)$n === $args[3] && $n >= 1 && $n <= 50) {
                $ok = true;
            }
        }
        if (!$ok) {
            return ['status' => 'error', 'msg' => 'Allowed git commands: status, rev-parse --is-inside-work-tree, branch --show-current, log --oneline -n <1..50>'];
        }
        return ['status' => 'success', 'exe' => 'git', 'args' => $args, 'label' => 'git ' . implode(' ', $args)];
    }
    return ['status' => 'error', 'msg' => 'Only git, php and whoami are allowed in manual mode'];
}

function fmBuildEscapedCommand(string $exe, array $args = []): string {
    $cmd = escapeshellcmd($exe);
    foreach ($args as $a) {
        $cmd .= ' ' . escapeshellarg((string)$a);
    }
    return $cmd;
}

function fmRunTerminalCommand(
    string $dir,
    string $cmdId,
    string $rootDir,
    bool $terminalEnabled,
    array $caps,
    string $mode = 'preset',
    string $manualInput = '',
    bool $manualEnabled = false,
    bool $advancedEnabled = false
): array {
    if (!$terminalEnabled) {
        return ['status' => 'error', 'msg' => 'Terminal is disabled. Open Terminal settings from the terminal icon, or set $FM_ENABLE_TERMINAL_HERE = true in this PHP file (trusted hosts only).'];
    }
    if (empty($caps['exec_available'])) {
        return ['status' => 'error', 'msg' => 'exec() is disabled on this server.'];
    }
    $realRaw = realpath($dir);
    if ($realRaw === false) {
        return ['status' => 'error', 'msg' => 'Invalid folder'];
    }
    $real = normPath($realRaw);
    if (!pathInsideRoot($real, $rootDir) || !is_dir($real)) {
        return ['status' => 'error', 'msg' => 'Invalid folder'];
    }
    $modeNorm = strtolower(trim($mode));
    $runLabel = '';
    $runCmd = '';
    if ($modeNorm === 'advanced') {
        if (!$advancedEnabled) {
            return ['status' => 'error', 'msg' => 'Advanced command mode is disabled by configuration.'];
        }
        $raw = trim($manualInput);
        if ($raw === '') {
            return ['status' => 'error', 'msg' => 'Command is required'];
        }
        if (strlen($raw) > 400) {
            return ['status' => 'error', 'msg' => 'Command is too long'];
        }
        if (preg_match('/[\r\n]/', $raw)) {
            return ['status' => 'error', 'msg' => 'Multiline commands are not allowed'];
        }
        $runLabel = $raw;
        $runCmd = $raw;
    } elseif ($modeNorm === 'manual') {
        if (!$manualEnabled) {
            return ['status' => 'error', 'msg' => 'Manual command mode is disabled by configuration.'];
        }
        global $isWindows;
        $built = fmBuildManualTerminalCommand($manualInput, $isWindows);
        if (($built['status'] ?? 'error') !== 'success') {
            return ['status' => 'error', 'msg' => (string)($built['msg'] ?? 'Invalid command')];
        }
        $runLabel = (string)($built['label'] ?? 'manual');
        $runCmd = fmBuildEscapedCommand((string)$built['exe'], (array)$built['args']);
    } else {
        $map = fmTerminalCommandMap();
        if (!isset($map[$cmdId])) {
            return ['status' => 'error', 'msg' => 'Unknown command'];
        }
        $spec = $map[$cmdId];
        $runLabel = $spec['label'];
        $runCmd = $spec['cmd'];
    }
    $old = @getcwd();
    if ($old === false) {
        $old = null;
    }
    if (!@chdir($real)) {
        return ['status' => 'error', 'msg' => 'Cannot enter selected folder'];
    }
    $out = [];
    $code = 1;
    @exec($runCmd . ' 2>&1', $out, $code);
    if ($old !== null) {
        @chdir($old);
    }
    $maxLines = 400;
    $truncated = false;
    if (count($out) > $maxLines) {
        $out = array_slice($out, 0, $maxLines);
        $out[] = '[output truncated]';
        $truncated = true;
    }
    $text = fmUtf8SafeText(implode("\n", $out));
    return [
        'status' => 'success',
        'command' => $cmdId,
        'label' => $runLabel,
        'output' => $text,
        'exit_code' => (int)$code,
        'truncated' => $truncated,
    ];
}

function fmTerminalComplete(string $dir, string $rootDir, bool $terminalEnabled, string $partial, int $limit = 30): array {
    if (!$terminalEnabled) {
        return ['status' => 'error', 'msg' => 'Terminal is disabled. Open Terminal settings from the terminal icon, or set $FM_ENABLE_TERMINAL_HERE = true in this PHP file (trusted hosts only).'];
    }
    $realRaw = realpath($dir);
    if ($realRaw === false) {
        return ['status' => 'error', 'msg' => 'Invalid folder'];
    }
    $cwd = normPath($realRaw);
    if (!pathInsideRoot($cwd, $rootDir) || !is_dir($cwd)) {
        return ['status' => 'error', 'msg' => 'Invalid folder'];
    }

    $raw = trim((string)$partial);
    if ($raw === '' || strlen($raw) > 220) {
        return ['status' => 'success', 'matches' => []];
    }
    if (preg_match('/[\r\n]/', $raw)) {
        return ['status' => 'success', 'matches' => []];
    }

    $token = str_replace('\\', '/', $raw);
    $slashPos = strrpos($token, '/');
    $relDir = $slashPos === false ? '' : substr($token, 0, $slashPos + 1);
    $namePrefix = $slashPos === false ? $token : substr($token, $slashPos + 1);

    $scanDir = $cwd;
    if ($relDir !== '') {
        $scanDirTry = safeRealpath($cwd . '/' . $relDir);
        if (!pathInsideRoot($scanDirTry, $rootDir) || !is_dir($scanDirTry)) {
            return ['status' => 'success', 'matches' => []];
        }
        $scanDir = $scanDirTry;
    }

    $entries = @scandir($scanDir);
    if (!is_array($entries)) {
        return ['status' => 'success', 'matches' => []];
    }

    $matches = [];
    $max = max(1, min(100, (int)$limit));
    foreach ($entries as $e) {
        if ($e === '.' || $e === '..') continue;
        if ($namePrefix !== '' && stripos($e, $namePrefix) !== 0) continue;
        $candidate = $relDir . $e;
        $full = rtrim(normPath($scanDir), '/') . '/' . $e;
        if (is_dir($full)) {
            $candidate .= '/';
        }
        $matches[] = $candidate;
        if (count($matches) >= $max) break;
    }

    usort($matches, static function ($a, $b) {
        return strnatcasecmp((string)$a, (string)$b);
    });

    return ['status' => 'success', 'matches' => $matches];
}

function fmEnsureParentDirs(string $path): bool {
    $dir = dirname($path);
    if (is_dir($dir)) {
        return true;
    }
    return @mkdir($dir, 0755, true);
}

function getFilesAndFoldersList(string $path, string $rootDir, array $excludeNames = [], bool $excludeTrashFolder = false): array {
    $folder = rawurldecode($path);
    $folder = safeRealpath($folder);
    $result = ['folders' => [], 'files' => []];
    if (!pathInsideRoot($folder, $rootDir) || !file_exists($folder) || !is_dir($folder)) {
        return $result;
    }
    $tb = fmTrashBasename();
    $list = @scandir($folder);
    if ($list === false) {
        return $result;
    }
    $baseFolder = rtrim($folder, '/');
    foreach ($list as $f) {
        if (in_array($f, $excludeNames, true)) {
            continue;
        }
        if ($excludeTrashFolder && $f === $tb && !fmIsUnderTrashTree($folder, $rootDir)) {
            continue;
        }
        $full = $baseFolder . '/' . $f;
        $perms = @fileperms($full);
        $permissions = $perms !== false ? substr(sprintf('%o', $perms), -4) : '';
        $mtime = @filemtime($full);
        $mtime = $mtime !== false ? $mtime : null;
        if (is_dir($full)) {
            $result['folders'][$f] = ['permissions' => $permissions, 'mtime' => $mtime];
        } elseif (is_file($full)) {
            $result['files'][$f] = [
                'ext' => pathinfo($full, PATHINFO_EXTENSION),
                'size' => @filesize($full) ?: 0,
                'permissions' => $permissions,
                'mtime' => $mtime
            ];
        }
    }
    return $result;
}

function getFolderTree(string $path, string $rootDir, int $depth = 0, int $maxDepth = 20): array {
    if (!pathInsideRoot($path, $rootDir) || !is_dir($path)) {
        return [];
    }
    $path = safeRealpath($path);
    $name = $path === $rootDir ? 'ROOT' : basename($path);
    if ($name === '') {
        $name = '/';
    }
    $children = [];
    $isTruncated = false;
    if ($depth >= $maxDepth) {
        return ['name' => $name, 'path' => $path, 'children' => [], 'is_truncated' => true];
    }
    $basePath = rtrim($path, '/');
    $list = @scandir($path);
    if ($list !== false) {
        foreach ($list as $f) {
            if ($f === '.' || $f === '..') {
                continue;
            }
            if ($f === fmTrashBasename() && !fmIsUnderTrashTree($path, $rootDir)) {
                continue;
            }
            $full = $basePath . '/' . $f;
            if (in_array($full, ['/proc', '/sys', '/dev'], true)) {
                continue;
            }
            if (is_dir($full) && pathInsideRoot($full, $rootDir)) {
                $children[] = getFolderTree($full, $rootDir, $depth + 1, $maxDepth);
            }
        }
    }
    usort($children, function ($a, $b) {
        return strcasecmp($a['name'] ?? '', $b['name'] ?? '');
    });
    return ['name' => $name, 'path' => $path, 'children' => $children, 'is_truncated' => $isTruncated];
}

function deletePath(string $path, string $rootDir): bool {
    if (!pathInsideRoot($path, $rootDir)) return false;
    if (is_file($path)) return @unlink($path);
    if (is_dir($path)) {
        $items = @scandir($path);
        if ($items === false) return false;
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') continue;
            $full = $path . '/' . $item;
            if (!deletePath($full, $rootDir)) return false;
        }
        return @rmdir($path);
    }
    return false;
}

function deletePathWithProgress(string $path, string $rootDir, int &$count, callable $onProgress): bool {
    if (!pathInsideRoot($path, $rootDir)) return false;
    if (is_file($path)) {
        if (@unlink($path)) {
            $count++;
            $onProgress($count, $path);
            return true;
        }
        return false;
    }
    if (is_dir($path)) {
        $items = @scandir($path);
        if ($items === false) return false;
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') continue;
            $full = $path . '/' . $item;
            if (!deletePathWithProgress($full, $rootDir, $count, $onProgress)) return false;
        }
        if (@rmdir($path)) {
            $count++;
            $onProgress($count, $path);
            return true;
        }
        return false;
    }
    return false;
}

function osDeletePath(string $path, string $rootDir): bool {
    if (!pathInsideRoot($path, $rootDir)) return false;
    if (!file_exists($path)) return true;

    if (PHP_OS_FAMILY === 'Windows') {
        $pathWin = str_replace('/', '\\', $path);
        if (is_file($path)) {
            $cmd = 'del /f /q ' . escapeshellarg($pathWin) . ' 2>nul';
        } else {
            $cmd = 'rmdir /s /q ' . escapeshellarg($pathWin) . ' 2>nul';
        }
    } else {
        $cmd = 'rm -rf ' . escapeshellarg($path) . ' 2>/dev/null';
    }

    @exec($cmd, $output, $returnCode);
    return !file_exists($path);
}

function pathIsStrictDescendant(string $path, string $ancestor): bool {
    $path = rtrim(normPath(safeRealpath($path)), '/');
    $ancestor = rtrim(normPath(safeRealpath($ancestor)), '/');
    if ($path === '' || $ancestor === '') {
        return false;
    }
    if ($path === $ancestor) {
        return false;
    }
    return strpos($path . '/', $ancestor . '/') === 0;
}

function copyPathPhp(string $src, string $dst, string $rootDir, int &$count, callable $onProgress): bool {
    if (!pathInsideRoot($src, $rootDir) || !file_exists($src)) {
        return false;
    }
    if (!pathInsideRoot($dst, $rootDir)) {
        return false;
    }
    if (file_exists($dst)) {
        return false;
    }
    if (is_file($src)) {
        $parent = dirname($dst);
        if (!is_dir($parent) && !@mkdir($parent, 0755, true)) {
            return false;
        }
        if (!@copy($src, $dst)) {
            return false;
        }
        $count++;
        $onProgress($count, $dst);
        return true;
    }
    if (!is_dir($src)) {
        return false;
    }
    if (!@mkdir($dst, 0755, true) && !is_dir($dst)) {
        return false;
    }
    $count++;
    $onProgress($count, $dst);
    $items = @scandir($src);
    if ($items === false) {
        return false;
    }
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }
        if (!copyPathPhp($src . '/' . $item, $dst . '/' . $item, $rootDir, $count, $onProgress)) {
            return false;
        }
    }
    return true;
}

function movePathPhp(string $src, string $dst, string $rootDir, int &$count, callable $onProgress): bool {
    if (!pathInsideRoot($src, $rootDir) || !file_exists($src)) {
        return false;
    }
    if (!pathInsideRoot($dst, $rootDir)) {
        return false;
    }
    if (file_exists($dst)) {
        return false;
    }
    $parent = dirname($dst);
    if (!is_dir($parent) && !@mkdir($parent, 0755, true)) {
        return false;
    }
    if (@rename($src, $dst)) {
        $count++;
        $onProgress($count, $dst);
        return true;
    }
    if (!copyPathPhp($src, $dst, $rootDir, $count, $onProgress)) {
        return false;
    }
    if (!deletePath($src, $rootDir)) {
        deletePath($dst, $rootDir);
        return false;
    }
    return true;
}

function fmNextDuplicateBasename(string $dir, string $sourceName, array &$taken): string {
    $pi = pathinfo($sourceName);
    if (($pi['filename'] ?? '') === '' && ($pi['basename'] ?? '') !== '') {
        $stem = $pi['basename'];
        $ext = '';
    } else {
        $stem = $pi['filename'] ?? $sourceName;
        $ext = isset($pi['extension']) && $pi['extension'] !== '' ? '.' . $pi['extension'] : '';
    }

    for ($n = 1; $n < 100000; $n++) {
        $try = $stem . ' (' . $n . ')' . $ext;
        if (!isset($taken[$try]) && !file_exists($dir . '/' . $try)) {
            $taken[$try] = true;
            return $try;
        }
    }
    $fallback = $stem . '-' . bin2hex(random_bytes(4)) . $ext;
    $taken[$fallback] = true;
    return $fallback;
}

function osCopyEntry(string $src, string $dst, string $rootDir): bool {
    if (!pathInsideRoot($src, $rootDir) || !file_exists($src)) {
        return false;
    }
    if (!pathInsideRoot(dirname($dst), $rootDir)) {
        return false;
    }
    if (file_exists($dst)) {
        return false;
    }
    if (PHP_OS_FAMILY === 'Windows') {
        $srcW = str_replace('/', '\\', $src);
        $dstW = str_replace('/', '\\', $dst);
        if (is_file($src)) {
            $parent = dirname($dstW);
            if (!is_dir($parent)) {
                @mkdir($parent, 0755, true);
            }
            $cmd = 'cmd /c copy /Y ' . escapeshellarg($srcW) . ' ' . escapeshellarg($dstW);
        } else {
            $parent = dirname($dstW);
            if (!is_dir($parent)) {
                @mkdir($parent, 0755, true);
            }
            $cmd = 'cmd /c xcopy ' . escapeshellarg($srcW) . ' ' . escapeshellarg($dstW) . ' /E /I /H /Y';
        }
    } else {
        $cmd = 'cp -a ' . escapeshellarg($src) . ' ' . escapeshellarg($dst);
    }
    @exec($cmd, $out, $rc);
    return file_exists($dst);
}

function osMoveEntry(string $src, string $dst, string $rootDir): bool {
    if (!pathInsideRoot($src, $rootDir) || !file_exists($src)) {
        return false;
    }
    if (!pathInsideRoot(dirname($dst), $rootDir)) {
        return false;
    }
    if (file_exists($dst)) {
        return false;
    }
    $parent = dirname($dst);
    if (!is_dir($parent)) {
        @mkdir($parent, 0755, true);
    }
    if (PHP_OS_FAMILY === 'Windows') {
        $srcW = str_replace('/', '\\', $src);
        $dstW = str_replace('/', '\\', $dst);
        $cmd = 'cmd /c move /Y ' . escapeshellarg($srcW) . ' ' . escapeshellarg($dstW);
    } else {
        $cmd = 'mv ' . escapeshellarg($src) . ' ' . escapeshellarg($dst);
    }
    @exec($cmd, $out, $rc);
    return !file_exists($src) && file_exists($dst);
}

function countPathFiles(string $path, string $rootDir): array {
    if (!pathInsideRoot($path, $rootDir) || !file_exists($path)) {
        return ['files' => 0, 'folders' => 0, 'total' => 0];
    }

    if (is_file($path)) {
        return ['files' => 1, 'folders' => 0, 'total' => 1];
    }
    if (!is_dir($path)) {
        return ['files' => 0, 'folders' => 0, 'total' => 0];
    }

    $filesCount = 0;
    $foldersCount = 0;
    $stack = [$path];

    while ($stack) {
        $dir = array_pop($stack);
        $foldersCount++;

        $items = @scandir($dir);
        if ($items === false) continue;
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') continue;
            $full = rtrim($dir, '/') . '/' . $item;
            if (!pathInsideRoot($full, $rootDir)) continue;

            if (is_dir($full)) {
                $stack[] = $full;
            } elseif (is_file($full)) {
                $filesCount++;
            }
        }
    }

    $foldersCount = max(0, $foldersCount - 1);

    return [
        'files' => $filesCount,
        'folders' => $foldersCount,
        'total' => $filesCount + $foldersCount
    ];
}

function getFolderSizePhp(string $path, string $rootDir): int {
    if (!pathInsideRoot($path, $rootDir) || !is_dir($path)) {
        return 0;
    }
    $size = 0;
    $list = @scandir($path);
    if ($list === false) {
        return 0;
    }
    $base = rtrim($path, '/');
    foreach ($list as $f) {
        if ($f === '.' || $f === '..') {
            continue;
        }
        $full = $base . '/' . $f;
        if (is_file($full)) {
            $size += (int) (@filesize($full) ?: 0);
        } elseif (is_dir($full)) {
            $size += getFolderSizePhp($full, $rootDir);
        }
    }
    return $size;
}

function getFolderSizeOs(string $path, string $rootDir): ?int {
    global $isWindows;
    if (!pathInsideRoot($path, $rootDir) || !is_dir($path)) {
        return null;
    }
    if (!fmFileOpsTryShell()) {
        return null;
    }

    if ($isWindows) {
        $pathWin = str_replace('/', '\\', rtrim($path, '/\\'));
        $src = escapeshellarg($pathWin);
        $cmd = 'robocopy ' . $src . ' ' . $src . ' /L /S /NJH /NFL /NDL /NC /NS /NP /BYTES /XJ /R:0 /W:0 2>&1';
        $out = [];
        $rc = 1;
        @exec($cmd, $out, $rc);
        if ($rc < 8) {
            $text = implode("\n", $out);
            if (preg_match('/^\s*Bytes\s*:\s*([\d]+)/mi', $text, $m)) {
                return (int)$m[1];
            }
        }

        $pathPs = str_replace("'", "''", $pathWin);
        $ps = '$sum = (Get-ChildItem -LiteralPath \'' . $pathPs . '\' -Recurse -Force -File -ErrorAction SilentlyContinue | Measure-Object -Property Length -Sum).Sum; if ($null -eq $sum) { 0 } else { [int64]$sum }';
        $cmdPs = 'powershell -NoProfile -NonInteractive -Command ' . escapeshellarg($ps) . ' 2>&1';
        $outPs = [];
        $rcPs = 1;
        @exec($cmdPs, $outPs, $rcPs);
        if ($rcPs === 0 && isset($outPs[0])) {
            $line = trim((string)$outPs[0]);
            if (preg_match('/^-?\d+$/', $line)) {
                $n = (int)$line;
                return $n >= 0 ? $n : null;
            }
        }
        return null;
    }

    $esc = escapeshellarg($path);
    $out = [];
    $rc = 1;
    @exec('du -sb ' . $esc . ' 2>/dev/null', $out, $rc);
    if ($rc === 0 && isset($out[0]) && preg_match('/^(\d+)/', (string)$out[0], $m)) {
        return (int)$m[1];
    }
    $out = [];
    $rc = 1;
    @exec('du -sk ' . $esc . ' 2>/dev/null', $out, $rc);
    if ($rc === 0 && isset($out[0]) && preg_match('/^(\d+)/', (string)$out[0], $m)) {
        return (int)$m[1] * 1024;
    }
    return null;
}

function getFolderSize(string $path, string $rootDir): int {
    if (!pathInsideRoot($path, $rootDir) || !is_dir($path)) {
        return 0;
    }
    if (fmFileOpsTryShell()) {
        $osSize = getFolderSizeOs($path, $rootDir);
        if ($osSize !== null) {
            return $osSize;
        }
        if (!fmFileOpsAllowPhpFallback()) {
            return 0;
        }
    } elseif (!fmFileOpsAllowPhpFallback()) {
        return 0;
    }
    return getFolderSizePhp($path, $rootDir);
}

function failHtml(string $title, string $msg): void {
    http_response_code(500);
    echo '<!doctype html><meta charset="utf-8"><title>' . htmlspecialchars($title) . '</title>';
    echo '<p style="font-family:system-ui,Segoe UI,Arial;padding:16px;">' . htmlspecialchars($msg) . '</p>';
    exit;
}

// =========================
// Archive helpers (compress / extract)
// =========================

function fmArchiveTypeFromBasename(string $name): ?string {
    if (preg_match('/\.(tar\.gz|tgz)$/i', $name)) {
        return 'tgz';
    }
    $n = strtolower($name);
    if (substr($n, -4) === '.zip') {
        return 'zip';
    }
    if (substr($n, -4) === '.tar') {
        return 'tar';
    }
    return null;
}

function fmArchiveFolderBasename(string $name): string {
    if (preg_match('/\.tar\.gz$/i', $name)) {
        return substr($name, 0, -7);
    }
    if (preg_match('/\.tgz$/i', $name)) {
        return substr($name, 0, -4);
    }
    if (preg_match('/\.zip$/i', $name)) {
        return substr($name, 0, -4);
    }
    if (preg_match('/\.tar$/i', $name)) {
        return substr($name, 0, -4);
    }
    return preg_replace('/\.[^.]+$/', '', $name) ?: $name;
}

function fmArchiveInternalPathAllowed(string $entry): bool {
    $entry = str_replace('\\', '/', $entry);
    if ($entry === '' || strpos($entry, "\0") !== false) {
        return false;
    }
    $parts = explode('/', $entry);
    foreach ($parts as $p) {
        if ($p === '..') {
            return false;
        }
    }
    if ($entry[0] === '/' || preg_match('#^[a-zA-Z]:/#', $entry)) {
        return false;
    }
    return true;
}

function fmFsPathForArchiveApi(string $path): string {
    return str_replace('\\', '/', $path);
}

function fmOpenPharDataForRead(string $archivePath, string $type): ?PharData {
    global $serverCapabilities;
    if (!$serverCapabilities['phar_data']) {
        return null;
    }
    $ap = fmFsPathForArchiveApi($archivePath);
    if ($type === 'tgz') {
        $attempts = [$ap, 'compress.zlib://' . $ap];
        if (preg_match('#^[A-Za-z]:/#', $ap)) {
            $attempts[] = 'compress.zlib://file:///' . $ap;
        }
        foreach ($attempts as $open) {
            try {
                return new PharData($open);
            } catch (Throwable $e) {
            }
        }
        return null;
    }
    try {
        return new PharData($ap);
    } catch (Throwable $e) {
        return null;
    }
}

function fmCountArchiveEntries(string $path, string $type): int {
    global $serverCapabilities;
    if ($type === 'zip') {
        if (!$serverCapabilities['zip_archive']) {
            return 0;
        }
        $z = new ZipArchive();
        $flags = defined('ZipArchive::RDONLY') ? ZipArchive::RDONLY : 0;
        if ($z->open($path, $flags) !== true && $z->open($path) !== true) {
            return 0;
        }
        $n = $z->numFiles;
        $z->close();
        return $n;
    }
    $phar = fmOpenPharDataForRead($path, $type);
    if ($phar === null) {
        return 0;
    }
    try {
        $count = 0;
        foreach (new RecursiveIteratorIterator($phar, RecursiveIteratorIterator::SELF_FIRST) as $_) {
            $count++;
        }
        return $count;
    } catch (Throwable $e) {
        return 0;
    }
}

final class FmDlAbortedException extends RuntimeException {}

final class FmDlStatus {
    private static $file = null;
    private static $data = ['state' => 'none', 'skipped' => [], 'skipped_count' => 0];
    private static $lastWrite = 0.0;

    public static function tokenIsValid(string $token): bool {
        return (bool)preg_match('/^[a-f0-9]{16,64}$/', $token);
    }

    public static function path(string $token): string {
        return sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'fm-dlstat-' . $token . '.json';
    }

    public static function init(string $name): void {
        $token = (string)($_POST['dl_token'] ?? $_GET['dl_token'] ?? '');
        if (!self::tokenIsValid($token)) {
            return;
        }
        self::cleanupOld();
        self::$file = self::path($token);
        self::$data = [
            'state'         => 'preparing',
            'name'          => $name,
            'engine'        => null,
            'bytes'         => 0,
            'total'         => null,
            'heartbeat'     => time(),
            'error'         => null,
            'warning'       => null,
            'skipped'       => [],
            'skipped_count' => 0,
        ];
        self::write();
        register_shutdown_function([self::class, 'onShutdown']);
    }

    public static function streaming(string $engine, ?int $total): void {
        self::$data['state'] = 'streaming';
        self::$data['engine'] = $engine;
        self::$data['total'] = $total;
        self::write();
    }

    public static function progress(int $bytes): void {
        self::$data['bytes'] = $bytes;
        if (microtime(true) - self::$lastWrite >= 1.0) {
            self::write();
        }
    }

    public static function skip(string $rel, string $reason): void {
        self::$data['skipped_count']++;
        if (count(self::$data['skipped']) < 100) {
            self::$data['skipped'][] = $rel . ' (' . $reason . ')';
        }
    }

    public static function done(?string $warning = null): void {
        self::$data['warning'] = $warning;
        self::finish('done');
    }

    public static function fail(string $msg): void {
        self::$data['error'] = $msg;
        self::finish('error');
    }

    public static function aborted(): void {
        self::finish('aborted');
    }

    private static function isFinal(): bool {
        return in_array(self::$data['state'], ['done', 'error', 'aborted'], true);
    }

    private static function finish(string $state): void {
        if (self::isFinal()) {
            return;
        }
        self::$data['state'] = $state;
        self::write();
    }

    private static function write(): void {
        if (self::$file === null) {
            return;
        }
        self::$data['heartbeat'] = time();
        self::$lastWrite = microtime(true);
        $flags = defined('JSON_INVALID_UTF8_SUBSTITUTE') ? JSON_INVALID_UTF8_SUBSTITUTE : 0;
        $json = json_encode(self::$data, $flags);
        if ($json !== false) {
            @file_put_contents(self::$file, $json, LOCK_EX);
        }
    }

    public static function onShutdown(): void {
        if (self::$file === null || self::isFinal()) {
            return;
        }
        $e = error_get_last();
        $fatal = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR];
        if ($e !== null && in_array($e['type'], $fatal, true)) {
            $msg = (string)$e['message'];
            if (stripos($msg, 'Allowed memory size') !== false) {
                self::fail('The server ran out of PHP memory (memory_limit = ' . ini_get('memory_limit') . ').');
            } elseif (stripos($msg, 'Maximum execution time') !== false) {
                self::fail('The server reached the PHP time limit (max_execution_time = ' . ini_get('max_execution_time') . ' s).');
            } else {
                self::fail('PHP error on the server: ' . $msg);
            }
            return;
        }
        if (connection_aborted()) {
            self::aborted();
            return;
        }
        self::fail('The download stopped unexpectedly on the server.');
    }

    public static function read(string $token): array {
        $file = self::path($token);
        if (!is_file($file)) {
            return ['state' => 'pending'];
        }
        $data = json_decode((string)@file_get_contents($file), true);
        if (!is_array($data)) {
            return ['state' => 'unknown'];
        }
        if (in_array($data['state'] ?? '', ['done', 'error', 'aborted'], true)) {
            @unlink($file);
        }
        return $data;
    }

    private static function cleanupOld(): void {
        $old = time() - 3600;
        foreach (glob(sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'fm-dlstat-*.json') ?: [] as $f) {
            if ((int)@filemtime($f) < $old) {
                @unlink($f);
            }
        }
    }
}

function fmDownloadFail(int $code, string $msg): void {
    FmDlStatus::fail($msg);
    while (ob_get_level()) {
        @ob_end_clean();
    }
    header('Content-Type: text/plain; charset=utf-8', true, $code);
    echo $msg;
    exit;
}

function fmBeginStreamDownload(string $downloadName, string $mime, ?int $length = null): void {
    @set_time_limit(0);
    ignore_user_abort(true);
    while (ob_get_level()) {
        @ob_end_clean();
    }
    @ini_set('zlib.output_compression', '0');
    if (function_exists('apache_setenv')) {
        @apache_setenv('no-gzip', '1');
    }
    header('Content-Type: ' . $mime);
    if ($length !== null) {
        header('Content-Length: ' . (string)$length);
    }
    $safe = str_replace(['"', "\r", "\n"], '', $downloadName);
    header('Content-Disposition: attachment; filename="' . $safe . '"; filename*=UTF-8\'\'' . rawurlencode($downloadName));
    header('Cache-Control: no-store');
    header('X-Accel-Buffering: no');
    header('X-Content-Type-Options: nosniff');
}

final class FmStreamOut {
    private $buf = '';
    private $position = 0;
    private $sent = 0;
    private $gzip = null;

    public function __construct(bool $gzip) {
        if ($gzip) {
            $this->gzip = deflate_init(ZLIB_ENCODING_GZIP, ['level' => 6]);
        }
    }

    public function position(): int {
        return $this->position;
    }

    public function write(string $data): void {
        $this->position += strlen($data);
        if ($this->gzip !== null) {
            $data = (string)deflate_add($this->gzip, $data, ZLIB_NO_FLUSH);
        }
        $this->buf .= $data;
        if (strlen($this->buf) >= 65536) {
            $this->flush();
        }
    }

    public function finish(): void {
        if ($this->gzip !== null) {
            $this->buf .= (string)deflate_add($this->gzip, '', ZLIB_FINISH);
            $this->gzip = null;
        }
        $this->flush();
    }

    private function flush(): void {
        if ($this->buf === '') {
            return;
        }
        echo $this->buf;
        $this->sent += strlen($this->buf);
        $this->buf = '';
        flush();
        FmDlStatus::progress($this->sent);
        if (connection_aborted()) {
            throw new FmDlAbortedException('Connection closed by the browser');
        }
    }
}

function fmIsProcOpenAvailable(): bool {
    if (!function_exists('proc_open')) {
        return false;
    }
    $disabled = strtolower((string)ini_get('disable_functions'));
    if ($disabled === '') {
        return true;
    }
    return !in_array('proc_open', array_map('trim', explode(',', $disabled)), true);
}

function fmDownloadArchiveOsCommand(string $type, array $names, bool $isUnixLikeShell, bool $isWindows): ?array {
    global $serverCapabilities;
    foreach ($names as $n) {
        if ($n[0] === '-') {
            return null;
        }
    }
    if ($isWindows) {
        $tar = (getenv('SystemRoot') ?: 'C:\\Windows') . '\\System32\\tar.exe';
        if (!is_file($tar)) {
            return null;
        }
        $opts = ['zip' => ['--format', 'zip', '-c'], 'tar' => ['-c'], 'gzip' => ['-z', '-c']][$type];
        return array_merge([$tar], $opts, ['-f', '-'], $names);
    }
    if (!$isUnixLikeShell) {
        return null;
    }
    if ($type === 'zip') {
        return $serverCapabilities['shell_zip'] ? array_merge(['zip', '-r', '-q', '-'], $names) : null;
    }
    if (!$serverCapabilities['shell_tar'] || ($type === 'gzip' && !$serverCapabilities['shell_gzip'])) {
        return null;
    }
    return array_merge(['tar', $type === 'gzip' ? '-czf' : '-cf', '-'], $names);
}

function fmStreamCommandToClient(array $argv, string $cwd, string $downloadName, string $mime): array {
    $tool = basename(str_replace('\\', '/', $argv[0]));
    $errFile = tempnam(sys_get_temp_dir(), 'fm-dlerr');
    if ($errFile === false) {
        return ['started' => false, 'error' => 'could not create a temp file for ' . $tool . ' output'];
    }
    $readErr = static function () use ($errFile): string {
        $t = trim((string)@file_get_contents($errFile));
        @unlink($errFile);
        return strlen($t) > 600 ? '...' . substr($t, -600) : $t;
    };
    $cmd = PHP_VERSION_ID >= 70400 ? $argv : implode(' ', array_map('escapeshellarg', $argv));
    $pipes = [];
    $proc = @proc_open($cmd, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['file', $errFile, 'w']], $pipes, $cwd);
    if (!is_resource($proc)) {
        $readErr();
        return ['started' => false, 'error' => 'could not start ' . $tool];
    }
    fclose($pipes[0]);
    $first = fread($pipes[1], 65536);
    if ($first === false || $first === '') {
        fclose($pipes[1]);
        $code = proc_close($proc);
        $err = $readErr();
        return ['started' => false, 'error' => $tool . ' exited with code ' . $code . ($err !== '' ? ': ' . $err : '')];
    }

    fmBeginStreamDownload($downloadName, $mime);
    FmDlStatus::streaming('os', null);
    $out = new FmStreamOut(false);
    try {
        $out->write($first);
        while (!feof($pipes[1])) {
            $chunk = fread($pipes[1], 65536);
            if ($chunk === false) {
                break;
            }
            $out->write($chunk);
        }
        $out->finish();
    } catch (FmDlAbortedException $e) {
        proc_terminate($proc);
        fclose($pipes[1]);
        proc_close($proc);
        $readErr();
        FmDlStatus::aborted();
        return ['started' => true, 'error' => ''];
    }
    fclose($pipes[1]);
    $code = proc_close($proc);
    $err = $readErr();
    $isTar = stripos($tool, 'tar') === 0;
    if ($code === 0 || $code === -1) {
        FmDlStatus::done();
    } elseif (($tool === 'zip' && $code === 18) || ($isTar && $code === 1)) {
        FmDlStatus::done($tool . ' could not read some files (exit code ' . $code . ')' . ($err !== '' ? ': ' . $err : '.'));
    } else {
        FmDlStatus::fail($tool . ' stopped with exit code ' . $code . ($err !== '' ? ': ' . $err : '') . '. The downloaded archive is incomplete.');
    }
    return ['started' => true, 'error' => ''];
}

function fmArchiveWalk(string $dir, array $names): Generator {
    $base = rtrim($dir, '/');
    foreach ($names as $n) {
        yield from fmArchiveWalkPath($base . '/' . $n, $n);
    }
}

function fmArchiveWalkPath(string $abs, string $rel): Generator {
    $isLink = is_link($abs);
    if (is_dir($abs)) {
        if ($isLink && strpos($rel, '/') !== false) {
            FmDlStatus::skip($rel . '/', 'linked folder');
            return;
        }
        yield [$abs, $rel, true];
        $entries = @scandir($abs);
        if ($entries === false) {
            FmDlStatus::skip($rel . '/', 'folder not readable');
            return;
        }
        foreach ($entries as $e) {
            if ($e !== '.' && $e !== '..') {
                yield from fmArchiveWalkPath($abs . '/' . $e, $rel . '/' . $e);
            }
        }
        return;
    }
    if (is_file($abs)) {
        yield [$abs, $rel, false];
    } elseif ($isLink) {
        FmDlStatus::skip($rel, 'broken link');
    }
}

function fmZipDosTime(int $ts): array {
    $d = getdate($ts);
    if ($d['year'] < 1980) {
        return [0, (1 << 5) | 1];
    }
    $year = min($d['year'], 2107) - 1980;
    return [
        ($d['hours'] << 11) | ($d['minutes'] << 5) | intdiv($d['seconds'], 2),
        ($year << 9) | ($d['mon'] << 5) | $d['mday'],
    ];
}

function fmZipCentralEntry(string $name, int $flags, int $method, array $dos, int $crc, int $csize, int $usize, int $offset, int $extAttr, bool $zip64Sizes): string {
    $z64 = '';
    if ($zip64Sizes || $usize >= 0xFFFFFFFF || $csize >= 0xFFFFFFFF) {
        $z64 .= pack('PP', $usize, $csize);
        $usize = 0xFFFFFFFF;
        $csize = 0xFFFFFFFF;
    }
    if ($offset >= 0xFFFFFFFF) {
        $z64 .= pack('P', $offset);
        $offset = 0xFFFFFFFF;
    }
    $extra = $z64 !== '' ? pack('vv', 0x0001, strlen($z64)) . $z64 : '';
    $version = $extra !== '' ? 45 : 20;
    return pack('VvvvvvvVVVvvvvvVV', 0x02014b50, $version, $version, $flags, $method, $dos[0], $dos[1], $crc, $csize, $usize, strlen($name), strlen($extra), 0, 0, 0, $extAttr, $offset)
        . $name . $extra;
}

function fmStreamZip(string $dir, array $names, FmStreamOut $out): void {
    $central = [];
    foreach (fmArchiveWalk($dir, $names) as [$abs, $rel, $isDir]) {
        $dos = fmZipDosTime((int)(@filemtime($abs) ?: time()));
        $offset = $out->position();
        if ($isDir) {
            $name = $rel . '/';
            $out->write(pack('VvvvvvVVVvv', 0x04034b50, 20, 0x0800, 0, $dos[0], $dos[1], 0, 0, 0, strlen($name), 0) . $name);
            $central[] = fmZipCentralEntry($name, 0x0800, 0, $dos, 0, 0, 0, $offset, 0x10, false);
            continue;
        }
        $fh = @fopen($abs, 'rb');
        if ($fh === false) {
            FmDlStatus::skip($rel, 'not readable');
            continue;
        }
        $zip64 = (int)@filesize($abs) >= 0xF0000000;
        $flags = 0x0808;
        $extra = $zip64 ? pack('vvPP', 0x0001, 16, 0, 0) : '';
        $sizeField = $zip64 ? 0xFFFFFFFF : 0;
        $out->write(pack('VvvvvvVVVvv', 0x04034b50, $zip64 ? 45 : 20, $flags, 8, $dos[0], $dos[1], 0, $sizeField, $sizeField, strlen($rel), strlen($extra)) . $rel . $extra);

        $crc = hash_init('crc32b');
        $deflate = deflate_init(ZLIB_ENCODING_RAW, ['level' => 1]);
        $usize = 0;
        $dataStart = $out->position();
        while (!feof($fh)) {
            $chunk = fread($fh, 1048576);
            if ($chunk === false || $chunk === '') {
                break;
            }
            $usize += strlen($chunk);
            hash_update($crc, $chunk);
            $out->write((string)deflate_add($deflate, $chunk, ZLIB_NO_FLUSH));
        }
        $out->write((string)deflate_add($deflate, '', ZLIB_FINISH));
        fclose($fh);
        $csize = $out->position() - $dataStart;
        $crcVal = (int)hexdec(hash_final($crc));
        $out->write($zip64
            ? pack('VVPP', 0x08074b50, $crcVal, $csize, $usize)
            : pack('VVVV', 0x08074b50, $crcVal, $csize, $usize));
        $central[] = fmZipCentralEntry($rel, $flags, 8, $dos, $crcVal, $csize, $usize, $offset, 0, $zip64);
    }

    $cdOffset = $out->position();
    foreach ($central as $entry) {
        $out->write($entry);
    }
    $cdSize = $out->position() - $cdOffset;
    $count = count($central);
    if ($count >= 0xFFFF || $cdOffset >= 0xFFFFFFFF || $cdSize >= 0xFFFFFFFF) {
        $eocd64Offset = $out->position();
        $out->write(pack('VPvvVVPPPP', 0x06064b50, 44, 45, 45, 0, 0, $count, $count, $cdSize, $cdOffset));
        $out->write(pack('VVPV', 0x07064b50, 0, $eocd64Offset, 1));
    }
    $out->write(pack('VvvvvVVv', 0x06054b50, 0, 0, min($count, 0xFFFF), min($count, 0xFFFF), min($cdSize, 0xFFFFFFFF), min($cdOffset, 0xFFFFFFFF), 0));
}

function fmTarWriteHeader(FmStreamOut $out, string $name, int $mode, int $size, int $mtime, string $type): void {
    if (strlen($name) > 100) {
        $long = $name . "\0";
        fmTarWriteHeader($out, '././@LongLink', 0644, strlen($long), 0, 'L');
        $out->write($long . str_repeat("\0", (512 - strlen($long) % 512) % 512));
        $name = substr($name, 0, 100);
    }
    if ($size <= 077777777777) {
        $sizeField = sprintf('%011o', $size) . "\0";
    } else {
        $sizeField = '';
        for ($i = 0, $v = $size; $i < 11; $i++, $v >>= 8) {
            $sizeField = chr($v & 0xFF) . $sizeField;
        }
        $sizeField = "\x80" . $sizeField;
    }
    $h = str_pad($name, 100, "\0")
        . sprintf('%07o', $mode & 07777) . "\0"
        . "0000000\0"
        . "0000000\0"
        . $sizeField
        . sprintf('%011o', max(0, $mtime)) . "\0"
        . '        '
        . $type
        . str_repeat("\0", 100)
        . "ustar  \0"
        . str_repeat("\0", 247);
    $sum = array_sum(unpack('C*', $h));
    $out->write(substr_replace($h, sprintf('%06o', $sum) . "\0 ", 148, 8));
}

function fmStreamTar(string $dir, array $names, FmStreamOut $out): void {
    foreach (fmArchiveWalk($dir, $names) as [$abs, $rel, $isDir]) {
        $mtime = (int)(@filemtime($abs) ?: time());
        $perms = @fileperms($abs);
        if ($isDir) {
            fmTarWriteHeader($out, $rel . '/', $perms !== false ? $perms : 0755, 0, $mtime, '5');
            continue;
        }
        $fh = @fopen($abs, 'rb');
        if ($fh === false) {
            FmDlStatus::skip($rel, 'not readable');
            continue;
        }
        $size = (int)@filesize($abs);
        fmTarWriteHeader($out, $rel, $perms !== false ? $perms : 0644, $size, $mtime, '0');
        $left = $size;
        while ($left > 0 && !feof($fh)) {
            $chunk = fread($fh, (int)min(1048576, $left));
            if ($chunk === false || $chunk === '') {
                break;
            }
            $out->write($chunk);
            $left -= strlen($chunk);
        }
        fclose($fh);
        if ($left > 0) {
            FmDlStatus::skip($rel, 'file changed while reading, end filled with zeros');
            for (; $left > 0; $left -= 1048576) {
                $out->write(str_repeat("\0", (int)min(1048576, $left)));
            }
        }
        $out->write(str_repeat("\0", (512 - $size % 512) % 512));
    }
    $out->write(str_repeat("\0", 1024));
}

function fmDownloadArchiveHandlePost(string $rootDir, bool $isUnixLikeShell, bool $isWindows): void {
    global $serverCapabilities;
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }
    FmDlStatus::init(trim((string)$_POST['archive_name']));
    $dir = fmResolveDir((string)$_POST['in'], $rootDir);
    $type = (string)$_POST['archive_type'];

    if (!pathInsideRoot($dir, $rootDir) || !is_dir($dir)) {
        fmDownloadFail(400, 'The folder no longer exists or is outside the file manager root.');
    }
    if (!in_array($type, ['zip', 'tar', 'gzip'], true)) {
        fmDownloadFail(400, 'Invalid archive type.');
    }
    if (fmFileOpsMode() === 'os' && !$serverCapabilities['exec_available']) {
        fmDownloadFail(500, 'File ops mode is OS-only but exec() is disabled. Set $FM_FILE_OPS_MODE to "auto" or "php".');
    }

    $baseName = trim((string)$_POST['archive_name']);
    $baseName = preg_replace('/[\\\\\/:*?"<>|]/', '', $baseName);
    if ($baseName === '') {
        fmDownloadFail(400, 'Archive name is required.');
    }
    $ext = ['zip' => '.zip', 'tar' => '.tar', 'gzip' => '.tar.gz'][$type];
    if (substr($baseName, -strlen($ext)) !== $ext) {
        $baseName .= $ext;
    }

    $names = [];
    $baseDir = rtrim($dir, '/');
    foreach ((array)$_POST['names'] as $n) {
        $n = basename((string)$n);
        if ($n === '' || $n === '.' || $n === '..') {
            continue;
        }
        if (preg_match('/[\\\\\/:*?"<>|]/', $n)) {
            continue;
        }
        $full = $baseDir . '/' . $n;
        if (file_exists($full) && pathInsideRoot($full, $rootDir)) {
            $names[] = $n;
        }
    }
    if ($names === []) {
        fmDownloadFail(400, 'None of the selected items exist any more.');
    }

    $mime = [
        'zip' => 'application/zip',
        'tar' => 'application/x-tar',
        'gzip' => 'application/gzip',
    ][$type];

    $osError = '';
    $tryOs = !$isWindows || fmFileOpsMode() === 'os';
    if ($tryOs && fmFileOpsTryShell() && fmIsProcOpenAvailable()) {
        $argv = fmDownloadArchiveOsCommand($type, $names, $isUnixLikeShell, $isWindows);
        if ($argv !== null) {
            $res = fmStreamCommandToClient($argv, $dir, $baseName, $mime);
            if ($res['started']) {
                exit;
            }
            $osError = $res['error'];
        } else {
            $osError = 'no suitable archive tool was found on the server';
        }
    }
    if (!fmFileOpsAllowPhpFallback()) {
        fmDownloadFail(500, 'Archive failed (OS-only mode): ' . ($osError !== '' ? $osError : 'proc_open() is not available') . '.');
    }
    if ($type !== 'tar' && !function_exists('deflate_init')) {
        fmDownloadFail(500, 'Cannot build the archive: the PHP zlib extension is not available' . ($osError !== '' ? ' and OS tools failed (' . $osError . ')' : '') . '. Try TAR format.');
    }

    fmBeginStreamDownload($baseName, $mime);
    FmDlStatus::streaming('php', null);
    $out = new FmStreamOut($type === 'gzip');
    try {
        if ($type === 'zip') {
            fmStreamZip($dir, $names, $out);
        } else {
            fmStreamTar($dir, $names, $out);
        }
        $out->finish();
        FmDlStatus::done();
    } catch (FmDlAbortedException $e) {
        FmDlStatus::aborted();
    } catch (Throwable $e) {
        FmDlStatus::fail('The archive stopped while downloading: ' . $e->getMessage() . '. The downloaded file is incomplete.');
    }
    exit;
}

// =========================
// Setup (rename) + Auth routing
// =========================

$thisFileName = pathinfo(__FILE__, PATHINFO_BASENAME);
$needsRename = false;
$isAuthed = true;
$passwordIsDefault = false;
$needsPasswordChange = false;

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && !fmCsrfRequestIsValid()) {
    $postAction = (string)($_POST['action'] ?? '');
    if (!$needsRename && !in_array($postAction, ['do-rename', 'login', 'logout'], true)) {
        requireAuthOrExit($ENABLE_AUTH);
    }
    http_response_code(403);
    jsonOut(['status' => 'error', 'msg' => 'Security token is missing or expired. Reload the page and try again.']);
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && $_POST === [] && $_FILES === []) {
    $postMax = fmIniSizeToBytes((string)ini_get('post_max_size'));
    $sent = (int)($_SERVER['CONTENT_LENGTH'] ?? 0);
    if ($postMax > 0 && $sent > $postMax) {
        while (ob_get_level() > 0) ob_end_clean();
        http_response_code(413);
        jsonOut([
            'status' => 'error',
            'msg'    => 'The upload (' . fmHumanBytes($sent) . ') is larger than the server limit post_max_size ('
                . ini_get('post_max_size') . '). Increase it in php.ini, or upload smaller files.',
        ]);
    }
}

if (isset($_POST['action']) && $_POST['action'] === 'do-rename') {
    if (!$needsRename) {
        jsonOut(['status' => 'error', 'msg' => 'Already renamed']);
    }
    $new = isset($_POST['new_name']) ? trim((string)$_POST['new_name']) : '';
    $validRandom = (bool)preg_match('/^[A-Za-z0-9]{30,40}\\.php$/', $new);
    $validPrefixed = (bool)preg_match('/^solofilemanager_[A-Za-z0-9]{24,32}\\.php$/', $new);
    if (!$validRandom && !$validPrefixed) {
        jsonOut(['status' => 'error', 'msg' => 'Invalid name. Use 30–40 chars [A-Za-z0-9].php, or solofilemanager_ + 24–32 chars + .php']);
    }
    $dest = safeRealpath(__DIR__ . '/' . $new);
    if (!pathInsideRoot($dest, $ROOT_DIR)) {
        jsonOut(['status' => 'error', 'msg' => 'Invalid destination']);
    }
    if (file_exists($dest)) {
        jsonOut(['status' => 'error', 'msg' => 'File already exists']);
    }
    $ok = @rename(__FILE__, __DIR__ . '/' . $new);
    if (!$ok) {
        jsonOut(['status' => 'error', 'msg' => 'Rename failed (permissions/lock).']);
    }
    jsonOut(['status' => 'success', 'redirect' => './' . $new]);
}

if (isset($_POST['action']) && $_POST['action'] === 'login') {
    ensureSession();
    $pass = (string)($_POST['password'] ?? '');
    if ($pass !== '' && password_verify($pass, $PASSWORD_HASH)) {
        $_SESSION['fm_auth'] = true;
        jsonOut(['status' => 'success']);
    }
    usleep(200000);
    jsonOut(['status' => 'error', 'msg' => 'Invalid password']);
}
if (isset($_POST['action']) && $_POST['action'] === 'logout') {
    ensureSession();
    $_SESSION['fm_auth'] = false;
    jsonOut(['status' => 'success']);
}
if (isset($_POST['action']) && $_POST['action'] === 'change-password') {
    requireAuthOrExit($ENABLE_AUTH);
    jsonOut(fmChangePassword(
        (string)($_POST['current_password'] ?? ''),
        (string)($_POST['new_password'] ?? ''),
        (string)($_POST['confirm_password'] ?? ''),
        $PASSWORD_HASH
    ));
}
if (isset($_POST['action']) && $_POST['action'] === 'set-terminal-settings') {
    requireAuthOrExit($ENABLE_AUTH);
    $asBool = static function ($v): bool {
        $raw = strtolower(trim((string)$v));
        return in_array($raw, ['1', 'true', 'yes', 'on'], true);
    };
    jsonOut(fmSetTerminalSettings(
        (string)($_POST['password'] ?? ''),
        $asBool($_POST['here'] ?? ''),
        $asBool($_POST['manual'] ?? ''),
        $asBool($_POST['advanced'] ?? ''),
        $PASSWORD_HASH,
        $ENABLE_AUTH,
        (bool)$FM_ENABLE_TERMINAL_HERE,
        (bool)$FM_ENABLE_TERMINAL_MANUAL,
        (bool)$FM_ENABLE_TERMINAL_ADVANCED
    ));
}
if (isset($_POST['action']) && $_POST['action'] === 'set-file-ops-mode') {
    requireAuthOrExit($ENABLE_AUTH);
    jsonOut(fmSetFileOpsMode(
        (string)($_POST['password'] ?? ''),
        (string)($_POST['mode'] ?? ''),
        $PASSWORD_HASH,
        $ENABLE_AUTH,
        fmFileOpsMode(),
        !empty($serverCapabilities['exec_available'])
    ));
}

// =========================
// API actions
// =========================

if (!$needsRename) {
    if (isset($_GET['action']) || isset($_POST['action'])) {
        $a = (string)($_GET['action'] ?? $_POST['action'] ?? '');
        $allowUnauthed = in_array($a, ['do-rename', 'login', 'logout'], true);
        if (!$allowUnauthed) {
            requireAuthOrExit($ENABLE_AUTH);
        }
        if (
            $ENABLE_AUTH
            && !$allowUnauthed
            && $a !== 'change-password'
            && fmPasswordIsDefault($PASSWORD_HASH)
        ) {
            jsonOut(['status' => 'error', 'msg' => 'Change the default password before continuing.']);
        }
    }
}

if (isset($_GET['action']) && $_GET['action'] === 'open' && isset($_GET['folder'])) {
    $folderRaw = rawurldecode((string)$_GET['folder']);
    $folder = safeRealpath($folderRaw);
    if (!pathInsideRoot($folder, $ROOT_DIR)) {
        jsonOut([
            'status'  => 'invalid',
            'reason'  => 'outside_root',
            'msg'     => 'That path is outside the file manager root.',
            'folders' => [],
            'files'   => [],
        ]);
    }
    if (fmIsUnderTrashTree($folder, $ROOT_DIR)) {
        $items = [];
        if (is_dir(fmTrashRootPath($ROOT_DIR))) {
            if (!fmTrashEnsure($ROOT_DIR)) {
                jsonOut([
                    'status'  => 'invalid',
                    'reason'  => 'not_found',
                    'msg'     => 'The Trash folder is not accessible (check permissions on the file manager root).',
                    'folders' => [],
                    'files'   => [],
                ]);
            }
            $items = fmTrashList($ROOT_DIR);
            if (count($items) === 0) {
                fmTrashCleanupIfEmpty($ROOT_DIR);
            }
        }
        jsonOut([
            'status'         => 'ok',
            'trash_view'     => true,
            'trash_basename' => fmTrashBasename(),
            'trash_items'    => $items,
            'trash_count'    => count($items),
            'folders'        => [],
            'files'          => [],
        ]);
    }
    if (!file_exists($folder) || !is_dir($folder)) {
        jsonOut([
            'status'  => 'invalid',
            'reason'  => 'not_found',
            'msg'     => 'This folder does not exist or is not accessible.',
            'folders' => [],
            'files'   => [],
        ]);
    }
    $exclude = array_merge($EXCLUDE_NAMES, [$thisFileName]);
    $payload = getFilesAndFoldersList($folder, $ROOT_DIR, $exclude, true);
    $payload['status'] = 'ok';
    $payload['trash_basename'] = fmTrashBasename();
    $payload['trash_count'] = fmTrashCount($ROOT_DIR);
    jsonOut($payload);
}

if (isset($_GET['action']) && $_GET['action'] === 'folder-tree') {
    $folder = safeRealpath($ROOT_DIR);
    if (!pathInsideRoot($folder, $ROOT_DIR)) jsonOut(['name' => 'ROOT', 'path' => $ROOT_DIR, 'children' => [], 'is_truncated' => false]);
    $depth = isset($_GET['depth']) ? max(1, min((int)$_GET['depth'], $MAX_TREE_DEPTH)) : $MAX_TREE_DEPTH;
    jsonOut(getFolderTree($folder, $ROOT_DIR, 0, $depth));
}

if (isset($_GET['action']) && $_GET['action'] === 'folder-tree-branch' && isset($_GET['folder'])) {
    $folder = safeRealpath(rawurldecode((string)$_GET['folder']));
    if (!pathInsideRoot($folder, $ROOT_DIR) || !is_dir($folder)) {
        jsonOut(['status' => 'error', 'msg' => 'Invalid folder']);
    }
    jsonOut(['status' => 'success', 'tree' => getFolderTree($folder, $ROOT_DIR, 0, $MAX_TREE_DEPTH)]);
}

if (isset($_GET['action']) && $_GET['action'] === 'get-folder-size' && isset($_GET['folder'])) {
    $folder = safeRealpath(rawurldecode((string)$_GET['folder']));
    if (!pathInsideRoot($folder, $ROOT_DIR) || !is_dir($folder)) {
        jsonOut(['status' => 'error', 'msg' => 'Invalid folder', 'size' => 0]);
    }
    if (fmFileOpsMode() === 'os' && !fmFileOpsTryShell()) {
        jsonOut([
            'status' => 'error',
            'msg'    => 'File ops mode is OS-only but exec() is disabled. Set $FM_FILE_OPS_MODE to "auto" or "php".',
            'size'   => 0,
        ]);
    }
    if (fmFileOpsTryShell()) {
        $osSize = getFolderSizeOs($folder, $ROOT_DIR);
        if ($osSize !== null) {
            jsonOut(['status' => 'success', 'size' => $osSize, 'via' => 'os']);
        }
        if (!fmFileOpsAllowPhpFallback()) {
            jsonOut(['status' => 'error', 'msg' => 'Could not get folder size via OS tools (OS-only mode).', 'size' => 0]);
        }
    }
    jsonOut(['status' => 'success', 'size' => getFolderSizePhp($folder, $ROOT_DIR), 'via' => 'php']);
}

if (isset($_GET['action']) && $_GET['action'] === 'get-info' && isset($_GET['path'])) {
    $path = safeRealpath(rawurldecode((string)$_GET['path']));
    if (!pathInsideRoot($path, $ROOT_DIR) || !file_exists($path)) jsonOut(['status' => 'error', 'msg' => 'Not found']);
    $name = basename($path);
    $isDir = is_dir($path);
    $size = $isDir ? getFolderSize($path, $ROOT_DIR) : (int) (@filesize($path) ?: 0);
    $modified = @filemtime($path);
    $modifiedStr = $modified ? date('Y-m-d H:i:s', $modified) : '';
    $ext = $isDir ? null : pathinfo($path, PATHINFO_EXTENSION);
    $perms = @fileperms($path);
    $permissions = $perms !== false ? substr(sprintf('%o', $perms), -4) : '';
    $out = [
        'status'      => 'success',
        'name'        => $name,
        'path'        => $path,
        'type'        => $isDir ? 'folder' : 'file',
        'size'        => (int)$size,
        'modified'    => $modifiedStr,
        'extension'   => $ext,
        'permissions' => $permissions
    ];
    jsonOut($out);
}

if (isset($_GET['action']) && $_GET['action'] === 'get-server-info') {
    $rootFree  = @disk_free_space($ROOT_DIR);
    $rootTotal = @disk_total_space($ROOT_DIR);
    jsonOut([
        'status'               => 'success',
        'solofilemanager_version' => SOLOFILEMANAGER_VERSION,
        'auth_enabled'         => (bool)$ENABLE_AUTH,
        'php_version'          => PHP_VERSION,
        'php_sapi'             => PHP_SAPI,
        'server_software'      => (string)($_SERVER['SERVER_SOFTWARE'] ?? ''),
        'os'                   => PHP_OS,
        'memory_limit'         => (string)ini_get('memory_limit'),
        'max_execution_time'   => (string)ini_get('max_execution_time'),
        'upload_max_filesize'  => (string)ini_get('upload_max_filesize'),
        'post_max_size'        => (string)ini_get('post_max_size'),
        'timezone'             => date_default_timezone_get(),
        'root_path'            => $ROOT_DIR,
        'allow_all_directories'=> !empty($ALLOW_ALL_DIRECTORIES),
        'disk_free_bytes'      => $rootFree !== false ? $rootFree : null,
        'disk_total_bytes'     => $rootTotal !== false ? $rootTotal : null,
        'server_time'          => date('Y-m-d H:i:s'),
        'exec_available'            => $serverCapabilities['exec_available'],
        'terminal_here_enabled'     => $FM_ENABLE_TERMINAL_HERE,
        'terminal_manual_enabled'   => $FM_ENABLE_TERMINAL_MANUAL,
        'terminal_advanced_enabled' => $FM_ENABLE_TERMINAL_ADVANCED,
        'file_ops_mode'             => fmFileOpsMode(),
        'verbose_progress_min_items'=> fmVerboseProgressMinItems(),
        'capabilities'              => $serverCapabilities,
    ]);
}

if (isset($_GET['action']) && $_GET['action'] === 'get-trash-info') {
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }
    jsonOut(['status' => 'success', 'basename' => fmTrashBasename()] + fmTrashStats($ROOT_DIR));
}

if (isset($_POST['action']) && $_POST['action'] === 'terminal-run' && isset($_POST['in'])) {
    $pathIn = trim((string)$_POST['in']);
    $cmdId = trim((string)$_POST['cmd']);
    $mode = trim((string)($_POST['mode'] ?? 'preset'));
    $userCmd = (string)($_POST['user_cmd'] ?? '');
    $dir = fmResolveDir($pathIn, $ROOT_DIR);
    jsonOut(fmRunTerminalCommand(
        $dir,
        $cmdId,
        $ROOT_DIR,
        $FM_ENABLE_TERMINAL_HERE,
        $serverCapabilities,
        $mode,
        $userCmd,
        $FM_ENABLE_TERMINAL_MANUAL,
        $FM_ENABLE_TERMINAL_ADVANCED
    ));
}

if (isset($_POST['action']) && $_POST['action'] === 'terminal-complete' && isset($_POST['in'])) {
    $pathIn = trim((string)$_POST['in']);
    $partial = (string)($_POST['partial'] ?? '');
    $limit = (int)($_POST['limit'] ?? 30);
    $dir = fmResolveDir($pathIn, $ROOT_DIR);
    jsonOut(fmTerminalComplete($dir, $ROOT_DIR, $FM_ENABLE_TERMINAL_HERE, $partial, $limit));
}

if (isset($_GET['action']) && $_GET['action'] === 'download-status' && isset($_GET['token'])) {
    $token = (string)$_GET['token'];
    if (!FmDlStatus::tokenIsValid($token)) {
        jsonOut(['status' => 'error', 'msg' => 'Invalid download token']);
    }
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }
    jsonOut(['status' => 'success', 'now' => time()] + FmDlStatus::read($token));
}

if (isset($_GET['action']) && $_GET['action'] === 'download' && isset($_GET['path'])) {
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }
    $path = safeRealpath(rawurldecode((string)$_GET['path']));
    $bn = basename($path);
    FmDlStatus::init($bn);
    if (!pathInsideRoot($path, $ROOT_DIR) || !is_file($path)) {
        fmDownloadFail(404, 'The file no longer exists or is outside the file manager root.');
    }
    $fh = @fopen($path, 'rb');
    if ($fh === false) {
        fmDownloadFail(403, 'Cannot read "' . $bn . '": permission denied or the file is locked by another program.');
    }
    $size = (int)filesize($path);
    fmBeginStreamDownload($bn, 'application/octet-stream', $size);
    FmDlStatus::streaming('file', $size);
    $out = new FmStreamOut(false);
    try {
        while (!feof($fh)) {
            $chunk = fread($fh, 1048576);
            if ($chunk === false || $chunk === '') {
                break;
            }
            $out->write($chunk);
        }
        $out->finish();
        if ($out->position() < $size) {
            FmDlStatus::fail('Reading "' . $bn . '" stopped after ' . $out->position() . ' of ' . $size . ' bytes (the file changed or a disk error occurred). The downloaded file is incomplete.');
        } else {
            FmDlStatus::done();
        }
    } catch (FmDlAbortedException $e) {
        FmDlStatus::aborted();
    }
    fclose($fh);
    exit;
}

function fmImageViewMimeForExtension(string $ext): ?string {
    $e = strtolower($ext);
    $map = [
        'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png',
        'gif' => 'image/gif', 'webp' => 'image/webp', 'svg' => 'image/svg+xml',
        'bmp' => 'image/bmp', 'ico' => 'image/x-icon', 'tif' => 'image/tiff',
        'tiff' => 'image/tiff', 'avif' => 'image/avif', 'heic' => 'image/heic',
    ];
    return $map[$e] ?? null;
}

if (isset($_GET['action']) && $_GET['action'] === 'file-view' && isset($_GET['path'])) {
    $path = safeRealpath(rawurldecode((string)$_GET['path']));
    if (!pathInsideRoot($path, $ROOT_DIR) || !is_file($path)) {
        http_response_code(404);
        exit;
    }
    $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    $mime = fmImageViewMimeForExtension($ext);
    if ($mime === null) {
        http_response_code(403);
        exit;
    }
    if (function_exists('mime_content_type')) {
        $detect = @mime_content_type($path);
        if (is_string($detect) && stripos($detect, 'image/') === 0) {
            $mime = $detect;
        }
    } elseif (function_exists('finfo_open')) {
        $fi = @finfo_open(FILEINFO_MIME_TYPE);
        if ($fi !== false) {
            $detect = @finfo_file($fi, $path);
            finfo_close($fi);
            if (is_string($detect) && stripos($detect, 'image/') === 0) {
                $mime = $detect;
            }
        }
    }
    while (ob_get_level()) {
        @ob_end_clean();
    }
    $bn = basename($path);
    header('Content-Type: ' . $mime);
    header('Content-Disposition: inline; filename="' . str_replace(['"', "\r", "\n"], '', $bn) . '"');
    header('Content-Length: ' . (string)filesize($path));
    header('Cache-Control: private, max-age=3600');
    readfile($path);
    exit;
}

if (isset($_POST['action']) && $_POST['action'] === 'download-archive' && isset($_POST['in'], $_POST['archive_name'], $_POST['archive_type'], $_POST['names']) && is_array($_POST['names'])) {
    fmDownloadArchiveHandlePost($ROOT_DIR, $isUnixLikeShell, $isWindows);
}

if (isset($_POST['action']) && $_POST['action'] === 'rename' && isset($_POST['in'], $_POST['name'], $_POST['new_name'])) {
    $dir = fmResolveDir((string)$_POST['in'], $ROOT_DIR);
    if (!pathInsideRoot($dir, $ROOT_DIR)) jsonOut(['status' => 'error', 'msg' => 'Invalid path']);
    $baseDir = rtrim($dir, '/');
    $oldPath = $baseDir . '/' . (string)$_POST['name'];
    $newPath = $baseDir . '/' . (string)$_POST['new_name'];
    if (!pathInsideRoot($oldPath, $ROOT_DIR) || !pathInsideRoot($newPath, $ROOT_DIR)) jsonOut(['status' => 'error', 'msg' => 'Invalid path']);
    if (!file_exists($oldPath)) jsonOut(['status' => 'error', 'msg' => 'File or folder not found']);
    if (file_exists($newPath)) jsonOut(['status' => 'error', 'msg' => 'A file or folder with that name already exists']);
    if (@rename($oldPath, $newPath)) jsonOut(['status' => 'success', 'msg' => 'Renamed']);
    jsonOut(['status' => 'error', 'msg' => 'Rename failed']);
}

if (isset($_POST['action']) && $_POST['action'] === 'bulk-rename' && isset($_POST['in']) && isset($_POST['from']) && isset($_POST['to']) && is_array($_POST['from']) && is_array($_POST['to'])) {
    $dir = fmResolveDir((string)$_POST['in'], $ROOT_DIR);
    if (!pathInsideRoot($dir, $ROOT_DIR)) {
        jsonOut(['status' => 'error', 'msg' => 'Invalid path']);
    }
    $from = $_POST['from'];
    $to = $_POST['to'];
    if (count($from) !== count($to) || count($from) === 0) {
        jsonOut(['status' => 'error', 'msg' => 'Invalid batch']);
    }
    $pairs = [];
    $n = count($from);
    for ($i = 0; $i < $n; $i++) {
        $pairs[] = [(string)$from[$i], (string)$to[$i]];
    }
    $result = fmBulkRenamePairs($dir, $ROOT_DIR, $pairs);
    if ($result['errors'] !== []) {
        jsonOut(['status' => 'error', 'msg' => implode('; ', $result['errors'])]);
    }
    jsonOut(['status' => 'success', 'msg' => 'Renamed', 'ok' => $result['ok']]);
}

if (isset($_POST['action']) && $_POST['action'] === 'create-new-folder' && isset($_POST['in'], $_POST['name'])) {
    $dir = fmResolveDir((string)$_POST['in'], $ROOT_DIR);
    if (!pathInsideRoot($dir, $ROOT_DIR)) jsonOut(['status' => 'error', 'msg' => 'Invalid path']);
    $name = basename((string)$_POST['name']);
    if ($name === '') jsonOut(['status' => 'error', 'msg' => 'Folder name is required']);
    if (preg_match('/[\\\\\/:*?"<>|]/', $name)) jsonOut(['status' => 'error', 'msg' => 'Folder name cannot contain \\ / : * ? " < > |']);
    $folderPath = rtrim($dir, '/') . '/' . $name;
    if (file_exists($folderPath)) jsonOut(['status' => 'error', 'msg' => 'A folder with that name already exists']);
    if (@mkdir($folderPath, 0755)) jsonOut(['status' => 'success', 'msg' => 'Folder created']);
    jsonOut(['status' => 'error', 'msg' => 'Could not create folder']);
}

if (isset($_POST['action']) && $_POST['action'] === 'create-new-file' && isset($_POST['in'], $_POST['name'])) {
    $dir = fmResolveDir((string)$_POST['in'], $ROOT_DIR);
    if (!pathInsideRoot($dir, $ROOT_DIR)) jsonOut(['status' => 'error', 'msg' => 'Invalid path']);
    $name = basename((string)$_POST['name']);
    if ($name === '') jsonOut(['status' => 'error', 'msg' => 'File name is required']);
    if (preg_match('/[\\\\\/:*?"<>|]/', $name)) jsonOut(['status' => 'error', 'msg' => 'File name cannot contain \\ / : * ? " < > |']);
    $filePath = rtrim($dir, '/') . '/' . $name;
    if (file_exists($filePath)) jsonOut(['status' => 'error', 'msg' => 'A file with that name already exists']);
    if (@file_put_contents($filePath, '') !== false) jsonOut(['status' => 'success', 'msg' => 'File created']);
    jsonOut(['status' => 'error', 'msg' => 'Could not create file']);
}

if (
    ((isset($_GET['action']) && $_GET['action'] === 'get-file-content') || (isset($_POST['action']) && $_POST['action'] === 'get-file-content'))
) {
    $dirIn = (string)($_POST['in'] ?? $_GET['in'] ?? '');
    $name  = basename((string)($_POST['name'] ?? $_GET['name'] ?? ''));
    if ($name === '') {
        jsonOut(['status' => 'error', 'msg' => 'File name is required']);
    }
    $dir = fmResolveDir($dirIn, $ROOT_DIR);
    if (!pathInsideRoot($dir, $ROOT_DIR)) {
        jsonOut(['status' => 'error', 'msg' => 'Invalid path']);
    }
    $filePath = safeRealpath(rtrim($dir, '/') . '/' . $name);
    if (!pathInsideRoot($filePath, $ROOT_DIR) || fmIsUnderTrashTree($filePath, $ROOT_DIR)) {
        jsonOut(['status' => 'error', 'msg' => 'Invalid file path']);
    }
    if (!is_file($filePath)) {
        jsonOut(['status' => 'error', 'msg' => 'File not found']);
    }
    if (!is_readable($filePath)) {
        jsonOut(['status' => 'error', 'msg' => 'File is not readable (check permissions)']);
    }
    $maxEditBytes = 10 * 1024 * 1024;
    $size = @filesize($filePath);
    if ($size !== false && $size > $maxEditBytes) {
        jsonOut(['status' => 'error', 'msg' => 'File is too large to edit in the browser (' . fmHumanBytes((int)$size) . ', max 10 MiB).']);
    }
    $content = @file_get_contents($filePath);
    if ($content === false) {
        jsonOut(['status' => 'error', 'msg' => 'Could not read file']);
    }
    if (strpos($content, "\0") !== false) {
        jsonOut(['status' => 'error', 'msg' => 'This appears to be a binary file and cannot be edited as text.']);
    }
    if (function_exists('mb_check_encoding') && !mb_check_encoding($content, 'UTF-8')) {
        if (function_exists('mb_convert_encoding')) {
            $content = @mb_convert_encoding($content, 'UTF-8', 'ISO-8859-1');
        }
    }
    jsonOut([
        'status'   => 'success',
        'name'     => $name,
        'content'  => $content,
        'size'     => $size !== false ? (int)$size : strlen($content),
        'mtime'    => (int)@filemtime($filePath),
        'writable' => is_writable($filePath),
    ]);
}

if (isset($_POST['action']) && $_POST['action'] === 'save-file' && isset($_POST['in'], $_POST['name'])) {
    $dirIn = (string)$_POST['in'];
    $name  = basename((string)$_POST['name']);
    if ($name === '') {
        jsonOut(['status' => 'error', 'msg' => 'File name is required']);
    }
    $dir = fmResolveDir($dirIn, $ROOT_DIR);
    if (!pathInsideRoot($dir, $ROOT_DIR)) {
        jsonOut(['status' => 'error', 'msg' => 'Invalid path']);
    }
    $filePath = safeRealpath(rtrim($dir, '/') . '/' . $name);
    if (!pathInsideRoot($filePath, $ROOT_DIR) || fmIsUnderTrashTree($filePath, $ROOT_DIR)) {
        jsonOut(['status' => 'error', 'msg' => 'Invalid file path']);
    }
    if (!is_file($filePath)) {
        jsonOut(['status' => 'error', 'msg' => 'File not found']);
    }
    if (!is_writable($filePath)) {
        jsonOut(['status' => 'error', 'msg' => 'File is not writable (check permissions)']);
    }
    $content = (string)($_POST['content'] ?? '');
    $written = @file_put_contents($filePath, $content, LOCK_EX);
    if ($written === false) {
        jsonOut(['status' => 'error', 'msg' => 'Could not save file']);
    }
    clearstatcache(true, $filePath);
    jsonOut([
        'status' => 'success',
        'msg'    => 'File saved',
        'name'   => $name,
        'size'   => (int)@filesize($filePath),
        'mtime'  => (int)@filemtime($filePath),
    ]);
}

if (isset($_POST['action']) && $_POST['action'] === 'upload-url' && isset($_POST['in'], $_POST['url'])) {
    $dir = fmResolveDir((string)$_POST['in'], $ROOT_DIR);
    if (!pathInsideRoot($dir, $ROOT_DIR) || !is_dir($dir)) {
        jsonOut(['status' => 'error', 'msg' => 'Invalid destination folder']);
    }
    $url = (string)$_POST['url'];
    $customName = (string)($_POST['filename'] ?? '');
    jsonOut(fmDownloadFromUrl($url, $dir, $customName));
}

if (isset($_POST['action']) && $_POST['action'] === 'upload' && isset($_POST['in'])) {
    $dir = fmResolveDir((string)$_POST['in'], $ROOT_DIR);
    if (!pathInsideRoot($dir, $ROOT_DIR) || !is_dir($dir)) {
        jsonOut(['status' => 'error', 'msg' => 'Invalid upload folder']);
    }

    $fileRows = fmNormalizeUploadedFiles('files');
    if ($fileRows === []) {
        jsonOut(['status' => 'error', 'msg' => 'No files received']);
    }

    $paths = isset($_POST['paths']) ? (array)$_POST['paths'] : [];
    $uploaded = 0;
    $errors = [];
    $baseDir = rtrim($dir, '/');

    foreach ($fileRows as $i => $row) {
        if ($row['error'] !== UPLOAD_ERR_OK) {
            $errors[] = $row['name'] . ': ' . fmUploadErrorMessage($row['error']);
            continue;
        }
        $rawPath = isset($paths[$i]) ? (string)$paths[$i] : $row['name'];
        $clean = fmSanitizeUploadRelativePath($rawPath);
        if ($clean === null) {
            $errors[] = $row['name'] . ': invalid relative path';
            continue;
        }
        $dest = normPath($baseDir . '/' . $clean);
        if (!pathInsideRoot($dest, $ROOT_DIR)) {
            $errors[] = $clean . ': outside allowed root';
            continue;
        }
        $parent = dirname($dest);
        if (!is_dir($parent) && !@mkdir($parent, 0755, true)) {
            $errors[] = $clean . ': could not create parent folder';
            continue;
        }
        if (is_dir($dest)) {
            $errors[] = $clean . ': path is a folder';
            continue;
        }
        if (!@move_uploaded_file($row['tmp_name'], $dest)) {
            $errors[] = $clean . ': could not save file';
            continue;
        }
        $uploaded++;
    }

    if ($uploaded === 0) {
        jsonOut(['status' => 'error', 'msg' => 'Nothing uploaded', 'errors' => $errors]);
    }
    if ($errors !== []) {
        jsonOut([
            'status'   => 'partial',
            'msg'      => 'Some files failed',
            'uploaded' => $uploaded,
            'errors'   => $errors,
        ]);
    }
    jsonOut(['status' => 'success', 'msg' => 'Upload complete', 'uploaded' => $uploaded]);
}

if (isset($_POST['action']) && $_POST['action'] === 'compress' && isset($_POST['in'], $_POST['names'], $_POST['archive_name'], $_POST['archive_type']) && is_array($_POST['names'])) {
    global $serverCapabilities;
    $dir = fmResolveDir((string)$_POST['in'], $ROOT_DIR);
    $type = (string)$_POST['archive_type'];

    while (ob_get_level()) { @ob_end_flush(); }
    header('Content-Type: application/x-ndjson');
    header('Cache-Control: no-cache');
    @fm_ob_implicit_flush_enable();

    $send = function (array $payload) {
        echo json_encode($payload, JSON_UNESCAPED_SLASHES) . "\n";
        @flush();
    };
    $sendError = function (string $msg) use ($send) {
        $send(['error' => $msg]);
        $send(['done' => true]);
        exit;
    };

    if (!pathInsideRoot($dir, $ROOT_DIR)) $sendError('Invalid path');
    if (!in_array($type, ['zip', 'tar', 'gzip'], true)) $sendError('Invalid archive type');
    if (fmFileOpsMode() === 'os' && !$serverCapabilities['exec_available']) {
        $sendError('File ops mode is OS-only but exec() is disabled. Set $FM_FILE_OPS_MODE to "auto" or "php".');
    }

    $baseName = trim((string)$_POST['archive_name']);
    $baseName = preg_replace('/[\\\\\/:*?"<>|]/', '', $baseName);
    if ($baseName === '') $sendError('Archive name is required');

    $ext = ['zip' => '.zip', 'tar' => '.tar', 'gzip' => '.tar.gz'][$type];
    if (substr($baseName, -strlen($ext)) !== $ext) {
        $baseName .= $ext;
    }

    $baseDir = rtrim($dir, '/');
    $destPath = $baseDir . '/' . $baseName;

    $names = [];
    foreach ((array)$_POST['names'] as $n) {
        $n = basename((string)$n);
        if ($n === '' || $n === '.' || $n === '..') continue;
        if (preg_match('/[\\\\\/:*?"<>|]/', $n)) continue;
        $full = $baseDir . '/' . $n;
        if (file_exists($full) && pathInsideRoot($full, $ROOT_DIR)) {
            $names[] = $n;
        }
    }
    if (count($names) === 0) $sendError('No valid items to archive');

    $overwrite = isset($_POST['overwrite']) && ((string)$_POST['overwrite'] === '1' || (string)$_POST['overwrite'] === 'true');
    if (file_exists($destPath)) {
        if (!$overwrite) {
            $sendError('Archive already exists. Enable overwrite to replace it.');
        }
        if (is_dir($destPath)) {
            $sendError('A folder with that name already exists');
        }
        if (!@unlink($destPath)) {
            $sendError('Could not overwrite existing archive');
        }
    }

    $providedTotal = null;
    if (isset($_POST['total']) && $_POST['total'] !== '') {
        $providedTotal = (int)$_POST['total'];
        if ($providedTotal < 0) $providedTotal = 0;
    }

    $total = is_int($providedTotal) && $providedTotal > 0 ? $providedTotal : 0;

    $tryShell         = fmFileOpsTryShell();
    $allowPhpFallback = fmFileOpsAllowPhpFallback();

    $processed = 0;
    $estForProgress = $total > 0 ? $total : max(count($names), 1);
    $send(array_merge(['total' => $total, 'n' => 0, 'name' => ''], fmStreamProgressMeta($estForProgress, !$tryShell)));

    $shellAttempted = false;
    try {
        if ($tryShell && $isUnixLikeShell) {
            if ($type === 'zip') {
                exec('zip -v 2>&1', $out, $ret);
                $zipOk = ($ret === 0);
                if ($zipOk) {
                    $shellAttempted = true;
                    $dirEsc = escapeshellarg($dir);
                    $destEsc = escapeshellarg($destPath);
                    $itemsEsc = '';
                    foreach ($names as $n) {
                        $itemsEsc .= ' ' . escapeshellarg($n);
                    }

                    $cmd = 'cd ' . $dirEsc . ' && zip -r -q ' . $destEsc . $itemsEsc . ' 2>&1';
                    exec($cmd, $out2, $ret2);
                    if ($ret2 !== 0) {
                        throw new RuntimeException('zip failed: ' . trim(implode("\n", (array)$out2)));
                    }

                    $processed = $total;
                    $send(['n' => $processed, 'name' => '']);
                    $send(['done' => true]);
                    exit;
                }
            }

            if ($type === 'tar' || $type === 'gzip') {
                exec('tar --version 2>&1', $out, $ret);
                $tarOk = ($ret === 0);
                exec('gzip --version 2>&1', $outGz, $retGz);
                $gzipOk = ($retGz === 0);

                if ($tarOk && ($type !== 'gzip' || $gzipOk)) {
                    $shellAttempted = true;

                    if ($type === 'gzip') {
                        $tarPath = $baseDir . '/' . basename($baseName, '.tar.gz') . '.tar';
                    } else {
                        $tarPath = $destPath;
                    }
                    if (file_exists($tarPath)) @unlink($tarPath);

                    $dirEsc = escapeshellarg($dir);
                    $tarEsc = escapeshellarg($tarPath);

                    $itemsEsc = '';
                    foreach ($names as $n) {
                        $itemsEsc .= ' ' . escapeshellarg($n);
                    }

                    $cmd = 'cd ' . $dirEsc . ' && tar -cf ' . $tarEsc . $itemsEsc . ' 2>&1';
                    exec($cmd, $out2, $ret2);
                    if ($ret2 !== 0) {
                        throw new RuntimeException('tar failed: ' . trim(implode("\n", (array)$out2)));
                    }

                    $processed = $total;

                    if ($type === 'gzip') {
                        $cmd = 'gzip -f ' . $tarEsc . ' 2>&1';
                        exec($cmd, $out3, $ret3);
                        if ($ret3 !== 0) {
                            throw new RuntimeException('gzip failed: ' . trim(implode("\n", (array)$out3)));
                        }
                    }

                    $send(['n' => $processed, 'name' => '']);
                    $send(['done' => true]);
                    exit;
                }
            }
        }

        if ($tryShell && $isWindows) {
            if ($type === 'tar' || $type === 'gzip') {
                exec('tar --version 2>&1', $outT, $retT);
                $tarOk = ($retT === 0);
                if ($tarOk) {
                    $shellAttempted = true;
                    $tarPath = $destPath;
                    $dirWin = str_replace('/', '\\', $dir);
                    $tarEsc = escapeshellarg(str_replace('/', '\\', $tarPath));
                    $dirEsc = escapeshellarg($dirWin);

                    $itemsEsc = '';
                    foreach ($names as $n) {
                        $itemsEsc .= ' ' . escapeshellarg($n);
                    }

                    if ($type === 'tar') {
                        $cmd = 'cd ' . $dirEsc . ' && tar -cf ' . $tarEsc . $itemsEsc . ' 2>&1';
                    } else {
                        $cmd = 'cd ' . $dirEsc . ' && tar -czf ' . $tarEsc . $itemsEsc . ' 2>&1';
                    }
                    exec($cmd, $out2, $ret2);
                    if ($ret2 !== 0) {
                        throw new RuntimeException('tar failed: ' . trim(implode("\n", (array)$out2)));
                    }

                    $processed = $total;
                    $send(['n' => $processed, 'name' => '']);
                    $send(['done' => true]);
                    exit;
                }
            }

            if ($type === 'zip') {
                exec('tar --version 2>&1', $outT, $retT);
                $tarOk = ($retT === 0);
                if ($tarOk) {
                    $shellAttempted = true;
                    $tarPath = $destPath;
                    $dirWin = str_replace('/', '\\', $dir);
                    $tarEsc = escapeshellarg(str_replace('/', '\\', $tarPath));
                    $dirEsc = escapeshellarg($dirWin);
                    $itemsEsc = '';
                    foreach ($names as $n) {
                        $itemsEsc .= ' ' . escapeshellarg($n);
                    }
                    $cmd = 'cd ' . $dirEsc . ' && tar -a -c -f ' . $tarEsc . $itemsEsc . ' 2>&1';
                    exec($cmd, $out2, $ret2);
                    if ($ret2 !== 0) {
                        throw new RuntimeException('tar failed: ' . trim(implode("\n", (array)$out2)));
                    }
                    $processed = $total;
                    $send(['n' => $processed, 'name' => '']);
                    $send(['done' => true]);
                    exit;
                }

                exec('powershell -NoProfile -NonInteractive -Command "exit 0" 2>&1', $out, $ret);
                $psOk = ($ret === 0);
                if ($psOk) {
                    $shellAttempted = true;
                    $destWin = str_replace('/', '\\', $destPath);
                    $useCreateFromDirectory = (count($names) === 1);
                    if ($useCreateFromDirectory) {
                        $srcFull = $baseDir . '/' . $names[0];
                        $useCreateFromDirectory = is_dir($srcFull);
                    }

                    if ($useCreateFromDirectory) {
                        $srcFull = $baseDir . '/' . $names[0];
                        $srcWin = str_replace('/', '\\', $srcFull);
                        $srcPs = str_replace("'", "''", $srcWin);
                        $destPs = str_replace("'", "''", $destWin);
                        $psScript = 'Add-Type -AssemblyName System.IO.Compression.FileSystem; ' .
                            '[System.IO.Compression.ZipFile]::CreateFromDirectory(' .
                            '\'' . $srcPs . '\',' .
                            '\'' . $destPs . '\')';
                        $cmd = 'powershell -NoProfile -NonInteractive -Command ' . escapeshellarg($psScript) . ' 2>&1';
                        exec($cmd, $out2, $ret2);
                        if ($ret2 !== 0) {
                            throw new RuntimeException('ZipFile.CreateFromDirectory failed: ' . trim(implode("\n", (array)$out2)));
                        }
                    } else {
                        $psDest = '"' . addslashes($destWin) . '"';
                        $paths = [];
                        foreach ($names as $n) {
                            $full = $baseDir . '/' . $n;
                            $fullWin = str_replace('/', '\\', $full);
                            $paths[] = '"' . addslashes($fullWin) . '"';
                        }
                        $psPaths = implode(',', $paths);
                        $cmd = 'powershell -NoProfile -NonInteractive -Command "Compress-Archive -Path ' . $psPaths . ' -DestinationPath ' . $psDest . ' -Force -CompressionLevel Fastest" 2>&1';
                        exec($cmd, $out2, $ret2);
                        if ($ret2 !== 0) {
                            throw new RuntimeException('Compress-Archive failed: ' . trim(implode("\n", (array)$out2)));
                        }
                    }

                    $processed = $total;
                    $send(['n' => $processed, 'name' => '']);
                    $send(['done' => true]);
                    exit;
                }
            }
        }
    } catch (Throwable $e) {
        if ($shellAttempted) {
            @unlink($destPath);
        }
        if (!$allowPhpFallback) {
            $sendError('Archive failed (OS only): ' . $e->getMessage());
        }
    }

    if ($allowPhpFallback) {
        try {
            $send(array_merge(
                ['phase' => 'php', 'msg' => 'Building archive in PHP…'],
                fmStreamProgressMeta($estForProgress, true)
            ));
            $lastProgressAt = microtime(true);
            $progressMinInterval = 0.2;
            $progressEvery = 250;
            $sendProgress = function () use ($send, &$processed, &$lastProgressAt, $progressEvery, $progressMinInterval) {
                $now = microtime(true);
                $timeOk = ($now - $lastProgressAt) >= $progressMinInterval;
                $countOk = ($progressEvery > 0) && ($processed % $progressEvery === 0);
                if ($timeOk || $countOk) {
                    $lastProgressAt = $now;
                    $send(['n' => $processed]);
                }
            };

            if ($type === 'zip') {
                $zip = new ZipArchive();
                if ($zip->open($destPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
                    throw new RuntimeException('Could not create archive');
                }
                $send(['phase' => 'adding', 'msg' => 'Adding files and folders structure…']);
                foreach ($names as $n) {
                    $full = $baseDir . '/' . $n;
                    if (is_file($full)) {
                        $zip->addFile($full, $n);
                        $processed++;
                        $sendProgress();
                    } elseif (is_dir($full)) {
                        $it = new RecursiveIteratorIterator(
                            new RecursiveDirectoryIterator($full, RecursiveDirectoryIterator::SKIP_DOTS)
                        );
                        foreach ($it as $fi) {
                            $path = $fi->getPathname();
                            $local = $n . '/' . substr($path, strlen($baseDir . '/' . $n) + 1);
                            $local = str_replace('\\', '/', $local);

                            if ($fi->isDir()) {
                                $zip->addEmptyDir($local);
                            } else {
                                $zip->addFile($path, $local);
                            }

                            $processed++;
                            $sendProgress();
                        }
                    }
                }

                $send(['n' => $processed, 'name' => '']);
                $send(['phase' => 'compressing', 'msg' => 'Archiving (this may take a while)…']);
                $zip->close();
                $send(['n' => $processed, 'name' => '']);
            } else {
                if ($type === 'gzip') {
                    $baseTar = $baseDir . '/' . preg_replace('/\.tar\.gz$/i', '', $baseName) . '.tar';
                    $tarPath = $baseTar;
                    $i = 1;
                    while (file_exists($tarPath) && $i < 1000) {
                        $tarPath = $baseDir . '/' . preg_replace('/\.tar\.gz$/i', '', $baseName) . "_{$i}.tar";
                        $i++;
                    }
                    if (file_exists($tarPath)) {
                        throw new RuntimeException('Could not create .tar file for gzip: a unique tar archive could not be found.');
                    }
                } else {
                    $tarPath = $destPath;
                }

                if (file_exists($tarPath)) @unlink($tarPath);

                $phar = new PharData($tarPath);
                $send(['phase' => 'adding', 'msg' => 'Adding files and folders structure…']);

                foreach ($names as $n) {
                    $full = $baseDir . '/' . $n;
                    if (is_file($full)) {
                        $phar->addFile($full, $n);
                        $processed++;
                        $sendProgress();
                    } elseif (is_dir($full)) {
                        if (is_dir($full)) {
                            $phar->buildFromDirectory($full);
                            $rii = new RecursiveIteratorIterator(
                                new RecursiveDirectoryIterator($full, RecursiveDirectoryIterator::SKIP_DOTS)
                            );
                            foreach ($rii as $fi) {
                                $processed++;
                                $sendProgress();
                            }
                        }
                    }
                }
                $send(['n' => $processed]);

                if ($type === 'gzip') {
                    $send(['phase' => 'compressing', 'msg' => 'Writing GZIP (this may take a while)…']);
                    $phar->compress(Phar::GZ);
                    @unlink($tarPath);
                }
                if ($type === 'tar') {
                    sleep(1);
                    $send(['phase' => 'compressing', 'msg' => 'Finalizing TAR archive (this may take a while)…']);
                    sleep(3);
                }
            }

            $send(['done' => true]);
        } catch (Throwable $e) {
            $sendError('Could not create archive: ' . $e->getMessage());
        }
        exit;
    }

    $sendError('Archive could not be created using OS commands only (tools missing or not supported for this type).');
    exit;
}

if (isset($_POST['action']) && $_POST['action'] === 'extract' && isset($_POST['in'], $_POST['names']) && is_array($_POST['names'])) {
    global $serverCapabilities;
    $dir = fmResolveDir((string)$_POST['in'], $ROOT_DIR);

    while (ob_get_level()) {
        @ob_end_flush();
    }
    header('Content-Type: application/x-ndjson');
    header('Cache-Control: no-cache');
    @fm_ob_implicit_flush_enable();

    $send = function (array $payload): void {
        echo json_encode($payload, JSON_UNESCAPED_SLASHES) . "\n";
        @flush();
    };
    $sendError = function (string $msg) use ($send): void {
        $send(['error' => $msg]);
        $send(['done' => true]);
        exit;
    };

    if (!pathInsideRoot($dir, $ROOT_DIR)) {
        $sendError('Invalid path');
    }

    if (fmFileOpsMode() === 'os' && !$serverCapabilities['exec_available']) {
        $sendError('File ops mode is OS-only but exec() is disabled. Set $FM_FILE_OPS_MODE to "auto" or "php".');
    }

    @ini_set('phar.readonly', '0');

    $separate = isset($_POST['separate_folders']) && (string)$_POST['separate_folders'] === '1';
    $tryShell = fmFileOpsTryShell();
    $allowPhpFallback = fmFileOpsAllowPhpFallback();

    $baseDir = rtrim($dir, '/');
    $archives = [];
    foreach ((array)$_POST['names'] as $n) {
        $n = basename((string)$n);
        if ($n === '' || $n === '.' || $n === '..') {
            continue;
        }
        if (preg_match('/[\\\\\/:*?"<>|]/', $n)) {
            continue;
        }
        $atype = fmArchiveTypeFromBasename($n);
        if ($atype === null) {
            continue;
        }
        $full = $baseDir . '/' . $n;
        if (!is_file($full) || !pathInsideRoot($full, $ROOT_DIR)) {
            continue;
        }
        $archives[] = ['name' => $n, 'path' => $full, 'type' => $atype];
    }

    if (count($archives) === 0) {
        $sendError('No supported archives selected (use .zip, .tar, or .tar.gz / .tgz)');
    }

    foreach ($archives as &$a) {
        $a['entryCount'] = max(1, fmCountArchiveEntries($a['path'], $a['type']));
    }
    unset($a);

    $grandTotal = 0;
    foreach ($archives as $a) {
        $grandTotal += $a['entryCount'];
    }

    $processed = 0;
    $send(array_merge(['total' => $grandTotal, 'n' => 0], fmStreamProgressMeta($grandTotal, !$tryShell)));

    $lastProgressAt = microtime(true);
    $progressMinInterval = 0.18;
    $progressEvery = 200;
    $sendProgress = function () use ($send, &$processed, &$lastProgressAt, $progressEvery, $progressMinInterval, $grandTotal): void {
        $now = microtime(true);
        $timeOk = ($now - $lastProgressAt) >= $progressMinInterval;
        $countOk = ($progressEvery > 0) && ($processed % $progressEvery === 0);
        if ($timeOk || $countOk || $processed >= $grandTotal) {
            $lastProgressAt = $now;
            $send(['n' => $processed, 'total' => $grandTotal]);
        }
    };

    $phpExtractOne = function (string $archivePath, string $destDir, string $type, int $entryCount) use (&$processed, $sendProgress, $sendError, $ROOT_DIR, $serverCapabilities): void {
        if (!is_dir($destDir)) {
            if (!@mkdir($destDir, 0775, true)) {
                $sendError('Could not create folder: ' . basename($destDir));
            }
        }
        $destReal = realpath($destDir);
        if ($destReal === false || !pathInsideRoot($destReal, $ROOT_DIR)) {
            $sendError('Invalid extract destination');
        }
        $destApi = fmFsPathForArchiveApi($destReal);

        if ($type === 'zip') {
            if (!isset($serverCapabilities['zip_archive']) || !$serverCapabilities['zip_archive']) {
                $sendError('ZIP extraction requires ZipArchive');
            }
            $zip = new ZipArchive();
            $flags = defined('ZipArchive::RDONLY') ? ZipArchive::RDONLY : 0;
            if ($zip->open($archivePath, $flags) !== true && $zip->open($archivePath) !== true) {
                $sendError('Could not open ZIP archive');
            }
            $entries = [];
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $stat = $zip->statIndex($i);
                if ($stat === false) {
                    continue;
                }
                $raw = $stat['name'];
                $entry = ltrim(str_replace('\\', '/', $raw), '/');
                if ($entry === '' && substr(str_replace('\\', '/', $raw), -1) !== '/') {
                    continue;
                }
                if (!fmArchiveInternalPathAllowed($entry)) {
                    continue;
                }
                $entries[] = $raw;
            }
            if (count($entries) === 0) {
                $zip->close();
                $sendError('No extractable entries in ZIP (or paths were rejected for safety)');
            }
            if (!$zip->extractTo($destApi, $entries)) {
                $zip->close();
                $sendError('ZIP extraction failed');
            }
            $processed += $entryCount;
            $sendProgress();
            $zip->close();
            return;
        }

        $phar = fmOpenPharDataForRead($archivePath, $type);
        if ($phar === null) {
            $sendError('Could not open TAR archive (enable Phar extension or check file format)');
        }
        try {
            if (!$phar->extractTo($destApi, null, true, true)) {
                $sendError('TAR extraction failed');
            }
            $processed += $entryCount;
            $sendProgress();
        } catch (Throwable $e) {
            $sendError('Could not extract archive: ' . $e->getMessage());
        }
    };

    $shellExtractOne = function (string $archivePath, string $destDir, string $type) use ($isUnixLikeShell, $isWindows): bool {
        if (!is_dir($destDir)) {
            if (!@mkdir($destDir, 0775, true)) {
                return false;
            }
        }
        $archEsc = escapeshellarg($archivePath);
        $destEsc = escapeshellarg($destDir);
        $out = [];
        $ret = 1;
        if ($type === 'zip') {
            $cmd = $isUnixLikeShell 
            ? 'unzip -o -q ' . $archEsc . ' -d ' . $destEsc . ' 2>&1'
            : 'tar -xf ' . $archEsc . ' -C ' . $destEsc . ' 2>&1';
        } else {
            $cmd = 'tar -x' . ($type === 'tgz' ? 'z' : '') . 'f ' . $archEsc . ' -C ' . $destEsc . ' 2>&1';
        }
        exec($cmd, $out, $ret);
        return $ret === 0;
    };

    try {
        $highlightNames = [];
        foreach ($archives as $a) {
            $base = $a['name'];
            $apath = $a['path'];
            $atype = $a['type'];

            if ($separate) {
                $sub = fmArchiveFolderBasename($base);
                $sub = preg_replace('/[\\\\\/:*?"<>|]/', '', $sub);
                if ($sub === '' || $sub === '.' || $sub === '..') {
                    $sendError('Invalid folder name for: ' . $base);
                }
                $destDir = $baseDir . '/' . $sub;
                $highlightNames[] = $sub;
            } else {
                $destDir = $dir;
            }

            if ($separate && file_exists($destDir)) {
                if (!is_dir($destDir)) {
                    $sendError('Cannot extract to ' . $sub . ': a file with that name exists');
                }
                $empty = !(new FilesystemIterator($destDir))->valid();
                if (!$empty) {
                    $sendError('Folder already exists and is not empty: ' . $sub);
                }
            }

            $entryCount = $a['entryCount'];
            $shellOk = false;
            if ($tryShell) {
                $shellOk = $shellExtractOne($apath, $destDir, $atype);
            }

            if ($shellOk) {
                $processed += $entryCount;
                $sendProgress();
                continue;
            }

            if (!$allowPhpFallback) {
                $sendError('Extraction failed (OS only): ' . $base);
            }

            $send(array_merge(
                [
                    'n' => $processed,
                    'total' => $grandTotal,
                    'phase' => 'php',
                    'msg' => 'Extracting via PHP: ' . $base,
                ],
                fmStreamProgressMeta($grandTotal, true)
            ));

            $phpExtractOne($apath, $destDir, $atype, $entryCount);
        }

        $send(['n' => $processed, 'total' => $grandTotal]);
        $send(['done' => true, 'highlight' => array_values(array_unique($highlightNames))]);
    } catch (Throwable $e) {
        $sendError($e->getMessage());
    }
    exit;
}

if (isset($_POST['action']) && $_POST['action'] === 'get-files-and-folders-count') {
    if (isset($_POST['count_abs_path']) && is_string($_POST['count_abs_path']) && $_POST['count_abs_path'] !== '') {
        $path = safeRealpath(rawurldecode((string)$_POST['count_abs_path']));
        if (!pathInsideRoot($path, $ROOT_DIR) || !is_dir($path)) {
            jsonOut(['status' => 'error', 'msg' => 'Invalid path', 'files' => 0, 'folders' => 0, 'total' => 0]);
        }
        $counts = countPathFiles($path, $ROOT_DIR);
        jsonOut([
            'status'  => 'success',
            'files'   => $counts['files'],
            'folders' => $counts['folders'],
            'total'   => $counts['total'],
        ]);
    }
    if (!isset($_POST['in']) || !isset($_POST['names']) || !is_array($_POST['names'])) {
        jsonOut(['status' => 'error', 'msg' => 'Invalid request', 'files' => 0, 'folders' => 0, 'total' => 0]);
    }
    $dir = fmResolveDir((string)$_POST['in'], $ROOT_DIR);
    if (!pathInsideRoot($dir, $ROOT_DIR)) jsonOut(['status' => 'error', 'msg' => 'Invalid path']);

    $baseDir = rtrim($dir, '/');
    $totalFiles = 0;
    $totalFolders = 0;
    foreach ($_POST['names'] as $name) {
        $name = basename((string)$name);
        if ($name === '' || $name === '.' || $name === '..') continue;
        if (preg_match('/[\\\\\/:*?"<>|]/', $name)) continue;
        $fullPath = $baseDir . '/' . $name;
        $counts = countPathFiles($fullPath, $ROOT_DIR);
        $totalFiles   += $counts['files'];
        $totalFolders += $counts['folders'];
    }
    $total = $totalFiles + $totalFolders;
    jsonOut([
        'status'  => 'success',
        'files'   => $totalFiles,
        'folders' => $totalFolders,
        'total'   => $total
    ]);
}

if (isset($_POST['action']) && $_POST['action'] === 'copy-move-stream' && isset($_POST['operation'], $_POST['in'], $_POST['dest']) && isset($_POST['names']) && is_array($_POST['names'])) {
    $operation = (string)$_POST['operation'];
    if (!in_array($operation, ['copy', 'move'], true)) {
        jsonOut(['status' => 'error', 'msg' => 'Invalid operation']);
    }

    $srcDir = fmResolveDir((string)$_POST['in'], $ROOT_DIR);
    if (!pathInsideRoot($srcDir, $ROOT_DIR) || !is_dir($srcDir)) {
        jsonOut(['status' => 'error', 'msg' => 'Invalid source folder']);
    }

    $destDir = fmResolveDir((string)$_POST['dest'], $ROOT_DIR);
    if (!pathInsideRoot($destDir, $ROOT_DIR) || !is_dir($destDir)) {
        jsonOut(['status' => 'error', 'msg' => 'Invalid destination folder']);
    }

    $names = [];
    foreach ($_POST['names'] as $n) {
        $n = basename((string)$n);
        if ($n === '' || $n === '.' || $n === '..') {
            continue;
        }
        if (preg_match('/[\\\\\/:*?"<>|]/', $n)) {
            continue;
        }
        $names[] = $n;
    }
    if (count($names) === 0) {
        jsonOut(['status' => 'error', 'msg' => 'Nothing to copy or move']);
    }

    while (ob_get_level()) {
        @ob_end_flush();
    }
    header('Content-Type: application/x-ndjson');
    header('Cache-Control: no-cache');
    fm_ob_implicit_flush_enable();

    $send = function (array $payload): void {
        echo json_encode($payload, JSON_UNESCAPED_SLASHES) . "\n";
        if (ob_get_level()) {
            ob_flush();
        }
        flush();
    };
    $sendError = function (string $msg) use ($send): void {
        $send(['error' => $msg]);
        $send(['done' => true]);
        exit;
    };

    $rootNorm = rtrim(safeRealpath($ROOT_DIR), '/');
    $execOk = fmIsExecAvailable();
    if (fmFileOpsMode() === 'os' && !$execOk) {
        $sendError('File ops mode is OS-only but exec() is disabled. Set $FM_FILE_OPS_MODE to "auto" or "php".');
    }
    $tryShell         = fmFileOpsTryShell();
    $allowPhpFallback = fmFileOpsAllowPhpFallback();

    $baseSrc = rtrim($srcDir, '/');
    $baseDst = rtrim($destDir, '/');
    $totalEstimate = 0;
    foreach ($names as $name) {
        $sp = $baseSrc . '/' . $name;
        if (!pathInsideRoot($sp, $ROOT_DIR) || !file_exists($sp)) {
            continue;
        }
        $c = countPathFiles($sp, $ROOT_DIR);
        $totalEstimate += $c['total'];
    }
    if ($totalEstimate < 1) {
        $totalEstimate = count($names);
    }

    $vpCopyMove = fmStreamProgressMeta($totalEstimate, !$tryShell);
    if ($tryShell) {
        $send(array_merge([
            'total'         => 0,
            'indeterminate' => true,
            'msg'           => ($operation === 'move' ? 'Moving' : 'Copying')
                . ($allowPhpFallback ? ' via OS…' : ' via OS only…'),
        ], $vpCopyMove));
    } else {
        $send(array_merge(['total' => $totalEstimate, 'n' => 0, 'name' => ''], $vpCopyMove));
    }

    $count = 0;
    $lastFlush = 0.0;
    $progressMinInterval = 0.25;
    $verboseCopyMove = $vpCopyMove['verbose_progress'];
    $phpProgress = function (int $n, ?string $currentPath) use (&$lastFlush, $progressMinInterval, $send, $totalEstimate, $rootNorm, &$verboseCopyMove): void {
        $now = microtime(true);
        if (($now - $lastFlush) < $progressMinInterval && $n % 200 !== 0) {
            return;
        }
        $lastFlush = $now;
        $relName = '';
        if ($verboseCopyMove && $currentPath !== null) {
            $norm = normPath($currentPath);
            $relName = strpos($norm, $rootNorm) === 0
                ? ltrim(substr($norm, strlen($rootNorm)), '/')
                : basename($currentPath);
        }
        $send(['n' => $n, 'name' => $relName, 'total' => $totalEstimate]);
    };

    foreach ($names as $name) {
        $srcPath = $baseSrc . '/' . $name;
        $dstPath = $baseDst . '/' . $name;
        if (!pathInsideRoot($srcPath, $ROOT_DIR) || !file_exists($srcPath)) {
            $sendError('Source not found: ' . $name);
        }
        if (!pathInsideRoot($dstPath, $ROOT_DIR)) {
            $sendError('Invalid destination for: ' . $name);
        }
        if (file_exists($dstPath)) {
            $sendError('Already exists in destination: ' . $name);
        }
        if ($srcPath === $dstPath) {
            $sendError('Source and destination are the same: ' . $name);
        }
        if (is_dir($srcPath)) {
            if (pathIsStrictDescendant($dstPath, $srcPath)) {
                $sendError('Cannot place a folder inside itself: ' . $name);
            }
            if ($operation === 'move' && pathIsStrictDescendant($destDir, $srcPath)) {
                $sendError('Cannot move a folder into its own subfolder: ' . $name);
            }
        }

        if ($tryShell) {
            $ok = $operation === 'move'
                ? osMoveEntry($srcPath, $dstPath, $ROOT_DIR)
                : osCopyEntry($srcPath, $dstPath, $ROOT_DIR);
            if ($ok) {
                continue;
            }
            if (!$allowPhpFallback) {
                $sendError(($operation === 'move' ? 'Move' : 'Copy') . ' failed (OS only): ' . $name);
            }
            $vpPhp = fmStreamProgressMeta($totalEstimate, true);
            $verboseCopyMove = $vpPhp['verbose_progress'];
            $send(array_merge(
                ['total' => $totalEstimate, 'n' => $count, 'name' => '', 'fallback' => 'php'],
                $vpPhp
            ));
        }

        if ($operation === 'copy') {
            if (!copyPathPhp($srcPath, $dstPath, $ROOT_DIR, $count, $phpProgress)) {
                $sendError('Copy failed: ' . $name);
            }
        } else {
            if (!movePathPhp($srcPath, $dstPath, $ROOT_DIR, $count, $phpProgress)) {
                $sendError('Move failed: ' . $name);
            }
        }
    }

    $send(['done' => true]);
    exit;
}

if (isset($_POST['action']) && $_POST['action'] === 'new-folder-from-selection-stream' && isset($_POST['in'], $_POST['name']) && isset($_POST['names']) && is_array($_POST['names'])) {
    $streamErr = static function (string $msg): void {
        while (ob_get_level()) {
            @ob_end_flush();
        }
        header('Content-Type: application/x-ndjson');
        header('Cache-Control: no-cache');
        echo json_encode(['error' => $msg], JSON_UNESCAPED_SLASHES) . "\n";
        echo json_encode(['done' => true], JSON_UNESCAPED_SLASHES) . "\n";
        exit;
    };

    $srcDir = fmResolveDir((string)$_POST['in'], $ROOT_DIR);
    if (!pathInsideRoot($srcDir, $ROOT_DIR) || !is_dir($srcDir)) {
        $streamErr('Invalid folder');
    }

    $folderName = basename((string)$_POST['name']);
    if ($folderName === '' || preg_match('/[\\\\\/:*?"<>|]/', $folderName)) {
        $streamErr('Invalid new folder name');
    }

    $names = [];
    foreach ($_POST['names'] as $n) {
        $n = basename((string)$n);
        if ($n === '' || $n === '.' || $n === '..') {
            continue;
        }
        if (preg_match('/[\\\\\/:*?"<>|]/', $n)) {
            continue;
        }
        $names[] = $n;
    }
    if (count($names) === 0) {
        $streamErr('Nothing to move');
    }

    foreach ($names as $n) {
        if ($n === $folderName) {
            $streamErr('The new folder name matches a selected item; choose a different name.');
        }
    }

    $baseSrc = rtrim($srcDir, '/');
    $newFolderPath = $baseSrc . '/' . $folderName;
    if (file_exists($newFolderPath)) {
        $streamErr('A file or folder with that name already exists');
    }

    foreach ($names as $n) {
        $p = $baseSrc . '/' . $n;
        if (!pathInsideRoot($p, $ROOT_DIR) || !file_exists($p)) {
            $streamErr('Source not found: ' . $n);
        }
    }

    while (ob_get_level()) {
        @ob_end_flush();
    }
    header('Content-Type: application/x-ndjson');
    header('Cache-Control: no-cache');
    fm_ob_implicit_flush_enable();

    $send = function (array $payload): void {
        echo json_encode($payload, JSON_UNESCAPED_SLASHES) . "\n";
        if (ob_get_level()) {
            ob_flush();
        }
        flush();
    };
    $sendError = function (string $msg) use ($send): void {
        $send(['error' => $msg]);
        $send(['done' => true]);
        exit;
    };

    if (!@mkdir($newFolderPath, 0755)) {
        $sendError('Could not create folder');
    }

    $destDir = safeRealpath($newFolderPath);
    if ($destDir === false || !is_dir($destDir) || !pathInsideRoot($destDir, $ROOT_DIR)) {
        $sendError('Could not create folder');
    }

    $rootNorm = rtrim(safeRealpath($ROOT_DIR), '/');
    $execOk = fmIsExecAvailable();
    if (fmFileOpsMode() === 'os' && !$execOk) {
        $sendError('File ops mode is OS-only but exec() is disabled. Set $FM_FILE_OPS_MODE to "auto" or "php".');
    }
    $tryShell         = fmFileOpsTryShell();
    $allowPhpFallback = fmFileOpsAllowPhpFallback();

    $totalEstimate = 0;
    foreach ($names as $name) {
        $sp = $baseSrc . '/' . $name;
        if (!pathInsideRoot($sp, $ROOT_DIR) || !file_exists($sp)) {
            continue;
        }
        $c = countPathFiles($sp, $ROOT_DIR);
        $totalEstimate += $c['total'];
    }
    if ($totalEstimate < 1) {
        $totalEstimate = count($names);
    }

    $vpCopyMove = fmStreamProgressMeta($totalEstimate, !$tryShell);
    if ($tryShell) {
        $send(array_merge([
            'total'         => 0,
            'indeterminate' => true,
            'msg'           => 'Moving' . ($allowPhpFallback ? ' via OS…' : ' via OS only…'),
        ], $vpCopyMove));
    } else {
        $send(array_merge(['total' => $totalEstimate, 'n' => 0, 'name' => ''], $vpCopyMove));
    }

    $count = 0;
    $lastFlush = 0.0;
    $progressMinInterval = 0.25;
    $verboseCopyMove = $vpCopyMove['verbose_progress'];
    $phpProgress = function (int $n, ?string $currentPath) use (&$lastFlush, $progressMinInterval, $send, $totalEstimate, $rootNorm, &$verboseCopyMove): void {
        $now = microtime(true);
        if (($now - $lastFlush) < $progressMinInterval && $n % 200 !== 0) {
            return;
        }
        $lastFlush = $now;
        $relName = '';
        if ($verboseCopyMove && $currentPath !== null) {
            $norm = normPath($currentPath);
            $relName = strpos($norm, $rootNorm) === 0
                ? ltrim(substr($norm, strlen($rootNorm)), '/')
                : basename($currentPath);
        }
        $send(['n' => $n, 'name' => $relName, 'total' => $totalEstimate]);
    };

    $baseDst = rtrim($destDir, '/');
    foreach ($names as $name) {
        $srcPath = $baseSrc . '/' . $name;
        $dstPath = $baseDst . '/' . $name;
        if (!pathInsideRoot($srcPath, $ROOT_DIR) || !file_exists($srcPath)) {
            $sendError('Source not found: ' . $name);
        }
        if (!pathInsideRoot($dstPath, $ROOT_DIR)) {
            $sendError('Invalid destination for: ' . $name);
        }
        if (file_exists($dstPath)) {
            $sendError('Already exists in destination: ' . $name);
        }
        if ($srcPath === $dstPath) {
            $sendError('Source and destination are the same: ' . $name);
        }
        if (is_dir($srcPath)) {
            if (pathIsStrictDescendant($dstPath, $srcPath)) {
                $sendError('Cannot place a folder inside itself: ' . $name);
            }
            if (pathIsStrictDescendant($destDir, $srcPath)) {
                $sendError('Cannot move a folder into its own subfolder: ' . $name);
            }
        }

        if ($tryShell) {
            $ok = osMoveEntry($srcPath, $dstPath, $ROOT_DIR);
            if ($ok) {
                continue;
            }
            if (!$allowPhpFallback) {
                $sendError('Move failed (OS only): ' . $name);
            }
            $vpPhp = fmStreamProgressMeta($totalEstimate, true);
            $verboseCopyMove = $vpPhp['verbose_progress'];
            $send(array_merge(
                ['total' => $totalEstimate, 'n' => $count, 'name' => '', 'fallback' => 'php'],
                $vpPhp
            ));
        }

        if (!movePathPhp($srcPath, $dstPath, $ROOT_DIR, $count, $phpProgress)) {
            $sendError('Move failed: ' . $name);
        }
    }

    $send(['done' => true]);
    exit;
}

if (isset($_POST['action']) && $_POST['action'] === 'duplicate-stream' && isset($_POST['in']) && isset($_POST['names']) && is_array($_POST['names'])) {
    $srcDir = fmResolveDir((string)$_POST['in'], $ROOT_DIR);
    if (!pathInsideRoot($srcDir, $ROOT_DIR) || !is_dir($srcDir)) {
        jsonOut(['status' => 'error', 'msg' => 'Invalid folder']);
    }

    $names = [];
    foreach ($_POST['names'] as $n) {
        $n = basename((string)$n);
        if ($n === '' || $n === '.' || $n === '..') {
            continue;
        }
        if (preg_match('/[\\\\\/:*?"<>|]/', $n)) {
            continue;
        }
        $names[] = $n;
    }
    if (count($names) === 0) {
        jsonOut(['status' => 'error', 'msg' => 'Nothing to duplicate']);
    }

    while (ob_get_level()) {
        @ob_end_flush();
    }
    header('Content-Type: application/x-ndjson');
    header('Cache-Control: no-cache');
    fm_ob_implicit_flush_enable();

    $send = function (array $payload): void {
        echo json_encode($payload, JSON_UNESCAPED_SLASHES) . "\n";
        if (ob_get_level()) {
            ob_flush();
        }
        flush();
    };
    $sendError = function (string $msg) use ($send): void {
        $send(['error' => $msg]);
        $send(['done' => true]);
        exit;
    };

    $rootNorm = rtrim(safeRealpath($ROOT_DIR), '/');
    $execOk = fmIsExecAvailable();
    if (fmFileOpsMode() === 'os' && !$execOk) {
        $sendError('File ops mode is OS-only but exec() is disabled. Set $FM_FILE_OPS_MODE to "auto" or "php".');
    }
    $tryShell         = fmFileOpsTryShell();
    $allowPhpFallback = fmFileOpsAllowPhpFallback();

    $destDir = $srcDir;
    $baseSrc = rtrim($srcDir, '/');

    $totalEstimate = 0;
    foreach ($names as $name) {
        $sp = $baseSrc . '/' . $name;
        if (!pathInsideRoot($sp, $ROOT_DIR) || !file_exists($sp)) {
            continue;
        }
        $c = countPathFiles($sp, $ROOT_DIR);
        $totalEstimate += $c['total'];
    }
    if ($totalEstimate < 1) {
        $totalEstimate = count($names);
    }

    $vpCopyMove = fmStreamProgressMeta($totalEstimate, !$tryShell);
    if ($tryShell) {
        $send(array_merge([
            'total'         => 0,
            'indeterminate' => true,
            'msg'           => 'Duplicating' . ($allowPhpFallback ? ' via OS…' : ' via OS only…'),
        ], $vpCopyMove));
    } else {
        $send(array_merge(['total' => $totalEstimate, 'n' => 0, 'name' => ''], $vpCopyMove));
    }

    $count = 0;
    $lastFlush = 0.0;
    $progressMinInterval = 0.25;
    $verboseCopyMove = $vpCopyMove['verbose_progress'];
    $phpProgress = function (int $n, ?string $currentPath) use (&$lastFlush, $progressMinInterval, $send, $totalEstimate, $rootNorm, &$verboseCopyMove): void {
        $now = microtime(true);
        if (($now - $lastFlush) < $progressMinInterval && $n % 200 !== 0) {
            return;
        }
        $lastFlush = $now;
        $relName = '';
        if ($verboseCopyMove && $currentPath !== null) {
            $norm = normPath($currentPath);
            $relName = strpos($norm, $rootNorm) === 0
                ? ltrim(substr($norm, strlen($rootNorm)), '/')
                : basename($currentPath);
        }
        $send(['n' => $n, 'name' => $relName, 'total' => $totalEstimate]);
    };

    $created = [];
    $taken = [];

    foreach ($names as $name) {
        $srcPath = $baseSrc . '/' . $name;
        if (!pathInsideRoot($srcPath, $ROOT_DIR) || !file_exists($srcPath)) {
            $sendError('Source not found: ' . $name);
        }

        $dstName = fmNextDuplicateBasename($destDir, $name, $taken);
        $dstPath = $baseSrc . '/' . $dstName;
        $created[] = $dstName;

        if (!pathInsideRoot($dstPath, $ROOT_DIR)) {
            $sendError('Invalid destination for: ' . $name);
        }
        if (file_exists($dstPath)) {
            $sendError('Could not allocate a unique name for: ' . $name);
        }
        if (is_dir($srcPath)) {
            if (pathIsStrictDescendant($dstPath, $srcPath)) {
                $sendError('Cannot place a folder inside itself: ' . $name);
            }
        }

        if ($tryShell) {
            $ok = osCopyEntry($srcPath, $dstPath, $ROOT_DIR);
            if ($ok) {
                continue;
            }
            if (!$allowPhpFallback) {
                $sendError('Duplicate failed (OS only): ' . $name);
            }
            $vpPhp = fmStreamProgressMeta($totalEstimate, true);
            $verboseCopyMove = $vpPhp['verbose_progress'];
            $send(array_merge(
                ['total' => $totalEstimate, 'n' => $count, 'name' => '', 'fallback' => 'php'],
                $vpPhp
            ));
        }

        if (!copyPathPhp($srcPath, $dstPath, $ROOT_DIR, $count, $phpProgress)) {
            $sendError('Duplicate failed: ' . $name);
        }
    }

    $send(['done' => true, 'created' => $created]);
    exit;
}

if (isset($_POST['action']) && $_POST['action'] === 'chmod' && isset($_POST['in'])) {
    $dir = fmResolveDir((string)$_POST['in'], $ROOT_DIR);
    if (!pathInsideRoot($dir, $ROOT_DIR) || !is_dir($dir)) {
        jsonOut(['status' => 'error', 'msg' => 'Invalid folder']);
    }

    $folderNames = isset($_POST['folder_names']) && is_array($_POST['folder_names']) ? $_POST['folder_names'] : [];
    $fileNames   = isset($_POST['file_names']) && is_array($_POST['file_names']) ? $_POST['file_names'] : [];

    $folderModeStr = isset($_POST['folder_mode']) ? trim((string)$_POST['folder_mode']) : '';
    $fileModeStr   = isset($_POST['file_mode']) ? trim((string)$_POST['file_mode']) : '';
    $recFolders    = isset($_POST['recursive_folders']) && (string)$_POST['recursive_folders'] === '1';
    $recFiles      = isset($_POST['recursive_files']) && (string)$_POST['recursive_files'] === '1';

    $errors = [];
    $ok     = [];

    $hasFolders = count($folderNames) > 0;
    $hasFiles   = count($fileNames) > 0;
    $needFileMode = $hasFiles || ($hasFolders && $recFiles);
    $needFolderMode = $hasFolders;

    $folderMode = $needFolderMode ? fmParseChmodOctal($folderModeStr) : null;
    $fileMode   = $needFileMode ? fmParseChmodOctal($fileModeStr) : null;

    if ($needFolderMode && $folderMode === null) {
        jsonOut(['status' => 'error', 'msg' => 'Invalid folder permissions (use octal, e.g. 755)']);
    }
    if ($needFileMode && $fileMode === null) {
        jsonOut(['status' => 'error', 'msg' => 'Invalid file permissions (use octal, e.g. 644)']);
    }
    if (!$hasFolders && !$hasFiles) {
        jsonOut(['status' => 'error', 'msg' => 'Nothing to change']);
    }

    $baseDir = rtrim($dir, '/');

    foreach ($folderNames as $name) {
        $name = basename((string)$name);
        if ($name === '' || $name === '.' || $name === '..') {
            continue;
        }
        if (preg_match('/[\\\\\/:*?"<>|]/', $name)) {
            $errors[] = $name . ': invalid name';
            continue;
        }
        $full = $baseDir . '/' . $name;
        if (!pathInsideRoot($full, $ROOT_DIR) || !file_exists($full)) {
            $errors[] = $name . ': not found';
            continue;
        }
        if (!is_dir($full)) {
            $errors[] = $name . ': not a folder';
            continue;
        }
        if (!fmApplyChmodTree($full, $folderMode, $fileMode, $recFolders, $recFiles, $ROOT_DIR)) {
            $errors[] = $name . ': chmod failed';
            continue;
        }
        $ok[] = $name;
    }

    foreach ($fileNames as $name) {
        $name = basename((string)$name);
        if ($name === '' || $name === '.' || $name === '..') {
            continue;
        }
        if (preg_match('/[\\\\\/:*?"<>|]/', $name)) {
            $errors[] = $name . ': invalid name';
            continue;
        }
        $full = $baseDir . '/' . $name;
        if (!pathInsideRoot($full, $ROOT_DIR) || !file_exists($full)) {
            $errors[] = $name . ': not found';
            continue;
        }
        if (!is_file($full)) {
            $errors[] = $name . ': not a file';
            continue;
        }
        if (!fmApplyChmod($full, $fileMode, $ROOT_DIR)) {
            $errors[] = $name . ': chmod failed';
            continue;
        }
        $ok[] = $name;
    }

    jsonOut([
        'status' => count($errors) === 0 ? 'success' : (count($ok) > 0 ? 'partial' : 'error'),
        'ok'     => $ok,
        'errors' => $errors,
    ]);
}

if (isset($_POST['action']) && $_POST['action'] === 'trash-move' && isset($_POST['in']) && isset($_POST['names']) && is_array($_POST['names'])) {
    $dir = fmResolveDir((string)$_POST['in'], $ROOT_DIR);
    if (!pathInsideRoot($dir, $ROOT_DIR) || fmIsUnderTrashTree($dir, $ROOT_DIR)) {
        jsonOut(['status' => 'error', 'msg' => 'Cannot move to trash from this folder.']);
    }
    if (!fmTrashEnsure($ROOT_DIR)) {
        jsonOut(['status' => 'error', 'msg' => 'The Trash folder could not be created (check write permissions on the file manager root).']);
    }
    $relDir = fmRelFromRoot($dir, $ROOT_DIR);
    $baseDir = rtrim($dir, '/');
    $errors = [];
    $ok     = [];
    foreach ($_POST['names'] as $name) {
        $name = basename((string)$name);
        if ($name === '' || $name === '.' || $name === '..') {
            continue;
        }
        if (preg_match('/[\\\\\/:*?"<>|]/', $name)) {
            $errors[] = $name . ': invalid name';
            continue;
        }
        $src = $baseDir . '/' . $name;
        if (!pathInsideRoot($src, $ROOT_DIR) || !file_exists($src)) {
            $errors[] = $name . ': not found';
            continue;
        }
        if (fmIsUnderTrashTree($src, $ROOT_DIR)) {
            $errors[] = $name . ': already in trash';
            continue;
        }
        $origRel = $relDir === '' ? $name : rtrim($relDir, '/') . '/' . $name;
        if (fmTrashStore($src, $origRel, $ROOT_DIR) === null) {
            $errors[] = $name . ': move to Trash failed (permission denied or file in use)';
            continue;
        }
        $ok[] = $name;
    }
    jsonOut([
        'status' => count($ok) > 0 && count($errors) === 0 ? 'success' : (count($ok) > 0 ? 'partial' : 'error'),
        'ok'     => $ok,
        'errors' => $errors,
    ]);
}

if (isset($_POST['action']) && $_POST['action'] === 'trash-restore' && isset($_POST['ids']) && is_array($_POST['ids'])) {
    if (!is_dir(fmTrashRootPath($ROOT_DIR))) {
        jsonOut(['status' => 'error', 'msg' => 'Trash is empty.', 'ok' => [], 'restored' => [], 'errors' => []]);
    }
    if (!fmTrashEnsure($ROOT_DIR)) {
        jsonOut(['status' => 'error', 'msg' => 'The Trash folder is not accessible.']);
    }
    $errors   = [];
    $ok       = [];
    $restored = [];
    $labels   = [];
    foreach (fmTrashList($ROOT_DIR) as $item) {
        $labels[$item['id']] = $item['name'];
    }
    foreach ($_POST['ids'] as $id) {
        $id = (string)$id;
        $label = $labels[$id] ?? $id;
        $r = fmTrashRestoreItem($id, $ROOT_DIR);
        if (!$r['ok']) {
            $errors[] = $label . ': ' . $r['msg'];
            continue;
        }
        $ok[] = $id;
        $restored[] = [
            'id'      => $id,
            'name'    => $r['name'],
            'path'    => $r['path'],
            'parent'  => $r['parent'],
            'renamed' => $r['renamed'],
            'type'    => $r['type'],
            'from'    => $label,
        ];
    }
    fmTrashCleanupIfEmpty($ROOT_DIR);
    jsonOut([
        'status'   => count($ok) > 0 && count($errors) === 0 ? 'success' : (count($ok) > 0 ? 'partial' : 'error'),
        'ok'       => $ok,
        'restored' => $restored,
        'errors'   => $errors,
    ]);
}

if (isset($_POST['action']) && $_POST['action'] === 'trash-empty') {
    $trashRoot = fmTrashRootPath($ROOT_DIR);
    $trashNorm = rtrim(normPath($trashRoot), '/');
    $rootNorm  = rtrim(normPath(safeRealpath($ROOT_DIR)), '/');
    if ($trashNorm === '' || $trashNorm === $rootNorm || strpos($trashNorm, $rootNorm . '/') !== 0) {
        jsonOut(['status' => 'error', 'msg' => 'Invalid trash path']);
    }
    if (!is_dir($trashRoot)) {
        jsonOut(['status' => 'success', 'msg' => 'Trash is already empty', 'ok' => [], 'errors' => []]);
    }
    if (!fmTrashEnsure($ROOT_DIR)) {
        jsonOut(['status' => 'error', 'msg' => 'Could not read Trash folder']);
    }
    $ok       = [];
    $errors   = [];
    $filesDir = fmTrashFilesDir($ROOT_DIR);
    $infoDir  = fmTrashInfoDir($ROOT_DIR);
    foreach (fmTrashList($ROOT_DIR) as $item) {
        $full = $filesDir . '/' . $item['id'];
        if (!pathInsideRoot($full, $ROOT_DIR) || !fmIsUnderTrashTree($full, $ROOT_DIR)) {
            $errors[] = $item['name'] . ': skipped';
            continue;
        }
        if (!deletePath($full, $ROOT_DIR)) {
            $errors[] = $item['name'] . ': delete failed';
            continue;
        }
        @unlink($infoDir . '/' . $item['id'] . '.json');
        $ok[] = $item['id'];
    }
    fmTrashCleanupIfEmpty($ROOT_DIR);
    jsonOut([
        'status' => count($errors) === 0 ? 'success' : (count($ok) > 0 ? 'partial' : 'error'),
        'ok'     => $ok,
        'errors' => $errors,
    ]);
}
if (isset($_POST['action']) && $_POST['action'] === 'delete-stream' && isset($_POST['in']) && isset($_POST['names']) && is_array($_POST['names'])) {
    global $serverCapabilities;
    if (fmFileOpsMode() === 'os' && !$serverCapabilities['exec_available']) {
        jsonOut(['status' => 'error', 'msg' => 'File ops mode is OS-only but exec() is disabled. Set $FM_FILE_OPS_MODE to "auto" or "php".']);
    }
    $tryShell = fmFileOpsTryShell();
    if ($tryShell && fmFileOpsAllowPhpFallback()) {
        $deleteMode = 'auto';
    } elseif ($tryShell && !fmFileOpsAllowPhpFallback()) {
        $deleteMode = 'os';
    } else {
        $deleteMode = 'php';
    }
    $dir = fmResolveDir((string)$_POST['in'], $ROOT_DIR);
    if (!pathInsideRoot($dir, $ROOT_DIR)) {
        jsonOut(['status' => 'error', 'msg' => 'Invalid path']);
    }
    $baseDir = rtrim($dir, '/');
    $names = [];
    foreach ($_POST['names'] as $name) {
        $name = basename((string)$name);
        if ($name === '' || $name === '.' || $name === '..') continue;
        if (preg_match('/[\\\\\/:*?"<>|]/', $name)) continue;
        $fullPath = $baseDir . '/' . $name;
        if (pathInsideRoot($fullPath, $ROOT_DIR) && file_exists($fullPath)) {
            $names[] = $fullPath;
        }
    }
    if (count($names) === 0) {
        jsonOut(['status' => 'error', 'msg' => 'Nothing to delete']);
    }
    while (ob_get_level()) { ob_end_flush(); }
    header('Content-Type: application/x-ndjson');
    header('Cache-Control: no-cache');
    fm_ob_implicit_flush_enable();

    $count  = 0;
    $failed = null;

    $deleteEstimate = 0;
    foreach ($names as $fp) {
        $cDel = countPathFiles($fp, $ROOT_DIR);
        $deleteEstimate += $cDel['total'];
    }
    if ($deleteEstimate < 1) {
        $deleteEstimate = count($names);
    }
    $vpDelete = fmStreamProgressMeta($deleteEstimate, $deleteMode === 'php');
    echo json_encode(array_merge(['total' => $deleteEstimate, 'n' => 0], $vpDelete)) . "\n";
    if (ob_get_level()) {
        ob_flush();
    }
    flush();

    $lastFlushTime     = 0.0;
    $progressBatchSize = 500;
    $progressMinInterval = 0.3;
    $rootDirNorm       = rtrim(safeRealpath($ROOT_DIR), '/');
    $verboseDeleteProgress = $vpDelete['verbose_progress'];
    $phpProgress = function (int $n, ?string $currentPath) use (&$lastFlushTime, $progressBatchSize, $progressMinInterval, $rootDirNorm, &$verboseDeleteProgress): void {
        $now     = microtime(true);
        $timeOk  = ($now - $lastFlushTime) >= $progressMinInterval;
        $batchOk = ($n % $progressBatchSize === 0);
        if ($batchOk || $timeOk) {
            $lastFlushTime = $now;
            $relName = '';
            if ($verboseDeleteProgress && $currentPath !== null) {
                $norm = normPath($currentPath);
                $relName = strpos($norm, $rootDirNorm) === 0
                    ? ltrim(substr($norm, strlen($rootDirNorm)), '/')
                    : basename($currentPath);
            }
            echo json_encode(['n' => $n, 'name' => $relName]) . "\n";
            if (ob_get_level()) ob_flush();
            flush();
        }
    };

    foreach ($names as $fullPath) {
        if ($deleteMode === 'auto') {
            $osOk = osDeletePath($fullPath, $ROOT_DIR);
            if (!$osOk) {
                $vpPhp = fmStreamProgressMeta($deleteEstimate, true);
                $verboseDeleteProgress = $vpPhp['verbose_progress'];
                echo json_encode(array_merge(['total' => $deleteEstimate, 'n' => $count, 'fallback' => 'php'], $vpPhp)) . "\n";
                if (ob_get_level()) {
                    ob_flush();
                }
                flush();
                if (!deletePathWithProgress($fullPath, $ROOT_DIR, $count, $phpProgress)) {
                    $failed = $fullPath;
                    break;
                }
            }
        } elseif ($deleteMode === 'os') {
            if (!osDeletePath($fullPath, $ROOT_DIR)) {
                $failed = $fullPath;
                break;
            }
        } else {
            if (!deletePathWithProgress($fullPath, $ROOT_DIR, $count, $phpProgress)) {
                $failed = $fullPath;
                break;
            }
        }
    }

    if ($failed !== null) echo json_encode(['error' => 'Could not delete: ' . basename($failed)]) . "\n";
    if (normPath($dir) === normPath(fmTrashFilesDir($ROOT_DIR))) {
        $infoDir = fmTrashInfoDir($ROOT_DIR);
        foreach ($names as $fp) {
            if (!file_exists($fp)) {
                @unlink($infoDir . '/' . basename($fp) . '.json');
            }
        }
        fmTrashCleanupIfEmpty($ROOT_DIR);
    }
    echo json_encode(['done' => true]) . "\n";
    if (ob_get_level()) ob_flush();
    flush();
    exit;
}

// =========================
// UI (HTML)
// =========================

$cmsId = detectCms($ROOT_DIR);
$suggested = randomName(30, 40) . '.php';
$suggestedPrefixed = 'solofilemanager_' . randomName(24, 32) . '.php';
global $serverCapabilities;
$FM_EXEC_AVAILABLE = $serverCapabilities['exec_available'];
$FM_TERMINAL_HERE_ENABLED = $FM_ENABLE_TERMINAL_HERE;
$FM_TERMINAL_MANUAL_ENABLED = $FM_ENABLE_TERMINAL_MANUAL;
$FM_TERMINAL_ADVANCED_ENABLED = $FM_ENABLE_TERMINAL_ADVANCED;
$csrfToken = fmCsrfToken();

?><!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>SoloFileManager</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Roboto+Mono:wght@400;500;700&display=swap" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/normalize/8.0.1/normalize.min.css" rel="stylesheet" crossorigin="anonymous" referrerpolicy="no-referrer">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.13.1/font/bootstrap-icons.css" rel="stylesheet">
    <style>
/* SoloFileManager inline styles — layout, popup, table, tree, setup/login. */
:root {
    --main-color: #da615b;
    --main-color-alpha-40: #da615b40;
    --fm-ls-width: 250px;
}

html * {
    box-sizing: border-box;
}

*,
*:before,
*:after {
    box-sizing: inherit;
}

body {
    margin: 0;
    background: #ccc;
    font-family: "Roboto Mono", monospace;
    font-size: 13px;
}

html {
    height: 100%;
}

html:has(.fm-table-wrapper) {
    overflow: hidden;
}

html:has(.fm-table-wrapper) body {
    height: 100%;
    overflow: hidden;
}

.mt-0 {
    margin-top: 0;
}
.mb-0 {
    margin-bottom: 0;
}
.fm-hidden {
    display: none !important;
}

.fm-table-wrapper {
    max-width: 1200px;
    height: 100%;
    min-height: 0;
    max-height: 100%;
    box-sizing: border-box;
    padding: 25px 0;
    margin: 0 auto;
    display: grid;
    gap: 6px;
    grid-template-rows: auto 1fr;
    grid-template-columns: var(--fm-ls-width, 250px) 1fr;
    grid-template-areas:
        "header header"
        "sidebar content";
    overflow: hidden;
}

.fm-table-header {
    height: 44px;
    padding: 0 10px;
    grid-area: header;
    display: flex;
    align-items: center;
    justify-content: space-between;
    background: var(--main-color);
}

.fm-table-title {
    color: #fff;
    font-weight: 700;
    font-size: 14px;
    display: flex;
    gap: 10px;
    align-items: center;
}

.fm-table-header-right {
    grid-area: header-right;
    display: flex;
    gap: 6px;
    justify-content: end;
    align-items: center;
}

.fm-auth-off-warning {
    flex: 0 1 auto;
    min-width: 0;
    margin: 0 10px;
    padding: 5px 10px;
    border-radius: 6px;
    background: #ffd54f;
    color: #3e2700;
    font-size: 12px;
    font-weight: 600;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

.fm-info-danger {
    color: #c62828;
    font-weight: 600;
}

.fm-table-config-btn,
.fm-header-btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 36px;
    height: 36px;
    padding: 0;
    border: none;
    background: transparent;
    color: #fff;
    font-size: 20px;
    cursor: pointer;
    border-radius: 8px;
    transition: background-color 150ms;
}

.fm-table-config-btn:hover,
.fm-header-btn:hover {
    background: rgba(255, 255, 255, .18);
}

#sidebar-and-content {
    display: flex;
    gap: 6px;
}

#sidebar-block {
    width: 250px;
    min-width: 220px;
    min-height: 0;
    background: #dadada;
    position: relative;
    grid-area: sidebar;
    overflow: hidden;
}

#fm-ls-resizer {
    position: absolute;
    top: 0;
    right: 0;
    width: 6px;
    height: 100%;
    cursor: col-resize;
    z-index: 50;
    background: transparent;
}

#fm-ls-resizer:hover {
    background: rgba(0, 0, 0, .08);
}

.fm-is-resizing {
    cursor: col-resize !important;
    user-select: none !important;
}

#sidebar {
    display: flex;
    flex-direction: column;
    padding: 10px;
    height: 100%;
    box-sizing: border-box;
}

#sidebar-sections {
    display: flex;
    flex-direction: column;
    gap: 16px;
    flex: 1;
    min-height: 0;
}

#sidebar-tree-block {
    position: relative;
    flex: 1;
    min-height: 0;
    display: flex;
}

#sidebar-tree {
    flex: 1;
    min-height: 0;
    overflow: auto;
    -webkit-overflow-scrolling: touch;
    scrollbar-gutter: stable;
    scrollbar-width: thin;
    scrollbar-color: rgba(80, 80, 80, 0.55) #ebebeb;
    padding-right: 36px;
}

#sidebar-tree::-webkit-scrollbar {
    width: 8px;
}

#sidebar-tree::-webkit-scrollbar-track {
    background: #ebebeb;
    border-radius: 4px;
}

#sidebar-tree::-webkit-scrollbar-thumb {
    background: rgba(100, 100, 100, 0.45);
    border-radius: 4px;
}

#sidebar-tree::-webkit-scrollbar-thumb:hover {
    background: rgba(218, 97, 91, 0.65);
}

@media (hover: hover) and (pointer: fine) {
    #sidebar-tree {
        scrollbar-color: transparent transparent;
    }
    #sidebar-tree::-webkit-scrollbar-track {
        background: transparent;
    }
    #sidebar-tree::-webkit-scrollbar-thumb {
        background: transparent;
    }
    #sidebar-tree:hover,
    #sidebar-tree:focus-within {
        scrollbar-color: rgba(80, 80, 80, 0.55) #ebebeb;
    }
    #sidebar-tree:hover::-webkit-scrollbar-track,
    #sidebar-tree:focus-within::-webkit-scrollbar-track {
        background: #ebebeb;
    }
    #sidebar-tree:hover::-webkit-scrollbar-thumb,
    #sidebar-tree:focus-within::-webkit-scrollbar-thumb {
        background: rgba(100, 100, 100, 0.45);
    }
    #sidebar-tree:hover::-webkit-scrollbar-thumb:hover,
    #sidebar-tree:focus-within::-webkit-scrollbar-thumb:hover {
        background: rgba(218, 97, 91, 0.65);
    }
}

#fm-sidebar-toolbar {
    display: flex;
    align-items: center;
    justify-content: flex-end;
    gap: 4px;
    padding: 0;
    margin: 0;
    position: absolute;
    top: 0;
    right: 10px;
    z-index: 8;
}

.fm-sidebar-toolbar-btn {
    width: 28px;
    height: 28px;
    padding: 0;
    border: none;
    background: transparent;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
    color: #444;
    transition: background-color 120ms, color 120ms;
}

.fm-sidebar-toolbar-btn:hover {
    background: rgba(0, 0, 0, .06);
    color: var(--main-color);
}

.fm-sidebar-toolbar-btn i {
    font-size: 20px;
}

.fm-bookmarks {
    margin: 0;
    border: 1px solid #e4e4e4;
    border-radius: 8px;
    background: #fff;
}

.fm-bookmarks-head {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 8px;
    padding: 6px 8px;
    border-bottom: 1px solid #efefef;
}

.fm-bookmarks-title {
    font-size: 12px;
    font-weight: 600;
    color: #555;
    display: inline-flex;
    align-items: center;
    gap: 6px;
}

.fm-bookmarks-pin-btn {
    width: 24px;
    height: 24px;
}

.fm-bookmarks-pin-btn i {
    font-size: 16px;
}

.fm-bookmarks-list {
    display: flex;
    flex-direction: column;
    padding: 4px;
}

.fm-bookmarks-empty {
    font-size: 12px;
    color: #888;
    padding: 6px 8px;
}

.fm-bookmark-row {
    display: flex;
    align-items: center;
    gap: 4px;
    border-radius: 6px;
}

.fm-bookmark-row--active {
    background: rgba(218, 97, 91, 0.1);
}

.fm-bookmark-open {
    flex: 1;
    min-width: 0;
    display: inline-flex;
    align-items: center;
    gap: 6px;
    border: none;
    background: transparent;
    text-align: left;
    padding: 6px 8px;
    color: #444;
    cursor: pointer;
    font-size: 12px;
}

.fm-bookmark-open:hover {
    color: var(--main-color);
}

.fm-bookmark-open span {
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.fm-bookmark-remove {
    width: 24px;
    height: 24px;
    border: none;
    background: transparent;
    border-radius: 5px;
    color: #666;
    cursor: pointer;
}

.fm-bookmark-remove:hover {
    background: rgba(0, 0, 0, .06);
    color: var(--main-color);
}

#sidebar-tree {
    font-size: 13px;
    position: relative;
}

.fm-sidebar-loading-overlay {
    height: 100px;
    inset: 0;
    display: flex;
    align-items: center;
    justify-content: center;
    z-index: 5;
}

.fm-sidebar-loading-spinner {
    width: 32px;
    height: 32px;
    border: 3px solid rgba(0, 0, 0, 0.12);
    border-top-color: var(--main-color);
    border-radius: 50%;
    animation: spin .8s linear infinite;
}

@keyframes spin {
    to {
        transform: rotate(360deg);
    }
}

.fm-inline-spinner {
    width: 28px;
    height: 28px;
    border: 3px solid rgba(0,0,0,.12);
    border-top-color: var(--main-color);
    border-radius: 50%;
    animation: spin .8s linear infinite;
    margin-left: 2px;
}

.fm-sidebar-loading-overlay.fm-sidebar-loading-error {
    flex-direction: column;
    gap: 1.3rem;
    padding: 1rem;
}

.fm-sidebar-loading-error-text {
    margin: 0;
    color: #c00;
    font-size: 0.9rem;
}

.fm-sidebar-retry-btn {
    display: inline-flex;
    align-items: center;
    gap: 0.4rem;
    margin: 0;
    padding: 0.4rem 0.75rem;
    font-size: 0.875rem;
    line-height: 1.2;
    cursor: pointer;
    border: 1px solid rgba(0, 0, 0, 0.15);
    border-radius: 6px;
    background: #fff;
    color: #373737;
    transition: background 0.15s ease, border-color 0.15s ease, color 0.15s ease;
}

.fm-sidebar-retry-btn:hover {
    border-color: var(--main-color);
    color: var(--main-color);
    background: rgba(218, 97, 91, 0.08);
}

.fm-sidebar-retry-btn .bi {
    font-size: 1.05rem;
}

.fm-tree-item {
    width: fit-content;
    min-height: 26px;
    display: flex;
    align-items: center;
}

.fm-tree-toggle {
    width: 20px;
    flex-shrink: 0;
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    color: #373737;
}

.fm-tree-spacer {
    display: inline-block;
    width: 14px;
    height: 14px;
}

.fm-tree-link {
    flex: 1;
    min-width: 0;
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 2px 4px;
    text-decoration: none;
    color: #373737;
    border-radius: 3px;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
    height: 24px;
}

.fm-tree-link:hover {
    background: rgba(0, 0, 0, .06);
}
.fm-sidebar-loading-error {
    justify-content: normal;
}
.fm-sidebar-loading-error .fm-tree-link {
    flex: none;
}

.fm-tree-link.fm-tree-current {
    background: #c2c2c2;
}

.fm-tree-link .bi-folder-fill,
.fm-tree-link .bi-house-fill {
    display: flex;
    flex-shrink: 0;
    color: var(--main-color);
}

.fm-tree-link .bi-house-fill {
    color: #5a6ba8;
}

.fm-tree-children {
    width: fit-content;
    margin-left: 12px;
    border-left: 1px solid rgba(0, 0, 0, .08);
}

.fm-tree-children.fm-tree-closed {
    display: none;
}

.fm-tree-lazy-loading {
    display: flex;
    align-items: center;
    justify-content: flex-start;
    padding: 4px 0 6px 4px;
    min-height: 28px;
}

.fm-tree-lazy-spinner {
    width: 18px;
    height: 18px;
    border-width: 2px;
}

#content {
    min-width: 0;
    min-height: 0;
    background: #dadada;
    grid-area: content;
    display: flex;
    flex-direction: column;
    overflow: hidden;
}

#fm-table-container {
    flex: 1;
    min-height: 0;
    display: flex;
    flex-direction: column;
    overflow: hidden;
}

/* FmPopup styles */
.fm-popup {
    position: fixed;
    left: 0;
    top: 0;
    height: 100%;
    width: 100%;
    opacity: 0;
    display: none;
    background-color: rgba(94, 110, 141, .6);
    transition: opacity .2s;
    z-index: 100;
}

.fm-popup.show {
    display: block;
}

.fm-popup.fade {
    opacity: 1;
}

.fm-popup-container {
    position: absolute;
    top: 50%;
    left: 50%;
    width: 90%;
    max-width: 400px;
    max-height: min(90vh, calc(100vh - 24px));
    background: #fff;
    border-radius: 5px;
    box-shadow: 0 0 20px rgba(0, 0, 0, .2);
    transform: translate3d(-50%, -50%, 0);
    backface-visibility: hidden;
    box-sizing: border-box;
    display: flex;
    flex-direction: column;
    overflow: hidden;
}

.fm-popup-container label input[type=text],
.fm-popup-container label input[type=url] {
    width: 100%;
    padding: 6px;
    border-radius: 5px;
    border: 1px solid #dee2e6;
    margin-top: 10px;
    display: block;
    transition: border-color 300ms, box-shadow 300ms;
}

.fm-popup-container label input[type=text]:focus,
.fm-popup-container label input[type=url]:focus {
    border: 1px solid var(--main-color);
    box-shadow: 0 0 0 .25rem var(--main-color-alpha-40);
    outline: 0;
}

.fm-popup-container .fm-popup-header {
    position: relative;
    flex-shrink: 0;
    height: 42px;
    padding-left: 20px;
    padding-right: 30px;
    display: flex;
    align-items: center;
    color: #fff;
    font-weight: 500;
    font-size: 15px;
    border-top-left-radius: 5px;
    border-top-right-radius: 5px;
    background-color: var(--main-color);
}

.fm-popup-container .fm-popup-header.fm-popup-header--no-close {
    padding-right: 20px;
}

.fm-popup-container .fm-popup-content {
    flex: 1 1 auto;
    min-height: 0;
    overflow-x: hidden;
    overflow-y: auto;
    -webkit-overflow-scrolling: touch;
    padding: 22px 18px;
}
.fm-popup-container .fm-popup-content > p {
    margin-top: 1em;
}
.fm-popup-container .fm-popup-content > p:first-child {
    margin-top: 0;
}

/* FmImageViewerPopup */
.fm-image-viewer-popup .fm-popup-container {
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    width: 100%;
    height: 100%;
    max-width: none;
    max-height: none;
    transform: none;
    border-radius: 0;
    box-shadow: none;
    display: flex;
    flex-direction: column;
    overflow: hidden;
}

.fm-image-viewer-popup .fm-popup-header {
    display: none;
}

.fm-image-viewer-popup .fm-popup-content {
    flex: 1 1 0%;
    min-height: 0;
    display: flex;
    flex-direction: column;
    padding: 0;
}

.fm-image-viewer {
    position: relative;
    display: flex;
    flex-direction: column;
    flex: 1 1 0%;
    min-height: 0;
    height: 100%;
}

.fm-image-viewer-close {
    position: absolute;
    top: 10px;
    right: 10px;
    z-index: 5;
    width: 44px;
    height: 44px;
    padding: 0;
    border: none;
    border-radius: 8px;
    background: rgba(0, 0, 0, .45);
    color: #fff;
    font-size: 1.35rem;
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    transition: background 150ms;
}

.fm-image-viewer-close:hover {
    background: rgba(0, 0, 0, .65);
}

.fm-image-viewer-close:focus {
    outline: 2px solid #fff;
    outline-offset: 2px;
}

.fm-image-viewer-main {
    flex: 1 1 0%;
    display: flex;
    flex-direction: row;
    align-items: stretch;
    gap: 4px;
    min-height: 0;
    padding: 8px 4px;
    background: #1a1d24;
}

.fm-image-viewer-nav {
    flex: 0 0 auto;
    align-self: center;
    width: 44px;
    height: 72px;
    border: none;
    border-radius: 6px;
    background: rgba(255, 255, 255, .12);
    color: #fff;
    font-size: 1.5rem;
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    transition: background 150ms;
}

.fm-image-viewer-nav:hover:not(:disabled) {
    background: rgba(255, 255, 255, .22);
}

.fm-image-viewer-nav:disabled {
    opacity: .25;
    cursor: default;
}

.fm-image-viewer-stage {
    flex: 1 1 0%;
    min-width: 0;
    min-height: 0;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 4px 8px;
    overflow: hidden;
}

.fm-image-viewer-main-img {
    box-sizing: border-box;
    display: block;
    margin: auto;
    max-width: 100%;
    max-height: 100%;
    width: auto;
    height: auto;
    object-fit: contain;
    object-position: center;
}

.fm-image-viewer-main-img--err {
    min-width: 120px;
    min-height: 80px;
    background: rgba(255, 255, 255, .08);
}

.fm-image-viewer-footer {
    flex: 0 0 auto;
    background: #f0f0f0;
    border-top: 1px solid #ccc;
    padding: 8px 10px 10px;
}

.fm-image-viewer-counter {
    font-size: 12px;
    color: #444;
    margin-bottom: 8px;
    text-align: center;
    word-break: break-word;
    padding: 0 52px;
}

.fm-image-viewer-thumbs {
    overflow-x: auto;
    overflow-y: hidden;
    -webkit-overflow-scrolling: touch;
    padding-bottom: 4px;
}

.fm-image-viewer-thumbs-inner {
    display: flex;
    flex-direction: row;
    gap: 8px;
    justify-content: center;
    align-items: center;
    min-height: 64px;
    min-width: 100%;
    width: max-content;
}

.fm-image-viewer-thumb {
    flex: 0 0 auto;
    padding: 0;
    margin: 0;
    border: 2px solid transparent;
    border-radius: 6px;
    cursor: pointer;
    background: #ddd;
    overflow: hidden;
    line-height: 0;
    transition: border-color 150ms, box-shadow 150ms;
}

.fm-image-viewer-thumb img {
    display: block;
    width: 56px;
    height: 56px;
    object-fit: cover;
}

.fm-image-viewer-thumb:hover,
.fm-image-viewer-thumb:focus {
    outline: none;
    border-color: var(--main-color, #0d6efd);
}

.fm-image-viewer-thumb--current {
    border-color: var(--main-color, #0d6efd);
    box-shadow: 0 0 0 1px rgba(13, 110, 253, .35);
}

.fm-context-menu {
    position: fixed;
    z-index: 10050;
    min-width: 220px;
    max-width: min(520px, calc(100vw - 16px));
    max-height: calc(100vh - 16px);
    overflow-y: auto;
    padding: 4px 0;
    margin: 0;
    list-style: none;
    background: #fff;
    border: 1px solid #ccc;
    border-radius: 8px;
    box-shadow: 0 8px 24px rgba(0, 0, 0, 0.18);
    font-size: 13px;
    color: #222;
}

.fm-context-menu-item {
    display: flex;
    align-items: center;
    gap: 10px;
    width: 100%;
    padding: 6px 12px;
    margin: 0;
    border: none;
    background: transparent;
    font: inherit;
    color: inherit;
    text-align: left;
    cursor: pointer;
    line-height: 1.15;
}

.fm-context-menu-item i.bi {
    flex: 0 0 auto;
    font-size: 1rem;
    opacity: 0.85;
}

.fm-context-menu-item-label {
    flex: 1 1 auto;
    min-width: 0;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.fm-context-menu-item-kbd {
    flex: 0 0 auto;
    font-size: 11px;
    color: #666;
    text-align: right;
    white-space: nowrap;
    flex-shrink: 0;
}

.fm-context-menu-item:hover:not(:disabled):not(.fm-context-menu-item--disabled) {
    background: rgba(0, 0, 0, 0.06);
}

.fm-context-menu-item:focus {
    outline: none;
    background: rgba(13, 110, 253, 0.12);
}

.fm-context-menu-item--disabled,
.fm-context-menu-item:disabled {
    opacity: 0.45;
    cursor: default;
    pointer-events: none;
}

.fm-context-menu-sep {
    height: 1px;
    margin: 4px 10px;
    background: #ddd;
    border: none;
    padding: 0;
}

.fm-toast-stack {
    position: fixed;
    top: 12px;
    right: 12px;
    z-index: 11000;
    display: flex;
    flex-direction: column;
    align-items: flex-end;
    gap: 8px;
    max-width: min(380px, calc(100vw - 24px));
    pointer-events: none;
}

.fm-toast {
    pointer-events: auto;
    padding: 10px 14px;
    border-radius: 8px;
    font-size: 13px;
    line-height: 1.45;
    box-shadow: 0 4px 18px rgba(0, 0, 0, 0.14);
    cursor: pointer;
    opacity: 0;
    transform: translateX(16px);
    transition: opacity 0.2s ease, transform 0.2s ease;
}

.fm-toast--visible {
    opacity: 1;
    transform: translateX(0);
}

.fm-toast--success {
    background: #ecfdf3;
    border: 1px solid #a3e4b8;
    color: #0f5132;
}

.fm-toast--info {
    background: #e7f1ff;
    border: 1px solid #9ec5fe;
    color: #052c65;
}

.fm-toast--warning {
    background: #fff8e6;
    border: 1px solid #ffe69c;
    color: #664d03;
}

.fm-toast-title {
    display: block;
    margin-bottom: 4px;
    font-weight: 600;
}

.fm-toast-msg {
    display: block;
}

.fm-download-skipped {
    max-height: 220px;
    overflow: auto;
    margin: 0 0 8px;
    padding-left: 18px;
    font-size: 12px;
    word-break: break-all;
}

.fm-download-progress-popup .fm-download-progress-line {
    margin-top: 14px;
    margin-bottom: 0;
}

.fm-notice-popup .fm-notice-popup-inner {
    border-radius: 8px;
    padding: 12px 14px;
    margin: 0;
}

.fm-notice-popup--error {
    background: #fdeaea;
    border: 1px solid #f5c2c7;
    color: #58151c;
}

.fm-notice-popup--warning {
    background: #fff8e6;
    border: 1px solid #ffe69c;
    color: #664d03;
}

.fm-notice-popup-msg {
    margin: 0;
    line-height: 1.45;
    font-size: 14px;
    word-break: break-word;
}

.fm-notice-dont-show {
    display: flex;
    align-items: center;
    gap: 8px;
    margin-top: 14px;
    font-size: 13px;
    cursor: pointer;
    user-select: none;
    color: inherit;
}

.fm-notice-dont-show input {
    cursor: pointer;
    flex-shrink: 0;
}

.fm-table-empty--invalid-folder {
    display: flex;
    align-items: center;
    justify-content: center;
    min-height: 12rem;
    padding: 1.5rem 1rem;
}

.fm-invalid-folder-notice {
    text-align: center;
    max-width: 28rem;
    padding: 1.1rem 1.35rem;
    background: #fff8e6;
    border: 1px solid #ffe69c;
    border-radius: 8px;
    color: #664d03;
}

.fm-invalid-folder-notice .fm-invalid-folder-msg {
    margin: 0 0 1rem 0;
    line-height: 1.45;
    font-size: 15px;
}

.fm-invalid-folder-notice .fm-invalid-folder-go-root {
    margin: 0 auto;
}

.fm-popup-container .fm-popup-divider {
    border: 0;
    border-top: 1px solid #ddd;
    margin: 14px 0;
}

.fm-popup-container .fm-buttons {
    flex-shrink: 0;
    display: flex;
    min-height: 52px;
    align-items: stretch;
    border-bottom-left-radius: 5px;
    border-bottom-right-radius: 5px;
    overflow: hidden;
}

.fm-popup-container .fm-buttons .fm-popup-action-button {
    min-height: 52px;
    background-color: var(--main-color);
    color: #fff;
    border: none;
    text-align: center;
    cursor: pointer;
    flex: 1 1 auto;
    font-size: 14px;
    transition: all 200ms;
    font-weight: 600;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    gap: 2px;
    padding: 6px 8px;
    line-height: 1.15;
}

.fm-popup-container .fm-buttons .fm-popup-btn-label {
    display: block;
}

.fm-popup-container .fm-buttons .fm-popup-btn-kbd {
    display: block;
    font-size: 10px;
    font-weight: 500;
    opacity: 0.88;
    letter-spacing: 0.02em;
}

.fm-popup-container .fm-buttons .fm-popup-action-button:focus {
    border: none;
    outline: 0;
    box-shadow: inset 0 -3px 0 rgba(0, 0, 0, 0.25);
}

.fm-popup-container .fm-buttons .fm-popup-action-button+.fm-popup-action-button {
    border-left: 1px solid #fff;
}

.fm-popup-container .fm-buttons .fm-popup-grey-btn {
    background-color: #b3b3b3;
}

.fm-popup-container .fm-buttons .fm-popup-grey-btn[data-fm-popup-btn="Reset"] {
    background-color: var(--main-color);
}

.fm-popup-container .fm-popup-close {
    position: absolute;
    top: 50%;
    right: 8px;
    width: 30px;
    height: 30px;
    color: #fff;
    cursor: pointer;
    transform: translateY(-50%);
}

.fm-popup-container .fm-popup-close::before,
.fm-popup-container .fm-popup-close::after {
    content: '';
    position: absolute;
    top: 14px;
    width: 14px;
    height: 3px;
    background-color: #fff;
}

.fm-popup-container .fm-popup-close::before {
    left: 8px;
    transform: rotate(45deg);
}

.fm-popup-container .fm-popup-close::after {
    right: 8px;
    transform: rotate(-45deg);
}

.fm-progress-label {
    margin: 0 0 .75rem 0;
    color: #373737;
}

.fm-progress-wrap {
    height: 8px;
    background: #e0e0e0;
    border-radius: 4px;
    overflow: hidden;
}

.fm-progress-bar {
    height: 100%;
    width: 35%;
    background: var(--main-color);
    border-radius: 4px;
    animation: fm-progress-indeterminate 1.2s ease-in-out infinite;
}

@keyframes fm-progress-indeterminate {
    0% {
        transform: translateX(-100%);
    }

    100% {
        transform: translateX(380%);
    }
}

.fm-copy-move-progress-wrap .fm-progress-bar {
    animation: none;
    width: 0%;
    transition: width 0.2s linear;
}

.fm-copy-move-progress-wrap.fm-progress-indeterminate .fm-progress-bar {
    animation: fm-progress-indeterminate 1.2s ease-in-out infinite;
    width: 35%;
}

.fm-copy-move-method-row {
    display: flex;
    flex-wrap: nowrap;
    align-items: center;
    gap: 0.65rem 1rem;
    margin: 0.75rem 0 0;
}

.fm-copy-move-method-row .fm-compress-type-label {
    flex-shrink: 0;
}

.fm-copy-move-tree-panel {
    margin: 0.5rem 0 0.75rem;
}

.fm-copy-move-tree-toolbar {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 0.35rem 0.75rem;
    justify-content: flex-start;
    margin-bottom: 6px;
}

.fm-copy-move-new-folder-kbd {
    font-size: 0.75rem;
    color: #666;
    user-select: none;
}

.fm-copy-move-new-folder-btn {
    display: inline-flex;
    align-items: center;
    gap: 0.35rem;
    padding: 5px 10px;
    font-size: 0.8125rem;
    border: 1px solid #ccc;
    border-radius: 6px;
    background: #fff;
    color: #333;
    cursor: pointer;
}

.fm-copy-move-new-folder-btn:hover {
    background: #f3f3f3;
    border-color: #bbb;
}

.fm-copy-move-new-folder-btn .bi {
    font-size: 1rem;
    line-height: 1;
}

.fm-copy-move-tree-host {
    height: 280px;
    max-height: 280px;
    display: flex;
    flex-direction: column;
    overflow: hidden;
    margin: 0;
    border: 1px solid #e0e0e0;
    border-radius: 4px;
    padding: 0;
    text-align: left;
    background: #fafafa;
}

.fm-copy-move-tree-inner {
    flex: 1;
    min-height: 0;
    overflow: auto;
    padding: 4px 6px;
}

.fm-copy-move-tree-loading {
    flex: 1;
    min-height: 0;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    gap: 0.65rem;
    padding: 12px;
    color: #555;
    font-size: 0.875rem;
}

.fm-copy-move-tree-spinner {
    width: 28px;
    height: 28px;
    border: 3px solid rgba(0, 0, 0, 0.12);
    border-top-color: var(--main-color);
    border-radius: 50%;
    animation: spin 0.8s linear infinite;
}

.fm-copy-move-tree-error {
    color: #b42318;
    font-size: 0.875rem;
}

.fm-copy-move-tree-inner .fm-tree-link.fm-copy-move-kbd-focus {
    outline: 2px solid var(--main-color);
    outline-offset: 2px;
    border-radius: 3px;
}

.fm-copy-move-name {
    margin: 4px 0 0 0;
    font-size: 11px;
    color: var(--muted-text);
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

.fm-upload-into {
    margin: 0 0 0.35rem;
    font-size: 0.9rem;
}

.fm-upload-hint {
    margin: 0 0 0.65rem;
    font-size: 0.8125rem;
    color: #555;
    line-height: 1.45;
}

.fm-upload-hint .fm-upload-php-ini code {
    font-size: 1.08em;
    font-weight: 600;
    color: #333;
}

.fm-upload-hint .fm-upload-php-val {
    font-size: 0.8125rem;
    font-weight: 500;
    color: #444;
}

.fm-upload-dropzone {
    border: 2px dashed #bbb;
    border-radius: 8px;
    padding: 1.75rem 1rem;
    text-align: center;
    background: #f8f8f8;
    cursor: default;
    transition: border-color 0.15s, background 0.15s;
}

.fm-upload-dropzone--active {
    border-color: var(--main-color);
    background: rgba(218, 97, 91, 0.06);
}

.fm-upload-dropzone-text {
    display: inline-flex;
    align-items: center;
    gap: 0.5rem;
    color: #444;
    font-size: 0.9rem;
}

.fm-upload-dropzone-text .bi {
    font-size: 1.5rem;
    color: var(--main-color);
}

.fm-upload-browse-row {
    display: flex;
    flex-wrap: wrap;
    gap: 0.5rem;
    margin: 0.65rem 0 0.75rem;
    align-items: center;
}

.fm-upload-browse-row .fm-btn {
    padding: 8px 12px;
    font-size: 0.8125rem;
    border-radius: 8px;
}

.fm-upload-file-count {
    margin-left: auto;
    margin-right: 16px;
    color: #444;
    font-size: 0.87rem;
    font-variant-numeric: tabular-nums;
    align-self: center;
}

.fm-upload-queue-wrap {
    margin-top: 0.25rem;
    border: 1px solid #e0e0e0;
    border-radius: 6px;
    background: #fafafa;
    max-height: 320px;
    overflow: auto;
}

.fm-upload-queue-title {
    padding: 6px 10px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 0.75rem;
    font-size: 0.75rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.03em;
    color: #666;
    border-bottom: 1px solid #e8e8e8;
    background: #f3f3f3;
}

.fm-upload-queue-count {
    color: #444;
    font-size: 0.8rem;
    font-variant-numeric: tabular-nums;
}

.fm-upload-queue {
    padding: 4px 0;
}

.fm-upload-queue-empty {
    margin: 0;
    padding: 0.75rem 10px;
    font-size: 0.8125rem;
    color: #777;
}

.fm-upload-queue-row {
    display: flex;
    flex-direction: column;
    gap: 6px;
    padding: 8px 10px;
    font-size: 0.8125rem;
    border-bottom: 1px solid #eee;
}

.fm-upload-queue-row:last-child {
    border-bottom: none;
}

.fm-upload-queue-row-top {
    display: grid;
    grid-template-columns: 1fr auto auto;
    gap: 0.5rem;
    align-items: center;
}

.fm-upload-queue-name {
    min-width: 0;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.fm-upload-queue-size {
    color: #666;
    flex-shrink: 0;
    font-variant-numeric: tabular-nums;
}

.fm-upload-queue-remove {
    border: none;
    background: transparent;
    color: #888;
    font-size: 1.1rem;
    line-height: 1;
    cursor: pointer;
    padding: 0 4px;
    border-radius: 4px;
}

.fm-upload-queue-remove:hover {
    color: #b42318;
    background: rgba(0, 0, 0, 0.05);
}

.fm-upload-queue.fm-upload-queue--uploading .fm-upload-queue-remove {
    visibility: hidden;
    pointer-events: none;
}

.fm-upload-popup--busy .fm-upload-dropzone,
.fm-upload-popup--busy .fm-upload-browse-row {
    opacity: 0.55;
    pointer-events: none;
}

.fm-upload-queue-progress-wrap {
    width: 100%;
}

.fm-upload-queue-progress-track {
    height: 6px;
    background: #e4e4e4;
    border-radius: 3px;
    overflow: hidden;
}

.fm-upload-queue-progress-bar {
    height: 100%;
    width: 0;
    max-width: 100%;
    background: var(--main-color);
    border-radius: 3px;
    transition: width 0.08s linear;
}

.fm-upload-queue-progress-wrap.fm-upload-progress--error .fm-upload-queue-progress-bar {
    background: #b42318;
}

.fm-upload-queue-progress-wrap.fm-upload-progress--done .fm-upload-queue-progress-bar {
    background: #2d6a2d;
}

.fm-upload-queue-item-status {
    font-size: 0.75rem;
    color: #666;
    min-height: 1.1em;
}

.fm-upload-queue-item-status.fm-upload-item-status--ok {
    color: #2d6a2d;
}

.fm-upload-queue-item-status.fm-upload-item-status--err {
    color: #b42318;
}

.fm-upload-status {
    margin: 0.5rem 0 0;
    font-size: 0.8125rem;
    color: #444;
}

.fm-compress-type-row {
    margin: 0.75rem 0 0 0;
    display: flex;
    flex-wrap: nowrap;
    gap: 1rem;
}

.fm-compress-type-row .fm-option-label {
    display: flex;
}

.fm-compress-type-label {
    flex-shrink: 0;
}

.fm-option-label {
    cursor: pointer;
    white-space: nowrap;
}

.fm-option-label input {
    margin-right: 0.25rem;
}

.fm-download-archive-type-row {
    display: flex;
    flex-wrap: nowrap;
    gap: 1rem;
}

.fm-download-archive-type-row .fm-option-label {
    display: flex;
}

.fm-download-archive-type-label {
    flex-shrink: 0;
}

.fm-compress-exists-notice {
    color: #b42318;
    font-size: 0.8125rem;
    line-height: 1.45;
    margin-top: 0.35rem;
}

.fm-compress-override-row {
    margin-top: 0.25rem;
}

.fm-exec-disabled-notice {
    margin: 0.75rem 0 0;
    padding: 0.65rem 0.75rem;
    font-size: 0.8125rem;
    line-height: 1.45;
    color: var(--muted-text, #5c5f62);
    background: rgba(255, 193, 7, 0.12);
    border: 1px solid rgba(200, 150, 0, 0.28);
    border-radius: 6px;
}

.fm-exec-disabled-notice code {
    font-size: 0.9em;
    padding: 0.1em 0.35em;
    border-radius: 4px;
    background: rgba(0, 0, 0, 0.06);
}

.fm-option-label--disabled {
    user-select: none;
}

button.fm-popup-action-button.fm-popup-btn-disabled,
.fm-popup-action-button:disabled {
    opacity: 0.45;
    cursor: not-allowed !important;
    pointer-events: none;
}

.fm-option-hint {
    margin-top: 1rem;
    padding-top: 0.75rem;
    border-top: 1px solid rgba(0, 0, 0, 0.08);
    font-size: 0.8125rem;
    line-height: 1.45;
    color: var(--muted-text, #6c757d);
    transition: color 0.15s ease, border-color 0.15s ease;
}

.fm-option-hint-text {
    display: block;
}

.fm-option-hint--excellent {
    color: #16823b;
    border-top-color: rgba(22, 130, 59, 0.22);
}

.fm-option-hint--good {
    color: #1f7a45;
    border-top-color: rgba(31, 122, 69, 0.22);
}

.fm-option-hint--medium {
    color: #9a6b00;
    border-top-color: rgba(154, 107, 0, 0.20);
}

.fm-option-hint--slow {
    color: #cf680e;
    border-top-color: rgba(207, 104, 14, 0.20);
}

.fm-option-hint--veryslow {
    color: #b42318;
    border-top-color: rgba(180, 35, 24, 0.22);
}

.fm-delete-hint {
    margin-top: 10px;
}

.fm-compress-hint-intro,
.fm-extract-selected-count,
.fm-copy-move-selected-text {
    margin-top: 0;
}

.fm-extract-progress-wrap,
.fm-copy-move-progress-wrap,
.fm-delete-progress-wrap {
    margin-top: 8px;
}

.fm-delete-phase,
.fm-copy-move-phase {
    margin: 6px 0 0 0;
}

.fm-copy-move-count-wrap {
    margin: 4px 0 0 0;
    display: none;
}

.fm-extract-option-hint {
    margin: 0 0 0.5rem 0;
}

.fm-extract-wait {
    margin: 8px 0 0 0;
}

.fm-copy-move-intro {
    margin: 0 0 0.35rem 0;
}

.fm-progress-bar-bg {
    height: 10px;
    background: rgba(0, 0, 0, 0.08);
    border-radius: 8px;
    overflow: hidden;
}

.fm-progress-bar-fill {
    height: 100%;
    width: 0;
    background: var(--main-color);
}

.fm-delete-bar {
    height: 100%;
    width: 0;
    background: var(--main-color);
}

.fm-progress-bar-bg.fm-progress-indeterminate .fm-delete-bar {
    width: 35%;
    animation: fm-progress-indeterminate 1.2s ease-in-out infinite;
}

.fm-info-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 0.875rem;
    line-height: 1.5;
}

.fm-info-table th,
.fm-info-table td {
    padding: 5px 8px;
    text-align: left;
    vertical-align: top;
    border-bottom: 1px solid rgba(0, 0, 0, 0.06);
}

.fm-info-table th {
    width: 30%;
    font-weight: 600;
    color: var(--muted-text, #6c757d);
    white-space: nowrap;
}

.fm-info-table td {
    word-break: break-all;
}

.fm-info-table tr:last-child th,
.fm-info-table tr:last-child td {
    border-bottom: none;
}

.fm-info-table .fm-info-muted {
    color: var(--muted-text, #6c757d);
    word-break: normal;
}

.fm-shortcuts-intro {
    margin: 0 0 1rem;
    font-size: 0.875rem;
    line-height: 1.5;
    color: var(--muted-text, #5c5f62);
}

.fm-shortcuts-sheet {
    margin: 0;
    overflow-x: auto;
    -webkit-overflow-scrolling: touch;
}

.fm-shortcuts-section {
    margin: 0 0 1rem;
}

.fm-shortcuts-section:last-child {
    margin-bottom: 0;
}

.fm-shortcuts-section-title {
    margin: 0 0 0.35rem;
    padding: 0;
    font-size: 0.625rem;
    font-weight: 700;
    letter-spacing: 0.1em;
    text-transform: uppercase;
    color: var(--muted-text, #6a6d70);
    line-height: 1.2;
}

.fm-shortcuts-list {
    list-style: none;
    margin: 0;
    padding: 0;
    font-size: 0.8125rem;
    line-height: 1.35;
}

.fm-shortcuts-item {
    padding: 0.4rem 0;
    display: flex;
    flex-direction: row;
    flex-wrap: nowrap;
    align-items: flex-start;
    gap: 0.5rem 0.65rem;
    border-bottom: 1px solid rgba(0, 0, 0, 0.06);
}

.fm-shortcuts-item:last-child {
    border-bottom: none;
}

.fm-shortcuts-item .fm-shortcuts-keys {
    flex: 0 0 38%;
    max-width: 50%;
    white-space: nowrap;
}

.fm-shortcuts-item .fm-shortcuts-desc {
    flex: 1 1 auto;
    min-width: 0;
    margin: 0;
    padding: 0;
    color: var(--muted-text, #5c5f62);
    font-size: 0.8125rem;
    line-height: 1.35;
}

.fm-shortcuts-keys .fm-shortcuts-kbd-row {
    display: inline-flex;
    flex-wrap: nowrap;
    align-items: center;
    gap: 0.12rem;
    vertical-align: middle;
}

.fm-popup-container .fm-shortcuts-sheet .fm-kbd {
    display: inline-block;
    margin: 0;
    padding: 0.05rem 0.3rem 0.08rem;
    min-width: 1em;
    text-align: center;
    font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, "Liberation Mono", monospace;
    font-size: 0.6875rem;
    font-weight: 600;
    line-height: 1.25;
    color: #333;
    background: #ececec;
    border: 1px solid #bbb;
    border-radius: 2px;
    box-shadow: none;
}

.fm-shortcuts-plus,
.fm-shortcuts-alt {
    font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
    font-size: 0.625rem;
    font-weight: 600;
    color: #888;
    padding: 0 0.02rem;
}

.fm-shortcuts-alt {
    padding: 0 0.12rem;
}

.fm-shortcuts-mac {
    display: inline-block;
    margin-left: 0.2rem;
    font-size: 0.625rem;
    font-weight: 500;
    color: var(--muted-text, #6c757d);
    white-space: nowrap;
}

.fm-chmod-popup .fm-chmod-intro {
    margin: 0 0 0.75rem;
    font-size: 0.875rem;
    line-height: 1.45;
    color: var(--muted-text, #5c5f62);
}

.fm-chmod-popup .fm-chmod-error {
    margin: 0 0 0.5rem;
}

.fm-chmod-popup .fm-chmod-fieldset {
    border: 1px solid #ddd;
    border-radius: 4px;
    padding: 0.65rem 0.75rem 0.75rem;
    margin: 0 0 0.75rem;
}

.fm-chmod-popup .fm-chmod-fieldset:last-of-type {
    margin-bottom: 0;
}

.fm-chmod-popup .fm-chmod-fieldset legend {
    font-size: 0.75rem;
    font-weight: 700;
    padding: 0 0.25rem;
}

.fm-chmod-popup .fm-chmod-count {
    font-weight: 600;
    color: var(--muted-text, #6c757d);
}

.fm-chmod-popup .fm-chmod-hint {
    margin: 0 0 0.5rem;
    font-size: 0.75rem;
    line-height: 1.4;
    color: var(--muted-text, #6c757d);
}

.fm-chmod-popup .fm-chmod-hint code,
.fm-chmod-popup .fm-chmod-intro code {
    font-size: 0.8125em;
    font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
}

.fm-chmod-popup .fm-chmod-rec-label {
    display: block;
    margin: 0 0 0.5rem;
    font-size: 0.8125rem;
    cursor: pointer;
}

.fm-chmod-popup .fm-chmod-octal-row {
    margin: 0.35rem 0 0.5rem;
}

.fm-chmod-popup .fm-chmod-octal-label {
    font-size: 0.8125rem;
    font-weight: 600;
}

.fm-chmod-popup .fm-chmod-octal-label .fm-input {
    width: 4.25rem;
    font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
}

.fm-chmod-popup .fm-chmod-bits {
    display: flex;
    flex-wrap: wrap;
    gap: 0.65rem 1rem;
    margin-top: 0.35rem;
    align-items: flex-start;
}

.fm-chmod-popup .fm-chmod-bits-group {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 0.35rem 0.5rem;
    min-width: 0;
}

.fm-chmod-popup .fm-chmod-bits-heading {
    display: block;
    width: 100%;
    margin: 0;
    font-size: 0.625rem;
    font-weight: 700;
    letter-spacing: 0.06em;
    text-transform: uppercase;
    color: var(--muted-text, #6a6d70);
}

.fm-chmod-popup .fm-chmod-bit {
    font-size: 0.8125rem;
    cursor: pointer;
    user-select: none;
}

.fm-bulk-rename-popup .fm-br-intro {
    margin: 0 0 0.75rem;
    font-size: 0.875rem;
    line-height: 1.45;
    color: var(--muted-text, #5c5f62);
}

.fm-bulk-rename-popup .fm-br-controls {
    display: grid;
    gap: 0.45rem;
    margin-bottom: 0.65rem;
}

.fm-bulk-rename-popup .fm-br-row {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    flex-wrap: wrap;
}

.fm-bulk-rename-popup .fm-br-label {
    flex: 0 0 7.5rem;
    font-size: 0.8125rem;
    font-weight: 600;
}

.fm-bulk-rename-popup .fm-br-row .fm-input {
    flex: 1 1 12rem;
    min-width: 0;
}

.fm-bulk-rename-popup .fm-br-check {
    display: block;
    font-size: 0.8125rem;
    cursor: pointer;
    margin: 0.15rem 0;
}

.fm-bulk-rename-popup .fm-br-counter-row {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 0.35rem 0.75rem;
    margin-top: 0.25rem;
}

.fm-bulk-rename-popup .fm-br-counter-fields {
    display: inline-flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 0.35rem 0.65rem;
    font-size: 0.75rem;
}

.fm-bulk-rename-popup .fm-br-counter-fields input[type="number"],
.fm-bulk-rename-popup .fm-br-counter-fields input[type="text"] {
    width: 3.25rem;
    padding: 0.2rem 0.35rem;
    font-size: 0.8125rem;
    display: inline;
    margin-top: 0;
    border-radius: 5px;
    border: 1px solid #767676;
}

.fm-bulk-rename-popup .fm-br-counter-fields .fm-br-c-sep {
    width: 2.5rem;
}

.fm-bulk-rename-popup .fm-br-preview-wrap {
    max-height: 220px;
    overflow: auto;
    border: 1px solid rgba(0, 0, 0, 0.1);
    border-radius: 4px;
    margin-top: 0.35rem;
}

.fm-bulk-rename-popup .fm-br-preview {
    width: 100%;
    border-collapse: collapse;
    font-size: 0.8125rem;
    line-height: 1.35;
}

.fm-bulk-rename-popup .fm-br-preview th,
.fm-bulk-rename-popup .fm-br-preview td {
    padding: 0.3rem 0.5rem;
    text-align: left;
    border-bottom: 1px solid rgba(0, 0, 0, 0.06);
    vertical-align: top;
}

.fm-bulk-rename-popup .fm-br-preview th {
    position: sticky;
    top: 0;
    background: #f8f9fa;
    font-weight: 600;
    font-size: 0.75rem;
    z-index: 1;
}

.fm-bulk-rename-popup .fm-br-preview-cell--err {
    color: #b02a37;
    font-weight: 600;
}

.fm-bulk-rename-popup .fm-br-cell-hint {
    font-weight: 500;
    font-size: 0.75rem;
}

.fm-bulk-rename-popup .fm-br-err {
    margin-top: 0.5rem;
}

.fm-terminal-popup .fm-popup-container {
    height: min(94vh, 980px);
    max-height: min(96vh, calc(100vh - 8px));
}

.fm-terminal-popup .fm-popup-content {
    display: flex;
    flex-direction: column;
    min-height: 0;
    padding: 14px 14px 10px;
}

.fm-terminal-popup .fm-terminal-intro {
    margin-bottom: 4px;
}

.fm-terminal-popup .fm-terminal-path {
    margin: 0 0 8px;
    font-size: 12px;
    color: #666;
    word-break: break-all;
}

.fm-terminal-popup .fm-terminal-controls {
    margin-bottom: 6px;
}

.fm-terminal-popup .fm-terminal-controls .fm-terminal-cmd {
    margin-left: 8px;
    min-width: 280px;
}

.fm-terminal-popup .fm-terminal-manual-row {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    margin: 0 0 6px;
    font-size: 13px;
}

.fm-terminal-popup .fm-terminal-manual-row--disabled {
    opacity: 0.6;
}

.fm-terminal-popup .fm-terminal-manual-input-wrap {
    display: block;
    margin: 0 0 4px;
}

.fm-terminal-popup .fm-terminal-manual-input-wrap .fm-terminal-user-cmd {
    margin-left: 8px;
    min-width: 420px;
}

.fm-terminal-popup .fm-terminal-manual-hint {
    margin: 0 0 6px;
    color: #666;
    font-size: 12px;
}

.fm-terminal-popup .fm-terminal-safety-note {
    margin: 0 0 6px;
    color: #5e6570;
    font-size: 12px;
}

.fm-terminal-popup .fm-terminal-output {
    margin: 0;
    padding: 10px;
    flex: 1 1 auto;
    min-height: 280px;
    max-height: none;
    overflow: auto;
    border: 1px solid #ddd;
    border-radius: 6px;
    background: #0f1115;
    color: #dce3ea;
    font-size: 12px;
    line-height: 1.45;
    font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", "Courier New", monospace;
}

.fm-terminal-popup .fm-terminal-output-log {
    margin: 0;
    white-space: pre-wrap;
    word-break: break-word;
}

.fm-terminal-popup .fm-terminal-console-row {
    margin-top: 8px;
    display: flex;
    align-items: center;
    gap: 6px;
}

.fm-terminal-popup .fm-terminal-console-suggestions {
    margin-top: 8px;
    color: #8dc2ff;
    font-size: 11px;
    white-space: pre-wrap;
}

.fm-terminal-popup .fm-terminal-console-prompt {
    color: #7ed0a5;
    font-weight: 600;
}

.fm-terminal-popup .fm-terminal-console-input {
    flex: 1 1 auto;
    min-width: 0;
    border: none;
    outline: none;
    background: transparent;
    color: #dce3ea;
    font: inherit;
}

.fm-terminal-popup .fm-terminal-console-input::placeholder {
    color: #7f8b96;
}

.fm-popup-content .fm-cfg-permissions-fieldset {
    border: 1px solid #ddd;
    border-radius: 4px;
    padding: 8px 10px;
    margin: 10px 0 0;
}

.fm-popup-content .fm-cfg-permissions-fieldset legend {
    font-size: 12px;
    padding: 0 4px;
    font-weight: 600;
}

.fm-popup-content .fm-cfg-perm-option {
    display: block;
    margin: 4px 0;
    cursor: pointer;
}

.fm-popup-content .fm-cfg-perm-example {
    color: var(--muted-text, #6c757d);
    font-size: 12px;
}

.fm-popup-content .fm-cfg-server-fieldset {
    border: 1px solid #c9d7e8;
    border-radius: 4px;
    padding: 8px 10px;
    margin: 0 0 12px;
    background: rgba(0, 80, 180, 0.04);
}

.fm-popup-content .fm-cfg-server-fieldset legend {
    font-size: 12px;
    padding: 0 4px;
    font-weight: 600;
}

.fm-popup-content .fm-cfg-server-legend-hint {
    font-weight: 400;
    color: var(--muted-text, #6c757d);
}

.fm-popup-content .fm-cfg-server-line {
    margin: 4px 0 2px;
    font-size: 13px;
}

.fm-popup-content .fm-cfg-server-label {
    color: var(--muted-text, #6c757d);
    margin-right: 4px;
}

.fm-popup-content .fm-cfg-server-desc,
.fm-popup-content .fm-cfg-server-edit-hint {
    margin: 4px 0 0;
    font-size: 12px;
    color: var(--muted-text, #6c757d);
    line-height: 1.4;
}

.fm-popup-content .fm-cfg-hint {
    font-size: 12px;
    color: #666;
    font-weight: normal;
}

.fm-popup-content .fm-cfg-server-edit-hint code {
    font-size: 11px;
}

.fm-popup-content .fm-cfg-fileops-option {
    display: flex;
    align-items: flex-start;
    gap: 8px;
    margin: 8px 0 0;
    font-size: 13px;
    cursor: pointer;
    line-height: 1.35;
}
.fm-popup-content .fm-cfg-fileops-option input {
    margin-top: 2px;
    flex-shrink: 0;
}
.fm-popup-content .fm-cfg-fileops-option.fm-cfg-fileops-disabled {
    opacity: 0.55;
    cursor: not-allowed;
}
.fm-popup-content .fm-cfg-fileops-pass {
    display: flex;
    flex-direction: column;
    gap: 4px;
    margin-top: 12px;
    font-size: 12px;
    color: #444;
}
.fm-popup-content .fm-cfg-fileops-pass .fm-input {
    width: 100%;
    min-width: 0;
}

.fm-info-loading {
    padding: 1.5rem 0;
    color: var(--muted-text, #6c757d);
}

.fm-error-text {
    color: #b42318;
}

.fm-spinner {
    display: inline-block;
    width: 1em;
    height: 1em;
    border: 2px solid currentColor;
    border-right-color: transparent;
    border-radius: 50%;
    animation: fm-spin 0.6s linear infinite;
    vertical-align: middle;
    margin-right: 0.25em;
}

@keyframes fm-spin {
    to { transform: rotate(360deg); }
}

.fm-rotating {
    animation: fm-spin 0.6s linear infinite;
    display: inline-block;
    backface-visibility: hidden;
}

.fm-table-toolbar {
    display: flex;
    align-items: center;
    flex-wrap: wrap;
    flex-shrink: 0;
    padding: 6px 11px;
    border-bottom: 1px solid #fff;
    background-color: #dcdcdc;
}

.fm-table-action-btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    padding: 5px;
    margin-left: 6px;
    border: none;
    background: transparent;
    color: #373737;
    font-size: 22px;
    line-height: normal;
    cursor: pointer;
    transition: all 300ms;
}

.fm-table-action-btn:first-child {
    margin-left: 0;
}

.fm-table-action-btn:hover:not(.disabled) {
    background-color: #afafaf;
}

.fm-table-action-btn.disabled {
    opacity: .4;
    cursor: default;
}

.fm-term-settings-options {
    display: flex;
    flex-direction: column;
    gap: 8px;
    margin: 12px 0;
}
.fm-term-settings-options label {
    display: flex;
    align-items: center;
    gap: 8px;
    font-size: 12px;
    color: #333;
    cursor: pointer;
    line-height: 1.3;
}
.fm-term-settings-options input[type="checkbox"] {
    margin: 0;
    flex-shrink: 0;
}

.fm-popup-header .fm-terminal-settings-btn {
    position: absolute;
    top: 50%;
    right: 40px;
    transform: translateY(-50%);
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 28px;
    height: 28px;
    padding: 0;
    border: none;
    border-radius: 6px;
    background: transparent;
    color: #fff;
    font-size: 16px;
    line-height: 1;
    cursor: pointer;
}
.fm-popup-header .fm-terminal-settings-btn i {
    display: block;
    line-height: 1;
}
.fm-popup-header .fm-terminal-settings-btn:hover {
    background: rgba(255, 255, 255, .18);
}

.fm-table-action-btn i {
    pointer-events: none;
}

.fm-table-action-btn i::before {
    display: block;
}

.fm-table-action-separator {
    width: 2px;
    height: 32px;
    margin-left: 10px;
    margin-right: 10px;
    background-color: #fff;
}

.fm-table-action-separator+button {
    margin-left: 0;
}

.fm-breadcrumb-bar {
    display: flex;
    flex-direction: column;
    align-items: stretch;
    flex-shrink: 0;
    gap: 8px;
    padding: 8px 10px;
    background: #f0f0f0;
    border-bottom: 1px solid #ccc;
    font-size: 13px;
}

.fm-breadcrumb-nav {
    display: flex;
    align-items: center;
    flex-wrap: wrap;
    gap: 6px 8px;
    flex-shrink: 0;
    min-width: 0;
}

.fm-goto-path-form {
    display: flex;
    align-items: center;
    gap: 4px;
    flex: 1 1 220px;
    min-width: 160px;
}

.fm-goto-path-input {
    flex: 1 1 auto;
    min-width: 0;
    padding: 4px 8px;
    border: 1px solid #bbb;
    border-radius: 4px;
    background: #fff;
    color: #222;
    font: inherit;
    font-size: 12px;
}

.fm-goto-path-input:focus {
    outline: none;
    border-color: var(--main-color);
}

.fm-goto-path-btn {
    padding: 4px 10px;
    border: 1px solid #bbb;
    border-radius: 4px;
    background: #fff;
    color: #333;
    font: inherit;
    font-size: 12px;
    font-weight: 600;
    cursor: pointer;
}

.fm-goto-path-btn:hover {
    background: #e8e8e8;
    border-color: #999;
}

.fm-breadcrumb-count {
    display: inline-flex;
    align-items: center;
    margin-left: 4px;
    padding: 0 8px;
    color: #444;
    font-size: 12px;
    line-height: 1.4;
    white-space: nowrap;
}

.fm-breadcrumb-nav-btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 27px;
    height: 27px;
    padding: 0;
    border: 1px solid #bbb;
    border-radius: 4px;
    background: #fff;
    color: #333;
    cursor: pointer;
    transition: background 150ms, border-color 150ms;
}

.fm-breadcrumb-nav-btn:hover:not(:disabled) {
    background: #e8e8e8;
    border-color: #999;
}

.fm-breadcrumb-nav-btn:disabled {
    opacity: .35;
    cursor: default;
}

.fm-breadcrumb-nav-btn i::before {
    display: block;
}

.fm-breadcrumb-path {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 4px 2px;
    min-width: 0;
    width: 100%;
    justify-content: flex-start;
    overflow: hidden;
}

.fm-breadcrumb-crumb {
    max-width: 100%;
    padding: 4px 8px;
    border: none;
    border-radius: 4px;
    background: transparent;
    color: #373737;
    font: inherit;
    font-size: 13px;
    cursor: pointer;
    text-align: left;
    text-decoration: underline;
    text-underline-offset: 2px;
    white-space: nowrap;
}

.fm-breadcrumb-crumb:hover {
    background: rgba(0, 0, 0, .06);
}

.fm-breadcrumb-crumb--current {
    color: var(--main-color);
    font-weight: 600;
    text-decoration: none;
    cursor: default;
    padding: 4px 8px;
}

.fm-breadcrumb-sep {
    display: inline-flex;
    align-items: center;
    color: #888;
    font-size: 10px;
    user-select: none;
}

.fm-breadcrumb-sep i::before {
    display: block;
}

.fm-breadcrumb-ellipsis {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-width: 28px;
    height: 27px;
    padding: 0 6px;
    margin-left: 6px;
    margin-right: 6px;
    border: 1px solid #bbb;
    border-radius: 4px;
    background: #fff;
    color: #444;
    font: inherit;
    cursor: pointer;
    vertical-align: middle;
    transition: background 150ms, border-color 150ms;
}

.fm-breadcrumb-ellipsis:hover {
    background: #e8e8e8;
    border-color: #999;
}

.fm-breadcrumb-ellipsis i::before {
    display: block;
    line-height: 1;
}

.fm-breadcrumb-ellipsis--less {
    margin-left: 10px;
    color: var(--main-color, #0d6efd);
}

.fm-table-icon-cell {
    align-items: center;
    justify-content: center;
    gap: 6px;
    flex-wrap: nowrap;
}

.fm-table-icon-cell--image {
    width: 32px;
    height: 28px;
    display: flex;
    justify-content: center;
    overflow: hidden;
    line-height: 0;
    align-items: normal;
}

.fm-table-icon-preview {
    display: block;
    object-fit: contain;
    object-position: center;
    border-radius: 2px;
    background: rgba(0, 0, 0, .06);
    flex-shrink: 0;
}

.fm-table td.fm-table-col-icon .fm-table-icon-preview {
    pointer-events: none;
}

.fm-sidebar-footer {
    display: flex;
    flex-direction: column;
    gap: 6px;
    padding: 8px;
    border: 1px solid #ccc;
    border-radius: 8px;
    background: #f5f5f5;
}

.fm-sidebar-trash-btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 6px;
    width: 100%;
    padding: 8px 10px;
    border: 1px solid #bbb;
    border-radius: 4px;
    background: #fff;
    color: #333;
    font: inherit;
    font-size: 13px;
    cursor: pointer;
    transition: background 150ms, border-color 150ms;
}

.fm-sidebar-trash-btn:hover {
    background: #eee;
    border-color: #999;
}

.fm-sidebar-trash-btn--active,
.fm-sidebar-trash-btn.fm-sidebar-trash-btn--active:hover {
    background: var(--main-color);
    border-color: var(--main-color, #0d6efd);
    color: #fff;
    font-weight: 600;
    box-shadow: inset 0 0 0 1px rgba(13, 110, 253, 0.25);
}

.fm-sidebar-trash-btn .bi {
    font-size: 1.1em;
}

.fm-sidebar-trash-btn--has-items:not(.fm-sidebar-trash-btn--active) {
    border-color: var(--main-color, #0d6efd);
    color: var(--main-color, #0d6efd);
    font-weight: 600;
}

.fm-sidebar-trash-count {
    display: none;
    min-width: 18px;
    padding: 1px 6px;
    border-radius: 9px;
    background: var(--main-color, #0d6efd);
    color: #fff;
    font-size: 11px;
    font-weight: 600;
    line-height: 16px;
    text-align: center;
}

.fm-sidebar-trash-btn--has-items .fm-sidebar-trash-count {
    display: inline-block;
}

.fm-sidebar-trash-btn--active .fm-sidebar-trash-count {
    background: #fff;
    color: var(--main-color, #0d6efd);
}

.fm-sidebar-trash-hint {
    margin: 6px 2px 0;
    font-size: 11px;
    line-height: 1.35;
    color: #666;
}

.fm-table {
    table-layout: fixed;
    width: 100%;
    border-collapse: collapse;
    background: #fff;
    overflow: hidden;
    box-shadow: 0 0 20px rgba(0, 0, 0, .08);
    display: flex;
    flex-direction: column;
    flex: 1;
    min-height: 0;
}

.fm-table thead {
    background-color: #dcdcdc;
    flex-shrink: 0;
    display: block;
}

.fm-table tbody {
    display: block;
    flex: 1;
    min-height: 0;
    overflow-x: hidden;
    overflow-y: auto;
    -webkit-overflow-scrolling: touch;
    scrollbar-gutter: stable;
    scrollbar-width: thin;
    scrollbar-color: rgba(80, 80, 80, 0.55) #ebebeb;
}

.fm-table tbody::-webkit-scrollbar {
    width: 8px;
}

.fm-table tbody::-webkit-scrollbar-track {
    background: #ebebeb;
    border-radius: 4px;
}

.fm-table tbody::-webkit-scrollbar-thumb {
    background: rgba(100, 100, 100, 0.45);
    border-radius: 4px;
}

.fm-table tbody::-webkit-scrollbar-thumb:hover {
    background: rgba(218, 97, 91, 0.65);
}

@media (hover: hover) and (pointer: fine) {
    .fm-table tbody {
        scrollbar-color: transparent transparent;
    }
    .fm-table tbody::-webkit-scrollbar-track {
        background: transparent;
    }
    .fm-table tbody::-webkit-scrollbar-thumb {
        background: transparent;
    }
    .fm-table tbody:hover,
    .fm-table tbody:focus-within {
        scrollbar-color: rgba(80, 80, 80, 0.55) #ebebeb;
    }
    .fm-table tbody:hover::-webkit-scrollbar-track,
    .fm-table tbody:focus-within::-webkit-scrollbar-track {
        background: #ebebeb;
    }
    .fm-table tbody:hover::-webkit-scrollbar-thumb,
    .fm-table tbody:focus-within::-webkit-scrollbar-thumb {
        background: rgba(100, 100, 100, 0.45);
    }
    .fm-table tbody:hover::-webkit-scrollbar-thumb:hover,
    .fm-table tbody:focus-within::-webkit-scrollbar-thumb:hover {
        background: rgba(218, 97, 91, 0.65);
    }
}

.fm-breadcrumb-bar .fm-table-filter-wrap {
    margin-left: auto;
    display: flex;
    align-items: center;
    min-width: 140px;
    width: min(220px, 100%);
    flex: 0 1 200px;
}

.fm-table-filter {
    width: 100%;
    box-sizing: border-box;
    padding: 5px 10px;
    border: 1px solid #bbb;
    border-radius: 4px;
    background: #fff;
    color: #222;
    font: inherit;
    font-size: 13px;
    line-height: 1.3;
}

.fm-table-filter:focus {
    outline: none;
    border-color: var(--main-color, #0d6efd);
    box-shadow: 0 0 0 2px rgba(13, 110, 253, 0.2);
}

@media (max-width: 720px) {
    .fm-breadcrumb-bar .fm-table-filter-wrap {
        margin-left: 0;
        flex: 1 1 100%;
        width: 100%;
        max-width: none;
    }
}

.fm-table tbody tr.fm-dnd-drag-over {
    outline: 2px solid var(--main-color);
    outline-offset: -2px;
}

.fm-breadcrumb-crumb.fm-dnd-drag-over {
    outline: none;
    background: rgba(0, 0, 0, 0.06);
    border-radius: 4px;
}

.fm-tree-link.fm-dnd-drag-over {
    outline: 2px solid var(--main-color);
    outline-offset: 1px;
    border-radius: 4px;
}

.fm-table thead tr,
.fm-table tbody tr {
    display: table;
    width: 100%;
    table-layout: fixed;
}

.fm-table th {
    padding: 10px;
    text-align: left;
    font-weight: 600;
    color: #373737;
    border-bottom: 2px solid #ccc;
    white-space: nowrap;
    font-size: 13px;
}

.fm-table th.fm-table-sortable {
    cursor: pointer;
    user-select: none;
}

.fm-table th.fm-table-sortable:hover {
    background-color: #cbcbcb;
}

.fm-table .fm-table-sort-indicator {
    margin-left: 4px;
    opacity: .5;
    font-size: .85em;
}

.fm-table .fm-table-sort-indicator.fm-table-sort-active {
    opacity: 1;
}

.fm-table th.fm-table-col-name,
.fm-table td.fm-table-col-name {
    width: auto;
    min-width: 0;
    overflow: hidden;
}

.fm-table th.fm-table-col-icon,
.fm-table td.fm-table-col-icon,
.fm-table th.fm-table-col-size,
.fm-table td.fm-table-col-size,
.fm-table th.fm-table-col-type,
.fm-table td.fm-table-col-type,
.fm-table th.fm-table-col-mtime,
.fm-table td.fm-table-col-mtime,
.fm-table th.fm-table-col-permissions,
.fm-table td.fm-table-col-permissions,
.fm-table th.fm-table-col-actions,
.fm-table td.fm-table-col-actions {
    white-space: nowrap;
}

.fm-table th.fm-table-col-icon,
.fm-table td.fm-table-col-icon {
    width: 48px;
    text-align: center;
}

.fm-table th.fm-table-col-size,
.fm-table td.fm-table-col-size {
    width: 124px;
    text-align: right;
}

.fm-table th.fm-table-col-type,
.fm-table td.fm-table-col-type {
    width: 80px;
    text-align: right;
}

.fm-table th.fm-table-col-mtime,
.fm-table td.fm-table-col-mtime {
    width: 148px;
    text-align: right;
}

.fm-table th.fm-table-col-permissions,
.fm-table td.fm-table-col-permissions {
    width: 80px;
    text-align: center;
}

.fm-table th.fm-table-col-actions,
.fm-table td.fm-table-col-actions {
    width: 152px;
    text-align: center;
}

.fm-table.fm-table--trash-view th.fm-table-col-actions,
.fm-table.fm-table--trash-view td.fm-table-col-actions {
    width: 184px;
}

.fm-table tbody tr {
    color: #373737;
    cursor: pointer;
    transition: all 150ms;
}

.fm-table tbody tr:hover {
    background-color: #ededed;
}

.fm-table tbody tr.fm-table-row-selected {
    background-color: var(--main-color);
    color: #fff;
}

.fm-table tbody tr.fm-table-row-focused:not(.fm-table-row-selected) {
    outline: 2px solid var(--main-color);
    outline-offset: -2px;
}

.fm-table tbody tr.fm-table-row-selected .fm-table-name,
.fm-table tbody tr.fm-table-row-selected .fm-table-size,
.fm-table tbody tr.fm-table-row-selected .fm-table-type {
    color: inherit;
}

.fm-table tbody tr.fm-table-row-heartbeat-from-non-selected-to-selected {
    animation: fm-table-row-heartbeat-from-non-selected-to-selected-bg 1s ease-in-out forwards;
}

@keyframes fm-table-row-heartbeat-from-non-selected-to-selected-bg {
    0%, 50%, 100% {
        background-color: transparent;
        color: #373737;
    }
    25%, 75% {
        background-color: var(--main-color);
        color: #fff;
    }
}

.fm-table tbody tr.fm-table-row-heartbeat-from-non-selected-to-selected td.fm-table-col-icon i {
    animation: fm-table-row-heartbeat-from-non-selected-to-selected-icon 1s ease-in-out forwards;
}

@keyframes fm-table-row-heartbeat-from-non-selected-to-selected-icon {
    0%, 50%, 100% {
        color: var(--main-color);
    }
    25%, 75% {
        color: #fff;
    }
}

.fm-table tbody tr.fm-table-row-heartbeat-from-non-selected-to-selected .fm-table-row-action {
    animation: fm-table-row-heartbeat-from-non-selected-to-selected-action 1s ease-in-out forwards;
}

@keyframes fm-table-row-heartbeat-from-non-selected-to-selected-action {
    0%, 50%, 100% {
        color: #373737;
    }
    25%, 75% {
        color: #fff;
    }
}

.fm-table tbody tr.fm-table-row-heartbeat-from-non-selected-to-selected .fm-table-folder-size-trigger {
    animation: fm-table-row-heartbeat-from-non-selected-to-selected-folder-trigger 1s ease-in-out forwards;
}

@keyframes fm-table-row-heartbeat-from-non-selected-to-selected-folder-trigger {
    0%, 50%, 100% {
        color: var(--main-color);
    }
    25%, 75% {
        color: #fff;
    }
}

.fm-table tbody tr.fm-table-row-heartbeat-from-non-selected-to-selected .fm-table-name,
.fm-table tbody tr.fm-table-row-heartbeat-from-non-selected-to-selected .fm-table-size,
.fm-table tbody tr.fm-table-row-heartbeat-from-non-selected-to-selected .fm-table-type {
    color: inherit;
}

.fm-table tbody tr.fm-table-row-heartbeat-from-selected-to-non {
    animation: fm-table-row-heartbeat-from-selected-to-non-bg 1s ease-in-out forwards;
}

@keyframes fm-table-row-heartbeat-from-selected-to-non-bg {
    0%,
    50%,
    100% {
        background-color: var(--main-color);
        color: #fff;
    }
    25%,
    75% {
        background-color: transparent;
        color: #373737;
    }
}

.fm-table tbody tr.fm-table-row-heartbeat-from-selected-to-non td.fm-table-col-icon i {
    animation: fm-table-row-heartbeat-from-selected-to-non-icon 1s ease-in-out forwards;
}

@keyframes fm-table-row-heartbeat-from-selected-to-non-icon {
    0%,
    50%,
    100% {
        color: #fff;
    }
    25%,
    75% {
        color: var(--main-color);
    }
}

.fm-table tbody tr.fm-table-row-heartbeat-from-selected-to-non .fm-table-row-action {
    animation: fm-table-row-heartbeat-from-selected-to-non-action 1s ease-in-out forwards;
}

@keyframes fm-table-row-heartbeat-from-selected-to-non-action {
    0%,
    50%,
    100% {
        color: rgba(255, 255, 255, 0.9);
    }
    25%,
    75% {
        color: #373737;
    }
}

.fm-table tbody tr.fm-table-row-heartbeat-from-selected-to-non .fm-table-folder-size-trigger {
    animation: fm-table-row-heartbeat-from-selected-to-non-folder-trigger 1s ease-in-out forwards;
}

@keyframes fm-table-row-heartbeat-from-selected-to-non-folder-trigger {
    0%,
    50%,
    100% {
        color: rgba(255, 255, 255);
        background-color: rgba(0, 0, 0, 0.07);
    }
    25%,
    75% {
        color: var(--main-color);
        background-color: rgba(0, 0, 0, 0.07);
    }
}

.fm-table tbody tr.fm-table-row-heartbeat-from-selected-to-non .fm-table-name,
.fm-table tbody tr.fm-table-row-heartbeat-from-selected-to-non .fm-table-size,
.fm-table tbody tr.fm-table-row-heartbeat-from-selected-to-non .fm-table-type {
    color: inherit;
}

.fm-table td {
    padding: 6px 10px;
    border-bottom: 1px solid #dee2e6;
    vertical-align: middle;
}

.fm-table td.fm-table-col-icon {
    text-align: center;
    padding-left: 8px;
    padding-right: 8px;
}

.fm-table td.fm-table-col-icon i {
    color: var(--main-color);
    font-size: 22px;
}

.fm-table tbody tr.fm-table-row-selected .fm-table-col-icon i {
    color: #fff;
}

.fm-table tbody tr.fm-table-row-selected .fm-table-col-icon .fm-table-icon-preview {
    opacity: 0.92;
    box-shadow: 0 0 0 1px rgba(255, 255, 255, .35);
}

.fm-table td.fm-table-col-icon i::before {
    display: block;
}

.fm-table-name-cell {
    display: flex;
    align-items: center;
    gap: 6px;
    min-width: 0;
    width: 100%;
}

.fm-table-name-cell--trash {
    flex-direction: column;
    align-items: flex-start;
    gap: 0;
}

.fm-trash-origin {
    display: block;
    max-width: 100%;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
    font-size: 11px;
    color: #777;
}

.fm-table .fm-table-name {
    display: block;
    min-width: 0;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
    font-weight: 500;
    font-size: 13px;
}

.fm-table .fm-table-name--image-preview {
    cursor: pointer;
    text-decoration: underline dotted;
    text-underline-offset: 2px;
    color: var(--main-color, #0d6efd);
}

.fm-table tr.fm-table-row-folder .fm-table-name {
    font-weight: bold;
}

.fm-table td.fm-table-size,
.fm-table td.fm-table-type,
.fm-table td.fm-table-mtime,
.fm-table td.fm-table-permissions {
    text-align: right;
    font-weight: bold;
    font-size: 12px;
}

.fm-table td.fm-table-permissions .fm-perm-both {
    display: inline-block;
    line-height: 1.35;
    text-align: right;
}

.fm-table td.fm-table-permissions .fm-perm-sym {
    color: var(--muted-text, #6c757d);
    font-weight: 600;
}

.fm-table thead .fm-table-size-all-btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 22px;
    height: 22px;
    padding: 0;
    margin-left: 4px;
    border: none;
    background: transparent;
    color: var(--muted-text, #6c757d);
    font-size: 13px;
    cursor: pointer;
    border-radius: 4px;
    vertical-align: middle;
    transition: background-color 150ms, color 150ms;
}

.fm-table thead .fm-table-size-all-btn:hover:not(:disabled) {
    background-color: rgba(0, 0, 0, 0.07);
    color: var(--main-color);
}

.fm-table thead .fm-table-size-all-btn:disabled {
    cursor: default;
    opacity: 0.6;
}

.fm-table-folder-size-value {
    margin-right: 6px;
    vertical-align: middle;
}

.fm-table .fm-table-folder-size-trigger {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 22px;
    height: 22px;
    padding: 0;
    border: none;
    background: transparent;
    background-color: rgba(0, 0, 0, 0.07);
    color: #6c757d;
    font-size: 13px;
    cursor: pointer;
    border-radius: 4px;
    vertical-align: middle;
    transition: background-color 150ms, color 150ms;
    opacity: 1;
}
.fm-table .fm-table-folder-size-trigger:hover {
    color: var(--main-color);
}

.fm-table tbody tr.fm-table-row-selected .fm-table-folder-size-trigger {
    color: rgba(255, 255, 255);
}

.fm-table tbody tr.fm-table-row-selected:hover .fm-table-folder-size-trigger,
.fm-table tbody tr.fm-table-row-selected .fm-table-folder-size-trigger:hover {
    background-color: rgba(255, 255, 255, .2);
    color: #fff;
    opacity: 1;
}

.fm-table-empty {
    flex: 1;
    min-height: 0;
    overflow-y: auto;
    padding: 2rem;
    text-align: center;
    color: #373737;
}

.fm-table-empty-text {
    margin: 0 0 1.2rem;
}

.fm-table-empty-text--trash {
    margin-bottom: 0;
}

.fm-table-empty-text--trash-hint,
.fm-trash-cleanup-hint {
    margin: 8px 0 0;
    font-size: 12px;
    line-height: 1.4;
    color: #666;
}

.fm-breadcrumb-bar .fm-trash-cleanup-hint {
    margin: 0;
    padding: 0 2px;
}

.fm-table-empty-actions {
    display: flex;
    justify-content: center;
    gap: 10px;
    flex-wrap: wrap;
}

.fm-table-empty-btn {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 8px 16px;
    border: 1px solid #bbb;
    border-radius: 5px;
    background: #fff;
    color: #373737;
    font: inherit;
    font-size: 13px;
    cursor: pointer;
    transition: background 150ms, border-color 150ms;
}

.fm-table-empty-btn i {
    font-size: 20px;
}

.fm-table-empty-btn:hover {
    background: #e8e8e8;
    border-color: #999;
}

.fm-table-empty-btn i::before {
    display: block;
}

.fm-table .fm-table-row-action {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 28px;
    height: 28px;
    padding: 0;
    border: none;
    background: transparent;
    color: #373737;
    font-size: 16px;
    cursor: pointer;
    border-radius: 5px;
    transition: background-color 150ms, color 150ms;
    flex-shrink: 0;
    vertical-align: middle;
}

.fm-table .fm-table-row-action .bi {
    font-size: 16px;
    line-height: 1;
}

.fm-table .fm-table-row-action:hover {
    background-color: #dcdcdc;
    color: var(--main-color);
}

.fm-table .fm-table-row-action:focus-visible {
    outline: 2px solid var(--main-color, #0d6efd);
    outline-offset: 1px;
}

.fm-table .fm-table-col-actions .fm-table-row-action {
    margin-left: 4px;
}

.fm-table tbody tr.fm-table-row-selected .fm-table-row-action {
    color: rgba(255, 255, 255, .9);
}

.fm-table tbody tr.fm-table-row-selected .fm-table-row-action:hover {
    background-color: rgba(255, 255, 255, .2);
    color: #fff;
}

@media (max-width: 900px) {
    #sidebar-and-content {
        flex-direction: column;
    }

    #sidebar-block {
        width: auto;
    }

    #fm-ls-resizer {
        display: none;
    }

    #content {
        padding: 8px;
    }
}

@media (max-width: 520px) {
    .fm-table-wrapper {
        padding: 0 8px;
    }

    #sidebar {
        padding: 8px;
    }

    .fm-table-header {
        border-radius: 8px;
    }

    .fm-table-title {
        font-size: 13px;
    }
}

.fm-card {
    max-width: 560px;
    margin: 28px auto;
    background: #fff;
    border-radius: 12px;
    padding: 16px;
    box-shadow: 0 10px 30px rgba(0, 0, 0, .15);
}

.fm-card h1 {
    margin: 0 0 10px;
    font-size: 18px;
}

.fm-card p {
    margin: 8px 0;
    color: #333;
    line-height: 1.35;
}

.fm-row {
    display: flex;
    gap: 8px;
    align-items: center;
    flex-wrap: wrap;
}

.fm-input {
    flex: 1;
    min-width: 240px;
    padding: 10px 12px;
    border: 1px solid rgba(0, 0, 0, .2);
    border-radius: 10px;
    font-size: 13px;
}

.fm-btn {
    padding: 10px 12px;
    border: none;
    border-radius: 10px;
    background: var(--main-color);
    color: #fff;
    cursor: pointer;
    font-weight: 700;
}

.fm-btn.secondary {
    background: #444;
}

.fm-hint {
    font-size: 12px;
    color: #555;
}

.fm-error {
    color: #b00020;
    font-size: 12px;
}

.fm-rename-options {
    display: flex;
    flex-direction: column;
    gap: 8px;
    margin: 12px 0;
}

.fm-rename-option {
    display: flex;
    flex-direction: column;
    align-items: flex-start;
    gap: 4px;
    width: 100%;
    padding: 10px 12px;
    border: 1px solid rgba(0, 0, 0, .18);
    border-radius: 10px;
    background: #f7f7f7;
    cursor: pointer;
    text-align: left;
    font: inherit;
    color: inherit;
    transition: border-color 120ms, background-color 120ms;
}

.fm-rename-option:hover,
.fm-rename-option.is-selected {
    border-color: var(--main-color);
    background: #fff;
}

.fm-rename-option-label {
    font-weight: 700;
    font-size: 12px;
}

.fm-rename-option-name {
    font-family: "Roboto Mono", monospace;
    font-size: 12px;
    word-break: break-all;
    color: #333;
}

.fm-rename-option-hint {
    font-size: 11px;
    color: #666;
}

.fm-pwd-fields {
    display: flex;
    flex-direction: column;
    gap: 8px;
    margin: 12px 0;
}

.fm-pwd-fields label {
    display: flex;
    flex-direction: column;
    gap: 4px;
    font-size: 12px;
    color: #444;
}

.fm-pwd-fields .fm-input {
    width: 100%;
    min-width: 0;
}

.fm-pwd-hash-box {
    margin-top: 10px;
    padding: 10px;
    border: 1px dashed rgba(0, 0, 0, .25);
    border-radius: 10px;
    background: #fafafa;
}

.fm-pwd-hash-box textarea {
    width: 100%;
    min-height: 64px;
    font-family: "Roboto Mono", monospace;
    font-size: 11px;
    resize: vertical;
}

.fm-pwd-hash-actions {
    display: flex;
    gap: 8px;
    margin-top: 8px;
    flex-wrap: wrap;
}

.fm-version-tag {
    font-weight: 500;
    opacity: .9;
}
.fm-edit-file-popup .fm-js-popup-body {
    display: flex;
    flex-direction: column;
    gap: 10px;
}
.fm-edit-meta {
    display: flex;
    align-items: center;
    justify-content: space-between;
    flex-wrap: wrap;
    gap: 8px;
    font-size: 12px;
    color: #5f6368;
}
.fm-edit-path {
    font-family: "Roboto Mono", ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
    background: #f1f3f4;
    padding: 3px 8px;
    border-radius: 4px;
    color: #202124;
    word-break: break-all;
}
.fm-edit-textarea {
    width: 100%;
    height: 62vh;
    min-height: 280px;
    max-height: 75vh;
    box-sizing: border-box;
    padding: 12px;
    border: 1px solid #dadce0;
    border-radius: 6px;
    background: #1e1e1e;
    color: #d4d4d4;
    font-family: "Roboto Mono", ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
    font-size: 13px;
    line-height: 1.5;
    tab-size: 4;
    resize: vertical;
    white-space: pre;
    overflow: auto;
    outline: none;
}
.fm-edit-textarea:focus {
    border-color: #1a73e8;
    box-shadow: 0 0 0 2px rgba(26, 115, 232, .2);
}
.fm-edit-textarea[readonly] {
    opacity: .75;
    cursor: not-allowed;
}
.fm-edit-status {
    font-size: 12px;
    color: #188038;
    min-height: 18px;
}
</style>
    <script>
        const root_dir = <?php echo json_encode($ROOT_DIR); ?>;
        const allow_all_directories = <?php echo !empty($ALLOW_ALL_DIRECTORIES) ? 'true' : 'false'; ?>;
        const sidebarMaxDepth = <?php echo (int)$MAX_TREE_DEPTH; ?>;
        const ajax_url = "./<?php echo htmlspecialchars($thisFileName, ENT_QUOTES); ?>";
        const cms_id = <?php echo json_encode($cmsId); ?>;
        const needs_rename = <?php echo $needsRename ? 'true' : 'false'; ?>;
        const enable_auth = <?php echo $ENABLE_AUTH ? 'true' : 'false'; ?>;
        const solofilemanager_version = <?php echo json_encode(SOLOFILEMANAGER_VERSION); ?>;
        const password_is_default = <?php echo $passwordIsDefault ? 'true' : 'false'; ?>;
        const suggested_name = <?php echo json_encode($suggested); ?>;
        const suggested_name_prefixed = <?php echo json_encode($suggestedPrefixed); ?>;
        const fm_exec_available = <?php echo $FM_EXEC_AVAILABLE ? 'true' : 'false'; ?>;
        const fm_terminal_here_enabled = <?php echo $FM_TERMINAL_HERE_ENABLED ? 'true' : 'false'; ?>;
        const fm_terminal_manual_enabled = <?php echo $FM_TERMINAL_MANUAL_ENABLED ? 'true' : 'false'; ?>;
        const fm_terminal_advanced_enabled = <?php echo $FM_TERMINAL_ADVANCED_ENABLED ? 'true' : 'false'; ?>;
        const fm_file_ops_mode = <?php echo json_encode(fmFileOpsMode()); ?>;
        const fm_verbose_progress_min_items = <?php echo (int)fmVerboseProgressMinItems(); ?>;
        const fm_trash_basename = <?php echo json_encode(fmTrashBasename()); ?>;
        const fm_php_upload_max_filesize = <?php echo json_encode((string)ini_get('upload_max_filesize')); ?>;
        const fm_php_post_max_size = <?php echo json_encode((string)ini_get('post_max_size')); ?>;
        const fm_csrf_token = <?php echo json_encode($csrfToken); ?>;
        function fmPostInit(body) {
            return { method: 'POST', body, headers: { 'X-CSRF-Token': fm_csrf_token } };
        }
        window.fm_exec_available = fm_exec_available;
        window.fm_terminal_here_enabled = fm_terminal_here_enabled;
        window.fm_terminal_manual_enabled = fm_terminal_manual_enabled;
        window.fm_terminal_advanced_enabled = fm_terminal_advanced_enabled;
    </script>
</head>
<body>
    <div class="fm-table-wrapper">
        <div class="fm-table-header">
            <div class="fm-table-title">
                <span>SoloFileManager</span>
                <span class="fm-version-tag" style="color:rgba(255,255,255,.85);font-size:12px">v<?php echo htmlspecialchars(SOLOFILEMANAGER_VERSION, ENT_QUOTES); ?></span>
                <span class="fm-hint" style="color:rgba(255,255,255,.85);font-size:12px"><?php echo htmlspecialchars($thisFileName); ?></span>
            </div>
            <div class="fm-table-header-right">
                <button type="button" id="fm-keyboard-shortcuts-btn" class="fm-table-config-btn" title="Keyboard shortcuts" aria-label="Keyboard shortcuts"><i class="bi bi-keyboard"></i></button>
                <button type="button" id="fm-server-info-btn" class="fm-table-config-btn" title="Server info" aria-label="Server info"><i class="bi bi-hdd-network"></i></button>
                <button type="button" id="fm-table-config-btn" class="fm-table-config-btn" title="Configuration" aria-label="Configuration"><i class="bi bi-gear"></i></button>
            </div>
        </div>

        <div id="sidebar-block">
            <div id="sidebar">
                <div id="sidebar-sections">
                    <div id="fm-bookmarks" class="fm-bookmarks">
                        <div class="fm-bookmarks-head">
                            <span class="fm-bookmarks-title"><i class="bi bi-bookmark-star" aria-hidden="true"></i> Bookmarks</span>
                            <button type="button" id="fm-bookmarks-pin-current" class="fm-sidebar-toolbar-btn fm-bookmarks-pin-btn" title="Pin current folder" aria-label="Pin current folder">
                                <i class="bi bi-bookmark-plus"></i>
                            </button>
                        </div>
                        <div id="fm-bookmarks-list" class="fm-bookmarks-list"></div>
                    </div>
                    <div id="sidebar-tree-block">
                        <div id="fm-sidebar-toolbar">
                            <button type="button" id="fm-ls-collapse-btn" class="fm-sidebar-toolbar-btn" title="Collapse all (except root)" aria-label="Collapse all (except root)"><i class="bi bi-chevron-bar-contract"></i></button>
                        </div>
                        <div id="sidebar-tree"><div class="fm-sidebar-loading-overlay"><div class="fm-sidebar-loading-spinner"></div></div></div>
                    </div>
                    <div class="fm-sidebar-footer">
                        <?php $fmTrashCountNow = fmTrashCount($ROOT_DIR); ?>
                        <button type="button" id="fm-sidebar-trash-btn" class="fm-sidebar-trash-btn<?php echo $fmTrashCountNow > 0 ? ' fm-sidebar-trash-btn--has-items' : ''; ?>" title="Trash (recycle bin)" aria-label="Trash" aria-pressed="false"><i class="bi bi-trash3" aria-hidden="true"></i> Trash <span class="fm-sidebar-trash-count"><?php echo (int)$fmTrashCountNow; ?></span></button>
                        <p class="fm-sidebar-trash-hint" id="fm-sidebar-trash-hint">Deleted items wait here until you restore them or delete them forever.</p>
                    </div>
                </div>
            </div>
            <div id="fm-ls-resizer" aria-label="Resize sidebar" title="Drag to resize sidebar"></div>
        </div>
        <div id="content">
            <div id="fm-table-container"></div>
        </div>
    </div>

<script>
/* ===== Shared utilities ===== */
function norm(p) {
    if (!p) return '';
    const s = String(p).replace(/\\/g, '/');
    if (s === '/') return '/';
    if (/^[a-zA-Z]:\/?$/.test(s)) return s.slice(0, 2) + '/';
    return s.replace(/\/+$/, '');
}

function fmJoinPath(dir, name) {
    const d = String(dir || '').replace(/\\/g, '/');
    if (d === '/') return '/' + name;
    return d.replace(/\/+$/, '') + '/' + name;
}

function fmPathInParam(currentPath, rootDir) {
    if (typeof allow_all_directories !== 'undefined' && allow_all_directories) {
        return currentPath || rootDir || '';
    }
    return currentPath === rootDir ? '' : currentPath.slice(rootDir.length + 1);
}

function escapeHtml(s) {
    if (s == null) return '';
    return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

function parseUrlHash() {
    if (window.location.hash.length <= 1) return {};
    const parsed = {};
    window.location.hash.slice(1).split('&').forEach(part => {
        const eq = part.indexOf('=');
        if (eq > 0) parsed[decodeURIComponent(part.slice(0, eq))] = decodeURIComponent(part.slice(eq + 1));
    });
    return parsed;
}

function getCurrentPath() {
    const h = parseUrlHash();
    return (h.action === 'open' && h.folder) ? h.folder : root_dir;
}

function getParentPath(path) {
    const s = norm(path);
    const root = norm(root_dir);
    const unrestricted = typeof allow_all_directories !== 'undefined' && allow_all_directories;
    if (!s) return unrestricted ? '/' : root_dir;
    if (!unrestricted && s === root) return root_dir;
    if (s === '/') return '/';
    if (/^[a-zA-Z]:\/?$/.test(s)) return s.slice(0, 2) + '/';

    const parts = s.split('/').filter(Boolean);
    if (parts.length <= 1) {
        if (s.startsWith('/')) return '/';
        if (/^[a-zA-Z]:/.test(s)) return parts[0] + '/';
        return unrestricted ? '/' : root_dir;
    }

    const parentParts = parts.slice(0, -1);
    let parent;
    if (s.startsWith('/')) {
        parent = '/' + parentParts.join('/');
    } else if (/^[a-zA-Z]:/.test(s)) {
        parent = parentParts.length === 1 ? parentParts[0] + '/' : parentParts.join('/');
    } else {
        parent = parentParts.join('/');
    }
    parent = norm(parent);

    if (!unrestricted) {
        if (parent === root) return root_dir;
        if (root && root !== '/' && (parent.length < root.length || !parent.startsWith(root))) {
            return root_dir;
        }
    }
    return parent;
}

function shortenMiddle(str, maxLen) {
    if (!str) return '';
    const s = String(str);
    const max = typeof maxLen === 'number' && maxLen > 0 ? maxLen : 50;
    if (s.length <= max) return s;
    if (max <= 3) return s.slice(0, max);
    const keep = max - 3;
    let head = Math.floor(keep / 2);
    let tail = keep - head;
    if (head < 1) head = 1;
    if (tail < 1) tail = 1;
    return s.slice(0, head) + '...' + s.slice(s.length - tail);
}

function fmVerboseProgressThreshold() {
    const v = typeof window.fm_verbose_progress_min_items === 'number' ? window.fm_verbose_progress_min_items : 800;
    return Math.max(0, v);
}

function fmShouldShowVerboseItemProgress(data, fallbackTotal) {
    if (data && typeof data.verbose_progress === 'boolean') {
        return data.verbose_progress;
    }
    const th = fmVerboseProgressThreshold();
    const t = (data && typeof data.total === 'number')
        ? data.total
        : (typeof fallbackTotal === 'number' ? fallbackTotal : 0);
    return th === 0 || t >= th;
}

function requireAuthFetch(url, opts) {
    return fetch(url, opts).then(async (r) => {
        if (r.status === 401) throw new Error('unauthorized');
        return r;
    });
}

/* ===== Popup layer ===== */
class FmPopup {
    constructor(options = {}) {
        this.options = {
            title: '',
            content: '',
            buttons: [],
            closeOnBackdrop: true,
            closeOnEscape: true,
            submitOnEnter: false,
            showKeyboardHints: true,
            className: '',
            maxWidth: '400px',
            animationDuration: 200,
            parent: document.body,
            parentPopup: null,
            onBeforeShow: null,
            onAfterShow: null,
            onBeforeHide: null,
            onAfterHide: null,
            ...options
        };

        this.el = null;
        this.containerEl = null;
        this.isVisible = false;
        this._backdropPointerDown = false;
        this._boundHandleKeydown = this._handleKeydown.bind(this);
        this._keydownUseCapture = true;

        this._build();
    }

    _isTopVisiblePopup() {
        if (!this.el) {
            return false;
        }
        const shown = typeof document !== 'undefined'
            ? Array.from(document.querySelectorAll('.fm-popup.show'))
            : [];
        return shown.length > 0 && shown[shown.length - 1] === this.el;
    }

    static primaryShortcutLabel() {
        try {
            if (typeof navigator !== 'undefined') {
                const p = navigator.platform || '';
                const ua = navigator.userAgent || '';
                if (/Mac|iPhone|iPad|iPod/i.test(p) || /Mac OS/i.test(ua)) {
                    return '\u2318\u23CE';
                }
            }
        } catch (e) {}
        return 'Ctrl+Enter';
    }

    _shouldShowHeaderClose() {
        if (typeof this.options.showHeaderClose === 'boolean') {
            return this.options.showHeaderClose;
        }
        return !!(this.options.closeOnBackdrop || this.options.closeOnEscape);
    }

    _setHeaderCloseVisible(visible) {
        if (!this.el) {
            return;
        }
        const header = this.el.querySelector('.fm-popup-header');
        const closeEl = this.el.querySelector('.fm-popup-close');
        if (header) {
            header.classList.toggle('fm-popup-header--no-close', !visible);
        }
        if (closeEl) {
            closeEl.style.display = visible ? '' : 'none';
        }
    }

    _build() {
        const content = this.options.content;
        const contentHtml = typeof content === 'string'
            ? content
            : (content instanceof HTMLElement ? content.outerHTML : '');

        const showHeaderClose = this._shouldShowHeaderClose();

        const showHints = this.options.showKeyboardHints !== false;
        const submitLbl = FmPopup.primaryShortcutLabel();
        const buttonsHtml = this.options.buttons.map(btn => {
            const cls = ['fm-popup-action-button'];
            if (btn.primary) {
                cls.push('fm-popup-primary-btn');
            }
            if (btn.close) {
                cls.push('fm-popup-close-btn');
            }
            if (!btn.primary && !btn.close) {
                cls.push('fm-popup-grey-btn');
            }
            let inner = `<span class="fm-popup-btn-label">${FmPopup.escapeHtml(btn.label)}</span>`;
            if (showHints) {
                const onlyDismiss =
                    this.options.buttons.length === 1 && !!(btn.primary && btn.close);
                if (btn.primary) {
                    if (onlyDismiss && this.options.closeOnEscape) {
                        inner += '<span class="fm-popup-btn-kbd" aria-hidden="true">Escape</span>';
                    } else {
                        inner += `<span class="fm-popup-btn-kbd" aria-hidden="true">${FmPopup.escapeHtml(submitLbl)}</span>`;
                    }
                } else if (btn.close && this.options.closeOnEscape) {
                    inner += '<span class="fm-popup-btn-kbd" aria-hidden="true">Escape</span>';
                }
            }
            return `<button type="button" class="${cls.join(' ')}" data-fm-popup-btn="${FmPopup.escapeAttr(btn.label)}">${inner}</button>`;
        }).join('');

        const root = document.createElement('div');
        root.className = `fm-popup fm-js-popup ${this.options.className}`.trim();
        root.setAttribute('role', 'dialog');
        root.setAttribute('aria-modal', 'true');
        root.innerHTML = `
            <div class="fm-popup-container" style="max-width: ${FmPopup.escapeAttr(this.options.maxWidth)}">
                <div class="fm-popup-header${showHeaderClose ? '' : ' fm-popup-header--no-close'}">
                    <span class="fm-popup-header-title">${FmPopup.escapeHtml(this.options.title)}</span>
                    ${showHeaderClose ? '<span class="fm-popup-close" aria-label="Close"></span>' : ''}
                </div>
                <div class="fm-popup-content">${contentHtml}</div>
                ${this.options.buttons.length ? `<div class="fm-buttons">${buttonsHtml}</div>` : ''}
            </div>`;

        this.el = root;
        this.containerEl = root.querySelector('.fm-popup-container');

        root.addEventListener('mousedown', (e) => {
            this._backdropPointerDown = (e.target === root);
        });
        root.addEventListener('mouseup', (e) => {
            if (!this.options.closeOnBackdrop) return;
            const isBackdrop = e.target === root;
            const canClose = this._backdropPointerDown && isBackdrop && !this._hasActiveSelectionFromPopup();
            this._backdropPointerDown = false;
            if (canClose) this.hideAndDestroy();
        });
        root.addEventListener('click', (e) => {
            if (e.target === root) {
                e.preventDefault();
                e.stopPropagation();
            }
        });

        root.querySelectorAll('.fm-popup-close').forEach(closeEl => {
            closeEl.addEventListener('click', () => this.hideAndDestroy());
        });

        root.querySelectorAll('[data-fm-popup-btn]').forEach(btnEl => {
            btnEl.addEventListener('click', (e) => {
                const label = btnEl.getAttribute('data-fm-popup-btn');
                const config = this.options.buttons.find(b => b.label === label);
                if (config) {
                    if (config.onClick) {
                        config.onClick.call(this, e);
                    }
                    if (config.close !== false) {
                        this.hideAndDestroy();
                    }
                }
            });
        });

        const parent = typeof this.options.parent === 'string'
            ? document.querySelector(this.options.parent)
            : this.options.parent;
        if (parent) {
            parent.appendChild(this.el);
        }
    }

    _hasActiveSelectionFromPopup() {
        if (!this.containerEl || typeof window === 'undefined' || typeof window.getSelection !== 'function') return false;
        const sel = window.getSelection();
        if (!sel || sel.rangeCount === 0) return false;
        const txt = String(sel.toString() || '').trim();
        if (!txt) return false;
        const range = sel.getRangeAt(0);
        const startNode = range.startContainer;
        const endNode = range.endContainer;
        return this.containerEl.contains(startNode) || this.containerEl.contains(endNode);
    }

    _handleKeydown(e) {
        if (!this._isTopVisiblePopup()) {
            return;
        }
        if (e.key === 'Escape' && this.options.closeOnEscape) {
            e.preventDefault();
            this.hideAndDestroy();
            return;
        }
        if (e.key === 'Enter' && (e.ctrlKey || e.metaKey) && this.el) {
            const primaryBtn = this.el.querySelector('.fm-popup-primary-btn:not([disabled]):not(.fm-popup-btn-disabled)');
            if (primaryBtn) {
                e.preventDefault();
                primaryBtn.click();
            }
            return;
        }
        if (e.key === 'Enter' && this.options.submitOnEnter && this.el) {
            const active = document.activeElement;
            const isTextInput = active && this.el.contains(active) &&
                active.tagName === 'INPUT' &&
                active.type !== 'radio' && active.type !== 'checkbox' &&
                active.type !== 'button' && active.type !== 'submit';
            if (isTextInput) {
                const primaryBtn = this.el.querySelector('.fm-popup-primary-btn:not([disabled]):not(.fm-popup-btn-disabled)');
                if (primaryBtn) {
                    e.preventDefault();
                    primaryBtn.click();
                }
            }
        }
    }

    show() {
        if (!this.el) {
            return;
        }
        if (this.options.onBeforeShow) {
            this.options.onBeforeShow.call(this);
        }
        this.el.classList.add('show');
        document.addEventListener('keydown', this._boundHandleKeydown, this._keydownUseCapture);
        this.isVisible = true;

        requestAnimationFrame(() => {
            this.el.classList.add('fade');
            const focusEl =
                this.el.querySelector('.fm-input-focus-on-open') ||
                this.el.querySelector('.fm-popup-primary-btn') ||
                this.el.querySelector('input[type="text"], input[type="url"], input[type="search"], textarea, select');
            if (focusEl) {
                focusEl.focus();
            }
            if (this.options.onAfterShow) {
                this.options.onAfterShow.call(this);
            }
        });
    }

    hide(onFinish = null) {
        if (!this.el || !this.isVisible) {
            return;
        }
        if (this.options.onBeforeHide) {
            this.options.onBeforeHide.call(this);
        }
        this.el.classList.remove('fade');
        document.removeEventListener('keydown', this._boundHandleKeydown, this._keydownUseCapture);
        this.isVisible = false;

        setTimeout(() => {
            if (this.el) {
                this.el.classList.remove('show');
                if (this.options.onAfterHide) {
                    this.options.onAfterHide.call(this);
                }
            }
            if (onFinish) {
                onFinish.call(this);
            }
        }, this.options.animationDuration);
    }
    hideAndDestroy(onHideFinish = null, onDestroyFinish = null) {
        this.hide(() => {
            if (onHideFinish) {
                onHideFinish.call(this);
            }
            this.destroy(() => {
                if (onDestroyFinish) {
                    onDestroyFinish.call(this);
                }
            });
        });
    }

    setTitle(title) {
        this.options.title = title;
        const el = this.el && this.el.querySelector('.fm-popup-header-title');
        if (el) {
            el.textContent = title;
        }
    }

    setContent(content) {
        this.options.content = content;
        const wrap = this.el && this.el.querySelector('.fm-popup-content');
        if (!wrap) {
            return;
        }
        if (typeof content === 'string') {
            wrap.innerHTML = content;
        } else if (content instanceof HTMLElement) {
            wrap.innerHTML = '';
            wrap.appendChild(content);
        }
    }

    querySelector(selector) {
        return this.el ? this.el.querySelector(selector) : null;
    }

    querySelectorAll(selector) {
        return this.el ? this.el.querySelectorAll(selector) : null;
    }

    destroy(onFinish = null) {
        document.removeEventListener('keydown', this._boundHandleKeydown, this._keydownUseCapture);

        if (this.el && this.el.parentNode) {
            this.el.parentNode.removeChild(this.el);
        }

        this.el = null;
        this.containerEl = null;
        this.isVisible = false;

        if (onFinish) {
            onFinish.call(this);
        }
    }

    static escapeHtml(str) {
        if (str == null) {
            return '';
        }
        const div = document.createElement('div');
        div.textContent = str;
        return div.innerHTML;
    }

    static escapeAttr(str) {
        if (str == null) {
            return '';
        }
        return FmPopup.escapeHtml(str).replace(/"/g, '&quot;');
    }
}

class FmIndeterminateProgressPopup extends FmPopup {
    constructor(options = {}) {
        const {
            title = 'Please wait',
            message,
            messageHtml,
            maxWidth = '440px',
            className = '',
            ...rest
        } = options;

        let bodyHtml;
        if (messageHtml != null && String(messageHtml).trim() !== '') {
            bodyHtml = String(messageHtml);
        } else if (message != null && String(message).trim() !== '') {
            bodyHtml = '<p>' + FmPopup.escapeHtml(String(message)) + '</p>';
        } else {
            bodyHtml = '<p>Please wait\u2026</p>';
        }

        const content =
            bodyHtml +
            '<div class="fm-progress-wrap fm-download-progress-line"><div class="fm-progress-bar"></div></div>';

        super({
            title,
            content,
            buttons: [],
            closeOnBackdrop: false,
            closeOnEscape: false,
            showHeaderClose: false,
            showKeyboardHints: false,
            className: ('fm-download-progress-popup ' + className).trim(),
            maxWidth,
            ...rest,
        });
    }
}

class FmNoticePopup extends FmPopup {
    constructor(options = {}) {
        const {
            variant = 'error',
            title,
            message,
            messageHtml,
            maxWidth = '440px',
            dontShowAgainStorageKey = '',
            dontShowAgainLabel = "Don't show this message again",
            ...rest
        } = options;

        let innerBody;
        if (messageHtml != null && String(messageHtml).trim() !== '') {
            innerBody = '<div class="fm-notice-popup-msg">' + String(messageHtml) + '</div>';
        } else {
            const t = String(message == null ? '' : message);
            innerBody = '<div class="fm-notice-popup-msg">' +
                FmPopup.escapeHtml(t).replace(/\n/g, '<br>') + '</div>';
        }

        const tone = variant === 'warning' ? 'fm-notice-popup--warning' : 'fm-notice-popup--error';

        let extraFooter = '';
        if (dontShowAgainStorageKey) {
            extraFooter =
                '<label class="fm-notice-dont-show">' +
                '<input type="checkbox" data-fm-notice-dont-show="1">' +
                '<span>' + FmPopup.escapeHtml(dontShowAgainLabel) + '</span>' +
                '</label>';
        }

        const keyForStorage = dontShowAgainStorageKey;

        super({
            title,
            content: '<div class="fm-notice-popup-inner ' + tone + '">' + innerBody + extraFooter + '</div>',
            buttons: [{
                label: 'OK',
                primary: true,
                close: true,
                onClick: function () {
                    if (keyForStorage && this.el) {
                        const cb = this.el.querySelector('input[data-fm-notice-dont-show]');
                        if (cb && cb.checked) {
                            try {
                                localStorage.setItem(keyForStorage, '1');
                            } catch (e2) {}
                        }
                    }
                },
            }],
            closeOnBackdrop: true,
            closeOnEscape: true,
            maxWidth,
            className: 'fm-notice-popup',
            ...rest,
        });
    }

    static isNoticeSuppressed(storageKey) {
        if (!storageKey) return false;
        try {
            return localStorage.getItem(storageKey) === '1';
        } catch (e) {
            return false;
        }
    }
}

function fmUserNotice(opts) {
    if (typeof FmNoticePopup !== 'undefined') {
        new FmNoticePopup(opts).show();
    } else {
        alert((opts && opts.message) || '');
    }
}

function fmShowTerminalSettingsPopup(opts) {
    if (typeof FmPopup === 'undefined') return;
    const ajaxUrl = (opts && opts.ajaxUrl) || (typeof ajax_url !== 'undefined' ? ajax_url : '');
    const authOn = typeof enable_auth === 'undefined' ? true : !!enable_auth;
    const curHere = typeof (opts && opts.here) === 'boolean'
        ? opts.here
        : (typeof window !== 'undefined' && window.fm_terminal_here_enabled === true);
    const curManual = typeof (opts && opts.manual) === 'boolean'
        ? opts.manual
        : (typeof window !== 'undefined' && window.fm_terminal_manual_enabled === true);
    const curAdvanced = typeof (opts && opts.advanced) === 'boolean'
        ? opts.advanced
        : (typeof window !== 'undefined' && window.fm_terminal_advanced_enabled === true);
    const content =
        '<p class="fm-hint">Runs commands on this server — use on trusted hosts. Settings are saved in this PHP file.</p>' +
        '<div class="fm-term-settings-options">' +
        '<label><input type="checkbox" name="term-here"' + (curHere ? ' checked' : '') + '> <span>Standard — allowlisted commands in the current folder</span></label>' +
        '<label><input type="checkbox" name="term-manual"' + (curManual ? ' checked' : '') + '> <span>Manual — typed commands (safe subset)</span></label>' +
        '<label><input type="checkbox" name="term-advanced"' + (curAdvanced ? ' checked' : '') + '> <span>Advanced — raw commands (powerful; trusted hosts)</span></label>' +
        '</div>' +
        '<p class="fm-error" data-term-error style="display:none"></p>' +
        '<div class="fm-pwd-hash-box" data-term-manual style="display:none">' +
        '<p class="fm-hint" data-term-manual-msg></p>' +
        '<textarea data-term-manual-line readonly></textarea>' +
        '<div class="fm-pwd-hash-actions">' +
        '<button type="button" class="fm-btn secondary" data-term-copy>Copy lines</button>' +
        '<button type="button" class="fm-btn" data-term-reload>I updated the file — reload</button>' +
        '</div></div>';
    const p = new FmPopup({
        title: 'Terminal settings',
        maxWidth: '520px',
        submitOnEnter: true,
        content: content,
        buttons: [
            { label: 'Cancel', close: true },
            {
                label: 'Save',
                primary: true,
                close: false,
                onClick: () => {
                    const root = p.el;
                    const err = root.querySelector('[data-term-error]');
                    const fallback = root.querySelector('[data-term-manual]');
                    const fallbackMsg = root.querySelector('[data-term-manual-msg]');
                    const fallbackLine = root.querySelector('[data-term-manual-line]');
                    const hereEl = root.querySelector('[name="term-here"]');
                    const manualEl = root.querySelector('[name="term-manual"]');
                    const advancedEl = root.querySelector('[name="term-advanced"]');
                    const passEl = root.querySelector('[name="term-password"]');
                    if (err) { err.style.display = 'none'; err.textContent = ''; }
                    if (fallback) fallback.style.display = 'none';
                    if (authOn && (!passEl || !(passEl.value || '').length)) {
                        if (err) {
                            err.textContent = 'Password is required.';
                            err.style.display = 'block';
                        }
                        return;
                    }
                    let here = !!(hereEl && hereEl.checked);
                    let manual = !!(manualEl && manualEl.checked);
                    let advanced = !!(advancedEl && advancedEl.checked);
                    if (manual || advanced) here = true;
                    if (!here) { manual = false; advanced = false; }
                    const form = new FormData();
                    form.append('action', 'set-terminal-settings');
                    form.append('here', here ? '1' : '0');
                    form.append('manual', manual ? '1' : '0');
                    form.append('advanced', advanced ? '1' : '0');
                    form.append('password', passEl ? (passEl.value || '') : '');
                    return fetch(ajaxUrl, fmPostInit(form))
                        .then(r => r.json())
                        .then(data => {
                            if (data.status !== 'success') {
                                if (err) {
                                    err.textContent = data.msg || 'Could not update terminal settings';
                                    err.style.display = 'block';
                                }
                                return;
                            }
                            if (data.saved) {
                                p.hide();
                                if (typeof opts.onSaved === 'function') {
                                    opts.onSaved(data);
                                } else {
                                    location.reload();
                                }
                                return;
                            }
                            if (fallback && fallbackMsg && fallbackLine) {
                                fallbackMsg.textContent = data.msg || 'Edit the script manually:';
                                fallbackLine.value = data.lines || '';
                                fallback.style.display = 'block';
                            }
                        })
                        .catch(() => {
                            if (err) {
                                err.textContent = 'Could not update terminal settings';
                                err.style.display = 'block';
                            }
                        });
                }
            }
        ],
        onAfterShow: () => {
            const root = p.el;
            const hereEl = root.querySelector('[name="term-here"]');
            const manualEl = root.querySelector('[name="term-manual"]');
            const advancedEl = root.querySelector('[name="term-advanced"]');
            const syncMaster = () => {
                if (!hereEl) return;
                if (hereEl.checked) return;
                if (manualEl) manualEl.checked = false;
                if (advancedEl) advancedEl.checked = false;
            };
            const ensureHere = () => {
                if ((manualEl && manualEl.checked) || (advancedEl && advancedEl.checked)) {
                    if (hereEl) hereEl.checked = true;
                }
            };
            if (hereEl) hereEl.addEventListener('change', syncMaster);
            if (manualEl) manualEl.addEventListener('change', ensureHere);
            if (advancedEl) advancedEl.addEventListener('change', ensureHere);
            const copyBtn = root.querySelector('[data-term-copy]');
            const reloadBtn = root.querySelector('[data-term-reload]');
            const lineEl = root.querySelector('[data-term-manual-line]');
            if (copyBtn && lineEl) {
                copyBtn.addEventListener('click', () => {
                    lineEl.select();
                    try {
                        if (navigator.clipboard && navigator.clipboard.writeText) {
                            navigator.clipboard.writeText(lineEl.value);
                        } else {
                            document.execCommand('copy');
                        }
                    } catch (e) {}
                });
            }
            if (reloadBtn) {
                reloadBtn.addEventListener('click', () => location.reload());
            }
        }
    });
    p.show();
}

function fmShowTerminalHereTogglePopup(opts) {
    fmShowTerminalSettingsPopup(opts || {});
}

function fmToast(opts) {
    if (!opts || opts.message == null || String(opts.message) === '') return null;

    let stack = document.getElementById('fm-toast-stack');
    if (!stack) {
        stack = document.createElement('div');
        stack.id = 'fm-toast-stack';
        stack.className = 'fm-toast-stack';
        stack.setAttribute('aria-live', 'polite');
        document.body.appendChild(stack);
    }

    const variant = opts.variant === 'warning' || opts.variant === 'info' ? opts.variant : 'success';
    const el = document.createElement('div');
    el.className = 'fm-toast fm-toast--' + variant;
    el.setAttribute('role', 'status');

    const titleHtml = opts.title
        ? '<strong class="fm-toast-title">' + FmPopup.escapeHtml(String(opts.title)) + '</strong>'
        : '';
    const msgHtml = '<span class="fm-toast-msg">' + FmPopup.escapeHtml(String(opts.message)) + '</span>';
    el.innerHTML = titleHtml + msgHtml;
    el.title = 'Click to dismiss';

    stack.appendChild(el);

    let hideTimer = 0;
    const removeEl = () => {
        el.classList.remove('fm-toast--visible');
        window.setTimeout(() => {
            if (el.parentNode) el.parentNode.removeChild(el);
        }, 220);
    };

    requestAnimationFrame(() => {
        el.classList.add('fm-toast--visible');
    });

    const duration = typeof opts.duration === 'number' ? opts.duration : 3800;
    if (duration > 0) {
        hideTimer = window.setTimeout(removeEl, duration);
    }
    el.addEventListener('click', () => {
        window.clearTimeout(hideTimer);
        removeEl();
    });
    return {
        update(message) {
            const msgEl = el.querySelector('.fm-toast-msg');
            if (msgEl) msgEl.textContent = String(message);
        },
        close() {
            window.clearTimeout(hideTimer);
            removeEl();
        },
    };
}

class FmDownloadTracker {
    constructor({ ajaxUrl, token, label, iframe }) {
        this._ajaxUrl = ajaxUrl;
        this._token = token;
        this._label = label || 'download';
        this._iframe = iframe;
        this._startedAt = Date.now();
        this._finished = false;
        this._pollFailures = 0;
        this._emptyErrorPage = false;
        this._timer = 0;
    }

    start() {
        this._iframe.addEventListener('load', () => this._onFrameLoad());
        this._schedule(700);
    }

    _onFrameLoad() {
        if (this._finished) return;
        let text = '';
        try {
            const doc = this._iframe.contentDocument;
            if (!doc || doc.location.href === 'about:blank') return;
            text = ((doc.body && (doc.body.innerText || doc.body.textContent)) || '').trim();
        } catch (e) {
            text = '';
        }
        if (text === '') {
            this._emptyErrorPage = true;
            return;
        }
        let msg = text;
        try {
            const j = JSON.parse(text);
            if (j && typeof j === 'object') msg = String(j.msg || j.message || text);
        } catch (e) {}
        if (/^unauthorized$/i.test(msg)) msg = 'Your session has expired. Reload the page and sign in again.';
        this._fail(msg.slice(0, 1000));
    }

    _schedule(ms) {
        if (!this._finished) this._timer = window.setTimeout(() => this._poll(), ms);
    }

    _poll() {
        if (this._finished) return;
        requireAuthFetch(this._ajaxUrl + '?' + new URLSearchParams({ action: 'download-status', token: this._token }))
            .then((r) => r.json())
            .then((d) => {
                this._pollFailures = 0;
                this._handleStatus(d || {});
            })
            .catch((err) => {
                if (this._finished) return;
                if (err && err.message === 'unauthorized') {
                    this._fail('Your session has expired. Reload the page and sign in again.');
                    return;
                }
                this._pollFailures++;
                if (this._pollFailures >= 5) {
                    this._fail('Lost contact with the server while downloading "' + this._label + '". Check your browser\'s downloads to see whether the file finished.');
                    return;
                }
                this._schedule(2000);
            });
    }

    _handleStatus(d) {
        if (this._finished) return;
        const st = d.state;
        const waited = Date.now() - this._startedAt;
        if (!st || st === 'pending' || st === 'unknown') {
            if (this._emptyErrorPage && waited > 3000) {
                this._fail('The server returned an error without details. Check the PHP / web server error log.');
            } else if (st !== 'unknown' && waited > 15000) {
                this._fail('The server did not start the download of "' + this._label + '". The request may have been blocked or timed out before it reached the file manager.');
            } else {
                this._schedule(1000);
            }
            return;
        }
        if (st === 'preparing' || st === 'streaming') {
            const age = (typeof d.now === 'number' && typeof d.heartbeat === 'number') ? d.now - d.heartbeat : 0;
            if (age > (st === 'preparing' ? 120 : 30)) {
                this._fail(
                    'The download of "' + this._label + '" stopped making progress on the server. ' +
                    'This usually means a hosting timeout or the PHP process was stopped. ' +
                    'Try fewer files, or TAR format. (If you paused the download in the browser, you can ignore this.)'
                );
                return;
            }
            this._schedule(1000);
            return;
        }
        if (st === 'done') {
            this._done(d);
        } else if (st === 'aborted') {
            this._finish();
        } else if (st === 'error') {
            this._fail(d.error || 'The download failed on the server.');
        } else {
            this._schedule(1000);
        }
    }

    _done(d) {
        this._finish();
        const skipped = Array.isArray(d.skipped) ? d.skipped : [];
        const skippedCount = d.skipped_count || skipped.length;
        if (skippedCount === 0 && !d.warning) return;
        const esc = FmPopup.escapeHtml;
        let html = '';
        if (d.warning) html += '<p class="mt-0">' + esc(String(d.warning)) + '</p>';
        if (skippedCount > 0) {
            html += '<p class="mt-0">' + skippedCount + ' item(s) could not be added to the archive:</p>' +
                '<ul class="fm-download-skipped">' + skipped.map((s) => '<li>' + esc(String(s)) + '</li>').join('') + '</ul>';
            if (skippedCount > skipped.length) html += '<p class="mb-0">…and ' + (skippedCount - skipped.length) + ' more.</p>';
        }
        fmUserNotice({ variant: 'warning', title: 'Download finished with problems', messageHtml: html });
    }

    _fail(message) {
        this._finish();
        fmUserNotice({ variant: 'error', title: 'Download failed', message });
    }

    _finish() {
        this._finished = true;
        window.clearTimeout(this._timer);
        const iframe = this._iframe;
        window.setTimeout(() => iframe.remove(), 60000);
    }
}

class FmCreateNewFolderPopup extends FmPopup {
    constructor(options = {}) {
        const { currentPath, rootDir, ajaxUrl, onSuccess, ...rest } = options;

        super({
            title: 'Create new folder',
            content: `<label><span>Folder name</span><input type="text" name="folder-name" class="fm-input-focus-on-open fm-input" autocomplete="off" placeholder="folder-name"></label><p class="fm-error fm-hidden" data-err></p>`,
            buttons: [
                { label: 'Cancel', close: true },
                { label: 'Create', primary: true, close: false, onClick: () => this._handleCreate() }
            ],
            submitOnEnter: true,
            ...rest
        });

        this._currentPath = currentPath;
        this._rootDir     = rootDir;
        this._ajaxUrl     = ajaxUrl;
        this._onSuccess   = onSuccess || null;
    }

    _handleCreate() {
        const input = this.querySelector('input');
        const err   = this.querySelector('[data-err]');
        if (err) {
            err.style.display = 'none';
        }

        const name = (input ? input.value : '').trim();
        if (!name) {
            if (err) {
                err.textContent = 'Enter a folder name';
                err.style.display = 'block';
            }
            return;
        }
        if (/[\\/:*?"<>|]/.test(name)) {
            if (err) {
                err.textContent = 'Name cannot contain \\ / : * ? " < > |';
                err.style.display = 'block';
            }
            return;
        }

        const form = new FormData();
        form.append('action', 'create-new-folder');
        form.append('in', fmPathInParam(this._currentPath, this._rootDir));
        form.append('name', name);

        requireAuthFetch(this._ajaxUrl, fmPostInit(form))
            .then(r => r.json())
            .then(data => {
                if (data.status === 'success') {
                    if (this._onSuccess) {
                        this._onSuccess(name);
                    }
                    this.hideAndDestroy();
                } else {
                    if (err) {
                        err.textContent = data.msg || 'Could not create folder';
                        err.style.display = 'block';
                    }
                }
            })
            .catch(() => {
                if (err) {
                    err.textContent = 'Could not create folder';
                    err.style.display = 'block';
                }
            });
    }
}

class FmNewFolderFromSelectionPopup extends FmPopup {
    constructor(options = {}) {
        const { currentPath, rootDir, ajaxUrl, names, execAvailable, onSuccess, ...rest } = options;

        super({
            title: 'New folder from selection',
            content:
                '<p class="fm-hint mt-0 mb-2">Selected items will be moved into a new subfolder in this directory.</p>' +
                '<label><span>New folder name</span>' +
                '<input type="text" name="folder-name" class="fm-input-focus-on-open fm-input" autocomplete="off" placeholder="folder-name"></label>' +
                '<p class="fm-error fm-hidden" data-err></p>',
            buttons: [
                { label: 'Cancel', close: true },
                { label: 'Create and move', primary: true, close: false, onClick: () => this._handleSubmit() },
            ],
            submitOnEnter: true,
            ...rest,
        });

        this._currentPath   = currentPath;
        this._rootDir       = rootDir;
        this._ajaxUrl       = ajaxUrl;
        this._names         = Array.isArray(names) ? names : [];
        this._execAvailable = execAvailable;
        this._onSuccess     = onSuccess || null;
    }

    _handleSubmit() {
        const input = this.querySelector('input');
        const err   = this.querySelector('[data-err]');
        if (err) {
            err.style.display = 'none';
        }

        const name = (input ? input.value : '').trim();
        if (!name) {
            if (err) {
                err.textContent = 'Enter a folder name';
                err.style.display = 'block';
            }
            return;
        }
        if (/[\\/:*?"<>|]/.test(name)) {
            if (err) {
                err.textContent = 'Name cannot contain \\ / : * ? " < > |';
                err.style.display = 'block';
            }
            return;
        }

        if (this._names.length === 0) {
            if (err) {
                err.textContent = 'Nothing selected';
                err.style.display = 'block';
            }
            return;
        }

        if (typeof FmCopyMovePopup === 'undefined' || !FmCopyMovePopup.runNewFolderFromSelectionDirect) {
            return;
        }

        const userOnSuccess = this._onSuccess;
        const folderName    = name;
        this.hideAndDestroy();

        FmCopyMovePopup.runNewFolderFromSelectionDirect({
            rootDir:       this._rootDir,
            ajaxUrl:       this._ajaxUrl,
            sourcePath:    this._currentPath,
            names:         this._names,
            folderName:    folderName,
            execAvailable: this._execAvailable,
            onSuccess:     () => {
                if (userOnSuccess) userOnSuccess(folderName);
            },
        });
    }
}

class FmCreateFilePopup extends FmPopup {
    constructor(options = {}) {
        const { currentPath, rootDir, ajaxUrl, onSuccess, ...rest } = options;

        super({
            title: 'Create new file',
            content: `<label><span>File name</span><input type="text" name="file-name" class="fm-input-focus-on-open fm-input" autocomplete="off" placeholder="filename.txt"></label><p class="fm-error fm-hidden" data-err></p>`,
            buttons: [
                { label: 'Cancel', close: true },
                { label: 'Create', primary: true, close: false, onClick: () => this._handleCreate() }
            ],
            submitOnEnter: true,
            ...rest
        });

        this._currentPath = currentPath;
        this._rootDir     = rootDir;
        this._ajaxUrl     = ajaxUrl;
        this._onSuccess   = onSuccess || null;
    }

    _handleCreate() {
        const input = this.querySelector('input');
        const err   = this.querySelector('[data-err]');
        if (err) {
            err.style.display = 'none';
        }

        const name = (input ? input.value : '').trim();
        if (!name) {
            if (err) {
                err.textContent = 'Enter a file name';
                err.style.display = 'block';
            }
            return;
        }
        if (/[\\/:*?"<>|]/.test(name)) {
            if (err) {
                err.textContent = 'Name cannot contain \\ / : * ? " < > |';
                err.style.display = 'block';
            }
            return;
        }

        const form = new FormData();
        form.append('action', 'create-new-file');
        form.append('in', fmPathInParam(this._currentPath, this._rootDir));
        form.append('name', name);

        requireAuthFetch(this._ajaxUrl, fmPostInit(form))
            .then(r => r.json())
            .then(data => {
                if (data.status === 'success') {
                    if (this._onSuccess) {
                        this._onSuccess(name);
                    }
                    this.hideAndDestroy();
                } else {
                    if (err) {
                        err.textContent = data.msg || 'Could not create file';
                        err.style.display = 'block';
                    }
                }
            })
            .catch(() => {
                if (err) {
                    err.textContent = 'Could not create file';
                    err.style.display = 'block';
                }
            });
    }
}

class FmUploadUrlPopup extends FmPopup {
    constructor(options = {}) {
        const { currentPath, rootDir, ajaxUrl, onSuccess, ...rest } = options;

        super({
            title: 'Upload from URL',
            maxWidth: '500px',
            submitOnEnter: true,
            content:
                '<p class="fm-upload-into">Into: <strong>' + FmPopup.escapeHtml(currentPath || rootDir) + '</strong></p>' +
                '<p><label><span>File URL (http:// or https://)</span>' +
                '<input type="url" name="upload-url" class="fm-input-focus-on-open fm-input" autocomplete="off" placeholder="https://example.com/archive.zip"></label></p>' +
                '<p><label><span>Save as filename (optional)</span>' +
                '<input type="text" name="upload-filename" class="fm-input" autocomplete="off" placeholder="Leave blank to use filename from URL"></label></p>' +
                '<p class="fm-hint" data-url-status></p>' +
                '<p class="fm-error fm-hidden" data-err></p>',
            buttons: [
                { label: 'Cancel', close: true },
                { label: 'Download to server', primary: true, close: false, onClick: () => this._handleDownload() }
            ],
            ...rest
        });

        this._currentPath = currentPath;
        this._rootDir     = rootDir;
        this._ajaxUrl     = ajaxUrl;
        this._onSuccess   = onSuccess || null;
        this._busy        = false;
    }

    _handleDownload() {
        if (this._busy) return;
        const urlIn    = this.querySelector('[name="upload-url"]');
        const nameIn   = this.querySelector('[name="upload-filename"]');
        const err      = this.querySelector('[data-err]');
        const statusEl = this.querySelector('[data-url-status]');
        const primary  = this.querySelector('.fm-popup-primary-btn');

        if (err) { err.style.display = 'none'; err.textContent = ''; }
        const url = (urlIn ? urlIn.value : '').trim();
        const filename = (nameIn ? nameIn.value : '').trim();

        if (!url || !/^https?:\/\//i.test(url)) {
            if (err) {
                err.textContent = 'Enter a valid http:// or https:// URL';
                err.style.display = 'block';
            }
            return;
        }

        this._busy = true;
        if (primary) primary.disabled = true;
        if (statusEl) statusEl.textContent = 'Downloading file to server…';

        const form = new FormData();
        form.append('action', 'upload-url');
        form.append('in', fmPathInParam(this._currentPath, this._rootDir));
        form.append('url', url);
        form.append('filename', filename);

        requireAuthFetch(this._ajaxUrl, fmPostInit(form))
            .then(r => r.json())
            .then(data => {
                this._busy = false;
                if (primary) primary.disabled = false;
                if (statusEl) statusEl.textContent = '';
                if (data && data.status === 'success') {
                    if (typeof fmToast === 'function') {
                        fmToast({ message: 'Downloaded ' + (data.name || ''), variant: 'success' });
                    }
                    if (this._onSuccess) this._onSuccess(data.name || '');
                    this.hideAndDestroy();
                } else {
                    if (err) {
                        err.textContent = (data && data.msg) || 'Download from URL failed';
                        err.style.display = 'block';
                    }
                }
            })
            .catch(() => {
                this._busy = false;
                if (primary) primary.disabled = false;
                if (statusEl) statusEl.textContent = '';
                if (err) {
                    err.textContent = 'Download from URL failed (network or server error)';
                    err.style.display = 'block';
                }
            });
    }
}

class FmEditFilePopup extends FmPopup {
    constructor(options = {}) {
        const { currentPath, rootDir, ajaxUrl, name, onSaved, ...rest } = options;
        const displayPath = fmJoinPath(currentPath, name || '');

        super({
            title: 'Edit file — ' + (name || ''),
            className: 'fm-edit-file-popup',
            maxWidth: '920px',
            submitOnEnter: false,
            closeOnBackdrop: false,
            content:
                '<div class="fm-edit-meta">' +
                    '<span class="fm-edit-path">' + FmPopup.escapeHtml(displayPath) + '</span>' +
                    '<span class="fm-hint">Ctrl+S / Cmd+S to save • Tab inserts indentation</span>' +
                '</div>' +
                '<textarea class="fm-edit-textarea" data-edit-textarea spellcheck="false" placeholder="Loading file contents…" disabled></textarea>' +
                '<div class="fm-edit-status" data-edit-status></div>' +
                '<p class="fm-error fm-hidden" data-err></p>',
            buttons: [
                { label: 'Cancel', close: true },
                { label: 'Save (Ctrl+S)', close: false, onClick: () => this._handleSave(false) },
                { label: 'Save & Close', primary: true, close: false, onClick: () => this._handleSave(true) },
            ],
            onAfterShow: () => this._loadFileContent(),
            ...rest,
        });

        this._currentPath = currentPath;
        this._rootDir     = rootDir;
        this._ajaxUrl     = ajaxUrl;
        this._name        = name || '';
        this._onSaved     = onSaved || null;
        this._saving      = false;
    }

    _inParam() {
        return fmPathInParam(this._currentPath, this._rootDir);
    }

    _loadFileContent() {
        const textarea = this.querySelector('[data-edit-textarea]');
        const err      = this.querySelector('[data-err]');
        const status   = this.querySelector('[data-edit-status]');
        if (!textarea) return;

        textarea.addEventListener('keydown', (e) => {
            if ((e.ctrlKey || e.metaKey) && !e.altKey && !e.shiftKey && e.key.toLowerCase() === 's') {
                e.preventDefault();
                e.stopPropagation();
                this._handleSave(false);
                return;
            }
            if (e.key === 'Tab' && !e.ctrlKey && !e.altKey && !e.metaKey) {
                e.preventDefault();
                const start = textarea.selectionStart;
                const end   = textarea.selectionEnd;
                const val   = textarea.value;
                textarea.value = val.substring(0, start) + '    ' + val.substring(end);
                textarea.selectionStart = textarea.selectionEnd = start + 4;
            }
        });

        const params = new URLSearchParams({
            action: 'get-file-content',
            in:     this._inParam(),
            name:   this._name,
        });

        requireAuthFetch(this._ajaxUrl + '?' + params.toString())
            .then(r => r.json())
            .then(data => {
                if (data.status !== 'success') {
                    textarea.placeholder = '';
                    if (err) {
                        err.textContent = data.msg || 'Could not load file';
                        err.style.display = 'block';
                    }
                    return;
                }
                textarea.value = data.content != null ? String(data.content) : '';
                textarea.disabled = false;
                if (data.writable === false) {
                    textarea.readOnly = true;
                    if (err) {
                        err.textContent = 'This file is read-only on the server (check file permissions).';
                        err.style.display = 'block';
                    }
                } else {
                    textarea.focus();
                }
                if (status) {
                    status.textContent = 'Loaded (' + FmFileManagerTable.bytesToSize(Number(data.size) || 0) + ')';
                }
            })
            .catch(() => {
                if (err) {
                    err.textContent = 'Could not load file';
                    err.style.display = 'block';
                }
            });
    }

    _handleSave(closeAfter) {
        if (this._saving) return;
        const textarea = this.querySelector('[data-edit-textarea]');
        const err      = this.querySelector('[data-err]');
        const status   = this.querySelector('[data-edit-status]');
        if (!textarea || textarea.disabled || textarea.readOnly) return;

        if (err) {
            err.style.display = 'none';
            err.textContent = '';
        }
        if (status) {
            status.textContent = 'Saving…';
        }
        this._saving = true;

        const form = new FormData();
        form.append('action', 'save-file');
        form.append('in', this._inParam());
        form.append('name', this._name);
        form.append('content', textarea.value);

        requireAuthFetch(this._ajaxUrl, fmPostInit(form))
            .then(r => r.json())
            .then(data => {
                this._saving = false;
                if (data.status === 'success') {
                    if (status) {
                        const now = new Date().toLocaleTimeString();
                        status.textContent = 'Saved at ' + now + ' (' + FmFileManagerTable.bytesToSize(Number(data.size) || 0) + ')';
                    }
                    if (typeof fmToast === 'function') {
                        fmToast({ message: 'Saved ' + this._name, variant: 'success', duration: 2200 });
                    }
                    if (this._onSaved) {
                        this._onSaved({ name: this._name, size: data.size, mtime: data.mtime, closed: !!closeAfter });
                    }
                    if (closeAfter) {
                        this.hideAndDestroy();
                    }
                } else {
                    if (status) status.textContent = '';
                    if (err) {
                        err.textContent = data.msg || 'Could not save file';
                        err.style.display = 'block';
                    }
                }
            })
            .catch(() => {
                this._saving = false;
                if (status) status.textContent = '';
                if (err) {
                    err.textContent = 'Could not save file';
                    err.style.display = 'block';
                }
            });
    }
}
class FmDeletePopup extends FmPopup {
    constructor(options = {}) {
        const {
            currentPath,
            rootDir,
            ajaxUrl,
            names,
            inTrashTree,
            defaultDeleteForever,
            onSuccess,
            execAvailable,
            ...rest
        } = options;
        let execOk = true;
        if (typeof execAvailable === 'boolean') {
            execOk = execAvailable;
        } else if (typeof window !== 'undefined' && typeof window.fm_exec_available === 'boolean') {
            execOk = window.fm_exec_available;
        }
        const list = names || [];
        const count = list.length;
        const label = count === 1 ? '1 item' : (count + ' items');
        const inT = !!inTrashTree;
        const foreverUi = inT;
        const foreverDefault = !!defaultDeleteForever;

        const hintTrash =
            '<p class="fm-hint fm-delete-hint">Unchecked: items go to Trash and can be restored to where they were. ' +
            'Checked: deleted permanently. <strong>Shift+Delete</strong> opens this with Delete forever on.</p>';
        const hintForever =
            '<p class="fm-hint fm-delete-hint">' +
            (execOk
                ? 'The server tries OS delete first when possible, then PHP with progress if needed.'
                : 'PHP <code>exec()</code> is disabled; deletion runs in PHP with per-item progress.') +
            '</p>';

        const foreverRow = foreverUi
            ? ''
            : '<p class="fm-delete-forever-row"><label><input type="checkbox" id="fm-delete-forever-cb"> Delete forever (skip Trash)</label></p>';

        let body;
        if (inT) {
            body = `<p>Permanently delete ${label} from Trash?</p><p>This cannot be undone.</p>` + hintForever;
        } else {
            body = `<p>Remove selected ${label}?</p>` + foreverRow + hintTrash + hintForever;
        }

        super({
            title:   foreverUi ? 'Delete permanently' : 'Delete',
            content: body,
            buttons: [
                { label: 'Cancel', close: true },
                { label: foreverUi ? 'Delete forever' : 'OK', primary: true, close: false, onClick: () => this._handleDelete() }
            ],
            ...rest
        });

        this._currentPath = currentPath;
        this._rootDir = rootDir;
        this._ajaxUrl = ajaxUrl;
        this._names = list;
        this._inTrashTree = inT;
        this._onSuccess = onSuccess || null;
        this._execAvailable = execOk;

        if (!foreverUi && this.el) {
            const cb = this.el.querySelector('#fm-delete-forever-cb');
            if (cb && foreverDefault) {
                cb.checked = true;
            }
        }
    }

    _handleDelete() {
        const cb = this.el ? this.el.querySelector('#fm-delete-forever-cb') : null;
        const deleteForever = this._inTrashTree || !!(cb && cb.checked);
        this.hideAndDestroy();

        const pathIn = fmPathInParam(this._currentPath, this._rootDir);
        const names = this._names.filter((n) => n);
        if (!deleteForever) {
            this._runTrashMove(pathIn, names, (ok) => {
                if (this._onSuccess) this._onSuccess(ok);
            });
            return;
        }
        this._runPermanentDeleteChain(names, () => {
            if (this._onSuccess) this._onSuccess(names);
        });
    }

    _runTrashMove(pathIn, names, onDone) {
        const form = new FormData();
        form.append('action', 'trash-move');
        form.append('in', pathIn);
        names.forEach((n) => form.append('names[]', n));
        fetch(this._ajaxUrl, fmPostInit(form))
            .then((r) => r.json())
            .then((data) => {
                const errs = data.errors || [];
                if (errs.length) {
                    fmUserNotice({ title: 'Trash', message: errs.join('; ') });
                }
                if (data.status === 'error' && !(data.ok && data.ok.length)) {
                    return;
                }
                if (typeof onDone === 'function') onDone(data.ok || names);
            })
            .catch(() => {
                fmUserNotice({ title: 'Trash', message: 'Move to Trash failed.' });
            });
    }

    _runPermanentDeleteChain(liveNames, onAllDone) {
        const pathIn = fmPathInParam(this._currentPath, this._rootDir);
        const finishTrashed = () => {
            if (typeof onAllDone === 'function') onAllDone();
        };

        if (!liveNames.length) {
            finishTrashed();
            return;
        }

        const opsMode = typeof fm_file_ops_mode === 'string' ? String(fm_file_ops_mode).toLowerCase() : 'auto';
        const usePhpDeleteUiFirst = opsMode === 'php' || !this._execAvailable;

        if (usePhpDeleteUiFirst) {
            const loadPopup = new FmPopup({
                title: 'Delete',
                content:
                    '<div>' +
                    '<p class="fm-progress-label">Preparing delete forever\u2026</p>' +
                    '<p class="fm-delete-phase fm-hint">Counting items to delete.</p>' +
                    '<div class="fm-progress-wrap fm-delete-progress-wrap"><div class="fm-progress-bar"></div></div>' +
                    '</div>',
                buttons: [],
                closeOnBackdrop: false,
                closeOnEscape: false,
                maxWidth: '460px'
            });
            loadPopup.show();

            const countForm = new FormData();
            countForm.append('action', 'get-files-and-folders-count');
            countForm.append('in', pathIn);
            liveNames.forEach((n) => countForm.append('names[]', n));

            fetch(this._ajaxUrl, fmPostInit(countForm))
                .then((r) => r.json())
                .then((countData) => {
                    loadPopup.hideAndDestroy();
                    const total = (countData.status === 'success' && typeof countData.total === 'number')
                        ? countData.total
                        : liveNames.length;
                    this._runStream(pathIn, total, 'php', liveNames, finishTrashed);
                })
                .catch(() => {
                    loadPopup.hideAndDestroy();
                    fmUserNotice({ title: 'Delete', message: 'Could not count items to delete.' });
                });
        } else {
            const streamMethod = opsMode === 'os' ? 'os' : 'auto';
            this._runStream(pathIn, 0, streamMethod, liveNames, finishTrashed);
        }
    }

    _runStream(pathIn, total, method, namesList, thenFn) {
        const names = (namesList && namesList.length) ? namesList : this._names;
        const isOsMode = (method === 'os');
        const progressPopup = new FmPopup({
            title: 'Delete',
            content: isOsMode
                ? '<div>' +
                  '<p class="fm-progress-label">Deleting (OS commands)\u2026</p>' +
                  '<p class="fm-delete-phase fm-hint">Please wait (this can take a while).</p>' +
                  '<div class="fm-progress-wrap fm-delete-progress-wrap"><div class="fm-progress-bar"></div></div>' +
                  '</div>'
                : `<div><p class="fm-progress-label">Deleting\u2026 <span class="fm-delete-count">0 / ${total}</span></p>` +
                  `<p class="fm-delete-name" title=""></p>` +
                  `<div class="fm-progress-bar-bg">` +
                  `<div class="fm-delete-bar"></div></div></div>`,
            buttons: [],
            closeOnBackdrop: false,
            closeOnEscape: false,
            maxWidth: '460px'
        });
        progressPopup.show();

        const countEl = progressPopup.querySelector('.fm-delete-count');
        const nameEl = progressPopup.querySelector('.fm-delete-name');
        const barEl = progressPopup.querySelector('.fm-delete-bar');
        const barWrap = barEl ? barEl.parentElement : null;

        let runningTotal = total;
        let verboseItems = fmShouldShowVerboseItemProgress(null, total);

        const applyDeleteProgressMode = () => {
            if (!barWrap) return;
            if (verboseItems) {
                barWrap.classList.remove('fm-progress-indeterminate');
                if (countEl) countEl.style.display = '';
                if (nameEl) nameEl.style.display = '';
            } else {
                barWrap.classList.add('fm-progress-indeterminate');
                if (countEl) countEl.style.display = 'none';
                if (nameEl) nameEl.style.display = 'none';
                if (barEl) barEl.style.width = '';
            }
        };
        applyDeleteProgressMode();

        const streamForm = new FormData();
        streamForm.append('action', 'delete-stream');
        streamForm.append('in', pathIn);
        names.forEach((n) => streamForm.append('names[]', n));

        fetch(this._ajaxUrl, fmPostInit(streamForm))
            .then((response) => {
                if (!response.ok || !response.body) {
                    progressPopup.hideAndDestroy();
                    fmUserNotice({ title: 'Delete', message: 'Delete failed.' });
                    return;
                }
                const reader = response.body.getReader();
                const decoder = new TextDecoder();
                let buffer = '';
                let failed = false;

                const processLine = (line) => {
                    line = String(line || '').trim();
                    if (!line) return;
                    try {
                        const data = JSON.parse(line);
                        if (typeof data.total === 'number' && data.total > 0) {
                            runningTotal = data.total;
                        }
                        if (data && Object.prototype.hasOwnProperty.call(data, 'verbose_progress')) {
                            verboseItems = !!data.verbose_progress;
                            applyDeleteProgressMode();
                        }
                        if (typeof data.n === 'number' && countEl && barEl) {
                            const t = runningTotal > 0 ? runningTotal : total;
                            if (verboseItems) {
                                countEl.textContent = data.n + ' / ' + (t > 0 ? t : '\u2026');
                                if (nameEl) {
                                    nameEl.textContent = shortenMiddle(data.name || '', 50);
                                    nameEl.title = data.name || '';
                                }
                                barEl.style.width = (t > 0 ? (data.n / t * 100) : 100) + '%';
                            }
                        }
                        if (data.error) {
                            failed = true;
                            progressPopup.hideAndDestroy();
                            fmUserNotice({ title: 'Delete', message: data.error });
                        }
                    } catch (e) {}
                };

                const readChunk = () => {
                    reader.read().then((result) => {
                        if (result.value) buffer += decoder.decode(result.value, { stream: !result.done });
                        const lines = buffer.split('\n');
                        buffer = lines.pop() || '';
                        lines.forEach(processLine);
                        if (result.done) {
                            if (buffer) processLine(buffer);
                            if (!failed) {
                                progressPopup.hideAndDestroy();
                                if (typeof thenFn === 'function') {
                                    thenFn();
                                } else if (this._onSuccess) {
                                    this._onSuccess(names);
                                }
                            }
                            return;
                        }
                        if (!failed) readChunk();
                    }).catch(() => {
                        progressPopup.hideAndDestroy();
                        fmUserNotice({ title: 'Delete', message: 'Delete failed.' });
                    });
                };
                readChunk();
            })
            .catch(() => {
                progressPopup.hideAndDestroy();
                fmUserNotice({ title: 'Delete', message: 'Delete failed.' });
            });
    }
}

class FmCompressPopup extends FmPopup {
    constructor(options = {}) {
        const {
            currentPath,
            rootDir,
            ajaxUrl,
            names,
            defaultArchiveName,
            execAvailable,
            onSuccess,
            ...rest
        } = options;

        const namesArr = names || [];
        let execOk = true;
        if (typeof execAvailable === 'boolean') {
            execOk = execAvailable;
        } else if (typeof window !== 'undefined' && typeof window.fm_exec_available === 'boolean') {
            execOk = window.fm_exec_available;
        }

        const defaultNameWithExt = defaultArchiveName || 'archive.zip';
        const compressHintIntro =
            '<p class="fm-hint fm-compress-hint-intro">' +
            (execOk
                ? 'The server tries OS archive tools first when possible, then PHP if needed.'
                : 'PHP <code>exec()</code> is disabled; archives are built with PHP only.') +
            '</p>';

        const separateRowHtml = namesArr.length > 1
            ? '<p class="fm-compress-separate-row"><label class="fm-option-label"><input type="checkbox" name="compress-separate" class="fm-compress-separate"> Create one archive per selected item</label></p>'
            : '';

        super({
            title: 'Archive',
            submitOnEnter: true,
            content:
                '<p><label><span class="fm-compress-name-label">Archive name</span><input type="text" name="archive-name" class="fm-compress-name fm-input-focus-on-open" autocomplete="off" value="' +
                FmPopup.escapeAttr(defaultNameWithExt) + '" placeholder="archive.zip"></label></p>' +
                '<p class="fm-compress-exists-notice fm-hidden" role="status"></p>' +
                '<p class="fm-compress-override-row fm-hidden"><label class="fm-option-label"><input type="checkbox" name="compress-override" class="fm-compress-override"> Overwrite existing archive</label></p>' +
                separateRowHtml +
                '<p class="fm-compress-type-row"><span class="fm-compress-type-label">Archive type:</span> ' +
                '<label class="fm-option-label"><input type="radio" name="fm-compress-type" class="fm-compress-type" value="zip" checked> ZIP</label> ' +
                '<label class="fm-option-label"><input type="radio" name="fm-compress-type" class="fm-compress-type" value="tar"> TAR</label> ' +
                '<label class="fm-option-label"><input type="radio" name="fm-compress-type" class="fm-compress-type" value="gzip"> GZIP</label></p>' +
                compressHintIntro +
                '<div class="fm-option-hint" role="status"><span class="fm-option-hint-text"></span></div>',
            buttons: [
                { label: 'Cancel', close: true },
                { label: 'Archive', primary: true, close: false, onClick: () => this._handleCreate() }
            ],
            onAfterShow() {
                this._bindCompressForm();
            },
            ...rest
        });

        this._currentPath   = currentPath;
        this._rootDir       = rootDir;
        this._ajaxUrl       = ajaxUrl;
        this._names         = namesArr;
        this._execAvailable = execOk;
        this._onSuccess     = onSuccess || null;
        this._extByType     = { zip: '.zip', tar: '.tar', gzip: '.tar.gz' };
        this._archiveExists = false;
        this._existingArchiveNames = [];
        this._archiveNameCheckReqId = 0;
        this._pendingOverwriteOk = false;
    }

    _pathIn() {
        return fmPathInParam(this._currentPath, this._rootDir);
    }

    _bindCompressForm() {
        const input       = this.querySelector('.fm-compress-name');
        const radios      = this.querySelectorAll('.fm-compress-type');
        const hintEl      = this.querySelector('.fm-option-hint-text');
        const hintWrap    = this.querySelector('.fm-option-hint');
        const noticeEl    = this.querySelector('.fm-compress-exists-notice');
        const overrideRow = this.querySelector('.fm-compress-override-row');
        const overrideEl  = this.querySelector('.fm-compress-override');
        const separateEl  = this.querySelector('.fm-compress-separate');
        const nameLabelEl = this.querySelector('.fm-compress-name-label');
        let archiveCheckTimer = null;

        this._archiveExists = false;

        const getCreateBtn = () => this.el && this.el.querySelector('[data-fm-popup-btn="Archive"]');

        const updateCreateButtonState = () => {
            const btn = getCreateBtn();
            if (!btn || !input) return;
            const n = (input.value || '').trim();
            const sep = !!(separateEl && separateEl.checked);
            const disabled =
                (!n && !sep) ||
                /[\\/:*?"<>|]/.test(n) ||
                (this._archiveExists && overrideEl && !overrideEl.checked);
            btn.disabled = disabled;
            btn.classList.toggle('fm-popup-btn-disabled', disabled);
        };

        const hideExistsUi = () => {
            this._archiveExists = false;
            this._existingArchiveNames = [];
            if (noticeEl) {
                noticeEl.classList.add('fm-hidden');
                noticeEl.textContent = '';
            }
            if (overrideRow) overrideRow.classList.add('fm-hidden');
            if (overrideEl) overrideEl.checked = false;
            updateCreateButtonState();
        };

        const checkArchiveNameExists = () => {
            if (!input) return;
            const n = (input.value || '').trim();
            const sep = !!(separateEl && separateEl.checked);
            if ((!n && !sep) || /[\\/:*?"<>|]/.test(n)) {
                hideExistsUi();
                return;
            }
            const typeRadio = this.querySelector('.fm-compress-type:checked');
            const type      = (typeRadio && typeRadio.value) || 'zip';
            const ext       = this._extByType[type] || '.zip';
            const prefix    = n.replace(/\.(zip|tar|tar\.gz)$/i, '');
            const archiveNames = sep
                ? (this._names.length > 1
                    ? this._names.map(an => (prefix ? prefix + '-' + an : an) + ext)
                    : [(prefix || this._names[0] || '') + ext])
                : [n.endsWith(ext) ? n : (n.replace(/\.(zip|tar|tar\.gz)$/i, '') + ext)];

            this._archiveNameCheckReqId += 1;
            const reqId = this._archiveNameCheckReqId;
            const existing = [];

            const setState = (existsList) => {
                const exists = existsList.length > 0;
                this._archiveExists = exists;
                this._existingArchiveNames = existsList.slice();
                if (noticeEl) {
                    noticeEl.classList.toggle('fm-hidden', !exists);
                    if (!exists) {
                        noticeEl.textContent = '';
                    } else if (existsList.length === 1) {
                        noticeEl.textContent = 'An archive named "' + existsList[0] + '" already exists in this folder.';
                    } else {
                        noticeEl.textContent = 'These archive names already exist in this folder: ' + existsList.join(', ') + '.';
                    }
                }
                if (overrideRow) overrideRow.classList.toggle('fm-hidden', !exists);
                if (!exists && overrideEl) overrideEl.checked = false;
                updateCreateButtonState();
            };

            let chain = Promise.resolve();
            archiveNames.forEach(an => {
                chain = chain.then(() => {
                    if (reqId !== this._archiveNameCheckReqId) return;
                    const fullPath = fmJoinPath(this._currentPath, an);
                    return requireAuthFetch(this._ajaxUrl + '?' + new URLSearchParams({ action: 'get-info', path: fullPath }))
                        .then(r => r.json())
                        .then(data => { if (data.status === 'success') existing.push(an); });
                });
            });
            chain
                .then(() => { if (reqId === this._archiveNameCheckReqId) setState(existing); })
                .catch(() => { if (reqId === this._archiveNameCheckReqId) setState([]); });
        };

        const scheduleArchiveCheck = () => {
            clearTimeout(archiveCheckTimer);
            archiveCheckTimer = setTimeout(checkArchiveNameExists, 380);
        };

        const updateNameLabel = () => {
            if (!nameLabelEl) return;
            nameLabelEl.textContent = (separateEl && separateEl.checked) ? 'Prefix' : 'Archive name';
        };

        const syncInputForSeparate = () => {
            if (!input) return;
            const typeRadio = this.querySelector('.fm-compress-type:checked');
            const type      = (typeRadio && typeRadio.value) || 'zip';
            const ext       = this._extByType[type] || '.zip';
            let v = (input.value || '').trim().replace(/\.(zip|tar|tar\.gz)$/i, '') || (input.value || '').trim();
            if (separateEl && separateEl.checked) {
                input.value = v;
                input.setSelectionRange(0, v.length);
            } else {
                input.value = v + ext;
                input.setSelectionRange(0, v.length);
            }
        };

        if (input) {
            input.addEventListener('input',  scheduleArchiveCheck);
            input.addEventListener('change', scheduleArchiveCheck);
            const val      = input.value || '';
            const nameOnly = val.replace(/\.(zip|tar|tar\.gz)$/i, '') || val;
            const setNameSelection = () => input.setSelectionRange(0, nameOnly.length);
            setNameSelection();
            setTimeout(setNameSelection, 0);
        }
        if (separateEl) separateEl.addEventListener('change', () => { syncInputForSeparate(); updateNameLabel(); scheduleArchiveCheck(); updateCreateButtonState(); });
        if (overrideEl) overrideEl.addEventListener('change', updateCreateButtonState);

        const getCompressHint = (type) => {
            const map = {
                zip: {
                    text: 'ZIP: fast with OS tools when available; PHP ZipArchive otherwise. Large folders may take a while.',
                    tone: 'good'
                },
                tar: {
                    text: 'TAR: OS tar when available; otherwise PHP (Phar). Big trees can take time.',
                    tone: 'medium'
                },
                gzip: {
                    text: 'GZIP (.tar.gz): OS tar/gzip when available; otherwise PHP. Can be slow for huge selections.',
                    tone: 'medium'
                }
            };
            return map[type] || map.zip;
        };

        const updateCompressHint = () => {
            if (!hintEl) return;
            const typeRadio = this.querySelector('.fm-compress-type:checked');
            const type      = (typeRadio && typeRadio.value) || 'zip';
            const h         = getCompressHint(type);
            hintEl.textContent = h.text;
            if (hintWrap) {
                hintWrap.classList.remove('fm-option-hint--excellent', 'fm-option-hint--good', 'fm-option-hint--medium', 'fm-option-hint--slow', 'fm-option-hint--veryslow');
                hintWrap.classList.add('fm-option-hint--' + (h.tone || 'medium'));
            }
        };

        const updateExt = () => {
            if (!input) return;
            const checked = this.querySelector('.fm-compress-type:checked');
            const type    = (checked && checked.value) || 'zip';
            const ext     = this._extByType[type] || '.zip';
            let val = input.value.replace(/\.(zip|tar|tar\.gz)$/i, '') || input.value;
            if (separateEl && separateEl.checked) {
                input.value = val;
                input.setSelectionRange(0, val.length);
            } else {
                input.value = val + ext;
                input.setSelectionRange(0, val.length);
            }
            updateCompressHint();
            scheduleArchiveCheck();
        };

        radios.forEach(radio => radio.addEventListener('change', updateExt));
        updateCompressHint();
        syncInputForSeparate();
        updateNameLabel();
        scheduleArchiveCheck();
        updateCreateButtonState();
    }

    _handleCreate() {
        const input      = this.querySelector('.fm-compress-name');
        const typeRadio  = this.querySelector('.fm-compress-type:checked');
        const name       = (input && input.value || '').trim();
        const type       = (typeRadio && typeRadio.value) || 'zip';
        const separateEl = this.querySelector('.fm-compress-separate');
        const separate   = !!(separateEl && separateEl.checked);
        const names      = this._names;
        const extByType  = this._extByType;

        if (!separate) {
            if (!name) {
                input && input.setCustomValidity('Enter an archive name');
                input && input.reportValidity();
                return;
            }
        }
        if (name && /[\\/:*?"<>|]/.test(name)) {
            input && input.setCustomValidity('Name cannot contain \\ / : * ? " < > |');
            input && input.reportValidity();
            return;
        }

        const allowOverwrite = !!(this._archiveExists && this.querySelector('.fm-compress-override') && this.querySelector('.fm-compress-override').checked);
        if (this._archiveExists && !allowOverwrite) return;

        if (allowOverwrite && !this._pendingOverwriteOk) {
            const existing = (this._existingArchiveNames && this._existingArchiveNames.length)
                ? this._existingArchiveNames
                : [name];
            const labelHtml = existing.length === 1
                ? '<strong>' + FmPopup.escapeHtml(existing[0]) + '</strong>'
                : 'these archives (<strong>' + FmPopup.escapeHtml(existing.join(', ')) + '</strong>)';
            const confirm = new FmPopup({
                title: 'Overwrite archive?',
                maxWidth: '460px',
                content: '<p class="mt-0 mb-0">Are you sure you want to replace ' + labelHtml + '? This cannot be undone.</p>',
                buttons: [
                    { label: 'Cancel', close: true },
                    {
                        label: 'Overwrite',
                        primary: true,
                        close: false,
                        onClick: () => {
                            confirm.hideAndDestroy();
                            this._pendingOverwriteOk = true;
                            this._handleCreate();
                        },
                    },
                ],
            });
            confirm.show();
            return;
        }
        this._pendingOverwriteOk = false;

        if (separate && names.length > 1) {
            const ext    = extByType[type] || '.zip';
            const prefix = name.replace(/\.(zip|tar|tar\.gz)$/i, '');

            const jobs = names.map(itemName => ({
                itemName,
                archiveName: (prefix ? prefix + '-' + itemName : itemName) + ext
            }));

            this.hideAndDestroy();
            const archiveBusyMsg = '<p class="mt-0">The server is building your archive. Large folders may take a while.</p>';
            const busyPopup = new FmIndeterminateProgressPopup({ title: 'Archive', messageHtml: archiveBusyMsg });
            busyPopup.show();

            const runJob = (jobArchiveName, jobNames) => new Promise((resolve) => {
                let finished = false;

                const streamCompress = (totalForUi) => {
                    const form = new FormData();
                    form.append('action',       'compress');
                    form.append('in',           this._pathIn());
                    form.append('archive_name', jobArchiveName);
                    form.append('archive_type', type);
                    form.append('total',        String(totalForUi || 0));
                    form.append('overwrite',    allowOverwrite ? '1' : '0');
                    jobNames.forEach(n => form.append('names[]', n));

                    fetch(this._ajaxUrl, fmPostInit(form))
                        .then(response => {
                            if (!response.ok)   throw new Error('HTTP ' + response.status);
                            if (!response.body) throw new Error('No response body');
                            const reader  = response.body.getReader();
                            const decoder = new TextDecoder();
                            let buffer = '';

                            const handleLine = (line) => {
                                line = (line || '').trim();
                                if (!line) return;
                                let data;
                                try { data = JSON.parse(line); } catch (e) { return; }
                                if (data.error) {
                                    if (!finished) {
                                        finished = true;
                                        busyPopup.hideAndDestroy();
                                        fmUserNotice({ title: 'Archive', message: data.error });
                                        resolve(false);
                                    }
                                    return;
                                }
                                if (data.done && !finished) { finished = true; resolve(true); }
                            };

                            const pump = () => reader.read().then(res => {
                                if (res.done) { if (buffer) handleLine(buffer); return; }
                                buffer += decoder.decode(res.value, { stream: true });
                                const lines = buffer.split('\n');
                                buffer = lines.pop() || '';
                                lines.forEach(handleLine);
                                return pump();
                            });
                            return pump();
                        })
                        .catch(() => {
                            if (!finished) {
                                finished = true;
                                busyPopup.hideAndDestroy();
                                fmUserNotice({ title: 'Archive', message: 'Could not create archive.' });
                                resolve(false);
                            }
                        });
                };

                const pathInJob    = this._pathIn();
                const countFormJob = new FormData();
                countFormJob.append('action', 'get-files-and-folders-count');
                countFormJob.append('mode',   'files');
                countFormJob.append('in',     pathInJob);
                jobNames.forEach(n => countFormJob.append('names[]', n));
                fetch(this._ajaxUrl, fmPostInit(countFormJob))
                    .then(r => r.json())
                    .then(countData => {
                        const total = (countData.status === 'success' && typeof countData.total === 'number') ? countData.total : jobNames.length;
                        streamCompress(total);
                    })
                    .catch(() => streamCompress(jobNames.length));
            });

            let idx = 0;
            const onDone = this._onSuccess;
            const archiveNamesForHook = jobs.map(j => j.archiveName);
            const nextJob = () => {
                if (idx >= jobs.length) {
                    busyPopup.hideAndDestroy();
                    if (onDone) onDone(archiveNamesForHook);
                    return;
                }
                const job = jobs[idx++];
                runJob(job.archiveName, [job.itemName]).then(ok => { if (ok) nextJob(); });
            };
            nextJob();
            return;
        }

        let archiveNameForRequest = name;
        if (separate && names.length === 1) {
            const prefix = name.replace(/\.(zip|tar|tar\.gz)$/i, '');
            archiveNameForRequest = prefix || names[0];
        }

        const extFinal = extByType[type] || '.zip';
        const withArchiveExt = (base) => {
            const b = String(base).replace(/\.(zip|tar|tar\.gz)$/i, '');
            return b + extFinal;
        };

        this.hideAndDestroy();

        const archiveBusyMsg = '<p class="mt-0">The server is building your archive. Large folders may take a while.</p>';
        const busyPopup = new FmIndeterminateProgressPopup({ title: 'Archive', messageHtml: archiveBusyMsg });
        busyPopup.show();

        const startStream = (total) => {
            let finished  = false;
            const onSuccessCb = this._onSuccess;

            const form = new FormData();
            form.append('action',       'compress');
            form.append('in',           this._pathIn());
            form.append('archive_name', archiveNameForRequest);
            form.append('archive_type', type);
            form.append('total',        String(total));
            form.append('overwrite',    allowOverwrite ? '1' : '0');
            names.forEach(n => form.append('names[]', n));

            fetch(this._ajaxUrl, fmPostInit(form))
                .then(response => {
                    if (!response.ok)   throw new Error('HTTP ' + response.status);
                    if (!response.body) throw new Error('No response body');
                    const reader  = response.body.getReader();
                    const decoder = new TextDecoder();
                    let buffer = '';

                    const handleLine = (line) => {
                        line = (line || '').trim();
                        if (!line) return;
                        let data;
                        try { data = JSON.parse(line); } catch (e) { return; }
                        if (data.error && !finished) {
                            finished = true;
                            busyPopup.hideAndDestroy();
                            fmUserNotice({ title: 'Archive', message: data.error });
                        }
                        if (data.done && !finished) {
                            finished = true;
                            busyPopup.hideAndDestroy();
                            if (onSuccessCb) onSuccessCb([withArchiveExt(archiveNameForRequest)]);
                        }
                    };

                    const pump = () => reader.read().then(res => {
                        if (res.done) { if (buffer) handleLine(buffer); return; }
                        buffer += decoder.decode(res.value, { stream: true });
                        const lines = buffer.split('\n');
                        buffer = lines.pop() || '';
                        lines.forEach(handleLine);
                        return pump();
                    }).catch(() => {
                        if (!finished) { finished = true; busyPopup.hideAndDestroy(); fmUserNotice({ title: 'Archive', message: 'Could not create archive.' }); }
                    });
                    return pump();
                })
                .catch(() => {
                    if (!finished) { finished = true; busyPopup.hideAndDestroy(); fmUserNotice({ title: 'Archive', message: 'Could not create archive.' }); }
                });
        };

        const pathIn    = this._pathIn();
        const countForm = new FormData();
        countForm.append('action', 'get-files-and-folders-count');
        countForm.append('mode',   'files');
        countForm.append('in',     pathIn);
        names.forEach(n => countForm.append('names[]', n));

        fetch(this._ajaxUrl, fmPostInit(countForm))
            .then(r => r.json())
            .then(countData => {
                const total = (countData.status === 'success' && typeof countData.total === 'number') ? countData.total : names.length;
                startStream(total);
            })
            .catch(() => startStream(names.length));
    }
}

class FmExtractPopup extends FmPopup {
    constructor(options = {}) {
        const {
            currentPath,
            rootDir,
            ajaxUrl,
            names,
            execAvailable,
            onSuccess,
            ...rest
        } = options;

        let execOk = true;
        if (typeof execAvailable === 'boolean') {
            execOk = execAvailable;
        } else if (typeof window !== 'undefined' && typeof window.fm_exec_available === 'boolean') {
            execOk = window.fm_exec_available;
        }

        const namesArr = names || [];
        const extractIntro =
            '<p class="fm-hint fm-extract-option-hint">' +
            (execOk
                ? 'The server tries OS extract tools first when possible, then PHP (ZipArchive / Phar).'
                : 'PHP <code>exec()</code> is disabled; extraction uses PHP only.') +
            '</p>';

        super({
            title: 'Extract',
            submitOnEnter: true,
            content:
                '<p class="fm-extract-selected-count">' +
                (namesArr.length === 1
                    ? '<strong>1</strong> item selected.'
                    : '<strong>' + namesArr.length + '</strong> items selected.') +
                '</p>' +
                '<hr class="fm-popup-divider" aria-hidden="true">' +
                '<p><label class="fm-option-label">' +
                '<input type="checkbox" name="extract-separate" class="fm-extract-separate fm-input-focus-on-open" checked="checked"> Extract each archive into its own folder</label></p>' +
                '<p class="fm-hint fm-extract-option-hint">' +
                'Each archive is unpacked into a subfolder named like the file (without .zip, .tar, or .tar.gz). ' +
                'That folder must not exist yet, unless it is completely empty.</p>' +
                '<hr class="fm-popup-divider" aria-hidden="true">' +
                extractIntro +
                '<div class="fm-option-hint" role="status"><span class="fm-option-hint-text"></span></div>',
            buttons: [
                { label: 'Cancel', close: true },
                { label: 'Extract', primary: true, close: false, onClick: () => this._handleExtract() }
            ],
            onAfterShow() {
                this._bindExtractForm();
            },
            ...rest
        });

        this._currentPath   = currentPath;
        this._rootDir       = rootDir;
        this._ajaxUrl       = ajaxUrl;
        this._names         = namesArr;
        this._execAvailable = execOk;
        this._onSuccess     = onSuccess || null;
    }

    _pathIn() {
        return fmPathInParam(this._currentPath, this._rootDir);
    }

    _bindExtractForm() {
        const hintEl   = this.querySelector('.fm-option-hint-text');
        const hintWrap = this.querySelector('.fm-option-hint');
        if (hintEl) {
            hintEl.textContent = 'If OS tools fail or are unavailable, extraction continues with PHP automatically.';
        }
        if (hintWrap) {
            hintWrap.classList.remove('fm-option-hint--excellent', 'fm-option-hint--good', 'fm-option-hint--medium', 'fm-option-hint--slow', 'fm-option-hint--veryslow');
            hintWrap.classList.add('fm-option-hint--good');
        }
    }

    _handleExtract() {
        const sepEl     = this.querySelector('.fm-extract-separate');
        const separate  = !!(sepEl && sepEl.checked);
        const onSuccessCb = this._onSuccess;
        const pathIn    = this._pathIn();
        const names     = this._names;
        const ajaxUrl   = this._ajaxUrl;

        this.hideAndDestroy();

        const waitPopup = new FmPopup({
            title: 'Extract',
            content:
                '<div>' +
                '<p class="fm-progress-label">Extracting\u2026</p>' +
                '<p class="fm-hint fm-extract-wait">Please wait.</p>' +
                '<div class="fm-progress-wrap fm-extract-progress-wrap"><div class="fm-progress-bar"></div></div>' +
                '</div>',
            buttons: [],
            closeOnBackdrop: false,
            closeOnEscape:   false,
            maxWidth:        '420px'
        });
        waitPopup.show();

        const extractBarWrap = waitPopup.querySelector('.fm-extract-progress-wrap');
        let finished = false;

        const form = new FormData();
        form.append('action', 'extract');
        form.append('in', pathIn);
        form.append('separate_folders', separate ? '1' : '0');
        names.forEach(n => form.append('names[]', n));

        fetch(ajaxUrl, fmPostInit(form))
            .then(response => {
                if (!response.ok) throw new Error('HTTP ' + response.status);
                if (!response.body) throw new Error('No response body');
                const reader  = response.body.getReader();
                const decoder = new TextDecoder();
                let buffer = '';

                const handleLine = (line) => {
                    line = (line || '').trim();
                    if (!line) return;
                    let data;
                    try { data = JSON.parse(line); } catch (e) { return; }
                    if (data && Object.prototype.hasOwnProperty.call(data, 'verbose_progress')) {
                        if (!data.verbose_progress && extractBarWrap) {
                            extractBarWrap.classList.add('fm-progress-indeterminate');
                        } else if (extractBarWrap) {
                            extractBarWrap.classList.remove('fm-progress-indeterminate');
                        }
                    }
                    if (data.error && !finished) {
                        finished = true;
                        waitPopup.hideAndDestroy();
                        fmUserNotice({ title: 'Extract', message: data.error });
                    }
                    if (data.done && !finished) {
                        finished = true;
                        waitPopup.hideAndDestroy();
                        if (onSuccessCb) onSuccessCb();
                    }
                };

                const pump = () => reader.read().then(res => {
                    if (res.done) { if (buffer) handleLine(buffer); return; }
                    buffer += decoder.decode(res.value, { stream: true });
                    const lines = buffer.split('\n');
                    buffer = lines.pop() || '';
                    lines.forEach(handleLine);
                    return pump();
                }).catch(() => {
                    if (!finished) { finished = true; waitPopup.hideAndDestroy(); fmUserNotice({ title: 'Extract', message: 'Could not extract.' }); }
                });
                return pump();
            })
            .catch(() => {
                if (!finished) { finished = true; waitPopup.hideAndDestroy(); fmUserNotice({ title: 'Extract', message: 'Could not extract.' }); }
            });
    }
}

class FmRenamePopup extends FmPopup {
    constructor(options = {}) {
        const { currentName, onSuccess, isFolder, ...rest } = options;

        super({
            title:   'Rename',
            content: '<p><label><span>New name</span><input type="text" name="new-name" class="fm-input-focus-on-open fm-input" autocomplete="off"></label><p class="fm-error fm-hidden" data-err></p></p>',
            submitOnEnter: true,
            buttons: [
                { label: 'Cancel', close: true },
                { label: 'Rename', primary: true, close: false, onClick: () => this._handleRename() }
            ],
            onAfterShow() {
                const input = this.querySelector('input');
                if (input) {
                    input.value = this._currentName;
                    input.focus();

                    if (this._renameIsFolder) {
                        input.select();
                    } else {
                        const val = input.value;
                        const lastDot = val.lastIndexOf('.');
                        if (lastDot > 0) {
                            input.setSelectionRange(0, lastDot);
                        } else {
                            input.select();
                        }
                    }
                }
            },
            ...rest
        });

        this._currentName    = currentName || '';
        this._renameIsFolder = !!isFolder;
        this._onSuccess      = onSuccess || null;
    }

    _handleRename() {
        const input = this.querySelector('input');
        const err   = this.querySelector('[data-err]');
        if (err) err.style.display = 'none';

        const name = (input ? input.value : '').trim();

        if (!name) {
            if (err) { err.textContent = 'Enter a name'; err.style.display = 'block'; }
            return;
        }
        if (name === this._currentName) {
            this.hideAndDestroy();
            return;
        }
        if (/[\\/:*?"<>|]/.test(name)) {
            if (err) { err.textContent = 'Name cannot contain \\ / : * ? " < > |'; err.style.display = 'block'; }
            return;
        }

        this.hideAndDestroy();
        if (this._onSuccess) this._onSuccess(name);
    }
}

class FmGetInfoPopup extends FmPopup {
    constructor(options = {}) {
        const { fullPath, rootDir, ajaxUrl, ...rest } = options;

        super({
            title:    'Info',
            content:  '<div class="fm-info-loading ta-c"><span class="fm-spinner"></span> Loading\u2026</div>',
            buttons:  [{ label: 'OK', primary: true, close: true }],
            maxWidth: '480px',
            onAfterShow() { this._fetchAndRender(); },
            ...rest
        });

        this._fullPath = fullPath;
        this._rootDir  = rootDir || '';
        this._ajaxUrl  = ajaxUrl;
    }

    _fetchAndRender() {
        requireAuthFetch(this._ajaxUrl + '?' + new URLSearchParams({ action: 'get-info', path: this._fullPath }))
            .then(r => r.json())
            .then(data => {
                if (data.status !== 'success') {
                    this._setContent('<p class="ta-c fm-error-text">' + FmPopup.escapeHtml(data.msg || 'Could not get info') + '</p>');
                    return;
                }
                this._setContent(this._buildContent(data));
                if (data.type === 'folder') {
                    this._fetchCounts(data.name, data.path);
                }
            })
            .catch(() => {
                this._setContent('<p class="ta-c fm-error-text">Could not get info</p>');
            });
    }

    _fetchCounts(name, itemFullPath) {
        const form = new FormData();
        form.append('action', 'get-files-and-folders-count');
        form.append('mode', 'all');
        form.append('count_abs_path', itemFullPath);

        requireAuthFetch(this._ajaxUrl, fmPostInit(form))
            .then(r => r.json())
            .then(data => {
                const filesEl   = this.el && this.el.querySelector('.fm-info-files-count');
                const foldersEl = this.el && this.el.querySelector('.fm-info-folders-count');
                if (filesEl) {
                    filesEl.textContent = data.status === 'success'
                        ? Number(data.files).toLocaleString()
                        : '\u2014';
                }
                if (foldersEl) {
                    foldersEl.textContent = data.status === 'success'
                        ? Number(data.folders).toLocaleString()
                        : '\u2014';
                }
            })
            .catch(() => {
                const filesEl   = this.el && this.el.querySelector('.fm-info-files-count');
                const foldersEl = this.el && this.el.querySelector('.fm-info-folders-count');
                if (filesEl)   filesEl.textContent   = '\u2014';
                if (foldersEl) foldersEl.textContent = '\u2014';
            });
    }

    _buildContent(data) {
        const isFolder  = data.type === 'folder';
        const typeLabel = isFolder ? 'Folder' : (data.extension ? data.extension.toUpperCase() + ' File' : 'File');
        const sizeLabel = data.size != null ? FmGetInfoPopup._bytesToSize(data.size) : '\u2014';

        const rootDisplay = FmGetInfoPopup._normSlashes(this._rootDir) || '\u2014';
        const relPath     = FmGetInfoPopup._pathWithoutRoot(data.path, this._rootDir);
        const relDisplay  = relPath === null
            ? FmPopup.escapeHtml(FmGetInfoPopup._normSlashes(data.path))
            : (relPath === '' ? FmPopup.escapeHtml('(at root)') : FmPopup.escapeHtml(relPath));

        const spinner = '<span class="fm-spinner"></span>';
        const rows = [
            ['Name',            FmPopup.escapeHtml(data.name || '\u2014'), ''],
            ['Full path',       FmPopup.escapeHtml(FmGetInfoPopup._normSlashes(data.path) || '\u2014'), ''],
            ['Home path',       FmPopup.escapeHtml(rootDisplay), ''],
            ['Path (relative)', relDisplay, ''],
            ['Type',            FmPopup.escapeHtml(typeLabel), ''],
            ['Size',            FmPopup.escapeHtml(sizeLabel), ''],
        ];

        if (isFolder) {
            rows.push(['Files',   spinner, ' class="fm-info-files-count"']);
            rows.push(['Folders', spinner, ' class="fm-info-folders-count"']);
        }

        rows.push(['Modified',    FmPopup.escapeHtml(data.modified    || '\u2014'), '']);
        rows.push(['Permissions', FmPopup.escapeHtml(data.permissions || '\u2014'), '']);

        const rowsHtml = rows.map(([label, value, tdAttr]) =>
            `<tr><th>${FmPopup.escapeHtml(label)}</th><td${tdAttr}>${value}</td></tr>`
        ).join('');

        return `<table class="fm-info-table">${rowsHtml}</table>`;
    }

    _setContent(html) {
        const contentEl = this.el && this.el.querySelector('.fm-popup-content');
        if (contentEl) {
            contentEl.innerHTML = html;
        }
    }

    static _bytesToSize(bytes) {
        if (bytes === 0) return '0 B';
        const k     = 1024;
        const sizes = ['B', 'KB', 'MB', 'GB', 'TB'];
        const i     = Math.floor(Math.log(bytes) / Math.log(k));
        return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
    }

    static _normSlashes(p) {
        return String(p || '').replace(/\\/g, '/').replace(/\/+$/, '');
    }

    static _pathWithoutRoot(fullPath, rootDir) {
        const f = FmGetInfoPopup._normSlashes(fullPath);
        const r = FmGetInfoPopup._normSlashes(rootDir);
        if (!f || !r) return null;
        if (f === r) return '';
        const prefix = r + '/';
        if (!f.startsWith(prefix)) return null;
        return f.slice(prefix.length);
    }
}

class FmServerInfoPopup extends FmPopup {
    constructor(options = {}) {
        const { ajaxUrl, ...rest } = options;
        super({
            title:    'Server info',
            content:  '<div class="fm-info-loading ta-c"><span class="fm-spinner"></span> Loading\u2026</div>',
            buttons:  [{ label: 'OK', primary: true, close: true }],
            maxWidth: '620px',
            onAfterShow() { this._fetchAndRender(); },
            ...rest
        });
        this._ajaxUrl = ajaxUrl;
    }

    _fetchAndRender() {
        requireAuthFetch(this._ajaxUrl + '?' + new URLSearchParams({ action: 'get-server-info' }))
            .then(r => r.json())
            .then(data => {
                if (data.status !== 'success') {
                    this._setContent('<p class="ta-c fm-error-text">' + FmPopup.escapeHtml(data.msg || 'Could not load server info') + '</p>');
                    return;
                }
                this._setContent(this._buildContent(data));
                this._fetchTrashInfo();
            })
            .catch(() => {
                this._setContent('<p class="ta-c fm-error-text">Could not load server info</p>');
            });
    }

    _fetchTrashInfo() {
        const cell = this.querySelector('.fm-info-trash');
        if (!cell) return;
        requireAuthFetch(this._ajaxUrl + '?' + new URLSearchParams({ action: 'get-trash-info' }))
            .then(r => r.json())
            .then(data => {
                if (data.status !== 'success') {
                    cell.textContent = data.msg || 'Could not read Trash';
                    return;
                }
                cell.innerHTML = FmServerInfoPopup._trashLine(data);
                const open = cell.querySelector('.fm-info-trash-open');
                if (open) {
                    open.addEventListener('click', (e) => {
                        e.preventDefault();
                        this.hideAndDestroy();
                        const btn = document.getElementById('fm-sidebar-trash-btn');
                        if (btn) btn.click();
                    });
                }
            })
            .catch(() => {
                cell.textContent = 'Could not read Trash';
            });
    }

    static _trashLine(t) {
        const esc = FmPopup.escapeHtml;
        const name = '<code>' + esc(t.basename || '.trash') + '</code>';
        if (!t.exists) {
            return name + ' — empty (not created yet)';
        }
        const link = '<a href="#" class="fm-info-trash-open" title="Open Trash">' + name + '</a>';
        const n = Number(t.items) || 0;
        if (!n) {
            return link + ' — empty';
        }
        const items = n === 1 ? '1 item' : n + ' items';
        const size = FmServerInfoPopup._bytesToSize(Number(t.bytes) || 0) + (t.truncated ? '+' : '');
        return link + ' — ' + esc(items + ', ' + size) + ' <span class="fm-info-muted">(Empty Trash frees this space)</span>';
    }

    _buildContent(data) {
        const esc = (s) => {
            const v = s != null && String(s).trim() !== '' ? String(s) : '\u2014';
            return FmPopup.escapeHtml(v);
        };
        const diskLine = FmServerInfoPopup._diskLine(data.disk_free_bytes, data.disk_total_bytes);
        const execLine = typeof data.exec_available === 'boolean'
            ? FmPopup.escapeHtml(data.exec_available ? 'Available' : 'Disabled (php.ini disable_functions)')
            : esc(null);
        const fileOpsLine = FmServerInfoPopup._fileOpsModeLine(data.file_ops_mode);
        const verboseTh = typeof data.verbose_progress_min_items === 'number'
            ? FmPopup.escapeHtml(String(data.verbose_progress_min_items))
            : esc(null);
        const authLine = data.auth_enabled === false
            ? '<span class="fm-info-danger">Off — anyone who knows this URL has full access</span>'
            : (data.auth_enabled === true ? 'On' : esc(null));
        const dirAccessLine = data.allow_all_directories
            ? 'All server directories (unrestricted)'
            : 'Locked to root_path';
        const rows = [
            ['SoloFileManager version', esc(data.solofilemanager_version)],
            ['Directory access',        esc(dirAccessLine)],
            ['Authentication',          authLine],
            ['PHP version',             esc(data.php_version)],
            ['PHP exec()',              execLine],
            ['File ops mode',           fileOpsLine],
            ['Verbose progress (≥ N items)', verboseTh],
            ['PHP SAPI',                esc(data.php_sapi)],
            ['Server software',         esc(data.server_software)],
            ['Operating system',        esc(data.os)],
            ['Time zone',               esc(data.timezone)],
            ['Server time',             esc(data.server_time)],
            ['Memory limit',            esc(data.memory_limit)],
            ['Max execution time',      esc(
                data.max_execution_time != null && String(data.max_execution_time).trim() !== ''
                    ? String(data.max_execution_time) + ' s'
                    : null
            )],
            ['Upload max filesize',     esc(data.upload_max_filesize)],
            ['Post max size',           esc(data.post_max_size)],
            ['File manager home',       esc(data.root_path)],
            ['Disk (root volume)',      diskLine],
            ['Trash',                   '<span class="fm-info-trash"><span class="fm-spinner"></span> Counting\u2026</span>'],
        ];
        const rowsHtml = rows.map(([label, value]) =>
            `<tr><th>${esc(label)}</th><td>${value}</td></tr>`
        ).join('');
        return `<table class="fm-info-table">${rowsHtml}</table>`;
    }

    _setContent(html) {
        const contentEl = this.el && this.el.querySelector('.fm-popup-content');
        if (contentEl) {
            contentEl.innerHTML = html;
        }
    }

    static _fileOpsModeLine(mode) {
        const raw = mode != null && String(mode).trim() !== ''
            ? String(mode).toLowerCase().trim()
            : 'auto';
        let text;
        if (raw === 'php') {
            text = 'PHP only (compress, extract, copy/move, delete-stream, folder-size)';
        } else if (raw === 'os' || raw === 'shell') {
            text = 'OS / shell only (no PHP fallback)';
        } else {
            text = 'Auto (try OS when exec() is available, then PHP)';
        }
        return FmPopup.escapeHtml(text);
    }

    static _diskLine(free, total) {
        if (free == null && total == null) {
            return FmPopup.escapeHtml('\u2014');
        }
        const f = free != null ? FmServerInfoPopup._bytesToSize(Number(free)) : '\u2014';
        const t = total != null ? FmServerInfoPopup._bytesToSize(Number(total)) : '\u2014';
        return FmPopup.escapeHtml(f + ' free / ' + t + ' total');
    }

    static _bytesToSize(bytes) {
        if (bytes === 0) return '0 B';
        const k     = 1024;
        const sizes = ['B', 'KB', 'MiB', 'GiB', 'TiB'];
        const i     = Math.min(Math.floor(Math.log(bytes) / Math.log(k)), sizes.length - 1);
        return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
    }
}

class FmTerminalHerePopup extends FmPopup {
    static STORAGE_KEY = 'fm_terminal_popup_state_1';
    constructor(options = {}) {
        const { currentPath, rootDir, ajaxUrl, ...rest } = options;
        super({
            title: 'Terminal / Shell Command',
            className: 'fm-terminal-popup',
            maxWidth: '980px',
            submitOnEnter: true,
            content:
                '<p class="fm-terminal-intro">Run shell commands in this folder.</p>' +
                '<p class="fm-terminal-path"></p>' +
                '<div class="fm-terminal-controls">' +
                '<label>Preset ' +
                '<select class="fm-terminal-cmd fm-input">' +
                '<option value="pwd">Print working directory</option>' +
                '<option value="list">List files</option>' +
                '<option value="phpv">PHP version</option>' +
                '<option value="git_status">Git status</option>' +
                '</select></label>' +
                '</div>' +
                '<label class="fm-terminal-manual-row"><input type="checkbox" class="fm-terminal-manual-toggle"> Manual command (safe subset)</label>' +
                '<label class="fm-terminal-manual-row fm-terminal-advanced-row fm-hidden"><input type="checkbox" class="fm-terminal-advanced-toggle"> Shell command mode (raw command)</label>' +
                '<label class="fm-terminal-manual-input-wrap fm-hidden">Command ' +
                '<input type="text" class="fm-input fm-terminal-user-cmd" autocomplete="off" placeholder="git status --short --branch"></label>' +
                '<p class="fm-terminal-manual-hint fm-hidden">Allowed in manual mode: <code>git</code>, <code>php</code>, <code>whoami</code> with safe argument patterns only.</p>' +
                '<p class="fm-terminal-manual-hint fm-terminal-advanced-hint fm-hidden">Shell command mode runs raw commands directly in this folder.</p>' +
                '<p class="fm-terminal-safety-note">Security note: temporary execution files are automatically removed after each command (including failures).</p>' +
                '<div class="fm-terminal-output">' +
                    '<pre class="fm-terminal-output-log">No output yet.</pre>' +
                    '<div class="fm-terminal-console-suggestions fm-hidden"></div>' +
                    '<div class="fm-terminal-console-row fm-hidden">' +
                        '<span class="fm-terminal-console-prompt" aria-hidden="true">&gt;</span>' +
                        '<input type="text" class="fm-terminal-console-input" spellcheck="false" autocomplete="off" placeholder="Type shell command and press Enter">' +
                    '</div>' +
                '</div>',
            buttons: [
                { label: 'Close', close: true },
                { label: 'Reset', close: false, onClick: () => this._resetState() },
                { label: 'Run', primary: true, close: false, onClick: () => this._run() },
            ],
            onAfterShow() {
                this._renderPath();
                this._ensureSettingsButton();
            },
            ...rest,
        });
        this._currentPath = currentPath || '';
        this._rootDir = rootDir || '';
        this._ajaxUrl = ajaxUrl || '';
        this._history = [];
        this._historyIndex = -1;
        this._historyDraft = '';
        this._tabState = null;
        this._terminalLexicon = [
            'dir', 'cd', 'cls', 'echo', 'type', 'copy', 'move', 'del', 'mkdir', 'rmdir',
            'git status --short --branch', 'git pull --rebase', 'git log --oneline -n 10',
            'php -v', 'php -m', 'php --ini', 'whoami', 'pwd', 'ls -la', 'uname -a', 'df -h'
        ];
    }

    _ensureSettingsButton() {
        if (!this.el) return;
        const header = this.el.querySelector('.fm-popup-header');
        if (!header || header.querySelector('.fm-terminal-settings-btn')) return;
        const btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'fm-terminal-settings-btn';
        btn.title = 'Terminal settings';
        btn.setAttribute('aria-label', 'Terminal settings');
        btn.innerHTML = '<i class="bi bi-gear" aria-hidden="true"></i>';
        btn.addEventListener('click', (e) => {
            e.preventDefault();
            e.stopPropagation();
            if (typeof fmShowTerminalSettingsPopup !== 'function') return;
            fmShowTerminalSettingsPopup({
                ajaxUrl: this._ajaxUrl,
                onSaved: () => location.reload(),
            });
        });
        const closeBtn = header.querySelector('.fm-popup-close');
        if (closeBtn) {
            header.insertBefore(btn, closeBtn);
        } else {
            header.appendChild(btn);
        }
    }

    _run() {
        const cmdSel = this.el && this.el.querySelector('.fm-terminal-cmd');
        const manualTgl = this.el && this.el.querySelector('.fm-terminal-manual-toggle');
        const advancedTgl = this.el && this.el.querySelector('.fm-terminal-advanced-toggle');
        const manualIn = this.el && this.el.querySelector('.fm-terminal-user-cmd');
        const outEl = this._outputLogEl();
        const primary = this.el && this.el.querySelector('.fm-buttons .fm-popup-action-button.fm-popup-primary');
        if (!cmdSel || !outEl) return;
        const inPath = fmPathInParam(this._currentPath, this._rootDir);
        const manualAllowed = typeof window !== 'undefined' && window.fm_terminal_manual_enabled === true;
        const advancedAllowed = typeof window !== 'undefined' && window.fm_terminal_advanced_enabled === true;
        const useAdvanced = !!(advancedAllowed && advancedTgl && advancedTgl.checked);
        const useManual = !!(!useAdvanced && manualAllowed && manualTgl && manualTgl.checked);
        let userCmd = '';
        const form = new FormData();
        form.append('action', 'terminal-run');
        form.append('in', inPath);
        form.append('mode', useAdvanced ? 'advanced' : (useManual ? 'manual' : 'preset'));
        if (useAdvanced || useManual) {
            userCmd = manualIn ? String(manualIn.value || '').trim() : '';
            if (userCmd === '') {
                return;
            }
            form.append('user_cmd', userCmd);
            form.append('cmd', useAdvanced ? 'advanced' : 'manual');
            this._pushHistory(userCmd);
            this._hideConsoleSuggestions();
            if (useAdvanced) {
                const consoleInput = this.el && this.el.querySelector('.fm-terminal-console-input');
                if (manualIn) manualIn.value = '';
                if (consoleInput) consoleInput.value = '';
            }
        } else {
            form.append('cmd', String(cmdSel.value || 'pwd'));
        }
        if (primary) primary.disabled = true;
        if (useAdvanced || useManual) {
            this._appendOutputBlock(userCmd, null, 'Running...');
        } else {
            this._setOutputText('Running...');
        }

        const run = typeof requireAuthFetch === 'function'
            ? requireAuthFetch(this._ajaxUrl, fmPostInit(form))
            : fetch(this._ajaxUrl, fmPostInit(form));
        run
            .then((r) => r.text().then((t) => ({ ok: r.ok, status: r.status, text: t })))
            .then(({ ok, status, text }) => {
                let data = null;
                try {
                    data = text ? JSON.parse(text) : null;
                } catch (e) {
                    throw new Error('Invalid JSON response (' + status + ')');
                }
                if (!ok) {
                    throw new Error((data && data.msg) ? String(data.msg) : ('Request failed (' + status + ')'));
                }
                return data;
            })
            .then((data) => {
                if (primary) primary.disabled = false;
                if (!data || data.status !== 'success') {
                    const msg = data && data.msg ? String(data.msg) : 'Command failed.';
                    if (useAdvanced || useManual) {
                        this._appendOutputBlock(userCmd || 'command', null, 'Error: ' + msg);
                    } else {
                        this._setOutputText('');
                    }
                    return;
                }
                const label = data.label ? String(data.label) : String(cmdSel.value || '');
                const code = data.exit_code != null ? Number(data.exit_code) : 0;
                const body = data.output != null ? String(data.output) : '';
                if (useAdvanced || useManual) {
                    this._appendOutputBlock(label, code, body);
                } else {
                    this._setOutputText('$ ' + label + '\n(exit code: ' + code + ')\n\n' + body);
                }
            })
            .catch(() => {
                if (primary) primary.disabled = false;
                if (useAdvanced || useManual) {
                    this._appendOutputBlock(userCmd || 'command', null, 'Request failed.');
                } else {
                    this._setOutputText('');
                }
            });
    }

    _syncManualUi() {
        const manualAllowed = typeof window !== 'undefined' && window.fm_terminal_manual_enabled === true;
        const advancedAllowed = typeof window !== 'undefined' && window.fm_terminal_advanced_enabled === true;
        const tgl = this.el && this.el.querySelector('.fm-terminal-manual-toggle');
        const advancedRow = this.el && this.el.querySelector('.fm-terminal-advanced-row');
        const advancedTgl = this.el && this.el.querySelector('.fm-terminal-advanced-toggle');
        const wrap = this.el && this.el.querySelector('.fm-terminal-manual-input-wrap');
        const hint = this.el && this.el.querySelector('.fm-terminal-manual-hint');
        const advancedHint = this.el && this.el.querySelector('.fm-terminal-advanced-hint');
        const safetyNote = this.el && this.el.querySelector('.fm-terminal-safety-note');
        const sel = this.el && this.el.querySelector('.fm-terminal-cmd');
        const manualIn = this.el && this.el.querySelector('.fm-terminal-user-cmd');
        const consoleRow = this.el && this.el.querySelector('.fm-terminal-console-row');
        const consoleInput = this.el && this.el.querySelector('.fm-terminal-console-input');
        if (!tgl || !wrap || !hint || !advancedHint || !sel || !consoleRow || !consoleInput) return;
        if (!manualAllowed) {
            tgl.checked = false;
            tgl.disabled = true;
            tgl.parentElement && tgl.parentElement.classList.add('fm-terminal-manual-row--disabled');
        }
        if (advancedRow) {
            advancedRow.classList.toggle('fm-hidden', !advancedAllowed);
        }
        if (advancedTgl && !advancedAllowed) {
            advancedTgl.checked = false;
            advancedTgl.disabled = true;
            advancedTgl.parentElement && advancedTgl.parentElement.classList.add('fm-terminal-manual-row--disabled');
        }
        const manualOn = manualAllowed && tgl.checked;
        const advancedOn = !!(advancedAllowed && advancedTgl && advancedTgl.checked);
        const on = manualOn || advancedOn;
        wrap.classList.toggle('fm-hidden', !manualOn);
        consoleRow.classList.toggle('fm-hidden', !advancedOn);
        hint.classList.toggle('fm-hidden', !manualOn);
        advancedHint.classList.toggle('fm-hidden', !advancedOn);
        if (safetyNote) {
            safetyNote.classList.toggle('fm-hidden', advancedOn);
        }
        if (manualIn) {
            manualIn.placeholder = advancedOn ? 'ls -la' : 'git status --short --branch';
        }
        if (advancedOn) {
            consoleInput.value = manualIn ? String(manualIn.value || '') : '';
            setTimeout(() => {
                try { consoleInput.focus(); } catch (e) {}
            }, 0);
        } else {
            this._hideConsoleSuggestions();
        }
        sel.disabled = on;
        this._persistState();
    }

    _renderPath() {
        const el = this.el && this.el.querySelector('.fm-terminal-path');
        if (el) {
            el.textContent = this._currentPath || this._rootDir || '';
        }
        const tgl = this.el && this.el.querySelector('.fm-terminal-manual-toggle');
        const advancedTgl = this.el && this.el.querySelector('.fm-terminal-advanced-toggle');
        if (tgl) {
            tgl.addEventListener('change', () => {
                if (tgl.checked && advancedTgl) advancedTgl.checked = false;
                this._syncManualUi();
            });
        }
        if (advancedTgl) {
            advancedTgl.addEventListener('change', () => {
                if (advancedTgl.checked && tgl) tgl.checked = false;
                this._syncManualUi();
            });
        }
        const cmdSel = this.el && this.el.querySelector('.fm-terminal-cmd');
        const manualIn = this.el && this.el.querySelector('.fm-terminal-user-cmd');
        if (cmdSel) cmdSel.addEventListener('change', () => this._persistState());
        if (manualIn) manualIn.addEventListener('input', () => this._persistState());
        if (manualIn) {
            manualIn.addEventListener('keydown', (e) => this._handleHistoryKeydown(e));
        }
        const outWrap = this.el && this.el.querySelector('.fm-terminal-output');
        const consoleInput = this.el && this.el.querySelector('.fm-terminal-console-input');
        if (consoleInput && manualIn) {
            consoleInput.addEventListener('input', () => {
                manualIn.value = consoleInput.value;
                this._tabState = null;
                this._updateConsoleSuggestions(consoleInput.value);
                this._persistState();
            });
            consoleInput.addEventListener('keydown', (e) => {
                if (e.key === 'Enter' && !e.shiftKey) {
                    e.preventDefault();
                    this._run();
                    return;
                }
                if (e.key === 'Tab') {
                    e.preventDefault();
                    this._handleConsoleTab(consoleInput).then(() => {
                        manualIn.value = consoleInput.value;
                        this._persistState();
                    });
                    return;
                }
                this._handleHistoryKeydown(e, consoleInput);
                manualIn.value = consoleInput.value;
            });
        }
        if (outWrap && consoleInput) {
            outWrap.addEventListener('click', () => {
                const advancedTgl = this.el && this.el.querySelector('.fm-terminal-advanced-toggle');
                if (advancedTgl && advancedTgl.checked) {
                    try { consoleInput.focus(); } catch (e) {}
                }
            });
        }
        this._restoreState();
        this._syncManualUi();
    }

    _persistState() {
        try {
            const cmdSel = this.el && this.el.querySelector('.fm-terminal-cmd');
            const manualTgl = this.el && this.el.querySelector('.fm-terminal-manual-toggle');
            const advancedTgl = this.el && this.el.querySelector('.fm-terminal-advanced-toggle');
            const manualIn = this.el && this.el.querySelector('.fm-terminal-user-cmd');
            const payload = {
                cmd: cmdSel ? String(cmdSel.value || 'pwd') : 'pwd',
                manual: !!(manualTgl && manualTgl.checked),
                advanced: !!(advancedTgl && advancedTgl.checked),
                userCmd: manualIn ? String(manualIn.value || '') : '',
                history: this._history,
            };
            localStorage.setItem(FmTerminalHerePopup.STORAGE_KEY, JSON.stringify(payload));
        } catch (e) {}
    }

    _restoreState() {
        try {
            const raw = localStorage.getItem(FmTerminalHerePopup.STORAGE_KEY);
            const advancedTgl = this.el && this.el.querySelector('.fm-terminal-advanced-toggle');
            if (!raw) {
                if (advancedTgl && typeof window !== 'undefined' && window.fm_terminal_advanced_enabled === true) {
                    advancedTgl.checked = true;
                }
                return;
            }
            const data = JSON.parse(raw);
            const cmdSel = this.el && this.el.querySelector('.fm-terminal-cmd');
            const manualTgl = this.el && this.el.querySelector('.fm-terminal-manual-toggle');
            const manualIn = this.el && this.el.querySelector('.fm-terminal-user-cmd');
            if (cmdSel && data && typeof data.cmd === 'string') cmdSel.value = data.cmd;
            if (manualTgl) manualTgl.checked = !!(data && data.manual === true);
            if (advancedTgl) advancedTgl.checked = !!(data && data.advanced === true);
            if (manualIn && data && typeof data.userCmd === 'string') manualIn.value = data.userCmd;
            this._history = (data && Array.isArray(data.history))
                ? data.history.filter((it) => typeof it === 'string' && it.trim() !== '').slice(-50)
                : [];
            this._historyIndex = -1;
            this._historyDraft = '';
        } catch (e) {}
    }

    _resetState() {
        try { localStorage.removeItem(FmTerminalHerePopup.STORAGE_KEY); } catch (e) {}
        const cmdSel = this.el && this.el.querySelector('.fm-terminal-cmd');
        const manualTgl = this.el && this.el.querySelector('.fm-terminal-manual-toggle');
        const advancedTgl = this.el && this.el.querySelector('.fm-terminal-advanced-toggle');
        const manualIn = this.el && this.el.querySelector('.fm-terminal-user-cmd');
        const consoleInput = this.el && this.el.querySelector('.fm-terminal-console-input');
        if (cmdSel) cmdSel.value = 'pwd';
        if (manualTgl) manualTgl.checked = false;
        if (advancedTgl) advancedTgl.checked = (typeof window !== 'undefined' && window.fm_terminal_advanced_enabled === true);
        if (manualIn) manualIn.value = '';
        if (consoleInput) consoleInput.value = '';
        this._historyIndex = -1;
        this._historyDraft = '';
        this._hideConsoleSuggestions();
        this._setOutputText('No output yet.');
        this._syncManualUi();
    }

    _pushHistory(cmd) {
        const c = String(cmd || '').trim();
        if (!c) return;
        const next = (this._history || []).filter((x) => x !== c);
        next.push(c);
        this._history = next.slice(-50);
        this._historyIndex = -1;
        this._historyDraft = '';
        this._persistState();
    }

    _handleHistoryKeydown(e, targetInput) {
        if (!e || (e.key !== 'ArrowUp' && e.key !== 'ArrowDown')) {
            return;
        }
        const input = targetInput || (this.el && this.el.querySelector('.fm-terminal-user-cmd'));
        if (!input) return;
        const list = Array.isArray(this._history) ? this._history : [];
        if (list.length === 0) return;

        e.preventDefault();
        if (this._historyIndex === -1) {
            this._historyDraft = String(input.value || '');
        }
        if (e.key === 'ArrowUp') {
            if (this._historyIndex < list.length - 1) {
                this._historyIndex += 1;
            }
        } else if (e.key === 'ArrowDown') {
            if (this._historyIndex > -1) {
                this._historyIndex -= 1;
            }
        }

        if (this._historyIndex === -1) {
            input.value = this._historyDraft;
        } else {
            input.value = list[list.length - 1 - this._historyIndex];
        }
        const manualIn = this.el && this.el.querySelector('.fm-terminal-user-cmd');
        if (manualIn && input !== manualIn) {
            manualIn.value = input.value;
            this._persistState();
        }
    }

    _outputLogEl() {
        return this.el && this.el.querySelector('.fm-terminal-output-log');
    }

    _setOutputText(text) {
        const outEl = this._outputLogEl();
        if (!outEl) return;
        outEl.textContent = String(text == null ? '' : text);
        const wrap = this.el && this.el.querySelector('.fm-terminal-output');
        if (wrap) {
            wrap.scrollTop = wrap.scrollHeight;
        }
    }

    _appendOutputBlock(label, code, body) {
        const outEl = this._outputLogEl();
        if (!outEl) return;
        const cur = String(outEl.textContent || '').trim();
        const chunks = [];
        chunks.push('$ ' + String(label || 'command'));
        if (code !== null && code !== undefined) {
            chunks.push('(exit code: ' + Number(code) + ')');
        }
        if (body) {
            chunks.push('');
            chunks.push(String(body));
        }
        const block = chunks.join('\n');
        if (!cur || cur === 'No output yet.' || cur === 'Running...') {
            outEl.textContent = block;
        } else {
            outEl.textContent = cur + '\n\n' + block;
        }
        const wrap = this.el && this.el.querySelector('.fm-terminal-output');
        if (wrap) wrap.scrollTop = wrap.scrollHeight;
    }

    _consoleSuggestionsEl() {
        return this.el && this.el.querySelector('.fm-terminal-console-suggestions');
    }

    _hideConsoleSuggestions() {
        const s = this._consoleSuggestionsEl();
        if (!s) return;
        s.classList.add('fm-hidden');
        s.textContent = '';
    }

    _updateConsoleSuggestions(value, listOverride) {
        const s = this._consoleSuggestionsEl();
        if (!s) return [];
        const top = Array.isArray(listOverride) ? listOverride.slice(0, 12) : [];
        if (top.length === 0) {
            this._hideConsoleSuggestions();
            return [];
        }
        s.textContent = 'Suggestions:\n' + top.join('\n');
        s.classList.remove('fm-hidden');
        return top;
    }

    _inputTokenContext(inputEl) {
        if (!inputEl) return null;
        const text = String(inputEl.value || '');
        const cursor = Math.max(0, Number(inputEl.selectionStart || 0));
        const before = text.slice(0, cursor);
        const after = text.slice(cursor);
        let start = cursor;
        while (start > 0 && !/\s/.test(text[start - 1])) {
            start -= 1;
        }
        const token = text.slice(start, cursor);
        const beforeToken = text.slice(0, start).trim();
        const tokenIndex = beforeToken === '' ? 0 : beforeToken.split(/\s+/).length;
        const firstCmd = text.trim().split(/\s+/)[0] || '';
        return { text, cursor, before, after, start, token, tokenIndex, firstCmd };
    }

    _replaceToken(inputEl, ctx, replacement, appendSpace = false) {
        const rep = String(replacement || '') + (appendSpace ? ' ' : '');
        const next = ctx.text.slice(0, ctx.start) + rep + ctx.after;
        inputEl.value = next;
        const caret = ctx.start + rep.length;
        try { inputEl.setSelectionRange(caret, caret); } catch (e) {}
    }

    _longestCommonPrefix(list) {
        if (!Array.isArray(list) || list.length === 0) return '';
        let prefix = String(list[0] || '');
        for (let i = 1; i < list.length; i++) {
            const cur = String(list[i] || '');
            let j = 0;
            while (j < prefix.length && j < cur.length && prefix[j].toLowerCase() === cur[j].toLowerCase()) j++;
            prefix = prefix.slice(0, j);
            if (!prefix) break;
        }
        return prefix;
    }

    _commandSuggestions(token, firstCmd, tokenIndex) {
        const t = String(token || '').toLowerCase();
        if (tokenIndex === 0) {
            const pool = [...this._terminalLexicon, ...this._history];
            const seen = new Set();
            const out = [];
            pool.forEach((it) => {
                const v = String(it || '').trim();
                if (!v) return;
                const first = v.split(/\s+/)[0];
                const k = first.toLowerCase();
                if (seen.has(k)) return;
                if (t && k.indexOf(t) !== 0) return;
                seen.add(k);
                out.push(first);
            });
            return out.slice(0, 20);
        }
        const cmd = String(firstCmd || '').toLowerCase();
        let pool = [];
        if (cmd === 'git') {
            pool = ['status', 'log', 'branch', 'pull', 'rev-parse', '--short', '--branch', '--oneline', '--rebase', '--show-current', '--is-inside-work-tree'];
        } else if (cmd === 'php') {
            pool = ['-v', '-m', '--ini', '--version'];
        } else if (cmd === 'dir' || cmd === 'ls') {
            pool = ['/a', '/b', '/s', '-la', '-lh', '-a'];
        }
        return pool.filter((x) => String(x).toLowerCase().indexOf(t) === 0).slice(0, 20);
    }

    _inPathRelative() {
        return fmPathInParam(this._currentPath, this._rootDir);
    }

    _fetchPathSuggestions(partialToken) {
        const form = new FormData();
        form.append('action', 'terminal-complete');
        form.append('in', this._inPathRelative());
        form.append('partial', String(partialToken || ''));
        form.append('limit', '40');
        const req = typeof requireAuthFetch === 'function'
            ? requireAuthFetch(this._ajaxUrl, fmPostInit(form))
            : fetch(this._ajaxUrl, fmPostInit(form));
        return req
            .then((r) => r.text().then((t) => ({ ok: r.ok, text: t })))
            .then(({ ok, text }) => {
                if (!ok) return [];
                let data = null;
                try { data = text ? JSON.parse(text) : null; } catch (e) { return []; }
                if (!data || data.status !== 'success' || !Array.isArray(data.matches)) return [];
                return data.matches
                    .map((x) => String(x || '').trim())
                    .filter((x) => x !== '')
                    .slice(0, 40);
            })
            .catch(() => []);
    }

    async _completionCandidates(ctx) {
        const token = String(ctx.token || '');
        const cmdPart = this._commandSuggestions(token, ctx.firstCmd, ctx.tokenIndex);
        const usePath = token.indexOf('/') >= 0 || ctx.tokenIndex > 0;
        const pathPart = usePath ? await this._fetchPathSuggestions(token) : [];
        const seen = new Set();
        const out = [];
        [...cmdPart, ...pathPart].forEach((it) => {
            const v = String(it || '').trim();
            const k = v.toLowerCase();
            if (!v || seen.has(k)) return;
            if (token && k.indexOf(token.toLowerCase()) !== 0) return;
            seen.add(k);
            out.push(v);
        });
        return out.slice(0, 30);
    }

    async _handleConsoleTab(inputEl) {
        if (!inputEl) return;
        const ctx = this._inputTokenContext(inputEl);
        if (!ctx) return;
        if (!ctx.token && ctx.tokenIndex === 0) return;

        const key = [ctx.start, ctx.cursor, ctx.tokenIndex, ctx.token.toLowerCase(), ctx.firstCmd.toLowerCase()].join('|');
        if (this._tabState && this._tabState.key === key && Array.isArray(this._tabState.candidates) && this._tabState.candidates.length > 0) {
            const cands = this._tabState.candidates;
            this._tabState.index = (this._tabState.index + 1) % cands.length;
            const cand = cands[this._tabState.index];
            const space = cands.length === 1 || /\/$/.test(cand) === false;
            this._replaceToken(inputEl, ctx, cand, space);
            this._updateConsoleSuggestions(inputEl.value, cands);
            return;
        }

        const list = await this._completionCandidates(ctx);
        if (!list || list.length === 0) return;
        this._tabState = { key, candidates: list, index: 0 };
        if (list.length === 1) {
            this._replaceToken(inputEl, ctx, list[0], !/\/$/.test(list[0]));
            this._hideConsoleSuggestions();
        } else {
            const lcp = this._longestCommonPrefix(list);
            const t = String(ctx.token || '');
            if (lcp && lcp.length > t.length) {
                this._replaceToken(inputEl, ctx, lcp, false);
            }
            this._updateConsoleSuggestions(inputEl.value, list);
        }
    }
}
class FmCopyMovePopup extends FmPopup {
    static STORAGE_KEY = 'fm_copy_move_popup_state_1';
    constructor(options = {}) {
        const {
            operation,
            currentPath,
            rootDir,
            ajaxUrl,
            names,
            execAvailable,
            onSuccess,
            treeDepth,
            ...rest
        } = options;

        const op     = operation === 'move' ? 'move' : 'copy';
        const isMove = op === 'move';
        let execOk   = true;
        if (typeof execAvailable === 'boolean') {
            execOk = execAvailable;
        } else if (typeof window !== 'undefined' && typeof window.fm_exec_available === 'boolean') {
            execOk = window.fm_exec_available;
        }

        const copyMoveIntro =
            '<p class="fm-hint fm-copy-move-intro">' +
            (execOk
                ? 'The server tries OS copy/move first when possible, then PHP with progress if needed.'
                : 'PHP <code>exec()</code> is disabled; copy/move runs in PHP with item progress.') +
            '</p>';

        const hintBlock = '<div class="fm-option-hint" role="status"><span class="fm-option-hint-text fm-copy-move-hint"></span></div>';
        const cnt       = (names || []).length;
        const label     = cnt === 1 ? '1 item' : (cnt + ' items');

        super({
            title:   isMove ? 'Move to folder' : 'Copy to folder',
            content:
                '<p class="fm-copy-move-selected-text">' + FmPopup.escapeHtml(label) + ' selected. Click a folder or enter a destination path.</p>' +
                '<p class="fm-copy-move-dest-display"><label><span>Destination path</span>' +
                '<input type="text" class="fm-input fm-copy-move-dest-input" autocomplete="off"></label></p>' +
                '<div class="fm-copy-move-tree-panel">' +
                '<div class="fm-copy-move-tree-toolbar">' +
                '<button type="button" class="fm-copy-move-new-folder-btn" title="Create folder inside the selected destination (Ctrl+Alt+D)">' +
                '<i class="bi bi-folder-plus" aria-hidden="true"></i><span>New folder</span></button>' +
                '<span class="fm-copy-move-new-folder-kbd" aria-hidden="true">Ctrl + Alt + D</span>' +
                '</div>' +
                '<div class="fm-copy-move-tree-host">' +
                '<div class="fm-copy-move-tree-loading"><div class="fm-copy-move-tree-spinner"></div><span>Loading folders\u2026</span></div>' +
                '<div class="fm-copy-move-tree-inner"></div>' +
                '</div></div>' +
                copyMoveIntro + hintBlock,
            buttons: [
                { label: 'Cancel', close: true },
                { label: 'Reset', close: false, onClick: () => this._resetState() },
                { label: isMove ? 'Move here' : 'Copy here', primary: true, close: false, onClick: () => this._submit() }
            ],
            onAfterShow() {
                this._bindCopyMoveUi();
            },
            maxWidth: '520px',
            ...rest
        });

        this._operation     = op;
        this._currentPath  = currentPath;
        this._rootDir      = rootDir;
        this._ajaxUrl      = ajaxUrl;
        this._names        = names || [];
        this._onSuccess    = onSuccess || null;
        this._execAvailable = execOk;
        this._treeDepth    = typeof treeDepth === 'number' && treeDepth > 0 ? Math.min(treeDepth, 50) : 12;
        this._destPath    = currentPath || rootDir;
        this._tree         = null;
        this._treeLoadSeq  = 0;
        this._copyMoveOnNodeClick = null;
        this._copyMoveOnLoadMore = null;
        this._copyMoveKbdPath = null;
        this._stateKey = FmCopyMovePopup.STORAGE_KEY + '_' + this._operation;
        this._restoreState();
    }

    _handleKeydown(e) {
        if (!this._isTopVisiblePopup()) {
            return;
        }
        if (e.ctrlKey && e.altKey && !e.shiftKey && e.key.toLowerCase() === 'd') {
            if (!this._copyMoveIsTypingTarget(document.activeElement)) {
                e.preventDefault();
                this._openCopyMoveNewFolderPopup();
            }
            return;
        }
        if (this._handleCopyMoveTreeKeydown(e)) {
            return;
        }
        super._handleKeydown(e);
    }

    _openCopyMoveNewFolderPopup() {
        const parentPath = this._destPath || this._rootDir;
        new FmCreateNewFolderPopup({
            currentPath: parentPath,
            rootDir:     this._rootDir,
            ajaxUrl:     this._ajaxUrl,
            onSuccess:   (name) => {
                try {
                    document.dispatchEvent(new CustomEvent('fm-folder-created', {
                        detail: { parentPath, name },
                    }));
                } catch (err) {}
                this._refreshCopyMoveTree(false);
            },
        }).show();
    }

    _copyMoveIsTypingTarget(active) {
        if (!active || !this.el || !this.el.contains(active)) {
            return false;
        }
        const tag = active.tagName;
        if (tag === 'TEXTAREA' || tag === 'SELECT' || active.isContentEditable) {
            return true;
        }
        if (tag === 'INPUT') {
            const t = (active.type || 'text').toLowerCase();
            return t === 'text' || t === 'search' || t === 'email' || t === 'url' || t === 'password' || t === 'number' || t === 'tel';
        }
        return false;
    }

    _isCopyMoveTreeLinkVisible(link) {
        let n = link.parentElement;
        while (n && !n.classList.contains('fm-copy-move-tree-inner')) {
            if (n.classList && n.classList.contains('fm-tree-children') && n.classList.contains('fm-tree-closed')) {
                return false;
            }
            n = n.parentElement;
        }
        return !!n;
    }

    _getCopyMoveVisibleTreeLinks() {
        const inner = this.querySelector('.fm-copy-move-tree-inner');
        if (!inner || inner.style.display === 'none') {
            return [];
        }
        const out = [];
        inner.querySelectorAll('.fm-tree-link').forEach(a => {
            if (this._isCopyMoveTreeLinkVisible(a)) {
                out.push(a);
            }
        });
        return out;
    }

    _getCopyMoveKbdTreeLink() {
        if (this._copyMoveKbdPath == null) {
            return null;
        }
        const inner = this.querySelector('.fm-copy-move-tree-inner');
        if (!inner) {
            return null;
        }
        const want = norm(this._copyMoveKbdPath);
        let hit = null;
        inner.querySelectorAll('.fm-tree-link').forEach(a => {
            if (norm(a.getAttribute('data-path') || '') === want) {
                hit = a;
            }
        });
        return hit && this._isCopyMoveTreeLinkVisible(hit) ? hit : null;
    }

    _restoreCopyMoveKbdFocus() {
        const inner = this.querySelector('.fm-copy-move-tree-inner');
        if (!inner) {
            return;
        }
        inner.querySelectorAll('.fm-tree-link.fm-copy-move-kbd-focus').forEach(a => a.classList.remove('fm-copy-move-kbd-focus'));
        if (this._copyMoveKbdPath == null) {
            return;
        }
        const want = norm(this._copyMoveKbdPath);
        let hit = null;
        inner.querySelectorAll('.fm-tree-link').forEach(a => {
            if (norm(a.getAttribute('data-path') || '') === want) {
                hit = a;
            }
        });
        if (hit && this._isCopyMoveTreeLinkVisible(hit)) {
            hit.classList.add('fm-copy-move-kbd-focus');
            hit.scrollIntoView({ block: 'nearest', inline: 'nearest' });
        }
    }

    _toggleCopyMoveTreeBranch(item, open) {
        if (!item) {
            return;
        }
        const children = item.nextElementSibling;
        if (!children || !children.classList.contains('fm-tree-children')) {
            return;
        }
        const isClosed = children.classList.contains('fm-tree-closed');
        if ((open && !isClosed) || (!open && isClosed)) {
            return;
        }
        if (open) {
            children.classList.remove('fm-tree-closed');
        } else {
            children.classList.add('fm-tree-closed');
        }
        const icon = item.querySelector('.fm-tree-toggle i.bi');
        if (icon) {
            icon.classList.toggle('bi-chevron-right', !open);
            icon.classList.toggle('bi-chevron-down', open);
        }
    }

    _handleCopyMoveTreeKeydown(e) {
        const keys = ['ArrowDown', 'ArrowUp', 'ArrowLeft', 'ArrowRight'];
        if (!keys.includes(e.key)) {
            return false;
        }
        if (this._copyMoveIsTypingTarget(document.activeElement)) {
            return false;
        }
        if (!this._tree || !this._copyMoveOnNodeClick) {
            return false;
        }

        const links = this._getCopyMoveVisibleTreeLinks();
        if (!links.length) {
            return false;
        }

        const cur = this._getCopyMoveKbdTreeLink();
        let idx = cur ? links.indexOf(cur) : -1;

        if (e.key === 'ArrowDown') {
            e.preventDefault();
            const next = idx < 0 ? 0 : Math.min(links.length - 1, idx + 1);
            this._copyMoveOnNodeClick(links[next].getAttribute('data-path'));
            return true;
        }

        if (e.key === 'ArrowUp') {
            if (idx < 0) {
                return false;
            }
            e.preventDefault();
            if (idx <= 0) {
                this._restoreCopyMoveKbdFocus();
                return true;
            }
            this._copyMoveOnNodeClick(links[idx - 1].getAttribute('data-path'));
            return true;
        }

        if (e.key === 'ArrowRight') {
            if (idx < 0) {
                e.preventDefault();
                this._copyMoveOnNodeClick(links[0].getAttribute('data-path'));
                return true;
            }
            const item = cur.closest('.fm-tree-item');
            const childWrap = item && item.nextElementSibling;
            if (childWrap && childWrap.classList.contains('fm-tree-children')) {
                if (childWrap.classList.contains('fm-tree-closed')) {
                    e.preventDefault();
                    this._toggleCopyMoveTreeBranch(item, true);
                    this._restoreCopyMoveKbdFocus();
                    return true;
                }
                for (let i = idx + 1; i < links.length; i++) {
                    if (childWrap.contains(links[i])) {
                        e.preventDefault();
                        this._copyMoveOnNodeClick(links[i].getAttribute('data-path'));
                        return true;
                    }
                }
            }
            return false;
        }

        if (e.key === 'ArrowLeft') {
            if (idx < 0) {
                return false;
            }
            const item = cur.closest('.fm-tree-item');
            const childWrap = item && item.nextElementSibling;
            if (childWrap && childWrap.classList.contains('fm-tree-children') && !childWrap.classList.contains('fm-tree-closed')) {
                e.preventDefault();
                this._toggleCopyMoveTreeBranch(item, false);
                this._restoreCopyMoveKbdFocus();
                return true;
            }
            const parentCh = item.parentElement;
            if (parentCh && parentCh.classList.contains('fm-tree-children')) {
                const parentItem = parentCh.previousElementSibling;
                const plink = parentItem && parentItem.querySelector('.fm-tree-link');
                if (plink) {
                    e.preventDefault();
                    this._copyMoveOnNodeClick(plink.getAttribute('data-path'));
                    return true;
                }
            }
            return false;
        }

        return false;
    }

    _pathIn() {
        return fmPathInParam(this._currentPath, this._rootDir);
    }

    _destRelativePath() {
        return fmPathInParam(this._destPath || this._rootDir, this._rootDir);
    }

    _bindCopyMoveUi() {
        const hostOuter = this.querySelector('.fm-copy-move-tree-host');
        const innerEl   = this.querySelector('.fm-copy-move-tree-inner');
        const loadingEl = this.querySelector('.fm-copy-move-tree-loading');
        const newFolderBtn = this.querySelector('.fm-copy-move-new-folder-btn');
        const destInput = this.querySelector('.fm-copy-move-dest-input');

        const updateDestLabel = () => {
            if (destInput) destInput.value = this._destPath || this._rootDir;
        };
        updateDestLabel();
        if (destInput) {
            destInput.addEventListener('input', () => {
                this._destPath = destInput.value.trim();
            });
        }

        const hintEl   = this.querySelector('.fm-copy-move-hint');
        const hintWrap = this.querySelector('.fm-option-hint');
        if (hintEl) {
            hintEl.textContent = 'Large folders may show an indeterminate bar during OS work, then file counts if PHP continues the job.';
        }
        if (hintWrap) {
            hintWrap.classList.remove('fm-option-hint--excellent', 'fm-option-hint--good', 'fm-option-hint--medium', 'fm-option-hint--slow', 'fm-option-hint--veryslow');
            hintWrap.classList.add('fm-option-hint--good');
        }

        if (newFolderBtn) {
            newFolderBtn.addEventListener('click', () => this._openCopyMoveNewFolderPopup());
        }

        if (!hostOuter || !innerEl || typeof FmTree === 'undefined') {
            if (loadingEl) loadingEl.innerHTML = '<span>Folder tree unavailable.</span>';
            return;
        }

        const onNodeClick = (path) => {
            this._copyMoveKbdPath = path || this._rootDir;
            this._destPath = path || this._rootDir;
            updateDestLabel();
            this._persistState();
            if (!this._tree) return;
            const exp = this._tree.getExpandedPaths();
            this._tree.render(this._destPath, exp);
            this._restoreCopyMoveKbdFocus();
        };

        const onLoadMore = (path) => {
            let lm = null;
            const el = this._tree && this._tree._el;
            if (el) {
                el.querySelectorAll('.fm-tree-load-more').forEach(a => {
                    if (a.getAttribute('data-path') === path) lm = a;
                });
            }
            requireAuthFetch(this._ajaxUrl + '?' + new URLSearchParams({ action: 'folder-tree-branch', folder: path }))
                .then(r => r.json())
                .then(data => {
                    if (lm) lm.classList.remove('is-loading');
                    if (data.status !== 'success' || !data.tree || !this._tree) return;
                    this._tree.replaceBranch(data.tree);
                    this._tree.render(this._destPath, this._tree.getExpandedPaths());
                    this._restoreCopyMoveKbdFocus();
                })
                .catch(() => { if (lm) lm.classList.remove('is-loading'); });
        };

        this._copyMoveOnNodeClick = onNodeClick;
        this._copyMoveOnLoadMore = onLoadMore;
        this._startCopyMoveTreeLoad(onNodeClick, onLoadMore, loadingEl, innerEl, true);
    }

    _persistState() {
        try {
            localStorage.setItem(this._stateKey, JSON.stringify({
                destPath: this._destPath || this._rootDir,
                kbdPath: this._copyMoveKbdPath || null,
            }));
        } catch (e) {}
    }

    _restoreState() {
        try {
            const raw = localStorage.getItem(this._stateKey);
            if (!raw) return;
            const data = JSON.parse(raw);
            const p = data && typeof data.destPath === 'string' ? data.destPath : '';
            if (p) {
                this._destPath = p;
            }
            if (data && typeof data.kbdPath === 'string') {
                this._copyMoveKbdPath = data.kbdPath;
            }
        } catch (e) {}
    }

    _resetState() {
        try { localStorage.removeItem(this._stateKey); } catch (e) {}
        this._destPath = this._rootDir;
        this._copyMoveKbdPath = null;
        const destInput = this.querySelector('.fm-copy-move-dest-input');
        if (destInput) destInput.value = this._rootDir;
        this._refreshCopyMoveTree(true);
    }

    _startCopyMoveTreeLoad(onNodeClick, onLoadMore, loadingEl, innerEl, showLoading, reportFetchError = true) {
        const seq = ++this._treeLoadSeq;
        const fullDepth = this._treeDepth;

        const showTreeUi = () => {
            if (loadingEl) loadingEl.style.display = 'none';
            innerEl.style.display = '';
        };

        const showError = () => {
            if (seq !== this._treeLoadSeq) return;
            if (loadingEl) {
                loadingEl.style.display = '';
                loadingEl.innerHTML = '<span class="fm-copy-move-tree-error">Could not load folders.</span>';
            }
            innerEl.style.display = 'none';
        };

        if (showLoading && loadingEl) {
            loadingEl.style.display = '';
            loadingEl.innerHTML = '<div class="fm-copy-move-tree-spinner"></div><span>Loading folders\u2026</span>';
        }

        requireAuthFetch(this._ajaxUrl + '?' + new URLSearchParams({ action: 'folder-tree', depth: '2' }))
            .then(r => r.json())
            .then(shallow => {
                if (seq !== this._treeLoadSeq || !this.el) return;
                showTreeUi();
                if (!this._tree) {
                    this._tree = new FmTree({
                        el: innerEl,
                        onNodeClick,
                        onLoadMore,
                        autoExpandAncestors: false,
                    });
                }
                const expanded = this._tree.data ? this._tree.getExpandedPaths() : new Set();
                if (!this._tree.data) {
                    expanded.add(norm(this._rootDir));
                }
                this._tree.setData(shallow, this._destPath, expanded);
                this._restoreCopyMoveKbdFocus();

                return requireAuthFetch(this._ajaxUrl + '?' + new URLSearchParams({ action: 'folder-tree', depth: String(fullDepth) }))
                    .then(r2 => r2.json())
                    .then(full => {
                        if (seq !== this._treeLoadSeq || !this._tree || !this.el) return;
                        const exp = this._tree.getExpandedPaths();
                        this._tree.setData(full, this._destPath, exp);
                        this._restoreCopyMoveKbdFocus();
                    })
                    .catch(() => {});
            })
            .catch(() => {
                if (reportFetchError) showError();
            });
    }

    _refreshCopyMoveTree(showFullLoading) {
        const loadingEl = this.querySelector('.fm-copy-move-tree-loading');
        const innerEl   = this.querySelector('.fm-copy-move-tree-inner');
        if (!innerEl || typeof FmTree === 'undefined') return;
        if (!this._copyMoveOnNodeClick || !this._copyMoveOnLoadMore) return;

        if (showFullLoading && loadingEl) {
            loadingEl.style.display = '';
            loadingEl.innerHTML = '<div class="fm-copy-move-tree-spinner"></div><span>Loading folders\u2026</span>';
            innerEl.style.display = 'none';
        }

        this._startCopyMoveTreeLoad(
            this._copyMoveOnNodeClick,
            this._copyMoveOnLoadMore,
            loadingEl,
            innerEl,
            false,
            !!showFullLoading
        );
    }

    _submit() {
        const destInput = this.querySelector('.fm-copy-move-dest-input');
        if (destInput && destInput.value.trim()) {
            this._destPath = destInput.value.trim();
        }
        const destRel = this._destRelativePath();
        const destAbs = this._destPath || this._rootDir;
        const srcAbs  = this._currentPath;

        if (norm(destAbs) === norm(srcAbs)) {
            fmUserNotice({ variant: 'warning', title: this._operation === 'move' ? 'Move' : 'Copy', message: 'Pick a different folder (same folder would overwrite names).' });
            return;
        }

        this.hideAndDestroy();

        const pathIn = this._pathIn();
        const useIndeterminateFirst = this._execAvailable;

        if (!useIndeterminateFirst) {
            const loadPopup = new FmPopup({
                title:   this._operation === 'move' ? 'Move' : 'Copy',
                content: '<p>Counting items\u2026</p>',
                buttons: [], closeOnBackdrop: false, closeOnEscape: false, maxWidth: '460px'
            });
            loadPopup.show();
            const countForm = new FormData();
            countForm.append('action', 'get-files-and-folders-count');
            countForm.append('in', pathIn);
            this._names.forEach(n => countForm.append('names[]', n));
            fetch(this._ajaxUrl, fmPostInit(countForm))
                .then(r => r.json())
                .then(countData => {
                    loadPopup.hideAndDestroy();
                    const total = (countData.status === 'success' && typeof countData.total === 'number')
                        ? countData.total
                        : this._names.length;
                    this._runCopyMoveStream(pathIn, destRel, total, false);
                })
                .catch(() => {
                    loadPopup.hideAndDestroy();
                    this._runCopyMoveStream(pathIn, destRel, this._names.length, false);
                });
        } else {
            this._runCopyMoveStream(pathIn, destRel, 0, true);
        }
    }

    _runCopyMoveStream(pathIn, destRel, totalHint, startIndeterminate) {
        const verb = this._operation === 'move' ? 'Move' : 'Copy';
        const progressPopup = new FmPopup({
            title: verb,
            content:
                '<div class="fm-copy-move-progress">' +
                '<p class="fm-progress-label fm-copy-move-progress-label">Working\u2026</p>' +
                '<p class="fm-copy-move-phase fm-hint"></p>' +
                '<p class="fm-copy-move-count-wrap fm-hint">' +
                '<span class="fm-copy-move-count">0</span> / <span class="fm-copy-move-total">0</span></p>' +
                '<p class="fm-copy-move-name" title=""></p>' +
                '<div class="fm-progress-wrap fm-copy-move-progress-wrap"><div class="fm-progress-bar fm-copy-move-progress-bar"></div></div>' +
                '</div>',
            buttons: [],
            closeOnBackdrop: false,
            closeOnEscape:   false,
            maxWidth:        '460px'
        });
        progressPopup.show();

        const labelEl   = progressPopup.querySelector('.fm-copy-move-progress-label');
        const phaseEl   = progressPopup.querySelector('.fm-copy-move-phase');
        const countWrap = progressPopup.querySelector('.fm-copy-move-count-wrap');
        const countEl   = progressPopup.querySelector('.fm-copy-move-count');
        const totalEl   = progressPopup.querySelector('.fm-copy-move-total');
        const nameEl    = progressPopup.querySelector('.fm-copy-move-name');
        const barEl     = progressPopup.querySelector('.fm-copy-move-progress-bar');
        const barWrap   = progressPopup.querySelector('.fm-copy-move-progress-wrap');

        let total  = totalHint > 0 ? totalHint : 0;
        let indet  = !!startIndeterminate;
        let failed = false;
        let verboseItems = fmShouldShowVerboseItemProgress(null, totalHint);

        const applyIndeterminateUi = (msg) => {
            indet = true;
            if (labelEl) labelEl.textContent = verb + ' (OS)\u2026';
            if (phaseEl) phaseEl.textContent = msg || 'Please wait (this can take a while).';
            if (countWrap) countWrap.style.display = 'none';
            if (barWrap) barWrap.classList.add('fm-progress-indeterminate');
            if (barEl) barEl.style.width = '';
        };

        const applyDeterminateUi = (t) => {
            indet = false;
            if (barWrap) barWrap.classList.remove('fm-progress-indeterminate');
            total = t > 0 ? t : total;
            if (labelEl) labelEl.textContent = verb + '\u2026';
            if (phaseEl) phaseEl.textContent = '';
            if (countWrap) countWrap.style.display = verboseItems ? '' : 'none';
            if (totalEl) totalEl.textContent = String(total || 0);
            if (countEl) countEl.textContent = '0';
            if (barEl) barEl.style.width = '0%';
            if (!verboseItems && barWrap) barWrap.classList.add('fm-progress-indeterminate');
            if (nameEl) nameEl.style.display = verboseItems ? '' : 'none';
        };

        if (startIndeterminate) applyIndeterminateUi('');
        else applyDeterminateUi(totalHint);

        const form = new FormData();
        form.append('action', 'copy-move-stream');
        form.append('operation', this._operation);
        form.append('in', pathIn);
        form.append('dest', destRel);
        this._names.forEach(n => form.append('names[]', n));

        fetch(this._ajaxUrl, fmPostInit(form))
            .then(response => {
                if (!response.ok || !response.body) {
                    progressPopup.hideAndDestroy();
                    fmUserNotice({ title: verb, message: verb + ' failed.' });
                    return;
                }
                const reader  = response.body.getReader();
                const decoder = new TextDecoder();
                let buffer    = '';

                const processLine = (line) => {
                    line = String(line || '').trim();
                    if (!line) return;
                    let data;
                    try { data = JSON.parse(line); } catch (e) { return; }
                    if (data && Object.prototype.hasOwnProperty.call(data, 'verbose_progress')) {
                        verboseItems = !!data.verbose_progress;
                        if (!indet) {
                            if (countWrap) countWrap.style.display = verboseItems ? '' : 'none';
                            if (nameEl) nameEl.style.display = verboseItems ? '' : 'none';
                            if (barWrap) {
                                if (verboseItems) barWrap.classList.remove('fm-progress-indeterminate');
                                else barWrap.classList.add('fm-progress-indeterminate');
                            }
                        }
                    }
                    if (data.error) {
                        failed = true;
                        progressPopup.hideAndDestroy();
                        fmUserNotice({ title: verb, message: data.error });
                        return;
                    }
                    if (data.done) {
                        if (!failed) {
                            progressPopup.hideAndDestroy();
                            if (this._onSuccess) this._onSuccess();
                        }
                        return;
                    }
                    if (data.indeterminate) {
                        applyIndeterminateUi(data.msg || '');
                        return;
                    }
                    if (data.fallback === 'php' && typeof data.total === 'number') {
                        applyDeterminateUi(data.total);
                        return;
                    }
                    if (typeof data.total === 'number' && data.total > 0 && indet) {
                        applyDeterminateUi(data.total);
                    }
                    if (typeof data.n === 'number' && countEl && barEl && !indet) {
                        const t = typeof data.total === 'number' && data.total > 0 ? data.total : total;
                        if (t > 0) total = t;
                        if (totalEl) totalEl.textContent = String(total);
                        if (verboseItems) {
                            if (countWrap) countWrap.style.display = '';
                            if (barWrap) barWrap.classList.remove('fm-progress-indeterminate');
                            if (nameEl) nameEl.style.display = '';
                            countEl.textContent = String(data.n);
                            if (nameEl) {
                                nameEl.textContent = shortenMiddle(data.name || '', 48);
                                nameEl.title = data.name || '';
                            }
                            barEl.style.width = (total > 0 ? Math.min(100, (data.n / total) * 100) : 0) + '%';
                        }
                    }
                };

                const readChunk = () => {
                    reader.read().then(result => {
                        if (result.value) buffer += decoder.decode(result.value, { stream: !result.done });
                        const lines = buffer.split('\n');
                        buffer = lines.pop() || '';
                        lines.forEach(processLine);
                        if (result.done) {
                            if (buffer) processLine(buffer);
                            return;
                        }
                        if (!failed) readChunk();
                    }).catch(() => {
                        if (!failed) {
                            failed = true;
                            progressPopup.hideAndDestroy();
                            fmUserNotice({ title: verb, message: verb + ' failed.' });
                        }
                    });
                };
                readChunk();
            })
            .catch(() => {
                progressPopup.hideAndDestroy();
                fmUserNotice({ title: verb, message: verb + ' failed.' });
            });
    }

    _runDuplicateStream(pathIn, totalHint, startIndeterminate) {
        const verb = 'Duplicate';
        const progressPopup = new FmPopup({
            title: verb,
            content:
                '<div class="fm-copy-move-progress">' +
                '<p class="fm-progress-label fm-copy-move-progress-label">Working\u2026</p>' +
                '<p class="fm-copy-move-phase fm-hint"></p>' +
                '<p class="fm-copy-move-count-wrap fm-hint">' +
                '<span class="fm-copy-move-count">0</span> / <span class="fm-copy-move-total">0</span></p>' +
                '<p class="fm-copy-move-name" title=""></p>' +
                '<div class="fm-progress-wrap fm-copy-move-progress-wrap"><div class="fm-progress-bar fm-copy-move-progress-bar"></div></div>' +
                '</div>',
            buttons: [],
            closeOnBackdrop: false,
            closeOnEscape:   false,
            maxWidth:        '460px'
        });
        progressPopup.show();

        const labelEl   = progressPopup.querySelector('.fm-copy-move-progress-label');
        const phaseEl   = progressPopup.querySelector('.fm-copy-move-phase');
        const countWrap = progressPopup.querySelector('.fm-copy-move-count-wrap');
        const countEl   = progressPopup.querySelector('.fm-copy-move-count');
        const totalEl   = progressPopup.querySelector('.fm-copy-move-total');
        const nameEl    = progressPopup.querySelector('.fm-copy-move-name');
        const barEl     = progressPopup.querySelector('.fm-copy-move-progress-bar');
        const barWrap   = progressPopup.querySelector('.fm-copy-move-progress-wrap');

        let total  = totalHint > 0 ? totalHint : 0;
        let indet  = !!startIndeterminate;
        let failed = false;
        let verboseItems = fmShouldShowVerboseItemProgress(null, totalHint);

        const applyIndeterminateUi = (msg) => {
            indet = true;
            if (labelEl) labelEl.textContent = verb + ' (OS)\u2026';
            if (phaseEl) phaseEl.textContent = msg || 'Please wait (this can take a while).';
            if (countWrap) countWrap.style.display = 'none';
            if (barWrap) barWrap.classList.add('fm-progress-indeterminate');
            if (barEl) barEl.style.width = '';
        };

        const applyDeterminateUi = (t) => {
            indet = false;
            if (barWrap) barWrap.classList.remove('fm-progress-indeterminate');
            total = t > 0 ? t : total;
            if (labelEl) labelEl.textContent = verb + '\u2026';
            if (phaseEl) phaseEl.textContent = '';
            if (countWrap) countWrap.style.display = verboseItems ? '' : 'none';
            if (totalEl) totalEl.textContent = String(total || 0);
            if (countEl) countEl.textContent = '0';
            if (barEl) barEl.style.width = '0%';
            if (!verboseItems && barWrap) barWrap.classList.add('fm-progress-indeterminate');
            if (nameEl) nameEl.style.display = verboseItems ? '' : 'none';
        };

        if (startIndeterminate) applyIndeterminateUi('');
        else applyDeterminateUi(totalHint);

        const form = new FormData();
        form.append('action', 'duplicate-stream');
        form.append('in', pathIn);
        this._names.forEach(n => form.append('names[]', n));

        fetch(this._ajaxUrl, fmPostInit(form))
            .then(response => {
                if (!response.ok || !response.body) {
                    progressPopup.hideAndDestroy();
                    fmUserNotice({ title: verb, message: verb + ' failed.' });
                    return;
                }
                const reader  = response.body.getReader();
                const decoder = new TextDecoder();
                let buffer    = '';

                const processLine = (line) => {
                    line = String(line || '').trim();
                    if (!line) return;
                    let data;
                    try { data = JSON.parse(line); } catch (e) { return; }
                    if (data && Object.prototype.hasOwnProperty.call(data, 'verbose_progress')) {
                        verboseItems = !!data.verbose_progress;
                        if (!indet) {
                            if (countWrap) countWrap.style.display = verboseItems ? '' : 'none';
                            if (nameEl) nameEl.style.display = verboseItems ? '' : 'none';
                            if (barWrap) {
                                if (verboseItems) barWrap.classList.remove('fm-progress-indeterminate');
                                else barWrap.classList.add('fm-progress-indeterminate');
                            }
                        }
                    }
                    if (data.error) {
                        failed = true;
                        progressPopup.hideAndDestroy();
                        fmUserNotice({ title: verb, message: data.error });
                        return;
                    }
                    if (data.done) {
                        if (!failed) {
                            progressPopup.hideAndDestroy();
                            if (this._onSuccess) this._onSuccess();
                        }
                        return;
                    }
                    if (data.indeterminate) {
                        applyIndeterminateUi(data.msg || '');
                        return;
                    }
                    if (data.fallback === 'php' && typeof data.total === 'number') {
                        applyDeterminateUi(data.total);
                        return;
                    }
                    if (typeof data.total === 'number' && data.total > 0 && indet) {
                        applyDeterminateUi(data.total);
                    }
                    if (typeof data.n === 'number' && countEl && barEl && !indet) {
                        const t = typeof data.total === 'number' && data.total > 0 ? data.total : total;
                        if (t > 0) total = t;
                        if (totalEl) totalEl.textContent = String(total);
                        if (verboseItems) {
                            if (countWrap) countWrap.style.display = '';
                            if (barWrap) barWrap.classList.remove('fm-progress-indeterminate');
                            if (nameEl) nameEl.style.display = '';
                            countEl.textContent = String(data.n);
                            if (nameEl) {
                                nameEl.textContent = shortenMiddle(data.name || '', 48);
                                nameEl.title = data.name || '';
                            }
                            barEl.style.width = (total > 0 ? Math.min(100, (data.n / total) * 100) : 0) + '%';
                        }
                    }
                };

                const readChunk = () => {
                    reader.read().then(result => {
                        if (result.value) buffer += decoder.decode(result.value, { stream: !result.done });
                        const lines = buffer.split('\n');
                        buffer = lines.pop() || '';
                        lines.forEach(processLine);
                        if (result.done) {
                            if (buffer) processLine(buffer);
                            return;
                        }
                        if (!failed) readChunk();
                    }).catch(() => {
                        if (!failed) {
                            failed = true;
                            progressPopup.hideAndDestroy();
                            fmUserNotice({ title: verb, message: verb + ' failed.' });
                        }
                    });
                };
                readChunk();
            })
            .catch(() => {
                progressPopup.hideAndDestroy();
                fmUserNotice({ title: verb, message: verb + ' failed.' });
            });
    }

    _runNewFolderFromSelectionStream(pathIn, folderName, totalHint, startIndeterminate) {
        const verb = 'Move';
        const progressPopup = new FmPopup({
            title: verb,
            content:
                '<div class="fm-copy-move-progress">' +
                '<p class="fm-progress-label fm-copy-move-progress-label">Working\u2026</p>' +
                '<p class="fm-copy-move-phase fm-hint"></p>' +
                '<p class="fm-copy-move-count-wrap fm-hint">' +
                '<span class="fm-copy-move-count">0</span> / <span class="fm-copy-move-total">0</span></p>' +
                '<p class="fm-copy-move-name" title=""></p>' +
                '<div class="fm-progress-wrap fm-copy-move-progress-wrap"><div class="fm-progress-bar fm-copy-move-progress-bar"></div></div>' +
                '</div>',
            buttons: [],
            closeOnBackdrop: false,
            closeOnEscape:   false,
            maxWidth:        '460px',
        });
        progressPopup.show();

        const labelEl   = progressPopup.querySelector('.fm-copy-move-progress-label');
        const phaseEl   = progressPopup.querySelector('.fm-copy-move-phase');
        const countWrap = progressPopup.querySelector('.fm-copy-move-count-wrap');
        const countEl   = progressPopup.querySelector('.fm-copy-move-count');
        const totalEl   = progressPopup.querySelector('.fm-copy-move-total');
        const nameEl    = progressPopup.querySelector('.fm-copy-move-name');
        const barEl     = progressPopup.querySelector('.fm-copy-move-progress-bar');
        const barWrap   = progressPopup.querySelector('.fm-copy-move-progress-wrap');

        let total  = totalHint > 0 ? totalHint : 0;
        let indet  = !!startIndeterminate;
        let failed = false;
        let verboseItems = fmShouldShowVerboseItemProgress(null, totalHint);

        const applyIndeterminateUi = (msg) => {
            indet = true;
            if (labelEl) labelEl.textContent = verb + ' (OS)\u2026';
            if (phaseEl) phaseEl.textContent = msg || 'Please wait (this can take a while).';
            if (countWrap) countWrap.style.display = 'none';
            if (barWrap) barWrap.classList.add('fm-progress-indeterminate');
            if (barEl) barEl.style.width = '';
        };

        const applyDeterminateUi = (t) => {
            indet = false;
            if (barWrap) barWrap.classList.remove('fm-progress-indeterminate');
            total = t > 0 ? t : total;
            if (labelEl) labelEl.textContent = verb + '\u2026';
            if (phaseEl) phaseEl.textContent = '';
            if (countWrap) countWrap.style.display = verboseItems ? '' : 'none';
            if (totalEl) totalEl.textContent = String(total || 0);
            if (countEl) countEl.textContent = '0';
            if (barEl) barEl.style.width = '0%';
            if (!verboseItems && barWrap) barWrap.classList.add('fm-progress-indeterminate');
            if (nameEl) nameEl.style.display = verboseItems ? '' : 'none';
        };

        if (startIndeterminate) applyIndeterminateUi('');
        else applyDeterminateUi(totalHint);

        const form = new FormData();
        form.append('action', 'new-folder-from-selection-stream');
        form.append('in', pathIn);
        form.append('name', folderName);
        this._names.forEach((n) => form.append('names[]', n));

        fetch(this._ajaxUrl, fmPostInit(form))
            .then((response) => {
                if (!response.ok || !response.body) {
                    progressPopup.hideAndDestroy();
                    fmUserNotice({ title: 'New folder from selection', message: 'Could not complete the operation.' });
                    return;
                }
                const reader  = response.body.getReader();
                const decoder = new TextDecoder();
                let buffer    = '';

                const processLine = (line) => {
                    line = String(line || '').trim();
                    if (!line) return;
                    let data;
                    try { data = JSON.parse(line); } catch (e) { return; }
                    if (data && Object.prototype.hasOwnProperty.call(data, 'verbose_progress')) {
                        verboseItems = !!data.verbose_progress;
                        if (!indet) {
                            if (countWrap) countWrap.style.display = verboseItems ? '' : 'none';
                            if (nameEl) nameEl.style.display = verboseItems ? '' : 'none';
                            if (barWrap) {
                                if (verboseItems) barWrap.classList.remove('fm-progress-indeterminate');
                                else barWrap.classList.add('fm-progress-indeterminate');
                            }
                        }
                    }
                    if (data.error) {
                        failed = true;
                        progressPopup.hideAndDestroy();
                        fmUserNotice({ title: 'New folder from selection', message: data.error });
                        return;
                    }
                    if (data.done) {
                        if (!failed) {
                            progressPopup.hideAndDestroy();
                            if (this._onSuccess) this._onSuccess();
                        }
                        return;
                    }
                    if (data.indeterminate) {
                        applyIndeterminateUi(data.msg || '');
                        return;
                    }
                    if (data.fallback === 'php' && typeof data.total === 'number') {
                        applyDeterminateUi(data.total);
                        return;
                    }
                    if (typeof data.total === 'number' && data.total > 0 && indet) {
                        applyDeterminateUi(data.total);
                    }
                    if (typeof data.n === 'number' && countEl && barEl && !indet) {
                        const t = typeof data.total === 'number' && data.total > 0 ? data.total : total;
                        if (t > 0) total = t;
                        if (totalEl) totalEl.textContent = String(total);
                        if (verboseItems) {
                            if (countWrap) countWrap.style.display = '';
                            if (barWrap) barWrap.classList.remove('fm-progress-indeterminate');
                            if (nameEl) nameEl.style.display = '';
                            countEl.textContent = String(data.n);
                            if (nameEl) {
                                nameEl.textContent = shortenMiddle(data.name || '', 48);
                                nameEl.title = data.name || '';
                            }
                            barEl.style.width = (total > 0 ? Math.min(100, (data.n / total) * 100) : 0) + '%';
                        }
                    }
                };

                const readChunk = () => {
                    reader.read().then((result) => {
                        if (result.value) buffer += decoder.decode(result.value, { stream: !result.done });
                        const lines = buffer.split('\n');
                        buffer = lines.pop() || '';
                        lines.forEach(processLine);
                        if (result.done) {
                            if (buffer) processLine(buffer);
                            return;
                        }
                        if (!failed) readChunk();
                    }).catch(() => {
                        if (!failed) {
                            failed = true;
                            progressPopup.hideAndDestroy();
                            fmUserNotice({ title: 'New folder from selection', message: 'Operation failed.' });
                        }
                    });
                };
                readChunk();
            })
            .catch(() => {
                progressPopup.hideAndDestroy();
                fmUserNotice({ title: 'New folder from selection', message: 'Operation failed.' });
            });
    }

    static runNewFolderFromSelectionDirect(options = {}) {
        const {
            rootDir,
            ajaxUrl,
            sourcePath,
            names,
            folderName,
            execAvailable,
            onSuccess,
        } = options;

        if (!names || names.length === 0 || !ajaxUrl || !folderName) return;

        const pathIn = fmPathInParam(sourcePath, rootDir);

        let execOk = true;
        if (typeof execAvailable === 'boolean') {
            execOk = execAvailable;
        } else if (typeof window !== 'undefined' && typeof window.fm_exec_available === 'boolean') {
            execOk = window.fm_exec_available;
        }

        const ctx = {
            _ajaxUrl:       ajaxUrl,
            _names:         names,
            _onSuccess:     onSuccess || null,
            _execAvailable: execOk,
        };

        const useIndeterminateFirst = execOk;

        if (!useIndeterminateFirst) {
            const loadPopup = new FmPopup({
                title:   'New folder from selection',
                content: '<p>Counting items\u2026</p>',
                buttons: [], closeOnBackdrop: false, closeOnEscape: false, maxWidth: '460px',
            });
            loadPopup.show();
            const countForm = new FormData();
            countForm.append('action', 'get-files-and-folders-count');
            countForm.append('in', pathIn);
            names.forEach((n) => countForm.append('names[]', n));
            fetch(ajaxUrl, fmPostInit(countForm))
                .then((r) => r.json())
                .then((countData) => {
                    loadPopup.hideAndDestroy();
                    const total = (countData.status === 'success' && typeof countData.total === 'number')
                        ? countData.total
                        : names.length;
                    FmCopyMovePopup.prototype._runNewFolderFromSelectionStream.call(
                        ctx, pathIn, folderName, total, false
                    );
                })
                .catch(() => {
                    loadPopup.hideAndDestroy();
                    FmCopyMovePopup.prototype._runNewFolderFromSelectionStream.call(
                        ctx, pathIn, folderName, names.length, false
                    );
                });
        } else {
            FmCopyMovePopup.prototype._runNewFolderFromSelectionStream.call(
                ctx, pathIn, folderName, 0, true
            );
        }
    }

    static runDirect(options = {}) {
        const {
            operation,
            rootDir,
            ajaxUrl,
            sourcePath,
            destAbs,
            names,
            execAvailable,
            onSuccess,
            sourceRowsByName,
        } = options;

        const op = operation === 'move' ? 'move' : 'copy';
        const srcAbs = norm(String(sourcePath || ''));
        const dstAbs = norm(String(destAbs || ''));

        if (!names || names.length === 0 || !ajaxUrl) return;

        if (srcAbs === dstAbs) {
            fmUserNotice({
                variant: 'warning',
                title:   op === 'move' ? 'Move' : 'Copy',
                message: 'Pick a different folder (same folder would overwrite names).',
            });
            return;
        }

        if (op === 'move' && sourceRowsByName) {
            for (let i = 0; i < names.length; i++) {
                const n = names[i];
                const row = sourceRowsByName[n];
                if (row && row.type === 'folder') {
                    const folderFull = norm(fmJoinPath(srcAbs, n));
                    if (dstAbs === folderFull || dstAbs.startsWith(folderFull + '/')) {
                        fmUserNotice({
                            variant: 'warning',
                            title:   'Move',
                            message: 'Cannot move a folder into itself or a subfolder.',
                        });
                        return;
                    }
                }
            }
        }

        const pathIn = fmPathInParam(sourcePath, rootDir);
        const destRel = fmPathInParam(destAbs, rootDir);

        let execOk = true;
        if (typeof execAvailable === 'boolean') {
            execOk = execAvailable;
        } else if (typeof window !== 'undefined' && typeof window.fm_exec_available === 'boolean') {
            execOk = window.fm_exec_available;
        }

        const ctx = {
            _ajaxUrl:       ajaxUrl,
            _operation:     op,
            _names:         names,
            _onSuccess:     onSuccess || null,
            _execAvailable: execOk,
        };

        const useIndeterminateFirst = execOk;

        if (!useIndeterminateFirst) {
            const loadPopup = new FmPopup({
                title:   op === 'move' ? 'Move' : 'Copy',
                content: '<p>Counting items\u2026</p>',
                buttons: [], closeOnBackdrop: false, closeOnEscape: false, maxWidth: '460px'
            });
            loadPopup.show();
            const countForm = new FormData();
            countForm.append('action', 'get-files-and-folders-count');
            countForm.append('in', pathIn);
            names.forEach(n => countForm.append('names[]', n));
            fetch(ajaxUrl, fmPostInit(countForm))
                .then(r => r.json())
                .then(countData => {
                    loadPopup.hideAndDestroy();
                    const total = (countData.status === 'success' && typeof countData.total === 'number')
                        ? countData.total
                        : names.length;
                    FmCopyMovePopup.prototype._runCopyMoveStream.call(ctx, pathIn, destRel, total, false);
                })
                .catch(() => {
                    loadPopup.hideAndDestroy();
                    FmCopyMovePopup.prototype._runCopyMoveStream.call(ctx, pathIn, destRel, names.length, false);
                });
        } else {
            FmCopyMovePopup.prototype._runCopyMoveStream.call(ctx, pathIn, destRel, 0, true);
        }
    }

    static runDuplicateDirect(options = {}) {
        const {
            rootDir,
            ajaxUrl,
            sourcePath,
            names,
            execAvailable,
            onSuccess,
        } = options;

        if (!names || names.length === 0 || !ajaxUrl) return;

        const pathIn = fmPathInParam(sourcePath, rootDir);

        let execOk = true;
        if (typeof execAvailable === 'boolean') {
            execOk = execAvailable;
        } else if (typeof window !== 'undefined' && typeof window.fm_exec_available === 'boolean') {
            execOk = window.fm_exec_available;
        }

        const ctx = {
            _ajaxUrl:       ajaxUrl,
            _names:         names,
            _onSuccess:     onSuccess || null,
            _execAvailable: execOk,
        };

        const useIndeterminateFirst = execOk;

        if (!useIndeterminateFirst) {
            const loadPopup = new FmPopup({
                title:   'Duplicate',
                content: '<p>Counting items\u2026</p>',
                buttons: [], closeOnBackdrop: false, closeOnEscape: false, maxWidth: '460px'
            });
            loadPopup.show();
            const countForm = new FormData();
            countForm.append('action', 'get-files-and-folders-count');
            countForm.append('in', pathIn);
            names.forEach(n => countForm.append('names[]', n));
            fetch(ajaxUrl, fmPostInit(countForm))
                .then(r => r.json())
                .then(countData => {
                    loadPopup.hideAndDestroy();
                    const total = (countData.status === 'success' && typeof countData.total === 'number')
                        ? countData.total
                        : names.length;
                    FmCopyMovePopup.prototype._runDuplicateStream.call(ctx, pathIn, total, false);
                })
                .catch(() => {
                    loadPopup.hideAndDestroy();
                    FmCopyMovePopup.prototype._runDuplicateStream.call(ctx, pathIn, names.length, false);
                });
        } else {
            FmCopyMovePopup.prototype._runDuplicateStream.call(ctx, pathIn, 0, true);
        }
    }
}

class FmDownloadArchivePopup extends FmPopup {
    constructor(options = {}) {
        const {
            currentPath,
            rootDir,
            ajaxUrl,
            names,
            defaultArchiveName,
            execAvailable,
            onConfirm,
            ...rest
        } = options;

        let execOk = true;
        if (typeof execAvailable === 'boolean') {
            execOk = execAvailable;
        } else if (typeof window !== 'undefined' && typeof window.fm_exec_available === 'boolean') {
            execOk = window.fm_exec_available;
        }

        const stamp = new Date();
        const pad = (n) => String(n).padStart(2, '0');
        const autoDefault =
            'download-' + stamp.getFullYear() + '-' + pad(stamp.getMonth() + 1) + '-' + pad(stamp.getDate()) + '-' +
            pad(stamp.getHours()) + pad(stamp.getMinutes()) + pad(stamp.getSeconds()) + '.zip';
        const initialName = defaultArchiveName || autoDefault;

        const hint = 'The download starts right away; the archive is built while it downloads. ' + (execOk
            ? 'The server uses OS archive tools when possible, otherwise PHP (see file ops mode on the server).'
            : 'PHP exec() is disabled; archives are built with PHP.');

        super({
            title:   'Download as archive',
            submitOnEnter: true,
            content:
                '<p class="mt-0"><label><span class="fm-download-archive-name-label">Archive name</span>' +
                '<input type="text" class="fm-download-archive-name fm-input-focus-on-open" autocomplete="off" value="' +
                FmPopup.escapeAttr(initialName) + '"></label></p>' +
                '<p class="fm-download-archive-type-row"><span class="fm-download-archive-type-label">Format:</span> ' +
                '<label class="fm-option-label"><input type="radio" name="fm-download-archive-type" class="fm-download-archive-type" value="zip" checked> ZIP</label> ' +
                '<label class="fm-option-label"><input type="radio" name="fm-download-archive-type" class="fm-download-archive-type" value="tar"> TAR</label> ' +
                '<label class="fm-option-label"><input type="radio" name="fm-download-archive-type" class="fm-download-archive-type" value="gzip"> GZIP</label></p>' +
                '<p class="mb-0 fm-hint">' +
                FmPopup.escapeHtml(hint) +
                '</p>',
            buttons: [
                { label: 'Cancel', close: true },
                { label: 'Download', primary: true, close: false, onClick: () => this._submitDownloadArchive() },
            ],
            onAfterShow() {
                this._bindDownloadArchiveForm();
            },
            ...rest,
        });

        this._currentPath = currentPath;
        this._rootDir     = rootDir;
        this._ajaxUrl     = ajaxUrl;
        this._names       = names || [];
        this._onConfirm   = onConfirm || null;
        this._extByType   = { zip: '.zip', tar: '.tar', gzip: '.tar.gz' };
    }

    _bindDownloadArchiveForm() {
        const input  = this.querySelector('.fm-download-archive-name');
        const radios = this.querySelectorAll('.fm-download-archive-type');

        const updateExt = () => {
            if (!input) return;
            const checked = this.querySelector('.fm-download-archive-type:checked');
            const type    = (checked && checked.value) || 'zip';
            const ext     = this._extByType[type] || '.zip';
            let val = (input.value || '').replace(/\.(zip|tar|tar\.gz)$/i, '');
            if (val === '') val = (input.value || '').trim();
            input.value = val + ext;
            const selLen = val.length;
            input.setSelectionRange(0, selLen);
        };

        radios.forEach(radio => radio.addEventListener('change', updateExt));
        if (input) {
            const val = input.value || '';
            const nameOnly = val.replace(/\.(zip|tar|tar\.gz)$/i, '');
            const setSel = () => input.setSelectionRange(0, nameOnly.length);
            setSel();
            setTimeout(setSel, 0);
        }
    }

    _submitDownloadArchive() {
        const input      = this.querySelector('.fm-download-archive-name');
        const typeRadio  = this.querySelector('.fm-download-archive-type:checked');
        const raw        = (input && input.value || '').trim();
        const type       = (typeRadio && typeRadio.value) || 'zip';
        const ext        = this._extByType[type] || '.zip';

        if (!raw) {
            fmUserNotice({ variant: 'warning', title: 'Download', message: 'Enter an archive name.' });
            return;
        }
        if (/[\\/:*?"<>|]/.test(raw)) {
            fmUserNotice({ variant: 'warning', title: 'Download', message: 'Name cannot contain \\ / : * ? " < > |' });
            return;
        }
        const prefix = raw.replace(/\.(zip|tar|tar\.gz)$/i, '');
        if (!prefix) {
            fmUserNotice({ variant: 'warning', title: 'Download', message: 'Enter a valid archive name.' });
            return;
        }
        const archiveName = prefix + ext;
        const cb = this._onConfirm;
        this.hideAndDestroy(null, () => {
            if (cb) cb({ archiveName, archiveType: type });
        });
    }
}

class FmUploadPopup extends FmPopup {
    constructor(options = {}) {
        const { currentPath, rootDir, ajaxUrl, onSuccess, ...rest } = options;

        const destHint = currentPath || rootDir || '(root)';

        let upIni = (typeof fm_php_upload_max_filesize === 'string' && fm_php_upload_max_filesize) ? fm_php_upload_max_filesize : '—';
        let postIni = (typeof fm_php_post_max_size === 'string' && fm_php_post_max_size) ? fm_php_post_max_size : '—';

        super({
            title:   'Upload files',
            content:
                '<p class="fm-upload-into">Into: <strong>' + FmPopup.escapeHtml(destHint) + '</strong></p>' +
                '<p class="fm-upload-hint">Drag and drop files or folders here, or use the buttons below. Folders keep their structure. Large uploads depend on PHP ' +
                '<span class="fm-upload-php-ini"><code>upload_max_filesize</code><span class="fm-upload-php-val"> (' + FmPopup.escapeHtml(upIni) + ')</span></span>' +
                ' and ' +
                '<span class="fm-upload-php-ini"><code>post_max_size</code><span class="fm-upload-php-val"> (' + FmPopup.escapeHtml(postIni) + ')</span></span>.</p>' +
                '<div class="fm-upload-dropzone" tabindex="0" role="button" aria-label="Drop files to upload">' +
                '<span class="fm-upload-dropzone-text"><i class="bi bi-cloud-arrow-up" aria-hidden="true"></i> Drop files or folders here</span>' +
                '</div>' +
                '<div class="fm-upload-browse-row">' +
                '<button type="button" class="fm-btn secondary fm-upload-browse-files">Browse files</button>' +
                '<button type="button" class="fm-btn secondary fm-upload-browse-folder">Browse folder</button>' +
                '<span class="fm-upload-file-count" aria-live="polite">0 / 0</span>' +
                '</div>' +
                '<input type="file" class="fm-upload-input-files fm-hidden" multiple aria-hidden="true">' +
                '<input type="file" class="fm-upload-input-dir fm-hidden" multiple aria-hidden="true">' +
                '<div class="fm-upload-queue-wrap"><div class="fm-upload-queue-title">Queued</div><div class="fm-upload-queue"></div></div>' +
                '<p class="fm-upload-status fm-hidden" role="status"></p>',
            buttons: [
                { label: 'Cancel', close: true },
                { label: 'Upload', primary: true, close: false, onClick: () => this._submitUpload() },
            ],
            onAfterShow() {
                this._bindUploadUi();
            },
            maxWidth: '640px',
            ...rest,
        });

        this._currentPath = currentPath;
        this._rootDir     = rootDir;
        this._ajaxUrl     = ajaxUrl;
        this._onSuccess   = onSuccess || null;
        this._queue = new Map();
    }

    static async collectFilesFromDataTransfer(dt) {
        const out = [];
        if (!dt) return out;
        if (dt.items && dt.items.length && typeof dt.items[0].webkitGetAsEntry === 'function') {
            for (let i = 0; i < dt.items.length; i++) {
                const item = dt.items[i];
                if (item.kind !== 'file') continue;
                const entry = item.webkitGetAsEntry();
                if (entry) {
                    const part = await FmUploadPopup._traverseFileSystemEntry(entry, '');
                    out.push(...part);
                } else {
                    const f = item.getAsFile();
                    if (f) out.push(f);
                }
            }
            if (out.length > 0) return out;
        }
        if (dt.files && dt.files.length) return Array.from(dt.files);
        return out;
    }

    static async _traverseFileSystemEntry(entry, pathPrefix) {
        const out = [];
        if (entry.isFile) {
            const file = await new Promise((resolve, reject) => {
                entry.file(resolve, reject);
            });
            const rel = (pathPrefix + file.name).replace(/^\/+/, '');
            try {
                Object.defineProperty(file, 'webkitRelativePath', { value: rel, configurable: true, writable: true });
            } catch (e) {}
            out.push(file);
            return out;
        }
        if (entry.isDirectory) {
            const prefix = pathPrefix + entry.name + '/';
            const reader = entry.createReader();
            let batch;
            do {
                batch = await new Promise((resolve, reject) => {
                    reader.readEntries(resolve, reject);
                });
                for (let j = 0; j < batch.length; j++) {
                    out.push(...await FmUploadPopup._traverseFileSystemEntry(batch[j], prefix));
                }
            } while (batch.length > 0);
        }
        return out;
    }

    _pathIn() {
        return fmPathInParam(this._currentPath, this._rootDir);
    }

    static iniSizeToBytes(value) {
        const m = /^\s*(-?\d+)\s*([kmg]?)/i.exec(String(value || ''));
        if (!m) return null;
        const n = parseInt(m[1], 10);
        if (!(n > 0)) return null;
        return n * ({ '': 1, k: 1024, m: 1048576, g: 1073741824 })[m[2].toLowerCase()];
    }

    static uploadSizeLimit() {
        const limits = [
            { setting: 'upload_max_filesize', raw: typeof fm_php_upload_max_filesize === 'string' ? fm_php_upload_max_filesize : '' },
            { setting: 'post_max_size', raw: typeof fm_php_post_max_size === 'string' ? fm_php_post_max_size : '' },
        ]
            .map((l) => ({ ...l, bytes: FmUploadPopup.iniSizeToBytes(l.raw) }))
            .filter((l) => l.bytes !== null);
        if (limits.length === 0) return null;
        return limits.reduce((a, b) => (b.bytes < a.bytes ? b : a));
    }

    _fmtSize(n) {
        const x = Number(n);
        if (!Number.isFinite(x) || x < 0) return '';
        if (x < 1024) return x + ' B';
        if (x < 1048576) return (x / 1024).toFixed(1) + ' KiB';
        return (x / 1048576).toFixed(1) + ' MiB';
    }

    _queueKey(file) {
        const p = (file.webkitRelativePath || '').trim();
        return p || file.name;
    }

    _updateUploadQueueCount() {
        const countEl = this.querySelector('.fm-upload-file-count') || this.querySelector('.fm-upload-queue-count');
        if (!countEl) {
            return;
        }
        const total = this._queue.size;
        const done = Array.from(this._queue.values()).filter((entry) => entry.status === 'done').length;
        countEl.textContent = done + ' / ' + total;
    }

    _addFiles(fileList) {
        if (!fileList || !fileList.length) return;
        const limit = FmUploadPopup.uploadSizeLimit();
        const incoming = new Map();
        for (let i = 0; i < fileList.length; i++) {
            const f = fileList[i];
            const k = this._queueKey(f);
            if (limit && f.size > limit.bytes) {
                incoming.set(k, {
                    file: f,
                    status: 'error',
                    errorMsg: 'Too large for the server: the limit is ' + limit.raw + ' (' + limit.setting + ' in php.ini). Not uploaded.',
                });
            } else {
                incoming.set(k, { file: f, status: 'pending' });
            }
        }
        const merged = new Map();
        incoming.forEach((v, k) => merged.set(k, v));
        this._queue.forEach((v, k) => {
            if (!merged.has(k)) merged.set(k, v);
        });
        this._queue = merged;
        this._renderQueue();
        const wrap = this.querySelector('.fm-upload-queue-wrap');
        if (wrap) wrap.scrollTop = 0;
    }

    _renderQueue() {
        const wrap = this.querySelector('.fm-upload-queue');
        if (!wrap) return;
        wrap.innerHTML = '';
        if (this._queue.size === 0) {
            const p = document.createElement('p');
            p.className = 'fm-upload-queue-empty';
            p.textContent = 'No files queued.';
            wrap.appendChild(p);
            this._updateUploadQueueCount();
            return;
        }
        let idx = 0;
        this._queue.forEach((entry, key) => {
            const file = entry.file;
            const st = entry.status;
            const row = document.createElement('div');
            row.className = 'fm-upload-queue-row';
            row.setAttribute('data-upload-idx', String(idx));

            const top = document.createElement('div');
            top.className = 'fm-upload-queue-row-top';
            const name = document.createElement('span');
            name.className = 'fm-upload-queue-name';
            name.textContent = key;
            name.title = key;
            const sz = document.createElement('span');
            sz.className = 'fm-upload-queue-size';
            sz.textContent = this._fmtSize(file.size);
            const rm = document.createElement('button');
            rm.type = 'button';
            rm.className = 'fm-upload-queue-remove';
            rm.setAttribute('aria-label', 'Remove from queue');
            rm.innerHTML = '&times;';
            rm.addEventListener('click', () => {
                this._queue.delete(key);
                this._renderQueue();
            });
            top.appendChild(name);
            top.appendChild(sz);
            top.appendChild(rm);

            const progWrap = document.createElement('div');
            progWrap.className = 'fm-upload-queue-progress-wrap';
            const track = document.createElement('div');
            track.className = 'fm-upload-queue-progress-track';
            const bar = document.createElement('div');
            bar.className = 'fm-upload-queue-progress-bar';
            track.appendChild(bar);
            progWrap.appendChild(track);

            const itemStatus = document.createElement('div');
            itemStatus.className = 'fm-upload-queue-item-status';

            if (st === 'pending') {
                itemStatus.textContent = 'Pending\u2026';
                bar.style.width = '0%';
            } else if (st === 'uploading') {
                itemStatus.textContent = 'Uploading\u2026';
            } else if (st === 'done') {
                itemStatus.textContent = 'Done';
                itemStatus.classList.add('fm-upload-item-status--ok');
                bar.style.width = '100%';
                progWrap.classList.add('fm-upload-progress--done');
            } else if (st === 'error') {
                itemStatus.textContent = entry.errorMsg || 'Failed';
                itemStatus.classList.add('fm-upload-item-status--err');
                bar.style.width = '100%';
                progWrap.classList.add('fm-upload-progress--error');
            }

            row.appendChild(top);
            row.appendChild(progWrap);
            row.appendChild(itemStatus);
            wrap.appendChild(row);
            idx++;
        });
        this._updateUploadQueueCount();
    }

    _uploadSingleFileWithProgress(file, progressWrap, barEl, statusEl) {
        return new Promise((resolve) => {
            const form = new FormData();
            form.append('action', 'upload');
            form.append('in', this._pathIn());
            form.append('files[]', file);
            form.append('paths[]', file.webkitRelativePath || file.name);

            const xhr = new XMLHttpRequest();
            xhr.open('POST', this._ajaxUrl);
            xhr.setRequestHeader('X-CSRF-Token', fm_csrf_token);
            xhr.withCredentials = true;

            xhr.upload.addEventListener('progress', (e) => {
                if (e.lengthComputable && e.total > 0 && barEl) {
                    const pct = Math.min(100, (e.loaded / e.total) * 100);
                    barEl.style.width = pct + '%';
                }
            });

            const finish = (ok, msg) => {
                if (barEl) {
                    barEl.style.width = '100%';
                }
                if (progressWrap) {
                    progressWrap.classList.toggle('fm-upload-progress--error', !ok);
                    progressWrap.classList.toggle('fm-upload-progress--done', ok);
                }
                if (statusEl) {
                    statusEl.textContent = ok ? 'Done' : (msg || 'Failed');
                    statusEl.classList.toggle('fm-upload-item-status--ok', ok);
                    statusEl.classList.toggle('fm-upload-item-status--err', !ok);
                }
                resolve({ ok, msg: msg || '' });
            };

            xhr.onload = () => {
                if (xhr.status === 401) {
                    finish(false, 'Unauthorized');
                    return;
                }
                const text = xhr.responseText || '{}';
                let data = null;
                try {
                    data = JSON.parse(text);
                } catch (err) {
                    const start = text.indexOf('{"status"');
                    try {
                        data = start >= 0 ? JSON.parse(text.slice(start)) : null;
                    } catch (err2) {
                        data = null;
                    }
                }
                if (!data || typeof data !== 'object') {
                    finish(false, xhr.status === 413
                        ? 'The web server rejected the file as too large (nginx client_max_body_size / Apache LimitRequestBody).'
                        : 'Unexpected server response (HTTP ' + xhr.status + '). Check the PHP / web server error log.');
                    return;
                }
                if (xhr.status === 200 && data.status === 'success' && (Number(data.uploaded) || 0) >= 1) {
                    finish(true, '');
                    return;
                }
                finish(false, (data.errors && data.errors[0]) || data.msg || ('HTTP ' + xhr.status));
            };

            xhr.onerror = () => finish(false, 'Network error');
            xhr.send(form);
        });
    }

    _bindUploadUi() {
        const zone      = this.querySelector('.fm-upload-dropzone');
        const inputF    = this.querySelector('.fm-upload-input-files');
        const inputDir  = this.querySelector('.fm-upload-input-dir');
        const btnFiles  = this.querySelector('.fm-upload-browse-files');
        const btnFolder = this.querySelector('.fm-upload-browse-folder');

        const inputSupportsDir = typeof HTMLInputElement !== 'undefined' && 'webkitdirectory' in HTMLInputElement.prototype;
        if (inputDir) {
            if (inputSupportsDir) {
                inputDir.setAttribute('webkitdirectory', '');
            } else {
                inputDir.style.display = 'none';
                if (btnFolder) btnFolder.style.display = 'none';
            }
        }

        const activate = (on) => {
            if (zone) zone.classList.toggle('fm-upload-dropzone--active', !!on);
        };

        if (zone) {
            ['dragenter', 'dragover'].forEach(ev => {
                zone.addEventListener(ev, (e) => {
                    e.preventDefault();
                    e.stopPropagation();
                    activate(true);
                });
            });
            zone.addEventListener('dragleave', (e) => {
                if (!zone.contains(e.relatedTarget)) activate(false);
            });
            zone.addEventListener('drop', (e) => {
                e.preventDefault();
                e.stopPropagation();
                activate(false);
                FmUploadPopup.collectFilesFromDataTransfer(e.dataTransfer).then(files => this._addFiles(files)).catch(() => {});
            });
        }

        if (inputF) {
            inputF.addEventListener('change', () => {
                if (inputF.files && inputF.files.length) this._addFiles(inputF.files);
                inputF.value = '';
            });
        }
        if (inputDir && inputSupportsDir) {
            inputDir.addEventListener('change', () => {
                if (inputDir.files && inputDir.files.length) this._addFiles(inputDir.files);
                inputDir.value = '';
            });
        }
        if (btnFiles && inputF) btnFiles.addEventListener('click', () => inputF.click());
        if (btnFolder && inputDir && inputSupportsDir) btnFolder.addEventListener('click', () => inputDir.click());

        this._renderQueue();
    }

    _submitUpload() {
        if (this._queue.size === 0) {
            fmUserNotice({ variant: 'warning', title: 'Upload', message: 'Add at least one file to upload.' });
            return;
        }

        const ordered = [...this._queue.entries()];
        const pendingTuples = ordered.filter(([, v]) => v.status === 'pending');
        if (pendingTuples.length === 0) {
            fmUserNotice({
                variant: 'warning',
                title:   'Upload',
                message: 'No files are waiting to upload. Add new files (they appear at the top) or remove a failed item and add it again.',
            });
            return;
        }

        this._renderQueue();

        const wrap      = this.querySelector('.fm-upload-queue');
        const statusEl  = this.querySelector('.fm-upload-status');
        const primary   = this.el && this.el.querySelector('.fm-popup-primary-btn');
        const btnBar    = this.el && this.el.querySelector('.fm-buttons');

        if (!this._uploadStateBackup) {
            this._uploadStateBackup = {
                closeOnBackdrop: this.options.closeOnBackdrop,
                closeOnEscape: this.options.closeOnEscape,
            };
        }
        this.options.closeOnBackdrop = false;
        this.options.closeOnEscape = false;
        this._uploadInProgress = true;

        const keyToRowIndex = new Map();
        ordered.forEach(([k], i) => keyToRowIndex.set(k, i));

        if (wrap) wrap.classList.add('fm-upload-queue--uploading');
        if (this.el) {
            this.el.classList.add('fm-upload-popup--busy');
            this._setHeaderCloseVisible(false);
        }
        if (primary) primary.disabled = true;
        if (btnBar) {
            btnBar.querySelectorAll('button').forEach((b) => {
                b.disabled = true;
            });
        }
        if (statusEl) {
            statusEl.style.display = '';
            statusEl.textContent = 'Ready — ' + pendingTuples.length + ' file(s) to upload.';
        }

        const startJobs = () => {
            pendingTuples.forEach(([key]) => {
                const i = keyToRowIndex.get(key);
                if (i === undefined) return;
                const row = wrap && wrap.querySelector('[data-upload-idx="' + i + '"]');
                if (!row) return;
                const ent = this._queue.get(key);
                if (ent) ent.status = 'uploading';

                const st = row.querySelector('.fm-upload-queue-item-status');
                if (st) {
                    st.textContent = 'Uploading\u2026';
                    st.classList.remove('fm-upload-item-status--ok', 'fm-upload-item-status--err');
                }
                const pw = row.querySelector('.fm-upload-queue-progress-wrap');
                const bar = row.querySelector('.fm-upload-queue-progress-bar');
                if (pw) {
                    pw.classList.remove('fm-upload-progress--error', 'fm-upload-progress--done');
                }
                if (bar) bar.style.width = '0%';
            });

            if (statusEl) {
                statusEl.textContent = 'Uploading ' + pendingTuples.length + ' file(s)\u2026';
            }

            const jobs = pendingTuples.map(([key, entry]) => {
                const i = keyToRowIndex.get(key);
                const row = wrap && i !== undefined && wrap.querySelector('[data-upload-idx="' + i + '"]');
                const progressWrap = row && row.querySelector('.fm-upload-queue-progress-wrap');
                const barEl = row && row.querySelector('.fm-upload-queue-progress-bar');
                const itemSt = row && row.querySelector('.fm-upload-queue-item-status');
                return this._uploadSingleFileWithProgress(entry.file, progressWrap, barEl, itemSt).then((r) => {
                    const e = this._queue.get(key);
                    if (e) {
                        e.status = r.ok ? 'done' : 'error';
                        e.errorMsg = r.ok ? '' : (r.msg || 'Failed');
                    }
                    this._updateUploadQueueCount();
                    return r;
                });
            });

            Promise.all(jobs)
                .then((results) => {
                    unlockUi();
                    const allOk = results.every((r) => r.ok);
                    const anyOk = results.some((r) => r.ok);
                    const failed = results.filter((r) => !r.ok);

                    if (statusEl) {
                        if (allOk) {
                            statusEl.textContent = 'Batch finished. You can upload more pending files or close when done.';
                        } else if (anyOk) {
                            statusEl.textContent = 'Finished with errors — fix issues and use Upload again for pending items.';
                        } else {
                            statusEl.textContent = 'Upload failed.';
                        }
                    }

                    if (allOk && this._onSuccess) {
                        this._onSuccess();
                    }
                    if (failed.length) {
                        fmUserNotice({
                            variant: anyOk ? 'warning' : 'error',
                            title:   'Upload',
                            message: (anyOk ? 'Some files did not upload:\n\n' : '') +
                                failed.map((r) => r.msg).filter(Boolean).join('\n'),
                        });
                    }
                })
                .catch(() => {
                    unlockUi();
                    pendingTuples.forEach(([key]) => {
                        const e = this._queue.get(key);
                        if (e && e.status === 'uploading') {
                            e.status = 'pending';
                            e.errorMsg = '';
                        }
                    });
                    if (statusEl) statusEl.textContent = 'Upload failed.';
                    fmUserNotice({ title: 'Upload', message: 'Upload failed.' });
                });
        };

        const unlockUi = () => {
            if (wrap) wrap.classList.remove('fm-upload-queue--uploading');
            if (this.el) {
                this.el.classList.remove('fm-upload-popup--busy');
                if (this._uploadStateBackup) {
                    this.options.closeOnBackdrop = this._uploadStateBackup.closeOnBackdrop;
                    this.options.closeOnEscape = this._uploadStateBackup.closeOnEscape;
                    this._uploadStateBackup = null;
                }
                this._uploadInProgress = false;
                this._setHeaderCloseVisible(this._shouldShowHeaderClose());
            }
            if (primary) primary.disabled = false;
            if (btnBar) {
                btnBar.querySelectorAll('button').forEach((b) => {
                    b.disabled = false;
                });
            }
        };

        requestAnimationFrame(() => {
            requestAnimationFrame(startJobs);
        });
    }
}

class FmChmodPopup extends FmPopup {
    constructor(options = {}) {
        const {
            currentPath,
            rootDir,
            ajaxUrl,
            folderRows = [],
            fileRows = [],
            onSuccess,
            ...rest
        } = options;

        const esc = FmPopup.escapeHtml.bind(FmPopup);
        const initOct = (rows, isFolder) => FmChmodPopup._initialOctal(rows, isFolder);
        const hasFolders = folderRows.length > 0;
        const hasFiles = fileRows.length > 0;
        const showFileSection = hasFiles || hasFolders;

        const bitsHtml = (kind) =>
            '<div class="fm-chmod-bits fm-chmod-bits-' + kind + '" role="group">' +
            '<div class="fm-chmod-bits-group"><span class="fm-chmod-bits-heading">Owner</span> ' +
            '<label class="fm-chmod-bit"><input type="checkbox" data-bit="256"> <span>r</span></label>' +
            '<label class="fm-chmod-bit"><input type="checkbox" data-bit="128"> <span>w</span></label>' +
            '<label class="fm-chmod-bit"><input type="checkbox" data-bit="64"> <span>x</span></label></div>' +
            '<div class="fm-chmod-bits-group"><span class="fm-chmod-bits-heading">Group</span> ' +
            '<label class="fm-chmod-bit"><input type="checkbox" data-bit="32"> <span>r</span></label>' +
            '<label class="fm-chmod-bit"><input type="checkbox" data-bit="16"> <span>w</span></label>' +
            '<label class="fm-chmod-bit"><input type="checkbox" data-bit="8"> <span>x</span></label></div>' +
            '<div class="fm-chmod-bits-group"><span class="fm-chmod-bits-heading">Other</span> ' +
            '<label class="fm-chmod-bit"><input type="checkbox" data-bit="4"> <span>r</span></label>' +
            '<label class="fm-chmod-bit"><input type="checkbox" data-bit="2"> <span>w</span></label>' +
            '<label class="fm-chmod-bit"><input type="checkbox" data-bit="1"> <span>x</span></label></div>' +
            '</div>';

        const folderSection =
            !hasFolders
                ? ''
                : '<fieldset class="fm-chmod-fieldset">' +
                  '<legend>Folders <span class="fm-chmod-count">(' + folderRows.length + ')</span></legend>' +
                  '<p class="fm-chmod-hint">Mode for selected folders (typical website: <code>0755</code>).</p>' +
                  '<label class="fm-chmod-rec-label"><input type="checkbox" class="fm-chmod-recursive-folders" name="chmod-rec-f"> ' +
                  'Apply to all subfolders (recursive)</label>' +
                  '<div class="fm-chmod-octal-row"><label class="fm-chmod-octal-label">Octal ' +
                  '<input type="text" class="fm-input fm-chmod-octal-folders" maxlength="4" inputmode="numeric" ' +
                  'placeholder="0755" value="' + esc(initOct(folderRows, true)) + '"></label></div>' +
                  bitsHtml('folders') +
                  '</fieldset>';

        let fileLegendCount = '';
        let fileHint = '';
        let fileRecLabel = '';
        if (hasFiles && hasFolders) {
            fileLegendCount = '(' + fileRows.length + ' selected)';
            fileHint =
                'Mode for selected files and, if checked, files inside selected folders (typical: <code>0644</code>).';
            fileRecLabel =
                '<label class="fm-chmod-rec-label"><input type="checkbox" class="fm-chmod-recursive-files" name="chmod-rec-fl"> ' +
                'Also apply to files inside selected folders</label>';
        } else if (hasFolders) {
            fileLegendCount = '(inside folders)';
            fileHint =
                'Mode for files inside selected folders when recursive is checked (typical: <code>0644</code>).';
            fileRecLabel =
                '<label class="fm-chmod-rec-label"><input type="checkbox" class="fm-chmod-recursive-files" name="chmod-rec-fl"> ' +
                'Apply to all files inside selected folders</label>';
        } else {
            fileLegendCount = '(' + fileRows.length + ')';
            fileHint = 'Applies to selected files.';
            fileRecLabel = '';
        }

        const fileSection =
            !showFileSection
                ? ''
                : '<fieldset class="fm-chmod-fieldset">' +
                  '<legend>Files <span class="fm-chmod-count">' + fileLegendCount + '</span></legend>' +
                  '<p class="fm-chmod-hint">' + fileHint + '</p>' +
                  fileRecLabel +
                  '<div class="fm-chmod-octal-row"><label class="fm-chmod-octal-label">Octal ' +
                  '<input type="text" class="fm-input fm-chmod-octal-files" maxlength="4" inputmode="numeric" ' +
                  'placeholder="0644" value="' + esc(initOct(fileRows, false)) + '"></label></div>' +
                  bitsHtml('files') +
                  '</fieldset>';

        const summaryParts = [];
        if (folderRows.length) summaryParts.push(folderRows.length + ' folder' + (folderRows.length === 1 ? '' : 's'));
        if (fileRows.length) summaryParts.push(fileRows.length + ' file' + (fileRows.length === 1 ? '' : 's'));
        const intro =
            '<p class="fm-chmod-intro">Change permissions for ' +
            summaryParts.join(' and ') +
            '. Use recursive checkboxes to set folders to <code>0755</code> and files to <code>0644</code> in one step.</p>';

        super({
            title: 'Change permissions',
            className: 'fm-chmod-popup',
            maxWidth: '530px',
            submitOnEnter: true,
            content:
                intro +
                '<p class="fm-error fm-hidden fm-chmod-error" data-chmod-err></p>' +
                folderSection +
                fileSection,
            buttons: [
                { label: 'Cancel', close: true },
                { label: 'Apply', primary: true, close: false, onClick: () => this._apply() },
            ],
            onAfterShow() {
                this._wireSection('folders');
                this._wireSection('files');
            },
            ...rest,
        });

        this._currentPath = currentPath || '';
        this._rootDir = rootDir || '';
        this._ajaxUrl = ajaxUrl || '';
        this._folderRows = folderRows;
        this._fileRows = fileRows;
        this._onSuccess = typeof onSuccess === 'function' ? onSuccess : null;
    }

    static _initialOctal(rows, isFolder) {
        const fallback = isFolder ? '0755' : '0644';
        if (!rows || rows.length === 0) return fallback;
        const normOct = (s) => {
            const t = String(s == null ? '' : s).trim();
            if (/^0[0-7]{3}$/.test(t)) return t;
            if (/^[0-7]{3}$/.test(t)) return '0' + t;
            return null;
        };
        const first = normOct(rows[0] && rows[0].permissions != null ? String(rows[0].permissions) : '');
        if (!first) return fallback;
        const same = rows.every((r) => normOct(r && r.permissions != null ? String(r.permissions) : '') === first);
        return same ? first : fallback;
    }

    _wireSection(kind) {
        const root = this.el && this.el.querySelector('.fm-chmod-bits-' + kind);
        const octIn = this.el && this.el.querySelector('.fm-chmod-octal-' + kind);
        if (!root || !octIn) return;

        const checks = root.querySelectorAll('input[type="checkbox"][data-bit]');

        const applyOctToBoxes = () => {
            let s = String(octIn.value || '').trim().replace(/\s/g, '');
            if (!/^0?[0-7]{3}$/.test(s)) return;
            const n = parseInt(s, 8) & 511;
            checks.forEach((cb) => {
                const b = parseInt(cb.getAttribute('data-bit'), 10);
                cb.checked = (n & b) !== 0;
            });
        };

        const applyBoxesToOct = () => {
            let n = 0;
            checks.forEach((cb) => {
                if (cb.checked) n |= parseInt(cb.getAttribute('data-bit'), 10);
            });
            const t = (n & 511).toString(8);
            octIn.value = ('000' + t).slice(-4);
        };

        octIn.addEventListener('input', applyOctToBoxes);
        octIn.addEventListener('change', applyOctToBoxes);
        checks.forEach((cb) => {
            cb.addEventListener('change', applyBoxesToOct);
        });
        applyOctToBoxes();
    }

    _apply() {
        const errEl = this.el && this.el.querySelector('[data-chmod-err]');
        if (errEl) {
            errEl.textContent = '';
            errEl.classList.add('fm-hidden');
        }

        const folderOctIn = this.el.querySelector('.fm-chmod-octal-folders');
        const fileOctIn = this.el.querySelector('.fm-chmod-octal-files');
        const recFolders = this.el.querySelector('.fm-chmod-recursive-folders');
        const recFiles = this.el.querySelector('.fm-chmod-recursive-files');

        const pathIn = fmPathInParam(this._currentPath, this._rootDir);
        const wantRecFiles = !!(recFiles && recFiles.checked);
        const wantRecFolders = !!(recFolders && recFolders.checked);
        const needFileMode = this._fileRows.length > 0 || (this._folderRows.length > 0 && wantRecFiles);

        const form = new FormData();
        form.append('action', 'chmod');
        form.append('in', pathIn);

        if (this._folderRows.length > 0) {
            const raw = folderOctIn ? String(folderOctIn.value || '').trim() : '';
            if (!/^0?[0-7]{3}$/.test(raw)) {
                if (errEl) {
                    errEl.textContent = 'Enter a valid octal mode for folders (e.g. 755).';
                    errEl.classList.remove('fm-hidden');
                }
                return;
            }
            form.append('folder_mode', raw);
            form.append('recursive_folders', wantRecFolders ? '1' : '0');
            this._folderRows.forEach((r) => {
                if (r && r.name) form.append('folder_names[]', r.name);
            });
        }

        if (needFileMode) {
            const raw = fileOctIn ? String(fileOctIn.value || '').trim() : '';
            if (!/^0?[0-7]{3}$/.test(raw)) {
                if (errEl) {
                    errEl.textContent = 'Enter a valid octal mode for files (e.g. 644).';
                    errEl.classList.remove('fm-hidden');
                }
                return;
            }
            form.append('file_mode', raw);
            form.append('recursive_files', wantRecFiles ? '1' : '0');
            this._fileRows.forEach((r) => {
                if (r && r.name) form.append('file_names[]', r.name);
            });
        } else {
            form.append('recursive_files', '0');
        }

        const primary = this.el.querySelector('.fm-buttons .fm-popup-action-button.fm-popup-primary, .fm-buttons .fm-popup-action-button:last-of-type');
        if (primary) primary.disabled = true;

        const run = typeof requireAuthFetch === 'function'
            ? requireAuthFetch(this._ajaxUrl, fmPostInit(form))
            : fetch(this._ajaxUrl, fmPostInit(form));

        run
            .then((r) => r.json())
            .then((data) => {
                if (primary) primary.disabled = false;
                if (data && (data.status === 'success' || data.status === 'partial')) {
                    if (data.status === 'partial' && typeof fmUserNotice === 'function') {
                        const errs = (data && data.errors) || [];
                        if (errs.length) {
                            fmUserNotice({ title: 'Permissions', message: errs.join('; ') });
                        }
                    }
                    this.hideAndDestroy();
                    if (this._onSuccess) this._onSuccess();
                    return;
                }
                const errs = (data && data.errors) || [];
                const msg =
                    (data && data.msg) ||
                    (errs.length ? errs.join('; ') : 'Could not change permissions.');
                if (errEl) {
                    errEl.textContent = msg;
                    errEl.classList.remove('fm-hidden');
                } else if (typeof fmUserNotice === 'function') {
                    fmUserNotice({ title: 'Permissions', message: msg });
                }
            })
            .catch(() => {
                if (primary) primary.disabled = false;
                if (errEl) {
                    errEl.textContent = 'Request failed.';
                    errEl.classList.remove('fm-hidden');
                } else if (typeof fmUserNotice === 'function') {
                    fmUserNotice({ title: 'Permissions', message: 'Request failed.' });
                }
            });
    }
}

class FmBulkRenamePopup extends FmPopup {
    static STORAGE_KEY = 'fm_bulk_rename_popup_state_1';
    constructor(options = {}) {
        const { currentPath, rootDir, ajaxUrl, rows = [], onSuccess, ...rest } = options;
        const n = rows.length;
        const content =
            '<p class="fm-br-intro">Rename <strong>' +
            n +
            '</strong> item' +
            (n === 1 ? '' : 's') +
            ' using the rules below. Preview updates as you type.</p>' +
            '<div class="fm-br-controls">' +
            '<div class="fm-br-row"><label class="fm-br-label">Find</label>' +
            '<input type="text" class="fm-input fm-br-find" autocomplete="off" placeholder="Text to find in each name"></div>' +
            '<div class="fm-br-row"><label class="fm-br-label">Replace with</label>' +
            '<input type="text" class="fm-input fm-br-replace" autocomplete="off" placeholder="Replacement (may be empty)"></div>' +
            '<div class="fm-br-row"><label class="fm-br-label">Prefix</label>' +
            '<input type="text" class="fm-input fm-br-prefix" autocomplete="off"></div>' +
            '<div class="fm-br-row"><label class="fm-br-label">Suffix</label>' +
            '<input type="text" class="fm-input fm-br-suffix" autocomplete="off"></div>' +
            '<label class="fm-br-check"><input type="checkbox" class="fm-br-suffix-before-ext" checked> ' +
            'Place suffix before file extension</label>' +
            '<div class="fm-br-counter-row">' +
            '<label class="fm-br-check"><input type="checkbox" class="fm-br-use-counter"> Append counter</label>' +
            '<span class="fm-br-counter-fields">' +
            '<label>start <input type="number" class="fm-br-c-start" min="0" value="1" step="1"></label> ' +
            '<label>step <input type="number" class="fm-br-c-step" value="1" step="1"></label> ' +
            '<label>digits <input type="number" class="fm-br-c-digits" min="1" max="9" value="3"></label> ' +
            '<label>sep <input type="text" class="fm-br-c-sep" maxlength="4" value="-"></label>' +
            '</span></div></div>' +
            '<div class="fm-br-preview-wrap">' +
            '<table class="fm-br-preview"><thead><tr><th>Current name</th><th>New name</th></tr></thead>' +
            '<tbody class="fm-br-tbody"></tbody></table></div>' +
            '<p class="fm-error fm-hidden fm-br-err" data-br-err></p>';

        super({
            title: 'Bulk rename',
            className: 'fm-bulk-rename-popup',
            maxWidth: '680px',
            submitOnEnter: true,
            content: content,
            buttons: [
                { label: 'Cancel', close: true },
                { label: 'Reset', close: false, onClick: () => this._resetState() },
                { label: 'Apply', primary: true, close: false, onClick: () => this._apply() },
            ],
            onAfterShow() {
                this._rows = rows.slice();
                this._restoreState();
                this._wire();
                this._refreshPreview();
            },
            ...rest,
        });

        this._currentPath = currentPath || '';
        this._rootDir = rootDir || '';
        this._ajaxUrl = ajaxUrl || '';
        this._onSuccess = typeof onSuccess === 'function' ? onSuccess : null;
    }

    static _computeNewName(oldName, isFolder, index, o) {
        const find = o.find != null ? String(o.find) : '';
        const replace = o.replace != null ? String(o.replace) : '';
        let s = find === '' ? oldName : oldName.split(find).join(replace);
        const prefix = o.prefix != null ? String(o.prefix) : '';
        const suffix = o.suffix != null ? String(o.suffix) : '';
        const suffixBeforeExt = o.suffixBeforeExt !== false;
        const useCounter = !!o.useCounter;
        let start = parseInt(String(o.start), 10);
        if (Number.isNaN(start)) start = 1;
        let step = parseInt(String(o.step), 10);
        if (Number.isNaN(step)) step = 1;
        let digits = parseInt(String(o.digits), 10);
        if (Number.isNaN(digits)) digits = 3;
        digits = Math.min(9, Math.max(1, digits));
        const sep = o.sep != null ? String(o.sep) : '-';

        let counterStr = '';
        if (useCounter) {
            counterStr = sep + String(start + index * step).padStart(digits, '0');
        }

        if (isFolder) {
            return prefix + s + suffix + (useCounter ? counterStr : '');
        }

        const lastDot = s.lastIndexOf('.');
        const hasExt = lastDot > 0 && lastDot < s.length - 1;
        const base = hasExt ? s.slice(0, lastDot) : s;
        const ext = hasExt ? s.slice(lastDot) : '';

        if (suffixBeforeExt) {
            return prefix + base + suffix + (useCounter ? counterStr : '') + ext;
        }
        return prefix + base + (useCounter ? counterStr : '') + ext + suffix;
    }

    _readOpts() {
        const el = this.el;
        if (!el) return {};
        const find = el.querySelector('.fm-br-find');
        const replace = el.querySelector('.fm-br-replace');
        const prefix = el.querySelector('.fm-br-prefix');
        const suffix = el.querySelector('.fm-br-suffix');
        const sb = el.querySelector('.fm-br-suffix-before-ext');
        const uc = el.querySelector('.fm-br-use-counter');
        const st = el.querySelector('.fm-br-c-start');
        const sp = el.querySelector('.fm-br-c-step');
        const dig = el.querySelector('.fm-br-c-digits');
        const sep = el.querySelector('.fm-br-c-sep');
        return {
            find: find ? find.value : '',
            replace: replace ? replace.value : '',
            prefix: prefix ? prefix.value : '',
            suffix: suffix ? suffix.value : '',
            suffixBeforeExt: sb ? sb.checked : true,
            useCounter: uc ? uc.checked : false,
            start: st ? st.value : '1',
            step: sp ? sp.value : '1',
            digits: dig ? dig.value : '3',
            sep: sep ? sep.value : '-',
        };
    }

    _refreshPreview() {
        const esc = FmPopup.escapeHtml.bind(FmPopup);
        const tbody = this.el && this.el.querySelector('.fm-br-tbody');
        const errEl = this.el && this.el.querySelector('[data-br-err]');
        if (!tbody) return;
        const o = this._readOpts();
        const invalidRe = /[\\/:*?"<>|]/;
        let changeCount = 0;
        const seen = Object.create(null);

        let html = '';
        let hasErr = false;
        for (let i = 0; i < this._rows.length; i++) {
            const row = this._rows[i];
            const oldName = row && row.name ? String(row.name) : '';
            const isFolder = !!(row && row.type === 'folder');
            const neu = FmBulkRenamePopup._computeNewName(oldName, isFolder, i, o);
            let cellErr = '';
            if (invalidRe.test(neu)) {
                cellErr = 'Invalid characters';
                hasErr = true;
            } else if (neu === '') {
                cellErr = 'Empty name';
                hasErr = true;
            } else if (seen[neu]) {
                cellErr = 'Duplicate new name';
                hasErr = true;
            } else {
                seen[neu] = true;
            }
            if (neu !== oldName) changeCount += 1;
            const cls = cellErr ? ' fm-br-preview-cell--err' : '';
            html +=
                '<tr><td>' +
                esc(oldName) +
                '</td><td class="' +
                cls.trim() +
                '">' +
                esc(neu) +
                (cellErr ? ' <span class="fm-br-cell-hint">(' + esc(cellErr) + ')</span>' : '') +
                '</td></tr>';
        }
        tbody.innerHTML = html;

        if (errEl) {
            if (changeCount === 0 && this._rows.length > 0) {
                errEl.textContent = 'Every new name matches the current name — change rules or add a counter.';
                errEl.classList.remove('fm-hidden');
                hasErr = true;
            } else {
                errEl.textContent = '';
                errEl.classList.add('fm-hidden');
            }
        }
        this._previewHasError = hasErr;
    }

    _wire() {
        const el = this.el;
        if (!el) return;
        const on = () => this._refreshPreview();
        el.querySelectorAll('.fm-br-controls input').forEach((inp) => {
            inp.addEventListener('input', on);
            inp.addEventListener('change', on);
            inp.addEventListener('input', () => this._persistState());
            inp.addEventListener('change', () => this._persistState());
        });
        const uc = el.querySelector('.fm-br-use-counter');
        const fields = el.querySelector('.fm-br-counter-fields');
        const sync = () => {
            if (fields) fields.style.opacity = uc && uc.checked ? '' : '0.45';
            if (fields) fields.style.pointerEvents = uc && uc.checked ? '' : 'none';
            on();
        };
        if (uc) uc.addEventListener('change', sync);
        sync();
    }

    _persistState() {
        try {
            localStorage.setItem(FmBulkRenamePopup.STORAGE_KEY, JSON.stringify(this._readOpts()));
        } catch (e) {}
    }

    _restoreState() {
        try {
            const raw = localStorage.getItem(FmBulkRenamePopup.STORAGE_KEY);
            if (!raw || !this.el) return;
            const d = JSON.parse(raw) || {};
            const setVal = (sel, val) => {
                const n = this.el.querySelector(sel);
                if (n && typeof val === 'string') n.value = val;
            };
            const setChk = (sel, val) => {
                const n = this.el.querySelector(sel);
                if (n && typeof val === 'boolean') n.checked = val;
            };
            setVal('.fm-br-find', d.find);
            setVal('.fm-br-replace', d.replace);
            setVal('.fm-br-prefix', d.prefix);
            setVal('.fm-br-suffix', d.suffix);
            setChk('.fm-br-suffix-before-ext', d.suffixBeforeExt);
            setChk('.fm-br-use-counter', d.useCounter);
            setVal('.fm-br-c-start', d.start != null ? String(d.start) : '');
            setVal('.fm-br-c-step', d.step != null ? String(d.step) : '');
            setVal('.fm-br-c-digits', d.digits != null ? String(d.digits) : '');
            setVal('.fm-br-c-sep', d.sep);
        } catch (e) {}
    }

    _resetState() {
        try { localStorage.removeItem(FmBulkRenamePopup.STORAGE_KEY); } catch (e) {}
        const setVal = (sel, val) => {
            const n = this.el && this.el.querySelector(sel);
            if (n) n.value = val;
        };
        const setChk = (sel, val) => {
            const n = this.el && this.el.querySelector(sel);
            if (n) n.checked = val;
        };
        setVal('.fm-br-find', '');
        setVal('.fm-br-replace', '');
        setVal('.fm-br-prefix', '');
        setVal('.fm-br-suffix', '');
        setChk('.fm-br-suffix-before-ext', true);
        setChk('.fm-br-use-counter', false);
        setVal('.fm-br-c-start', '1');
        setVal('.fm-br-c-step', '1');
        setVal('.fm-br-c-digits', '3');
        setVal('.fm-br-c-sep', '-');
        this._refreshPreview();
    }

    _apply() {
        this._refreshPreview();
        const errEl = this.el && this.el.querySelector('[data-br-err]');
        if (this._previewHasError) {
            if (errEl && !errEl.textContent) {
                errEl.textContent = 'Fix invalid or duplicate names in the preview before applying.';
                errEl.classList.remove('fm-hidden');
            }
            return;
        }
        const o = this._readOpts();
        const pairs = [];
        for (let i = 0; i < this._rows.length; i++) {
            const row = this._rows[i];
            const oldName = row && row.name ? String(row.name) : '';
            const isFolder = !!(row && row.type === 'folder');
            const neu = FmBulkRenamePopup._computeNewName(oldName, isFolder, i, o);
            if (neu !== oldName) pairs.push([oldName, neu]);
        }
        if (pairs.length === 0) {
            this.hideAndDestroy();
            return;
        }

        const pathIn = fmPathInParam(this._currentPath, this._rootDir);
        const form = new FormData();
        form.append('action', 'bulk-rename');
        form.append('in', pathIn);
        pairs.forEach((p) => {
            form.append('from[]', p[0]);
            form.append('to[]', p[1]);
        });

        const primary = this.el.querySelector('.fm-buttons .fm-popup-action-button.fm-popup-primary, .fm-buttons .fm-popup-action-button:last-of-type');
        if (primary) primary.disabled = true;

        const run = typeof requireAuthFetch === 'function'
            ? requireAuthFetch(this._ajaxUrl, fmPostInit(form))
            : fetch(this._ajaxUrl, fmPostInit(form));

        run
            .then((r) => r.json())
            .then((data) => {
                if (primary) primary.disabled = false;
                if (data && data.status === 'success') {
                    this.hideAndDestroy();
                    if (this._onSuccess) this._onSuccess();
                    return;
                }
                const msg = (data && data.msg) || 'Bulk rename failed.';
                if (errEl) {
                    errEl.textContent = msg;
                    errEl.classList.remove('fm-hidden');
                } else if (typeof fmUserNotice === 'function') {
                    fmUserNotice({ title: 'Bulk rename', message: msg });
                }
            })
            .catch(() => {
                if (primary) primary.disabled = false;
                if (errEl) {
                    errEl.textContent = 'Request failed.';
                    errEl.classList.remove('fm-hidden');
                } else if (typeof fmUserNotice === 'function') {
                    fmUserNotice({ title: 'Bulk rename', message: 'Request failed.' });
                }
            });
    }
}

class FmImageViewerPopup {
    constructor(options = {}) {
        const items = Array.isArray(options.items) ? options.items.filter((x) => x && x.src) : [];
        this.items = items;
        const n = items.length;
        let start = Number(options.startIndex) || 0;
        if (start < 0) start = 0;
        if (start >= n) start = Math.max(0, n - 1);
        this.index = start;

        this._boundDocKeydown = this._onDocKeydown.bind(this);

        const contentHtml =
            '<div class="fm-image-viewer">' +
            '<button type="button" class="fm-image-viewer-close" title="Close (Escape)" aria-label="Close">' +
            '<i class="bi bi-x-lg" aria-hidden="true"></i></button>' +
            '<div class="fm-image-viewer-main">' +
            '<button type="button" class="fm-image-viewer-nav fm-image-viewer-prev" aria-label="Previous image"></button>' +
            '<div class="fm-image-viewer-stage">' +
            '<img class="fm-image-viewer-main-img" alt="" decoding="async" />' +
            '</div>' +
            '<button type="button" class="fm-image-viewer-nav fm-image-viewer-next" aria-label="Next image"></button>' +
            '</div>' +
            '<div class="fm-image-viewer-footer">' +
            '<div class="fm-image-viewer-counter" aria-live="polite"></div>' +
            '<div class="fm-image-viewer-thumbs" role="navigation" aria-label="Images in this folder">' +
            '<div class="fm-image-viewer-thumbs-inner"></div>' +
            '</div></div></div>';

        this._popup = new FmPopup({
            title: '',
            className: 'fm-image-viewer-popup',
            maxWidth: 'none',
            content: contentHtml,
            buttons: [],
            closeOnBackdrop: true,
            closeOnEscape: true,
            showHeaderClose: false,
            showKeyboardHints: false,
            onAfterShow: () => this._afterPopupShow(),
            onBeforeHide: () => this._beforePopupHide(),
        });

        this._root = this._popup.querySelector('.fm-image-viewer');
        const closeTop = this._popup.querySelector('.fm-image-viewer-close');
        if (closeTop) {
            closeTop.addEventListener('click', () => this._popup.hideAndDestroy());
        }
        this._img = this._popup.querySelector('.fm-image-viewer-main-img');
        this._prevBtn = this._popup.querySelector('.fm-image-viewer-prev');
        this._nextBtn = this._popup.querySelector('.fm-image-viewer-next');
        this._counter = this._popup.querySelector('.fm-image-viewer-counter');
        this._thumbsStrip = this._popup.querySelector('.fm-image-viewer-thumbs');
        this._thumbsInner = this._popup.querySelector('.fm-image-viewer-thumbs-inner');

        if (this._prevBtn) {
            this._prevBtn.innerHTML = '<i class="bi bi-chevron-left" aria-hidden="true"></i>';
            this._prevBtn.addEventListener('click', () => this._step(-1));
        }
        if (this._nextBtn) {
            this._nextBtn.innerHTML = '<i class="bi bi-chevron-right" aria-hidden="true"></i>';
            this._nextBtn.addEventListener('click', () => this._step(1));
        }

        if (this._thumbsInner && n > 0) {
            let thumbsHtml = '';
            for (let i = 0; i < n; i++) {
                const it = items[i];
                thumbsHtml +=
                    '<button type="button" class="fm-image-viewer-thumb" data-iv-index="' +
                    i +
                    '" title="' +
                    FmPopup.escapeAttr(it.name) +
                    '">' +
                    '<img src="' +
                    FmPopup.escapeAttr(it.src) +
                    '" alt="" loading="lazy" decoding="async" width="56" height="56" />' +
                    '</button>';
            }
            this._thumbsInner.innerHTML = thumbsHtml;
            this._thumbsInner.querySelectorAll('.fm-image-viewer-thumb').forEach((btn) => {
                btn.addEventListener('click', () => {
                    const j = parseInt(btn.getAttribute('data-iv-index'), 10);
                    if (!Number.isNaN(j) && j >= 0 && j < n) {
                        this.index = j;
                        this._syncUi();
                    }
                });
            });
        }

        if (this._img) {
            this._img.addEventListener('load', () => {
                this._img.classList.remove('fm-image-viewer-main-img--err');
            });
            this._img.addEventListener('error', () => {
                this._img.classList.add('fm-image-viewer-main-img--err');
                this._img.removeAttribute('src');
            });
        }
    }

    _isTopPopup() {
        if (!this._popup || !this._popup.el) return false;
        const shown = typeof document !== 'undefined' ? Array.from(document.querySelectorAll('.fm-popup.show')) : [];
        return shown.length > 0 && shown[shown.length - 1] === this._popup.el;
    }

    _onDocKeydown(e) {
        if (!this._popup || !this._popup.isVisible || !this._isTopPopup()) return;
        if (e.key === 'ArrowLeft') {
            e.preventDefault();
            this._step(-1);
        } else if (e.key === 'ArrowRight') {
            e.preventDefault();
            this._step(1);
        }
    }

    _afterPopupShow() {
        document.addEventListener('keydown', this._boundDocKeydown, true);
        this._syncUi();
        const closeTop = this._popup && this._popup.querySelector('.fm-image-viewer-close');
        if (closeTop) closeTop.focus();
        else if (this._prevBtn) this._prevBtn.focus();
    }

    _beforePopupHide() {
        document.removeEventListener('keydown', this._boundDocKeydown, true);
    }

    _step(delta) {
        const n = this.items.length;
        if (n === 0) return;
        let i = this.index + delta;
        if (i < 0) i = 0;
        if (i >= n) i = n - 1;
        if (i === this.index) return;
        this.index = i;
        this._syncUi();
    }

    _syncUi() {
        const n = this.items.length;
        if (!this._img || n === 0) return;
        const item = this.items[this.index];
        if (this._popup.el) {
            this._popup.el.setAttribute('aria-label', 'Image viewer: ' + item.name);
        }
        this._img.classList.remove('fm-image-viewer-main-img--err');
        this._img.src = item.src;
        this._img.alt = item.name;

        if (this._counter) {
            const countPart = n === 1 ? '1 image' : `${this.index + 1} / ${n}`;
            this._counter.textContent = item.name + ' · ' + countPart;
        }

        if (this._prevBtn) {
            const atStart = this.index <= 0;
            this._prevBtn.disabled = atStart;
            this._prevBtn.setAttribute('aria-disabled', atStart ? 'true' : 'false');
        }
        if (this._nextBtn) {
            const atEnd = this.index >= n - 1;
            this._nextBtn.disabled = atEnd;
            this._nextBtn.setAttribute('aria-disabled', atEnd ? 'true' : 'false');
        }

        if (this._thumbsInner) {
            this._thumbsInner.querySelectorAll('.fm-image-viewer-thumb').forEach((btn, i) => {
                btn.classList.toggle('fm-image-viewer-thumb--current', i === this.index);
            });
        }
        requestAnimationFrame(() => this._scrollActiveThumbIntoViewCentered());
    }

    _scrollActiveThumbIntoViewCentered() {
        const strip = this._thumbsStrip;
        const inner = this._thumbsInner;
        if (!strip || !inner) return;
        const btn = inner.querySelector('.fm-image-viewer-thumb--current');
        if (!btn) return;
        const sr = strip.getBoundingClientRect();
        const br = btn.getBoundingClientRect();
        const btnCenter = br.left + br.width / 2;
        const viewCenter = sr.left + sr.width / 2;
        const delta = btnCenter - viewCenter;
        const maxScroll = Math.max(0, strip.scrollWidth - strip.clientWidth);
        strip.scrollLeft = Math.max(0, Math.min(strip.scrollLeft + delta, maxScroll));
    }

    show() {
        if (this.items.length === 0) return;
        this._popup.show();
    }
}

if (typeof window !== 'undefined') {
    window.FmPopup                      = FmPopup;
    window.FmIndeterminateProgressPopup = FmIndeterminateProgressPopup;
    window.FmNoticePopup                = FmNoticePopup;
    window.fmUserNotice                 = fmUserNotice;
    window.fmToast                      = fmToast;
    window.FmCreateNewFolderPopup       = FmCreateNewFolderPopup;
    window.FmCreateFilePopup   = FmCreateFilePopup;
    window.FmUploadUrlPopup    = FmUploadUrlPopup;
    window.FmEditFilePopup     = FmEditFilePopup;
    window.FmRenamePopup       = FmRenamePopup;
    window.FmDeletePopup       = FmDeletePopup;
    window.FmCompressPopup          = FmCompressPopup;
    window.FmDownloadArchivePopup   = FmDownloadArchivePopup;
    window.FmExtractPopup      = FmExtractPopup;
    window.FmGetInfoPopup      = FmGetInfoPopup;
    window.FmServerInfoPopup   = FmServerInfoPopup;
    window.FmCopyMovePopup     = FmCopyMovePopup;
    window.FmUploadPopup       = FmUploadPopup;
    window.FmTerminalHerePopup = FmTerminalHerePopup;
    window.FmImageViewerPopup  = FmImageViewerPopup;
}


/* ===== Folder tree ===== */
class FmTree {
    static defaultDepth = 12;

    static expandPathChainSet(absPath, treeRoot) {
        const set = new Set();
        if (!absPath || !treeRoot || treeRoot.path == null) return set;
        const root = norm(treeRoot.path);
        let cur = norm(absPath);
        for (let n = 0; n < 500 && cur; n++) {
            set.add(cur);
            if (cur === root) break;
            const slash = cur.lastIndexOf('/');
            if (slash <= 0) break;
            cur = norm(cur.slice(0, slash));
            if (root && cur.length < root.length) break;
        }
        return set;
    }

    constructor({ el, onNodeClick, onLoadMore, autoExpandAncestors = true } = {}) {
        this._el          = el || null;
        this._onNodeClick = onNodeClick || null;
        this._onLoadMore  = onLoadMore  || null;
        this._data        = null;
        this._autoExpandAncestors = autoExpandAncestors !== false;
    }

    get data() {
        return this._data;
    }

    setData(data, currentPath, expandedPaths) {
        this._data = data;
        this.render(currentPath, expandedPaths);
    }

    render(currentPath, expandedPaths) {
        if (!this._el || !this._data) return;
        this._el.innerHTML = this._renderNode(this._data, currentPath || '', 0, expandedPaths);
        this._bindEvents();
        this.highlight(currentPath);
    }

    highlight(path) {
        if (!this._el || !path) return;

        let currentLink = null;
        this._el.querySelectorAll('.fm-tree-link').forEach(function (a) {
            if (norm(a.getAttribute('data-path')) === norm(path)) {
                a.classList.add('fm-tree-current');
                currentLink = a;
            } else {
                a.classList.remove('fm-tree-current');
            }
        });
        if (!currentLink) return;

        if (!this._autoExpandAncestors) {
            return;
        }

        let currentItem = currentLink.closest('.fm-tree-item');
        if (currentItem) {
            const ownChildren = currentItem.nextElementSibling;
            if (ownChildren && ownChildren.classList.contains('fm-tree-children')) {
                ownChildren.classList.remove('fm-tree-closed');
                const ownIcon = currentItem.querySelector('.fm-tree-toggle i.bi');
                if (ownIcon) {
                    ownIcon.classList.remove('bi-chevron-right');
                    ownIcon.classList.add('bi-chevron-down');
                }
            }
        }

        let item = currentItem;
        while (item) {
            const parent = item.parentElement;
            if (!parent || !parent.classList.contains('fm-tree-children')) break;
            parent.classList.remove('fm-tree-closed');
            const parentItem = parent.previousElementSibling;
            if (parentItem) {
                const icon = parentItem.querySelector('.fm-tree-toggle i.bi');
                if (icon) {
                    icon.classList.remove('bi-chevron-right');
                    icon.classList.add('bi-chevron-down');
                }
            }
            item = parentItem;
        }
    }

    getExpandedPaths() {
        if (!this._el) return new Set();
        const expanded = new Set();
        this._el.querySelectorAll('.fm-tree-children:not(.fm-tree-closed)').forEach(function (children) {
            const parentItem = children.previousElementSibling;
            if (parentItem) {
                const link = parentItem.querySelector('.fm-tree-link');
                const path = link && link.getAttribute('data-path');
                if (path) expanded.add(norm(path));
            }
        });
        return expanded;
    }

    addFolder(parentPath, folderName, rootDir) {
        if (!this._data) return false;
        return this._addFolderRecursive(this._data, parentPath, folderName, rootDir);
    }

    removePaths(parentPath, namesToRemove) {
        if (!this._data) return;
        this._removePathsRecursive(this._data, parentPath, namesToRemove);
    }

    clearChildren(parentPath) {
        if (!this._data) return false;
        return this._clearChildrenRecursive(this._data, parentPath);
    }

    renameFolder(parentPath, oldName, newName, rootDir) {
        if (!this._data || !oldName || !newName || oldName === newName) return false;
        return this._renameFolderRecursive(this._data, parentPath, oldName, newName, rootDir);
    }

    replaceBranch(updatedNode) {
        if (!this._data) return false;
        return this._replaceBranchRecursive(this._data, updatedNode);
    }

    collapseAll(keepOpenPath) {
        if (!this._el) return;
        this._el.querySelectorAll('.fm-tree-children').forEach(function (children) {
            const parentItem = children.previousElementSibling;
            if (!parentItem || !parentItem.classList.contains('fm-tree-item')) return;
            const link = parentItem.querySelector('.fm-tree-link');
            const p    = link && link.getAttribute('data-path');
            const icon = parentItem.querySelector('.fm-tree-toggle i.bi');
            if (keepOpenPath && p && norm(p) === norm(keepOpenPath)) {
                children.classList.remove('fm-tree-closed');
                if (icon) { icon.classList.remove('bi-chevron-right'); icon.classList.add('bi-chevron-down'); }
            } else {
                children.classList.add('fm-tree-closed');
                if (icon) { icon.classList.remove('bi-chevron-down'); icon.classList.add('bi-chevron-right'); }
            }
        });
    }

    _renderNode(node, currentPath, depth, expandedPaths) {
        depth = depth || 0;
        const cp          = norm(currentPath || '');
        const np          = norm(node.path);
        const isCurrent   = !!(cp && np === cp);
        const hasKids     = !!(node.children && node.children.length > 0);
        const lazyTruncated = !!(node.is_truncated && !hasKids);
        const showToggle    = hasKids || lazyTruncated;
        const strictSet     = expandedPaths instanceof Set;
        const isAncestor    = !strictSet && !!(cp && (np === cp || (cp + '/').startsWith(np + '/')));
        const wasExpanded   = strictSet
            ? expandedPaths.has(np)
            : !!(expandedPaths && expandedPaths.has(np));
        const isOpen        = showToggle && (
            strictSet
                ? wasExpanded
                : (this._autoExpandAncestors ? (isCurrent || wasExpanded || isAncestor) : wasExpanded)
        );

        let html = '<div class="fm-tree-item" data-path="' + escapeHtml(node.path) + '" data-depth="' + depth + '"' +
            (lazyTruncated ? ' data-lazy-branch="1"' : '') + '>';
        html += '<span class="fm-tree-toggle">';
        if (showToggle) {
            html += isOpen ? '<i class="bi bi-chevron-down"></i>' : '<i class="bi bi-chevron-right"></i>';
        } else {
            html += '<span class="fm-tree-spacer"></span>';
        }
        html += '</span>';
        html += '<a href="#" class="fm-tree-link' + (isCurrent ? ' fm-tree-current' : '') + '" data-path="' + escapeHtml(node.path) + '">';
        if (node.name === 'ROOT') {
            html += '<i class="bi bi-house-fill"></i> - /';
        } else {
            html += '<i class="bi bi-folder-fill"></i> ' + escapeHtml(node.name);
        }
        html += '</a></div>';

        if (showToggle) {
            html += '<div class="fm-tree-children' + (isOpen ? '' : ' fm-tree-closed') + '">';
            if (hasKids) {
                const self = this;
                node.children.forEach(function (child) {
                    html += self._renderNode(child, currentPath, depth + 1, expandedPaths);
                });
            }
            html += '</div>';
        }

        return html;
    }

    _bindEvents() {
        if (!this._el) return;
        const self = this;

        this._el.querySelectorAll('.fm-tree-link').forEach(function (a) {
            a.addEventListener('click', function (e) {
                e.preventDefault();
                const path = a.getAttribute('data-path');
                if (path && self._onNodeClick) self._onNodeClick(path);
            });
        });

        this._el.querySelectorAll('.fm-tree-toggle').forEach(function (toggle) {
            if (toggle.querySelector('.fm-tree-spacer')) return;
            const item     = toggle.closest('.fm-tree-item');
            const children = item && item.nextElementSibling;
            toggle.addEventListener('click', function (e) {
                e.preventDefault();
                if (!children || !children.classList.contains('fm-tree-children')) return;
                const lazy       = item.getAttribute('data-lazy-branch') === '1';
                const opening    = children.classList.contains('fm-tree-closed');
                const emptyLazy  = lazy && opening && children.querySelectorAll('.fm-tree-item').length === 0;
                if (emptyLazy && typeof self._onLazyBranchExpand === 'function') {
                    self._onLazyBranchExpand(item.getAttribute('data-path'), item, children, toggle);
                    return;
                }
                children.classList.toggle('fm-tree-closed');
                const icon = toggle.querySelector('i.bi');
                if (icon) {
                    icon.classList.toggle('bi-chevron-right', children.classList.contains('fm-tree-closed'));
                    icon.classList.toggle('bi-chevron-down',  !children.classList.contains('fm-tree-closed'));
                }
            });
        });

    }

    _addFolderRecursive(node, parentPath, folderName, rootDir) {
        if (norm(node.path) === norm(parentPath)) {
            const newPath = fmJoinPath(parentPath, folderName);
            node.children = node.children || [];
            node.children.push({ name: folderName, path: newPath, children: [] });
            node.children.sort(function (a, b) {
                return a.name.localeCompare(b.name, undefined, { sensitivity: 'base' });
            });
            return true;
        }
        if (node.children && node.children.length) {
            for (let i = 0; i < node.children.length; i++) {
                if (this._addFolderRecursive(node.children[i], parentPath, folderName, rootDir)) return true;
            }
        }
        return false;
    }

    _removePathsRecursive(node, parentPath, namesToRemove) {
        if (norm(node.path) === norm(parentPath)) {
            node.children = (node.children || []).filter(function (c) {
                return !namesToRemove.has(c.name);
            });
            return;
        }
        if (node.children && node.children.length) {
            const self = this;
            node.children.forEach(function (c) {
                self._removePathsRecursive(c, parentPath, namesToRemove);
            });
        }
    }

    _clearChildrenRecursive(node, parentPath) {
        if (norm(node.path) === norm(parentPath)) {
            node.children = [];
            return true;
        }
        if (node.children && node.children.length) {
            for (let i = 0; i < node.children.length; i++) {
                if (this._clearChildrenRecursive(node.children[i], parentPath)) return true;
            }
        }
        return false;
    }

    _renameFolderRecursive(node, parentPath, oldName, newName, rootDir) {
        if (norm(node.path) !== norm(parentPath)) {
            if (node.children && node.children.length) {
                for (let i = 0; i < node.children.length; i++) {
                    const r = this._renameFolderRecursive(node.children[i], parentPath, oldName, newName, rootDir);
                    if (r) return r;
                }
            }
            return false;
        }
        const children = node.children || [];
        const idx = children.findIndex(function (c) { return c.name === oldName; });
        if (idx < 0) return false;
        const child = children[idx];
        const oldItemPath = child.path;
        const newItemPath = fmJoinPath(parentPath, newName);
        this._rewritePathsInSubtree(child, oldItemPath, newItemPath);
        child.name = newName;
        children.sort(function (a, b) {
            return a.name.localeCompare(b.name, undefined, { sensitivity: 'base' });
        });
        return { oldPath: oldItemPath, newPath: newItemPath };
    }

    _rewritePathsInSubtree(node, oldRoot, newRootFull) {
        const o     = norm(oldRoot);
        const nFull = newRootFull;
        const nBase = norm(nFull);
        function walk(nd) {
            const p  = nd.path;
            const pn = norm(p);
            if (pn === o) {
                nd.path = nFull;
            } else if (pn.startsWith(o + '/')) {
                nd.path = nBase + p.slice(o.length);
            }
            if (nd.children && nd.children.length) {
                nd.children.forEach(walk);
            }
        }
        walk(node);
    }

    _replaceBranchRecursive(node, updated) {
        if (!node || !updated) return false;
        if (norm(node.path) === norm(updated.path)) {
            node.children     = Array.isArray(updated.children) ? updated.children : [];
            node.is_truncated = !!updated.is_truncated;
            return true;
        }
        if (!node.children || !node.children.length) return false;
        for (let i = 0; i < node.children.length; i++) {
            if (this._replaceBranchRecursive(node.children[i], updated)) return true;
        }
        return false;
    }

    handleAction(actionId, payload) {}
}

class FmSidebarTree extends FmTree {
    static defaultDepth = 10;

    constructor({ el, ajaxUrl, rootDir, onNodeClick, collapseBtn } = {}) {
        super({
            el,
            onNodeClick,
            onLoadMore: (path) => this._handleLoadMore(path)
        });

        this._ajaxUrl     = ajaxUrl;
        this._rootDir     = rootDir;
        this._loadSeq     = 0;
        this._currentPath = '';

        this._onLazyBranchExpand = (path, item, children, toggle) => {
            this._fetchLazyBranch(path, item, children, toggle);
        };

        this._bindToolbarButtons(collapseBtn);
    }

    static _hookName(actionId) {
        return actionId
            .replace(/-([a-z])/g, (_, c) => c.toUpperCase())
            .replace(/^[a-z]/, c => c.toUpperCase());
    }

    _callHook(timing, actionId, context) {
        const name = timing + FmSidebarTree._hookName(actionId);
        if (typeof this[name] === 'function') this[name](context);
    }

    handleAction(actionId, payload) {
        this._callHook('before', actionId, payload);
        switch (actionId) {
            case 'collapse':
                this.collapseAll(this._rootDir);
                this._callHook('after', 'collapse', { currentPath: this._currentPath });
                return;
        }
    }

    _bindToolbarButtons(collapseBtn) {
        if (collapseBtn) collapseBtn.addEventListener('click', () => this.handleAction('collapse', {}));
    }

    render(currentPath, expandedPaths) {
        if (currentPath !== undefined) {
            this._currentPath = currentPath;
        }
        const pathForMb = (this._currentPath != null && this._currentPath !== '')
            ? this._currentPath
            : currentPath;
        let toExpand;
        if (expandedPaths !== undefined) {
            toExpand = new Set(expandedPaths);
            if (this._autoExpandAncestors && this._data && pathForMb) {
                FmTree.expandPathChainSet(pathForMb, this._data).forEach((p) => toExpand.add(p));
            }
        } else if (this._autoExpandAncestors && this._data && pathForMb) {
            toExpand = FmTree.expandPathChainSet(pathForMb, this._data);
        } else {
            toExpand = this.getExpandedPaths();
        }
        super.render(currentPath, toExpand);
        this._maybeLoadLazyCurrentBranch();
    }

    _maybeLoadLazyCurrentBranch() {
        if (!this._el || !this._currentPath) return;
        const link = this._el.querySelector('.fm-tree-link.fm-tree-current');
        if (!link) return;
        const item = link.closest('.fm-tree-item');
        if (!item || item.getAttribute('data-lazy-branch') !== '1') return;
        const children = item.nextElementSibling;
        if (!children || !children.classList.contains('fm-tree-children')) return;
        if (children.querySelector('.fm-tree-item')) return;
        if (item.getAttribute('data-lazy-loading') === '1') return;
        const path = item.getAttribute('data-path');
        const toggle = item.querySelector('.fm-tree-toggle');
        if (path && toggle) this._fetchLazyBranch(path, item, children, toggle);
    }

    _fetchLazyBranch(path, item, children, toggle) {
        if (!path || !item || !children || item.getAttribute('data-lazy-loading') === '1') return;
        item.setAttribute('data-lazy-loading', '1');
        children.classList.remove('fm-tree-closed');
        const icon = toggle && toggle.querySelector('i.bi');
        if (icon) {
            icon.classList.remove('bi-chevron-right');
            icon.classList.add('bi-chevron-down');
        }
        children.innerHTML =
            '<div class="fm-tree-lazy-loading"><span class="fm-sidebar-loading-spinner fm-tree-lazy-spinner" aria-hidden="true"></span></div>';

        this._requestFolderTreeBranch(path)
            .then((data) => {
                if (data && data.status === 'success' && data.tree) {
                    this.replaceBranch(data.tree);
                    this.render(this._currentPath);
                    return;
                }
                children.classList.add('fm-tree-closed');
                if (icon) {
                    icon.classList.remove('bi-chevron-down');
                    icon.classList.add('bi-chevron-right');
                }
                children.innerHTML = '';
            })
            .catch(() => {
                children.classList.add('fm-tree-closed');
                if (icon) {
                    icon.classList.remove('bi-chevron-down');
                    icon.classList.add('bi-chevron-right');
                }
                children.innerHTML = '';
            })
            .finally(() => {
                item.removeAttribute('data-lazy-loading');
            });
    }

    _requestFolderTreeBranch(path) {
        return requireAuthFetch(
            this._ajaxUrl + '?' + new URLSearchParams({ action: 'folder-tree-branch', folder: path })
        ).then((r) => r.json());
    }

    highlight(path) {
        if (!this._el || !path) return;
        this._el.querySelectorAll('.fm-tree-link').forEach(function (a) {
            if (norm(a.getAttribute('data-path')) === norm(path)) {
                a.classList.add('fm-tree-current');
            } else {
                a.classList.remove('fm-tree-current');
            }
        });
    }

    reloadFolderTree(path) {
        const p = path != null && path !== '' ? path : (this._currentPath || this._rootDir);
        this.load(
            p, 2,
            () => this.load(
                p, Math.ceil(this.constructor.defaultDepth / 2),
                () => this.load(p, this.constructor.defaultDepth, null, false),
            false)
        );
    }

    load(currentPath, depth = this.constructor.defaultDepth, onAfterLoad, showLoading = true) {
        if (!this._el) return;
        this._currentPath = currentPath;
        const seq = ++this._loadSeq;
        if (showLoading) {
            this._el.innerHTML = '<div class="fm-sidebar-loading-overlay"><div class="fm-sidebar-loading-spinner"></div></div>';
        }

        requireAuthFetch(this._ajaxUrl + '?' + new URLSearchParams({ action: 'folder-tree', depth: depth }))
            .then((r) => r.json())
            .then((shallowTree) => {
                if (seq !== this._loadSeq) return;
                this.setData(shallowTree, currentPath);
                if (onAfterLoad) onAfterLoad.call(this);
            })
            .catch(() => {
                if (seq !== this._loadSeq) return;
                this._el.innerHTML =
                    '<div class="fm-sidebar-loading-overlay fm-sidebar-loading-error">' +
                        '<p class="fm-sidebar-loading-error-text">Failed to load folders.</p>' +
                        '<button type="button" class="fm-sidebar-retry-btn" title="Refresh folder list">' +
                            '<i class="bi bi-arrow-clockwise" aria-hidden="true"></i>' +
                            '<span>Refresh</span>' +
                        '</button>' +
                    '</div>';
                const retryBtn = this._el.querySelector('.fm-sidebar-retry-btn');
                if (retryBtn) {
                    retryBtn.addEventListener('click', () => this.reloadFolderTree());
                }
                const link = this._el.querySelector('.fm-sidebar-error-root');
                if (link) {
                    link.addEventListener('click', (e) => {
                        e.preventDefault();
                        if (this._onNodeClick) this._onNodeClick(this._rootDir);
                    });
                }
            });
    }

    _handleLoadMore(path) {
        this._loadSeq++;
        this._requestFolderTreeBranch(path)
            .then((data) => {
                if (data.status !== 'success' || !data.tree) return;
                this.replaceBranch(data.tree);
                this.render(this._currentPath);
            })
            .catch(function () {});
    }
}

if (typeof window !== 'undefined') {
    window.FmTree        = FmTree;
    window.FmSidebarTree = FmSidebarTree;
}
/* ===== Table UI ===== */
const FM_DOWNLOAD_ACTION_TITLE =
    'Download — one file as-is; multiple items open a dialog (name + ZIP/TAR/GZIP), then download.';

const FM_CONTEXT_MENU_DOWNLOAD_LABEL = 'Download';

const FM_NOTICE_SUPPRESS_COPY_PATH_OK = 'fm_notice_suppress_copy_path_ok_1';

class FmTable {
    container = null;
    rows = [];
    selectedRows = [];
    tableEl = null;
    toolbarEl = null;
    _focusedIndex = -1;

    options = {
        columns:          [],
        actions:          [],
        onRowClick:       () => {},
        onRowDoubleClick: null,
        rowClass:         null,
        rowAttrs:         null,
        emptyText:        'No data',
        sortBy:           null,
        sortDir:          'asc',
        onSortChange:     null,
        pageUpDownStep:   null
    };

    constructor(container, rows = [], options = {}) {
        this.container = container;
        this.rows = rows;
        this.selectedRows   = [];
        this.tableEl        = null;
        this.toolbarEl      = null;

        this.options = {
            ...this.options,
            ...options
        };
    }

    sortRows(rows) {
        if (!this.options.sortBy) return [...rows];
        const dir = this.options.sortDir === 'asc' ? 1 : -1;
        const by  = this.options.sortBy;
        return [...rows].sort((a, b) => {
            const va = a[by] != null ? a[by] : '';
            const vb = b[by] != null ? b[by] : '';
            if (typeof va === 'number' && typeof vb === 'number') return dir * (va - vb);
            return dir * String(va).localeCompare(String(vb), undefined, { sensitivity: 'base' });
        });
    }

    handleSortClick(column) {
        if (this.options.sortBy === column) {
            this.options.sortDir = this.options.sortDir === 'asc' ? 'desc' : 'asc';
        } else {
            this.options.sortBy  = column;
            this.options.sortDir = 'asc';
        }
        if (this.options.onSortChange) this.options.onSortChange({ sortBy: this.options.sortBy, sortDir: this.options.sortDir });
        this.render();
    }

    renderToolbar() {
        const parts = [];
        this.options.actions.forEach((a, i) => {
            if (i > 0 && a.separatorBefore) {
                parts.push('<div class="fm-table-action-separator"></div>');
            }
            const tip = a.shortcut ? `${a.title} (${a.shortcut})` : a.title;
            parts.push(
                `<button type="button" class="fm-table-action-btn" data-action="${this.escapeAttr(a.id)}" title="${this.escapeAttr(tip)}"><i class="bi ${a.icon}"></i></button>`
            );
        });
        const toolbar = document.createElement('div');
        toolbar.className = 'fm-table-toolbar';
        toolbar.innerHTML = parts.join('');
        toolbar.querySelectorAll('.fm-table-action-btn').forEach(btn => {
            btn.addEventListener('click', (e) => {
                e.preventDefault();
                if (btn.classList.contains('disabled')) return;
                this.handleAction(btn.dataset.action, {
                    selectedRows: this.getSelectedRows(),
                    selectedRow:  this.getSelected(),
                });
            });
        });
        this.toolbarEl = toolbar;
        return toolbar;
    }

    handleAction(actionId, payload) {
        const methodName = `handle${actionId.replace(/(^|-)([a-z])/g, (_, __, c) => c.toUpperCase())}`;
        if (typeof this[methodName] === 'function') {
            this[methodName](payload);
            return;
        }
    }

    renderCell(col, row, index) {
        const val = row[col.key];
        return this.escapeHtml(String(val != null ? val : ''));
    }

    render() {
        this.rows = this.sortRows(this.rows);
        this.container.innerHTML = '';
        this.container.appendChild(this.renderToolbar());
        this._appendBeforeTable();

        if (this.rows.length === 0) {
            this.tableEl = null;
            const empty = document.createElement('div');
            empty.className = 'fm-table-empty';
            this._renderEmptyState(empty);
            this.container.appendChild(empty);
            this.updateActionsState();
            this._bindCustomEvents();
            return;
        }

        const sortIcon = (col) => {
            if (!col.sortable) return '';
            if (this.options.sortBy !== col.key) return '<i class="bi bi-chevron-expand fm-table-sort-indicator"></i>';
            return this.options.sortDir === 'asc'
                ? '<i class="bi bi-chevron-up fm-table-sort-indicator fm-table-sort-active"></i>'
                : '<i class="bi bi-chevron-down fm-table-sort-indicator fm-table-sort-active"></i>';
        };

        const thead = '<thead><tr>' +
            this.options.columns.map(col => {
                const classes = [col.className, col.sortable ? 'fm-table-sortable' : ''].filter(Boolean).join(' ');
                const cls     = classes ? ` class="${classes}"` : '';
                const attrs   = col.sortable ? ` data-sort="${this.escapeAttr(col.key)}" role="button" tabindex="0"` : '';
                return `<th${cls}${attrs}>${col.label || ''}${col.headerSuffix || ''}${sortIcon(col)}</th>`;
            }).join('') +
            '</tr></thead>';

        const tbodyRows = this.rows.map((row, index) => {
            const cells = this.options.columns.map(col => {
                const cls     = col.className ? ` class="${col.className}"` : '';
                const content = col.render ? col.render(row, index) : this.renderCell(col, row, index);
                return `<td${cls}>${content}</td>`;
            }).join('');

            const rowClass  = typeof this.options.rowClass === 'function' ? this.options.rowClass(row) : '';
            const attrsObj  = typeof this.options.rowAttrs === 'function' ? this.options.rowAttrs(row, index) : {};
            const attrsStr  = Object.entries(attrsObj).map(([k, v]) => `${k}="${this.escapeAttr(String(v))}"`).join(' ');

            return `<tr class="fm-table-row${rowClass ? ' ' + rowClass : ''}" data-index="${index}"${attrsStr ? ' ' + attrsStr : ''}>${cells}</tr>`;
        }).join('');

        const table = document.createElement('table');
        table.className = 'fm-table';
        table.innerHTML = thead + '<tbody>' + tbodyRows + '</tbody>';
        this.container.appendChild(table);
        this.tableEl = table;

        this._bindEvents();
        this._bindCustomEvents();
        this.updateActionsState();
    }

    _bindEvents() {
        if (!this.tableEl) return;
        this.tableEl.querySelectorAll('thead .fm-table-sortable').forEach((th) => {
            const column = th.dataset.sort;
            if (!column) return;
            th.addEventListener('click', () => this.handleSortClick(column));
            th.addEventListener('keydown', (e) => {
                if (e.key === 'Enter' || e.key === ' ') {
                    e.preventDefault();
                    this.handleSortClick(column);
                }
            });
        });
        this.tableEl.querySelectorAll('tbody tr').forEach((tr, index) => {
            tr.addEventListener('click',  (e) => this.handleRowClick(tr, index, e));
            tr.addEventListener('dblclick', (e) => {
                const row = this.rows[index];
                if (row && this.options.onRowDoubleClick) this.options.onRowDoubleClick(row, e);
            });
        });
    }

    _appendBeforeTable() {}

    _renderEmptyState(el) {
        el.textContent = this.options.emptyText;
    }

    _bindCustomEvents() {}

    handleRowClick(tr, index, e) {
        const row = this.rows[index];
        this._focusRow(index, false);
        if (e && e.shiftKey && this._selectionAnchor != null) {
            this._extendSelectionTo(index);
        } else if (e && e.ctrlKey) {
            const idx = this.selectedRows.findIndex(r => r === row);
            if (idx >= 0) {
                this.selectedRows.splice(idx, 1);
                tr.classList.remove('fm-table-row-selected');
            } else {
                this.selectedRows.push(row);
                tr.classList.add('fm-table-row-selected');
            }
            this._selectionAnchor = index;
            this.updateActionsState();
            this.options.onRowClick(row, e);
        } else {
            this.container.querySelectorAll('.fm-table-row-selected').forEach(el => el.classList.remove('fm-table-row-selected'));
            this.selectedRows = [row];
            tr.classList.add('fm-table-row-selected');
            this._selectionAnchor = index;
            this.updateActionsState();
            this.options.onRowClick(row, e);
        }
    }

    _focusRow(index, scrollIntoView = true) {
        if (!this.tableEl) return;
        this.tableEl.querySelectorAll('.fm-table-row-focused').forEach(el => el.classList.remove('fm-table-row-focused'));
        this._focusedIndex = index;
        if (index < 0 || index >= this.rows.length) return;
        const tr = this.tableEl.querySelector(`tbody tr[data-index="${index}"]`);
        if (tr) {
            tr.classList.add('fm-table-row-focused');
            if (scrollIntoView) tr.scrollIntoView({ block: 'nearest' });
        }
    }

    _selectRow(index) {
        if (!this.tableEl) return;
        this.container.querySelectorAll('.fm-table-row-selected').forEach(el => el.classList.remove('fm-table-row-selected'));
        this.selectedRows = [];
        const row = this.rows[index];
        if (!row) return;
        this.selectedRows = [row];
        const tr = this.tableEl.querySelector(`tbody tr[data-index="${index}"]`);
        if (tr) tr.classList.add('fm-table-row-selected');
        this.updateActionsState();
        this.options.onRowClick(row, null);
    }

    _extendSelectionTo(index) {
        if (!this.tableEl) return;
        const anchor = this._selectionAnchor ?? this._focusedIndex;
        const lo = Math.min(anchor, index);
        const hi = Math.max(anchor, index);
        this.container.querySelectorAll('.fm-table-row-selected').forEach(el => el.classList.remove('fm-table-row-selected'));
        this.selectedRows = [];
        for (let i = lo; i <= hi; i++) {
            const row = this.rows[i];
            if (!row) continue;
            this.selectedRows.push(row);
            const tr = this.tableEl.querySelector(`tbody tr[data-index="${i}"]`);
            if (tr) tr.classList.add('fm-table-row-selected');
        }
        this.updateActionsState();
    }

    _selectAllRows() {
        if (!this.tableEl) return;
        this.container.querySelectorAll('.fm-table-row-selected').forEach(el => el.classList.remove('fm-table-row-selected'));
        this.selectedRows = [];
        let firstIdx = -1;
        let lastIdx = -1;
        this.rows.forEach((row, i) => {
            if (firstIdx < 0) firstIdx = i;
            lastIdx = i;
            this.selectedRows.push(row);
            const tr = this.tableEl.querySelector(`tbody tr[data-index="${i}"]`);
            if (tr) tr.classList.add('fm-table-row-selected');
        });
        if (firstIdx >= 0) {
            this._selectionAnchor = firstIdx;
            this._focusedIndex = lastIdx;
            this._focusRow(lastIdx, false);
        } else {
            this._selectionAnchor = null;
            this._focusedIndex = -1;
            this.tableEl.querySelectorAll('.fm-table-row-focused').forEach(el => el.classList.remove('fm-table-row-focused'));
        }
        this.updateActionsState();
    }

    _selectNone() {
        if (!this.tableEl) return;
        this.container.querySelectorAll('.fm-table-row-selected').forEach((el) => el.classList.remove('fm-table-row-selected'));
        this.selectedRows = [];
        if (this.rows.length === 0) {
            this._focusedIndex = -1;
            this._selectionAnchor = null;
            this.tableEl.querySelectorAll('.fm-table-row-focused').forEach((el) => el.classList.remove('fm-table-row-focused'));
        } else {
            let idx = this._focusedIndex;
            if (idx < 0 || idx >= this.rows.length) idx = 0;
            this._selectionAnchor = idx;
            this._focusRow(idx);
        }
        this.updateActionsState();
    }

    _invertSelection() {
        if (!this.tableEl || this.rows.length === 0) return;
        const selectedSet = new Set(this.selectedRows);
        this.container.querySelectorAll('.fm-table-row-selected').forEach((el) => el.classList.remove('fm-table-row-selected'));
        this.selectedRows = [];
        this.rows.forEach((row, i) => {
            if (!selectedSet.has(row)) {
                this.selectedRows.push(row);
                const tr = this.tableEl.querySelector(`tbody tr[data-index="${i}"]`);
                if (tr) tr.classList.add('fm-table-row-selected');
            }
        });
        if (this.selectedRows.length > 0) {
            const lastIdx = this.rows.indexOf(this.selectedRows[this.selectedRows.length - 1]);
            this._selectionAnchor = lastIdx;
            this._focusedIndex = lastIdx;
            this._focusRow(lastIdx, false);
        } else {
            let idx = this._focusedIndex;
            if (idx < 0 || idx >= this.rows.length) idx = 0;
            this._selectionAnchor = idx;
            this._focusRow(idx);
        }
        this.updateActionsState();
    }

    _pageSize() {
        const raw = this.options.pageUpDownStep;
        const n   = parseInt(raw, 10);
        if (Number.isFinite(n) && n >= 1) {
            return Math.min(500, n);
        }
        return 10;
    }

    updateActionsState() {
        const toolbar = this.toolbarEl;
        if (!toolbar) return;
        const selected       = this.getSelectedRows();
        const hasSelection   = selected.length > 0;
        const singleSelection = selected.length === 1;
        this.options.actions.forEach(a => {
            const btn = toolbar.querySelector(`[data-action="${a.id}"]`);
            if (!btn) return;
            let disabled = false;
            if (a.needSelection)              disabled = !hasSelection;
            if (a.singleOnly && hasSelection) disabled = !singleSelection;
            btn.classList.toggle('disabled', disabled);
        });
    }

    setData(rows) {
        this.rows = rows || [];
        this.selectedRows = [];
        this._focusedIndex = -1;
        this._selectionAnchor = null;
        this.render();
    }

    getSelected()     { return this.selectedRows.length ? this.selectedRows[this.selectedRows.length - 1] : null; }
    getSelectedRows() { return this.selectedRows.slice(); }
    getRows()         { return this.rows; }

    escapeHtml(str) {
        const div = document.createElement('div');
        div.textContent = str;
        return div.innerHTML;
    }

    escapeAttr(str) {
        return this.escapeHtml(str).replace(/"/g, '&quot;');
    }
}

class FmFileManagerTable extends FmTable {
    static ACTIONS = [
        { id: 'create-new-folder', icon: 'bi-folder-plus',         title: 'Create new folder', needSelection: false },
        { id: 'create-new-file',   icon: 'bi-file-earmark-plus',   title: 'Create new file',   needSelection: false },
        { id: 'edit-file',         icon: 'bi-pencil-square',       title: 'Edit file',         needSelection: true,  singleOnly: true, separatorBefore: true },
        { id: 'delete',            icon: 'bi-trash',               title: 'Delete',            needSelection: true,  separatorBefore: true },
        { id: 'restore-trashed',   icon: 'bi-arrow-counterclockwise', title: 'Restore',       needSelection: true,  separatorBefore: true },
        { id: 'copy',              icon: 'bi-copy',                title: 'Copy',              needSelection: true,  separatorBefore: true },
        { id: 'move',              icon: 'bi-arrow-right-square',  title: 'Move',              needSelection: true },
        { id: 'duplicate',       icon: 'bi-files',               title: 'Duplicate',         needSelection: true },
        { id: 'new-folder-from-selection', icon: 'bi-folder-symlink', title: 'New folder from selection', needSelection: true },
        { id: 'copy-path',       icon: 'bi-clipboard',           title: 'Copy path',         needSelection: true,  separatorBefore: true },
        { id: 'copy-relative-path', icon: 'bi-clipboard2',       title: 'Copy relative path', needSelection: true },
        { id: 'rename',            icon: 'bi-input-cursor-text',   title: 'Rename',            needSelection: true,  singleOnly: true, separatorBefore: true },
        { id: 'bulk-rename',       icon: 'bi-input-cursor',        title: 'Bulk rename',       needSelection: true },
        { id: 'get-info',          icon: 'bi-info-circle',         title: 'Get info',          needSelection: true,  singleOnly: true },
        { id: 'change-permissions', icon: 'bi-shield-lock',        title: 'Change permissions', needSelection: true },
        { id: 'compress',          icon: 'bi-arrows-collapse',     title: 'Archive',           needSelection: true,  separatorBefore: true },
        { id: 'extract',           icon: 'bi-arrows-expand',       title: 'Extract',           needSelection: true,  requiresArchive: true },
        { id: 'refresh',           icon: 'bi-arrow-clockwise',     title: 'Refresh',           needSelection: false, separatorBefore: true },
        { id: 'upload',            icon: 'bi-upload',              title: 'Upload',            needSelection: false, separatorBefore: true },
        { id: 'upload-url',        icon: 'bi-link-45deg',          title: 'Upload from URL',   needSelection: false },
        { id: 'download',          icon: 'bi-download',            title: FM_DOWNLOAD_ACTION_TITLE, needSelection: true },
        { id: 'open-in-new-tab',   icon: 'bi-box-arrow-up-right',  title: 'Open in new tab',       needSelection: true, singleOnly: true },
        { id: 'terminal-here',     icon: 'bi-terminal',            title: 'Open terminal / shell command', needSelection: false, singleOnly: true },
    ];

    static BREADCRUMB_COLLAPSE_THRESHOLD = 5;
    static BREADCRUMB_TAIL_COUNT = 3;

    static ACTION_TOOLTIP_SHORTCUT = {
        'create-new-folder': 'Ctrl + Alt + D',
        'create-new-file':   'Ctrl + Alt + F',
        'edit-file':         'F4 / Ctrl + Alt + V',
        'delete':            'Delete / Shift + Delete',
        'restore-trashed':   'Ctrl + Alt + R',
        'copy':              'Ctrl + Alt + C',
        'move':              'Ctrl + Alt + M / Ctrl + Alt + X',
        'duplicate':         'Ctrl + Alt + U',
        'new-folder-from-selection': 'Ctrl + Alt + N',
        'copy-path':         'Ctrl + Alt + L',
        'copy-relative-path': 'Ctrl + Alt + Shift + L',
        'bookmark-pin-current': 'Ctrl + Alt + B',
        'open-in-new-tab':   'Ctrl + Alt + Enter',
        'terminal-here':     'Ctrl + Alt + T',
        'select-none':       'Escape',
        'invert-selection': 'Ctrl + Alt + I',
        'rename':            'F2',
        'get-info':          'Alt + Enter',
        'compress':          'Ctrl + Alt + A',
        'extract':           'Ctrl + Alt + E',
        'open':              'Enter',
        'refresh':           'F5',
    };

    static TRASH_VIEW_ACTIONS = new Set([
        'refresh', 'delete', 'restore-trashed', 'empty-trash', 'select-none', 'invert-selection',
    ]);

    static KEYBOARD_SHORTCUTS_HELP_SECTIONS = [
        {
            title: 'Navigation & list',
            rows: [
                ['Alt + \u2190 / \u2192', 'Go back or forward in folder history'],
                ['\u2191 \u2193 Home End PgUp PgDn', 'Move selection (Shift + arrow keys extend selection)'],
                ['Enter', 'Open folder or file'],
                ['Backspace / Alt + \u2191', 'Go to parent folder'],
            ],
        },
        {
            title: 'Selection',
            rows: [
                ['Ctrl + A (\u2318 + A on Mac)', 'Select all items in the folder'],
                ['Escape', 'Clear selection (when items are selected)'],
                ['Ctrl + Alt + I', 'Invert selection in the current folder'],
            ],
        },
        {
            title: 'Context & properties',
            rows: [
                ['Shift + F10', 'Open context menu for focused row'],
                ['F2', 'Rename selected item'],
                ['F4 / Ctrl + Alt + V', 'Edit selected text/code file'],
                ['F5', 'Refresh the current folder'],
                ['Alt + Enter', 'Get info for selected item'],
            ],
        },
        {
            title: 'Delete',
            rows: [
                ['Delete', 'Delete selected items (move to Trash unless Delete forever is checked)'],
                ['Shift + Delete', 'Open delete dialog with Delete forever enabled'],
            ],
        },
        {
            title: 'Commands (Ctrl + Alt + …)',
            rows: [
                ['Ctrl + Alt + F', 'Create new file'],
                ['Ctrl + Alt + D', 'Create new folder'],
                ['Ctrl + Alt + A', 'Archive selected items'],
                ['Ctrl + Alt + E', 'Extract selected items'],
                ['Ctrl + Alt + C', 'Copy selected items to another folder'],
                ['Ctrl + Alt + M / Ctrl + Alt + X', 'Move selected items to another folder'],
                ['Ctrl + Alt + U', 'Duplicate selected items in this folder (new names: (1), (2), …)'],
                ['Ctrl + Alt + N', 'Create a new subfolder and move selected items into it'],
                ['Ctrl + Alt + L', 'Copy full server path(s) for selected item(s) to clipboard'],
                ['Ctrl + Alt + Shift + L', 'Copy path(s) relative to root to clipboard'],
                ['Ctrl + Alt + Enter', 'Open selected folder or file in a new tab'],
                ['Ctrl + Alt + B', 'Bookmark the current folder'],
                ['Ctrl + Alt + T', 'Open terminal / shell command in current folder'],
                ['Ctrl + Alt + R', 'Restore selected items to where they were deleted from (Trash only)'],
            ],
        },
    ];

    static bytesToSize(bytes, decimals = 2) {
        if (bytes == null || !Number(bytes)) return '0 Bytes';
        const kb    = 1024;
        const dm    = decimals < 0 ? 0 : decimals;
        const sizes = ['Bytes', 'KiB', 'MiB', 'GiB', 'TiB', 'PiB', 'EiB', 'ZiB', 'YiB'];
        const i     = Math.floor(Math.log(bytes) / Math.log(kb));
        return `${parseFloat((bytes / Math.pow(kb, i)).toFixed(dm))} ${sizes[i]}`;
    }

    static FILE_ICON_BY_EXT = {
        jpg: 'bi-file-earmark-image-fill', jpeg: 'bi-file-earmark-image-fill', png: 'bi-file-earmark-image-fill',
        gif: 'bi-file-earmark-image-fill', webp: 'bi-file-earmark-image-fill', svg: 'bi-file-earmark-image-fill',
        bmp: 'bi-file-earmark-image-fill', ico: 'bi-file-earmark-image-fill', tif: 'bi-file-earmark-image-fill',
        tiff: 'bi-file-earmark-image-fill', avif: 'bi-file-earmark-image-fill', heic: 'bi-file-earmark-image-fill',
        mp4: 'bi-file-earmark-play-fill', webm: 'bi-file-earmark-play-fill', mov: 'bi-file-earmark-play-fill',
        mkv: 'bi-file-earmark-play-fill', avi: 'bi-file-earmark-play-fill', m4v: 'bi-file-earmark-play-fill',
        wmv: 'bi-file-earmark-play-fill', flv: 'bi-file-earmark-play-fill',
        mp3: 'bi-file-earmark-music-fill', wav: 'bi-file-earmark-music-fill', ogg: 'bi-file-earmark-music-fill',
        opus: 'bi-file-earmark-music-fill', flac: 'bi-file-earmark-music-fill', m4a: 'bi-file-earmark-music-fill',
        aac: 'bi-file-earmark-music-fill', wma: 'bi-file-earmark-music-fill',
        pdf: 'bi-file-earmark-pdf-fill',
        zip: 'bi-file-earmark-zip-fill', '7z': 'bi-file-earmark-zip-fill', rar: 'bi-file-earmark-zip-fill',
        gz: 'bi-file-earmark-zip-fill', bz2: 'bi-file-earmark-zip-fill', xz: 'bi-file-earmark-zip-fill',
        doc: 'bi-file-earmark-word-fill', docx: 'bi-file-earmark-word-fill', odt: 'bi-file-earmark-word-fill',
        xls: 'bi-file-earmark-excel-fill', xlsx: 'bi-file-earmark-excel-fill', ods: 'bi-file-earmark-excel-fill',
        ppt: 'bi-file-earmark-ppt-fill', pptx: 'bi-file-earmark-ppt-fill', odp: 'bi-file-earmark-ppt-fill',
        rtf: 'bi-file-earmark-text-fill',
        js: 'bi-file-earmark-code-fill', mjs: 'bi-file-earmark-code-fill', cjs: 'bi-file-earmark-code-fill',
        ts: 'bi-file-earmark-code-fill', tsx: 'bi-file-earmark-code-fill', jsx: 'bi-file-earmark-code-fill',
        vue: 'bi-file-earmark-code-fill', svelte: 'bi-file-earmark-code-fill',
        css: 'bi-file-earmark-code-fill', scss: 'bi-file-earmark-code-fill', less: 'bi-file-earmark-code-fill',
        html: 'bi-file-earmark-code-fill', htm: 'bi-file-earmark-code-fill', xhtml: 'bi-file-earmark-code-fill',
        php: 'bi-file-earmark-code-fill', py: 'bi-file-earmark-code-fill', rb: 'bi-file-earmark-code-fill',
        go: 'bi-file-earmark-code-fill', rs: 'bi-file-earmark-code-fill', java: 'bi-file-earmark-code-fill',
        kt: 'bi-file-earmark-code-fill', swift: 'bi-file-earmark-code-fill', c: 'bi-file-earmark-code-fill',
        cc: 'bi-file-earmark-code-fill', cpp: 'bi-file-earmark-code-fill', h: 'bi-file-earmark-code-fill',
        hpp: 'bi-file-earmark-code-fill', cs: 'bi-file-earmark-code-fill', sql: 'bi-file-earmark-code-fill',
        sh: 'bi-file-earmark-code-fill', bash: 'bi-file-earmark-code-fill', zsh: 'bi-file-earmark-code-fill',
        ps1: 'bi-file-earmark-code-fill', yaml: 'bi-file-earmark-code-fill', yml: 'bi-file-earmark-code-fill',
        toml: 'bi-file-earmark-code-fill', ini: 'bi-file-earmark-code-fill', cfg: 'bi-file-earmark-code-fill',
        txt: 'bi-file-earmark-text-fill', text: 'bi-file-earmark-text-fill', md: 'bi-file-earmark-text-fill',
        markdown: 'bi-file-earmark-text-fill', log: 'bi-file-earmark-text-fill', csv: 'bi-file-earmark-text-fill',
        tsv: 'bi-file-earmark-text-fill', json: 'bi-file-earmark-text-fill', xml: 'bi-file-earmark-text-fill',
    };

    static IMAGE_PREVIEW_EXT = new Set([
        'jpg', 'jpeg', 'png', 'gif', 'webp', 'svg', 'bmp', 'ico', 'tif', 'tiff', 'avif', 'heic',
    ]);

    static isImagePreviewExt(ext, name) {
        let e = ext != null ? String(ext).trim().toLowerCase() : '';
        if (e.startsWith('.')) e = e.slice(1);
        if (!e && name) {
            const base = String(name).split('/').pop() || '';
            const m = base.match(/\.([a-z0-9]+)$/i);
            if (m) e = m[1].toLowerCase();
        }
        return !!(e && FmFileManagerTable.IMAGE_PREVIEW_EXT.has(e));
    }

    static getFileIcon(ext, name) {
        if (name && FmFileManagerTable.isArchiveFile(name)) {
            return '<i class="bi bi-file-earmark-zip-fill"></i>';
        }
        let e = ext != null ? String(ext).trim().toLowerCase() : '';
        if (e.startsWith('.')) e = e.slice(1);
        if (!e && name) {
            const base = String(name).split('/').pop() || '';
            const m = base.match(/\.([a-z0-9]+)$/i);
            if (m) e = m[1].toLowerCase();
        }
        const cls = (e && FmFileManagerTable.FILE_ICON_BY_EXT[e]) || 'bi-file-earmark-fill';
        return `<i class="bi ${cls}"></i>`;
    }

    static formatMtime(timestamp) {
        if (timestamp == null || !Number(timestamp)) return '—';
        const d = new Date(timestamp * 1000);
        return d.toLocaleString(undefined, { dateStyle: 'short', timeStyle: 'short' }).replace(/\r?\n/g, ' ').trim();
    }

    static isArchiveFile(name) {
        if (!name || typeof name !== 'string') return false;
        return /\.(zip|tar\.gz|tgz)$/i.test(name) || /\.tar$/i.test(name);
    }

    static octalPermToSymbolic(octalStr, isDirectory) {
        if (octalStr == null || String(octalStr).trim() === '') return '';
        const n = parseInt(String(octalStr).trim(), 8);
        if (Number.isNaN(n)) return '';
        const mode = n & 0o777;
        const type = isDirectory ? 'd' : '-';
        const tri = (v) => {
            const x = v & 7;
            return (x & 4 ? 'r' : '-') + (x & 2 ? 'w' : '-') + (x & 1 ? 'x' : '-');
        };
        return type + tri(mode >> 6) + tri(mode >> 3) + tri(mode);
    }

    static formatPermissionsCell(octalStr, rowType, display) {
        if (octalStr == null || String(octalStr).trim() === '') return '—';
        const oct = String(octalStr).trim();
        const sym = FmFileManagerTable.octalPermToSymbolic(octalStr, rowType === 'folder');
        if (display === 'symbolic') return sym || oct;
        return oct;
    }

    static _buildColumns(config) {
        const cols = [
            { key: 'icon', label: '', sortable: false, className: 'fm-table-col-icon' },
            { key: 'name', label: 'Name', sortable: true, className: 'fm-table-col-name' },
            { key: 'size', label: 'Size', sortable: true, className: 'fm-table-col-size fm-table-size',
              headerSuffix: '<button type="button" class="fm-table-size-all-btn" title="Calculate size for all folders"><i class="bi bi-arrow-clockwise"></i></button>' },
            { key: 'type', label: 'Type', sortable: true, className: 'fm-table-col-type fm-table-type' },
        ];
        if (config.showLastModifiedColumn) {
            cols.push({ key: 'mtime', label: 'Last modified', sortable: true, className: 'fm-table-col-mtime fm-table-mtime' });
        }
        cols.push({ key: 'permissions', label: 'Perms', sortable: true, className: 'fm-table-col-permissions fm-table-permissions' });
        if (config.showActionsColumn) {
            cols.push({ key: 'actions', label: 'Actions', sortable: false, className: 'fm-table-col-actions' });
        }
        return cols;
    }

    constructor(container, rows, options = {}) {
        const sc      = FmFileManagerTable.ACTION_TOOLTIP_SHORTCUT;
        const actions = FmFileManagerTable.ACTIONS.filter(a =>
            ['create-new-folder', 'create-new-file', 'edit-file', 'rename', 'bulk-rename', 'delete', 'restore-trashed', 'copy', 'move', 'duplicate', 'new-folder-from-selection', 'copy-path', 'copy-relative-path', 'get-info', 'change-permissions', 'compress', 'extract', 'refresh', 'download', 'open-in-new-tab', 'terminal-here', 'upload', 'upload-url'].includes(a.id)
        ).map(a => {
            const shortcut = sc[a.id];
            return shortcut ? { ...a, shortcut } : a;
        });
        super(container, rows, {
            actions,
            onRowClick:       (row, e) => this._handleRowNav(row, e),
            onRowDoubleClick: (row, e) => this._handleRowDoubleClickNav(row, e),
            rowClass:         (row) => (row.type === 'folder' ? 'fm-table-row-folder' : 'fm-table-row-file'),
            rowAttrs:         (row) => ({ 'data-name': row.name, 'data-type': row.type }),
            emptyText:        'No files or folders',
            sortBy:           options.sortBy  || 'name',
            sortDir:          options.sortDir || 'asc',
            onSortChange:     options.onSortChange || null,
        });

        this._ajaxUrl     = options.ajaxUrl;
        this._rootDir     = options.rootDir;
        this._currentPath = options.rootDir;
        this._onNavigate  = options.onNavigate || null;
        this._onTrashCount = options.onTrashCount || null;
        this._data        = null;

        this._invalidFolderMessage = null;
        this._breadcrumbMidExpanded = false;
        this._nameFilter = '';
        this._allRows = [];
        this._filterKeepFocus = false;
        this._filterSelStart = null;
        this._filterSelEnd = null;

        this._navBackStack = [];
        this._navForwardStack = [];
        this._pendingRestoreChildName = null;
        this._pendingKeyboardAnchor = null;

        this._execAvailable = typeof options.execAvailable === 'boolean'
            ? options.execAvailable
            : (typeof fm_exec_available !== 'undefined' ? fm_exec_available : true);

        this.options.showActionsColumn = options.showActionsColumn || true;
        this.options.showLastModifiedColumn = options.showLastModifiedColumn || true;
        this.options.automaticGetFoldersSize = options.automaticGetFoldersSize || false;
        this.options.permissionsDisplay = ['octal', 'symbolic', 'both'].includes(options.permissionsDisplay)
            ? options.permissionsDisplay
            : 'octal';
        this.options.showImagePreviews = options.showImagePreviews === true;

        this._initKeyboardShortcuts();
        this._initDragAndDrop();
        this._boundContainerContextMenu = this._onContainerContextMenu.bind(this);
        this._boundContextMenuDismiss = this._onContextMenuDismiss.bind(this);
        this._contextMenuEl = null;
        this._containerContextMenuBound = false;
    }

    _bindEvents() {
        super._bindEvents();
        if (!this.tableEl) return;
        this.tableEl.querySelectorAll('tbody tr').forEach((tr) => {
            tr.setAttribute('draggable', 'true');
        });
    }

    static DND_MIME = 'application/x-fm-dnd';

    _initDragAndDrop() {
        this._dndDragPayload = null;
        this._dndDropHighlightEl = null;
        this._boundDnDDragStart = this._onDnDDragStart.bind(this);
        this._boundDnDDragEnd = this._onDnDDragEnd.bind(this);
        this._boundDnDDragOver = this._onDnDDragOver.bind(this);
        this._boundDnDDragLeave = this._onDnDDragLeave.bind(this);
        this._boundDnDDrop = this._onDnDDrop.bind(this);
        this.container.addEventListener('dragstart', this._boundDnDDragStart);
        this.container.addEventListener('dragend', this._boundDnDDragEnd);
        this.container.addEventListener('dragover', this._boundDnDDragOver);
        this.container.addEventListener('dragleave', this._boundDnDDragLeave);
        this.container.addEventListener('drop', this._boundDnDDrop);
    }

    attachSidebarDnD(treeEl) {
        if (!treeEl || treeEl._fmDnDAttached) return;
        treeEl._fmDnDAttached = true;
        treeEl.addEventListener('dragenter', (e) => {
            if (!this._dndHasOurPayload(e)) return;
            const a = e.target.closest('.fm-tree-link');
            if (!a) return;
            e.preventDefault();
        });
        treeEl.addEventListener('dragover', (e) => {
            if (!this._dndHasOurPayload(e)) return;
            const a = e.target.closest('.fm-tree-link');
            if (!a) return;
            e.preventDefault();
            e.dataTransfer.dropEffect = e.ctrlKey ? 'copy' : 'move';
            this._dndSetHighlight(a);
        });
        treeEl.addEventListener('dragleave', (e) => {
            const a = e.target.closest('.fm-tree-link');
            if (a && !a.contains(e.relatedTarget)) this._dndClearHighlight();
        });
        treeEl.addEventListener('drop', (e) => {
            if (!this._dndHasOurPayload(e)) return;
            const a = e.target.closest('.fm-tree-link');
            if (!a) return;
            e.preventDefault();
            this._dndClearHighlight();
            const path = a.getAttribute('data-path');
            if (path) this._executeDnDDropToPath(path, e.ctrlKey, e.dataTransfer);
        });
    }

    _dndHasOurPayload(e) {
        return e.dataTransfer && e.dataTransfer.types.includes(FmFileManagerTable.DND_MIME);
    }

    _dndAllowed() {
        if (this._invalidFolderMessage) return false;
        if (this._pathIsInTrash(this._currentPath)) return false;
        return true;
    }

    _onDnDDragStart(e) {
        if (document.querySelector('.fm-js-popup.show')) {
            e.preventDefault();
            return;
        }
        const tr = e.target.closest('tbody tr');
        if (!tr || !this.tableEl) return;
        if (e.target.closest('.fm-table-row-action') || e.target.closest('.fm-table-folder-size-trigger')) {
            e.preventDefault();
            return;
        }
        if (!this._dndAllowed()) {
            e.preventDefault();
            return;
        }
        const index = parseInt(tr.getAttribute('data-index'), 10);
        const row = this.rows[index];
        if (!row) {
            e.preventDefault();
            return;
        }
        const sel = this.getSelectedRows();
        const inSel = sel.some((r) => r === row);
        let names;
        const sourceRowsByName = {};
        if (inSel && sel.length) {
            names = sel.map((r) => r.name);
            sel.forEach((r) => { sourceRowsByName[r.name] = r; });
        } else {
            names = [row.name];
            sourceRowsByName[row.name] = row;
        }
        const payload = { names, sourcePath: this._currentPath };
        this._dndDragPayload = { names, sourcePath: this._currentPath, sourceRowsByName };
        e.dataTransfer.setData(FmFileManagerTable.DND_MIME, JSON.stringify(payload));
        e.dataTransfer.effectAllowed = 'copyMove';
        try {
            e.dataTransfer.setData('text/plain', names.join(', '));
        } catch (err) {}
    }

    _onDnDDragEnd() {
        this._dndClearHighlight();
        this._dndDragPayload = null;
    }

    _onDnDDragOver(e) {
        if (!this._dndHasOurPayload(e)) return;
        const t = this._dndResolveDropTarget(e.target);
        if (!t) {
            e.dataTransfer.dropEffect = 'none';
            return;
        }
        e.preventDefault();
        e.dataTransfer.dropEffect = e.ctrlKey ? 'copy' : 'move';
        this._dndSetHighlight(t.el);
    }

    _onDnDDragLeave(e) {
        if (!this._dndHasOurPayload(e)) return;
        const le = e.target;
        const rel = e.relatedTarget;
        if (le && rel && le.contains && le.contains(rel)) return;
        if (this._dndDropHighlightEl && le === this._dndDropHighlightEl && (!rel || !this._dndDropHighlightEl.contains(rel))) {
            this._dndClearHighlight();
        }
    }

    _onDnDDrop(e) {
        if (!this._dndHasOurPayload(e)) return;
        const t = this._dndResolveDropTarget(e.target);
        if (!t) return;
        e.preventDefault();
        this._dndClearHighlight();
        this._executeDnDDropToPath(t.path, e.ctrlKey, e.dataTransfer);
    }

    _dndResolveDropTarget(target) {
        if (!target || !target.closest) return null;
        const el = target;
        const tr = el.closest('tbody tr');
        if (tr && this.tableEl && this.tableEl.contains(tr)) {
            const idx = parseInt(tr.getAttribute('data-index'), 10);
            const row = this.rows[idx];
            if (!row || row.type !== 'folder') return null;
            const destAbs = norm(fmJoinPath(this._currentPath, row.name));
            return { path: destAbs, el: tr };
        }
        const crumb = el.closest('.fm-breadcrumb-crumb[data-path]');
        if (crumb && this.container.contains(crumb)) {
            const p = crumb.getAttribute('data-path');
            if (!p) return null;
            return { path: norm(p), el: crumb };
        }
        return null;
    }

    _dndSetHighlight(el) {
        if (this._dndDropHighlightEl === el) return;
        this._dndClearHighlight();
        this._dndDropHighlightEl = el;
        el.classList.add('fm-dnd-drag-over');
    }

    _dndClearHighlight() {
        if (this._dndDropHighlightEl) {
            this._dndDropHighlightEl.classList.remove('fm-dnd-drag-over');
            this._dndDropHighlightEl = null;
        }
    }

    _executeDnDDropToPath(destAbs, copyModifier, dataTransfer) {
        let payload = this._dndDragPayload;
        if (!payload && dataTransfer) {
            try {
                const raw = dataTransfer.getData(FmFileManagerTable.DND_MIME);
                if (raw) payload = JSON.parse(raw);
            } catch (e) {}
        }
        if (!payload || !payload.names || !payload.names.length) return;

        if (!payload.sourceRowsByName) {
            payload.sourceRowsByName = {};
            payload.names.forEach((n) => {
                const r = this.rows.find((x) => x.name === n);
                if (r) payload.sourceRowsByName[n] = r;
            });
        }

        const operation = copyModifier ? 'copy' : 'move';
        const srcPath = norm(String(payload.sourcePath || ''));
        const dstPath = norm(String(destAbs || ''));

        if (srcPath === dstPath) {
            if (typeof fmUserNotice === 'function') {
                fmUserNotice({
                    variant: 'warning',
                    title:   operation === 'move' ? 'Move' : 'Copy',
                    message: 'Pick a different folder (same folder would overwrite names).',
                });
            }
            return;
        }

        const names = payload.names;
        if (operation === 'move') {
            const prefix = srcPath === '/' ? '/' : srcPath + '/';
            const seg = dstPath.startsWith(prefix) ? dstPath.slice(prefix.length).split('/')[0] : '';
            if (seg && names.includes(seg)) {
                if (typeof fmUserNotice === 'function') {
                    fmUserNotice({ variant: 'warning', title: 'Move', message: 'Cannot move an item into itself.' });
                }
                return;
            }
        }

        if (typeof FmCopyMovePopup === 'undefined' || !FmCopyMovePopup.runDirect) return;

        const namesCopy = payload.names.slice();
        FmCopyMovePopup.runDirect({
            operation,
            rootDir:            this._rootDir,
            ajaxUrl:            this._ajaxUrl,
            sourcePath:         payload.sourcePath,
            destAbs:            destAbs,
            names:              namesCopy,
            execAvailable:      this._execAvailable,
            sourceRowsByName:   payload.sourceRowsByName,
            onSuccess:          () => {
                if (operation === 'move') {
                    this._captureKeyboardAnchorFromRemovedNames(namesCopy);
                }
                this.load(this._currentPath, () => {
                    this._callHook('after', operation, { names: namesCopy, currentPath: this._currentPath });
                });
            },
        });
    }

    _trashBasename() {
        return typeof fm_trash_basename !== "undefined" ? fm_trash_basename : ".trash";
    }

    _pathIsInTrash(path) {
        const tb = this._trashBasename();
        const r = norm(this._rootDir).replace(/\/$/, '');
        const prefix = r + "/" + tb;
        const p = norm(path).replace(/\/$/, '');
        return p === prefix || p.startsWith(prefix + "/");
    }

    _trashRootPath() {
        return norm(this._rootDir).replace(/\/$/, '') + '/' + this._trashBasename();
    }

    _trashFilesPath() {
        return this._trashRootPath() + '/files';
    }

    _inTrashView() {
        return this._pathIsInTrash(this._currentPath);
    }

    _rowPhysicalPath(row) {
        if (!row || !row.name) return null;
        if (this._inTrashView()) {
            return this._trashFilesPath() + '/' + row.name;
        }
        return fmJoinPath(this._currentPath, row.name);
    }

    _folderSizeQueryPath(row) {
        if (!row || row.type !== 'folder' || !row.name) return null;
        return this._rowPhysicalPath(row);
    }

    _fileRowAbsolutePath(row) {
        if (!row || row.type !== 'file' || !row.name) return null;
        return this._rowPhysicalPath(row);
    }

    _fileViewUrl(fullPath) {
        return this._ajaxUrl + '?' + new URLSearchParams({ action: 'file-view', path: fullPath }).toString();
    }

    _downloadUrl(fullPath) {
        return this._ajaxUrl + '?' + new URLSearchParams({ action: 'download', path: fullPath }).toString();
    }

    _toolbarActionsForCurrentPath() {
        const sc = FmFileManagerTable.ACTION_TOOLTIP_SHORTCUT;
        const withShortcut = (a) => {
            const shortcut = sc[a.id];
            return shortcut ? { ...a, shortcut } : { ...a };
        };
        if (this._inTrashView()) {
            const byId = Object.fromEntries(FmFileManagerTable.ACTIONS.map((x) => [x.id, x]));
            return [
                { ...byId['restore-trashed'], separatorBefore: false },
                { ...byId['delete'], title: 'Delete forever', separatorBefore: false },
                { id: 'empty-trash', icon: 'bi-trash3', title: 'Empty Trash', needSelection: false, separatorBefore: true },
                { ...byId['refresh'], separatorBefore: true },
            ].map(withShortcut);
        }
        const allowed = new Set([
            "create-new-folder", "create-new-file", "edit-file", "rename", "delete", "copy", "move", "duplicate", "new-folder-from-selection",
            "copy-path", "copy-relative-path",
            "get-info", "bulk-rename", "change-permissions", "compress", "extract", "refresh", "download", "open-in-new-tab", "terminal-here", "upload", "upload-url",
        ]);
        return FmFileManagerTable.ACTIONS.filter((a) => allowed.has(a.id)).map(withShortcut);
    }

    updateActionsState() {
        const toolbar = this.toolbarEl;
        if (!toolbar) return;

        if (this._invalidFolderMessage) {
            this.options.actions.forEach(a => {
                const btn = toolbar.querySelector(`[data-action="${a.id}"]`);
                if (!btn) return;
                btn.classList.add('disabled');
                btn.disabled = true;
            });
            return;
        }

        this.options.actions.forEach(a => {
            const btn = toolbar.querySelector(`[data-action="${a.id}"]`);
            if (btn) btn.disabled = false;
        });

        super.updateActionsState();
        const selected   = this.getSelectedRows();
        const editBtn = toolbar.querySelector('[data-action="edit-file"]');
        if (editBtn) {
            const canEdit = selected.length === 1 && selected[0] && selected[0].type === 'file';
            editBtn.disabled = !canEdit;
            editBtn.classList.toggle('disabled', !canEdit);
        }
        const dupBtn = toolbar.querySelector('[data-action="duplicate"]');
        if (dupBtn) {
            const canDup = selected.length > 0;
            dupBtn.disabled = !canDup;
            dupBtn.classList.toggle('disabled', !canDup);
        }
        const nfsBtn = toolbar.querySelector('[data-action="new-folder-from-selection"]');
        if (nfsBtn) {
            const canNfs = selected.length > 0;
            nfsBtn.disabled = !canNfs;
            nfsBtn.classList.toggle('disabled', !canNfs);
        }
        const chmodBtn = toolbar.querySelector('[data-action="change-permissions"]');
        if (chmodBtn) {
            const canChmod = selected.length > 0;
            chmodBtn.disabled = !canChmod;
            chmodBtn.classList.toggle('disabled', !canChmod);
        }
        const bulkRenBtn = toolbar.querySelector('[data-action="bulk-rename"]');
        if (bulkRenBtn) {
            const canBulk = selected.length > 0;
            bulkRenBtn.disabled = !canBulk;
            bulkRenBtn.classList.toggle('disabled', !canBulk);
        }
        const openTabBtn = toolbar.querySelector('[data-action="open-in-new-tab"]');
        if (openTabBtn) {
            const canOpenTab = selected.length === 1 && selected[0] && selected[0].type === 'folder';
            openTabBtn.disabled = !canOpenTab;
            openTabBtn.classList.toggle('disabled', !canOpenTab);
        }
        const terminalBtn = toolbar.querySelector('[data-action="terminal-here"]');
        if (terminalBtn) {
            const enabled = typeof window !== 'undefined' && window.fm_terminal_here_enabled === true;
            const execOk = typeof window !== 'undefined' && window.fm_exec_available === true;
            terminalBtn.classList.remove('fm-terminal-needs-enable');
            if (!execOk) {
                terminalBtn.disabled = true;
                terminalBtn.classList.add('disabled');
                terminalBtn.title = 'Terminal unavailable (exec() is disabled on this server)';
            } else if (!enabled) {
                terminalBtn.disabled = false;
                terminalBtn.classList.remove('disabled');
                terminalBtn.title = 'Terminal settings';
            } else {
                const canTerminal = selected.length === 0 || (selected.length === 1 && selected[0] && selected[0].type === 'folder');
                terminalBtn.disabled = !canTerminal;
                terminalBtn.classList.toggle('disabled', !canTerminal);
                const termSc = FmFileManagerTable.ACTION_TOOLTIP_SHORTCUT['terminal-here'];
                terminalBtn.title = termSc ? `Open terminal / shell command (${termSc})` : 'Open terminal / shell command';
            }
        }
        const hasArchive = selected.some(r => r.type === 'file' && FmFileManagerTable.isArchiveFile(r.name));
        this.options.actions.forEach(a => {
            if (!a.requiresArchive) return;
            const btn = toolbar.querySelector(`[data-action="${a.id}"]`);
            if (!btn) return;
            if (selected.length > 0 && !hasArchive) btn.classList.add('disabled');
        });
        if (this._inTrashView()) {
            const canRestore = selected.some((r) => FmFileManagerTable._trashRowCanRestore(r));
            const restoreBtn = toolbar.querySelector('[data-action="restore-trashed"]');
            if (restoreBtn) {
                restoreBtn.classList.toggle('disabled', !canRestore);
                const scR = FmFileManagerTable.ACTION_TOOLTIP_SHORTCUT['restore-trashed'];
                restoreBtn.title = canRestore || selected.length === 0
                    ? (scR ? `Restore to original location (${scR})` : 'Restore to original location')
                    : 'Restore — the original location of the selected item is unknown';
            }
            const emptyBtn = toolbar.querySelector('[data-action="empty-trash"]');
            if (emptyBtn) {
                const hasItems = Array.isArray(this._allRows) && this._allRows.length > 0;
                emptyBtn.disabled = !hasItems;
                emptyBtn.classList.toggle('disabled', !hasItems);
                emptyBtn.title = 'Empty Trash — permanently delete everything in Trash';
            }
        }
    }

    static _trashRowCanRestore(row) {
        return !!(row && row.trashOriginalPath);
    }

    _firstChildNameWhenGoingUp(fromPath, toPath) {
        const nf = norm(fromPath);
        const nt = norm(toPath);
        if (nf === nt) return null;
        const prefix = nt === '/' ? '/' : nt + '/';
        if (!nf.startsWith(prefix)) return null;
        const rest = nf.slice(prefix.length);
        if (!rest) return null;
        const seg = rest.split('/')[0];
        return seg || null;
    }

    _navigateFolder(path, opts = {}) {
        if (!this._onNavigate) return;
        const { skipHistory = false, skipRestoreChild = false } = opts;
        if (norm(path) !== norm(this._currentPath)) {
            if (!skipRestoreChild) {
                const childName = this._firstChildNameWhenGoingUp(this._currentPath, path);
                this._pendingRestoreChildName = childName || null;
            } else {
                this._pendingRestoreChildName = null;
            }
        }
        if (!skipHistory && norm(path) !== norm(this._currentPath)) {
            this._navBackStack.push(this._currentPath);
            this._navForwardStack = [];
        }
        this._onNavigate(path);
    }

    _navigateFolderBack() {
        if (!this._onNavigate || this._navBackStack.length === 0) return;
        const prev = this._navBackStack.pop();
        this._navForwardStack.push(this._currentPath);
        this._navigateFolder(prev, { skipHistory: true });
    }

    _navigateFolderForward() {
        if (!this._onNavigate || this._navForwardStack.length === 0) return;
        const next = this._navForwardStack.pop();
        this._navBackStack.push(this._currentPath);
        this._navigateFolder(next, { skipHistory: true });
    }

    _appendBeforeTable() {
        const bar = document.createElement('div');
        bar.className =
            'fm-breadcrumb-bar' +
            (this._invalidFolderMessage ? ' fm-breadcrumb-bar--invalid-folder' : '');
        bar.innerHTML = this._buildBreadcrumbHtml();
        this.container.appendChild(bar);
    }

    _rootCrumbLabel() {
        const parts = norm(this._rootDir).split('/').filter(Boolean);
        if (parts.length) return parts[parts.length - 1];
        return 'Root';
    }

    _buildBreadcrumbCrumbs() {
        const c = norm(this._currentPath);
        const r = norm(this._rootDir);
        const unrestricted = typeof allow_all_directories !== 'undefined' && allow_all_directories;
        const trashRoot = norm(this._trashRootPath());

        if (unrestricted) {
            const items = [];
            if (c.startsWith('/')) {
                items.push({ path: '/', label: '/' });
                const segs = c.split('/').filter(Boolean);
                let acc = '';
                for (const seg of segs) {
                    acc += '/' + seg;
                    items.push({ path: acc, label: acc === trashRoot ? 'Trash' : seg });
                }
                return items;
            }
            const winDrive = c.match(/^([A-Za-z]:)(?:\/(.*))?$/);
            if (winDrive) {
                const driveRoot = winDrive[1] + '/';
                items.push({ path: driveRoot, label: driveRoot });
                const segs = (winDrive[2] || '').split('/').filter(Boolean);
                let acc = winDrive[1];
                for (const seg of segs) {
                    acc += '/' + seg;
                    items.push({ path: acc, label: acc === trashRoot ? 'Trash' : seg });
                }
                return items;
            }
        }

        const items = [{ path: r, label: this._rootCrumbLabel() }];
        if (c === r) return items;
        const prefix = r === '/' ? '/' : r + '/';
        if (!c.startsWith(prefix)) return items;
        const rest = c.slice(prefix.length);
        if (!rest) return items;
        let acc = r === '/' ? '' : r;
        for (const seg of rest.split('/').filter(Boolean)) {
            acc = norm(acc + '/' + seg);
            items.push({ path: acc, label: acc === trashRoot ? 'Trash' : seg });
        }
        return items;
    }

    _breadcrumbSepHtml() {
        return '<span class="fm-breadcrumb-sep" aria-hidden="true"><i class="bi bi-chevron-right"></i></span>';
    }

    _breadcrumbCrumbHtml(cr) {
        const cur = norm(this._currentPath);
        if (norm(cr.path) === cur) {
            return `<span class="fm-breadcrumb-crumb fm-breadcrumb-crumb--current" aria-current="page">${this.escapeHtml(cr.label)}</span>`;
        }
        return `<button type="button" class="fm-breadcrumb-crumb" data-path="${this.escapeAttr(cr.path)}">${this.escapeHtml(cr.label)}</button>`;
    }

    _buildBreadcrumbPathHtml(crumbs) {
        const sep = this._breadcrumbSepHtml();
        const joinParts = (parts) => parts.join(sep);
        const th = FmFileManagerTable.BREADCRUMB_COLLAPSE_THRESHOLD;
        const tailN = FmFileManagerTable.BREADCRUMB_TAIL_COUNT;

        if (crumbs.length <= th) {
            return joinParts(crumbs.map((cr) => this._breadcrumbCrumbHtml(cr)));
        }

        if (this._breadcrumbMidExpanded) {
            const collapseBtn =
                '<button type="button" class="fm-breadcrumb-ellipsis fm-breadcrumb-ellipsis--less" data-bc="breadcrumb-collapse-mid" ' +
                'title="Show shorter path" aria-label="Show shorter path">' +
                '<i class="bi bi-chevron-bar-contract" aria-hidden="true"></i></button>';
            return joinParts(crumbs.map((cr) => this._breadcrumbCrumbHtml(cr))) + sep + collapseBtn;
        }

        const head = crumbs[0];
        const tail = crumbs.slice(-tailN);
        const hidden = crumbs.slice(1, -tailN);
        if (hidden.length === 0) {
            return joinParts(crumbs.map((cr) => this._breadcrumbCrumbHtml(cr)));
        }
        const hiddenTitle = 'Hidden: ' + hidden.map((h) => h.label).join(' / ');
        const expandBtn =
            '<button type="button" class="fm-breadcrumb-ellipsis" data-bc="breadcrumb-expand-mid" ' +
            `title="${this.escapeAttr(hiddenTitle)}" aria-label="Show full path" aria-expanded="false">` +
            '<i class="bi bi-three-dots" aria-hidden="true"></i></button>';
        return joinParts([this._breadcrumbCrumbHtml(head), expandBtn, ...tail.map((cr) => this._breadcrumbCrumbHtml(cr))]);
    }

    _buildBreadcrumbHtml() {
        const unrestricted = typeof allow_all_directories !== 'undefined' && allow_all_directories;
        const gotoFormHtml = unrestricted
            ? '<form class="fm-goto-path-form" autocomplete="off">' +
              `<input type="text" class="fm-goto-path-input" value="${this.escapeAttr(this._currentPath || '')}" placeholder="Go to path (e.g. /etc or C:/)" aria-label="Go to path">` +
              '<button type="submit" class="fm-goto-path-btn">Go</button>' +
              '</form>'
            : '';

        if (this._invalidFolderMessage) {
            return (
                '<div class="fm-breadcrumb-nav">' +
                '<button type="button" class="fm-breadcrumb-nav-btn" data-bc="back" title="Back (Alt + ←)" aria-label="Back" disabled><i class="bi bi-arrow-left"></i></button>' +
                '<button type="button" class="fm-breadcrumb-nav-btn" data-bc="forward" title="Forward (Alt + →)" aria-label="Forward" disabled><i class="bi bi-arrow-right"></i></button>' +
                '<button type="button" class="fm-breadcrumb-nav-btn" data-bc="up" title="Up to parent folder (Backspace / Alt + \u2191)" aria-label="Up to parent folder" disabled><i class="bi bi-arrow-up"></i></button>' +
                `<button type="button" class="fm-breadcrumb-nav-btn" data-bc="home" title="Go to home (${this.escapeAttr(this._rootDir)})" aria-label="Home"><i class="bi bi-house-door"></i></button>` +
                gotoFormHtml +
                '</div>'
            );
        }

        const canBack    = this._navBackStack.length > 0;
        const canForward = this._navForwardStack.length > 0;
        const cur        = norm(this._currentPath);
        const parent     = norm(getParentPath(this._currentPath));
        const canUp      = cur !== parent;

        const crumbs = this._buildBreadcrumbCrumbs();
        const nav =
            '<div class="fm-breadcrumb-nav">' +
            `<button type="button" class="fm-breadcrumb-nav-btn" data-bc="back" title="Back (Alt + ←)" aria-label="Back"${canBack ? '' : ' disabled'}><i class="bi bi-arrow-left"></i></button>` +
            `<button type="button" class="fm-breadcrumb-nav-btn" data-bc="forward" title="Forward (Alt + →)" aria-label="Forward"${canForward ? '' : ' disabled'}><i class="bi bi-arrow-right"></i></button>` +
            `<button type="button" class="fm-breadcrumb-nav-btn" data-bc="up" title="Up to parent folder (Backspace / Alt + \u2191)" aria-label="Up to parent folder"${canUp ? '' : ' disabled'}><i class="bi bi-arrow-up"></i></button>` +
            `<button type="button" class="fm-breadcrumb-nav-btn" data-bc="home" title="Go to home (${this.escapeAttr(this._rootDir)})" aria-label="Home"><i class="bi bi-house-door"></i></button>` +
            this._buildBreadcrumbCountHtml() +
            gotoFormHtml +
            this._buildNameFilterHtml() +
            '</div>';

        const crumbsHtml = this._buildBreadcrumbPathHtml(crumbs);
        let trashHint = '';
        if (this._inTrashView()) {
            trashHint =
                '<p class="fm-trash-cleanup-hint">' +
                this.escapeHtml('Restore puts an item back where it was deleted from. Removed files stay on the server until you use Delete forever or Empty Trash.') +
                '</p>';
        }

        return nav + '<div class="fm-breadcrumb-path">' + crumbsHtml + '</div>' + trashHint;
    }

    _buildNameFilterHtml() {
        return (
            '<div class="fm-table-filter-wrap">' +
            '<input type="search" class="fm-table-filter" placeholder="Filter by name…" ' +
            'aria-label="Filter items in this folder by name" autocomplete="off" value="' +
            this.escapeAttr(this._nameFilter || '') +
            '">' +
            '</div>'
        );
    }

    _buildBreadcrumbCountHtml() {
        if (this._invalidFolderMessage) return '';
        const rows = Array.isArray(this.rows) ? this.rows : [];
        const all = Array.isArray(this._allRows) && this._allRows.length ? this._allRows : rows;
        const filterOn = String(this._nameFilter || '').trim() !== '';
        if (filterOn && all.length > 0) {
            return '<span class="fm-breadcrumb-count">Showing ' + rows.length + ' of ' + all.length + '</span>';
        }
        if (this._inTrashView()) {
            const n = rows.length;
            return '<span class="fm-breadcrumb-count">' + (n === 0 ? 'Trash is empty' : (n === 1 ? '1 item' : n + ' items')) + '</span>';
        }
        const folders = rows.filter(r => r.type === 'folder').length;
        const files = rows.filter(r => r.type === 'file').length;
        if (folders === 0 && files === 0) {
            return '<span class="fm-breadcrumb-count">Empty folder</span>';
        }
        const parts = [];
        if (folders === 1) parts.push('1 folder');
        else if (folders > 1) parts.push(`${folders} folders`);
        if (files === 1) parts.push('1 file');
        else if (files > 1) parts.push(`${files} files`);
        return '<span class="fm-breadcrumb-count">' + this.escapeHtml(parts.join(', ')) + '</span>';
    }

    _bindBreadcrumbEvents() {
        const bar = this.container.querySelector('.fm-breadcrumb-bar');
        if (!bar) return;
        bar.querySelectorAll('.fm-breadcrumb-nav-btn[data-bc]').forEach(btn => {
            btn.addEventListener('click', (e) => {
                e.preventDefault();
                if (btn.disabled) return;
                const a = btn.getAttribute('data-bc');
                if (a === 'back') this._navigateFolderBack();
                else if (a === 'forward') this._navigateFolderForward();
                else if (a === 'up') {
                    this._navigateFolder(getParentPath(this._currentPath));
                } else if (a === 'home') {
                    this._navigateFolder(this._rootDir);
                }
            });
        });
        const gotoForm = bar.querySelector('.fm-goto-path-form');
        const gotoInput = bar.querySelector('.fm-goto-path-input');
        if (gotoForm && gotoInput) {
            gotoForm.addEventListener('submit', (e) => {
                e.preventDefault();
                const target = (gotoInput.value || '').trim().replace(/\\/g, '/');
                if (target) {
                    this._navigateFolder(target);
                }
            });
        }
        bar.querySelectorAll('.fm-breadcrumb-crumb[data-path]').forEach(btn => {
            btn.addEventListener('click', (e) => {
                e.preventDefault();
                const path = btn.getAttribute('data-path');
                if (path) {
                    this._navigateFolder(path);
                }
            });
        });
        const expandMid = bar.querySelector('[data-bc="breadcrumb-expand-mid"]');
        if (expandMid) {
            expandMid.addEventListener('click', (e) => {
                e.preventDefault();
                this._breadcrumbMidExpanded = true;
                this.render();
            });
        }
        const collapseMid = bar.querySelector('[data-bc="breadcrumb-collapse-mid"]');
        if (collapseMid) {
            collapseMid.addEventListener('click', (e) => {
                e.preventDefault();
                this._breadcrumbMidExpanded = false;
                this.render();
            });
        }
        this._bindNameFilterInput(bar.querySelector('.fm-table-filter'));
    }

    _bindNameFilterInput(input) {
        if (!input) return;
        input.addEventListener('input', () => {
            this._nameFilter = input.value || '';
            this._filterKeepFocus = true;
            this._filterSelStart = input.selectionStart;
            this._filterSelEnd = input.selectionEnd;
            this._allRows = Array.isArray(this._allRows) && this._allRows.length
                ? this._allRows
                : (this._data ? this.buildRows(this._data) : this.rows.slice());
            this.rows = this._filteredRows(this._allRows);
            this.selectedRows = this.selectedRows.filter((r) => this.rows.includes(r));
            this._focusedIndex = -1;
            this._selectionAnchor = null;
            this.render();
        });
        input.addEventListener('keydown', (e) => {
            if (e.key === 'Escape' && (input.value || '')) {
                e.preventDefault();
                e.stopPropagation();
                input.value = '';
                input.dispatchEvent(new Event('input', { bubbles: true }));
            }
        });
    }

    _renderEmptyState(el) {
        if (this._invalidFolderMessage) {
            const msg = this.escapeHtml(this._invalidFolderMessage);
            el.classList.add('fm-table-empty--invalid-folder');
            el.innerHTML =
                '<div class="fm-invalid-folder-notice" role="status">' +
                `<p class="fm-table-empty-text fm-invalid-folder-msg">${msg}</p>` +
                '<button type="button" class="fm-table-empty-btn fm-invalid-folder-go-root" data-fm-go-root="1">' +
                '<i class="bi bi-house-door" aria-hidden="true"></i> Go to root</button>' +
                '</div>';
            return;
        }
        el.classList.remove('fm-table-empty--invalid-folder');
        const filterQ = String(this._nameFilter || '').trim();
        if (filterQ && Array.isArray(this._allRows) && this._allRows.length > 0) {
            el.innerHTML =
                '<p class="fm-table-empty-text">No items match <strong>' +
                this.escapeHtml(filterQ) +
                '</strong>.</p>' +
                '<p class="fm-table-empty-text fm-hint">Clear the filter to see all items in this folder.</p>';
            return;
        }
        if (this._inTrashView()) {
            el.innerHTML =
                '<p class="fm-table-empty-text fm-table-empty-text--trash">' +
                this.escapeHtml('Trash is empty.') +
                '</p>' +
                '<p class="fm-table-empty-text fm-table-empty-text--trash-hint">' +
                this.escapeHtml('Deleted items appear here until you restore them or delete them forever.') +
                '</p>';
            return;
        }
        const sc = FmFileManagerTable.ACTION_TOOLTIP_SHORTCUT;
        const folderTip = `New folder (${sc['create-new-folder']})`;
        const fileTip   = `New file (${sc['create-new-file']})`;
        el.innerHTML =
            `<p class="fm-table-empty-text">${this.escapeHtml(this.options.emptyText)}</p>` +
            '<div class="fm-table-empty-actions">' +
            `<button type="button" class="fm-table-empty-btn" data-empty-action="create-new-folder" title="${this.escapeAttr(folderTip)}"><i class="bi bi-folder-plus"></i> New folder</button>` +
            `<button type="button" class="fm-table-empty-btn" data-empty-action="create-new-file" title="${this.escapeAttr(fileTip)}"><i class="bi bi-file-earmark-plus"></i> New file</button>` +
            '<button type="button" class="fm-table-empty-btn" data-empty-action="upload" title="Upload files"><i class="bi bi-upload"></i> Upload</button>' +
            '<button type="button" class="fm-table-empty-btn" data-empty-action="upload-url" title="Upload from URL"><i class="bi bi-link-45deg"></i> Upload from URL</button>' +
            '</div>';
    }

    render() {
        this.options.actions = this._toolbarActionsForCurrentPath();
        const inTrash = this._inTrashView();
        this.options.columns = FmFileManagerTable._buildColumns({
            showActionsColumn: this.options.showActionsColumn,
            showLastModifiedColumn: this.options.showLastModifiedColumn
        }).map((col) => (inTrash && col.key === 'mtime' ? { ...col, label: 'Deleted' } : col));
        if (Array.isArray(this._allRows) && this._allRows.length) {
            this.rows = this._filteredRows(this._allRows);
        }
        super.render();
        if (this.tableEl) {
            this.tableEl.classList.toggle('fm-table--image-previews', !!this.options.showImagePreviews);
            this.tableEl.classList.toggle('fm-table--trash-view', inTrash);
        }
        if (this._filterKeepFocus) {
            const bar = this.container && this.container.querySelector('.fm-breadcrumb-bar');
            const inp = bar && bar.querySelector('.fm-table-filter');
            if (inp) {
                inp.focus();
                const a = this._filterSelStart;
                const b = this._filterSelEnd;
                if (typeof a === 'number' && typeof b === 'number') {
                    try { inp.setSelectionRange(a, b); } catch (e) {}
                }
            }
        }
        this._filterKeepFocus = false;
        this._filterSelStart = null;
        this._filterSelEnd = null;
    }

    _trashNameCellHtml(row) {
        const name = row.displayName || row.name;
        const orig = row.trashOriginalPath;
        let fromLabel;
        let title;
        if (orig) {
            const slash = orig.lastIndexOf('/');
            const dir = slash > 0 ? orig.slice(0, slash) : '';
            fromLabel = 'from ' + (dir.startsWith('/') ? dir : '/' + dir);
            title = name + ' — deleted from ' + orig;
        } else {
            fromLabel = 'original location unknown';
            title = name + ' — original location unknown (can only be deleted forever)';
        }
        return '<span class="fm-table-name-cell fm-table-name-cell--trash">' +
            `<span class="fm-table-name" title="${this.escapeAttr(title)}">${this.escapeHtml(name)}</span>` +
            `<span class="fm-trash-origin" title="${this.escapeAttr(title)}">${this.escapeHtml(fromLabel)}</span>` +
            '</span>';
    }

    renderCell(col, row, index) {
        switch (col.key) {
            case 'icon': {
                if (row.type === 'folder') {
                    return '<span class="fm-table-icon-cell"><i class="bi bi-folder-fill"></i></span>';
                }
                const dispName = row.displayName || row.name;
                if (this.options.showImagePreviews && FmFileManagerTable.isImagePreviewExt(row.ext, dispName)) {
                    const fp = this._fileRowAbsolutePath(row);
                    if (fp) {
                        const src = this.escapeAttr(this._fileViewUrl(fp));
                        return '<span class="fm-table-icon-cell fm-table-icon-cell--image">' +
                            `<img class="fm-table-icon-preview" src="${src}" alt="" loading="lazy" decoding="async">` +
                            '</span>';
                    }
                }
                const typeIconHtml = FmFileManagerTable.getFileIcon(row.ext, dispName);
                return `<span class="fm-table-icon-cell">${typeIconHtml}</span>`;
            }
            case 'name': {
                if (this._inTrashView()) return this._trashNameCellHtml(row);
                const isImgFile = row.type === 'file' && FmFileManagerTable.isImagePreviewExt(row.ext, row.name);
                const nameTitle = isImgFile
                    ? this.escapeAttr(row.name + ' — Click to view; use arrows to browse images in this folder')
                    : this.escapeAttr(row.name);
                const nameCls = isImgFile ? 'fm-table-name fm-table-name--image-preview' : 'fm-table-name';
                const nameSpan = `<span class="${nameCls}" title="${nameTitle}">${this.escapeHtml(row.name)}</span>`;
                return `<span class="fm-table-name-cell">${nameSpan}</span>`;
            }
            case 'size':
                if (row.type === 'folder') {
                    const sizeVal = row.size != null ? this.escapeHtml(FmFileManagerTable.bytesToSize(row.size)) : '';
                    return `<span class="fm-table-folder-size-value">${sizeVal}</span><span class="fm-table-folder-size-trigger" data-index="${index}" title="Refresh folder size"><i class="bi bi-arrow-clockwise"></i></span>`;
                }
                return this.escapeHtml(row.size != null ? FmFileManagerTable.bytesToSize(row.size) : '—');
            case 'type':
                return this.escapeHtml(row.type === 'folder' ? 'Folder' : (row.ext || '—'));
            case 'mtime':
                return this.escapeHtml(FmFileManagerTable.formatMtime(row.mtime));
            case 'permissions': {
                const disp = this.options.permissionsDisplay || 'octal';
                if (disp === 'both') {
                    const p = row.permissions;
                    if (p == null || String(p).trim() === '') return '—';
                    const octStr = String(p).trim();
                    const sym = FmFileManagerTable.octalPermToSymbolic(p, row.type === 'folder');
                    const octEsc = this.escapeHtml(octStr);
                    if (!sym) return octEsc;
                    return '<span class="fm-perm-both"><span class="fm-perm-oct">' + octEsc + '</span><br><span class="fm-perm-sym">' + this.escapeHtml(sym) + '</span></span>';
                }
                const text = FmFileManagerTable.formatPermissionsCell(row.permissions, row.type, disp);
                return text === '—' ? '—' : this.escapeHtml(text);
            }
            case 'actions':
                return this._renderRowActionsHtml(row, index);
            default:
                return '';
        }
    }

    sortRows(rows) {
        const dir = this.options.sortDir === 'asc' ? 1 : -1;
        const by  = this.options.sortBy;
        return [...rows].sort((a, b) => {
            let va, vb;
            if (by === 'name') {
                const foldersFirst = this.options.sortDir === 'asc';
                if (a.type !== b.type) return a.type === 'folder' ? (foldersFirst ? -1 : 1) : (foldersFirst ? 1 : -1);
                const na = a.displayName || a.name;
                const nb = b.displayName || b.name;
                return dir * (na.localeCompare(nb, undefined, { sensitivity: 'base' }) || 0);
            }
            if (by === 'size') {
                va = a.size != null ? a.size : (dir === 1 ? Infinity : -Infinity);
                vb = b.size != null ? b.size : (dir === 1 ? Infinity : -Infinity);
                return dir * (va - vb);
            }
            if (by === 'type') {
                va = a.type === 'folder' ? 'Folder' : (a.ext || '');
                vb = b.type === 'folder' ? 'Folder' : (b.ext || '');
                return dir * ((va || '').localeCompare(vb || '', undefined, { sensitivity: 'base' }) || 0);
            }
            if (by === 'mtime') {
                va = a.mtime != null ? a.mtime : (dir === 1 ? -Infinity : Infinity);
                vb = b.mtime != null ? b.mtime : (dir === 1 ? -Infinity : Infinity);
                return dir * (va - vb);
            }
            if (by === 'permissions') {
                const permNum = (r) => {
                    const p = r.permissions;
                    if (p == null || String(p).trim() === '') return dir === 1 ? Infinity : -Infinity;
                    const n = parseInt(String(p).trim(), 8);
                    if (Number.isNaN(n)) return dir === 1 ? Infinity : -Infinity;
                    return n & 0o777;
                };
                va = permNum(a);
                vb = permNum(b);
                return dir * (va - vb);
            }
            return 0;
        });
    }

    setData(data) {
        this._data = data || { folders: {}, files: {} };
        this._allRows = this.buildRows(this._data);
        super.setData(this._filteredRows(this._allRows));
    }

    _filteredRows(rows) {
        const q = String(this._nameFilter || '').trim().toLowerCase();
        const list = Array.isArray(rows) ? rows : [];
        if (!q) return list.slice();
        return list.filter((r) => r && String(r.displayName || r.name || '').toLowerCase().includes(q));
    }

    static _hookName(actionId) {
        return actionId
            .replace(/-([a-z])/g, (_, c) => c.toUpperCase())
            .replace(/^[a-z]/, c => c.toUpperCase());
    }

    _callHook(timing, actionId, context) {
        const name = timing + FmFileManagerTable._hookName(actionId);
        if (typeof this[name] === 'function') this[name](context);
    }

    handleAction(actionId, payload) {
        const { selectedRows, selectedRow } = payload || {};
        if (this._inTrashView() && !FmFileManagerTable.TRASH_VIEW_ACTIONS.has(actionId)) return;
        if (!this._inTrashView() && (actionId === 'restore-trashed' || actionId === 'empty-trash')) return;
        this._callHook('before', actionId, payload);
        switch (actionId) {
            case 'refresh':           return this._handleRefresh();
            case 'open':              return this._handleOpen({ selectedRow });
            case 'create-new-folder': return this._handleCreateNewFolder();
            case 'create-new-file':   return this._handleCreateNewFile();
            case 'edit-file':         return this._handleEditFile({ selectedRow: selectedRow || (selectedRows && selectedRows[0]) });
            case 'delete':            return this._handleDelete({ selectedRows, defaultDeleteForever: !!(payload && payload.defaultDeleteForever) });
            case 'restore-trashed':   return this._handleRestoreTrashed({ selectedRows });
            case 'empty-trash':       return this._handleEmptyTrash();
            case 'copy':              return this._handleCopyMove('copy', { selectedRows });
            case 'move':              return this._handleCopyMove('move', { selectedRows });
            case 'duplicate':         return this._handleDuplicate({ selectedRows });
            case 'new-folder-from-selection': return this._handleNewFolderFromSelection({ selectedRows });
            case 'copy-path':         return this._handleCopyPath({ selectedRows, relative: false });
            case 'copy-relative-path': return this._handleCopyPath({ selectedRows, relative: true });
            case 'rename':            return this._handleRename(payload || {});
            case 'bulk-rename':       return this._handleBulkRename({ selectedRows });
            case 'get-info':          return this._handleGetInfo({
                selectedRow,
                getInfoForCurrentFolder: !!(payload && payload.getInfoForCurrentFolder),
            });
            case 'change-permissions': return this._handleChangePermissions({ selectedRows });
            case 'compress':          return this._handleCompress({ selectedRows });
            case 'extract':           return this._handleExtract({ selectedRows });
            case 'download':          return this._handleDownload({ selectedRows, selectedRow });
            case 'open-in-new-tab':   return this._handleOpenInNewTab({ selectedRows, selectedRow });
            case 'terminal-here':     return this._handleTerminalHere({ selectedRows, selectedRow });
            case 'bookmark-pin-current': return this._handleBookmarkAction('pin-current');
            case 'bookmark-remove-current': return this._handleBookmarkAction('remove-current');
            case 'bookmark-pin-row-folder': return this._handleBookmarkAction('pin-row-folder', { selectedRow });
            case 'bookmark-remove-row-folder': return this._handleBookmarkAction('remove-row-folder', { selectedRow });
            case 'upload':            return this._handleUpload();
            case 'upload-url':        return this._handleUploadUrl();
            case 'select-none':       return this._selectNone();
            case 'invert-selection':  return this._invertSelection();
        }
    }

    _bookmarkRowFolderPath(row) {
        if (!row || row.type !== 'folder' || !row.name) return null;
        return fmJoinPath(this._currentPath, row.name);
    }

    _bookmarkIsSaved(path) {
        if (!path || typeof window === 'undefined') return false;
        const api = window.fmBookmarkApi;
        if (!api || typeof api.isBookmarked !== 'function') return false;
        return api.isBookmarked(path);
    }

    _handleBookmarkAction(kind, payload = {}) {
        let detail = { kind };
        if (kind === 'pin-current' || kind === 'remove-current') {
            detail.path = this._currentPath;
        } else if (kind === 'pin-row-folder' || kind === 'remove-row-folder') {
            detail.path = this._bookmarkRowFolderPath(payload.selectedRow);
        }
        if (!detail.path) return;
        document.dispatchEvent(new CustomEvent('fm-bookmark-action', { detail }));
    }

    _handleRefresh() {
        this.load(this._currentPath, () => {
            this._callHook('after', 'refresh', { currentPath: this._currentPath });
        });
    }

    _handleOpen({ selectedRow } = {}) {
        if (!selectedRow || selectedRow.type !== 'folder' || !this._onNavigate || this._inTrashView()) return;
        this._navigateFolder(fmJoinPath(this._currentPath, selectedRow.name));
    }

    _handleCreateNewFolder() {
        new FmCreateNewFolderPopup({
            currentPath: this._currentPath,
            rootDir:     this._rootDir,
            ajaxUrl:     this._ajaxUrl,
            onSuccess:   (name) => {
                this.load(this._currentPath, () => {
                    this.highlightRowByName(name);
                    this._callHook('after', 'create-new-folder', { name, currentPath: this._currentPath });
                });
            }
        }).show();
    }

    _handleCreateNewFile() {
        new FmCreateFilePopup({
            currentPath: this._currentPath,
            rootDir:     this._rootDir,
            ajaxUrl:     this._ajaxUrl,
            onSuccess:   (name) => {
                this.load(this._currentPath, () => {
                    this.highlightRowByName(name);
                    this._callHook('after', 'create-new-file', { name, currentPath: this._currentPath });
                });
            }
        }).show();
    }

    _handleUploadUrl() {
        new FmUploadUrlPopup({
            currentPath: this._currentPath,
            rootDir:     this._rootDir,
            ajaxUrl:     this._ajaxUrl,
            onSuccess:   (name) => {
                this.load(this._currentPath, () => {
                    if (name) this.highlightRowByName(name);
                    this._callHook('after', 'upload-url', { name, currentPath: this._currentPath });
                });
            }
        }).show();
    }

    _handleEditFile({ selectedRow } = {}) {
        const row = selectedRow || this.getSelected();
        if (!row || row.type !== 'file' || !row.name || this._inTrashView()) return;
        new FmEditFilePopup({
            currentPath: this._currentPath,
            rootDir:     this._rootDir,
            ajaxUrl:     this._ajaxUrl,
            name:        row.name,
            onSaved:     ({ name, closed }) => {
                if (closed) {
                    this.load(this._currentPath, () => {
                        this.highlightRowByName(name);
                        this._callHook('after', 'edit-file', { name, currentPath: this._currentPath });
                    });
                }
            },
        }).show();
    }

    _handleDelete({ selectedRows, defaultDeleteForever } = {}) {
        const names = (selectedRows || []).filter((r) => r && r.name).map((r) => r.name);
        if (names.length === 0) return;
        const inTrash = this._inTrashView();
        new FmDeletePopup({
            currentPath: inTrash ? this._trashFilesPath() : this._currentPath,
            rootDir:     this._rootDir,
            ajaxUrl:     this._ajaxUrl,
            execAvailable: this._execAvailable,
            names,
            inTrashTree: inTrash,
            defaultDeleteForever: !!defaultDeleteForever,
            onSuccess:   (deletedNames) => {
                this._captureKeyboardAnchorFromRemovedNames(Array.isArray(deletedNames) ? deletedNames : names);
                this.load(this._currentPath, () => {
                    this._callHook('after', 'delete', { names: deletedNames, currentPath: this._currentPath });
                });
            }
        }).show();
    }

    _handleRestoreTrashed({ selectedRows } = {}) {
        if (!this._inTrashView()) return;
        const rows = (selectedRows || []).filter((r) => FmFileManagerTable._trashRowCanRestore(r));
        if (rows.length === 0) {
            if ((selectedRows || []).length > 0 && typeof fmUserNotice === 'function') {
                fmUserNotice({
                    title:   'Restore',
                    message: 'The original location of the selected items is unknown. They can only be deleted forever.',
                });
            }
            return;
        }
        const form = new FormData();
        form.append('action', 'trash-restore');
        rows.forEach((r) => form.append('ids[]', r.name));
        fetch(this._ajaxUrl, fmPostInit(form))
            .then((r) => r.json())
            .then((data) => {
                const errs = Array.isArray(data.errors) ? data.errors : [];
                const restored = Array.isArray(data.restored) ? data.restored : [];
                const renamed = restored.filter((x) => x && x.renamed);
                const lines = [];
                if (renamed.length) {
                    lines.push('A file or folder with the same name already exists, so these were restored under a new name:');
                    renamed.forEach((x) => lines.push('• ' + x.from + ' → ' + this._relPathForDisplay(x.path)));
                }
                if (errs.length) {
                    lines.push((lines.length ? '\n' : '') + 'Could not restore:');
                    errs.forEach((e) => lines.push('• ' + e));
                }
                if (lines.length && typeof fmUserNotice === 'function') {
                    fmUserNotice({
                        variant: errs.length ? 'error' : 'warning',
                        title:   'Restore',
                        message: lines.join('\n'),
                    });
                }
                if (!restored.length) return;
                this._captureKeyboardAnchorFromRemovedNames(restored.map((x) => x.id));
                this.load(this._currentPath, () => {
                    this._callHook('after', 'restore-trashed', {
                        currentPath: this._currentPath,
                        restored,
                        folderRestored: restored.some((x) => x.type === 'folder'),
                    });
                });
            })
            .catch(() => {
                if (typeof fmUserNotice === 'function') {
                    fmUserNotice({ title: 'Restore', message: 'Restore failed.' });
                }
            });
    }

    _relPathForDisplay(absPath) {
        const r = norm(this._rootDir).replace(/\/$/, '');
        const p = norm(absPath || '');
        if (p.toLowerCase().startsWith(r.toLowerCase() + '/')) return p.slice(r.length);
        return p;
    }

    _handleEmptyTrash() {
        if (!this._inTrashView()) return;
        const n = Array.isArray(this._allRows) ? this._allRows.length : 0;
        const confirm = new FmPopup({
            title: 'Empty Trash?',
            maxWidth: '460px',
            content:
                '<p class="mt-0">Permanently delete <strong>' +
                (n === 1 ? '1 item' : n + ' items') +
                '</strong> in Trash?</p>' +
                '<p class="mb-0">This cannot be undone.</p>',
            buttons: [
                { label: 'Cancel', close: true },
                {
                    label: 'Empty Trash',
                    primary: true,
                    close: false,
                    onClick: () => {
                        confirm.hideAndDestroy();
                        const form = new FormData();
                        form.append('action', 'trash-empty');
                        fetch(this._ajaxUrl, fmPostInit(form))
                            .then((r) => r.json())
                            .then((data) => {
                                const errs = data.errors || [];
                                if (errs.length && typeof fmUserNotice === 'function') {
                                    fmUserNotice({ title: 'Empty Trash', message: errs.join('; ') });
                                }
                                if (data.status === 'error' && !(data.ok && data.ok.length)) {
                                    if (typeof fmUserNotice === 'function') {
                                        fmUserNotice({ title: 'Empty Trash', message: data.msg || 'Could not empty Trash.' });
                                    }
                                    return;
                                }
                                const trashRoot = this._trashRootPath();
                                this.load(trashRoot, () => {
                                    this._callHook('after', 'empty-trash', {
                                        currentPath: trashRoot,
                                        trashRootPath: trashRoot,
                                    });
                                });
                            })
                            .catch(() => {
                                if (typeof fmUserNotice === 'function') {
                                    fmUserNotice({ title: 'Empty Trash', message: 'Could not empty Trash.' });
                                }
                            });
                    },
                },
            ],
        });
        confirm.show();
    }

    _handleCopyMove(operation, { selectedRows } = {}) {
        const rows  = selectedRows || [];
        const names = rows.map(r => r.name);
        if (names.length === 0) return;
        const depth = typeof sidebarMaxDepth !== 'undefined' ? sidebarMaxDepth : 12;
        new FmCopyMovePopup({
            operation,
            currentPath: this._currentPath,
            rootDir:     this._rootDir,
            ajaxUrl:     this._ajaxUrl,
            names,
            execAvailable: this._execAvailable,
            treeDepth:   depth,
            onSuccess:   () => {
                if (operation === 'move') {
                    this._captureKeyboardAnchorFromRemovedNames(names);
                }
                this.load(this._currentPath, () => {
                    this._callHook('after', operation, { names, currentPath: this._currentPath });
                });
            }
        }).show();
    }

    _handleDuplicate({ selectedRows } = {}) {
        const names = (selectedRows || []).filter((r) => r && r.name).map((r) => r.name);
        if (names.length === 0) return;
        if (typeof FmCopyMovePopup === 'undefined' || !FmCopyMovePopup.runDuplicateDirect) return;
        FmCopyMovePopup.runDuplicateDirect({
            rootDir:       this._rootDir,
            ajaxUrl:       this._ajaxUrl,
            sourcePath:    this._currentPath,
            names,
            execAvailable: this._execAvailable,
            onSuccess:     (createdNames) => {
                const list = Array.isArray(createdNames) ? createdNames : [];
                this.load(this._currentPath, () => {
                    this.highlightRowsByNames(list);
                    this._callHook('after', 'duplicate', { names, createdNames: list, currentPath: this._currentPath });
                });
            },
        });
    }

    _handleNewFolderFromSelection({ selectedRows } = {}) {
        const names = (selectedRows || []).filter((r) => r && r.name).map((r) => r.name);
        if (names.length === 0) return;
        if (typeof FmNewFolderFromSelectionPopup === 'undefined') return;
        new FmNewFolderFromSelectionPopup({
            currentPath:   this._currentPath,
            rootDir:       this._rootDir,
            ajaxUrl:       this._ajaxUrl,
            names,
            execAvailable: this._execAvailable,
            onSuccess:     (folderName) => {
                this._captureKeyboardAnchorFromRemovedNames(names);
                this.load(this._currentPath, () => {
                    this.highlightRowByName(folderName);
                    this._callHook('after', 'new-folder-from-selection', {
                        movedNames: names,
                        folderName,
                        currentPath: this._currentPath,
                    });
                });
            },
        }).show();
    }

    _handleRename({ selectedRow, newName } = {}) {
        if (!selectedRow) return;
        if (newName) {
            const form = new FormData();
            form.append('action',   'rename');
            form.append('in',       fmPathInParam(this._currentPath, this._rootDir));
            form.append('name',     selectedRow.name);
            form.append('new_name', newName);
            fetch(this._ajaxUrl, fmPostInit(form))
                .then(r => r.json())
                .then(data => {
                    if (data.status === 'success') {
                        this.renameRow(selectedRow.name, newName);
                        this._callHook('after', 'rename', { oldName: selectedRow.name, newName, currentPath: this._currentPath });
                    } else if (typeof fmUserNotice === 'function') {
                        fmUserNotice({ title: 'Rename', message: data.msg || 'Rename failed.' });
                    } else {
                        alert(data.msg || 'Rename failed');
                    }
                })
                .catch(() => {
                    if (typeof fmUserNotice === 'function') {
                        fmUserNotice({ title: 'Rename', message: 'Rename failed.' });
                    } else {
                        alert('Rename failed');
                    }
                });
            return;
        }

        new FmRenamePopup({
            currentName: selectedRow.name,
            isFolder:    selectedRow.type === 'folder',
            onSuccess: (newName) => {
                this.handleAction('rename', { selectedRow, selectedRows: [selectedRow], newName });
            }
        }).show();
    }

    _handleBulkRename({ selectedRows } = {}) {
        const rows = (selectedRows || []).filter((r) => r && r.name);
        if (rows.length === 0) return;
        new FmBulkRenamePopup({
            currentPath: this._currentPath,
            rootDir:     this._rootDir,
            ajaxUrl:     this._ajaxUrl,
            rows,
            onSuccess:   (newNames) => {
                const list = Array.isArray(newNames) ? newNames : [];
                this.load(this._currentPath, () => {
                    this.highlightRowsByNames(list);
                    this._callHook('after', 'bulk-rename', { newNames: list, currentPath: this._currentPath });
                });
            },
        }).show();
    }

    _handleGetInfo({ selectedRow, getInfoForCurrentFolder } = {}) {
        let fullPath;
        if (getInfoForCurrentFolder) {
            fullPath = norm(this._currentPath);
            if (!fullPath) fullPath = norm(this._rootDir);
        } else if (!selectedRow) {
            return;
        } else {
            fullPath = this._rowPhysicalPath(selectedRow);
        }
        if (!fullPath) return;
        new FmGetInfoPopup({ fullPath, rootDir: this._rootDir, ajaxUrl: this._ajaxUrl }).show();
    }

    _handleChangePermissions({ selectedRows } = {}) {
        const rows = (selectedRows || []).filter((r) => r && r.name);
        const folderRows = rows.filter((r) => r.type === 'folder');
        const fileRows = rows.filter((r) => r.type === 'file');
        if (folderRows.length === 0 && fileRows.length === 0) return;
        new FmChmodPopup({
            currentPath: this._currentPath,
            rootDir:     this._rootDir,
            ajaxUrl:     this._ajaxUrl,
            folderRows,
            fileRows,
            onSuccess:   (changedNames) => {
                const list = Array.isArray(changedNames) ? changedNames : [];
                this.load(this._currentPath, () => {
                    this.highlightRowsByNames(list);
                    this._callHook('after', 'change-permissions', { names: list, currentPath: this._currentPath });
                });
            },
        }).show();
    }

    _normalizePathSlashesForCopy(p) {
        return norm(p);
    }

    _pathRelativeToRoot(absPath) {
        const r = this._normalizePathSlashesForCopy(this._rootDir);
        const p = this._normalizePathSlashesForCopy(absPath);
        if (!p || !r) return null;
        if (p === r) return '';
        const prefix = r === '/' ? '/' : r + '/';
        if (!p.startsWith(prefix)) return p;
        return p.slice(prefix.length);
    }

    _writeTextToClipboard(text) {
        if (navigator.clipboard && typeof navigator.clipboard.writeText === 'function') {
            return navigator.clipboard.writeText(text).catch(() => {
                if (this._writeTextToClipboardFallback(text)) return;
                throw new Error('clipboard');
            });
        }
        if (this._writeTextToClipboardFallback(text)) return Promise.resolve();
        return Promise.reject(new Error('clipboard'));
    }

    _writeTextToClipboardFallback(text) {
        try {
            const ta = document.createElement('textarea');
            ta.value = text;
            ta.setAttribute('readonly', '');
            ta.style.position = 'fixed';
            ta.style.left = '-9999px';
            document.body.appendChild(ta);
            ta.select();
            ta.setSelectionRange(0, text.length);
            const ok = document.execCommand('copy');
            document.body.removeChild(ta);
            return ok;
        } catch (e) {
            return false;
        }
    }

    _handleCopyPath({ selectedRows, relative } = {}) {
        const rows = (selectedRows || []).filter((r) => r && r.name);
        const lines = [];
        for (let i = 0; i < rows.length; i++) {
            const abs = this._rowPhysicalPath(rows[i]);
            if (!abs) continue;
            if (relative) {
                const rel = this._pathRelativeToRoot(abs);
                if (rel === null) continue;
                lines.push(rel === '' ? '.' : rel);
            } else {
                lines.push(this._normalizePathSlashesForCopy(abs));
            }
        }
        if (lines.length === 0) {
            if (typeof fmUserNotice === 'function') {
                fmUserNotice({
                    variant: 'warning',
                    title:   'Copy path',
                    message: 'Select items in this folder to copy paths.',
                });
            }
            return;
        }
        const text = lines.join('\n');
        this._writeTextToClipboard(text).then(() => {
            const n = lines.length;
            const label = relative ? 'relative path' + (n === 1 ? '' : 's') : 'path' + (n === 1 ? '' : 's');
            const msg = 'Copied ' + n + ' ' + label + ' to clipboard.';
            const suppressed = typeof FmNoticePopup !== 'undefined' &&
                FmNoticePopup.isNoticeSuppressed(FM_NOTICE_SUPPRESS_COPY_PATH_OK);
            if (suppressed) {
                if (typeof fmToast === 'function') {
                    fmToast({ title: 'Copy path', message: msg, variant: 'success' });
                }
                return;
            }
            if (typeof fmUserNotice === 'function') {
                fmUserNotice({
                    title: 'Copy path',
                    message: msg,
                    dontShowAgainStorageKey: FM_NOTICE_SUPPRESS_COPY_PATH_OK,
                });
            }
        }).catch(() => {
            if (typeof fmUserNotice === 'function') {
                fmUserNotice({
                    variant: 'warning',
                    title: 'Copy path',
                    message: 'Could not copy to clipboard.',
                });
            }
        });
    }

    _nativeDownload({ method, url, fields = [], label }) {
        const bytes = new Uint8Array(16);
        window.crypto.getRandomValues(bytes);
        const token = Array.from(bytes, (b) => b.toString(16).padStart(2, '0')).join('');

        const frameName = 'fm-dl-frame-' + token;
        const iframe = document.createElement('iframe');
        iframe.name = frameName;
        iframe.style.display = 'none';
        iframe.setAttribute('aria-hidden', 'true');
        document.body.appendChild(iframe);

        new FmDownloadTracker({ ajaxUrl: this._ajaxUrl, token, label, iframe }).start();

        if (method === 'POST') {
            const form = document.createElement('form');
            form.method = 'POST';
            form.action = url;
            form.target = frameName;
            form.style.display = 'none';
            fields.concat([['dl_token', token], ['csrf_token', fm_csrf_token]]).forEach(([name, value]) => {
                const input = document.createElement('input');
                input.type = 'hidden';
                input.name = name;
                input.value = value;
                form.appendChild(input);
            });
            document.body.appendChild(form);
            form.submit();
            form.remove();
        } else {
            iframe.src = url + (url.indexOf('?') === -1 ? '?' : '&') + 'dl_token=' + token;
        }
    }

    _downloadSingleFileViaIframe(fullPath) {
        const slash = fullPath.replace(/\\/g, '/').split('/').filter(Boolean);
        const label = slash.length ? slash[slash.length - 1] : 'download';
        this._nativeDownload({ method: 'GET', url: this._downloadUrl(fullPath), label });
    }

    _handleOpenInNewTab({ selectedRows, selectedRow } = {}) {
        const row = (Array.isArray(selectedRows) && selectedRows.length === 1)
            ? selectedRows[0]
            : selectedRow;
        if (!row || row.type !== 'folder') return;
        const target = fmJoinPath(this._currentPath, row.name);
        const hash = 'action=open&folder=' + encodeURIComponent(target);
        const base = window.location.href.split('#')[0];
        const url = base + '#' + hash;
        window.open(url, '_blank', 'noopener,noreferrer');
        this._callHook('after', 'open-in-new-tab', { selectedRow: row, currentPath: this._currentPath });
    }

    _resolveTerminalTargetPath({ selectedRows, selectedRow } = {}) {
        const row = (Array.isArray(selectedRows) && selectedRows.length === 1)
            ? selectedRows[0]
            : selectedRow;
        if (row && row.type === 'folder') {
            return fmJoinPath(this._currentPath, row.name);
        }
        return this._currentPath;
    }

    _handleTerminalHere({ selectedRows, selectedRow } = {}) {
        const execOk = typeof window !== 'undefined' && window.fm_exec_available === true;
        if (!execOk) {
            if (typeof fmUserNotice === 'function') {
                fmUserNotice({ title: 'Terminal', message: 'exec() is disabled on this server.' });
            }
            return;
        }
        const enabled = typeof window !== 'undefined' && window.fm_terminal_here_enabled === true;
        if (!enabled) {
            if (typeof fmShowTerminalSettingsPopup === 'function') {
                fmShowTerminalSettingsPopup({
                    ajaxUrl: this._ajaxUrl,
                    onSaved: () => location.reload(),
                });
            } else if (typeof fmUserNotice === 'function') {
                fmUserNotice({
                    title: 'Terminal',
                    message: 'Terminal is disabled. Open Terminal settings or set $FM_ENABLE_TERMINAL_HERE = true in this PHP file.',
                });
            }
            return;
        }
        if (typeof FmTerminalHerePopup === 'undefined') return;
        const targetPath = this._resolveTerminalTargetPath({ selectedRows, selectedRow });
        new FmTerminalHerePopup({
            currentPath: targetPath,
            rootDir: this._rootDir,
            ajaxUrl: this._ajaxUrl,
        }).show();
    }

    _handleDownload({ selectedRows, selectedRow } = {}) {
        const rows = (selectedRows && selectedRows.length > 0)
            ? selectedRows
            : (selectedRow ? [selectedRow] : []);
        const rowsDl = rows.filter(r => r.type === 'file' || r.type === 'folder');
        if (rowsDl.length === 0) return;
        const onlyOneFile = rowsDl.length === 1 && rowsDl[0].type === 'file';
        if (onlyOneFile) {
            const r0 = rowsDl[0];
            const fullPath = this._rowPhysicalPath(r0);
            if (fullPath) this._downloadSingleFileViaIframe(fullPath);
            return;
        }
        const names = rowsDl.map(r => r.name);
        const defaultArchiveName = names.length === 1 ? names[0] + '.zip' : undefined;
        new FmDownloadArchivePopup({
            currentPath:         this._currentPath,
            rootDir:             this._rootDir,
            ajaxUrl:             this._ajaxUrl,
            names,
            defaultArchiveName,
            execAvailable:       this._execAvailable,
            onConfirm:           ({ archiveName, archiveType }) => {
                this._postDownloadArchive(names, archiveName, archiveType);
            },
        }).show();
    }

    _postDownloadArchive(names, archiveName, archiveType) {
        const relIn = fmPathInParam(this._currentPath, this._rootDir);
        const fields = [
            ['action', 'download-archive'],
            ['in', relIn],
            ['archive_name', archiveName],
            ['archive_type', archiveType],
        ];
        names.forEach((n) => fields.push(['names[]', n]));
        this._nativeDownload({ method: 'POST', url: this._ajaxUrl, fields, label: archiveName });
    }

    _handleUpload() {
        new FmUploadPopup({
            currentPath: this._currentPath,
            rootDir:     this._rootDir,
            ajaxUrl:     this._ajaxUrl,
            onSuccess:   (uploadedNames) => {
                const list = Array.isArray(uploadedNames) ? uploadedNames : [];
                this.load(this._currentPath, () => {
                    this.highlightRowsByNames(list);
                    this._callHook('after', 'upload', { names: list, currentPath: this._currentPath });
                });
            },
        }).show();
    }

    _handleCompress({ selectedRows } = {}) {
        const rows  = selectedRows || [];
        const names = rows.map(r => r.name);
        if (names.length === 0) return;

        const baseName = names.length === 1 ? names[0] : 'archive';
        const defaultNameWithExt = baseName + '.zip';

        new FmCompressPopup({
            currentPath: this._currentPath,
            rootDir:     this._rootDir,
            ajaxUrl:     this._ajaxUrl,
            names,
            defaultArchiveName: defaultNameWithExt,
            execAvailable: this._execAvailable,
            maxWidth: '500px',
            onSuccess: (archiveNames) => {
                const list = Array.isArray(archiveNames) ? archiveNames : (archiveNames ? [archiveNames] : []);
                this.load(this._currentPath, () => {
                    this.highlightRowsByNames(list);
                    this._callHook('after', 'compress', { currentPath: this._currentPath });
                });
            },
        }).show();
    }

    _handleExtract({ selectedRows } = {}) {
        const rows      = selectedRows || [];
        const archives  = rows.filter(r => r.type === 'file' && FmFileManagerTable.isArchiveFile(r.name));
        if (archives.length === 0) return;
        const names = archives.map(r => r.name);

        new FmExtractPopup({
            currentPath:   this._currentPath,
            rootDir:       this._rootDir,
            ajaxUrl:       this._ajaxUrl,
            names,
            execAvailable: this._execAvailable,
            maxWidth:      '500px',
            onSuccess:     (highlightNames) => {
                const list = Array.isArray(highlightNames) ? highlightNames : [];
                this.load(this._currentPath, () => {
                    this.highlightRowsByNames(list);
                    this._callHook('after', 'extract', { names: list, currentPath: this._currentPath });
                });
            },
        }).show();
    }

    _initKeyboardShortcuts() {
        this._boundKeyboardShortcut = (e) => {
            if (document.querySelector('.fm-js-popup.show')) return;
            const tag = document.activeElement && document.activeElement.tagName;
            if (tag === 'INPUT' || tag === 'TEXTAREA' || tag === 'SELECT') return;

            if (e.key === 'F10' && e.shiftKey && !e.ctrlKey && !e.metaKey && !e.altKey) {
                if (this._contextMenuEl) {
                    e.preventDefault();
                    this._hideContextMenu();
                    return;
                }
                if (this.tableEl && this.rows.length) {
                    let idx = this._focusedIndex >= 0 ? this._focusedIndex : -1;
                    if (idx < 0) {
                        const sel = this.getSelected();
                        idx = sel ? this.rows.indexOf(sel) : -1;
                    }
                    if (idx >= 0) {
                        e.preventDefault();
                        const tr = this.tableEl.querySelector(`tbody tr[data-index="${idx}"]`);
                        if (tr) {
                            const r = tr.getBoundingClientRect();
                            this._showContextMenuAt(
                                Math.min(r.left + 4, window.innerWidth - 8),
                                Math.min(r.bottom + 4, window.innerHeight - 8),
                                idx
                            );
                        }
                    }
                }
                return;
            }

            if (e.altKey && (e.key === 'ArrowLeft' || e.key === 'ArrowRight')) {
                e.preventDefault();
                if (e.key === 'ArrowLeft') this._navigateFolderBack();
                else this._navigateFolderForward();
                return;
            }

            if (e.altKey && e.key === 'ArrowUp') {
                const parent = norm(getParentPath(this._currentPath));
                const cur    = norm(this._currentPath);
                if (parent !== cur) {
                    e.preventDefault();
                    this._navigateFolder(parent);
                }
                return;
            }

            const navKeys = ['ArrowUp', 'ArrowDown', 'Home', 'End', 'PageUp', 'PageDown'];
            if (navKeys.includes(e.key)) {
                const len = this.rows.length;
                if (!len) return;
                const cur = this._focusedIndex < 0 ? (e.key === 'ArrowDown' || e.key === 'PageDown' || e.key === 'End' ? -1 : len) : this._focusedIndex;
                if ((e.key === 'ArrowUp' || e.key === 'PageUp' || e.key === 'Home') && cur === 0) return;

                e.preventDefault();
                let next;
                switch (e.key) {
                    case 'ArrowUp':   next = cur - 1; break;
                    case 'ArrowDown': next = cur + 1; break;
                    case 'Home':      next = 0; break;
                    case 'End':       next = len - 1; break;
                    case 'PageUp':    next = cur - this._pageSize(); break;
                    case 'PageDown':  next = cur + this._pageSize(); break;
                }
                next = Math.max(0, Math.min(len - 1, next));
                if (e.shiftKey) {
                    this._focusRow(next);
                    this._extendSelectionTo(next);
                } else {
                    this._selectionAnchor = next;
                    this._selectRow(next);
                    this._focusRow(next);
                }
                return;
            }

            if (e.key === 'Enter' && !e.altKey) {
                const row = this._focusedIndex >= 0 ? this.rows[this._focusedIndex] : this.getSelected();
                if (row) {
                    e.preventDefault();
                    this._handleRowDoubleClickNav(row, null);
                }
                return;
            }

            if (e.key === 'Backspace') {
                const parent = norm(getParentPath(this._currentPath));
                const cur    = norm(this._currentPath);
                if (parent !== cur) {
                    e.preventDefault();
                    this._navigateFolder(parent);
                }
                return;
            }

            if ((e.ctrlKey || e.metaKey) && !e.altKey && !e.shiftKey && e.key.toLowerCase() === 'a') {
                e.preventDefault();
                this._selectAllRows();
                return;
            }

            if (this.tableEl && this.rows.length > 0 && !e.ctrlKey && !e.altKey && !e.metaKey && !e.shiftKey && e.key === 'Escape' && this.selectedRows.length > 0) {
                e.preventDefault();
                this._selectNone();
                return;
            }

            if (this.tableEl && this.rows.length > 0 && e.ctrlKey && e.altKey && !e.shiftKey && e.key.toLowerCase() === 'i') {
                e.preventDefault();
                this._invertSelection();
                return;
            }

            const sel    = this.getSelected();
            const selAll = this.getSelectedRows();

            const inTrashTree = this._pathIsInTrash(this._currentPath);

            if (e.key === 'F5' && !e.ctrlKey && !e.metaKey && !e.altKey) {
                e.preventDefault();
                this.handleAction('refresh', { selectedRows: selAll, selectedRow: sel });
                return;
            }

            if (e.key === 'Delete' && sel) {
                e.preventDefault();
                this.handleAction('delete', { selectedRows: selAll, selectedRow: sel, defaultDeleteForever: e.shiftKey });
            } else if (e.key === 'F2' && sel) {
                e.preventDefault();
                this.handleAction('rename', { selectedRows: selAll, selectedRow: sel });
            } else if (!inTrashTree && ((e.key === 'F4' && !e.ctrlKey && !e.metaKey && !e.altKey) || (e.ctrlKey && e.altKey && !e.shiftKey && e.key.toLowerCase() === 'v')) && sel && sel.type === 'file') {
                e.preventDefault();
                this.handleAction('edit-file', { selectedRows: selAll, selectedRow: sel });
            } else if (inTrashTree && e.ctrlKey && e.altKey && !e.shiftKey && e.key.toLowerCase() === 'r' && sel) {
                e.preventDefault();
                this.handleAction('restore-trashed', { selectedRows: selAll, selectedRow: sel });
            } else if (!inTrashTree && e.ctrlKey && e.altKey && !e.shiftKey && e.key.toLowerCase() === 'f') {
                e.preventDefault();
                this.handleAction('create-new-file', { selectedRows: [], selectedRow: null });
            } else if (!inTrashTree && e.ctrlKey && e.altKey && !e.shiftKey && e.key.toLowerCase() === 'd') {
                e.preventDefault();
                this.handleAction('create-new-folder', { selectedRows: [], selectedRow: null });
            } else if (!inTrashTree && e.ctrlKey && e.altKey && !e.shiftKey && e.key.toLowerCase() === 'a' && sel) {
                e.preventDefault();
                this.handleAction('compress', { selectedRows: selAll, selectedRow: sel });
            } else if (!inTrashTree && e.ctrlKey && e.altKey && !e.shiftKey && e.key.toLowerCase() === 'e' && sel) {
                e.preventDefault();
                this.handleAction('extract', { selectedRows: selAll, selectedRow: sel });
            } else if (!inTrashTree && e.ctrlKey && e.altKey && !e.shiftKey && e.key.toLowerCase() === 'c' && sel) {
                e.preventDefault();
                this.handleAction('copy', { selectedRows: selAll, selectedRow: sel });
            } else if (!inTrashTree && e.ctrlKey && e.altKey && !e.shiftKey && (e.key.toLowerCase() === 'm' || e.key.toLowerCase() === 'x') && sel) {
                e.preventDefault();
                this.handleAction('move', { selectedRows: selAll, selectedRow: sel });
            } else if (!inTrashTree && e.ctrlKey && e.altKey && !e.shiftKey && e.key.toLowerCase() === 'u' && sel) {
                e.preventDefault();
                this.handleAction('duplicate', { selectedRows: selAll, selectedRow: sel });
            } else if (!inTrashTree && e.ctrlKey && e.altKey && !e.shiftKey && e.key.toLowerCase() === 'n' && sel) {
                e.preventDefault();
                this.handleAction('new-folder-from-selection', { selectedRows: selAll, selectedRow: sel });
            } else if (e.ctrlKey && e.altKey && e.shiftKey && e.key.toLowerCase() === 'l' && sel) {
                e.preventDefault();
                this.handleAction('copy-relative-path', { selectedRows: selAll, selectedRow: sel });
            } else if (e.ctrlKey && e.altKey && !e.shiftKey && e.key.toLowerCase() === 'l' && sel) {
                e.preventDefault();
                this.handleAction('copy-path', { selectedRows: selAll, selectedRow: sel });
            } else if (e.ctrlKey && e.altKey && !e.shiftKey && e.key.toLowerCase() === 'b') {
                e.preventDefault();
                this.handleAction('bookmark-pin-current', { selectedRows: selAll, selectedRow: sel });
            } else if (e.ctrlKey && e.altKey && !e.shiftKey && e.key.toLowerCase() === 't') {
                e.preventDefault();
                this.handleAction('terminal-here', { selectedRows: selAll, selectedRow: sel });
            } else if (e.ctrlKey && e.altKey && !e.shiftKey && e.key === 'Enter' && sel) {
                e.preventDefault();
                this.handleAction('open-in-new-tab', { selectedRows: selAll, selectedRow: sel });
            } else if (!e.ctrlKey && !e.metaKey && !e.shiftKey && e.altKey && e.key === 'Enter' && sel) {
                e.preventDefault();
                this.handleAction('get-info', { selectedRows: selAll, selectedRow: sel });
            }
        };
        document.addEventListener('keydown', this._boundKeyboardShortcut);
    }

    _onContextMenuDismiss(e) {
        if (e.type === 'keydown') {
            if (e.key !== 'Escape') return;
            e.preventDefault();
            e.stopPropagation();
        }
        if (e.type === 'resize') {
            this._hideContextMenu();
            return;
        }
        if (e.type === 'mousedown' && this._contextMenuEl && this._contextMenuEl.contains(e.target)) return;
        this._hideContextMenu();
    }

    _hideContextMenu() {
        if (this._contextMenuEl && this._contextMenuEl.parentNode) {
            this._contextMenuEl.parentNode.removeChild(this._contextMenuEl);
        }
        this._contextMenuEl = null;
        document.removeEventListener('keydown', this._boundContextMenuDismiss, true);
        document.removeEventListener('mousedown', this._boundContextMenuDismiss, true);
        window.removeEventListener('resize', this._boundContextMenuDismiss);
    }

    _onContainerContextMenu(e) {
        if (this._invalidFolderMessage) return;
        if (document.querySelector('.fm-js-popup.show')) return;
        const tag = document.activeElement && document.activeElement.tagName;
        if (tag === 'INPUT' || tag === 'TEXTAREA' || tag === 'SELECT') return;

        const rowTr = e.target.closest('tbody tr');
        if (rowTr && this.tableEl && this.tableEl.contains(rowTr)) {
            e.preventDefault();
            const idx = parseInt(rowTr.getAttribute('data-index'), 10);
            if (Number.isNaN(idx) || idx < 0 || idx >= this.rows.length) return;
            this._showContextMenuAt(e.clientX, e.clientY, idx);
            return;
        }
        const empty = e.target.closest('.fm-table-empty');
        if (empty && this.container.contains(empty) && this.rows.length === 0) {
            e.preventDefault();
            this._showContextMenuEmpty(e.clientX, e.clientY);
            return;
        }
        if (this.tableEl && this.tableEl.contains(e.target) && !e.target.closest('tbody tr')) {
            e.preventDefault();
            this._showContextMenuEmpty(e.clientX, e.clientY);
        }
    }

    _showContextMenuEmpty(clientX, clientY) {
        const sc = FmFileManagerTable.ACTION_TOOLTIP_SHORTCUT;
        if (this._inTrashView()) {
            this._renderContextMenu([
                { id: 'refresh', title: 'Refresh', icon: 'bi-arrow-clockwise', shortcut: sc['refresh'] },
                { id: 'empty-trash', title: 'Empty Trash', icon: 'bi-trash3' },
            ], clientX, clientY, { selectedRows: this.getSelectedRows(), selectedRow: this.getSelected() });
            return;
        }
        const items = [
            { id: 'create-new-folder', title: 'Create new folder', icon: 'bi-folder-plus', shortcut: sc['create-new-folder'] },
            { id: 'create-new-file', title: 'Create new file', icon: 'bi-file-earmark-plus', shortcut: sc['create-new-file'] },
            { id: 'bookmark-pin-current', title: 'Bookmark current folder', icon: 'bi-bookmark-plus', shortcut: sc['bookmark-pin-current'] },
            { id: 'bookmark-remove-current', title: 'Remove current bookmark', icon: 'bi-bookmark-x', shortcut: sc['bookmark-remove-current'] },
            { id: 'terminal-here', title: 'Open terminal / shell command', icon: 'bi-terminal', shortcut: sc['terminal-here'] },
            { type: 'separator' },
            { id: 'refresh', title: 'Refresh', icon: 'bi-arrow-clockwise', shortcut: sc['refresh'] },
            { id: 'get-info', title: 'Get info', icon: 'bi-info-circle', shortcut: sc['get-info'] },
            { type: 'separator' },
            { id: 'upload', title: 'Upload', icon: 'bi-upload', shortcut: sc['upload'] },
            { id: 'upload-url', title: 'Upload from URL', icon: 'bi-link-45deg' },
            { id: 'download', title: FM_CONTEXT_MENU_DOWNLOAD_LABEL, icon: 'bi-download', shortcut: sc['download'] },
        ];
        if (this.rows.length > 0) {
            items.push(
                { type: 'separator' },
                { id: 'invert-selection', title: 'Invert selection', icon: 'bi-arrow-left-right', shortcut: sc['invert-selection'] },
                { id: 'select-none', title: 'Select none', icon: 'bi-x-square', shortcut: sc['select-none'] }
            );
        }
        const selAll = this.getSelectedRows();
        const sel    = this.getSelected();
        this._renderContextMenu(items, clientX, clientY, {
            selectedRows: selAll,
            selectedRow: sel,
            getInfoForCurrentFolder: true,
        });
    }

    _showContextMenuAt(clientX, clientY, rowIndex) {
        const items = this._buildContextMenuItems(rowIndex);
        if (items.length === 0) return;
        const selAll = this.getSelectedRows();
        const sel    = this.getSelected();
        this._renderContextMenu(items, clientX, clientY, { selectedRows: selAll, selectedRow: sel });
    }

    _buildContextMenuItems(rowIndex) {
        const row = this.rows[rowIndex];
        if (!row) return [];

        const inSel = this.selectedRows.some((r) => r === row);
        if (!inSel) {
            this._selectionAnchor = rowIndex;
            this._selectRow(rowIndex);
            this._focusRow(rowIndex);
        } else {
            this._focusRow(rowIndex);
        }

        const sc = FmFileManagerTable.ACTION_TOOLTIP_SHORTCUT;
        const rowPart = [];
        const head = [];

        const pushDownloadOnly = () => {
            rowPart.push(
                { type: 'separator' },
                { id: 'download', title: FM_CONTEXT_MENU_DOWNLOAD_LABEL, icon: 'bi-download', shortcut: sc['download'] }
            );
            if (row.type === 'folder') {
                rowPart.push({ id: 'open-in-new-tab', title: 'Open in new tab', icon: 'bi-box-arrow-up-right', shortcut: sc['open-in-new-tab'] });
            }
        };

        if (this._inTrashView()) {
            return [
                { id: 'restore-trashed', title: 'Restore', icon: 'bi-arrow-counterclockwise', shortcut: sc['restore-trashed'] },
                { id: 'delete', title: 'Delete forever', icon: 'bi-trash', shortcut: sc['delete'] },
                { type: 'separator' },
                { id: 'invert-selection', title: 'Invert selection', icon: 'bi-arrow-left-right', shortcut: sc['invert-selection'] },
                { id: 'select-none', title: 'Select none', icon: 'bi-x-square', shortcut: sc['select-none'] },
            ];
        } else {
            if (row.type === 'folder') {
                rowPart.push({ id: 'open', title: 'Open', icon: 'bi-folder2-open', shortcut: sc['open'] });
                rowPart.push({ id: 'bookmark-pin-row-folder', title: 'Bookmark this folder', icon: 'bi-bookmark-plus', shortcut: sc['bookmark-pin-row-folder'] });
                rowPart.push({ id: 'bookmark-remove-row-folder', title: 'Remove folder bookmark', icon: 'bi-bookmark-x', shortcut: sc['bookmark-remove-row-folder'] });
                rowPart.push({ id: 'terminal-here', title: 'Open terminal / shell command', icon: 'bi-terminal', shortcut: sc['terminal-here'] });
            } else if (row.type === 'file') {
                rowPart.push({ id: 'edit-file', title: 'Edit file', icon: 'bi-pencil-square', shortcut: sc['edit-file'] });
            }
            rowPart.push(
                { id: 'rename', title: 'Rename', icon: 'bi-input-cursor-text', shortcut: sc['rename'] },
                { id: 'delete', title: 'Delete', icon: 'bi-trash', shortcut: sc['delete'] }
            );
            pushDownloadOnly();
        }

        const headIds = new Set(head.map((x) => x.id));
        const rowIds = new Set(rowPart.map((x) => x.id));
        const merged = head.slice();
        if (head.length) merged.push({ type: 'separator' });
        merged.push(...rowPart);
        merged.push({ type: 'separator' });

        const toolbar = this.options.actions || [];
        for (let i = 0; i < toolbar.length; i++) {
            const a = toolbar[i];
            if (a.separatorBefore && merged.length && merged[merged.length - 1].type !== 'separator') {
                merged.push({ type: 'separator' });
            }
            if (headIds.has(a.id)) continue;
            if (rowIds.has(a.id)) continue;
            if (a.id === 'create-new-folder' || a.id === 'create-new-file' || a.id === 'upload' || a.id === 'upload-url' || a.id === 'refresh') continue;
            if (a.id === 'download' && (headIds.has('download') || rowIds.has('download'))) continue;
            if (a.id === 'open-in-new-tab' && (headIds.has('open-in-new-tab') || rowIds.has('open-in-new-tab'))) continue;
            if (a.id === 'extract' && rowIds.has('extract')) continue;
            merged.push({
                id: a.id,
                title: a.title,
                icon: a.icon,
                shortcut: a.shortcut || null,
                type: 'action',
            });
        }

        if (this.rows.length > 0) {
            merged.push({ type: 'separator' });
            merged.push({
                id: 'invert-selection',
                title: 'Invert selection',
                icon: 'bi-arrow-left-right',
                shortcut: sc['invert-selection'],
            });
            merged.push({
                id: 'select-none',
                title: 'Select none',
                icon: 'bi-x-square',
                shortcut: sc['select-none'],
            });
        }

        while (merged.length && merged[merged.length - 1].type === 'separator') {
            merged.pop();
        }
        return merged;
    }

    _renderContextMenu(items, clientX, clientY, payload) {
        this._hideContextMenu();

        const menu = document.createElement('div');
        menu.className = 'fm-context-menu';
        menu.setAttribute('role', 'menu');

        let pendingSeparator = false;
        let addedActions = 0;
        items.forEach((it) => {
            if (it.type === 'separator') {
                if (addedActions > 0) pendingSeparator = true;
                return;
            }
            const id = it.id;
            if (this._isContextMenuActionDisabled(id, payload)) return;
            if (pendingSeparator) {
                const sep = document.createElement('div');
                sep.className = 'fm-context-menu-sep';
                sep.setAttribute('role', 'separator');
                menu.appendChild(sep);
                pendingSeparator = false;
            }
            const btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'fm-context-menu-item';
            btn.setAttribute('role', 'menuitem');
            const label = it.shortcut ? `${it.title} (${it.shortcut})` : it.title;
            btn.innerHTML =
                (it.icon ? `<i class="bi ${it.icon}" aria-hidden="true"></i>` : '') +
                `<span class="fm-context-menu-item-label">${this.escapeHtml(it.title)}</span>` +
                (it.shortcut ? `<span class="fm-context-menu-item-kbd">${this.escapeHtml(it.shortcut)}</span>` : '');
            btn.title = label;
            btn.addEventListener('click', (ev) => {
                ev.preventDefault();
                ev.stopPropagation();
                this._hideContextMenu();
                this.handleAction(id, payload);
            });
            menu.appendChild(btn);
            addedActions++;
        });
        if (addedActions === 0) return;

        document.body.appendChild(menu);
        this._contextMenuEl = menu;

        let left = clientX;
        let top  = clientY;
        menu.style.left = `${left}px`;
        menu.style.top  = `${top}px`;

        requestAnimationFrame(() => {
            const rect = menu.getBoundingClientRect();
            const pad = 8;
            if (left + rect.width > window.innerWidth - pad) {
                left = Math.max(pad, window.innerWidth - rect.width - pad);
            }
            if (top + rect.height > window.innerHeight - pad) {
                top = Math.max(pad, window.innerHeight - rect.height - pad);
            }
            if (left < pad) left = pad;
            if (top < pad) top = pad;
            menu.style.left = `${left}px`;
            menu.style.top  = `${top}px`;
        });

        document.addEventListener('keydown', this._boundContextMenuDismiss, true);
        window.addEventListener('resize', this._boundContextMenuDismiss);
        setTimeout(() => {
            document.addEventListener('mousedown', this._boundContextMenuDismiss, true);
        }, 0);
    }

    _isContextMenuActionDisabled(actionId, payload) {
        const selected = payload.selectedRows || [];
        const hasSelection = selected.length > 0;
        const single = selected.length === 1;

        if (actionId === 'create-new-folder' || actionId === 'create-new-file' || actionId === 'refresh' || actionId === 'upload' || actionId === 'upload-url') {
            return false;
        }

        if (actionId === 'get-info' && payload && payload.getInfoForCurrentFolder) {
            return false;
        }
        if (actionId === 'bookmark-pin-current') {
            return this._bookmarkIsSaved(this._currentPath);
        }
        if (actionId === 'bookmark-remove-current') {
            return !this._bookmarkIsSaved(this._currentPath);
        }
        if (actionId === 'bookmark-pin-row-folder') {
            const row = payload.selectedRow;
            const p = this._bookmarkRowFolderPath(row);
            return !p || this._bookmarkIsSaved(p);
        }
        if (actionId === 'bookmark-remove-row-folder') {
            const row = payload.selectedRow;
            const p = this._bookmarkRowFolderPath(row);
            return !p || !this._bookmarkIsSaved(p);
        }
        if (actionId === 'terminal-here') {
            const execOk = typeof window !== 'undefined' && window.fm_exec_available === true;
            if (!execOk) return true;
            const enabled = typeof window !== 'undefined' && window.fm_terminal_here_enabled === true;
            if (!enabled) return false;
            const row = payload.selectedRow;
            if (!row) return false;
            return row.type !== 'folder';
        }

        if (actionId === 'invert-selection') {
            return this.rows.length === 0;
        }
        if (actionId === 'select-none') {
            return !hasSelection;
        }

        const fromActions = FmFileManagerTable.ACTIONS.find((a) => a.id === actionId);
        if (fromActions) {
            if (fromActions.needSelection && !hasSelection) return true;
            if (fromActions.singleOnly && !single) return true;
            if (fromActions.requiresArchive) {
                if (!selected.some((r) => r.type === 'file' && FmFileManagerTable.isArchiveFile(r.name))) return true;
            }
        }

        if (actionId === 'duplicate' || actionId === 'new-folder-from-selection' || actionId === 'change-permissions') {
            return !hasSelection;
        }

        if (actionId === 'open-in-new-tab') {
            if (!single) return true;
            const row = payload.selectedRow;
            return !row || row.type !== 'folder';
        }

        if (actionId === 'bulk-rename') {
            return !hasSelection || single;
        }

        if (actionId === 'restore-trashed') {
            return !selected.some((r) => FmFileManagerTable._trashRowCanRestore(r));
        }

        if (actionId === 'empty-trash') {
            return !(Array.isArray(this._allRows) && this._allRows.length);
        }

        if (actionId === 'open') {
            return !payload.selectedRow || payload.selectedRow.type !== 'folder';
        }

        if (actionId === 'edit-file') {
            return !single || !payload.selectedRow || payload.selectedRow.type !== 'file';
        }

        if (actionId === 'rename' || actionId === 'get-info') {
            if (!single || !payload.selectedRow) return true;
        }

        if (actionId === 'delete' && this._inTrashView()) {
            return !selected.some((r) => r && r.name);
        }

        return false;
    }

    _bindCustomEvents() {
        if (!this._containerContextMenuBound) {
            this._containerContextMenuBound = true;
            this.container.addEventListener('contextmenu', this._boundContainerContextMenu);
        }
        this._bindBreadcrumbEvents();
        this.container.querySelectorAll('[data-empty-action]').forEach(btn => {
            btn.addEventListener('click', () => {
                this.handleAction(btn.dataset.emptyAction, { selectedRows: [], selectedRow: null });
            });
        });
        const goRootBtn = this.container.querySelector('[data-fm-go-root]');
        if (goRootBtn) {
            goRootBtn.addEventListener('click', () => {
                this._invalidFolderMessage = null;
                if (this._onNavigate) this._onNavigate(this._rootDir);
            });
        }
        if (!this.tableEl) return;
        this.tableEl.querySelectorAll('.fm-table-row-action').forEach(btn => {
            btn.addEventListener('click', (e) => {
                e.stopPropagation();
                const actionId = btn.dataset.action;
                const idx      = parseInt(btn.dataset.rowIndex, 10);
                const row      = this.rows[idx];
                if (!row) return;
                this.handleAction(actionId, { selectedRows: [row], selectedRow: row });
            });
        });
        this.tableEl.querySelectorAll('.fm-table-folder-size-trigger').forEach(el => {
            el.addEventListener('click', (e) => {
                e.stopPropagation();
                const idx = parseInt(el.dataset.index, 10);
                const row = this.rows[idx];
                if (row) this._handleFolderSizeClick(row);
            });
        });

        const sizeAllBtn = this.tableEl.querySelector('.fm-table-size-all-btn');
        if (sizeAllBtn) {
            const hasFolders = (this.rows || []).some(r => r.type === 'folder');
            sizeAllBtn.style.opacity = hasFolders ? '' : '0.6';
            sizeAllBtn.style.disabled = hasFolders ? '' : 'disabled';
            sizeAllBtn.addEventListener('click', (e) => {
                e.stopPropagation();
                this._handleGetAllFolderSizes(sizeAllBtn);
            });
        }
    }

    load(path, onLoaded) {
        if (this._pathIsInTrash(path)) path = this._trashRootPath();
        if (norm(path) !== norm(this._currentPath)) {
            this._breadcrumbMidExpanded = false;
            this._pendingKeyboardAnchor = null;
            this._nameFilter = '';
        }
        const openParams = { action: 'open', folder: path };
        requireAuthFetch(this._ajaxUrl + '?' + new URLSearchParams(openParams))
            .then(r => r.json())
            .then(data => {
                if (data && data.status === 'invalid') {
                    this._invalidFolderMessage =
                        (data.msg && String(data.msg)) || 'This folder does not exist or cannot be opened.';
                    this._currentPath = path;
                    this._pendingRestoreChildName = null;
                    this._pendingKeyboardAnchor = null;
                    this.setData({ folders: {}, files: {} });
                    this._fetchAllFolderSizes({ folders: {} });
                    if (typeof onLoaded === 'function') onLoaded();
                    return;
                }

                this._invalidFolderMessage = null;

                this._currentPath = path;
                this.setData(data);
                if (data && typeof data.trash_count === 'number' && this._onTrashCount) {
                    this._onTrashCount(data.trash_count);
                }
                this._fetchAllFolderSizes(data);
                const hadPendingRestore = !!this._pendingRestoreChildName;
                if (this._pendingRestoreChildName) {
                    const rn = this._pendingRestoreChildName;
                    this._pendingRestoreChildName = null;
                    requestAnimationFrame(() => this._focusRowByName(rn));
                }
                if (this._pendingKeyboardAnchor) {
                    if (!hadPendingRestore) {
                        requestAnimationFrame(() => this._applyPendingKeyboardAnchor());
                    } else {
                        this._pendingKeyboardAnchor = null;
                    }
                }
                if (typeof onLoaded === 'function') onLoaded();
            })
            .catch((err) => {
                console.error('Load folder failed', err);
                this._pendingRestoreChildName = null;
                this._pendingKeyboardAnchor = null;
                if (typeof fmUserNotice === 'function') {
                    fmUserNotice({ title: 'Folder', message: 'Could not load folder (network or session error).' });
                }
            });
    }

    buildRows(data) {
        const rows = [];
        if (data && data.trash_view === true) {
            for (const item of (Array.isArray(data.trash_items) ? data.trash_items : [])) {
                if (!item || !item.id) continue;
                const isFolder = item.type === 'folder';
                rows.push({
                    type:              isFolder ? 'folder' : 'file',
                    name:              item.id,
                    displayName:       item.name || item.id,
                    trashOriginalPath: item.original_path || null,
                    size:              isFolder ? null : (item.size != null ? item.size : null),
                    ext:               isFolder ? null : (item.ext || ''),
                    permissions:       item.permissions != null ? item.permissions : null,
                    mtime:             item.deleted_at != null ? item.deleted_at : null,
                    raw:               item,
                });
            }
            return rows;
        }
        const { folders = {}, files = {} } = data || {};
        for (const name of Object.keys(folders)) {
            const meta = folders[name] || {};
            rows.push({
                type:        'folder',
                name,
                size:        null,
                ext:         null,
                permissions: meta.permissions != null ? meta.permissions : null,
                mtime:       meta.mtime       != null ? meta.mtime       : null,
                raw:         meta,
            });
        }
        for (const name of Object.keys(files)) {
            const meta = files[name] || {};
            rows.push({
                type:        'file',
                name,
                size:        meta.size        != null ? meta.size        : null,
                ext:         meta.ext         != null ? meta.ext         : '',
                permissions: meta.permissions != null ? meta.permissions : null,
                mtime:       meta.mtime       != null ? meta.mtime       : null,
                raw:         meta,
            });
        }
        return rows;
    }

    _flashRowHighlight(tr, renameFlash) {
        if (!tr) return;
        tr.classList.remove(
            'fm-table-row-heartbeat-from-non-selected-to-selected',
            'fm-table-row-heartbeat-from-selected-to-non'
        );
        clearTimeout(tr._flashHighlightTimeout);
        if (renameFlash) {
            tr.classList.add('fm-table-row-heartbeat-from-selected-to-non');
            tr._flashHighlightTimeout = setTimeout(
                () => tr.classList.remove('fm-table-row-heartbeat-from-selected-to-non'),
                1000
            );
            return;
        }
        if (tr.classList.contains('fm-table-row-selected')) return;
        tr.classList.add('fm-table-row-heartbeat-from-non-selected-to-selected');
        tr._flashHighlightTimeout = setTimeout(
            () => tr.classList.remove('fm-table-row-heartbeat-from-non-selected-to-selected'),
            1000
        );
    }

    _captureKeyboardAnchorFromRemovedNames(removedNames) {
        if (!removedNames || removedNames.length === 0 || !this.rows || this.rows.length === 0) return;
        const set = new Set(removedNames.map((n) => String(n)));
        const indices = [];
        this.rows.forEach((r, i) => {
            if (r && set.has(r.name)) indices.push(i);
        });
        if (indices.length === 0) return;
        indices.sort((a, b) => a - b);
        this._pendingKeyboardAnchor = {
            minIndex: indices[0],
            maxIndex: indices[indices.length - 1],
            oldLen:   this.rows.length,
        };
    }

    _applyPendingKeyboardAnchor() {
        const a = this._pendingKeyboardAnchor;
        this._pendingKeyboardAnchor = null;
        if (!a || !this.tableEl) return;
        const len = this.rows.length;
        if (len === 0) return;
        let idx;
        if (a.maxIndex < a.oldLen - 1) {
            idx = a.minIndex;
        } else {
            idx = a.minIndex - 1;
        }
        if (idx < 0) idx = 0;
        if (idx >= len) idx = len - 1;
        this._selectionAnchor = idx;
        this._selectRow(idx);
        this._focusRow(idx);
    }

    highlightRowByName(name) {
        if (!this.tableEl || name == null || name === '') return;
        const idx = this.rows.findIndex(r => r.name === name);
        if (idx < 0) return;
        const tr = this.tableEl.querySelector(`tbody tr[data-index="${idx}"]`);
        this._flashRowHighlight(tr);
    }

    highlightRowsByNames(names) {
        if (!Array.isArray(names) || names.length === 0) return;
        const seen = new Set();
        names.forEach((name) => {
            const n = name != null ? String(name) : '';
            if (n === '' || seen.has(n)) return;
            seen.add(n);
            this.highlightRowByName(n);
        });
    }

    _focusRowByName(name) {
        if (!this.tableEl || name == null || name === '') return;
        let idx = this.rows.findIndex(r => r.name === name && r.type === 'folder');
        if (idx < 0) idx = this.rows.findIndex(r => r.name === name);
        if (idx < 0) return;
        this._focusRow(idx, true);
    }

    renameRow(oldName, newName) {
        const idx = this.rows.findIndex(r => r.name === oldName);
        if (idx < 0 || !this.tableEl) return false;

        const row  = this.rows[idx];
        row.name   = newName;
        if (row.type === 'file') {
            row.ext = newName.includes('.') ? newName.split('.').pop() : '';
        }
        if (this._data) {
            if (row.type === 'folder') {
                this._data.folders[newName] = this._data.folders[oldName];
                delete this._data.folders[oldName];
            } else {
                this._data.files[newName] = this._data.files[oldName];
                delete this._data.files[oldName];
            }
        }

        const tr = this.tableEl.querySelector(`tbody tr[data-index="${idx}"]`);
        if (tr) {
            const nameCell = tr.querySelector('.fm-table-name');
            if (nameCell) {
                nameCell.textContent = newName;
                nameCell.title = newName;
            }
            tr.setAttribute('data-name', this.escapeAttr(newName));
            const typeCell = tr.querySelector('.fm-table-type');
            if (typeCell) typeCell.textContent = row.type === 'folder' ? 'Folder' : (row.ext || '—');
            this._flashRowHighlight(tr, true);
        }
        return true;
    }

    setFolderSize(rowName, bytes) {
        const row = this.rows.find(r => r.type === 'folder' && r.name === rowName);
        if (row) row.size = (bytes != null && bytes >= 0) ? bytes : null;

        if (!this.tableEl) return;
        const tr = this.tableEl.querySelector(`tbody tr[data-type="folder"][data-name="${this.escapeAttr(rowName)}"]`);
        if (!tr) return;
        const sizeCell = tr.querySelector('.fm-table-col-size');
        if (!sizeCell) return;
        const formattedSize = (bytes != null && bytes >= 0)
            ? FmFileManagerTable.bytesToSize(bytes)
            : '\u2014';
        const valueEl = sizeCell.querySelector('.fm-table-folder-size-value');
        if (valueEl) {
            valueEl.textContent = formattedSize;
            const trigger = sizeCell.querySelector('.fm-table-folder-size-trigger');
            if (trigger) {
                const icon = trigger.querySelector('i');
                if (icon) icon.classList.remove('fm-rotating');
                trigger.classList.remove('fm-rotating-parent');
                trigger.style.pointerEvents = '';
            }
        } else {
            sizeCell.textContent = formattedSize;
        }
    }

    _setFolderSizeSpinning(rowName) {
        if (!this.tableEl) return;
        const tr = this.tableEl.querySelector(`tbody tr[data-type="folder"][data-name="${this.escapeAttr(rowName)}"]`);
        if (!tr) return;
        const trigger = tr.querySelector('.fm-table-folder-size-trigger');
        if (!trigger) return;
        const icon = trigger.querySelector('i');
        if (icon) icon.classList.add('fm-rotating');
        trigger.classList.add('fm-rotating-parent');
        trigger.style.pointerEvents = 'none';
    }

    _renderRowActionsHtml(row, index) {
        const actions = [];
        const sc = FmFileManagerTable.ACTION_TOOLTIP_SHORTCUT;
        const rowBtn = (a) => {
            const extra = sc[a.id];
            const tip   = extra ? `${a.title} (${extra})` : a.title;
            return `<button type="button" class="fm-table-row-action" data-action="${this.escapeAttr(a.id)}" data-row-index="${index}" title="${this.escapeAttr(tip)}"><i class="bi ${a.icon}"></i></button>`;
        };
        if (this._inTrashView()) {
            if (FmFileManagerTable._trashRowCanRestore(row)) {
                actions.push({ id: 'restore-trashed', icon: 'bi-arrow-counterclockwise', title: 'Restore' });
            }
            actions.push({ id: 'delete', icon: 'bi-trash', title: 'Delete forever' });
            return actions.map(rowBtn).join('');
        }
        if (row.type === 'folder') {
            actions.push({ id: 'open',     icon: 'bi-folder2-open',     title: 'Open' });
            actions.push({ id: 'open-in-new-tab', icon: 'bi-box-arrow-up-right', title: 'Open in new tab' });
        } else {
            actions.push({ id: 'edit-file', icon: 'bi-pencil-square',   title: 'Edit file' });
            actions.push({ id: 'download', icon: 'bi-download',         title: FM_DOWNLOAD_ACTION_TITLE });
            if (FmFileManagerTable.isArchiveFile(row.name)) {
                actions.push({ id: 'extract', icon: 'bi-arrows-expand', title: 'Extract' });
            }
        }
        actions.push({ id: 'rename', icon: 'bi-input-cursor-text', title: 'Rename' });
        actions.push({ id: 'delete', icon: 'bi-trash',             title: 'Delete' });
        return actions.map(rowBtn).join('');
    }

    _handleGetAllFolderSizes(btn) {
        const folderRows = (this.rows || []).filter(r => r.type === 'folder');
        if (folderRows.length === 0) return;

        const btnIcon = btn && btn.querySelector('i');
        if (btn)     btn.disabled = true;
        if (btnIcon) btnIcon.classList.add('fm-rotating');

        let pending    = folderRows.length;

        const onDone = () => {
            pending--;
            if (pending <= 0) {
                if (btn)     btn.disabled = false;
                if (btnIcon) btnIcon.classList.remove('fm-rotating');
            }
        };

        folderRows.forEach(row => {
            const fullPath = this._folderSizeQueryPath(row);
            if (!fullPath) {
                onDone();
                return;
            }
            this._setFolderSizeSpinning(row.name);
            requireAuthFetch(this._ajaxUrl + '?' + new URLSearchParams({ action: 'get-folder-size', folder: fullPath }))
                .then(r => r.json())
                .then(data => {
                    this.setFolderSize(row.name, data.status === 'success' ? data.size : null);
                    onDone();
                })
                .catch(() => { this.setFolderSize(row.name, null); onDone(); });
        });
    }

    _fetchAllFolderSizes(data) {
        if (!this.options.automaticGetFoldersSize || !data) return;
        let entries;
        if (data.trash_view === true) {
            entries = (Array.isArray(data.trash_items) ? data.trash_items : [])
                .filter((it) => it && it.type === 'folder' && it.id)
                .map((it) => [it.id, this._trashFilesPath() + '/' + it.id]);
        } else if (data.folders) {
            entries = Object.keys(data.folders).map((n) => [n, fmJoinPath(this._currentPath, n)]);
        } else {
            return;
        }
        entries.forEach(([folderName, fullPath]) => {
            this._setFolderSizeSpinning(folderName);
            requireAuthFetch(this._ajaxUrl + '?' + new URLSearchParams({ action: 'get-folder-size', folder: fullPath }))
                .then(r => r.json())
                .then(result => {
                    this.setFolderSize(folderName, result.status === 'success' ? result.size : null);
                })
                .catch(() => this.setFolderSize(folderName, null));
        });
    }

    _handleRowNav(row, e) {
        if (!e || this._inTrashView()) return;
        if (e.target.closest('.fm-table-row-action') || e.target.closest('.fm-table-folder-size-trigger')) return;

        if (!e.ctrlKey && !e.shiftKey &&
            row.type === 'file' && e.target.closest('span.fm-table-name') &&
            FmFileManagerTable.isImagePreviewExt(row.ext, row.name)) {
            this._openImageViewerForRow(row);
            return;
        }

        if (row.type !== 'folder') return;

        if (e.target.closest('span.fm-table-name')) {
            this._navigateFolder(fmJoinPath(this._currentPath, row.name));
        }
    }

    _handleRowDoubleClickNav(row, e) {
        if (this._inTrashView()) return;
        if (e && (e.target.closest('.fm-table-row-action') || e.target.closest('.fm-table-folder-size-trigger'))) return;
        if (row.type === 'folder') {
            this._navigateFolder(fmJoinPath(this._currentPath, row.name));
        } else if (FmFileManagerTable.isImagePreviewExt(row.ext, row.name)) {
            this._openImageViewerForRow(row);
        } else {
            const fullPath = fmJoinPath(this._currentPath, row.name);
            this._downloadSingleFileViaIframe(fullPath);
        }
    }

    _collectImageViewerItems() {
        const out = [];
        const rows = Array.isArray(this.rows) ? this.rows : [];
        for (let i = 0; i < rows.length; i++) {
            const r = rows[i];
            if (!r || r.type !== 'file' || !FmFileManagerTable.isImagePreviewExt(r.ext, r.name)) continue;
            const fp = this._fileRowAbsolutePath(r);
            if (!fp) continue;
            out.push({ name: r.name, src: this._fileViewUrl(fp) });
        }
        return out;
    }

    _openImageViewerForRow(row) {
        if (typeof FmImageViewerPopup === 'undefined') return;
        if (!row || row.type !== 'file' || !FmFileManagerTable.isImagePreviewExt(row.ext, row.name)) return;
        const fp = this._fileRowAbsolutePath(row);
        if (!fp) return;
        const items = this._collectImageViewerItems();
        if (items.length === 0) return;
        const mySrc = this._fileViewUrl(fp);
        let startIndex = items.findIndex((it) => it.src === mySrc);
        if (startIndex < 0) startIndex = items.findIndex((it) => it.name === row.name);
        if (startIndex < 0) startIndex = 0;
        new FmImageViewerPopup({ items, startIndex }).show();
    }

    _handleFolderSizeClick(row) {
        const fullPath = this._folderSizeQueryPath(row);
        if (!fullPath) return;
        this._setFolderSizeSpinning(row.name);
        requireAuthFetch(this._ajaxUrl + '?' + new URLSearchParams({ action: 'get-folder-size', folder: fullPath }))
            .then(r => r.json())
            .then(data => {
                this.setFolderSize(row.name, data.status === 'success' ? data.size : null);
            })
            .catch(() => this.setFolderSize(row.name, null));
    }
}

if (typeof window !== 'undefined') {
    window.FmTable            = FmTable;
    window.FmFileManagerTable = FmFileManagerTable;
}


/* ===== App bootstrap ===== */
const mainTableContainer = document.getElementById('fm-table-container');
let mainTable   = null;
let currentPath = root_dir;
let sidebarTree = null;

const CONFIG_STORAGE_KEY = 'fm_table_config_1';
const BOOKMARKS_STORAGE_KEY = 'fm_bookmarks_1';
const BOOKMARKS_MAX_COUNT = 12;

const PERMISSIONS_DISPLAY_MODES = ['octal', 'symbolic', 'both'];

function normalizePermissionsDisplay(v) {
    return PERMISSIONS_DISPLAY_MODES.includes(v) ? v : 'octal';
}

const defaultConfig = {
    showActionsColumn:      true,
    showLastModifiedColumn: true,
    automaticGetFoldersSize: false,
    showImagePreviews:      false,
    sortBy:  'name',
    sortDir: 'asc',
    pageUpDownStep:         10,
    permissionsDisplay:     'octal',
};

let config = loadConfig(defaultConfig);
function loadConfig(base) {
    const baseCfg = base && typeof base === 'object' ? base : defaultConfig;
    try {
        const stored = localStorage.getItem(CONFIG_STORAGE_KEY);
        if (stored) {
            const merged = { ...baseCfg, ...JSON.parse(stored) };
            merged.permissionsDisplay = normalizePermissionsDisplay(merged.permissionsDisplay);
            merged.showImagePreviews = merged.showImagePreviews === true;
            return merged;
        }
    } catch (e) {}
    return { ...baseCfg };
}

function clampPageUpDownStep(n) {
    const x = parseInt(n, 10);
    if (Number.isNaN(x) || x < 1) return 10;
    return Math.min(500, x);
}

function normalizeFileOpsModeUi(mode) {
    const raw = mode != null ? String(mode).toLowerCase().trim() : 'auto';
    if (raw === 'shell') return 'os';
    return (raw === 'php' || raw === 'os') ? raw : 'auto';
}
function saveConfig() {
    try { localStorage.setItem(CONFIG_STORAGE_KEY, JSON.stringify(config)); } catch (e) {}
}

function bookmarkLabelFromPath(path) {
    const n = norm(path);
    const root = norm(root_dir);
    if (!n || n === root) return '/';
    const parts = n.split('/').filter(Boolean);
    return parts.length ? parts[parts.length - 1] : n;
}

function loadBookmarks() {
    try {
        const raw = localStorage.getItem(BOOKMARKS_STORAGE_KEY);
        if (!raw) return [];
        const parsed = JSON.parse(raw);
        if (!Array.isArray(parsed)) return [];
        const out = [];
        const seen = new Set();
        for (let i = 0; i < parsed.length; i++) {
            const it = parsed[i];
            if (!it || typeof it.path !== 'string') continue;
            const p = norm(it.path);
            if (!p || seen.has(p)) continue;
            seen.add(p);
            out.push({
                path: p,
                label: (typeof it.label === 'string' && it.label.trim()) ? it.label.trim() : bookmarkLabelFromPath(p),
            });
        }
        return out;
    } catch (e) {
        return [];
    }
}

function saveBookmarks(bookmarks) {
    try { localStorage.setItem(BOOKMARKS_STORAGE_KEY, JSON.stringify(bookmarks)); } catch (e) {}
}

function bookmarkExists(bookmarks, path) {
    const p = norm(path);
    return bookmarks.some((b) => b && norm(b.path) === p);
}

function addBookmark(bookmarks, path) {
    const p = norm(path);
    if (!p || bookmarkExists(bookmarks, p)) return { ok: false, reason: 'exists', bookmarks };
    if (bookmarks.length >= BOOKMARKS_MAX_COUNT) return { ok: false, reason: 'limit', bookmarks };
    const next = bookmarks.concat([{ path: p, label: bookmarkLabelFromPath(p) }]);
    saveBookmarks(next);
    return { ok: true, bookmarks: next };
}

function removeBookmark(bookmarks, path) {
    const p = norm(path);
    const next = bookmarks.filter((b) => !(b && norm(b.path) === p));
    saveBookmarks(next);
    return next;
}

function performBookmarkAction(kind, path, activePath) {
    const p = norm(path);
    if (!p) return;
    if (kind === 'pin-current' || kind === 'pin-row-folder') {
        const res = addBookmark(window.fmBookmarks || [], p);
        if (!res.ok) {
            if (typeof fmUserNotice === 'function') {
                if (res.reason === 'exists') {
                    fmUserNotice({ title: 'Bookmarks', message: 'This folder is already bookmarked.' });
                } else if (res.reason === 'limit') {
                    fmUserNotice({ title: 'Bookmarks', message: 'Bookmark limit reached (12). Remove one first.' });
                }
            }
            return;
        }
        window.fmBookmarks = res.bookmarks;
        renderBookmarks(window.fmBookmarks, activePath || currentPath);
        return;
    }
    if (kind === 'remove-current' || kind === 'remove-row-folder') {
        window.fmBookmarks = removeBookmark(window.fmBookmarks || [], p);
        renderBookmarks(window.fmBookmarks, activePath || currentPath);
    }
}

function renderBookmarks(bookmarks, activePath) {
    const listEl = document.getElementById('fm-bookmarks-list');
    if (!listEl) return;
    const cur = norm(activePath);
    if (!Array.isArray(bookmarks) || bookmarks.length === 0) {
        listEl.innerHTML = '<div class="fm-bookmarks-empty">No bookmarks yet.</div>';
        return;
    }
    listEl.innerHTML = bookmarks.map((b) => {
        const p = b.path || '';
        const isActive = norm(p) === cur;
        const escPath = escapeHtml(p);
        return (
            '<div class="fm-bookmark-row' + (isActive ? ' fm-bookmark-row--active' : '') + '">' +
                '<button type="button" class="fm-bookmark-open" data-bookmark-path="' + escPath + '" title="' + escPath + '">' +
                    '<i class="bi bi-bookmark-fill" aria-hidden="true"></i><span>' + escapeHtml(b.label || p) + '</span>' +
                '</button>' +
                '<button type="button" class="fm-bookmark-remove" data-bookmark-remove="' + escPath + '" title="Remove bookmark" aria-label="Remove bookmark">' +
                    '<i class="bi bi-x"></i>' +
                '</button>' +
            '</div>'
        );
    }).join('');
}

function pathIsUnderTrash(absPath) {
    const tb = typeof fm_trash_basename !== 'undefined' ? fm_trash_basename : '.trash';
    const r = norm(root_dir).replace(/\/$/, '');
    const prefix = r + '/' + tb;
    const p = norm(absPath).replace(/\/$/, '');
    return p === prefix || p.startsWith(prefix + '/');
}

function syncTrashSidebarButton(path) {
    const btn = document.getElementById('fm-sidebar-trash-btn');
    if (!btn) return;
    const active = pathIsUnderTrash(path);
    btn.classList.toggle('fm-sidebar-trash-btn--active', active);
    btn.setAttribute('aria-pressed', active ? 'true' : 'false');
}

function fmSetTrashCount(n) {
    const count = Math.max(0, Number(n) || 0);
    const label = count === 1 ? '1 item' : count + ' items';
    const btn = document.getElementById('fm-sidebar-trash-btn');
    if (btn) {
        btn.classList.toggle('fm-sidebar-trash-btn--has-items', count > 0);
        const badge = btn.querySelector('.fm-sidebar-trash-count');
        if (badge) badge.textContent = String(count);
        btn.title = count > 0 ? 'Trash: ' + label + ' still stored on the server' : 'Trash (empty)';
    }
    const hint = document.getElementById('fm-sidebar-trash-hint');
    if (hint) {
        hint.textContent = count > 0
            ? label + ' still stored on the server. Restore or empty Trash when you are done.'
            : 'Deleted items wait here until you restore them or delete them forever.';
    }
}

function applyFolderLocation(path) {
    location.hash = 'action=open&folder=' + encodeURIComponent(path);
    if (sidebarTree) sidebarTree.highlight(path);
}

function fetchAndShowFolder(path, callback) {
    mainTable.load(path, () => {
        currentPath = path;
        if (sidebarTree) sidebarTree.render(currentPath);
        syncTrashSidebarButton(currentPath);
        renderBookmarks(window.fmBookmarks || [], currentPath);
        if (typeof callback === 'function') callback();
    });
}

(function () {
    const sidebarBlock = document.getElementById('sidebar-block');
    const resizer = document.getElementById('fm-ls-resizer');
    if (!sidebarBlock || !resizer) return;

    const LS_WIDTH_KEY = 'fm_ls_width';
    const minW = 220;
    const maxW = 520;

    function clamp(n, a, b) {
        n = Number(n);
        if (Number.isNaN(n)) return a;
        return Math.max(a, Math.min(b, n));
    }

    function applyWidth(w) {
        const ww = clamp(w, minW, maxW);
        try { document.documentElement.style.setProperty('--fm-ls-width', ww + 'px'); } catch (e) {}
        sidebarBlock.style.width = ww + 'px';
        try { localStorage.setItem(LS_WIDTH_KEY, String(ww)); } catch (e) {}
    }

    try {
        const stored = parseInt(localStorage.getItem(LS_WIDTH_KEY), 10);
        if (!Number.isNaN(stored)) applyWidth(stored);
    } catch (e) {}

    let isDown = false;
    let startX = 0;
    let startW = 0;

    function onMove(e) {
        if (!isDown) return;
        const dx = e.clientX - startX;
        applyWidth(startW + dx);
    }

    function onUp() {
        isDown = false;
        document.body.classList.remove('fm-is-resizing');
        document.removeEventListener('mousemove', onMove);
    }

    resizer.addEventListener('mousedown', function (e) {
        if (e.button !== 0) return;
        isDown = true;
        document.body.classList.add('fm-is-resizing');
        startX = e.clientX;
        startW = sidebarBlock.getBoundingClientRect().width;
        document.addEventListener('mousemove', onMove);
        document.addEventListener('mouseup', onUp, { once: true });
        e.preventDefault();
    });
})();

const keyboardShortcutsBtn = document.getElementById('fm-keyboard-shortcuts-btn');
if (keyboardShortcutsBtn) {
    keyboardShortcutsBtn.addEventListener('click', () => {
        const sections = (typeof FmFileManagerTable !== 'undefined' && FmFileManagerTable.KEYBOARD_SHORTCUTS_HELP_SECTIONS)
            ? FmFileManagerTable.KEYBOARD_SHORTCUTS_HELP_SECTIONS
            : [];

        function fmKbdSequenceHtml(s) {
            if (s.indexOf('+') !== -1) {
                const parts = s.split(/\s*\+\s*/).map(function (p) { return p.trim(); }).filter(Boolean);
                return parts.map(function (p) {
                    return '<kbd class="fm-kbd">' + FmPopup.escapeHtml(p) + '</kbd>';
                }).join('<span class="fm-shortcuts-plus" aria-hidden="true">+</span>');
            }
            const tokens = s.split(/\s+/).filter(Boolean);
            if (tokens.length > 1) {
                return tokens.map(function (t) {
                    return '<kbd class="fm-kbd">' + FmPopup.escapeHtml(t) + '</kbd>';
                }).join('');
            }
            return '<kbd class="fm-kbd">' + FmPopup.escapeHtml(s) + '</kbd>';
        }

        function fmFormatShortcutKeysHtml(keys) {
            let s = String(keys).trim();
            let macHint = '';
            const macRe = /\([^)]*(?:Mac|⌘|\u2318)[^)]*\)\s*$/;
            const macMatch = s.match(macRe);
            if (macMatch) {
                macHint = '<span class="fm-shortcuts-mac">' + FmPopup.escapeHtml(macMatch[0].trim()) + '</span>';
                s = s.slice(0, s.lastIndexOf('(')).trim();
            }
            const altRe = /^(.+?)\s*\/\s*(.+)$/;
            const altMatch = s.match(altRe);
            if (altMatch && (altMatch[1].indexOf('+') !== -1 || altMatch[2].indexOf('+') !== -1)) {
                return (
                    '<span class="fm-shortcuts-kbd-row">' +
                    fmKbdSequenceHtml(altMatch[1].trim()) +
                    '<span class="fm-shortcuts-alt" aria-hidden="true">/</span>' +
                    fmKbdSequenceHtml(altMatch[2].trim()) +
                    '</span>' + macHint
                );
            }
            return '<span class="fm-shortcuts-kbd-row">' + fmKbdSequenceHtml(s) + '</span>' + macHint;
        }

        function shortcutsListItemsHtml(slice) {
            return slice.map(function (row) {
                return (
                    '<li class="fm-shortcuts-item">' +
                    '<span class="fm-shortcuts-keys">' + fmFormatShortcutKeysHtml(row[0]) + '</span>' +
                    '<span class="fm-shortcuts-desc">' + FmPopup.escapeHtml(row[1]) + '</span>' +
                    '</li>'
                );
            }).join('');
        }

        function sectionBlock(rows) {
            if (!rows.length) return '';
            return '<ul class="fm-shortcuts-list">' + shortcutsListItemsHtml(rows) + '</ul>';
        }

        const body = sections.map(function (sec) {
            return (
                '<section class="fm-shortcuts-section">' +
                '<h3 class="fm-shortcuts-section-title">' + FmPopup.escapeHtml(sec.title) + '</h3>' +
                sectionBlock(sec.rows) +
                '</section>'
            );
        }).join('');

        new FmPopup({
            title:   'Keyboard shortcuts',
            content:
                '<p class="fm-shortcuts-intro">These work when the file list is focused, not while typing in a field or when a dialog is open. The <strong>Page Up</strong> / <strong>Page Down</strong> step is set in Configuration. On macOS, <strong>Ctrl</strong> is Control and <strong>Alt</strong> is Option.</p>' +
                '<div class="fm-shortcuts-sheet">' + body + '</div>',
            buttons: [{ label: 'OK', close: true, primary: true }],
            maxWidth: '840px',
        }).show();
    });
}

const serverInfoBtn = document.getElementById('fm-server-info-btn');
if (serverInfoBtn) {
    serverInfoBtn.addEventListener('click', () => {
        new FmServerInfoPopup({ ajaxUrl: ajax_url }).show();
    });
}

document.getElementById('fm-table-config-btn').addEventListener('click', () => {
    const curOps = normalizeFileOpsModeUi(typeof fm_file_ops_mode !== 'undefined' ? fm_file_ops_mode : 'auto');
    const authOn = typeof enable_auth === 'undefined' ? true : !!enable_auth;
    const execOk = typeof window !== 'undefined' && window.fm_exec_available === true;
    const fileOpsHtml =
        '<fieldset class="fm-cfg-server-fieldset">' +
        '<legend>Heavy file operations</legend>' +
        '<p class="fm-cfg-server-desc">How SoloFileManager runs compress, extract, copy/move, delete-stream, and folder-size. Saved in this PHP file when changed.</p>' +
        '<label class="fm-cfg-fileops-option"><input type="radio" name="cfg-fileops" value="auto" class="cfg-fileops" ' + (curOps === 'auto' ? 'checked' : '') + '> Auto <span class="fm-cfg-perm-example">OS first when exec() is available, then PHP</span></label>' +
        '<label class="fm-cfg-fileops-option"><input type="radio" name="cfg-fileops" value="php" class="cfg-fileops" ' + (curOps === 'php' ? 'checked' : '') + '> PHP only <span class="fm-cfg-perm-example">no OS shell for these actions</span></label>' +
        '<label class="fm-cfg-fileops-option' + (execOk ? '' : ' fm-cfg-fileops-disabled') + '"><input type="radio" name="cfg-fileops" value="os" class="cfg-fileops" ' + (curOps === 'os' ? 'checked' : '') + (execOk ? '' : ' disabled') + '> OS / shell only <span class="fm-cfg-perm-example">' + (execOk ? 'no PHP fallback' : 'unavailable — exec() disabled') + '</span></label>' +
        (authOn
            ? '<label class="fm-cfg-fileops-pass"><span>Password <span class="fm-cfg-hint">(required only if you change the mode above)</span></span><input class="fm-input" name="cfg-fileops-password" type="password" autocomplete="current-password"></label>'
            : '') +
        '<p class="fm-error" data-cfg-fileops-error style="display:none"></p>' +
        '<div class="fm-pwd-hash-box" data-cfg-fileops-fallback style="display:none">' +
        '<p class="fm-hint" data-cfg-fileops-fallback-msg></p>' +
        '<textarea data-cfg-fileops-fallback-line readonly></textarea>' +
        '<div class="fm-pwd-hash-actions">' +
        '<button type="button" class="fm-btn secondary" data-cfg-fileops-copy>Copy line</button>' +
        '<button type="button" class="fm-btn" data-cfg-fileops-reload>I updated the file — reload</button>' +
        '</div></div>' +
        '</fieldset>';
    const p = new FmPopup({
            title:'Configuration',
            maxWidth: '620px',
            submitOnEnter: true,
            content: fileOpsHtml +
            '<p><label><input type="checkbox" name="cfg-actions" class="cfg-actions" ' + (config.showActionsColumn ? 'checked' : '') + '> Show row actions column</label></p>' +
            '<p><label><input type="checkbox" name="cfg-mtime" class="cfg-mtime" ' + (config.showLastModifiedColumn ? 'checked' : '') + '> Show last modified column</label></p>' +
            '<p><label><input type="checkbox" name="cfg-auto" class="cfg-auto" ' + (config.automaticGetFoldersSize ? 'checked' : '') + '> Automatic get folders size</label></p>' +
            '<p><label><input type="checkbox" name="cfg-imgprev" class="cfg-imgprev" ' + (config.showImagePreviews ? 'checked' : '') + '> Show image previews in list <span class="fm-cfg-hint">(loads original file in the icon column; may be slow for large folders)</span></label></p>' +
            '<fieldset class="fm-cfg-permissions-fieldset">' +
            '<legend>Permissions column</legend>' +
            '<label class="fm-cfg-perm-option"><input type="radio" name="cfg-perm-display" value="octal" class="cfg-perm-display" ' + (normalizePermissionsDisplay(config.permissionsDisplay) === 'octal' ? 'checked' : '') + '> Octal <span class="fm-cfg-perm-example">e.g. 0644</span></label>' +
            '<label class="fm-cfg-perm-option"><input type="radio" name="cfg-perm-display" value="symbolic" class="cfg-perm-display" ' + (normalizePermissionsDisplay(config.permissionsDisplay) === 'symbolic' ? 'checked' : '') + '> Symbolic <span class="fm-cfg-perm-example">e.g. -rw-r--r-- or drwxr-xr-x</span></label>' +
            '<label class="fm-cfg-perm-option"><input type="radio" name="cfg-perm-display" value="both" class="cfg-perm-display" ' + (normalizePermissionsDisplay(config.permissionsDisplay) === 'both' ? 'checked' : '') + '> Both <span class="fm-cfg-perm-example">e.g. 0644 on one line, -rw-r--r-- below</span></label>' +
            '</fieldset>' +
            '<p><label class="fm-cfg-pagestep-label">Page Up / Down step (rows) ' +
            '<input type="number" name="cfg-pagestep" class="cfg-pagestep" min="1" max="500" step="1" value="' +
            String(clampPageUpDownStep(config.pageUpDownStep)).replace(/"/g, '&quot;') + '"></label></p>',
        buttons: [
            { label:'Cancel', close:true },
            { label:'Apply', primary:true, close:false, onClick() {
                const cbA = p.querySelector('.cfg-actions');
                const cbM = p.querySelector('.cfg-mtime');
                const cbS = p.querySelector('.cfg-auto');
                const cbImg = p.querySelector('.cfg-imgprev');
                const stepInp = p.querySelector('.cfg-pagestep');
                const permRadio = p.querySelector('.cfg-perm-display:checked');
                const opsRadio = p.querySelector('.cfg-fileops:checked');
                const opsPass = p.querySelector('[name="cfg-fileops-password"]');
                const opsErr = p.querySelector('[data-cfg-fileops-error]');
                const opsFallback = p.querySelector('[data-cfg-fileops-fallback]');
                const opsFallbackMsg = p.querySelector('[data-cfg-fileops-fallback-msg]');
                const opsFallbackLine = p.querySelector('[data-cfg-fileops-fallback-line]');
                if (opsErr) { opsErr.style.display = 'none'; opsErr.textContent = ''; }
                if (opsFallback) opsFallback.style.display = 'none';
                if (cbA) config.showActionsColumn = cbA.checked;
                if (cbM) config.showLastModifiedColumn = cbM.checked;
                if (cbS) config.automaticGetFoldersSize = cbS.checked;
                if (cbImg) config.showImagePreviews = cbImg.checked;
                if (permRadio) config.permissionsDisplay = normalizePermissionsDisplay(permRadio.value);
                if (stepInp) config.pageUpDownStep = clampPageUpDownStep(stepInp.value);
                if (mainTable) {
                    mainTable.options.pageUpDownStep = config.pageUpDownStep;
                    mainTable.options.permissionsDisplay = config.permissionsDisplay;
                    mainTable.options.showImagePreviews = config.showImagePreviews === true;
                }
                saveConfig();

                const nextOps = normalizeFileOpsModeUi(opsRadio ? opsRadio.value : curOps);
                if (nextOps === curOps) {
                    p.hide();
                    fetchAndShowFolder(currentPath);
                    return;
                }
                if (nextOps === 'os' && !execOk) {
                    if (opsErr) {
                        opsErr.textContent = 'OS-only mode needs exec(). Choose Auto or PHP only.';
                        opsErr.style.display = 'block';
                    }
                    return;
                }
                if (authOn && (!opsPass || !(opsPass.value || '').length)) {
                    if (opsErr) {
                        opsErr.textContent = 'Password is required to change file ops mode.';
                        opsErr.style.display = 'block';
                    }
                    return;
                }
                const form = new FormData();
                form.append('action', 'set-file-ops-mode');
                form.append('mode', nextOps);
                form.append('password', opsPass ? (opsPass.value || '') : '');
                return fetch(ajax_url, fmPostInit(form))
                    .then(r => r.json())
                    .then(data => {
                        if (data.status !== 'success') {
                            if (opsErr) {
                                opsErr.textContent = data.msg || 'Could not update file ops mode';
                                opsErr.style.display = 'block';
                            }
                            return;
                        }
                        if (data.saved) {
                            location.reload();
                            return;
                        }
                        if (opsFallback && opsFallbackMsg && opsFallbackLine) {
                            opsFallbackMsg.textContent = data.msg || 'Edit the script manually:';
                            opsFallbackLine.value = data.line || ('$FM_FILE_OPS_MODE = \'' + nextOps + '\';');
                            opsFallback.style.display = 'block';
                        }
                    })
                    .catch(() => {
                        if (opsErr) {
                            opsErr.textContent = 'Could not update file ops mode';
                            opsErr.style.display = 'block';
                        }
                    });
            }}
        ],
        onAfterShow() {
            const copyBtn = p.querySelector('[data-cfg-fileops-copy]');
            const reloadBtn = p.querySelector('[data-cfg-fileops-reload]');
            const lineEl = p.querySelector('[data-cfg-fileops-fallback-line]');
            if (copyBtn && lineEl) {
                copyBtn.addEventListener('click', () => {
                    lineEl.select();
                    try {
                        if (navigator.clipboard && navigator.clipboard.writeText) {
                            navigator.clipboard.writeText(lineEl.value);
                        } else {
                            document.execCommand('copy');
                        }
                    } catch (e) {}
                });
            }
            if (reloadBtn) {
                reloadBtn.addEventListener('click', () => location.reload());
            }
        }
    });
    p.show();
});

window.addEventListener('hashchange', () => {
    fetchAndShowFolder(getCurrentPath());
});

(function init() {
    window.fmBookmarks = loadBookmarks();
    window.fmBookmarkApi = {
        isBookmarked: (path) => bookmarkExists(window.fmBookmarks || [], path),
    };
    const initialPath = getCurrentPath();
    mainTable = new FmFileManagerTable(mainTableContainer, [], {
        ajaxUrl: ajax_url,
        rootDir: root_dir,
        execAvailable: typeof fm_exec_available !== 'undefined' ? fm_exec_available : true,
        sortBy: config.sortBy || 'name',
        sortDir: config.sortDir || 'asc',
        showActionsColumn: config.showActionsColumn || true,
        showLastModifiedColumn: config.showLastModifiedColumn || true,
        automaticGetFoldersSize: config.automaticGetFoldersSize || false,
        showImagePreviews: config.showImagePreviews === true,
        permissionsDisplay: normalizePermissionsDisplay(config.permissionsDisplay),
        pageUpDownStep: clampPageUpDownStep(config.pageUpDownStep),
        onSortChange: ({ sortBy, sortDir }) => {
            config.sortBy  = sortBy;
            config.sortDir = sortDir;
            saveConfig();
        },
        onNavigate: applyFolderLocation,
        onTrashCount: fmSetTrashCount,
    });

    const sidebarTreeDnDEl = document.getElementById('sidebar-tree');
    if (sidebarTreeDnDEl && typeof mainTable.attachSidebarDnD === 'function') {
        mainTable.attachSidebarDnD(sidebarTreeDnDEl);
    }

    mainTable.afterRefresh = ({ currentPath: path }) => {
        if (sidebarTree) {
            sidebarTree.reloadFolderTree(path);
        }
    };
    mainTable.afterDuplicate = ({ currentPath: path }) => {
        if (sidebarTree) {
            sidebarTree.reloadFolderTree(path);
        }
    };
    mainTable.afterUpload = ({ currentPath: path }) => {
        if (sidebarTree) {
            sidebarTree.reloadFolderTree(path);
        }
    };
    mainTable.afterExtract = ({ currentPath: path }) => {
        if (sidebarTree) {
            sidebarTree.reloadFolderTree(path);
        }
    };
    mainTable.afterRestoreTrashed = ({ currentPath: path, restored }) => {
        if (!sidebarTree || !Array.isArray(restored)) return;
        let needFullReload = false;
        restored.forEach((x) => {
            if (x && x.type === 'folder' && x.parent && !sidebarTree.addFolder(x.parent, x.name, root_dir)) {
                needFullReload = true;
            }
        });
        if (needFullReload) {
            sidebarTree.reloadFolderTree(path);
        } else {
            sidebarTree.render(path);
        }
    };
    mainTable.afterCreateNewFolder = ({ name, currentPath: path }) => {
        if (sidebarTree) {
            sidebarTree.addFolder(path, name, root_dir);
            sidebarTree.render(path);
        }
    };

    document.addEventListener('fm-folder-created', (e) => {
        const d = e.detail || {};
        const name = d.name;
        const parentPath = d.parentPath;
        if (!sidebarTree || !name) return;
        if (parentPath === undefined || parentPath === null) return;
        const p = (mainTable && mainTable._currentPath) ? mainTable._currentPath : currentPath;
        const added = sidebarTree.addFolder(parentPath, name, root_dir);
        if (!added) {
            sidebarTree.reloadFolderTree(p);
        } else {
            sidebarTree.render(p);
        }
    });

    mainTable.afterDelete = ({ names, currentPath: path }) => {
        if (sidebarTree && names.length) {
            sidebarTree.removePaths(path, new Set(names));
            sidebarTree.render(path);
        }
    };
    mainTable.afterRename = ({ oldName, newName, currentPath: path }) => {
        if (!sidebarTree) return;
        const r = sidebarTree.renameFolder(path, oldName, newName, root_dir);
        if (!r) return;
        const o = norm(r.oldPath);
        const n = norm(r.newPath);
        const expanded = sidebarTree.getExpandedPaths();
        const mapped = new Set();
        expanded.forEach((ep) => {
            const en = norm(ep);
            if (en === o) mapped.add(n);
            else if (en.startsWith(o + '/')) mapped.add(n + en.slice(o.length));
            else mapped.add(ep);
        });
        sidebarTree.render(path, mapped);
    };

    sidebarTree = new FmSidebarTree({
        el:          document.getElementById('sidebar-tree'),
        ajaxUrl:     ajax_url,
        rootDir:     root_dir,
        onNodeClick: (path) => mainTable._navigateFolder(path),
        collapseBtn: document.getElementById('fm-ls-collapse-btn'),
    });

    const trashSidebarBtn = document.getElementById('fm-sidebar-trash-btn');
    if (trashSidebarBtn) {
        trashSidebarBtn.addEventListener('click', () => {
            const tb = typeof fm_trash_basename !== 'undefined' ? fm_trash_basename : '.trash';
            const base = norm(root_dir).replace(/\/$/, '');
            mainTable._navigateFolder(base + '/' + tb);
        });
    }

    const pinCurrentBtn = document.getElementById('fm-bookmarks-pin-current');
    if (pinCurrentBtn) {
        pinCurrentBtn.addEventListener('click', () => {
            performBookmarkAction('pin-current', currentPath || root_dir, currentPath);
        });
    }

    const bookmarksList = document.getElementById('fm-bookmarks-list');
    if (bookmarksList) {
        bookmarksList.addEventListener('click', (e) => {
            const removeBtn = e.target.closest('[data-bookmark-remove]');
            if (removeBtn) {
                const p = removeBtn.getAttribute('data-bookmark-remove');
                performBookmarkAction('remove-current', p, currentPath);
                return;
            }
            const openBtn = e.target.closest('[data-bookmark-path]');
            if (openBtn) {
                const p = openBtn.getAttribute('data-bookmark-path');
                if (p) mainTable._navigateFolder(p);
            }
        });
    }
    document.addEventListener('fm-bookmark-action', (e) => {
        const d = e.detail || {};
        performBookmarkAction(d.kind, d.path, currentPath);
    });
    renderBookmarks(window.fmBookmarks, initialPath);

    if (sidebarTree) {
        sidebarTree.reloadFolderTree(initialPath);
    }
    fetchAndShowFolder(initialPath);
})();

</script>
</body>
</html>
