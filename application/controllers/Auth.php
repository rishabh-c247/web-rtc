<?php
defined('BASEPATH') OR exit('No direct script access allowed');

class Auth extends CI_Controller {

    public function __construct()
    {
        parent::__construct();
        $this->load->model('User_model');
    }

    public function index()
    {
        if ($this->session->userdata('user_id')) {
            redirect('dashboard');
        }
        $this->load->view('auth/login');
    }

    public function login()
    {
        if ($this->input->method() !== 'post') {
            redirect('auth');
        }

        $email = strtolower(trim($this->input->post('email')));
        $first = trim($this->input->post('first_name'));
        $last  = trim($this->input->post('last_name'));

        if (empty($email) || empty($first) || empty($last)) {
            $this->session->set_flashdata('error', 'Email, first name, and last name are required.');
            redirect('auth');
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->session->set_flashdata('error', 'Please enter a valid email address.');
            redirect('auth');
        }

        $first = ucfirst(strtolower($first));
        $last  = ucfirst(strtolower($last));

        $user_id = $this->User_model->find_or_create($email, $first, $last);
        $user    = $this->User_model->get($user_id);

        if ($user->session_token !== null) {
            if ((int) $user->is_online === 1) {
                // Account is actively online — hard block, no override allowed.
                $this->session->set_flashdata(
                    'error',
                    'This account is currently active on another device. ' .
                    'Please sign out from that device before signing in here.'
                );
                redirect('auth');
            }

            // Session token exists but the device is offline (stale session).
            // Ask the user to confirm before revoking it.
            $this->session->set_userdata('pending_login', [
                'user_id'    => $user_id,
                'email'      => $email,
                'first_name' => $first,
                'last_name'  => $last,
            ]);
            redirect('auth/confirm');
        }

        $this->_complete_login($user_id, $email, $first, $last);
    }

    /**
     * GET  /auth/confirm  — show the "already signed in elsewhere" prompt.
     * POST /auth/confirm  — user confirmed; revoke the other session and sign in.
     */
    public function confirm()
    {
        $pending = $this->session->userdata('pending_login');

        if (!$pending) {
            redirect('auth');
        }

        if ($this->input->method() === 'post') {
            $this->session->unset_userdata('pending_login');
            $this->_complete_login(
                $pending['user_id'],
                $pending['email'],
                $pending['first_name'],
                $pending['last_name']
            );
            return;
        }

        $this->load->view('auth/confirm', ['pending' => $pending]);
    }

    /**
     * GET /auth/confirm/cancel — user chose not to revoke the other session.
     */
    public function confirm_cancel()
    {
        $this->session->unset_userdata('pending_login');
        redirect('auth');
    }

    public function logout()
    {
        $user_id = $this->session->userdata('user_id');
        if ($user_id) {
            $this->User_model->set_online($user_id, false);
            $this->User_model->clear_session_token($user_id);
        }
        $this->session->sess_destroy();
        redirect('auth');
    }

    // ----------------------------------------------------------------

    /**
     * Generate a fresh token (kicking any other active device), mark online,
     * write the session, and redirect to the dashboard.
     */
    private function _complete_login($user_id, $email, $first_name, $last_name)
    {
        $token = $this->User_model->refresh_session_token($user_id);
        $this->User_model->set_online($user_id, true);

        $this->session->set_userdata([
            'user_id'       => $user_id,
            'first_name'    => $first_name,
            'last_name'     => $last_name,
            'email'         => $email,
            'session_token' => $token,
        ]);

        redirect('dashboard');
    }
}
