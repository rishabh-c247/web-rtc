<?php
declare(strict_types=1);

namespace VoiceLink\WebSocket;

use Ratchet\ConnectionInterface;
use Ratchet\MessageComponentInterface;

/**
 * Main Ratchet MessageComponent.
 *
 * Lifecycle:
 *   onOpen    → register connection, send "connected" greeting
 *   onMessage → validate format, rate-limit, dispatch to handler
 *   onClose   → unregister connection, mark user offline, broadcast departure
 *   onError   → log, close connection
 *
 * All business logic lives in EventDispatcher + Handlers.
 * This class only manages the connection lifecycle.
 */
class Server implements MessageComponentInterface
{
    /** Seconds a connection has to send an auth event before being closed. */
    private const AUTH_TIMEOUT_SECONDS = 15;

    public function __construct(
        private readonly ConnectionManager  $connMgr,
        private readonly EventDispatcher    $dispatcher,
        private readonly Auth               $auth,
        private readonly PresenceBroadcaster $presence,
        private readonly Logger             $logger
    ) {}

    public function onOpen(ConnectionInterface $conn): void
    {
        $this->connMgr->register($conn);
        $this->logger->info("Connection opened: #{$conn->resourceId} from {$this->getIp($conn)}");

        $conn->send(json_encode([
            'event' => 'connected',
            'data'  => ['message' => 'Authenticate within ' . self::AUTH_TIMEOUT_SECONDS . 's'],
        ]));
    }

    public function onMessage(ConnectionInterface $from, $msg): void
    {
        // Validate JSON
        $decoded = json_decode($msg, true);
        if (!is_array($decoded) || empty($decoded['event'])) {
            $from->send(json_encode([
                'event' => 'error',
                'data'  => ['code' => 400, 'message' => 'Expected JSON: {"event":"name","data":{}}'],
            ]));
            return;
        }

        $event = (string) $decoded['event'];
        $data  = is_array($decoded['data'] ?? null) ? $decoded['data'] : [];

        $this->dispatcher->dispatch($from, $event, $data);
    }

    public function onClose(ConnectionInterface $conn): void
    {
        $userId = $this->connMgr->getUserId($conn);

        $this->connMgr->unregister($conn);

        if ($userId !== null) {
            $this->auth->markOffline($userId);
            $this->presence->broadcastPresence($userId, 0, $this->connMgr);
            $this->logger->info("User {$userId} disconnected (conn #{$conn->resourceId}). Total: {$this->connMgr->count()}");
        } else {
            $this->logger->info("Unauthenticated conn #{$conn->resourceId} closed. Total: {$this->connMgr->count()}");
        }
    }

    public function onError(ConnectionInterface $conn, \Exception $e): void
    {
        $this->logger->error("Error on conn #{$conn->resourceId}: " . $e->getMessage());
        $conn->close();
    }

    private function getIp(ConnectionInterface $conn): string
    {
        return $conn->remoteAddress ?? 'unknown';
    }
}
