SET @add_zip = (
    SELECT IF(
        EXISTS(SELECT 1 FROM information_schema.columns
            WHERE table_schema = DATABASE() AND table_name = 'staff' AND column_name = 'zip'),
        'SELECT 1',
        'ALTER TABLE staff ADD COLUMN zip VARCHAR(10) NOT NULL DEFAULT '''' AFTER state'
    )
);
PREPARE add_zip_stmt FROM @add_zip;
EXECUTE add_zip_stmt;
DEALLOCATE PREPARE add_zip_stmt;

START TRANSACTION;
UPDATE staff SET email = LOWER(TRIM(username))
WHERE TRIM(COALESCE(email, '')) = ''
    AND TRIM(username) REGEXP '^[^[:space:]@]+@[^[:space:]@]+[.][^[:space:]@]+$';
UPDATE staff SET username = LOWER(TRIM(email)), email = LOWER(TRIM(email)),
    password_version = password_version + 1
WHERE TRIM(email) REGEXP '^[^[:space:]@]+@[^[:space:]@]+[.][^[:space:]@]+$'
    AND BINARY username <> BINARY LOWER(TRIM(email));
COMMIT;
