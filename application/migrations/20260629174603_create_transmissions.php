<?php
defined('BASEPATH') OR exit('No direct script access allowed');

class Migration_Create_transmissions extends CI_Migration {

    public function up()
    {
        $this->db->query("
            CREATE TABLE IF NOT EXISTS `transmissions` (
                `id`              INT UNSIGNED  NOT NULL AUTO_INCREMENT,
                `conversation_id` INT UNSIGNED  NOT NULL,
                `sender_id`       INT UNSIGNED  NOT NULL,
                `receiver_id`     INT UNSIGNED  NOT NULL,
                `status`          ENUM('active','pending','completed','failed')
                                                NOT NULL DEFAULT 'active',
                `is_live`         TINYINT(1)    NOT NULL DEFAULT 1
                                  COMMENT '1=WebRTC live audio, 0=pending record-only',
                `started_at`      DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `ended_at`        DATETIME      DEFAULT NULL,
                `duration`        INT UNSIGNED  DEFAULT NULL
                                  COMMENT 'Duration in seconds',
                `audio_file_path` VARCHAR(500)  DEFAULT NULL,
                `created_at`      DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                KEY `idx_sender`   (`sender_id`, `status`),
                KEY `idx_receiver` (`receiver_id`, `status`),
                CONSTRAINT `fk_tx_conversation`
                    FOREIGN KEY (`conversation_id`) REFERENCES `conversations`(`id`) ON DELETE CASCADE,
                CONSTRAINT `fk_tx_sender`
                    FOREIGN KEY (`sender_id`)   REFERENCES `users`(`id`) ON DELETE CASCADE,
                CONSTRAINT `fk_tx_receiver`
                    FOREIGN KEY (`receiver_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ");
    }

    public function down()
    {
        $this->dbforge->drop_table('transmissions', TRUE);
    }
}
