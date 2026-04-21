<?php
declare(strict_types=1);

require_once(__DIR__ . '/../src/TelegramBridge/bootstrap.php');
require_once(__DIR__ . '/settings.php');
require_once(__DIR__ . '/crest.php');
require_once(__DIR__ . '/../src/TelegramBridge/MediaService.php');

use Bitrix24\TelegramBridge\MediaService;

$action = $_GET['action'] ?? '';
// Auto-detect base URL — never use stale ngrok URLs on production
$appBaseUrl = $_ENV['APP_BASE_URL'] ?? $_SERVER['APP_BASE_URL'] ?? '';
if (empty($appBaseUrl) || strpos($appBaseUrl, 'ngrok') !== false) {
    $proto = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host  = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $appBaseUrl = $proto . '://' . $host;
}
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
            $settingsArr = [];
            if (file_exists($settingsFile)) {
                $settingsArr = json_decode(file_get_contents($settingsFile), true) ?: [];
            }
            $lineId = $settingsArr['open_line_id'] ?? '1';

            // Get agent info from request
            $agentName = $_POST['agent_name'] ?? '';
            $agentId   = $_POST['agent_id'] ?? '';

            // Get chat info for proper user identification in B24
            $chatInfo = $storage->getChatInfo($chatId);
            $userName  = trim(($chatInfo['first_name'] ?? '') . ' ' . ($chatInfo['last_name'] ?? ''));

            // Build message payload — text must be non-empty for B24
            $b24Text = $text;
            if (empty($b24Text)) {
                if ($mediaType === 'voice')        { $b24Text = 'Voice message'; }
                elseif ($mediaType === 'document') { $b24Text = 'Document'; }
                else                               { $b24Text = 'Attachment'; }
            }

            // Prefix with agent name if available (Bitrix24 supports BBCode [b])
            if ($agentName) {
                $b24Text = "[b]{$agentName} replied via dashboard:[/b] " . $b24Text;
            }

            $b24Msg = [
                'text' => $b24Text,
            ];
            if ($mediaPath) {
                $b24Msg['files'] = [[
                    'url'  => $appBaseUrl . '/public/uploads/' . $mediaPath,
                    'name' => $mediaPath,
                ]];
            }

            $b24Result = CRest::call('imconnector.send.messages', [
                'CONNECTOR' => 'telegram_bridge',
                'LINE'      => $lineId,
                'MESSAGES'  => [[
                    'user' => [
                        'id'        => (string)$chatId,
                        'name'      => $chatInfo['first_name'] ?? ($userName ?: 'Telegram User'),
                        'last_name' => $chatInfo['last_name'] ?? '',
                    ],
                    'message' => $b24Msg,
                    'chat'    => [
                        'id'   => (string)$chatId,
                        'name' => 'Telegram: ' . ($userName ?: $chatId),
                    ],
                ]],
            ]);

            // Attempt to assign/answer the chat in Bitrix24 if agent_id is provided
            $assignmentResult = null;
            if ($agentId) {
                $mapping = $storage->getMappingByTelegramId($chatId);
                $b24ChatId = $mapping['b24_connector_chat_id'] ?? null;
                
                if ($b24ChatId) {
                    // Try to answer/take the session (requires agent token or specific permissions)
                    // We use imopenlines.operator.answer but it often acts on current auth user.
                    // For explicit assignment, imopenlines.operator.transfer is better.
                    $assignmentResult = CRest::call('imopenlines.operator.transfer', [
                        'CHAT_ID'     => $b24ChatId,
                        'TRANSFER_ID' => $agentId,
                    ]);
                }
            }

            CRest::setLog([
                'chat_id'           => $chatId,
                'line_id'           => $lineId,
                'agent_id'          => $agentId,
                'b24_response'      => $b24Result,
                'assignment_result' => $assignmentResult,
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
