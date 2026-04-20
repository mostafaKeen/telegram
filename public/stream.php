<?php
declare(strict_types=1);

require_once(__DIR__ . '/../src/TelegramBridge/bootstrap.php');
require_once(__DIR__ . '/settings.php');

header('Content-Type: application/json');

$lastId = (int)($_GET['last_id'] ?? 0);
$chatId = $_GET['chat_id'] ?? '';

try {
    if ($chatId) {
        $messages = $storage->getMessagesSince($chatId, $lastId);
    } else {
        $messages = $storage->getGlobalMessagesSince($lastId);
    }

    echo json_encode(['success' => true, 'messages' => $messages]);

} catch (Throwable $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
