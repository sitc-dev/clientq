SET @add_password_version = (
    SELECT IF(
        EXISTS(SELECT 1 FROM information_schema.columns
            WHERE table_schema = DATABASE() AND table_name = 'staff' AND column_name = 'password_version'),
        'SELECT 1',
        'ALTER TABLE staff ADD COLUMN password_version INT UNSIGNED NOT NULL DEFAULT 0 AFTER password_hash'
    )
);
PREPARE add_password_version_stmt FROM @add_password_version;
EXECUTE add_password_version_stmt;
DEALLOCATE PREPARE add_password_version_stmt;

-- Legacy tenants may use signed staff IDs; keep the foreign key compatible.
SET @reset_account_type = (
    SELECT column_type FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'staff' AND column_name = 'id'
);
SET @create_reset_tokens = CONCAT(
    'CREATE TABLE IF NOT EXISTS password_reset_tokens (
    account_id ', @reset_account_type, ' NOT NULL,
    token_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    password_version INT UNSIGNED NOT NULL,
    expires_at DATETIME NOT NULL,
    PRIMARY KEY (account_id),
    UNIQUE KEY uq_password_reset_token (token_hash),
    CONSTRAINT fk_password_reset_staff FOREIGN KEY (account_id) REFERENCES staff (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
);
PREPARE create_reset_tokens_stmt FROM @create_reset_tokens;
EXECUTE create_reset_tokens_stmt;
DEALLOCATE PREPARE create_reset_tokens_stmt;
