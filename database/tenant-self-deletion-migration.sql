CREATE TABLE IF NOT EXISTS waitlist_deletion_challenges (
    client_id INT UNSIGNED NOT NULL,
    code_hash VARCHAR(255) NOT NULL,
    expires_at DATETIME NOT NULL,
    attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
    requested_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    window_started_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    send_count TINYINT UNSIGNED NOT NULL DEFAULT 1,
    verification_token_hash CHAR(64) DEFAULT NULL,
    PRIMARY KEY (client_id),
    CONSTRAINT fk_waitlist_deletion_client
        FOREIGN KEY (client_id) REFERENCES clients (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
