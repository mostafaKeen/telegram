<?php

declare(strict_types=1);

// --- Disable All Caching ---
// HTTP Headers for browser/proxy anti-caching
if (!headers_sent()) {
    header("Cache-Control: no-cache, no-store, must-revalidate"); // HTTP 1.1
    header("Pragma: no-cache"); // HTTP 1.0
    header("Expires: 0"); // Proxies
}

// Reset OPcache if available to ensure code changes are reflected immediately
if (function_exists('opcache_reset')) {
    @opcache_reset();
}

// Disable PHP internal caching behavior
ini_set('session.cache_limiter', 'nocache');
ini_set('display_errors', '1');
error_reporting(E_ALL);
// ---------------------------

require_once __DIR__ . '/../../vendor/autoload.php';

use Symfony\Component\Dotenv\Dotenv;
use Bitrix24\TelegramBridge\Storage\JsonStorage;
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
$storage = new JsonStorage(__DIR__ . '/../../var');

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
