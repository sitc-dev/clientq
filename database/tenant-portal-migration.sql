CREATE TABLE IF NOT EXISTS business_settings (
    id TINYINT UNSIGNED NOT NULL,
    logo_path VARCHAR(255) DEFAULT NULL,
    theme_primary CHAR(7) NOT NULL DEFAULT '#111827',
    theme_accent CHAR(7) NOT NULL DEFAULT '#DC2626',
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    CONSTRAINT chk_business_settings_singleton CHECK (id = 1)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO business_settings (id) VALUES (1);

SET @add_theme_primary = (
    SELECT IF(
        EXISTS(
            SELECT 1
            FROM information_schema.columns
            WHERE table_schema = DATABASE()
              AND table_name = 'business_settings'
              AND column_name = 'theme_primary'
        ),
        'SELECT 1',
        "ALTER TABLE business_settings ADD COLUMN theme_primary CHAR(7) NOT NULL DEFAULT '#111827' AFTER logo_path"
    )
);
PREPARE add_theme_primary_stmt FROM @add_theme_primary;
EXECUTE add_theme_primary_stmt;
DEALLOCATE PREPARE add_theme_primary_stmt;

SET @add_theme_accent = (
    SELECT IF(
        EXISTS(
            SELECT 1
            FROM information_schema.columns
            WHERE table_schema = DATABASE()
              AND table_name = 'business_settings'
              AND column_name = 'theme_accent'
        ),
        'SELECT 1',
        "ALTER TABLE business_settings ADD COLUMN theme_accent CHAR(7) NOT NULL DEFAULT '#DC2626' AFTER theme_primary"
    )
);
PREPARE add_theme_accent_stmt FROM @add_theme_accent;
EXECUTE add_theme_accent_stmt;
DEALLOCATE PREPARE add_theme_accent_stmt;

ALTER TABLE business_settings
    MODIFY theme_primary CHAR(7) NOT NULL DEFAULT '#111827',
    MODIFY theme_accent CHAR(7) NOT NULL DEFAULT '#DC2626';

UPDATE business_settings
SET theme_primary = '#111827',
    theme_accent = '#DC2626'
WHERE logo_path IS NULL
  AND theme_primary = '#5B2A86'
  AND theme_accent = '#E94B35';
