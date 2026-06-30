<?php
declare(strict_types=1);

namespace VoiceLink\WebSocket;

/**
 * Database-backed authentication and presence management.
 *
 * Uses PDO directly — the WS server runs outside CodeIgniter so we cannot
 * rely on CI's database layer.  All queries are parameterised to prevent injection.
 */
class Auth
{
    public function __construct(private readonly \PDO $pdo) {}

    // ── Token validation ──────────────────────────────────────────────────────

    /**
     * Validate a session token against the users table.
     * Returns the user row on success, null on failure.
     *
     * @return array{id:int,first_name:string,last_name:string,email:string,session_token:string}|null
     */
    public function validate(int $userId, string $token): ?array
    {
        if ($userId <= 0 || $token === '') {
            return null;
        }

        $stmt = $this->pdo->prepare(
            'SELECT id, first_name, last_name, email, session_token
             FROM   users
             WHERE  id = ?
             LIMIT  1'
        );
        $stmt->execute([$userId]);
        $user = $stmt->fetch();

        if (!$user || !isset($user['session_token'])) {
            return null;
        }

        // Use constant-time comparison to prevent timing attacks
        if (!hash_equals((string) $user['session_token'], $token)) {
            return null;
        }

        return $user;
    }

    // ── Presence management ───────────────────────────────────────────────────

    public function markOnline(int $userId): void
    {
        $this->pdo->prepare(
            'UPDATE users SET is_online = 1, last_seen = NOW() WHERE id = ?'
        )->execute([$userId]);
    }

    public function markOffline(int $userId): void
    {
        $this->pdo->prepare(
            'UPDATE users SET is_online = 0, last_seen = NOW() WHERE id = ?'
        )->execute([$userId]);
    }

    /**
     * Heartbeat: refresh last_seen and ensure is_online = 1.
     */
    public function heartbeat(int $userId): void
    {
        $this->pdo->prepare(
            'UPDATE users SET is_online = 1, last_seen = NOW() WHERE id = ?'
        )->execute([$userId]);
    }

    /**
     * Mark all users offline whose last_seen is older than $timeoutSecs.
     * Called by the WS server's periodic cleanup timer — replaces cron / DB event.
     *
     * @return int Number of rows updated
     */
    public function markStaleOffline(int $timeoutSecs = 30): int
    {
        $stmt = $this->pdo->prepare(
            'UPDATE users
             SET    is_online = 0
             WHERE  is_online = 1
               AND  last_seen < DATE_SUB(NOW(), INTERVAL ? SECOND)'
        );
        $stmt->execute([$timeoutSecs]);
        return $stmt->rowCount();
    }

    /**
     * Fetch IDs of users who were just marked stale (is_online just became 0).
     * Call this BEFORE markStaleOffline to get the list for presence broadcasting.
     *
     * @return int[]
     */
    public function getStaleUserIds(int $timeoutSecs = 30): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id FROM users
             WHERE  is_online = 1
               AND  last_seen < DATE_SUB(NOW(), INTERVAL ? SECOND)'
        );
        $stmt->execute([$timeoutSecs]);
        return array_column($stmt->fetchAll(), 'id');
    }
}
