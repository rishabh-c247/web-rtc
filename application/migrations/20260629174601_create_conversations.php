<?php
defined('BASEPATH') OR exit('No direct script access allowed');

class Migration_Create_conversations extends CI_Migration {

    public function up()
    {
        $this->db->query("
            CREATE TABLE IF NOT EXISTS `conversations` (
                `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `created_by` INT UNSIGNED NOT NULL,
                `created_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                CONSTRAINT `fk_conv_creator`
                    FOREIGN KEY (`created_by`) REFERENCES `users`(`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ");
    }

    public function down()
    {
        $this->dbforge->drop_table('conversations', TRUE);
    }
}
