<?php
header('Content-Type: text/plain');
require_once(__DIR__ . '/crest.php');

echo "=== KEEN Telegram — Full Sync ===\n\n";

// 1. Detect current domain
$protocol = "https://";
$host = $_SERVER['HTTP_HOST'];
$basePath = dirname($_SERVER['REQUEST_URI']);
if ($basePath === '\\' || $basePath === '/') {
    $basePath = '';
}
$baseUrl = $protocol . $host . $basePath;
$handlerUrl         = $baseUrl . '/webhook_bitrix_event.php';
$telegramWebhookUrl = $baseUrl . '/webhook_telegram.php';

echo "[1/6] Domain: $baseUrl\n";
echo "      Event Handler:    $handlerUrl\n";
echo "      Telegram Webhook: $telegramWebhookUrl\n";

// 2. Register / update the connector
echo "\n[2/6] Registering connector 'telegram_bridge'...\n";
$connRes = CRest::call('imconnector.register', [
    'ID'   => 'telegram_bridge',
    'NAME' => 'Keen Telegram',
    'ICON' => [
        'DATA_IMAGE' => 'data:image/svg+xml;charset=US-ASCII,%3Csvg%20xmlns%3D%22http%3A%2F%2Fwww.w3.org%2F2000%2Fsvg%22%20viewBox%3D%220%200%2040%2040%22%3E%3Ccircle%20cx%3D%2220%22%20cy%3D%2220%22%20r%3D%2220%22%20fill%3D%22%232CA5E0%22%2F%3E%3Cpath%20fill%3D%22%23fff%22%20d%3D%22M28.4%2013.5l-2.8%2013.3c-.2.9-.7%201.1-1.5.7l-4.3-3.2-2.1%202c-.2.2-.4.4-.9.4l.3-4.4%208-7.2c.3-.3-.1-.5-.5-.2L14.7%2021.1l-4.3-1.3c-.9-.3-.9-.9.2-1.3l16.8-6.5c.8-.5%201.5-.3%201%201.5z%22%2F%3E%3C%2Fsvg%3E',
        'COLOR' => '#2CA5E0',
        'SIZE' => '90%',
        'POSITION' => 'center',
    ],
    'PLACEMENT_HANDLER' => $baseUrl . '/connector_setup.php',
]);
echo "      Result: " . (isset($connRes['result']['result']) ? "OK" : ($connRes['error_description'] ?? 'FAILED')) . "\n";

// 3. Bind the Bitrix24 event
echo "\n[3/6] Binding OnImConnectorMessageAdd event...\n";
// Unbind all old handlers first
$unbindRes = CRest::call('event.unbind', ['event' => 'OnImConnectorMessageAdd']);
echo "      Unbind old: " . (isset($unbindRes['result']) ? "OK" : "SKIP") . "\n";

$bindRes = CRest::call('event.bind', [
    'event'   => 'OnImConnectorMessageAdd',
    'handler' => $handlerUrl,
]);
if (isset($bindRes['result'])) {
    echo "      Bind new:   OK\n";
} else {
    $errDesc = $bindRes['error_description'] ?? 'Unknown';
    // "Handler already binded" is OK — it means it's already pointing to the correct URL
    if (strpos($errDesc, 'already binded') !== false) {
        echo "      Bind new:   ALREADY SET (OK)\n";
    } else {
        echo "      Bind new:   FAILED - $errDesc\n";
    }
}

// 4. Detect Open Line and activate connector
echo "\n[4/6] Detecting Open Lines...\n";
$lines = CRest::call('imopenlines.config.list.get', []);
$lineId = 1;
if (!empty($lines['result'])) {
    $lineId = $lines['result'][0]['ID'];
    echo "      Using Open Line ID: $lineId (" . ($lines['result'][0]['LINE_NAME'] ?? '?') . ")\n";
} else {
    echo "      No Open Lines found, defaulting to ID 1\n";
}

CRest::call('imconnector.activate', [
    'CONNECTOR' => 'telegram_bridge',
    'LINE'      => $lineId,
    'ACTIVE'    => 1,
]);

CRest::call('imconnector.connector.data.set', [
    'CONNECTOR' => 'telegram_bridge',
    'LINE'      => $lineId,
    'DATA'      => [
        'id'     => 'telegram_bridge_line_' . $lineId,
        'url_im' => '',
        'name'   => 'Keen Telegram',
    ],
]);
echo "      Connector activated on line $lineId\n";

// 5. Reset Telegram webhook (delete + set to prevent 409 Conflict)
echo "\n[5/6] Resetting Telegram webhook...\n";
$botToken = TELEGRAM_BOT_TOKEN;
$delResult = @file_get_contents("https://api.telegram.org/bot{$botToken}/deleteWebhook");
$delData = json_decode($delResult, true);
echo "      Delete old: " . ($delData['ok'] ? 'OK' : 'FAILED') . "\n";

$setResult = @file_get_contents(
    "https://api.telegram.org/bot{$botToken}/setWebhook?url=" . urlencode($telegramWebhookUrl)
);
$setData = json_decode($setResult, true);
echo "      Set new:    " . ($setData['ok'] ? 'OK' : 'FAILED') . " - " . ($setData['description'] ?? '') . "\n";

// Verify
$infoResult = @file_get_contents("https://api.telegram.org/bot{$botToken}/getWebhookInfo");
$infoData = json_decode($infoResult, true);
echo "      Verified:   " . ($infoData['result']['url'] ?? 'UNKNOWN') . "\n";
$pending = $infoData['result']['pending_update_count'] ?? 0;
if ($pending > 0) {
    echo "      Pending updates: $pending (will be delivered now)\n";
}

// 6. Save settings — MERGE to preserve OAuth tokens
echo "\n[6/6] Saving settings...\n";
$settingsFile = __DIR__ . '/settings.json';
$existing = [];
if (file_exists($settingsFile)) {
    $existing = json_decode(file_get_contents($settingsFile), true) ?: [];
}
$existing['open_line_id']        = $lineId;
$existing['server_url']          = $baseUrl;
$existing['telegram_webhook_url'] = $telegramWebhookUrl;
$existing['last_sync']           = date('Y-m-d H:i:s');
if (file_put_contents($settingsFile, json_encode($existing, JSON_PRETTY_PRINT))) {
    echo "      Settings saved (tokens preserved)\n";
} else {
    echo "      ERROR: settings.json NOT writable!\n";
}

echo "\n=== SYNC COMPLETE ===\n";
echo "Next steps:\n";
echo "  1. Send a message from Telegram to your bot\n";
echo "  2. Check Bitrix24 Open Lines for the incoming message\n";
echo "  3. Reply from Bitrix24 and verify it reaches Telegram\n";

