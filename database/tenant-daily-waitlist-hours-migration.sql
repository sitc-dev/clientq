CREATE TABLE IF NOT EXISTS waitlist_daily_hours (
    work_date DATE NOT NULL,
    is_closed TINYINT(1) NOT NULL DEFAULT 0,
    opening_time TIME NOT NULL DEFAULT '08:00:00',
    closing_time TIME NOT NULL DEFAULT '16:30:00',
    PRIMARY KEY (work_date),
    CONSTRAINT chk_waitlist_daily_hours CHECK (opening_time >= '00:00:00' AND closing_time < '24:00:00' AND closing_time > opening_time),
    CONSTRAINT chk_waitlist_daily_closed CHECK (is_closed IN (0, 1))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET @clientq_daily_staff_id_type = (
    SELECT COLUMN_TYPE FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'staff' AND COLUMN_NAME = 'id'
);
SET @clientq_daily_staff_ddl = CONCAT(
    'CREATE TABLE IF NOT EXISTS staff_daily_availability (
        staff_id ', @clientq_daily_staff_id_type, ' NOT NULL,
        work_date DATE NOT NULL,
        is_closed TINYINT(1) NOT NULL DEFAULT 0,
        opening_time TIME NOT NULL DEFAULT ''08:00:00'',
        closing_time TIME NOT NULL DEFAULT ''16:30:00'',
        PRIMARY KEY (staff_id, work_date),
        CONSTRAINT fk_staff_daily_availability FOREIGN KEY (staff_id) REFERENCES staff (id) ON DELETE CASCADE,
        CONSTRAINT chk_staff_daily_hours CHECK (opening_time >= ''00:00:00'' AND closing_time < ''24:00:00'' AND closing_time > opening_time),
        CONSTRAINT chk_staff_daily_closed CHECK (is_closed IN (0, 1))
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
);
PREPARE clientq_daily_staff_stmt FROM @clientq_daily_staff_ddl;
EXECUTE clientq_daily_staff_stmt;
DEALLOCATE PREPARE clientq_daily_staff_stmt;
