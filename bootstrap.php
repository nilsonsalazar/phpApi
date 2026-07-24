<?php

declare(strict_types=1);

header("Content-Type: application/json; charset=UTF-8");

require_once __DIR__.'/config.php';
require_once __DIR__.'/response.php';
require_once __DIR__.'/session.php';
require_once __DIR__.'/auth_service.php';

try {

    $pdo = new PDO(
        "mysql:host=".DB_HOST.";dbname=".DB_NAME.";charset=utf8mb4",
        DB_USER,
        DB_PASS,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]
    );

} catch(PDOException $e){

    errorResponse("No se pudo conectar con la base de datos.",500);

}