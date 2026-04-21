<?php

declare(strict_types=1);

namespace Bitrix24\TelegramBridge\Storage;

class JsonStorage
{
    private string $basePath;
    private string $leadsPath;
    private string $authFile;
    private string $streamFile;
    private string $seqFile;

    public function __construct(string $workDir)
    {
        $this->basePath = rtrim($workDir, '/') . '/storage';
        $this->leadsPath = $this->basePath . '/leads';
        $this->authFile = $this->basePath . '/auth.json';
        $this->streamFile = $this->basePath . '/global_stream.json';
        $this->seqFile = $this->basePath . '/sequence.json';

        $this->ensureDirectories();
    }

    private function ensureDirectories(): void
    {
        foreach ([$this->basePath, $this->leadsPath] as $dir) {
            if (!is_dir($dir)) {
                mkdir($dir, 0775, true);
            }
        }
    }

    private function readJson(string $file, $default = []): array
    {
        if (!file_exists($file)) {
            return $default;
        }
        $fp = fopen($file, 'r');
        if (!$fp) return $default;
        flock($fp, LOCK_SH);
        $content = file_get_contents($file);
        flock($fp, LOCK_UN);
        fclose($fp);
        return json_decode($content, true) ?: $default;
    }

    private function writeJson(string $file, array $data): void
    {
        $fp = fopen($file, 'c+');
        if (!$fp) return;
        flock($fp, LOCK_EX);
        ftruncate($fp, 0);
        rewind($fp);
        fwrite($fp, json_encode($data, JSON_PRETTY_PRINT));
        fflush($fp);
        flock($fp, LOCK_UN);
        fclose($fp);
    }

    // --- Bitrix24 Auth ---

    public function saveTokens(string $portalUrl, string $accessToken, string $refreshToken, int $expiresAt): void
    {
        $auth = $this->readJson($this->authFile);
        $auth[$portalUrl] = [
            'portal_url' => $portalUrl,
            'access_token' => $accessToken,
            'refresh_token' => $refreshToken,
            'expires_at' => $expiresAt,
        ];
        $this->writeJson($this->authFile, $auth);
    }

    public function getTokens(string $portalUrl): ?array
    {
        $auth = $this->readJson($this->authFile);
        return $auth[$portalUrl] ?? null;
    }

    // --- Chat Info & Metadata ---

    public function updateChatInfo(string $telegramChatId, ?string $firstName, ?string $lastName, ?string $username, ?string $photoUrl = null): void
    {
        $file = "{$this->leadsPath}/{$telegramChatId}.json";
        $data = $this->readJson($file, [
            'telegram_chat_id' => $telegramChatId,
            'first_name' => '',
            'last_name' => '',
            'username' => '',
            'photo_url' => null,
            'mapping' => null,
            'messages' => [],
            'updated_at' => 0
        ]);

        $data['first_name'] = $firstName ?? $data['first_name'];
        $data['last_name'] = $lastName ?? $data['last_name'];
        $data['username'] = $username ?? $data['username'];
        $data['photo_url'] = $photoUrl ?? $data['photo_url'];
        $data['updated_at'] = time();

        $this->writeJson($file, $data);
    }

    /**
     * Retrieve stored chat info (first_name, last_name, username, etc.)
     * Returns an empty array if no data exists for this chat.
     */
    public function getChatInfo(string $telegramChatId): array
    {
        $file = "{$this->leadsPath}/{$telegramChatId}.json";
        $data = $this->readJson($file, []);
        return [
            'first_name' => $data['first_name'] ?? '',
            'last_name'  => $data['last_name'] ?? '',
            'username'   => $data['username'] ?? '',
            'photo_url'  => $data['photo_url'] ?? null,
        ];
    }

    public function saveMapping(string $telegramChatId, string $b24ConnectorChatId, string $b24SessionId): void
    {
        $file = "{$this->leadsPath}/{$telegramChatId}.json";
        $data = $this->readJson($file);
        if (!$data) return;

        $data['mapping'] = [
            'b24_connector_chat_id' => $b24ConnectorChatId,
            'b24_session_id' => $b24SessionId,
            'last_message_date' => time()
        ];
        $this->writeJson($file, $data);
    }

