<?php
/**
 * Database setup script for CustQ
 * Run this once to create the database and seed staff data.
 * Usage: php setup.php
 */

$host = 'localhost';
$user = 'root';
$pass = 'RootMySQL@2026!';

try {
    $pdo = new PDO("mysql:host=$host;charset=utf8mb4", $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]);

    // Create database
    $pdo->exec("CREATE DATABASE IF NOT EXISTS `custq` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $pdo->exec("USE `custq`");
    echo "✓ Database 'custq' created/verified.\n";

    // Create staff table
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `staff` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `name` VARCHAR(100) NOT NULL,
            `goes_by` VARCHAR(50) NOT NULL,
            `position` VARCHAR(100) NOT NULL,
            `experience` VARCHAR(50) NOT NULL,
            `additional_info` TEXT,
            `profile_pic` VARCHAR(255) DEFAULT NULL,
            `is_active` TINYINT(1) DEFAULT 1,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    echo "✓ Table 'staff' created/verified.\n";

    // Create clients table
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `clients` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `name` VARCHAR(100) NOT NULL,
            `email` VARCHAR(255) NOT NULL,
            `no_in_party` INT NOT NULL DEFAULT 1,
            `staff_id` INT NOT NULL,
            `confirmation_code` VARCHAR(6) NOT NULL,
            `sign_in_time` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            `is_active` TINYINT(1) DEFAULT 1,
            FOREIGN KEY (`staff_id`) REFERENCES `staff`(`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    echo "✓ Table 'clients' created/verified.\n";

    // Seed staff data
    $stmt = $pdo->query("SELECT COUNT(*) FROM `staff`");
    if ($stmt->fetchColumn() == 0) {
        $pdo->exec("
            INSERT INTO `staff` (`name`, `goes_by`, `position`, `experience`, `additional_info`) VALUES
            ('Staff Member One', 'Staff1', 'Barber', '8 yrs', 'Specializes in classic cuts and fades. Precision line-ups.'),
            ('Their Name', 'Staff2', 'Master barber, owner', '14 yrs', 'Favorite cut is high-top fade. Always shave beards with a razor.'),
            ('Staff Member Three', 'Staff3', 'Barber', '5 yrs', 'Specializes in modern styles and beard grooming.'),
            ('Another Person', 'Staff4', 'Stylist', '10 yrs', 'Dreads. Twists. Corn rows. Braids.')
        ");
        echo "✓ Staff data seeded (4 members).\n";
    } else {
        echo "✓ Staff data already exists, skipping seed.\n";
    }

    echo "\n✅ Setup complete! The database is ready.\n";

} catch (PDOException $e) {
    echo "✗ Error: " . $e->getMessage() . "\n";
    exit(1);
}
