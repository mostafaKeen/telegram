<?php
header('Content-Type: text/plain');
require_once(__DIR__ . '/crest.php');

echo "Starting Live Sync...\n\n";

// 1. Detect current domain
$protocol = "https://";
$host = $_SERVER['HTTP_HOST'];
$basePath = dirname($_SERVER['REQUEST_URI']);
$baseUrl = $protocol . $host . $basePath;
$handlerUrl = $baseUrl . '/webhook_bitrix_event.php';

echo "Local Domain Detected: $baseUrl\n";

// 2. Clear old event and bind new one
echo "Unbinding old events...\n";
CRest::call('event.unbind', ['event' => 'OnImConnectorMessageAdd']);

echo "Binding new live event handler...\n";
$bindRes = CRest::call('event.bind', [
    'event' => 'OnImConnectorMessageAdd',
    'handler' => $handlerUrl
]);
echo "Result: " . (isset($bindRes['result']) ? "SUCCESS" : "FAILED") . "\n";

// 3. Detect correct Open Line
echo "\nDetecting Open Line ID...\n";
$lines = CRest::call('imopenlines.config.list.get', []);
$lineId = 1;
if (!empty($lines['result'])) {
    $lineId = $lines['result'][0]['ID'];
    echo "Found Open Line ID: $lineId\n";
} else {
    echo "No Open Lines found, defaulting to 1.\n";
}

// 4. Update Connector Settings
echo "\nUpdating Connector Activation...\n";
CRest::call('imconnector.activate', [
    'CONNECTOR' => 'telegram_bridge',
    'LINE' => $lineId,
    'ACTIVE' => 1
]);

// 5. Save settings locally
echo "\nSaving settings to settings.json...\n";
$settingsFile = __DIR__ . '/settings.json';
$settings = [
    'open_line_id' => $lineId,
    'server_url' => $baseUrl,
    'last_sync' => date('Y-m-d H:i:s')
];
if(file_put_contents($settingsFile, json_encode($settings, JSON_PRETTY_PRINT))) {
    echo "Settings saved successfully.\n";
} else {
    echo "ERROR: settings.json is NOT writable!\n";
}

echo "\n--- SYNC COMPLETE ---\n";
echo "Now test a message from Bitrix24 to Telegram.";
