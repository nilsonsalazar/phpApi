<?php

declare(strict_types=1);

/**
 * Obtiene el token Bearer enviado por el cliente.
 */
function getBearerToken(): ?string
{
    $headers = getallheaders();

    if (!isset($headers['Authorization'])) {
        return null;
    }

    if (preg_match('/Bearer\s(\S+)/', $headers['Authorization'], $matches)) {
        return $matches[1];
    }

    return null;
}

/**
 * Genera un token aleatorio de 64 caracteres.
 */
function generateSessionToken(): string
{
    return bin2hex(random_bytes(32));
}


/**
 * Guarda una nueva sesión.
 */
function createSession(PDO $pdo, array $user): string
{
    $token = generateSessionToken();

    $expires = new DateTime('+30 days');

    $sql = "
        INSERT INTO user_sessions
        (
            user_id,
            token,
            expires_at,
            last_activity,
            ip_address,
            user_agent
        )
        VALUES
        (
            :user_id,
            :token,
            :expires_at,
            NOW(),
            :ip,
            :agent
        )
    ";

    $stmt = $pdo->prepare($sql);

    $stmt->execute([
        ':user_id' => $user['id'],
        ':token' => $token,
        ':expires_at' => $expires->format('Y-m-d H:i:s'),
        ':ip' => $_SERVER['REMOTE_ADDR'] ?? null,
        ':agent' => $_SERVER['HTTP_USER_AGENT'] ?? null
    ]);

    return $token;
}
/**
 * Busca una sesión válida por su token.
 */
function getSessionByToken(PDO $pdo, string $token): ?array
{
    $sql = "
        SELECT
            u.*
        FROM user_sessions s
        INNER JOIN users u
            ON u.id = s.user_id
        WHERE s.token = ?
          AND s.expires_at > NOW()
          AND u.active = 1
        LIMIT 1
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([$token]);

    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    return $user ?: null;
}