<?php
declare(strict_types=1);

require_once(__DIR__ . '/../src/TelegramBridge/bootstrap.php');
require_once(__DIR__ . '/crest.php');

$input = $_POST;
CRest::setLog(['sms_webhook_input' => $input], 'sms_webhook_incoming');

$messageBody = $input['message_body'] ?? '';
$b24MessageId = $input['message_id'] ?? '';
$entities = $input['entities'] ?? [];
$phoneNumber = $input['phone_number'] ?? '';

// 1. Identify CRM Entity (Lead/Contact)
$entityId = null;
$entityType = null;

// Handle 'entities' format (documented in some B24 versions)
if (isset($input['entities']) && is_array($input['entities'])) {
    foreach ($input['entities'] as $entity) {
        if (isset($entity['entity_type']) && in_array($entity['entity_type'], ['LEAD', 'CONTACT'])) {
            $entityType = (string)$entity['entity_type'];
            $entityId = (string)$entity['entity_id'];
            break;
        }
    }
}

// Handle 'bindings' format (commonly sent by the CRM module)
if (!$entityId && isset($input['bindings']) && is_array($input['bindings'])) {
    foreach ($input['bindings'] as $binding) {
        $typeId = (int)($binding['OWNER_TYPE_ID'] ?? 0);
        if ($typeId === 1) { // LEAD
            $entityType = 'LEAD';
            $entityId = (string)($binding['OWNER_ID'] ?? '');
            break;
        } elseif ($typeId === 3) { // CONTACT
            $entityType = 'CONTACT';
            $entityId = (string)($binding['OWNER_ID'] ?? '');
            break;
        }
    }
}

try {
    if (!$entityId) {
        throw new Exception("No CRM entity (Lead/Contact) associated with this request.");
    }

    // 2. Get all mappings and find Telegram chat via CRM
    $telegramChatId = null;

    // First try: look up by phone number directly in storage
    if ($phoneNumber) {
        $telegramChatId = $storage->getTelegramIdByPhone($phoneNumber);
    }

    // Second try: find via B24 open line chat ID
    if (!$telegramChatId && $entityId) {
        // Get ALL chats for this entity, not just one
        $chatRes = CRest::call('imopenlines.crm.chat.get', [
            'CRM_ENTITY_TYPE' => $entityType,
            'CRM_ENTITY'      => $entityId
        ]);

        if (!empty($chatRes['result'])) {
            // Log to debug mismatch
            CRest::setLog(['lookup_chats' => $chatRes['result']], 'sms_debug');
            
            foreach ($chatRes['result'] as $b24ChatId) {
                // Try both the raw ID and prefixed variants
                $b24ChatId = (string)$b24ChatId;
                $telegramChatId = $storage->getTelegramIdByB24ConnectorId($b24ChatId);
                if ($telegramChatId) break;

                // Bitrix24 sometimes prefixes connector chat IDs
                $telegramChatId = $storage->getTelegramIdByB24ConnectorId('telegram_bridge|' . $b24ChatId);
                if ($telegramChatId) break;
            }
        }
    }

    if ($telegramChatId) {
        // 4. Send to Telegram
        $botToken = TELEGRAM_BOT_TOKEN;
        $url = "https://api.telegram.org/bot{$botToken}/sendMessage";
        
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, [
            'chat_id' => $telegramChatId,
            'text'    => $messageBody
        ]);
        $response = curl_exec($ch);
        curl_close($ch);
        
        $tgResult = json_decode((string)$response, true);
        
        if ($tgResult && $tgResult['ok']) {
            // Save locally
            $storage->saveMessage($telegramChatId, 'OUT', $messageBody, 'text');
            
            // 5. Update Status in Bitrix24
            CRest::call('messageservice.message.status.update', [
                'MESSAGE_ID' => $b24MessageId,
                'STATUS'     => 'delivered'
            ]);
        } else {
            throw new Exception("Telegram API error: " . ($tgResult['description'] ?? 'Unknown'));
        }
    } else {
        // 6. NO CHAT FOUND: Notify responsible person
        $entityInfoRes = CRest::call('crm.' . strtolower($entityType) . '.get', ['id' => $entityId]);
        $assignedById  = $entityInfoRes['result']['ASSIGNED_BY_ID'] ?? null;
        $entityName    = $entityInfoRes['result']['TITLE'] ?? $entityInfoRes['result']['NAME'] ?? "Entity #$entityId";

        if ($assignedById) {
            CRest::call('im.notify.system.add', [
                'USER_ID' => $assignedById,
                'MESSAGE' => "⚠️ [b]Telegram Bridge Error:[/b] Attempted to send an SMS/Message to [b]{$entityName}[/b], but no active Telegram session was found. The message was not delivered."
            ]);
        }
        
        // Update Status to failed in Bitrix24
        CRest::call('messageservice.message.status.update', [
            'MESSAGE_ID' => $b24MessageId,
            'STATUS'     => 'failed'
        ]);
        
        throw new Exception("No active Telegram session for this {$entityType}. Responsible person notified.");
    }

} catch (Throwable $e) {
    CRest::setLog(['error' => $e->getMessage()], 'sms_webhook_error');
    // Final fallback status update if not already done
    if ($b24MessageId) {
        CRest::call('messageservice.message.status.update', [
            'MESSAGE_ID' => $b24MessageId,
            'STATUS'     => 'failed'
        ]);
    }
}
