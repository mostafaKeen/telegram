<?php
$db = new PDO('sqlite:' . __DIR__ . '/var/database.sqlite');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

$stmt = $db->query("SELECT * FROM messages ORDER BY id DESC LIMIT 5");
print_r($stmt->fetchAll());
