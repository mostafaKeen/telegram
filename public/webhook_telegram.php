<?php
declare(strict_types=1);

require_once(__DIR__ . '/crest.php');

try {
    $input = file_get_contents('php://input');
    $update = json_decode($input, true);

    CRest::setLog(['raw_input' => $update], 'telegram_incoming');

    if (!isset($update['message'])) {
        exit();
    }

    $message = $update['message'];
    $telegramChatId = (string)$message['chat']['id'];
    $text = $message['text'] ?? '';
    $user = $message['from'];
    $userName = trim(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? ''));

    // Get the open line ID from settings
    $settingsFile = __DIR__ . '/settings.json';
    $settings = json_decode(file_get_contents($settingsFile), true) ?: [];
    $lineId = $settings['open_line_id'] ?? 1;

    // Send message to Bitrix24 via imconnector
    $result = CRest::call(
        'imconnector.send.messages',
        [
            'CONNECTOR' => 'telegram_bridge',
            'LINE' => $lineId,
            'MESSAGES' => [
                [
                    'user' => [
                        'id' => (string)$user['id'],
                        'name' => $userName ?: 'Telegram User',
                        'picture' => ['url' => 'https://ui-avatars.com/api/?name=' . urlencode($userName ?: 'TG') . '&background=2CA5E0&color=fff'],
                    ],
                    'message' => [
                        'id' => (string)$message['message_id'],
                        'date' => $message['date'],
                        'text' => $text,
                    ],
                    'chat' => [
                        'id' => $telegramChatId,
                        'name' => 'Telegram: ' . ($userName ?: $telegramChatId),
                    ],
                ],
            ],
        ]
    );

    CRest::setLog(['send_result' => $result], 'telegram_to_b24');

} catch (Throwable $e) {
    CRest::setLog(['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()], 'telegram_error');
}
