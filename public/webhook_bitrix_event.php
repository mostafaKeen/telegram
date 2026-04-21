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
        $telegramChatId = (string)($msg['chat']['id'] ?? '');
        $messageText    = $msg['message']['text'] ?? '';
        $imMsgId        = $msg['im'] ?? null;
        $files          = $msg['message']['files'] ?? [];
        $b24ChatId      = (string)($msg['im']['chat_id'] ?? ''); // Extract B24 Chat ID

        if (empty($telegramChatId)) {
            CRest::setLog(['skip' => 'missing chat_id', 'msg' => $msg], 'b24_event_skip');
            continue;
        }

        // Save mapping between Telegram chat and Bitrix24 chat
        if ($b24ChatId) {
            $storage->saveMapping($telegramChatId, $b24ChatId, '');
        }

        // Clean Bitrix24 formatting
        $cleanText = preg_replace('/\[b\].*?\[\/b\]\s*\[br\]\s*/i', '', $messageText);
        $cleanText = str_ireplace(['[br]', '[BR]'], "\n", $cleanText);
        $cleanText = strip_tags($cleanText);
        $cleanText = trim($cleanText);

        if (empty($cleanText) && empty($files)) {
            CRest::setLog(['skip' => 'empty message', 'msg' => $msg], 'b24_event_skip');
            continue;
        }

        $botToken    = TELEGRAM_BOT_TOKEN;
        $allSentMsgIds = [];

        // 1. Handle Files
        if (!empty($files)) {
            foreach ($files as $index => $file) {
                // Use downloadLink if available (common in IM events), fallback to url or link
                $fileUrl  = $file['downloadLink'] ?? $file['url'] ?? $file['link'] ?? '';
                $fileName = $file['name'] ?? 'file';
                
                if (!$fileUrl) {
                    CRest::setLog(['skip_file' => 'missing url/link', 'file' => $file], 'b24_event_skip');
                    continue;
                }

                // Download file
                $tempPath = __DIR__ . '/uploads/tmp_' . bin2hex(random_bytes(8)) . '_' . $fileName;
                $fileContent = file_get_contents($fileUrl);
                if ($fileContent === false) {
                    CRest::setLog(['error' => 'Failed to download file', 'url' => $fileUrl], 'b24_event_error');
                    continue;
                }
                file_put_contents($tempPath, $fileContent);

                // Determine Telegram method
                $ext = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
                $isPhoto = in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp']);
                $tgMethod = $isPhoto ? 'sendPhoto' : 'sendDocument';
                $tgField  = $isPhoto ? 'photo' : 'document';
                
                $postFields = [
                    'chat_id' => $telegramChatId,
                    $tgField  => new CURLFile($tempPath)
                ];

                // Add caption to the first file if text exists
                if ($index === 0 && !empty($cleanText)) {
                    $postFields['caption'] = $cleanText;
                }

                $ch = curl_init("https://api.telegram.org/bot{$botToken}/{$tgMethod}");
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_POST, true);
                curl_setopt($ch, CURLOPT_POSTFIELDS, $postFields);
                $response = curl_exec($ch);
                curl_close($ch);

                $tgResult = json_decode((string)$response, true);
                if ($tgResult && $tgResult['ok']) {
                    $sentMsgId = (string)$tgResult['result']['message_id'];
                    $allSentMsgIds[] = $sentMsgId;
                    
                    // Save to local storage
                    $localName = bin2hex(random_bytes(8)) . '_' . $fileName;
                    $localPath = __DIR__ . '/uploads/' . $localName;
                    rename($tempPath, $localPath);
                    $storage->saveMessage($telegramChatId, 'OUT', ($index === 0 ? $cleanText : ''), ($isPhoto ? 'photo' : 'document'), $localName, $sentMsgId);
                } else {
                    @unlink($tempPath);
                    CRest::setLog(['error' => 'Telegram file upload failed', 'response' => $tgResult], 'b24_event_error');
                }
            }
        } 
        // 2. Handle Text Only
        elseif (!empty($cleanText)) {
            $ch = curl_init("https://api.telegram.org/bot{$botToken}/sendMessage");
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
                'chat_id' => $telegramChatId,
                'text'    => $cleanText,
            ]));
            $response = curl_exec($ch);
            curl_close($ch);

            $tgResult = json_decode((string)$response, true);
            if ($tgResult && $tgResult['ok']) {
                $sentMsgId = (string)$tgResult['result']['message_id'];
                $allSentMsgIds[] = $sentMsgId;
                $storage->saveMessage($telegramChatId, 'OUT', $cleanText, 'text', null, $sentMsgId);
            }
        }

        // 3. Push delivery status to Bitrix24 (stops the "loading" spinner)
        if ($lineId && $imMsgId && !empty($allSentMsgIds)) {
            $deliveryStatus = CRest::call('imconnector.send.status.delivery', [
                'CONNECTOR' => 'telegram_bridge',
                'LINE'      => $lineId,
                'MESSAGES'  => [
                    [
                        'im'      => $imMsgId,
                        'message' => ['id' => $allSentMsgIds],
                        'chat'    => ['id' => $telegramChatId]
                    ]
                ]
            ]);
            CRest::setLog([
                'delivery_status' => $deliveryStatus,
                'msg_ids'         => $allSentMsgIds,
            ], 'b24_delivery_report');
        }
    }

} catch (Throwable $e) {
    CRest::setLog(['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()], 'b24_event_error');
}
