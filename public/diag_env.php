<?php
declare(strict_types=1);

header('Content-Type: text/plain');

echo "=== KEEN Environment Diagnostics ===\n\n";

// 1. Check PHP Version & SAPI
echo "[1] Server Info:\n";
echo "    PHP Version: " . PHP_VERSION . "\n";
echo "    PHP SAPI:    " . PHP_SAPI . "\n";
echo "    FPM Support: " . (function_exists('fastcgi_finish_request') ? "ENABLED (Recommended)" : "NOT FOUND (Using fallback)") . "\n";

// 2. Check Directories existence and permissions
echo "\n[2] Directory & Permission Check:\n";
$dirs = [
    'public' => __DIR__,
    'var'    => __DIR__ . '/../var',
    'logs'   => __DIR__ . '/../var/logs',
    'public/logs' => __DIR__ . '/logs',
];

foreach ($dirs as $label => $path) {
    if (!is_dir($path)) {
        echo "    [-] $label: FOLDER MISSING at $path\n";
    } else {
        $writeable = is_writable($path);
        echo "    [" . ($writeable ? "+" : "!") . "] $label: " . ($writeable ? "Writable" : "READ ONLY (Error)") . " - $path\n";
    }
}

// 3. Test File Creation 
echo "\n[3] Write Test:\n";
$testFile = __DIR__ . '/__diag_test.txt';
if (@file_put_contents($testFile, "Test at " . date('Y-m-d H:i:s'))) {
    echo "    [+] public: Success (File created)\n";
    unlink($testFile);
} else {
    echo "    [!] public: FAILED to create file. Check folder ownership/permissions.\n";
}

// 4. Settings Check
echo "\n[4] Settings Check:\n";
$settingsFile = __DIR__ . '/settings.json';
if (file_exists($settingsFile)) {
    echo "    [+] settings.json found.\n";
    $json = json_decode(file_get_contents($settingsFile), true);
    if ($json) {
        echo "    [+] settings.json is valid JSON.\n";
        echo "    [*] Open Line ID: " . ($json['open_line_id'] ?? 'MISSING') . "\n";
    } else {
        echo "    [!] settings.json is CORRUPT or empty.\n";
    }
} else {
    echo "    [-] settings.json MISSING. Run install.php or fix_webhooks.php.\n";
}

echo "\n=== DIAGNOSTICS COMPLETE ===\n";
