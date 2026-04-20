<?php
declare(strict_types=1);

require_once(__DIR__ . '/../src/TelegramBridge/bootstrap.php');
require_once(__DIR__ . '/settings.php');

header('Content-Type: application/json');

$lastId = (int)($_GET['last_id'] ?? 0);
$chatId = $_GET['chat_id'] ?? '';

try {
    $db = new PDO('sqlite:' . __DIR__ . '/../var/database.sqlite');
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

    if ($chatId) {
        $stmt = $db->prepare("SELECT * FROM messages WHERE id > ? AND telegram_chat_id = ? ORDER BY id ASC LIMIT 50");
        $stmt->execute([$lastId, $chatId]);
    } else {
        $stmt = $db->prepare("SELECT * FROM messages WHERE id > ? ORDER BY id ASC LIMIT 50");
        $stmt->execute([$lastId]);
    }

    $messages = $stmt->fetchAll();
    echo json_encode(['success' => true, 'messages' => $messages]);

} catch (Throwable $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
