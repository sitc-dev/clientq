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
