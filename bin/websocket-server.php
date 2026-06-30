#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * VoiceLink WebSocket Server — bootstrap entry point.
 *
 * Start:    php bin/websocket-server.php
 * As daemon: see config/systemd/voicelink-ws.service
 *            or config/supervisor/voicelink-ws.conf
 *
 * Two servers share the same React event loop:
 *   - WS server on WS_PORT      (e.g. 8080) — proxied by Apache
 *   - Admin HTTP on WS_ADMIN_PORT (e.g. 8081) — localhost only, for CI→WS pushes
 */

require_once dirname(__DIR__) . '/vendor/autoload.php';

use React\EventLoop\Loop;
use React\Http\HttpServer;
use React\Socket\SocketServer;
use Ratchet\Server\IoServer;
use Ratchet\Http\HttpServer as RatchetHttpServer;
use Ratchet\WebSocket\WsServer;
use VoiceLink\WebSocket\Server;
use VoiceLink\WebSocket\ConnectionManager;
use VoiceLink\WebSocket\Auth;
use VoiceLink\WebSocket\EventDispatcher;
use VoiceLink\WebSocket\Logger;
use VoiceLink\WebSocket\PresenceBroadcaster;
use VoiceLink\WebSocket\AdminServer;
use VoiceLink\WebSocket\Handlers\AuthHandler;
use VoiceLink\WebSocket\Handlers\HeartbeatHandler;
use VoiceLink\WebSocket\Handlers\SignalingHandler;

// ── Load environment ──────────────────────────────────────────────────────────

$envFile = dirname(__DIR__) . '/.env';
if (!file_exists($envFile)) {
    fwrite(STDERR, "[FATAL] .env file not found at {$envFile}\n");
    exit(1);
}

foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
    $line = trim($line);
    if ($line === '' || str_starts_with($line, '#')) {
        continue;
    }
    if (str_contains($line, '=')) {
        [$key, $val] = explode('=', $line, 2);
        $_ENV[trim($key)] = trim($val);
    }
}

// ── Configuration ──────────────────────────────────────────────────────────────

$dbHost    = $_ENV['DB_HOST']     ?? 'localhost';
$dbName    = $_ENV['DB_DATABASE'] ?? '';
$dbUser    = $_ENV['DB_USERNAME'] ?? '';
$dbPass    = $_ENV['DB_PASSWORD'] ?? '';
$wsPort    = (int) ($_ENV['WS_PORT']       ?? 8080);
$adminPort = (int) ($_ENV['WS_ADMIN_PORT'] ?? 8081);
$logFile   = $_ENV['WS_LOG_FILE']  ?? dirname(__DIR__) . '/application/logs/websocket.log';
$verbose   = in_array('--verbose', $argv ?? [], true);

// ── Database connection ────────────────────────────────────────────────────────

try {
    $pdo = new PDO(
        "mysql:host={$dbHost};dbname={$dbName};charset=utf8mb4",
        $dbUser,
        $dbPass,
        [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4",
            // Persistent connections can cause stale state in long-running processes;
            // use non-persistent and re-connect on error below.
            PDO::ATTR_PERSISTENT         => false,
        ]
    );
} catch (PDOException $e) {
    fwrite(STDERR, "[FATAL] Cannot connect to database: " . $e->getMessage() . "\n");
    exit(1);
}

// ── Service wiring ────────────────────────────────────────────────────────────

$logger     = new Logger($logFile, $verbose);
$connMgr    = new ConnectionManager();
$auth       = new Auth($pdo);
$presence   = new PresenceBroadcaster();
$dispatcher = new EventDispatcher($connMgr, $logger);

// Wire event handlers
$dispatcher->register('auth',      new AuthHandler($connMgr, $auth, $logger, $presence));
$dispatcher->register('heartbeat', new HeartbeatHandler($connMgr, $auth, $logger));
$dispatcher->register('signaling', new SignalingHandler($connMgr, $logger));

// Main WS server component
$wsComponent = new Server($connMgr, $dispatcher, $auth, $presence, $logger);

// ── React event loop ──────────────────────────────────────────────────────────

$loop = Loop::get();

// WebSocket server — correct Ratchet nesting:
//   IoServer → RatchetHttpServer → WsServer → our Server component
$wsSocket = new SocketServer("0.0.0.0:{$wsPort}", [], $loop);
$wsApp    = new RatchetHttpServer(new WsServer($wsComponent));
$ioServer = new IoServer($wsApp, $wsSocket, $loop);

// Admin HTTP server — CI controllers call http://127.0.0.1:{adminPort}/internal/push
$adminHandler = new AdminServer($connMgr, $presence, $logger);
$httpServer   = new HttpServer($loop, $adminHandler);
$adminSocket  = new SocketServer("127.0.0.1:{$adminPort}", [], $loop);
$httpServer->listen($adminSocket);

// ── Periodic timers ───────────────────────────────────────────────────────────

// Stale-user cleanup: mark offline any user whose last_seen exceeds 30 seconds.
// This replaces the cron job / MySQL Event Scheduler approach entirely.
$loop->addPeriodicTimer(15.0, static function () use ($auth, $connMgr, $presence, $logger): void {
    $staleIds = $auth->getStaleUserIds(30);
    if (empty($staleIds)) {
        return;
    }
    $auth->markStaleOffline(30);
    foreach ($staleIds as $userId) {
        // Only broadcast for users not connected via WS (WS disconnect already broadcasts)
        if (!$connMgr->isUserConnected($userId)) {
            $presence->broadcastPresence($userId, 0, $connMgr);
        }
    }
    $logger->info('Stale cleanup: marked ' . count($staleIds) . ' user(s) offline');
});

// Stale-connection eviction: close WS connections that stopped heartbeating.
$loop->addPeriodicTimer(20.0, static function () use ($connMgr, $auth, $presence, $logger): void {
    $stale = $connMgr->getStaleConnections(30);
    foreach ($stale as $conn) {
        $userId = $connMgr->getUserId($conn);
        $logger->info("Evicting stale WS conn #{$conn->resourceId} for user {$userId}");
        if ($userId !== null) {
            $auth->markOffline($userId);
            $presence->broadcastPresence($userId, 0, $connMgr);
        }
        $conn->close();
    }
});

// MySQL keep-alive: send a cheap query every 60s to prevent "MySQL server has gone away"
$loop->addPeriodicTimer(60.0, static function () use ($pdo, $logger): void {
    try {
        $pdo->query('SELECT 1');
    } catch (PDOException $e) {
        $logger->error('MySQL keep-alive failed: ' . $e->getMessage());
    }
});

// ── Start ─────────────────────────────────────────────────────────────────────

$logger->info("═══════════════════════════════════════════════════════");
$logger->info("VoiceLink WebSocket Server");
$logger->info("  WS port:    {$wsPort}   (ws://0.0.0.0:{$wsPort})");
$logger->info("  Admin port: {$adminPort} (http://127.0.0.1:{$adminPort})");
$logger->info("  Log file:   {$logFile}");
$logger->info("═══════════════════════════════════════════════════════");

$loop->run();
