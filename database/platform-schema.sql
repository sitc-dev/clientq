CREATE TABLE IF NOT EXISTS businesses (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    name VARCHAR(120) NOT NULL,
    slug VARCHAR(63) NOT NULL,
    owner_email VARCHAR(255) NOT NULL,
    database_name VARCHAR(64) NOT NULL,
    plan ENUM('starter', 'growth', 'pro') NOT NULL DEFAULT 'growth',
    appointments_enabled TINYINT(1) NOT NULL DEFAULT 0,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_businesses_slug (slug),
    UNIQUE KEY uq_businesses_database_name (database_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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

CREATE TABLE IF NOT EXISTS paypal_signup_subscriptions (
    id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    subscription_id VARCHAR(50) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
    plan_id VARCHAR(50) NOT NULL,
    plan ENUM('starter', 'growth', 'pro') NOT NULL,
    appointments_enabled TINYINT(1) NOT NULL DEFAULT 0,
    amount_cents INT UNSIGNED NOT NULL,
    environment ENUM('sandbox', 'live') NOT NULL,
    pending_registration JSON DEFAULT NULL,
    first_payment_id VARCHAR(50) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
    business_id INT UNSIGNED DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    provisioned_at TIMESTAMP NULL DEFAULT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_paypal_signup_subscription (subscription_id),
    UNIQUE KEY uq_paypal_signup_payment (first_payment_id),
    UNIQUE KEY uq_paypal_signup_business (business_id),
    CONSTRAINT fk_paypal_signup_business
        FOREIGN KEY (business_id) REFERENCES businesses (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS stripe_signup_subscriptions (
    id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    checkout_session_id VARCHAR(255) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
    subscription_id VARCHAR(255) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
    customer_id VARCHAR(255) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
    plan ENUM('starter', 'growth', 'pro') NOT NULL,
    appointments_enabled TINYINT(1) NOT NULL DEFAULT 0,
    amount_cents INT UNSIGNED NOT NULL,
    environment ENUM('test', 'live') NOT NULL,
    pending_registration JSON DEFAULT NULL,
    first_payment_id VARCHAR(255) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
    business_id INT UNSIGNED DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    provisioned_at TIMESTAMP NULL DEFAULT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_stripe_signup_checkout (checkout_session_id),
    UNIQUE KEY uq_stripe_signup_subscription (subscription_id),
    UNIQUE KEY uq_stripe_signup_payment (first_payment_id),
    UNIQUE KEY uq_stripe_signup_business (business_id),
    CONSTRAINT fk_stripe_signup_business
        FOREIGN KEY (business_id) REFERENCES businesses (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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

CREATE TABLE IF NOT EXISTS signup_attempts (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    ip_address VARCHAR(45) NOT NULL,
    attempted_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_signup_attempts_ip_time (ip_address, attempted_at),
    KEY idx_signup_attempts_time (attempted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS platform_users (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    name VARCHAR(100) NOT NULL,
    first_name VARCHAR(50) DEFAULT NULL,
    last_name VARCHAR(50) DEFAULT NULL,
    phone CHAR(10) DEFAULT NULL,
    email VARCHAR(255) NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    password_version INT UNSIGNED NOT NULL DEFAULT 0,
    role ENUM('platform_owner', 'business_owner') NOT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_platform_users_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS password_reset_tokens (
    account_id INT UNSIGNED NOT NULL,
    token_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    password_version INT UNSIGNED NOT NULL,
    expires_at DATETIME NOT NULL,
    PRIMARY KEY (account_id),
    UNIQUE KEY uq_password_reset_token (token_hash),
    CONSTRAINT fk_password_reset_platform_user FOREIGN KEY (account_id) REFERENCES platform_users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS password_reset_rate_limits (
    ip_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    request_count INT UNSIGNED NOT NULL,
    window_started_at DATETIME NOT NULL,
    PRIMARY KEY (ip_hash)
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
