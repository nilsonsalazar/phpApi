<?php

declare(strict_types=1);

function success($data = [])
{
    http_response_code(200);

    echo json_encode([
        "success" => true,
        "data" => $data
    ]);

    exit;
}

function errorResponse(string $message,int $code=400)
{
    http_response_code($code);

    echo json_encode([
        "success"=>false,
        "message"=>$message
    ]);

    exit;
}