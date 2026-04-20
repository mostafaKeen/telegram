<?php
require_once(__DIR__ . '/../src/TelegramBridge/bootstrap.php');

echo "<h2>Diagnostic - All Leads (JSON Store)</h2>";
$chats = $storage->getRecentChats();

if (empty($chats)) {
    echo "<p>No leads found in storage.</p>";
} else {
    foreach ($chats as $chat) {
        echo "<h3>Lead: " . htmlspecialchars($chat['first_name'] . ' ' . $chat['last_name']) . " (" . $chat['telegram_chat_id'] . ")</h3>";
        echo "<strong>Last Message:</strong> " . htmlspecialchars($chat['last_message']) . "<br>";
        echo "<strong>Date:</strong> " . date('Y-m-d H:i:s', $chat['last_message_time']) . "<br>";
        
        echo "<h4>Recent History:</h4><pre>";
        $messages = $storage->getMessages((string)$chat['telegram_chat_id'], 5);
        print_r($messages);
        echo "</pre><hr>";
    }
}
