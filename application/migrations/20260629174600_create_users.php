<?php
defined('BASEPATH') OR exit('No direct script access allowed');

class Migration_Create_users extends CI_Migration {

    public function up()
    {
        $this->db->query("
            CREATE TABLE IF NOT EXISTS `users` (
                `id`            INT UNSIGNED    NOT NULL AUTO_INCREMENT,
                `email`         VARCHAR(255)    NOT NULL,
                `first_name`    VARCHAR(100)    NOT NULL,
                `last_name`     VARCHAR(100)    NOT NULL,
                `session_token` VARCHAR(255)    DEFAULT NULL,
                `is_online`     TINYINT(1)      NOT NULL DEFAULT 0,
                `last_seen`     DATETIME        DEFAULT NULL,
                `created_at`    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                UNIQUE KEY `idx_email`    (`email`),
                KEY        `idx_session`  (`session_token`),
                KEY        `idx_online`   (`is_online`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ");
    }

    public function down()
    {
        $this->dbforge->drop_table('users', TRUE);
    }
}
