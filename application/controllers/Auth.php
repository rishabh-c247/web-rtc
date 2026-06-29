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

        $first = trim($this->input->post('first_name'));
        $last  = trim($this->input->post('last_name'));

        if (empty($first) || empty($last)) {
            $this->session->set_flashdata('error', 'First name and last name are required.');
            redirect('auth');
        }

        // Normalise
        $first = ucfirst(strtolower($first));
        $last  = ucfirst(strtolower($last));

        $user_id = $this->User_model->find_or_create($first, $last);
        $this->User_model->set_online($user_id, true);

        $this->session->set_userdata([
            'user_id'    => $user_id,
            'first_name' => $first,
            'last_name'  => $last,
        ]);

        redirect('dashboard');
    }

    public function logout()
    {
        $user_id = $this->session->userdata('user_id');
        if ($user_id) {
            $this->User_model->set_online($user_id, false);
        }
        $this->session->sess_destroy();
        redirect('auth');
    }
}
