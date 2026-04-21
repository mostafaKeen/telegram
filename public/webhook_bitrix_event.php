<?php
declare(strict_types=1);

require_once(__DIR__ . '/../src/TelegramBridge/bootstrap.php');
require_once(__DIR__ . '/settings.php');
require_once(__DIR__ . '/crest.php');

try {
    $input = file_get_contents('php://input');
    // Bitrix24 sends event data as POST form data
    $eventData = $_POST;

    CRest::setLog(['raw_event' => $eventData], 'b24_event_incoming');

    // Validate this is the right event
    if (empty($eventData['event']) || $eventData['event'] !== 'ONIMCONNECTORMESSAGEADD') {
        exit();
    }

    $data = $eventData['data'] ?? [];
    $connector = $data['CONNECTOR'] ?? '';
    $messages = $data['MESSAGES'] ?? [];

    // Only process messages from our connector
    if ($connector !== 'telegram_bridge' || empty($messages)) {
        exit();
    }

    // Load settings for Line ID
    $settingsFile = __DIR__ . '/settings.json';
    $settings = [];
    if (file_exists($settingsFile)) {
        $settings = json_decode(file_get_contents($settingsFile), true) ?: [];
    }
    $lineId = $settings['open_line_id'] ?? '1';

    foreach ($messages as $msg) {
        $chatId = $msg['im']['chat_id'] ?? '';
        $messageText = $msg['message']['text'] ?? '';
        $telegramChatId = (string)($msg['chat']['id'] ?? '');
        $imMsgId = $msg['im'] ?? null;

        if (empty($telegramChatId) || empty($messageText)) {
            CRest::setLog(['skip' => 'missing chat_id or text', 'msg' => $msg], 'b24_event_skip');
            continue;
        }

        // Clean Bitrix24 formatting like [b]User:[/b] and [br]
        $cleanText = preg_replace('/\[b\].*?\[\/b\]\s*\[br\]\s*/i', '', $messageText);
        $cleanText = str_ireplace(['[br]', '[BR]'], "\n", $cleanText);
        $cleanText = strip_tags($cleanText);
        $cleanText = trim($cleanText);

        // 1. Save to local JSON DB (Direction: OUT)
        // JsonStorage handles its own deduplication internally
        $storage->saveMessage($telegramChatId, 'OUT', $cleanText, 'text');

        // 2. Send to Telegram
        $botToken = TELEGRAM_BOT_TOKEN;
        $telegramUrl = "https://api.telegram.org/bot{$botToken}/sendMessage";

        $ch = curl_init($telegramUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
            'chat_id' => $telegramChatId,
            'text' => $cleanText,
        ]));
        $response = curl_exec($ch);
        $error = curl_error($ch);
        curl_close($ch);

        $tgResult = json_decode((string)$response, true);
        $msgId = $tgResult['result']['message_id'] ?? uniqid('b24_');

        CRest::setLog([
            'telegram_chat_id' => $telegramChatId,
            'text' => $cleanText,
            'response' => $response,
            'curl_error' => $error,
        ], 'b24_to_telegram');

        // 3. Push delivery status to Bitrix24 (required to stop "loading" spinner)
        if ($lineId && $imMsgId) {
            $deliveryStatus = CRest::call('imconnector.send.status.delivery', [
                'CONNECTOR' => 'telegram_bridge',
                'LINE' => $lineId,
                'MESSAGES' => [
                    [
                        'im' => $imMsgId,
                        'message' => ['id' => [$msgId]],
                        'chat' => ['id' => $telegramChatId]
                    ]
                ]
            ]);
            CRest::setLog(['delivery_status' => $deliveryStatus, 'msg_id' => $msgId], 'b24_delivery_report');
        }
    }

} catch (Throwable $e) {
    CRest::setLog(['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()], 'b24_event_error');
}
