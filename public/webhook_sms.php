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

    // 2. Find Telegram chat via B24 open line connector
    $telegramChatId = null;

    if ($entityId) {
        CRest::setLog(['step' => 'Calling imopenlines.crm.chat.get'], 'sms_webhook_flow');
        
        // Get chats for this entity - search by CONNECTOR_ID
        $chatRes = CRest::call('imopenlines.crm.chat.get', [
            'CRM_ENTITY_TYPE' => $entityType,
            'CRM_ENTITY'      => $entityId
        ]);

        CRest::setLog([
            'step' => 'imopenlines.crm.chat.get response',
            'response' => $chatRes
        ], 'sms_webhook_flow');

        if (!empty($chatRes['result'])) {
            // Log for debugging
            CRest::setLog(['lookup_chats_count' => count($chatRes['result'])], 'sms_debug');
            
            foreach ($chatRes['result'] as $index => $chatObj) {
                CRest::setLog([
                    'chat_object_index' => $index,
                    'chat_object' => $chatObj
                ], 'sms_webhook_flow');
                
                if (!isset($chatObj['CHAT_ID']) || !isset($chatObj['CONNECTOR_ID'])) {
                    CRest::setLog([
                        'skipped_chat' => $index,
                        'reason' => 'missing CHAT_ID or CONNECTOR_ID'
                    ], 'sms_webhook_flow');
                    continue;
                }
                
                $connectorId = (string)$chatObj['CONNECTOR_ID'];
                CRest::setLog([
                    'step' => 'Checking connector',
                    'connector_id' => $connectorId,
                    'is_telegram' => $connectorId === 'telegram_bridge'
                ], 'sms_webhook_flow');
                
                // If connector is telegram_bridge, get the B24 chat ID
                if ($connectorId === 'telegram_bridge') {
                    $b24ChatId = (string)$chatObj['CHAT_ID'];
                    CRest::setLog([
                        'step' => 'Found telegram_bridge chat',
                        'b24_chat_id' => $b24ChatId
                    ], 'sms_webhook_flow');
                    
                    // Convert B24 chat ID to actual Telegram chat ID via storage
                    CRest::setLog(['step' => 'Looking up Telegram ID in storage'], 'sms_webhook_flow');
                    $telegramChatId = $storage->getTelegramIdByB24ConnectorId($b24ChatId);
                    
                    CRest::setLog([
                        'step' => 'Storage lookup result',
                        'telegram_chat_id' => $telegramChatId,
                        'telegram_chat_id_type' => gettype($telegramChatId)
                    ], 'sms_webhook_flow');
                    
                    if ($telegramChatId) {
                        CRest::setLog(['step' => 'Telegram ID found, will send message'], 'sms_webhook_flow');
                        break;
                    } else {
                        CRest::setLog(['step' => 'Telegram ID not found in storage'], 'sms_webhook_flow');
                    }
                }
            }
        } else {
            CRest::setLog(['step' => 'No chats found for entity'], 'sms_webhook_flow');
        }
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
        
        // 6. NO CHAT FOUND: Notify responsible person
        $entityInfoRes = CRest::call('crm.' . strtolower($entityType) . '.get', ['id' => $entityId]);
        
        CRest::setLog([
            'step' => 'CRM entity info retrieved',
            'response' => $entityInfoRes
        ], 'sms_webhook_flow');
        
        $assignedById  = $entityInfoRes['result']['ASSIGNED_BY_ID'] ?? null;
        $entityName    = $entityInfoRes['result']['TITLE'] ?? $entityInfoRes['result']['NAME'] ?? "Entity #$entityId";

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
