<?php
defined('BASEPATH') OR exit('No direct script access allowed');

class Dashboard extends CI_Controller {

    public function __construct()
    {
        parent::__construct();
        $this->load->model('User_model');
        $this->_check_auth();
    }

    public function index()
    {
        $data['page_title'] = 'Voice Transmission Dashboard';
        $this->load->view('templates/header', $data);
        $this->load->view('dashboard/index', $data);
        $this->load->view('templates/footer', $data);
    }

    /**
     * Validates both session presence and the single-device token.
     * Destroys the session and redirects to login if either check fails.
     */
    private function _check_auth()
    {
        $user_id = $this->session->userdata('user_id');
        $token   = $this->session->userdata('session_token');

        if (!$user_id || !$token) {
            redirect('auth');
        }

        if (!$this->User_model->validate_session_token($user_id, $token)) {
            $this->session->sess_destroy();
            redirect('auth');
        }
    }
}
