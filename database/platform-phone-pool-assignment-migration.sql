CREATE TABLE IF NOT EXISTS phone_pool_state (
    id TINYINT UNSIGNED NOT NULL,
    last_group_code CHAR(1) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
    PRIMARY KEY (id),
    CONSTRAINT chk_phone_pool_state_singleton CHECK (id = 1),
    CONSTRAINT fk_phone_pool_state_group
        FOREIGN KEY (last_group_code) REFERENCES phone_groups (group_code) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO phone_pool_state (id, last_group_code)
SELECT 1, (SELECT group_code FROM business_phone_groups ORDER BY business_id DESC LIMIT 1)
ON DUPLICATE KEY UPDATE id = phone_pool_state.id;
