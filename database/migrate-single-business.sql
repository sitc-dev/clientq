-- Example migration when the old tables are in a database named clientq_legacy.
-- Change the business details and database names before running.

CREATE DATABASE clientq_tenant_roadtest_barbers
    CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

CREATE TABLE clientq_tenant_roadtest_barbers.staff
    LIKE clientq_legacy.staff;
CREATE TABLE clientq_tenant_roadtest_barbers.clients
    LIKE clientq_legacy.clients;

INSERT INTO clientq_tenant_roadtest_barbers.staff
SELECT * FROM clientq_legacy.staff;

INSERT INTO clientq_tenant_roadtest_barbers.clients
SELECT * FROM clientq_legacy.clients;

INSERT INTO clientq.businesses
    (name, slug, owner_email, database_name)
VALUES
    ('RoadTest Barbers', 'roadtest-barbers', 'owner@example.com', 'clientq_tenant_roadtest_barbers');
