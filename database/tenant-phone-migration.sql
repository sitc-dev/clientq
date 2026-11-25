SET @add_client_phone = (
    SELECT IF(
        EXISTS(
            SELECT 1
            FROM information_schema.columns
            WHERE table_schema = DATABASE()
              AND table_name = 'clients'
              AND column_name = 'phone'
        ),
        'SELECT 1',
        'ALTER TABLE clients ADD COLUMN phone CHAR(10) NULL AFTER email'
    )
);
PREPARE add_client_phone_stmt FROM @add_client_phone;
EXECUTE add_client_phone_stmt;
DEALLOCATE PREPARE add_client_phone_stmt;

ALTER TABLE clients MODIFY COLUMN email VARCHAR(255) NULL;

SET @add_phone_confirmation_index = (
    SELECT IF(
        EXISTS(
            SELECT 1
            FROM information_schema.statistics
            WHERE table_schema = DATABASE()
              AND table_name = 'clients'
              AND index_name = 'idx_clients_confirmation_phone'
        ),
        'SELECT 1',
        'CREATE INDEX idx_clients_confirmation_phone ON clients (confirmation_code, phone)'
    )
);
PREPARE add_phone_confirmation_index_stmt FROM @add_phone_confirmation_index;
EXECUTE add_phone_confirmation_index_stmt;
DEALLOCATE PREPARE add_phone_confirmation_index_stmt;
