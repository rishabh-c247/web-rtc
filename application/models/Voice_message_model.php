<?php
defined('BASEPATH') OR exit('No direct script access allowed');

class Voice_message_model extends CI_Model {

    /**
     * Create a voice message record.
     * Call with is_pending=1 when the transmission is still in progress
     * (receiver was busy); call with is_pending=0 for normal completions.
     */
    public function create($data)
    {
        $this->db->insert('voice_messages', [
            'transmission_id' => isset($data['transmission_id']) ? (int) $data['transmission_id'] : null,
            'conversation_id' => (int) $data['conversation_id'],
            'sender_id'       => (int) $data['sender_id'],
            'receiver_id'     => (int) $data['receiver_id'],
            'audio_file_path' => $data['audio_file_path'] ?? null,
            'duration'        => isset($data['duration']) ? (int) $data['duration'] : null,
            'playback_state'  => 'unplayed',
            'is_pending'      => isset($data['is_pending']) ? (int) $data['is_pending'] : 0,
        ]);
        return $this->db->insert_id();
    }

    /**
     * Finalise a pending voice message after its audio file has been uploaded.
     */
    public function finalize($transmission_id, $audio_path, $duration)
    {
        $this->db->update('voice_messages', [
            'audio_file_path' => $audio_path,
            'duration'        => (int) $duration,
            'is_pending'      => 0,
        ], ['transmission_id' => (int) $transmission_id]);
    }

    /**
     * Get all voice messages for a conversation visible to a specific user.
     * Includes messages where the user is either sender or receiver.
     */
    public function get_for_conversation($conv_id, $user_id)
    {
        $sql = "
            SELECT
                vm.id,
                vm.transmission_id,
                vm.audio_file_path,
                vm.duration,
                vm.playback_state,
                vm.is_pending,
                vm.created_at,
                vm.updated_at,
                s.id         AS sender_id,
                s.first_name AS sender_first,
                s.last_name  AS sender_last,
                r.id         AS receiver_id,
                r.first_name AS receiver_first,
                r.last_name  AS receiver_last
            FROM   voice_messages vm
            JOIN   users s ON s.id = vm.sender_id
            JOIN   users r ON r.id = vm.receiver_id
            WHERE  vm.conversation_id = ?
              AND  (vm.sender_id = ? OR vm.receiver_id = ?)
            ORDER  BY vm.created_at ASC
        ";
        return $this->db->query($sql, [
            (int) $conv_id,
            (int) $user_id,
            (int) $user_id,
        ])->result();
    }

    /**
     * Mark a voice message as played.
     */
    public function mark_played($id)
    {
        $this->db->update('voice_messages',
            ['playback_state' => 'played'],
            ['id' => (int) $id]
        );
    }

    /**
     * Return the voice message row linked to a transmission.
     */
    public function get_by_transmission($tx_id)
    {
        return $this->db->get_where('voice_messages',
            ['transmission_id' => (int) $tx_id]
        )->row();
    }
}
