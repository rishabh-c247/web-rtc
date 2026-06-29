<?php
defined('BASEPATH') OR exit('No direct script access allowed');

class Signaling_model extends CI_Model {

    /**
     * Persist a signaling message (offer / answer / ICE candidate / hang_up).
     */
    public function save($data)
    {
        $this->db->insert('signaling_messages', [
            'transmission_id' => (int) $data['transmission_id'],
            'from_user_id'    => (int) $data['from_user_id'],
            'to_user_id'      => (int) $data['to_user_id'],
            'message_type'    => $data['message_type'],
            'payload'         => $data['payload'],
            'is_processed'    => 0,
        ]);
        return $this->db->insert_id();
    }

    /**
     * Fetch all unprocessed messages for a user on a given transmission,
     * then immediately mark them processed (consumed-once pattern).
     */
    public function consume($transmission_id, $to_user_id)
    {
        $this->db->select('id, from_user_id, message_type, payload, created_at');
        $this->db->where('transmission_id', (int) $transmission_id);
        $this->db->where('to_user_id',      (int) $to_user_id);
        $this->db->where('is_processed',    0);
        $this->db->order_by('id', 'ASC');
        $rows = $this->db->get('signaling_messages')->result();

        if (!empty($rows)) {
            $ids = array_column((array) $rows, 'id');
            $this->db->where_in('id', $ids);
            $this->db->update('signaling_messages', ['is_processed' => 1]);
        }

        return $rows;
    }

    /**
     * Remove all signaling messages for a transmission (cleanup after hang_up).
     */
    public function clear($transmission_id)
    {
        $this->db->delete('signaling_messages', [
            'transmission_id' => (int) $transmission_id,
        ]);
    }
}
