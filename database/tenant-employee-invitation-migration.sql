SET @add_must_change_password = (
    SELECT IF(
        EXISTS(SELECT 1 FROM information_schema.columns
            WHERE table_schema = DATABASE() AND table_name = 'staff' AND column_name = 'must_change_password'),
        'SELECT 1',
        'ALTER TABLE staff ADD COLUMN must_change_password TINYINT(1) NOT NULL DEFAULT 0 AFTER password_version'
    )
);
PREPARE add_must_change_password_stmt FROM @add_must_change_password;
EXECUTE add_must_change_password_stmt;
DEALLOCATE PREPARE add_must_change_password_stmt;

UPDATE staff SET experience = '' WHERE experience IS NULL;
ALTER TABLE staff MODIFY experience TEXT NOT NULL DEFAULT ('');
