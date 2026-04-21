<?php
declare(strict_types=1);

// Respond 200 immediately so Telegram never marks this webhook as failing
// and never retries, even if processing takes time.
http_response_code(200);

require_once(__DIR__ . '/../src/TelegramBridge/bootstrap.php');
require_once(__DIR__ . '/settings.php');
require_once(__DIR__ . '/crest.php');
require_once(__DIR__ . '/../src/TelegramBridge/MediaService.php');

use Bitrix24\TelegramBridge\MediaService;

try {
    // Auto-detect base URL for production (fallback if .env APP_BASE_URL is stale/ngrok)
    $appBaseUrl = $_ENV['APP_BASE_URL'] ?? $_SERVER['APP_BASE_URL'] ?? '';
    if (empty($appBaseUrl) || strpos($appBaseUrl, 'ngrok') !== false) {
        $proto      = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host       = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $scriptDir  = dirname(dirname($_SERVER['SCRIPT_NAME'] ?? '/public/webhook_telegram.php'));
        $appBaseUrl = $proto . '://' . $host . $scriptDir;
    }
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
    $firstName = $user['first_name'] ?? '';
    $lastName = $user['last_name'] ?? '';
    $username = $user['username'] ?? '';

    // Initialize Services (use the auto-detected $appBaseUrl from above — do NOT re-read from .env here)
    $mediaService = new MediaService(TELEGRAM_BOT_TOKEN, __DIR__ . '/uploads/', $appBaseUrl);
    
    // Save/Update Chat Info
    $storage->updateChatInfo($telegramChatId, $firstName, $lastName, $username);

    $mediaType = 'text';
    $mediaPath = null;
    $b24Files = [];

    // Handle Media
    if (isset($message['photo'])) {
        $mediaType = 'photo';
        $bestPhoto = end($message['photo']); // Largest size
        $download = $mediaService->downloadTelegramFile($bestPhoto['file_id'], 'img_');
        if ($download) {
            $mediaPath = $download['name'];
            $b24Files[] = $mediaService->prepareB24File($download);
        }
    } elseif (isset($message['voice'])) {
        $mediaType = 'voice';
        $download = $mediaService->downloadTelegramFile($message['voice']['file_id'], 'voice_');
        if ($download) {
            $mediaPath = $download['name'];
            $b24Files[] = $mediaService->prepareB24File($download);
        }
    } elseif (isset($message['document'])) {
        $mediaType = 'document';
        $download = $mediaService->downloadTelegramFile($message['document']['file_id'], 'doc_');
        if ($download) {
            $mediaPath = $download['name'];
            $b24Files[] = $mediaService->prepareB24File($download);
        }
    } elseif (isset($message['video'])) {
        $mediaType = 'video';
        $download = $mediaService->downloadTelegramFile($message['video']['file_id'], 'vid_');
        if ($download) {
            $mediaPath = $download['name'];
            $b24Files[] = $mediaService->prepareB24File($download);
        }
    }

    // Save Message to local DB
    $storage->saveMessage($telegramChatId, 'IN', $text, $mediaType, $mediaPath, (string)$message['message_id']);

    // Forward to Bitrix24
    $settingsFile = __DIR__ . '/settings.json';
    $settings = json_decode(file_get_contents($settingsFile), true) ?: [];
    $lineId = $settings['open_line_id'] ?? 1;

    // Bitrix24 requires message.text to be non-empty even when files are present.
    // Use a descriptive placeholder so imconnector.send.messages doesn't reject with "Incomplete data".
    // NOTE: if/elseif used instead of match() for PHP 7.x compatibility.
    $b24Text = $text;
    if (empty($b24Text)) {
        if ($mediaType === 'photo')         { $b24Text = 'Photo'; }
        elseif ($mediaType === 'voice')     { $b24Text = 'Voice message'; }
        elseif ($mediaType === 'document')  { $b24Text = 'Document'; }
        elseif ($mediaType === 'video')     { $b24Text = 'Video'; }
        else                               { $b24Text = 'Attachment'; }
    }

    $b24Message = [
        'id'   => (string)$message['message_id'],
        'date' => $message['date'],
        'text' => $b24Text,
    ];
    if (!empty($b24Files)) {
        $b24Message['files'] = $b24Files;
    }

    $result = CRest::call(
        'imconnector.send.messages',
        [
            'CONNECTOR' => 'telegram_bridge',
            'LINE'      => $lineId,
            'MESSAGES'  => [
                [
                    'user' => [
                        'id'        => (string)$user['id'],
                        'name'      => $firstName ?: ($userName ?: 'Telegram User'),
                        'last_name' => $lastName,
                        'picture'   => ['url' => 'https://ui-avatars.com/api/?name=' . urlencode($userName ?: 'TG') . '&background=2CA5E0&color=fff'],
                    ],
                    'message' => $b24Message,
                    'chat' => [
                        'id'   => $telegramChatId,
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
