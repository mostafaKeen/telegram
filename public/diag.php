<?php
require_once(__DIR__ . '/../src/TelegramBridge/bootstrap.php');

$dbPath = __DIR__ . '/../var/database.sqlite';
$db = new PDO('sqlite:' . $dbPath);
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

echo "<h2>Diagnostic - Last 5 Messages (DB: $dbPath)</h2><pre>";
$stmt = $db->query("SELECT * FROM messages ORDER BY id DESC LIMIT 5");
print_r($stmt->fetchAll());
echo "</pre>";
