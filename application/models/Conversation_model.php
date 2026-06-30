<?php
defined('BASEPATH') OR exit('No direct script access allowed');

class Conversation_model extends CI_Model {

    /**
     * Find an existing 1:1 conversation between two users.
     */
    public function find_between($user_a, $user_b)
    {
        $sql = "
            SELECT c.id
            FROM   conversations c
            JOIN   conversation_members ma ON ma.conversation_id = c.id AND ma.user_id = ?
            JOIN   conversation_members mb ON mb.conversation_id = c.id AND mb.user_id = ?
            LIMIT  1
        ";
        $query = $this->db->query($sql, [(int) $user_a, (int) $user_b]);
        return $query->row();
    }

    /**
     * Create a new conversation between two users.
     */
    public function create($user_a, $user_b)
    {
        $this->db->trans_start();

        $this->db->insert('conversations', ['created_by' => (int) $user_a]);
        $conv_id = $this->db->insert_id();

        $this->db->insert_batch('conversation_members', [
            ['conversation_id' => $conv_id, 'user_id' => (int) $user_a],
            ['conversation_id' => $conv_id, 'user_id' => (int) $user_b],
        ]);

        $this->db->trans_complete();
        if ($this->db->trans_status() === false) {
            return false;
        }
        return (int) $conv_id;
    }

    /**
     * Get all conversations a user belongs to, with the other member's name.
     */
    public function get_user_conversations($user_id)
    {
        $sql = "
            SELECT
                c.id,
                c.created_at,
                u.id        AS other_user_id,
                u.first_name,
                u.last_name,
                u.is_online
            FROM conversations c
            JOIN conversation_members cm  ON cm.conversation_id = c.id AND cm.user_id = ?
            JOIN conversation_members cm2 ON cm2.conversation_id = c.id AND cm2.user_id != ?
            JOIN users u                  ON u.id = cm2.user_id
            ORDER BY c.created_at DESC
        ";
        $rows = $this->db->query($sql, [(int) $user_id, (int) $user_id])->result();

        foreach ($rows as $row) {
            $row->id            = (int) $row->id;
            $row->other_user_id = (int) $row->other_user_id;
            $row->is_online     = (int) $row->is_online;
        }

        return $rows;
    }

    /**
     * Get a single conversation (ensures calling user is a member).
     */
    public function get_for_user($conv_id, $user_id)
    {
        $sql = "
            SELECT c.*
            FROM   conversations c
            JOIN   conversation_members cm ON cm.conversation_id = c.id AND cm.user_id = ?
            WHERE  c.id = ?
            LIMIT  1
        ";
        return $this->db->query($sql, [(int) $user_id, (int) $conv_id])->row();
    }
}
