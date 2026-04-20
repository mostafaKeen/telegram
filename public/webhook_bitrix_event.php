<?php
declare(strict_types=1);

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

    foreach ($messages as $msg) {
        $chatId = $msg['im']['chat_id'] ?? '';
        $messageText = $msg['im']['message']['text'] ?? '';

        // The chat_id in the connector context is the Telegram chat ID we passed earlier
        // We need to extract the original Telegram chat ID from the connector chat mapping
        // In our setup, we used the telegram_chat_id as the external chat.id
        $telegramChatId = $msg['chat']['id'] ?? '';

        if (empty($telegramChatId) || empty($messageText)) {
            CRest::setLog(['skip' => 'missing chat_id or text', 'msg' => $msg], 'b24_event_skip');
            continue;
        }

        // Strip HTML tags from Bitrix24 message
        $cleanText = strip_tags($messageText);

        // Send to Telegram
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

        CRest::setLog([
            'telegram_chat_id' => $telegramChatId,
            'text' => $cleanText,
            'response' => $response,
            'curl_error' => $error,
        ], 'b24_to_telegram');
    }

} catch (Throwable $e) {
    CRest::setLog(['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()], 'b24_event_error');
}
