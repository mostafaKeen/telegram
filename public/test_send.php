<?php
$ch = curl_init('http://localhost:8000/api.php?action=send_message');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, ['chat_id' => '8699919366', 'text' => 'Hello UI']);
$response = curl_exec($ch);
echo "Response: " . $response;
