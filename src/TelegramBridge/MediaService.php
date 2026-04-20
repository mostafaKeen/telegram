<?php

declare(strict_types=1);

namespace Bitrix24\TelegramBridge;

class MediaService
{
    private string $botToken;
    private string $uploadDir;
    private string $baseUrl;

    public function __construct(string $botToken, string $uploadDir, string $baseUrl)
    {
        $this->botToken = $botToken;
        $this->uploadDir = rtrim($uploadDir, '/\\') . DIRECTORY_SEPARATOR;
        $this->baseUrl = rtrim($baseUrl, '/') . '/';
        
        if (!is_dir($this->uploadDir)) {
            mkdir($this->uploadDir, 0775, true);
        }
    }

    /**
     * Downloads a file from Telegram and returns the local filename and public URL.
     */
    public function downloadTelegramFile(string $fileId, string $prefix = 'file_'): ?array
    {
        // 1. Get file path from Telegram
        $url = "https://api.telegram.org/bot{$this->botToken}/getFile?file_id={$fileId}";
        $response = file_get_contents($url);
        if (!$response) return null;

        $data = json_decode($response, true);
        if (!$data['ok']) return null;

        $filePath = $data['result']['file_path'];
        $extension = pathinfo($filePath, PATHINFO_EXTENSION);
        $localName = $prefix . bin2hex(random_bytes(8)) . '.' . $extension;
        $localPath = $this->uploadDir . $localName;

        // 2. Download the actual file
        $fileUrl = "https://api.telegram.org/file/bot{$this->botToken}/{$filePath}";
        $fileContent = file_get_contents($fileUrl);
        if (!$fileContent) return null;

        file_put_contents($localPath, $fileContent);

        return [
            'name' => $localName,
            'local_path' => $localPath,
            'public_url' => $this->baseUrl . 'public/uploads/' . $localName
        ];
    }

    /**
     * Helper to prepare the media object for Bitrix24 imconnector
     */
    public function prepareB24File(array $downloadResult): array
    {
        return [
            'url' => $downloadResult['public_url'],
            'name' => $downloadResult['name']
        ];
    }
}
