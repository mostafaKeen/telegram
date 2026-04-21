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

    CRest::setLog([
        'step' => 'Starting SMS webhook processing',
        'entity_type' => $entityType,
        'entity_id' => $entityId,
        'message_id' => $b24MessageId,
        'phone_number' => $phoneNumber
    ], 'sms_webhook_flow');

    // 2. Get CRM entity to check IM field for telegram_bridge connection
    $telegramChatId = null;
    
    CRest::setLog(['step' => 'Fetching CRM entity data'], 'sms_webhook_flow');
    $entityInfoRes = CRest::call('crm.' . strtolower($entityType) . '.get', ['id' => $entityId]);
    
    CRest::setLog([
        'step' => 'CRM entity retrieved',
        'entity_response' => $entityInfoRes
    ], 'sms_webhook_flow');
    
    if (empty($entityInfoRes['result'])) {
        throw new Exception("Failed to retrieve CRM entity #{$entityId}");
    }
    
    $entityData = $entityInfoRes['result'];
    $assignedById = $entityData['ASSIGNED_BY_ID'] ?? null;
    $entityName = $entityData['TITLE'] ?? $entityData['NAME'] ?? "Entity #$entityId";
    
    // Check IM field for telegram_bridge connection
    if (!empty($entityData['IM']) && is_array($entityData['IM'])) {
        CRest::setLog(['im_field' => $entityData['IM']], 'sms_webhook_flow');
        
        foreach ($entityData['IM'] as $imEntry) {
            if ($imEntry['VALUE_TYPE'] === 'IMOL' && strpos($imEntry['VALUE'], 'telegram_bridge') !== false) {
                // Parse imol format: imol|telegram_bridge|1|telegram_user_id|user_id
                $parts = explode('|', $imEntry['VALUE']);
                if (count($parts) >= 4) {
                    $telegramChatId = $parts[3]; // Extract telegram_user_id
                    CRest::setLog([
                        'step' => 'Found telegram_bridge in IM field',
                        'im_value' => $imEntry['VALUE'],
                        'extracted_telegram_id' => $telegramChatId
                    ], 'sms_webhook_flow');
                    break;
                }
            }
        }
    }
    
    if (empty($telegramChatId)) {
        CRest::setLog(['step' => 'No telegram_bridge found in IM field'], 'sms_webhook_flow');
    }

    if ($telegramChatId) {
        CRest::setLog(['step' => 'Telegram chat ID found, sending message'], 'sms_webhook_flow');
        
        // 4. Send to Telegram
        $botToken = TELEGRAM_BOT_TOKEN;
        $url = "https://api.telegram.org/bot{$botToken}/sendMessage";
        
        CRest::setLog([
            'step' => 'Calling Telegram API',
            'telegram_chat_id' => $telegramChatId
        ], 'sms_webhook_flow');
        
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
        
        CRest::setLog([
            'step' => 'Telegram API response',
            'response' => $tgResult
        ], 'sms_webhook_flow');
        
        if ($tgResult && $tgResult['ok']) {
            CRest::setLog(['step' => 'Message sent successfully to Telegram'], 'sms_webhook_flow');
            
            // Save locally
            $storage->saveMessage($telegramChatId, 'OUT', $messageBody, 'text');
            
            // 5. Update Status in Bitrix24
            CRest::call('messageservice.message.status.update', [
                'MESSAGE_ID' => $b24MessageId,
                'STATUS'     => 'delivered'
            ]);
            
            CRest::setLog(['step' => 'Message status updated to delivered in B24'], 'sms_webhook_flow');
        } else {
            throw new Exception("Telegram API error: " . ($tgResult['description'] ?? 'Unknown'));
        }
    } else {
        CRest::setLog(['step' => 'No Telegram chat ID found, will notify responsible person'], 'sms_webhook_flow');
        
        // NO CHAT FOUND: Notify responsible person
        CRest::setLog([
            'step' => 'About to send notification',
            'assigned_by_id' => $assignedById,
            'entity_name' => $entityName
        ], 'sms_webhook_flow');

        if ($assignedById) {
            $notificationMsg = "⚠️ [b]Telegram Bridge Error:[/b] Attempted to send an SMS/Message to [b]{$entityName}[/b], but no active Telegram session was found. The message was not delivered.";
            
            CRest::setLog([
                'step' => 'Sending IM notification',
                'user_id' => $assignedById,
                'message' => $notificationMsg
            ], 'sms_webhook_flow');
            
            $notifyRes = CRest::call('im.notify.system.add', [
                'USER_ID' => $assignedById,
                'MESSAGE' => $notificationMsg
            ]);
            
            CRest::setLog([
                'step' => 'IM notification sent',
                'response' => $notifyRes
            ], 'sms_webhook_flow');
        }
        
        // Update Status to failed in Bitrix24
        CRest::call('messageservice.message.status.update', [
            'MESSAGE_ID' => $b24MessageId,
            'STATUS'     => 'failed'
        ]);
        
        CRest::setLog(['step' => 'Message status updated to failed in B24'], 'sms_webhook_flow');
        
        throw new Exception("No active Telegram session for this {$entityType}. Responsible person notified.");
    }

} catch (Throwable $e) {
    CRest::setLog([
        'step' => 'Exception caught',
        'error' => $e->getMessage(),
        'file' => $e->getFile(),
        'line' => $e->getLine(),
        'trace' => $e->getTraceAsString()
    ], 'sms_webhook_error');
    
    // Final fallback status update if not already done
    if ($b24MessageId) {
        CRest::call('messageservice.message.status.update', [
            'MESSAGE_ID' => $b24MessageId,
            'STATUS'     => 'failed'
        ]);
    }
}
