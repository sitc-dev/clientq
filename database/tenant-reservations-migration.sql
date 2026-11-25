SET @reservation_staff_type = (
    SELECT column_type FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'staff' AND column_name = 'id'
);
SET @reservation_ddl = CONCAT(
'CREATE TABLE IF NOT EXISTS staff_reservation_schedules (
    staff_id ', @reservation_staff_type, ' NOT NULL,
    working_days VARCHAR(13) NOT NULL DEFAULT ''2,3,4,5,6,7'',
    opening_time TIME NOT NULL DEFAULT ''08:30:00'',
    closing_time TIME NOT NULL DEFAULT ''17:00:00'',
    days_ahead TINYINT UNSIGNED NOT NULL DEFAULT 14,
    PRIMARY KEY (staff_id),
    CONSTRAINT fk_reservation_schedule_staff FOREIGN KEY (staff_id) REFERENCES staff (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
PREPARE reservation_stmt FROM @reservation_ddl;
EXECUTE reservation_stmt;
DEALLOCATE PREPARE reservation_stmt;

SET @reservation_ddl = CONCAT(
'CREATE TABLE IF NOT EXISTS reservations (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    staff_id ', @reservation_staff_type, ' NOT NULL,
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
PREPARE reservation_stmt FROM @reservation_ddl;
EXECUTE reservation_stmt;
DEALLOCATE PREPARE reservation_stmt;

SET @reservation_ddl = CONCAT(
'CREATE TABLE IF NOT EXISTS reservation_slots (
    staff_id ', @reservation_staff_type, ' NOT NULL,
    starts_at DATETIME NOT NULL,
    reservation_id INT UNSIGNED NOT NULL,
    PRIMARY KEY (staff_id, starts_at),
    KEY idx_reservation_slots_booking (reservation_id),
    CONSTRAINT fk_reservation_slots_staff FOREIGN KEY (staff_id) REFERENCES staff (id) ON DELETE CASCADE,
    CONSTRAINT fk_reservation_slots_booking FOREIGN KEY (reservation_id) REFERENCES reservations (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
PREPARE reservation_stmt FROM @reservation_ddl;
EXECUTE reservation_stmt;
DEALLOCATE PREPARE reservation_stmt;
