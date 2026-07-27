<?php

declare(strict_types=1);

// Manejo de cabeceras CORS para Vercel
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");

// Si el navegador pregunta por OPTIONS (preflight), responder OK
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once __DIR__.'/bootstrap.php';

// Obtener datos JSON enviados desde React
$input = json_decode(file_get_contents('php://input'), true);

$username = $input['username'] ?? '';
$password = $input['password'] ?? '';

if (empty($username) || empty($password)) {
    errorResponse("Usuario y contraseña requeridos", 400);
}

// Llamar a la función login() que ya tienes en auth_service.php
$user = login($pdo, $username, $password);

if (!$user) {
    errorResponse("Credenciales incorrectas", 401);
}

// Devuelve el token y el usuario en el formato que espera React
success([
    'token' => $user['token'],
    'user'  => $user,
    'role'  => $user['role'],
    'workspace_id' => $user['workspace_id'],
]);