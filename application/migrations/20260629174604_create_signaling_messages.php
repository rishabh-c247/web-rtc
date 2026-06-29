<?php
defined('BASEPATH') OR exit('No direct script access allowed');

class Migration_Create_signaling_messages extends CI_Migration {

    public function up()
    {
        $this->db->query("
            CREATE TABLE IF NOT EXISTS `signaling_messages` (
                `id`              INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `transmission_id` INT UNSIGNED NOT NULL,
                `from_user_id`    INT UNSIGNED NOT NULL,
                `to_user_id`      INT UNSIGNED NOT NULL,
                `message_type`    ENUM('offer','answer','ice_candidate','hang_up') NOT NULL,
                `payload`         LONGTEXT     NOT NULL,
                `is_processed`    TINYINT(1)   NOT NULL DEFAULT 0,
                `created_at`      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                KEY `idx_poll` (`transmission_id`, `to_user_id`, `is_processed`),
                CONSTRAINT `fk_sig_transmission`
                    FOREIGN KEY (`transmission_id`) REFERENCES `transmissions`(`id`) ON DELETE CASCADE,
                CONSTRAINT `fk_sig_from`
                    FOREIGN KEY (`from_user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
                CONSTRAINT `fk_sig_to`
                    FOREIGN KEY (`to_user_id`)   REFERENCES `users`(`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ");
    }

    public function down()
    {
        $this->dbforge->drop_table('signaling_messages', TRUE);
    }
}
