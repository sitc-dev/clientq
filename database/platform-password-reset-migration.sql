SET @add_password_version = (
    SELECT IF(
        EXISTS(SELECT 1 FROM information_schema.columns
            WHERE table_schema = DATABASE() AND table_name = 'platform_users' AND column_name = 'password_version'),
        'SELECT 1',
        'ALTER TABLE platform_users ADD COLUMN password_version INT UNSIGNED NOT NULL DEFAULT 0 AFTER password_hash'
    )
);
PREPARE add_password_version_stmt FROM @add_password_version;
EXECUTE add_password_version_stmt;
DEALLOCATE PREPARE add_password_version_stmt;

CREATE TABLE IF NOT EXISTS password_reset_tokens (
    account_id INT UNSIGNED NOT NULL,
    token_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    password_version INT UNSIGNED NOT NULL,
    expires_at DATETIME NOT NULL,
    PRIMARY KEY (account_id),
    UNIQUE KEY uq_password_reset_token (token_hash),
    CONSTRAINT fk_password_reset_platform_user FOREIGN KEY (account_id) REFERENCES platform_users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS password_reset_rate_limits (
    ip_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    request_count INT UNSIGNED NOT NULL,
    window_started_at DATETIME NOT NULL,
    PRIMARY KEY (ip_hash)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
