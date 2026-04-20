<?php
header('Content-Type: text/plain');
echo "Checking Server Environment...\n\n";

$extensions = ['pdo_sqlite', 'curl', 'mbstring', 'json', 'openssl'];
foreach ($extensions as $ext) {
    echo "[EXT] $ext: " . (extension_loaded($ext) ? "OK" : "MISSING") . "\n";
}

echo "\nChecking File Permissions...\n";
$dirs = ['var', 'var/logs', 'public/uploads', 'public/logs', 'vendor'];
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

echo "\nTesting Database Connection...\n";
try {
    $dbPath = __DIR__ . '/var/database.sqlite';
    if (!is_dir(__DIR__ . '/var')) mkdir(__DIR__ . '/var', 0775, true);
    $db = new PDO('sqlite:' . $dbPath);
    echo "[DB] SQLite Connection: OK\n";
} catch (Exception $e) {
    echo "[DB] Error: " . $e->getMessage() . "\n";
}
