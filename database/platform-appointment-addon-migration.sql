SET @add_business_appointments = (
    SELECT IF(
        EXISTS(
            SELECT 1 FROM information_schema.columns
            WHERE table_schema = DATABASE() AND table_name = 'businesses'
              AND column_name = 'appointments_enabled'
        ),
        'SELECT 1',
        'ALTER TABLE businesses ADD COLUMN appointments_enabled TINYINT(1) NOT NULL DEFAULT 0 AFTER plan'
    )
);
PREPARE add_business_appointments_stmt FROM @add_business_appointments;
EXECUTE add_business_appointments_stmt;
DEALLOCATE PREPARE add_business_appointments_stmt;

SET @add_paypal_appointments = (
    SELECT IF(
        EXISTS(
            SELECT 1 FROM information_schema.columns
            WHERE table_schema = DATABASE() AND table_name = 'paypal_signup_subscriptions'
              AND column_name = 'appointments_enabled'
        ),
        'SELECT 1',
        'ALTER TABLE paypal_signup_subscriptions ADD COLUMN appointments_enabled TINYINT(1) NOT NULL DEFAULT 0 AFTER plan'
    )
);
PREPARE add_paypal_appointments_stmt FROM @add_paypal_appointments;
EXECUTE add_paypal_appointments_stmt;
DEALLOCATE PREPARE add_paypal_appointments_stmt;
