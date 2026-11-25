CREATE TABLE IF NOT EXISTS phone_groups (
    group_code CHAR(1) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    phone_number VARCHAR(16) DEFAULT NULL,
    PRIMARY KEY (group_code),
    UNIQUE KEY uq_phone_groups_number (phone_number),
    CONSTRAINT chk_phone_groups_code CHECK (group_code IN ('A', 'B', 'C', 'D', 'E')),
    CONSTRAINT chk_phone_groups_number CHECK (
        phone_number IS NULL OR phone_number REGEXP '^[+][1-9][0-9]{1,14}$'
    )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO phone_groups (group_code)
VALUES ('A'), ('B'), ('C'), ('D'), ('E')
ON DUPLICATE KEY UPDATE group_code = phone_groups.group_code;

CREATE TABLE IF NOT EXISTS business_phone_groups (
    business_id INT UNSIGNED NOT NULL,
    group_code CHAR(1) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    PRIMARY KEY (business_id),
    KEY idx_business_phone_groups_group (group_code),
    CONSTRAINT fk_business_phone_groups_business
        FOREIGN KEY (business_id) REFERENCES businesses (id) ON DELETE CASCADE,
    CONSTRAINT fk_business_phone_groups_group
        FOREIGN KEY (group_code) REFERENCES phone_groups (group_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
