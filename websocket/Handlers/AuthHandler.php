<?php
declare(strict_types=1);

namespace VoiceLink\WebSocket\Handlers;

use Ratchet\ConnectionInterface;
use VoiceLink\WebSocket\Auth;
use VoiceLink\WebSocket\ConnectionManager;
use VoiceLink\WebSocket\Logger;
use VoiceLink\WebSocket\PresenceBroadcaster;

/**
 * Handles the "auth" event sent by clients immediately after WS connection.
 *
 * Expected data:
 *   { "user_id": 5, "token": "hex-session-token" }
 *
 * On success the connection is authenticated and a presence snapshot is pushed
 * so the client immediately knows who is online without an HTTP round-trip.
 */
class AuthHandler
{
    public function __construct(
        private readonly ConnectionManager  $connMgr,
        private readonly Auth               $auth,
        private readonly Logger             $logger,
        private readonly PresenceBroadcaster $presence
    ) {}

    public function __invoke(ConnectionInterface $conn, array $data): void
    {
        $userId = (int) ($data['user_id'] ?? 0);
        $token  = trim((string) ($data['token'] ?? ''));

        $user = $this->auth->validate($userId, $token);

        if (!$user) {
            $conn->send(json_encode([
                'event' => 'auth_error',
                'data'  => ['message' => 'Invalid or expired credentials'],
            ]));
            $this->logger->warn("Auth failed for user_id={$userId} on conn {$conn->resourceId}");
            // Close after a brief moment so the client can read the error
            $conn->close();
            return;
        }

        // Bind connection — kicks any duplicate WS session for this user
        $this->connMgr->authenticate($conn, $userId);
        $this->auth->markOnline($userId);

        $conn->send(json_encode([
            'event' => 'auth_ok',
            'data'  => [
                'user_id'    => $userId,
                'first_name' => $user['first_name'],
                'last_name'  => $user['last_name'],
            ],
        ]));

        $this->logger->info("User {$userId} ({$user['first_name']} {$user['last_name']}) authenticated on conn {$conn->resourceId}");

        // Tell this user who is currently online
        $onlineIds = $this->connMgr->getConnectedUserIds();
        $this->presence->sendInitialPresence($userId, $onlineIds, $this->connMgr);

        // Tell everyone else this user just came online
        $this->presence->broadcastPresence($userId, 1, $this->connMgr);
    }
}
