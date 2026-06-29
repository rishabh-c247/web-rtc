<?php
defined('BASEPATH') OR exit('No direct script access allowed');

class Migration_Create_conversation_members extends CI_Migration {

    public function up()
    {
        $this->db->query("
            CREATE TABLE IF NOT EXISTS `conversation_members` (
                `id`              INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `conversation_id` INT UNSIGNED NOT NULL,
                `user_id`         INT UNSIGNED NOT NULL,
                `joined_at`       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uq_conv_member` (`conversation_id`, `user_id`),
                CONSTRAINT `fk_cm_conversation`
                    FOREIGN KEY (`conversation_id`) REFERENCES `conversations`(`id`) ON DELETE CASCADE,
                CONSTRAINT `fk_cm_user`
                    FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ");
    }

    public function down()
    {
        $this->dbforge->drop_table('conversation_members', TRUE);
    }
}
