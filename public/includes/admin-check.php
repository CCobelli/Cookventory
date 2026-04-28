<?php
require_once __DIR__ . '/auth-check.php';
require_once __DIR__ . '/../../private/role-helpers.php';

if (!isAdminUser()) {
    http_response_code(403);
    die('Admin access only.');
}
