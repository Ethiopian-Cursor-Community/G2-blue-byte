-- Optional migration: aligns DB with runtime audit + fraud helpers.
-- Run once on your MySQL database (e.g. phpMyAdmin or mysql CLI).

CREATE TABLE IF NOT EXISTS audit_logs (
    id                  INT AUTO_INCREMENT PRIMARY KEY,
    actor_app_user_id   INT DEFAULT NULL,
    action              VARCHAR(128) NOT NULL,
    entity_type         VARCHAR(64) DEFAULT NULL,
    entity_id           VARCHAR(128) DEFAULT NULL,
    ip                  VARCHAR(45) DEFAULT NULL,
    user_agent          VARCHAR(500) DEFAULT NULL,
    meta                JSON DEFAULT NULL,
    created_at          DATETIME DEFAULT CURRENT_TIMESTAMP,
    KEY idx_actor (actor_app_user_id),
    KEY idx_action (action),
    KEY idx_created (created_at),
    CONSTRAINT fk_audit_actor FOREIGN KEY (actor_app_user_id) REFERENCES app_users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS fraud_signals (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    signal_type     VARCHAR(64) NOT NULL,
    ref_type        VARCHAR(32) NOT NULL,
    ref_id          VARCHAR(64) NOT NULL,
    score           INT NOT NULL DEFAULT 0,
    meta            JSON DEFAULT NULL,
    resolved        TINYINT(1) NOT NULL DEFAULT 0,
    created_at      DATETIME DEFAULT CURRENT_TIMESTAMP,
    KEY idx_open (resolved, score),
    KEY idx_ref (ref_type, ref_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
