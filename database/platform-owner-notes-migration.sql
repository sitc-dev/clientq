CREATE TABLE IF NOT EXISTS business_owner_notes (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    owner_user_id INT UNSIGNED NOT NULL,
    created_by INT UNSIGNED DEFAULT NULL,
    note_text TEXT NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_business_owner_notes_owner_created (owner_user_id, created_at),
    CONSTRAINT fk_business_owner_notes_owner
        FOREIGN KEY (owner_user_id) REFERENCES platform_users (id) ON DELETE CASCADE,
    CONSTRAINT fk_business_owner_notes_author
        FOREIGN KEY (created_by) REFERENCES platform_users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
