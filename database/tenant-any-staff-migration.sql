SET @clientq_staff_id_type = (
    SELECT COLUMN_TYPE
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'clients' AND COLUMN_NAME = 'staff_id'
);
SET @clientq_staff_id_ddl = CONCAT(
    'ALTER TABLE clients MODIFY COLUMN staff_id ', @clientq_staff_id_type, ' DEFAULT NULL'
);
PREPARE clientq_staff_id_stmt FROM @clientq_staff_id_ddl;
EXECUTE clientq_staff_id_stmt;
DEALLOCATE PREPARE clientq_staff_id_stmt;
