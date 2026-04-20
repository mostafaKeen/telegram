<?php
require_once(__DIR__ . '/../src/TelegramBridge/bootstrap.php');
header('Content-Type: text/plain');
echo "Checking Server Environment...\n\n";

$extensions = ['pdo_sqlite', 'curl', 'mbstring', 'json', 'openssl'];
foreach ($extensions as $ext) {
    echo "[EXT] $ext: " . (extension_loaded($ext) ? "OK" : "MISSING") . "\n";
}

echo "\nChecking File Permissions...\n";
$dirs = ['var', 'var/logs', 'var/storage', 'var/storage/leads', 'public/uploads', 'public/logs', 'vendor'];
foreach ($dirs as $dir) {
    $fullPath = __DIR__ . '/../' . $dir;
    if (is_dir($fullPath)) {
        echo "[DIR] $dir: EXISTS, WRITABLE: " . (is_writable($fullPath) ? "YES" : "NO") . "\n";
        if ($dir === 'vendor' && file_exists($fullPath . '/autoload.php')) {
            echo "      [OK] Autoload found.\n";
        }
    } else {
        echo "[DIR] $dir: MISSING (Try creating/checking manually)\n";
    }
}

echo "\nChecking .env...\n";
if (file_exists(__DIR__ . '/.env')) {
    echo "[ENV] .env file found.\n";
} else if (file_exists(__DIR__ . '/../.env')) {
    echo "[ENV] .env file found in parent dir.\n";
} else {
    echo "[ENV] .env NOT FOUND.\n";
}

echo "\nTesting JSON Storage...\n";
try {
    $storageFile = __DIR__ . '/../var/storage/sequence.json';
    if (is_dir(__DIR__ . '/../var/storage')) {
        echo "[STORAGE] Directory: OK\n";
        echo "[STORAGE] Writable: " . (is_writable(__DIR__ . '/../var/storage') ? "YES" : "NO") . "\n";
    } else {
        echo "[STORAGE] Error: Directory missing\n";
    }
} catch (Exception $e) {
    echo "[STORAGE] Error: " . $e->getMessage() . "\n";
}
