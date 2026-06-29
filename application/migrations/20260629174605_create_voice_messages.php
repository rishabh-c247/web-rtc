<?php
defined('BASEPATH') OR exit('No direct script access allowed');

class Migration_Create_voice_messages extends CI_Migration {

    public function up()
    {
        $this->db->query("
            CREATE TABLE IF NOT EXISTS `voice_messages` (
                `id`              INT UNSIGNED  NOT NULL AUTO_INCREMENT,
                `transmission_id` INT UNSIGNED  DEFAULT NULL,
                `conversation_id` INT UNSIGNED  NOT NULL,
                `sender_id`       INT UNSIGNED  NOT NULL,
                `receiver_id`     INT UNSIGNED  NOT NULL,
                `audio_file_path` VARCHAR(500)  DEFAULT NULL,
                `duration`        INT UNSIGNED  DEFAULT NULL
                                  COMMENT 'Seconds',
                `playback_state`  ENUM('unplayed','played') NOT NULL DEFAULT 'unplayed',
                `is_pending`      TINYINT(1)    NOT NULL DEFAULT 0
                                  COMMENT '1=still recording, 0=ready to play',
                `created_at`      DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at`      DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP
                                  ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                KEY `idx_conv`     (`conversation_id`),
                KEY `idx_receiver` (`receiver_id`),
                CONSTRAINT `fk_vm_conversation`
                    FOREIGN KEY (`conversation_id`) REFERENCES `conversations`(`id`) ON DELETE CASCADE,
                CONSTRAINT `fk_vm_sender`
                    FOREIGN KEY (`sender_id`)   REFERENCES `users`(`id`) ON DELETE CASCADE,
                CONSTRAINT `fk_vm_receiver`
                    FOREIGN KEY (`receiver_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ");
    }

    public function down()
    {
        $this->dbforge->drop_table('voice_messages', TRUE);
    }
}
