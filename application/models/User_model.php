<?php
defined('BASEPATH') OR exit('No direct script access allowed');

class User_model extends CI_Model {

    /** Seconds without a heartbeat before a user is marked offline.
     *  15 s gives enough headroom for browser tab-switch latency and the
     *  brief gap before visibilitychange restarts the heartbeat interval. */
    const ONLINE_TIMEOUT_SECONDS = 15;

    /**
     * Find a user by email or create one.
     * Names are updated on every login so corrections are reflected immediately.
     * Returns the user's id.
     */
    public function find_or_create($email, $first_name, $last_name)
    {
        $row = $this->db->get_where('users', ['email' => $email])->row();

        if ($row) {
            if ($row->first_name !== $first_name || $row->last_name !== $last_name) {
                $this->db->update('users', [
                    'first_name' => $first_name,
                    'last_name'  => $last_name,
                ], ['id' => $row->id]);
            }
            return (int) $row->id;
        }

        $this->db->insert('users', [
            'email'      => $email,
            'first_name' => $first_name,
            'last_name'  => $last_name,
        ]);
        return (int) $this->db->insert_id();
    }

    /**
     * Generate a new cryptographic session token, persist it, and return it.
     * Calling this invalidates any token previously held by another device.
     */
    public function refresh_session_token($user_id)
    {
        $token = bin2hex(random_bytes(32)); // 64-char hex, cryptographically random
        $this->db->update('users', ['session_token' => $token], ['id' => $user_id]);
        return $token;
    }

    /**
     * Compare the supplied token against the one stored in the DB.
     * Uses hash_equals to prevent timing-based side-channel leaks.
     */
    public function validate_session_token($user_id, $token)
    {
        if (empty($token)) {
            return false;
        }
        $row = $this->db->select('session_token')
                        ->get_where('users', ['id' => $user_id])
                        ->row();
        return $row && $row->session_token !== null
            && hash_equals($row->session_token, $token);
    }

    /**
     * Returns true when a non-null session_token exists for the user,
     * meaning an active session is already open on another device.
     */
    public function has_active_session($user_id)
    {
        $row = $this->db->select('session_token')
                        ->get_where('users', ['id' => $user_id])
                        ->row();
        return $row && $row->session_token !== null;
    }

    /** Invalidate the session token on explicit logout. */
    public function clear_session_token($user_id)
    {
        $this->db->update('users', ['session_token' => null], ['id' => $user_id]);
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
            $user->id        = (int) $user->id;
            $user->is_online = (int) $user->is_online;
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
