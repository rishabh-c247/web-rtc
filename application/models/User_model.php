<?php
defined('BASEPATH') OR exit('No direct script access allowed');

class User_model extends CI_Model {

    public function find_or_create($first_name, $last_name)
    {
        $this->db->where('first_name', $first_name);
        $this->db->where('last_name',  $last_name);
        $row = $this->db->get('users')->row();

        if ($row) {
            return $row->id;
        }

        $this->db->insert('users', [
            'first_name' => $first_name,
            'last_name'  => $last_name,
        ]);
        return $this->db->insert_id();
    }

    public function get($id)
    {
        return $this->db->get_where('users', ['id' => $id])->row();
    }

    public function get_other_users($exclude_id)
    {
        $this->db->select('id, first_name, last_name, is_online, last_seen');
        $this->db->where('id !=', $exclude_id);
        $this->db->order_by('first_name');
        return $this->db->get('users')->result();
    }

    public function set_online($id, $online = true)
    {
        $this->db->update('users', [
            'is_online' => (int) $online,
            'last_seen' => date('Y-m-d H:i:s'),
        ], ['id' => $id]);
    }

    public function heartbeat($id)
    {
        $this->db->update('users', [
            'is_online' => 1,
            'last_seen' => date('Y-m-d H:i:s'),
        ], ['id' => $id]);

        // Mark users offline if last_seen older than 90 seconds
        $this->db->where('last_seen <', date('Y-m-d H:i:s', time() - 90));
        $this->db->where('is_online', 1);
        $this->db->update('users', ['is_online' => 0]);
    }
}