    public function getMappingByTelegramId(string $telegramChatId): ?array
    {
        $data = $this->readJson("{$this->leadsPath}/{$telegramChatId}.json");
        return $data['mapping'] ?? null;
    }

    public function getTelegramIdByB24ConnectorId(string $b24ConnectorChatId): ?string
    {
        // This is inefficient in JSON (must scan all leads)
        $files = glob("{$this->leadsPath}/*.json");
        foreach ($files as $file) {
            $data = json_decode(file_get_contents($file), true);
            if (($data['mapping']['b24_connector_chat_id'] ?? '') == $b24ConnectorChatId) {
                return (string)$data['telegram_chat_id'];
            }
        }
        return null;
    }

    // --- Messages ---

    private function getNextId(): int
    {
        $seq = $this->readJson($this->seqFile, ['last_id' => 0]);
        $newId = $seq['last_id'] + 1;
        $this->writeJson($this->seqFile, ['last_id' => $newId]);
        return (int)$newId;
    }

    public function saveMessage(string $telegramChatId, string $direction, ?string $text, ?string $mediaType = 'text', ?string $mediaPath = null, ?string $messageId = null): void
    {
        $file = "{$this->leadsPath}/{$telegramChatId}.json";
        $data = $this->readJson($file);
        if (!$data) return;

        // Deduplication (last 5 seconds)
        if ($text) {
            $lastMsgs = array_slice($data['messages'], -5);
            foreach ($lastMsgs as $m) {
                if ($m['text'] === $text && $m['direction'] === $direction && $m['timestamp'] > time() - 5) {
                    return;
                }
            }
        }

        $id = $this->getNextId();
        $msg = [
            'id' => $id,
            'telegram_chat_id' => $telegramChatId,
            'direction' => $direction,
            'message_id' => $messageId,
            'text' => $text,
            'media_type' => $mediaType,
            'media_path' => $mediaPath,
            'timestamp' => time()
        ];

        $data['messages'][] = $msg;
        $this->writeJson($file, $data);

        // Update Global Stream
        $stream = $this->readJson($this->streamFile);
        $stream[] = $msg;
        // Keep only last 100 entries in stream to prevent bloat
        if (count($stream) > 100) array_shift($stream);
        $this->writeJson($this->streamFile, $stream);
    }

    public function getRecentChats(): array
    {
        $results = [];
        $files = glob("{$this->leadsPath}/*.json");
        foreach ($files as $file) {
            $data = json_decode(file_get_contents($file), true);
            if (!$data) continue;

            $lastMsg = end($data['messages']) ?: null;
            $results[] = [
                'telegram_chat_id' => $data['telegram_chat_id'],
                'first_name' => $data['first_name'],
                'last_name' => $data['last_name'],
                'username' => $data['username'],
                'photo_url' => $data['photo_url'],
                'last_message' => $lastMsg['text'] ?? ($lastMsg['media_type'] ? '[' . $lastMsg['media_type'] . ']' : ''),
                'last_message_time' => $lastMsg['timestamp'] ?? 0
            ];
        }

        // Sort by last message time
        usort($results, fn($a, $b) => $b['last_message_time'] <=> $a['last_message_time']);
        return $results;
    }

    public function getMessages(string $telegramChatId, int $limit = 50): array
    {
        $data = $this->readJson("{$this->leadsPath}/{$telegramChatId}.json");
        return array_slice($data['messages'] ?? [], -$limit);
    }

    public function getMessagesSince(string $telegramChatId, int $lastId, int $limit = 50): array
    {
        $data = $this->readJson("{$this->leadsPath}/{$telegramChatId}.json");
        $msgs = $data['messages'] ?? [];
        $filtered = array_filter($msgs, fn($m) => $m['id'] > $lastId);
        return array_slice($filtered, 0, $limit);
    }

    public function getGlobalMessagesSince(int $lastId, int $limit = 50): array
    {
        $stream = $this->readJson($this->streamFile);
        $filtered = array_filter($stream, fn($m) => $m['id'] > $lastId);
        return array_slice($filtered, 0, $limit);
    }
}
