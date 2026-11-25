ALTER TABLE business_settings
    MODIFY theme_primary CHAR(7) NOT NULL DEFAULT '#111827',
    MODIFY theme_accent CHAR(7) NOT NULL DEFAULT '#DC2626';

UPDATE business_settings
SET theme_primary = '#111827',
    theme_accent = '#DC2626'
WHERE logo_path IS NULL
  AND theme_primary = '#5B2A86'
  AND theme_accent = '#E94B35';
