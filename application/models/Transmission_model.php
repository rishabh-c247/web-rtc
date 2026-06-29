<?php
defined('BASEPATH') OR exit('No direct script access allowed');

class Transmission_model extends CI_Model {

    /**
     * True when the user has no active outgoing transmission.
     */
    public function is_transmitting($user_id)
    {
        return (bool) $this->db->get_where('transmissions', [
            'sender_id' => (int) $user_id,
            'status'    => 'active',
        ])->num_rows();
    }

    /**
     * True when the user is NOT already receiving a live transmission.
     * A user is "busy receiving" when they appear as receiver_id in an
     * active+live row, OR when they are the sender in any active row
     * (Case 1: you cannot receive live while you are transmitting).
     */
    public function can_receive($user_id)
    {
        // Busy as sender
        if ($this->is_transmitting($user_id)) {
            return false;
        }
        // Busy as live receiver
        $busy = $this->db->get_where('transmissions', [
            'receiver_id' => (int) $user_id,
            'status'      => 'active',
            'is_live'     => 1,
        ])->num_rows();
        return $busy === 0;
    }

    /**
     * Create a new transmission record.
     * $is_live=0 means the receiver is busy; the sender still records
     * but no WebRTC P2P connection is established for live audio.
     */
    public function create($conv_id, $sender_id, $receiver_id, $is_live = true)
    {
        $status = $is_live ? 'active' : 'pending';
        $this->db->insert('transmissions', [
            'conversation_id' => (int) $conv_id,
            'sender_id'       => (int) $sender_id,
            'receiver_id'     => (int) $receiver_id,
            'status'          => $status,
            'is_live'         => (int) $is_live,
            'started_at'      => date('Y-m-d H:i:s'),
        ]);
        return $this->db->insert_id();
    }

    /**
     * Get a single transmission by id.
     */
    public function get($id)
    {
        return $this->db->get_where('transmissions', ['id' => (int) $id])->row();
    }

    /**
     * Mark a transmission completed and record timestamps / duration.
     */
    public function complete($id)
    {
        $tx = $this->get($id);
        if (!$tx) return false;

        $ended   = time();
        $started = strtotime($tx->started_at);
        $duration = max(0, $ended - $started);

        $this->db->update('transmissions', [
            'status'   => 'completed',
            'ended_at' => date('Y-m-d H:i:s', $ended),
            'duration' => $duration,
        ], ['id' => (int) $id]);
        return true;
    }

    /**
     * Attach the uploaded audio file path to a transmission.
     */
    public function set_audio($id, $path)
    {
        $this->db->update('transmissions', [
            'audio_file_path' => $path,
        ], ['id' => (int) $id]);
    }

    /**
     * Return the active live transmission directed at a specific receiver
     * that the receiver has not yet started receiving (no answer sent yet).
     * Used by the receiver's polling loop.
     */
    public function get_incoming_for_receiver($receiver_id)
    {
        $sql = "
            SELECT t.*,
                   u.first_name AS sender_first,
                   u.last_name  AS sender_last
            FROM   transmissions t
            JOIN   users u ON u.id = t.sender_id
            WHERE  t.receiver_id = ?
              AND  t.status      = 'active'
              AND  t.is_live     = 1
            ORDER  BY t.started_at ASC
            LIMIT  1
        ";
        return $this->db->query($sql, [(int) $receiver_id])->row();
    }

    /**
     * Return a pending (non-live) transmission for a receiver so the UI
     * can show a disabled pending card.
     * These are transmissions where is_live=0 and still status=active/pending.
     */
    public function get_pending_for_receiver($receiver_id)
    {
        $sql = "
            SELECT t.*,
                   u.first_name AS sender_first,
                   u.last_name  AS sender_last
            FROM   transmissions t
            JOIN   users u ON u.id = t.sender_id
            WHERE  t.receiver_id = ?
              AND  t.status      IN ('active','pending')
              AND  t.is_live     = 0
            ORDER  BY t.started_at ASC
        ";
        return $this->db->query($sql, [(int) $receiver_id])->result();
    }
}
