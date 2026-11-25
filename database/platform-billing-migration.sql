CREATE TABLE IF NOT EXISTS platform_users (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    name VARCHAR(100) NOT NULL,
    email VARCHAR(255) NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    role ENUM('platform_owner', 'business_owner') NOT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_platform_users_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS platform_login_attempts (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    ip_address VARCHAR(45) NOT NULL,
    email_hash CHAR(64) NOT NULL,
    role ENUM('platform_owner', 'business_owner') NOT NULL,
    attempted_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_platform_login_ip_time (ip_address, attempted_at),
    KEY idx_platform_login_account_time (email_hash, role, attempted_at),
    KEY idx_platform_login_time (attempted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS business_owners (
    business_id INT UNSIGNED NOT NULL,
    user_id INT UNSIGNED NOT NULL,
    is_primary TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (business_id, user_id),
    KEY idx_business_owners_user (user_id),
    CONSTRAINT fk_business_owners_business
        FOREIGN KEY (business_id) REFERENCES businesses (id) ON DELETE CASCADE,
    CONSTRAINT fk_business_owners_user
        FOREIGN KEY (user_id) REFERENCES platform_users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS invoices (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    business_id INT UNSIGNED NOT NULL,
    created_by INT UNSIGNED NOT NULL,
    invoice_number VARCHAR(32) NOT NULL,
    description VARCHAR(255) NOT NULL,
    amount_cents INT UNSIGNED NOT NULL,
    currency CHAR(3) NOT NULL DEFAULT 'usd',
    status ENUM('open', 'paid', 'void') NOT NULL DEFAULT 'open',
    due_date DATE DEFAULT NULL,
    stripe_checkout_session_id VARCHAR(255) DEFAULT NULL,
    stripe_checkout_url VARCHAR(2048) DEFAULT NULL,
    stripe_checkout_expires_at TIMESTAMP NULL DEFAULT NULL,
    stripe_checkout_attempt INT UNSIGNED NOT NULL DEFAULT 0,
    stripe_payment_intent_id VARCHAR(255) DEFAULT NULL,
    paid_at TIMESTAMP NULL DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_invoices_number (invoice_number),
    UNIQUE KEY uq_invoices_checkout_session (stripe_checkout_session_id),
    KEY idx_invoices_business_status (business_id, status, due_date),
    CONSTRAINT fk_invoices_business
        FOREIGN KEY (business_id) REFERENCES businesses (id) ON DELETE CASCADE,
    CONSTRAINT fk_invoices_creator
        FOREIGN KEY (created_by) REFERENCES platform_users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET @add_checkout_url = (
    SELECT IF(
        EXISTS(
            SELECT 1 FROM information_schema.columns
            WHERE table_schema = DATABASE() AND table_name = 'invoices'
              AND column_name = 'stripe_checkout_url'
        ),
        'SELECT 1',
        'ALTER TABLE invoices ADD COLUMN stripe_checkout_url VARCHAR(2048) DEFAULT NULL AFTER stripe_checkout_session_id'
    )
);
PREPARE add_checkout_url_stmt FROM @add_checkout_url;
EXECUTE add_checkout_url_stmt;
DEALLOCATE PREPARE add_checkout_url_stmt;

SET @add_checkout_expires = (
    SELECT IF(
        EXISTS(
            SELECT 1 FROM information_schema.columns
            WHERE table_schema = DATABASE() AND table_name = 'invoices'
              AND column_name = 'stripe_checkout_expires_at'
        ),
        'SELECT 1',
        'ALTER TABLE invoices ADD COLUMN stripe_checkout_expires_at TIMESTAMP NULL DEFAULT NULL AFTER stripe_checkout_url'
    )
);
PREPARE add_checkout_expires_stmt FROM @add_checkout_expires;
EXECUTE add_checkout_expires_stmt;
DEALLOCATE PREPARE add_checkout_expires_stmt;

SET @add_checkout_attempt = (
    SELECT IF(
        EXISTS(
            SELECT 1 FROM information_schema.columns
            WHERE table_schema = DATABASE() AND table_name = 'invoices'
              AND column_name = 'stripe_checkout_attempt'
        ),
        'SELECT 1',
        'ALTER TABLE invoices ADD COLUMN stripe_checkout_attempt INT UNSIGNED NOT NULL DEFAULT 0 AFTER stripe_checkout_expires_at'
    )
);
PREPARE add_checkout_attempt_stmt FROM @add_checkout_attempt;
EXECUTE add_checkout_attempt_stmt;
DEALLOCATE PREPARE add_checkout_attempt_stmt;
