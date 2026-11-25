SET @add_platform_first_name = (
    SELECT IF(
        EXISTS(
            SELECT 1
            FROM information_schema.columns
            WHERE table_schema = DATABASE()
              AND table_name = 'platform_users'
              AND column_name = 'first_name'
        ),
        'SELECT 1',
        'ALTER TABLE platform_users ADD COLUMN first_name VARCHAR(50) NULL AFTER name'
    )
);
PREPARE add_platform_first_name_stmt FROM @add_platform_first_name;
EXECUTE add_platform_first_name_stmt;
DEALLOCATE PREPARE add_platform_first_name_stmt;

SET @add_platform_last_name = (
    SELECT IF(
        EXISTS(
            SELECT 1
            FROM information_schema.columns
            WHERE table_schema = DATABASE()
              AND table_name = 'platform_users'
              AND column_name = 'last_name'
        ),
        'SELECT 1',
        'ALTER TABLE platform_users ADD COLUMN last_name VARCHAR(50) NULL AFTER first_name'
    )
);
PREPARE add_platform_last_name_stmt FROM @add_platform_last_name;
EXECUTE add_platform_last_name_stmt;
DEALLOCATE PREPARE add_platform_last_name_stmt;

SET @add_platform_phone = (
    SELECT IF(
        EXISTS(
            SELECT 1
            FROM information_schema.columns
            WHERE table_schema = DATABASE()
              AND table_name = 'platform_users'
              AND column_name = 'phone'
        ),
        'SELECT 1',
        'ALTER TABLE platform_users ADD COLUMN phone CHAR(10) NULL AFTER last_name'
    )
);
PREPARE add_platform_phone_stmt FROM @add_platform_phone;
EXECUTE add_platform_phone_stmt;
DEALLOCATE PREPARE add_platform_phone_stmt;
