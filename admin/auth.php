<?php
/**
 * Admin authentication middleware
 * Include at the top of every admin page.
 */
session_start();
require_once __DIR__ . '/../src/php/config.php';

$tenant = requireTenant();

function requireAuth() {
    if (getSessionStaff() === null) {
        session_unset();
        header('Location: login');
        exit;
    }
}

function getLoggedInStaff() {
    return getSessionStaff();
}

function isOwner() {
    $staff = getSessionStaff();
    return $staff !== null && $staff['role'] === 'owner';
}

function requireOwner() {
    requireAuth();
    if (!isOwner()) {
        http_response_code(403);
        die('Access denied. Owner privileges required.');
    }

}

function requireStaffAdministrator() {
    requireAuth();
    if (!staffCanManageUsers(getLoggedInStaff())) {
        http_response_code(403);
        die('Access denied. Owner or Manager access is required.');
    }
}
