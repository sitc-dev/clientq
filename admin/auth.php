<?php
/**
 * Admin authentication middleware
 * Include at the top of every admin page.
 */
session_start();
require_once __DIR__ . '/../config.php';

function requireAuth() {
    if (!isset($_SESSION['staff_id'])) {
        header('Location: login');
        exit;
    }
}

function getLoggedInStaff() {
    if (!isset($_SESSION['staff_id'])) return null;
    $db = getDB();
    $stmt = $db->prepare("SELECT * FROM staff WHERE id = :id AND is_active = 1");
    $stmt->execute([':id' => $_SESSION['staff_id']]);
    return $stmt->fetch();
}

function isOwner() {
    return isset($_SESSION['role']) && $_SESSION['role'] === 'owner';
}

function requireOwner() {
    requireAuth();
    if (!isOwner()) {
        http_response_code(403);
        die('Access denied. Owner privileges required.');
    }
}
