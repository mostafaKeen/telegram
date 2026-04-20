<?php

declare(strict_types=1);

namespace Bitrix24\TelegramBridge\Storage;

use PDO;

class SqliteStorage
{
    private PDO $db;

    public function __construct(string $dbPath)
    {
        $this->db = new PDO('sqlite:' . $dbPath);
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $this->initSchema();
    }

    private function initSchema(): void
    {
        // Table for Bitrix24 Auth Tokens
        $this->db->exec("CREATE TABLE IF NOT EXISTS b24_auth (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            portal_url TEXT UNIQUE,
            access_token TEXT,
            refresh_token TEXT,
            expires_at INTEGER
        )");

        // Table for Telegram Chat to Bitrix24 Session mapping
        $this->db->exec("CREATE TABLE IF NOT EXISTS chat_mappings (
            telegram_chat_id TEXT PRIMARY KEY,
            b24_connector_chat_id TEXT,
            b24_session_id TEXT,
            last_message_date INTEGER
        )");
    }

    public function saveTokens(string $portalUrl, string $accessToken, string $refreshToken, int $expiresAt): void
    {
        $stmt = $this->db->prepare("INSERT OR REPLACE INTO b24_auth (portal_url, access_token, refresh_token, expires_at) VALUES (?, ?, ?, ?)");
        $stmt->execute([$portalUrl, $accessToken, $refreshToken, $expiresAt]);
    }

    public function getTokens(string $portalUrl): ?array
    {
        $stmt = $this->db->prepare("SELECT * FROM b24_auth WHERE portal_url = ?");
        $stmt->execute([$portalUrl]);
        return $stmt->fetch() ?: null;
    }

    public function saveMapping(string $telegramChatId, string $b24ConnectorChatId, string $b24SessionId): void
    {
        $stmt = $this->db->prepare("INSERT OR REPLACE INTO chat_mappings (telegram_chat_id, b24_connector_chat_id, b24_session_id, last_message_date) VALUES (?, ?, ?, ?)");
        $stmt->execute([$telegramChatId, $b24ConnectorChatId, $b24SessionId, time()]);
    }

    public function getMappingByTelegramId(string $telegramChatId): ?array
    {
        $stmt = $this->db->prepare("SELECT * FROM chat_mappings WHERE telegram_chat_id = ?");
        $stmt->execute([$telegramChatId]);
        return $stmt->fetch() ?: null;
    }

    public function getTelegramIdByB24ConnectorId(string $b24ConnectorChatId): ?string
    {
        $stmt = $this->db->prepare("SELECT telegram_chat_id FROM chat_mappings WHERE b24_connector_chat_id = ?");
        $stmt->execute([$b24ConnectorChatId]);
        $res = $stmt->fetch();
        return $res ? $res['telegram_chat_id'] : null;
    }
}
