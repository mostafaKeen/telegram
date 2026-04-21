<?php
declare(strict_types=1);

require_once(__DIR__ . '/crest.php');

// Define the handler URL (must be public-facing)
$protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host = $_SERVER['HTTP_HOST'];
$scriptDir = dirname($_SERVER['SCRIPT_NAME']);
$handlerUrl = $protocol . '://' . $host . $scriptDir . '/webhook_sms.php';

echo "Registering SMS provider with handler: " . $handlerUrl . "<br>";

$result = CRest::call('messageservice.sender.add', [
    'CODE'        => 'keen_telegram_sms',
    'TYPE'        => 'SMS',
    'HANDLER'     => $handlerUrl,
    'NAME'        => 'Keen Telegram Bridge',
    'DESCRIPTION' => 'Sends SMS messages via the linked Telegram Bot session.'
]);

if (isset($result['result']) && $result['result'] === true) {
    echo "<b>Success:</b> SMS provider 'Keen Telegram Bridge' registered successfully.<br>";
} else {
    echo "<b>Error:</b> Failed to register SMS provider.<br>";
    echo "<pre>" . print_r($result, true) . "</pre>";
    if (isset($result['error']) && $result['error'] === 'SCOPE_NOT_ALLOWED') {
        echo "<p style='color:red;'><b>Critical:</b> The 'messageservice' scope is not enabled for this application. Please add it in Bitrix24 app settings.</p>";
    }
}
