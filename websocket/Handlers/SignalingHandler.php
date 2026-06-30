<?php
declare(strict_types=1);

namespace VoiceLink\WebSocket\Handlers;

use Ratchet\ConnectionInterface;
use VoiceLink\WebSocket\ConnectionManager;
use VoiceLink\WebSocket\Logger;

/**
 * Relays WebRTC signaling messages between peers in real time.
 *
 * Previously these messages were written to the signaling_messages DB table
 * and consumed by 1-second polling.  Now they are relayed in-memory via the
 * WS server — sub-millisecond delivery, zero DB writes during active calls.
 *
 * Expected data:
 *   {
 *     "transmission_id": 42,
 *     "to_user_id":      7,
 *     "type":            "offer" | "answer" | "ice_candidate" | "hang_up",
 *     "payload":         "<json string or object>"
 *   }
 */
class SignalingHandler
{
    private const ALLOWED_TYPES = ['offer', 'answer', 'ice_candidate', 'hang_up'];

    public function __construct(
        private readonly ConnectionManager $connMgr,
        private readonly Logger            $logger
    ) {}

    public function __invoke(ConnectionInterface $conn, array $data): void
    {
        $fromUserId = $this->connMgr->getUserId($conn);
        $toUserId   = (int) ($data['to_user_id'] ?? 0);
        $txId       = (int) ($data['transmission_id'] ?? 0);
        $type       = (string) ($data['type'] ?? '');
        $payload    = $data['payload'] ?? null;

        if ($toUserId <= 0 || $txId <= 0 || !in_array($type, self::ALLOWED_TYPES, true)) {
            $conn->send(json_encode([
                'event' => 'error',
                'data'  => ['code' => 400, 'message' => 'Invalid signaling payload'],
            ]));
            return;
        }

        $sent = $this->connMgr->sendToUser($toUserId, [
            'event' => 'signaling',
            'data'  => [
                'transmission_id' => $txId,
                'from_user_id'    => $fromUserId,
                'type'            => $type,
                'payload'         => $payload,
            ],
        ]);

        if ($sent) {
            $this->logger->debug("Signaling [{$type}] tx#{$txId}: user {$fromUserId} → user {$toUserId}");
        } else {
            // Receiver is not connected via WS; acknowledge so the sender can fall back to HTTP
            $conn->send(json_encode([
                'event' => 'signaling_ack',
                'data'  => [
                    'status'          => 'receiver_offline',
                    'type'            => $type,
                    'transmission_id' => $txId,
                ],
            ]));
            $this->logger->warn("Signaling [{$type}] tx#{$txId}: user {$toUserId} not connected via WS");
        }
    }
}
