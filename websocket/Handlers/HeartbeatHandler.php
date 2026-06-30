<?php
declare(strict_types=1);

namespace VoiceLink\WebSocket\Handlers;

use Ratchet\ConnectionInterface;
use VoiceLink\WebSocket\Auth;
use VoiceLink\WebSocket\ConnectionManager;
use VoiceLink\WebSocket\Logger;

/**
 * Handles the "heartbeat" event.
 *
 * The client sends a heartbeat every 5 seconds (vs. the old 1-second HTTP poll).
 * The server updates the DB's last_seen and responds with a "pong" event.
 *
 * A dedicated stale-connection timer in the server bootstrap handles eviction
 * of connections that stop sending heartbeats (network drop, crashed tab).
 */
class HeartbeatHandler
{
    public function __construct(
        private readonly ConnectionManager $connMgr,
        private readonly Auth              $auth,
        private readonly Logger            $logger
    ) {}

    public function __invoke(ConnectionInterface $conn, array $data): void
    {
        $userId = $this->connMgr->getUserId($conn);
        if ($userId === null) {
            return;
        }

        $this->connMgr->updateHeartbeat($conn);
        $this->auth->heartbeat($userId);

        $conn->send(json_encode([
            'event' => 'pong',
            'data'  => ['ts' => time()],
        ]));

        $this->logger->debug("Heartbeat from user {$userId}");
    }
}
