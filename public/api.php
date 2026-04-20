<?php
declare(strict_types=1);

require_once(__DIR__ . '/../src/TelegramBridge/bootstrap.php');
require_once(__DIR__ . '/settings.php');
require_once(__DIR__ . '/crest.php');
require_once(__DIR__ . '/../src/TelegramBridge/MediaService.php');

use Bitrix24\TelegramBridge\MediaService;

$action = $_GET['action'] ?? '';
$appBaseUrl = $_ENV['APP_BASE_URL'] ?? $_SERVER['APP_BASE_URL'] ?? (($_SERVER['HTTPS'] ?? 'off') === 'on' ? 'https://' : 'http://') . ($_SERVER['HTTP_HOST'] ?? 'localhost');
header('Content-Type: application/json');

try {
    $mediaService = new MediaService(TELEGRAM_BOT_TOKEN, __DIR__ . '/uploads/', $appBaseUrl);

    switch ($action) {
        case 'list_chats':
            echo json_encode(['success' => true, 'chats' => $storage->getRecentChats()]);
            break;

        case 'get_messages':
            $chatId = $_GET['chat_id'] ?? '';
            if (!$chatId) throw new Exception("Missing chat_id");
            echo json_encode(['success' => true, 'messages' => $storage->getMessages($chatId)]);
            break;

        case 'send_message':
            $chatId = $_POST['chat_id'] ?? '';
            $text = $_POST['text'] ?? '';
            if (!$chatId) throw new Exception("Missing chat_id");

            // 1. Send to Telegram
            $botToken = TELEGRAM_BOT_TOKEN;
            $params = ['chat_id' => $chatId];
            $method = 'sendMessage';

            if (isset($_FILES['file']) && $_FILES['file']['error'] === UPLOAD_ERR_OK) {
                // Determine if it's a voice record or a general file
                $isVoice = ($_POST['is_voice'] ?? '0') === '1';
                $tmpPath = $_FILES['file']['tmp_name'];
                $ext = pathinfo($_FILES['file']['name'], PATHINFO_EXTENSION);
                $newName = ($isVoice ? 'voice_out_' : 'doc_out_') . bin2hex(random_bytes(8)) . '.' . $ext;
                $localPath = __DIR__ . '/uploads/' . $newName;
                move_uploaded_file($tmpPath, $localPath);
                
                $mediaType = $isVoice ? 'voice' : 'document';
                $mediaPath = $newName;
                
                // Prepare Telegram request
                $method = $isVoice ? 'sendVoice' : 'sendDocument';
                $params[$isVoice ? 'voice' : 'document'] = new CURLFile($localPath);
                if ($text) $params['caption'] = $text;
            } else {
                if (!$text) throw new Exception("Message cannot be empty");
                $params['text'] = $text;
                $mediaType = 'text';
                $mediaPath = null;
            }

            $telegramUrl = "https://api.telegram.org/bot{$botToken}/$method";
            $ch = curl_init($telegramUrl);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $params);
            $response = curl_exec($ch);
            $error = curl_error($ch);
            curl_close($ch);

            $tgResult = json_decode($response, true);
            if (!$tgResult || !$tgResult['ok']) {
                throw new Exception("Telegram API error: " . ($tgResult['description'] ?? $error ?: 'Unknown'));
            }

            // 2. Save locally
            $storage->saveMessage($chatId, 'OUT', $text, $mediaType, $mediaPath, (string)$tgResult['result']['message_id']);

            // 3. Sync to Bitrix24 (to keep Open Line updated)
            $settingsFile = __DIR__ . '/settings.json';
            $settings = [];
            if (file_exists($settingsFile)) {
                $settings = json_decode(file_get_contents($settingsFile), true) ?: [];
            }
            $lineId = $settings['open_line_id'] ?? '1'; // Default to '1' but try to detect

            $b24Message = ['text' => $text];
            if ($mediaPath) {
                $b24Message['files'] = [[
                    'url' => $appBaseUrl . '/public/uploads/' . $mediaPath,
                    'name' => $mediaPath
                ]];
            }

            $b24Result = CRest::call('imconnector.send.messages', [
                'CONNECTOR' => 'telegram_bridge',
                'LINE' => $lineId,
                'MESSAGES' => [[
                    // Using the Telegram User ID so B24 routes it to the correct chat session.
                    'user' => ['id' => (string)$chatId],
                    'message' => [
                        'text' => "[Agent reply via Dashboard]: \n" . $text,
                        'files' => $b24Message['files'] ?? []
                    ],
                    'chat' => ['id' => (string)$chatId]
                ]]
            ]);

            CRest::setLog([
                'chat_id' => $chatId,
                'line_id' => $lineId,
                'b24_response' => $b24Result
            ], 'api_to_b24_sync');

            echo json_encode(['success' => true]);
            break;

        default:
            throw new Exception("Invalid action");
    }
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
