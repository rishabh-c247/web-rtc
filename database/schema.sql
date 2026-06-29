-- ============================================================
-- WebRTC Voice Transmission System — Database Schema
-- Engine: MySQL InnoDB  |  Charset: utf8mb4
-- ============================================================

CREATE DATABASE IF NOT EXISTS web_rtc
    CHARACTER SET utf8mb4
    COLLATE utf8mb4_unicode_ci;

USE web_rtc;

-- ------------------------------------------------------------
-- 1. users
-- ------------------------------------------------------------
CREATE TABLE users (
    id            INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    first_name    VARCHAR(100)    NOT NULL,
    last_name     VARCHAR(100)    NOT NULL,
    session_token VARCHAR(255)    DEFAULT NULL,
    is_online     TINYINT(1)      NOT NULL DEFAULT 0,
    last_seen     DATETIME        DEFAULT NULL,
    created_at    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_session (session_token),
    KEY idx_online  (is_online)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ------------------------------------------------------------
-- 2. conversations  (1:1 between exactly two users)
-- ------------------------------------------------------------
CREATE TABLE conversations (
    id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    created_by INT UNSIGNED NOT NULL,
    created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ------------------------------------------------------------
-- 3. conversation_members
-- ------------------------------------------------------------
CREATE TABLE conversation_members (
    id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
    conversation_id INT UNSIGNED NOT NULL,
    user_id         INT UNSIGNED NOT NULL,
    joined_at       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_conv_member (conversation_id, user_id),
    FOREIGN KEY (conversation_id) REFERENCES conversations(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id)         REFERENCES users(id)         ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ------------------------------------------------------------
-- 4. transmissions
--    status:
--      active    = WebRTC live session in progress
--      pending   = sender is recording but receiver was busy
--                  (no live WebRTC, saved to file when done)
--      completed = finished, audio file uploaded
--      failed    = aborted before completing
--    is_live:
--      1 = receiver hears it live via WebRTC
--      0 = receiver was busy; stored as pending voice card
-- ------------------------------------------------------------
CREATE TABLE transmissions (
    id                  INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    conversation_id     INT UNSIGNED  NOT NULL,
    sender_id           INT UNSIGNED  NOT NULL,
    receiver_id         INT UNSIGNED  NOT NULL,
    status              ENUM('active','pending','completed','failed')
                                      NOT NULL DEFAULT 'active',
    is_live             TINYINT(1)    NOT NULL DEFAULT 1
                        COMMENT '1=WebRTC live, 0=pending record-only',
    started_at          DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    ended_at            DATETIME      DEFAULT NULL,
    duration            INT UNSIGNED  DEFAULT NULL
                        COMMENT 'Seconds; populated on stop',
    audio_file_path     VARCHAR(500)  DEFAULT NULL,
    created_at          DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_sender   (sender_id, status),
    KEY idx_receiver (receiver_id, status),
    FOREIGN KEY (conversation_id) REFERENCES conversations(id) ON DELETE CASCADE,
    FOREIGN KEY (sender_id)       REFERENCES users(id)         ON DELETE CASCADE,
    FOREIGN KEY (receiver_id)     REFERENCES users(id)         ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ------------------------------------------------------------
-- 5. signaling_messages  (WebRTC offer/answer/ICE via polling)
-- ------------------------------------------------------------
CREATE TABLE signaling_messages (
    id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
    transmission_id INT UNSIGNED NOT NULL,
    from_user_id    INT UNSIGNED NOT NULL,
    to_user_id      INT UNSIGNED NOT NULL,
    message_type    ENUM('offer','answer','ice_candidate','hang_up')
                                 NOT NULL,
    payload         LONGTEXT     NOT NULL,
    is_processed    TINYINT(1)   NOT NULL DEFAULT 0,
    created_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_poll (transmission_id, to_user_id, is_processed),
    FOREIGN KEY (transmission_id) REFERENCES transmissions(id)   ON DELETE CASCADE,
    FOREIGN KEY (from_user_id)    REFERENCES users(id)           ON DELETE CASCADE,
    FOREIGN KEY (to_user_id)      REFERENCES users(id)           ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ------------------------------------------------------------
-- 6. voice_messages  (saved cards shown in dashboard)
--    is_pending = 1 → transmission still in progress
--                     (disabled card, no audio yet)
--    is_pending = 0 → completed, audio file available
-- ------------------------------------------------------------
CREATE TABLE voice_messages (
    id              INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    transmission_id INT UNSIGNED  DEFAULT NULL,
    conversation_id INT UNSIGNED  NOT NULL,
    sender_id       INT UNSIGNED  NOT NULL,
    receiver_id     INT UNSIGNED  NOT NULL,
    audio_file_path VARCHAR(500)  DEFAULT NULL,
    duration        INT UNSIGNED  DEFAULT NULL
                    COMMENT 'Seconds',
    playback_state  ENUM('unplayed','played') NOT NULL DEFAULT 'unplayed',
    is_pending      TINYINT(1)    NOT NULL DEFAULT 0
                    COMMENT '1=still recording, 0=ready to play',
    created_at      DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP
                    ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_conv      (conversation_id),
    KEY idx_receiver  (receiver_id),
    FOREIGN KEY (conversation_id) REFERENCES conversations(id) ON DELETE CASCADE,
    FOREIGN KEY (sender_id)       REFERENCES users(id)         ON DELETE CASCADE,
    FOREIGN KEY (receiver_id)     REFERENCES users(id)         ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
