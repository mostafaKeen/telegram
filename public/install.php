<?php
declare(strict_types=1);

ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

require_once(__DIR__ . '/crest.php');

$install_result = CRest::installApp();

$is_installed = false;
$error_message = '';

// Force HTTPS as it is required by both Bitrix24 and Telegram APIs
$protocol = "https://";
$host = $_SERVER['HTTP_HOST'];
$basePath = dirname($_SERVER['REQUEST_URI']);
if ($basePath === '\\' || $basePath === '/') {
    $basePath = '';
}

$baseUrl = $protocol . $host . $basePath;
$connectorSetupUrl = $baseUrl . '/connector_setup.php';
$eventHandlerUrl = $baseUrl . '/webhook_bitrix_event.php';
$telegramWebhookUrl = $baseUrl . '/webhook_telegram.php';

if ($install_result['install'] === true) {

    // 1. Register the Telegram Custom Connector
    $connectorRes = CRest::call(
        'imconnector.register',
        [
            'ID' => 'telegram_bridge',
            'NAME' => 'Keen Telegram',
            'ICON' => [
                'DATA_IMAGE' => 'data:image/svg+xml;charset=US-ASCII,%3Csvg%20xmlns%3D%22http%3A%2F%2Fwww.w3.org%2F2000%2Fsvg%22%20viewBox%3D%220%200%2040%2040%22%3E%3Ccircle%20cx%3D%2220%22%20cy%3D%2220%22%20r%3D%2220%22%20fill%3D%22%232CA5E0%22%2F%3E%3Cpath%20fill%3D%22%23fff%22%20d%3D%22M28.4%2013.5l-2.8%2013.3c-.2.9-.7%201.1-1.5.7l-4.3-3.2-2.1%202c-.2.2-.4.4-.9.4l.3-4.4%208-7.2c.3-.3-.1-.5-.5-.2L14.7%2021.1l-4.3-1.3c-.9-.3-.9-.9.2-1.3l16.8-6.5c.8-.5%201.5-.3%201%201.5z%22%2F%3E%3C%2Fsvg%3E',
                'COLOR' => '#2CA5E0',
                'SIZE' => '90%',
                'POSITION' => 'center',
            ],
            'ICON_DISABLED' => [
                'DATA_IMAGE' => 'data:image/svg+xml;charset=US-ASCII,%3Csvg%20xmlns%3D%22http%3A%2F%2Fwww.w3.org%2F2000%2Fsvg%22%20viewBox%3D%220%200%2040%2040%22%3E%3Ccircle%20cx%3D%2220%22%20cy%3D%2220%22%20r%3D%2220%22%20fill%3D%22%23cccccc%22%2F%3E%3Cpath%20fill%3D%22%23fff%22%20d%3D%22M28.4%2013.5l-2.8%2013.3c-.2.9-.7%201.1-1.5.7l-4.3-3.2-2.1%202c-.2.2-.4.4-.9.4l.3-4.4%208-7.2c.3-.3-.1-.5-.5-.2L14.7%2021.1l-4.3-1.3c-.9-.3-.9-.9.2-1.3l16.8-6.5c.8-.5%201.5-.3%201%201.5z%22%2F%3E%3C%2Fsvg%3E',
                'COLOR' => '#eeeeee',
                'SIZE' => '90%',
                'POSITION' => 'center',
            ],
            'PLACEMENT_HANDLER' => $connectorSetupUrl,
        ]
    );

    // 2. Bind the OnImConnectorMessageAdd event (Bitrix24 -> Telegram)
    // First, try to unbind any old handler to avoid double-firing or conflicting with old URLs
    CRest::call('event.unbind', [
        'event' => 'OnImConnectorMessageAdd',
        'handler' => $eventHandlerUrl
    ]);

    $eventRes = CRest::call(
        'event.bind',
        [
            'event' => 'OnImConnectorMessageAdd',
            'handler' => $eventHandlerUrl,
        ]
    );

    // 3. Find an Open Line and activate the connector on it
    $defaultLine = 1;
    $linesRes = CRest::call('imopenlines.config.list.get', []);
    if (!empty($linesRes['result'])) {
        foreach ($linesRes['result'] as $lineInfo) {
            $defaultLine = $lineInfo['ID'];
            break; // Use the first Open Line
        }
    }

    $activateRes = CRest::call(
        'imconnector.activate',
        [
            'CONNECTOR' => 'telegram_bridge',
            'LINE' => $defaultLine,
            'ACTIVE' => 1,
        ]
    );

    $connectorDataRes = CRest::call(
        'imconnector.connector.data.set',
        [
            'CONNECTOR' => 'telegram_bridge',
            'LINE' => $defaultLine,
            'DATA' => [
                'id' => 'telegram_bridge_line_' . $defaultLine,
                'url_im' => '',
                'name' => 'Keen Telegram',
            ],
        ]
    );

    // 4. Set Telegram Bot Webhook
    // IMPORTANT: Must delete the old webhook first to avoid 409 Conflict errors
    $botToken = TELEGRAM_BOT_TOKEN;
    @file_get_contents("https://api.telegram.org/bot{$botToken}/deleteWebhook");
    $setWebhookResult = file_get_contents(
        "https://api.telegram.org/bot{$botToken}/setWebhook?url=" . urlencode($telegramWebhookUrl)
    );

    // 5. Save settings (open line ID for use in webhooks)
    $settingsFile = __DIR__ . '/settings.json';
    if (file_exists($settingsFile)) {
        $settings = json_decode(file_get_contents($settingsFile), true) ?: [];
    } else {
        $settings = [];
    }
    $settings['open_line_id'] = $defaultLine;
    $settings['telegram_webhook_url'] = $telegramWebhookUrl;
    file_put_contents($settingsFile, json_encode($settings, JSON_PRETTY_PRINT));

    CRest::setLog([
        'connector' => $connectorRes,
        'event' => $eventRes,
        'activate' => $activateRes,
        'connector_data' => $connectorDataRes,
        'telegram_webhook' => $setWebhookResult,
    ], 'installation_bindings');

    $is_installed = true;
} else {
    if (isset($_REQUEST['PLACEMENT']) && $_REQUEST['PLACEMENT'] == 'DEFAULT') {
        // Just show the UI
    } else {
        $error_message = 'Installation failed or invalid request.';
    }
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Keen Telegram - Setup</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Outfit:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.3/css/all.min.css">
    <script src="//api.bitrix24.com/api/v1/"></script>
    <style>
        body {
            font-family: 'Inter', sans-serif;
            background: #f8f9fa;
            background-image:
                radial-gradient(at 0% 0%, rgba(44, 165, 224, 0.08) 0px, transparent 50%),
                radial-gradient(at 100% 100%, rgba(0, 136, 204, 0.08) 0px, transparent 50%);
            min-height: 100vh;
            display: flex;
            align-items: center;
        }
        .install-card {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(10px);
            border: 1px solid rgba(255, 255, 255, 0.8);
            border-radius: 24px;
            box-shadow: 0 25px 50px -12px rgba(44, 165, 224, 0.15);
            padding: 40px;
            max-width: 600px;
            margin: auto;
        }
        h1 { font-family: 'Outfit', sans-serif; font-weight: 700; color: #2CA5E0; }
        .btn-install {
            background: linear-gradient(135deg, #2CA5E0, #0088CC);
            border: none;
            border-radius: 12px;
            padding: 12px 40px;
            font-weight: 600;
            color: white;
            transition: all 0.3s ease;
        }
        .btn-install:hover { transform: translateY(-2px); box-shadow: 0 10px 20px rgba(44, 165, 224, 0.3); color: white; }
    </style>
</head>
<body>
    <div class="container">
        <div class="install-card text-center">
            <div class="mb-4">
                <i class="fab fa-telegram fa-4x" style="color: #2CA5E0;"></i>
            </div>
            <h1 class="mb-3">Keen Telegram</h1>

            <?php if ($error_message): ?>
                <div class="alert alert-danger"><?= $error_message ?></div>
            <?php else: ?>
                <p class="text-muted mb-4">Connecting your Telegram Bot to Bitrix24 Open Lines. Click below to complete the setup.</p>
                <button id="finishBtn" class="btn btn-lg btn-install px-5">Complete Setup</button>
            <?php endif; ?>

            <div id="status" class="mt-4" style="display:none;">
                <div class="spinner-border text-primary mr-2" role="status"></div>
                <span class="text-muted">Registering connector...</span>
            </div>

            <div id="nextSteps" class="mt-4" style="<?= $is_installed ? '' : 'display:none;' ?>">
                <div class="alert alert-success border-0 shadow-sm rounded-lg py-3">
                    <i class="fas fa-check-circle mr-2"></i> Setup finished successfully!
                </div>
                <div class="text-left mt-4 text-muted">
                    <p><span class="badge badge-primary mr-2">1</span> Close this window.</p>
                    <p><span class="badge badge-primary mr-2">2</span> <b>Refresh your Bitrix24 page.</b></p>
                    <p><span class="badge badge-primary mr-2">3</span> Go to <b>Contact Center → Keen Telegram</b> to verify the connection.</p>
                    <p><span class="badge badge-primary mr-2">4</span> Send a message to your Telegram Bot to test!</p>
                </div>
            </div>
        </div>
    </div>

    <script>
        BX24.init(function() {
            console.log("BX24 Initialized");
            <?php if ($is_installed): ?>
                document.getElementById('finishBtn').style.display = 'none';
                document.getElementById('nextSteps').style.display = 'block';
                BX24.installFinish();
            <?php endif; ?>
        });

        document.getElementById('finishBtn').addEventListener('click', function() {
            BX24.installFinish();
            document.getElementById('finishBtn').style.display = 'none';
            document.getElementById('nextSteps').style.display = 'block';
        });
    </script>
</body>
</html>
