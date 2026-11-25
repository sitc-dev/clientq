ALTER TABLE businesses
    ADD COLUMN plan ENUM('starter', 'growth', 'pro') NOT NULL DEFAULT 'growth'
    AFTER database_name;
