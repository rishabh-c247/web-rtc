<?php
declare(strict_types=1);

namespace VoiceLink\WebSocket;

use Ratchet\ConnectionInterface;

/**
 * Routes incoming WebSocket messages to the correct handler.
 *
 * Only the "auth" event is allowed on unauthenticated connections.
 * All other events are rejected with an error message until authentication succeeds.
 *
 * Handlers are plain callables: (ConnectionInterface $conn, array $data) → void
 */
class EventDispatcher
{
    /** @var array<string, callable> */
    private array $handlers = [];

    public function __construct(
        private readonly ConnectionManager $connMgr,
        private readonly Logger            $logger
    ) {}

    /**
     * Register an event handler.
     *
     * @param string   $event   Event name (e.g. 'auth', 'heartbeat', 'signaling')
     * @param callable $handler callable(ConnectionInterface $conn, array $data): void
     */
    public function register(string $event, callable $handler): void
    {
        $this->handlers[$event] = $handler;
    }

    /**
     * Dispatch an inbound message to its registered handler.
     */
    public function dispatch(ConnectionInterface $conn, string $event, array $data): void
    {
        // Rate limiting before anything else
        if (!$this->connMgr->checkRate($conn)) {
            $conn->send(json_encode([
                'event' => 'error',
                'data'  => ['code' => 429, 'message' => 'Rate limit exceeded — slow down'],
            ]));
            $this->logger->warn("Rate limit hit on conn {$conn->resourceId}");
            return;
        }

        // All events except 'auth' require authentication
        if ($event !== 'auth' && !$this->connMgr->isAuthenticated($conn)) {
            $conn->send(json_encode([
                'event' => 'error',
                'data'  => ['code' => 401, 'message' => 'Not authenticated. Send an auth event first.'],
            ]));
            return;
        }

        if (!isset($this->handlers[$event])) {
            $this->logger->debug("Unknown event [{$event}] from conn {$conn->resourceId}");
            return;
        }

        try {
            ($this->handlers[$event])($conn, $data);
        } catch (\Throwable $e) {
            $this->logger->error("Handler [{$event}] threw: " . $e->getMessage());
            $conn->send(json_encode([
                'event' => 'error',
                'data'  => ['code' => 500, 'message' => 'Internal server error'],
            ]));
        }
    }
}
