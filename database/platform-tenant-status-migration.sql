CREATE TABLE IF NOT EXISTS tenant_status_pages (
    slug VARCHAR(63) NOT NULL,
    business_id INT UNSIGNED NOT NULL,
    business_name VARCHAR(120) NOT NULL,
    logo_path VARCHAR(255) DEFAULT NULL,
    theme_primary CHAR(7) NOT NULL,
    status ENUM('inactive', 'deleted') NOT NULL,
    changed_at DATETIME NOT NULL,
    changed_by_id INT UNSIGNED NOT NULL,
    changed_by_name VARCHAR(100) NOT NULL,
    note VARCHAR(500) NOT NULL,
    PRIMARY KEY (slug),
    KEY idx_tenant_status_business (business_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
