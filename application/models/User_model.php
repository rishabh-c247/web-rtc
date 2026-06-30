<?php
defined('BASEPATH') OR exit('No direct script access allowed');

class User_model extends CI_Model {

    /** Seconds without a heartbeat before a user is marked offline. */
    const ONLINE_TIMEOUT_SECONDS = 3;

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
        $this->mark_stale_offline();

        $this->db->select('id, first_name, last_name, is_online, last_seen');
        $this->db->where('id !=', $exclude_id);
        $this->db->order_by('first_name');
        $users = $this->db->get('users')->result();

        foreach ($users as $user) {
            $user->id         = (int) $user->id;
            $user->is_online  = (int) $user->is_online;
        }

        return $users;
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

        $this->mark_stale_offline();
    }

    public function go_offline($id)
    {
        $this->db->update('users', [
            'is_online' => 0,
            'last_seen' => date('Y-m-d H:i:s'),
        ], ['id' => $id]);
    }

    public function mark_stale_offline()
    {
        $this->db->where('last_seen <', date('Y-m-d H:i:s', time() - self::ONLINE_TIMEOUT_SECONDS));
        $this->db->where('is_online', 1);
        $this->db->update('users', ['is_online' => 0]);
    }
}
