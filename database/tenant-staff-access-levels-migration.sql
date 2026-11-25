ALTER TABLE staff MODIFY COLUMN role ENUM('owner', 'manager', 'staff') NOT NULL DEFAULT 'staff';
