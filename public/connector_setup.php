<?php
declare(strict_types=1);

require_once(__DIR__ . '/crest.php');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Keen Telegram - Connector Settings</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600&display=swap" rel="stylesheet">
    <script src="//api.bitrix24.com/api/v1/"></script>
    <style>
        body { font-family: 'Inter', sans-serif; padding: 30px; background: #f8f9fa; }
        .card {
            background: white;
            border-radius: 16px;
            padding: 30px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.08);
            max-width: 500px;
            margin: 0 auto;
        }
        .status-badge {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 8px 16px;
            border-radius: 20px;
            background: #e8f5e9;
            color: #2e7d32;
            font-weight: 500;
        }
        .status-dot {
            width: 8px; height: 8px;
            border-radius: 50%;
            background: #4caf50;
            animation: pulse 2s infinite;
        }
        @keyframes pulse {
            0%, 100% { opacity: 1; }
            50% { opacity: 0.5; }
        }
    </style>
</head>
<body>
    <div class="card">
        <h2 style="color: #2CA5E0; margin-bottom: 20px;">
            <svg xmlns="http://www.w3.org/2000/svg" width="28" height="28" viewBox="0 0 40 40" style="vertical-align: middle; margin-right: 8px;">
                <circle cx="20" cy="20" r="20" fill="#2CA5E0"/>
                <path fill="#fff" d="M28.4 13.5l-2.8 13.3c-.2.9-.7 1.1-1.5.7l-4.3-3.2-2.1 2c-.2.2-.4.4-.9.4l.3-4.4 8-7.2c.3-.3-.1-.5-.5-.2L14.7 21.1l-4.3-1.3c-.9-.3-.9-.9.2-1.3l16.8-6.5c.8-.5 1.5-.3 1 1.5z"/>
            </svg>
            Keen Telegram
        </h2>
        <p style="color: #666;">Your Telegram Bot is connected and routing messages through this Open Line.</p>
        <hr style="border: 1px solid #eee;">
        <div style="margin-top: 16px;">
            <span class="status-badge">
                <span class="status-dot"></span>
                Connected & Active
            </span>
        </div>
    </div>
</body>
</html>
