<?php
declare(strict_types=1);

namespace VoiceLink\WebSocket;

use Psr\Http\Message\ServerRequestInterface;
use React\Http\Message\Response;

/**
 * Internal HTTP server that allows CodeIgniter controllers to push events to
 * connected WebSocket clients without going through the public WS protocol.
 *
 * Listens on 127.0.0.1:{WS_ADMIN_PORT} — never exposed to the internet.
 *
 * Accepted POST body (application/json):
 *   {
 *     "event":      "transmission_incoming",
 *     "to_user_id": 5,
 *     "data":       { ... }
 *   }
 *
 * Used by Api.php for:
 *   - transmission/start  → push "transmission_incoming" to receiver
 *   - transmission/stop   → push "signaling/hang_up"      to the other peer
 *   - transmission/upload → push "voice_card_ready"        to both parties
 */
class AdminServer
{
    public function __construct(
        private readonly ConnectionManager   $connMgr,
        private readonly PresenceBroadcaster $presence,
        private readonly Logger              $logger
    ) {}

    public function __invoke(ServerRequestInterface $request): Response
    {
        // Only accept POST to /internal/push
        if ($request->getUri()->getPath() !== '/internal/push') {
            return new Response(404, ['Content-Type' => 'text/plain'], 'Not found');
        }
        if ($request->getMethod() !== 'POST') {
            return new Response(405, ['Content-Type' => 'text/plain'], 'Method Not Allowed');
        }

        $body = json_decode((string) $request->getBody(), true);

        if (
            !is_array($body)           ||
            empty($body['event'])      ||
            !isset($body['to_user_id'])
        ) {
            return new Response(400, ['Content-Type' => 'application/json'],
                json_encode(['ok' => false, 'error' => 'Missing event or to_user_id']));
        }

        $event    = (string) $body['event'];
        $toUserId = (int)    $body['to_user_id'];
        $data     = is_array($body['data'] ?? null) ? $body['data'] : [];

        $sent = $this->connMgr->sendToUser($toUserId, [
            'event' => $event,
            'data'  => $data,
        ]);

        $this->logger->info(
            sprintf('Admin push [%s] → user %d: %s', $event, $toUserId, $sent ? 'delivered' : 'not connected')
        );

        return new Response(200, ['Content-Type' => 'application/json'],
            json_encode(['ok' => true, 'delivered' => $sent]));
    }
}
