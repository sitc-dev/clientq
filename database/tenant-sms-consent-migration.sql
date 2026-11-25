SET @add_sms_consent = (
    SELECT IF(
        EXISTS(
            SELECT 1
            FROM information_schema.columns
            WHERE table_schema = DATABASE()
              AND table_name = 'clients'
              AND column_name = 'sms_consent'
        ),
        'SELECT 1',
        'ALTER TABLE clients ADD COLUMN sms_consent TINYINT(1) NOT NULL DEFAULT 0 AFTER confirmation_code'
    )
);
PREPARE add_sms_consent_stmt FROM @add_sms_consent;
EXECUTE add_sms_consent_stmt;
DEALLOCATE PREPARE add_sms_consent_stmt;

SET @add_sms_consent_at = (
    SELECT IF(
        EXISTS(
            SELECT 1
            FROM information_schema.columns
            WHERE table_schema = DATABASE()
              AND table_name = 'clients'
              AND column_name = 'sms_consent_at'
        ),
        'SELECT 1',
        'ALTER TABLE clients ADD COLUMN sms_consent_at TIMESTAMP NULL DEFAULT NULL AFTER sms_consent'
    )
);
PREPARE add_sms_consent_at_stmt FROM @add_sms_consent_at;
EXECUTE add_sms_consent_at_stmt;
DEALLOCATE PREPARE add_sms_consent_at_stmt;
