<?php
header('Content-Type: text/plain');
ini_set('display_errors', '1');
error_reporting(E_ALL);

echo "Checking environment...\n";
echo "Current File: " . __FILE__ . "\n";
echo "Current Dir: " . __DIR__ . "\n";

require_once(__DIR__ . '/../src/TelegramBridge/bootstrap.php');

echo "Storage Instance: " . get_class($storage) . "\n";

$storagePath = __DIR__ . '/../var/storage';
echo "Checking Storage Path: $storagePath\n";

if (is_dir($storagePath)) {
    echo "[OK] Directory exists.\n";
} else {
    echo "[FAIL] Directory does not exist. Attempting manual creation...\n";
    if (mkdir($storagePath, 0775, true)) {
        echo "[OK] Created directory manually.\n";
    } else {
        echo "[FAIL] Could not create directory. Reason: " . print_r(error_get_last(), true) . "\n";
    }
}

echo "\nDone.";
