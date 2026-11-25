<?php
/**
 * Database configuration for CustQ
 */

define('DB_HOST', 'localhost');
define('DB_NAME', 'custq');
define('DB_USER', 'root');
define('DB_PASS', 'RootMySQL@2026!');

// Business name displayed throughout the site
define('BUSINESS_NAME', 'Some Business');

// Pagination
define('CLIENTS_PER_PAGE', 25);

function getDB() {
    static $pdo = null;
    if ($pdo === null) {
        try {
            $pdo = new PDO(
                "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4",
                DB_USER,
                DB_PASS,
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false,
                ]
            );
        } catch (PDOException $e) {
            http_response_code(500);
            die(json_encode(['error' => 'Database connection failed: ' . $e->getMessage()]));
        }
    }
    return $pdo;
}
