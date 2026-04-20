<?php
header('Content-Type: text/plain');

$files = ['settings.json', 'settings.php.log'];
echo "Cleaning up old connection tokens...\n\n";

foreach ($files as $file) {
    if (file_exists(__DIR__ . '/' . $file)) {
        if (unlink(__DIR__ . '/' . $file)) {
            echo "[OK] Deleted $file\n";
        } else {
            echo "[ERROR] Could not delete $file. Please delete it manually via FTP.\n";
        }
    } else {
        echo "[SKIP] $file does not exist.\n";
    }
}

echo "\nCleanup Complete. Now follow the Bitrix24 Re-installation steps provided.";
