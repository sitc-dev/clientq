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
