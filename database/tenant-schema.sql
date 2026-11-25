CREATE TABLE IF NOT EXISTS staff (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    name VARCHAR(100) NOT NULL,
    first_name VARCHAR(100) NOT NULL DEFAULT '',
    last_name VARCHAR(100) NOT NULL DEFAULT '',
    address VARCHAR(255) NOT NULL DEFAULT '',
    city VARCHAR(100) NOT NULL DEFAULT '',
    state CHAR(2) NOT NULL DEFAULT '',
    zip VARCHAR(10) NOT NULL DEFAULT '',
    phone CHAR(10) NOT NULL DEFAULT '',
    email VARCHAR(255) NOT NULL DEFAULT '',
    goes_by VARCHAR(50) NOT NULL,
    position VARCHAR(100) NOT NULL DEFAULT '',
    experience TEXT NOT NULL DEFAULT (''),
    additional_info TEXT,
    profile_pic VARCHAR(255) DEFAULT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    username VARCHAR(255) NOT NULL COMMENT 'Login email; legacy column name',
    password_hash VARCHAR(255) NOT NULL,
    password_version INT UNSIGNED NOT NULL DEFAULT 0,
    must_change_password TINYINT(1) NOT NULL DEFAULT 0,
    role ENUM('owner', 'manager', 'staff') NOT NULL DEFAULT 'staff',
    PRIMARY KEY (id),
    UNIQUE KEY uq_staff_username (username)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS password_reset_tokens (
    account_id INT UNSIGNED NOT NULL,
    token_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    password_version INT UNSIGNED NOT NULL,
    expires_at DATETIME NOT NULL,
    PRIMARY KEY (account_id),
    UNIQUE KEY uq_password_reset_token (token_hash),
    CONSTRAINT fk_password_reset_staff FOREIGN KEY (account_id) REFERENCES staff (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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

CREATE TABLE IF NOT EXISTS waitlist_daily_hours (
    work_date DATE NOT NULL,
    is_closed TINYINT(1) NOT NULL DEFAULT 0,
    opening_time TIME NOT NULL DEFAULT '08:00:00',
    closing_time TIME NOT NULL DEFAULT '16:30:00',
    PRIMARY KEY (work_date),
    CONSTRAINT chk_waitlist_daily_hours CHECK (opening_time >= '00:00:00' AND closing_time < '24:00:00' AND closing_time > opening_time),
    CONSTRAINT chk_waitlist_daily_closed CHECK (is_closed IN (0, 1))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS staff_daily_availability (
    staff_id INT UNSIGNED NOT NULL,
    work_date DATE NOT NULL,
    is_closed TINYINT(1) NOT NULL DEFAULT 0,
    opening_time TIME NOT NULL DEFAULT '08:00:00',
    closing_time TIME NOT NULL DEFAULT '16:30:00',
    PRIMARY KEY (staff_id, work_date),
    CONSTRAINT fk_staff_daily_availability FOREIGN KEY (staff_id) REFERENCES staff (id) ON DELETE CASCADE,
    CONSTRAINT chk_staff_daily_hours CHECK (opening_time >= '00:00:00' AND closing_time < '24:00:00' AND closing_time > opening_time),
    CONSTRAINT chk_staff_daily_closed CHECK (is_closed IN (0, 1))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS clients (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    name VARCHAR(100) NOT NULL,
    email VARCHAR(255) DEFAULT NULL,
    phone CHAR(10) NOT NULL,
    no_in_party INT NOT NULL DEFAULT 1,
    staff_id INT UNSIGNED DEFAULT NULL,
    confirmation_code VARCHAR(6) NOT NULL,
    sms_consent TINYINT(1) NOT NULL DEFAULT 0,
    sms_consent_at TIMESTAMP NULL DEFAULT NULL,
    sign_in_time TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    PRIMARY KEY (id),
    KEY idx_clients_active_time (is_active, sign_in_time),
    KEY idx_clients_staff (staff_id),
    KEY idx_clients_confirmation_email (confirmation_code, email),
    KEY idx_clients_confirmation_phone (confirmation_code, phone),
    CONSTRAINT fk_clients_staff
        FOREIGN KEY (staff_id) REFERENCES staff (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS waitlist_deletion_challenges (
    client_id INT UNSIGNED NOT NULL,
    code_hash VARCHAR(255) NOT NULL,
    expires_at DATETIME NOT NULL,
    attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
    requested_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    window_started_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    send_count TINYINT UNSIGNED NOT NULL DEFAULT 1,
    verification_token_hash CHAR(64) DEFAULT NULL,
    PRIMARY KEY (client_id),
    CONSTRAINT fk_waitlist_deletion_client
        FOREIGN KEY (client_id) REFERENCES clients (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS staff_reservation_schedules (
    staff_id INT UNSIGNED NOT NULL,
    working_days VARCHAR(13) NOT NULL DEFAULT '2,3,4,5,6,7',
    opening_time TIME NOT NULL DEFAULT '08:30:00',
    closing_time TIME NOT NULL DEFAULT '17:00:00',
    days_ahead TINYINT UNSIGNED NOT NULL DEFAULT 14,
    PRIMARY KEY (staff_id),
    CONSTRAINT fk_reservation_schedule_staff FOREIGN KEY (staff_id) REFERENCES staff (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS reservations (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    staff_id INT UNSIGNED NOT NULL,
    name VARCHAR(100) NOT NULL,
    phone CHAR(10) NOT NULL,
    party_size TINYINT UNSIGNED NOT NULL,
    starts_at DATETIME NOT NULL,
    ends_at DATETIME NOT NULL,
    confirmation_code CHAR(6) NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_reservations_staff_date (staff_id, starts_at),
    CONSTRAINT fk_reservations_staff FOREIGN KEY (staff_id) REFERENCES staff (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS reservation_slots (
    staff_id INT UNSIGNED NOT NULL,
    starts_at DATETIME NOT NULL,
    reservation_id INT UNSIGNED NOT NULL,
    PRIMARY KEY (staff_id, starts_at),
    KEY idx_reservation_slots_booking (reservation_id),
    CONSTRAINT fk_reservation_slots_staff FOREIGN KEY (staff_id) REFERENCES staff (id) ON DELETE CASCADE,
    CONSTRAINT fk_reservation_slots_booking FOREIGN KEY (reservation_id) REFERENCES reservations (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
