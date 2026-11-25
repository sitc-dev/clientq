SET @staff_profile_columns = (
    SELECT GROUP_CONCAT(CONCAT('ADD COLUMN ', required.definition) ORDER BY required.sort_order SEPARATOR ', ')
    FROM (
        SELECT 1 AS sort_order, 'first_name' AS column_name, "first_name VARCHAR(100) NOT NULL DEFAULT '' AFTER name" AS definition
        UNION ALL SELECT 2, 'last_name', "last_name VARCHAR(100) NOT NULL DEFAULT '' AFTER first_name"
        UNION ALL SELECT 3, 'address', "address VARCHAR(255) NOT NULL DEFAULT '' AFTER last_name"
        UNION ALL SELECT 4, 'city', "city VARCHAR(100) NOT NULL DEFAULT '' AFTER address"
        UNION ALL SELECT 5, 'state', "state CHAR(2) NOT NULL DEFAULT '' AFTER city"
        UNION ALL SELECT 6, 'phone', "phone CHAR(10) NOT NULL DEFAULT '' AFTER state"
        UNION ALL SELECT 7, 'email', "email VARCHAR(255) NOT NULL DEFAULT '' AFTER phone"
    ) AS required
    WHERE NOT EXISTS (
        SELECT 1 FROM information_schema.columns existing
        WHERE existing.table_schema = DATABASE()
            AND existing.table_name = 'staff'
            AND existing.column_name = required.column_name
    )
);
SET @update_staff_profile = IF(
    @staff_profile_columns IS NULL,
    'SELECT 1',
    CONCAT('ALTER TABLE staff ', @staff_profile_columns)
);
PREPARE update_staff_profile_stmt FROM @update_staff_profile;
EXECUTE update_staff_profile_stmt;
DEALLOCATE PREPARE update_staff_profile_stmt;

UPDATE staff SET
    first_name = SUBSTRING_INDEX(TRIM(name), ' ', 1),
    last_name = IF(LOCATE(' ', TRIM(name)) = 0, '', LTRIM(SUBSTRING(TRIM(name), LOCATE(' ', TRIM(name)) + 1)))
WHERE first_name = '' AND last_name = '' AND TRIM(name) <> '';
