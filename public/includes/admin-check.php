<?php
require_once __DIR__ . '/auth-check.php';
require_once __DIR__ . '/../../private/db-connect.php';
require_once __DIR__ . '/../../private/role-helpers.php';

if (isset($_SESSION['user_id']) && ctype_digit((string)$_SESSION['user_id'])) {
    $_SESSION['role'] = getUserPrimaryRole($pdo, (int)$_SESSION['user_id']);
}

if (!isAdminUser()) {
    http_response_code(403);
    die('Admin access only.');
}
