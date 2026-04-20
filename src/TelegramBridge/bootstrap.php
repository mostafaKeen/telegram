<?php

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use Symfony\Component\Dotenv\Dotenv;
use Bitrix24\TelegramBridge\Storage\SqliteStorage;
use Bitrix24\SDK\Core\Credentials\ApplicationProfile;
use Bitrix24\SDK\Core\Credentials\Scope;
use Bitrix24\SDK\Core\Credentials\AuthToken;
use Bitrix24\SDK\Services\ServiceBuilderFactory;
use Monolog\Logger;
use Monolog\Handler\StreamHandler;
use Symfony\Component\EventDispatcher\EventDispatcher;

$dotenv = new Dotenv();
// Use bootEnv to ensure $_ENV and $_SERVER are correctly populated
$dotenv->bootEnv(__DIR__ . '/../../.env');

// Init Storage
$storage = new SqliteStorage(__DIR__ . '/../../var/database.sqlite');

// Init Logger
// Ensure logs directory exists
$logDir = __DIR__ . '/../../var/logs';
if (!is_dir($logDir)) {
    if (!@mkdir($logDir, 0775, true)) {
        die("FATAL ERROR: The directory /var/logs/ is missing and the server cannot create it. 
             Please manually create a folder named 'var' in your project root, then a 'logs' folder inside it, and set permissions to 775.");
    }
}

$logger = new Logger('telegram_bridge');
$logger->pushHandler(new StreamHandler($logDir . '/app.log', Logger::DEBUG));

// App Credentials
$appProfile = new ApplicationProfile(
    $_ENV['BITRIX24_CLIENT_ID'],
    $_ENV['BITRIX24_CLIENT_SECRET'],
    new Scope(['imopenlines', 'im', 'crm', 'user', 'imconnector', 'contact_center']),
);

// Event Dispatcher
$eventDispatcher = new EventDispatcher();

/**
 * Build a ServiceBuilder from stored tokens for a portal
 */
function buildServiceBuilder(
    string $portalUrl,
    SqliteStorage $storage,
    ApplicationProfile $appProfile,
    Logger $logger,
    EventDispatcher $eventDispatcher
): \Bitrix24\SDK\Services\ServiceBuilder {
    $tokens = $storage->getTokens($portalUrl);
    if (!$tokens) {
        throw new \RuntimeException("No tokens found for portal: " . $portalUrl);
    }

    $authToken = new AuthToken(
        $tokens['access_token'],
        $tokens['refresh_token'],
        (int)$tokens['expires_at'],
    );

    $factory = new ServiceBuilderFactory($eventDispatcher, $logger);
    return $factory->init(
        $appProfile,
        $authToken,
        'https://' . $portalUrl,
    );
}
