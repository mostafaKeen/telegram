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

    foreach ($messages as $msg) {
        $chatId = $msg['im']['chat_id'] ?? '';
        $messageText = $msg['message']['text'] ?? ''; // Corrected path
        $telegramChatId = ($msg['chat']['id'] ?? '');

        if (empty($telegramChatId) || empty($messageText)) {
            CRest::setLog(['skip' => 'missing chat_id or text', 'msg' => $msg], 'b24_event_skip');
            continue;
        }

        // Clean Bitrix24 formatting like [b]User:[/b] and [br]
        $cleanText = preg_replace('/\[b\].*?\[\/b\]\s*\[br\]\s*/i', '', $messageText);
        $cleanText = strip_tags($cleanText);
        $cleanText = trim($cleanText);

        // Deduplication: Don't save if we already have this exact message as OUT recently
        $stmt = $db->prepare("SELECT id FROM messages WHERE telegram_chat_id = ? AND text = ? AND direction = 'OUT' AND timestamp > ? LIMIT 1");
        $stmt->execute([$telegramChatId, $cleanText, time() - 10]);
        if ($stmt->fetch()) {
            CRest::setLog(['skip' => 'duplicate message', 'text' => $cleanText], 'b24_event_skip');
            continue;
        }

        // 1. Save to local DB (Direction: OUT)
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
