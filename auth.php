<?php

declare(strict_types=1);

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once __DIR__.'/bootstrap.php';

$currentUser = getCurrentUser($pdo);

if (!$currentUser) {
    errorResponse("No autenticado", 401);
}

unset($currentUser['password_hash']);

success([
    "user" => $currentUser,
    "authenticated" => true
]);