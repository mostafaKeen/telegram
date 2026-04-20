<?php

declare(strict_types=1);

namespace Bitrix24\TelegramBridge\Storage;

use PDO;

class SqliteStorage
{
    private PDO $db;

    public function __construct(string $dbPath)
    {
        try {
            $this->db = new PDO('sqlite:' . $dbPath);
            $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $this->db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
            // Add busy timeout (5 seconds) to prevent locking hangups
            $this->db->exec("PRAGMA busy_timeout = 5000");
            $this->initSchema();
        } catch (\PDOException $e) {
            die("FATAL ERROR: Unable to access the database file. 
                 Please ensure the '/var' folder exists in your project root and has write permissions (chmod 775). 
                 Folder checked: " . dirname($dbPath));
        }
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

        // Table for Chats (Users)
        $this->db->exec("CREATE TABLE IF NOT EXISTS chats (
            telegram_chat_id TEXT PRIMARY KEY,
            first_name TEXT,
            last_name TEXT,
            username TEXT,
            photo_url TEXT,
            updated_at INTEGER
        )");

        // Table for Messages
        $this->db->exec("CREATE TABLE IF NOT EXISTS messages (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            telegram_chat_id TEXT,
            direction TEXT, -- IN (from TG), OUT (from B24/Dashboard)
            message_id TEXT, -- Telegram message ID
            text TEXT,
            media_type TEXT, -- text, photo, voice, document, etc.
            media_path TEXT, -- Local path in uploads/
            timestamp INTEGER,
            FOREIGN KEY(telegram_chat_id) REFERENCES chats(telegram_chat_id)
        )");
        
        $this->db->exec("CREATE INDEX IF NOT EXISTS idx_messages_chat ON messages(telegram_chat_id)");
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

    public function updateChatInfo(string $telegramChatId, ?string $firstName, ?string $lastName, ?string $username, ?string $photoUrl = null): void
    {
        $stmt = $this->db->prepare("INSERT INTO chats (telegram_chat_id, first_name, last_name, username, photo_url, updated_at) 
            VALUES (?, ?, ?, ?, ?, ?) 
            ON CONFLICT(telegram_chat_id) DO UPDATE SET 
                first_name = excluded.first_name, 
                last_name = excluded.last_name, 
                username = excluded.username,
                photo_url = COALESCE(excluded.photo_url, photo_url),
                updated_at = excluded.updated_at");
        $stmt->execute([$telegramChatId, $firstName, $lastName, $username, $photoUrl, time()]);
    }

    public function saveMessage(string $telegramChatId, string $direction, ?string $text, ?string $mediaType = 'text', ?string $mediaPath = null, ?string $messageId = null): void
    {
        // Internal Deduplication: Don't save if we already have this exact message in the last 5 seconds
        if ($text) {
            $stmt = $this->db->prepare("SELECT id FROM messages WHERE telegram_chat_id = ? AND text = ? AND direction = ? AND timestamp > ? LIMIT 1");
            $stmt->execute([$telegramChatId, $text, $direction, time() - 5]);
            if ($stmt->fetch()) {
                return; 
            }
        }

        $stmt = $this->db->prepare("INSERT INTO messages (telegram_chat_id, direction, text, media_type, media_path, message_id, timestamp) 
            VALUES (?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([$telegramChatId, $direction, $text, $mediaType, $mediaPath, $messageId, time()]);
        
        // Update last message date in mappings
        $stmt = $this->db->prepare("UPDATE chat_mappings SET last_message_date = ? WHERE telegram_chat_id = ?");
        $stmt->execute([time(), $telegramChatId]);
    }

    public function getRecentChats(): array
    {
        $stmt = $this->db->query("SELECT c.*, m.text as last_message, m.timestamp as last_message_time 
            FROM chats c 
            LEFT JOIN (
                SELECT telegram_chat_id, text, timestamp 
                FROM messages 
                GROUP BY telegram_chat_id 
                HAVING timestamp = MAX(timestamp)
            ) m ON c.telegram_chat_id = m.telegram_chat_id 
            ORDER BY m.timestamp DESC");
        return $stmt->fetchAll();
    }

    public function getMessages(string $telegramChatId, int $limit = 50): array
    {
        $stmt = $this->db->prepare("SELECT * FROM messages WHERE telegram_chat_id = ? ORDER BY id DESC LIMIT ?");
        $stmt->execute([$telegramChatId, $limit]);
        $rows = $stmt->fetchAll();
        return array_reverse($rows);
    }

    public function getMessagesSince(string $telegramChatId, int $lastId, int $limit = 50): array
    {
        $stmt = $this->db->prepare("SELECT * FROM messages WHERE telegram_chat_id = ? AND id > ? ORDER BY id ASC LIMIT ?");
        $stmt->execute([$telegramChatId, $lastId, $limit]);
        return $stmt->fetchAll();
    }

    public function getGlobalMessagesSince(int $lastId, int $limit = 50): array
    {
        $stmt = $this->db->prepare("SELECT * FROM messages WHERE id > ? ORDER BY id ASC LIMIT ?");
        $stmt->execute([$lastId, $limit]);
        return $stmt->fetchAll();
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
