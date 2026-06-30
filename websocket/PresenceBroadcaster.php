<?php
declare(strict_types=1);

namespace VoiceLink\WebSocket;

/**
 * Broadcasts user presence changes to all connected clients.
 *
 * Presence is pushed, not pulled — eliminating the 2-second conversation-list
 * polling that existed in the HTTP implementation.
 */
class PresenceBroadcaster
{
    /**
     * Notify all connected users (except the subject) that a user's status changed.
     *
     * @param int $userId    The user whose presence changed
     * @param int $isOnline  1 = online, 0 = offline
     */
    public function broadcastPresence(int $userId, int $isOnline, ConnectionManager $connMgr): void
    {
        $connMgr->broadcast([
            'event' => 'presence_update',
            'data'  => [
                'user_id'   => $userId,
                'is_online' => $isOnline,
            ],
        ], $userId);
    }

    /**
     * Tell a newly connected user which other users are currently online.
     * Avoids the need for an initial HTTP fetch just for online status.
     *
     * @param int[]    $onlineUserIds
     */
    public function sendInitialPresence(int $toUserId, array $onlineUserIds, ConnectionManager $connMgr): void
    {
        $connMgr->sendToUser($toUserId, [
            'event' => 'presence_snapshot',
            'data'  => ['online_user_ids' => $onlineUserIds],
        ]);
    }
}
