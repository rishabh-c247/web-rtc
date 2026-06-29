<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Migrate controller — run from the command line only.
 *
 *   php index.php migrate          → migrate to latest
 *   php index.php migrate rollback → roll back all (latest → 0)
 *
 * Web access is blocked intentionally.
 */
class Migrate extends CI_Controller {

    public function __construct()
    {
        parent::__construct();

        if (!$this->input->is_cli_request()) {
            show_404();
        }
    }

    public function index()
    {
        $this->load->library('migration');

        if ($this->migration->latest() === FALSE) {
            $this->_out('MIGRATION FAILED: ' . $this->migration->error_string());
            exit(1);
        }

        // Read current version from the migrations tracking table
        $row = $this->db->select('version')->get('migrations')->row();
        $version = $row ? $row->version : 'unknown';
        $this->_out("Migration complete. Current version: {$version}");
    }

    public function rollback()
    {
        $this->load->library('migration');

        if ($this->migration->version(0) === FALSE) {
            $this->_out('ROLLBACK FAILED: ' . $this->migration->error_string());
            exit(1);
        }

        $this->_out('Rolled back to version 0 (all tables dropped).');
    }

    private function _out($msg)
    {
        echo $msg . PHP_EOL;
    }
}
