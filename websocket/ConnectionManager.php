<?php
declare(strict_types=1);

namespace VoiceLink\WebSocket;

use Ratchet\ConnectionInterface;

/**
 * Tracks every active WebSocket connection.
 *
 * Two indexes are maintained:
 *   $connections[resourceId] → connection metadata
 *   $userMap[userId]         → resourceId  (one connection per authenticated user)
 *
 * When a second connection arrives for the same user (e.g. second tab), the first
 * connection is sent a "kicked" event and closed — enforcing single-device WS.
 */
class ConnectionManager
{
    /** @var array<int, array{conn: ConnectionInterface, user_id: int|null, authed: bool, last_heartbeat: int, connected_at: int, rate_count: int, rate_window: int}> */
    private array $connections = [];

    /** @var array<int, int>  userId → resourceId */
    private array $userMap = [];

    // ── Registration ────────────────────────────────────────────────────────

    public function register(ConnectionInterface $conn): void
    {
        $this->connections[$conn->resourceId] = [
            'conn'           => $conn,
            'user_id'        => null,
            'authed'         => false,
            'last_heartbeat' => time(),
            'connected_at'   => time(),
            'rate_count'     => 0,
            'rate_window'    => time(),
        ];
    }

    /**
     * Mark a connection as authenticated and bind it to a user ID.
     * Any previous connection for this user is kicked and closed.
     */
    public function authenticate(ConnectionInterface $conn, int $userId): void
    {
        // Kick any existing WS connection for this user
        if (isset($this->userMap[$userId])) {
            $oldResId = $this->userMap[$userId];
            if ($oldResId !== $conn->resourceId && isset($this->connections[$oldResId])) {
                $oldConn = $this->connections[$oldResId]['conn'];
                $oldConn->send(json_encode([
                    'event' => 'kicked',
                    'data'  => ['message' => 'Signed in from another device or tab'],
                ]));
                $oldConn->close();
            }
        }

        $this->connections[$conn->resourceId]['user_id'] = $userId;
        $this->connections[$conn->resourceId]['authed']  = true;
        $this->userMap[$userId] = $conn->resourceId;
    }

    public function unregister(ConnectionInterface $conn): void
    {
        $resId = $conn->resourceId;
        if (!isset($this->connections[$resId])) {
            return;
        }
        $userId = $this->connections[$resId]['user_id'];
        if ($userId !== null && isset($this->userMap[$userId]) && $this->userMap[$userId] === $resId) {
            unset($this->userMap[$userId]);
        }
        unset($this->connections[$resId]);
    }

    // ── Queries ──────────────────────────────────────────────────────────────

    public function isAuthenticated(ConnectionInterface $conn): bool
    {
        return $this->connections[$conn->resourceId]['authed'] ?? false;
    }

    public function getUserId(ConnectionInterface $conn): ?int
    {
        return $this->connections[$conn->resourceId]['user_id'] ?? null;
    }

    public function getConnectionByUserId(int $userId): ?ConnectionInterface
    {
        $resId = $this->userMap[$userId] ?? null;
        if ($resId === null) {
            return null;
        }
        return $this->connections[$resId]['conn'] ?? null;
    }

    public function isUserConnected(int $userId): bool
    {
        return isset($this->userMap[$userId]);
    }

    // ── Heartbeat ────────────────────────────────────────────────────────────

    public function updateHeartbeat(ConnectionInterface $conn): void
    {
        if (isset($this->connections[$conn->resourceId])) {
            $this->connections[$conn->resourceId]['last_heartbeat'] = time();
        }
    }

    /**
     * Return connections whose last heartbeat is older than $timeoutSecs.
     * Used by the stale-connection cleanup timer.
     *
     * @return ConnectionInterface[]
     */
    public function getStaleConnections(int $timeoutSecs = 30): array
    {
        $stale  = [];
        $cutoff = time() - $timeoutSecs;
        foreach ($this->connections as $info) {
            if ($info['authed'] && $info['last_heartbeat'] < $cutoff) {
                $stale[] = $info['conn'];
            }
        }
        return $stale;
    }

    // ── Rate limiting ─────────────────────────────────────────────────────────

    /**
     * Sliding per-second rate limit. Returns false when the connection exceeds the cap.
     */
    public function checkRate(ConnectionInterface $conn, int $maxPerSecond = 60): bool
    {
        $resId = $conn->resourceId;
        if (!isset($this->connections[$resId])) {
            return false;
        }
        $now = time();
        if ($this->connections[$resId]['rate_window'] < $now) {
            $this->connections[$resId]['rate_count']  = 0;
            $this->connections[$resId]['rate_window'] = $now;
        }
        $this->connections[$resId]['rate_count']++;
        return $this->connections[$resId]['rate_count'] <= $maxPerSecond;
    }

    // ── Messaging ─────────────────────────────────────────────────────────────

    /**
     * Send a JSON payload to a specific user. Returns false if not connected.
     */
    public function sendToUser(int $userId, array $payload): bool
    {
        $conn = $this->getConnectionByUserId($userId);
        if (!$conn) {
            return false;
        }
        $conn->send(json_encode($payload));
        return true;
    }

    /**
     * Send a JSON payload to every authenticated connection, optionally excluding one user.
     */
    public function broadcast(array $payload, ?int $excludeUserId = null): void
    {
        $json = json_encode($payload);
        foreach ($this->connections as $info) {
            if (!$info['authed']) {
                continue;
            }
            if ($excludeUserId !== null && $info['user_id'] === $excludeUserId) {
                continue;
            }
            $info['conn']->send($json);
        }
    }

    /** Return all authenticated user IDs currently connected. */
    public function getConnectedUserIds(): array
    {
        return array_keys($this->userMap);
    }

    public function count(): int
    {
        return count($this->connections);
    }
}
