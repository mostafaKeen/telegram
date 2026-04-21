<?php
$bot = '8796999696:AAHEzIkEKnhhzEDwvaAvuBAG7_FBJkBYUxQ';
echo file_get_contents('https://api.telegram.org/bot' . $bot . '/getWebhookInfo');
