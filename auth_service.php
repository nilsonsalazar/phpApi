<?php

declare(strict_types=1);

/**
 * Usuario temporal mientras implementamos el login.
 *
 * Más adelante este método leerá el token de la tabla
 * user_sessions y devolverá el usuario autenticado.
 */
/**
 * Devuelve el usuario autenticado mediante el token Bearer.
 */
function getCurrentUser(PDO $pdo): ?array
{
    $token = getBearerToken();

    if (!$token) {
        return null;
    }

    return getSessionByToken($pdo, $token);
}

/**
 * Devuelve true si el usuario es administrador.
 */
function isAdmin(): bool
{
    return getCurrentUser()['role'] === 'admin';
}

/**
 * Devuelve el Workspace actual.
 */
function getWorkspaceId(): int
{
    return (int)getCurrentUser()['workspace_id'];
}

/**
 * Busca un usuario por teléfono.
 */
function getUserByPhone(PDO $pdo, string $phone): ?array
{
    $sql = "
        SELECT *
        FROM users
        WHERE phone = ?
          AND active = 1
        LIMIT 1
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([$phone]);

    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    return $user ?: null;
}

/**
 * Intenta autenticar un usuario mediante teléfono y contraseña.
 *
 * Devuelve el usuario si las credenciales son correctas.
 * Devuelve null si son incorrectas.
 */
function login(PDO $pdo, string $phone, string $password): ?array
{
    $user = getUserByPhone($pdo, $phone);

    if (!$user) {
        return null;
    }

    if (!$user['active']) {
        return null;
    }

    if (!password_verify($password, $user['password_hash'])) {
        return null;
    }
    
    updateLastLogin($pdo, (int)$user['id']);

    $user['token'] = createSession($pdo, $user);
    // Nunca devolver información sensible
unset($user['password_hash']);

    return $user;
}

function updateLastLogin(PDO $pdo, int $userId): void
{
    $stmt = $pdo->prepare("
        UPDATE users
        SET last_login = NOW()
        WHERE id = ?
    ");

    $stmt->execute([$userId]);
}
/**
 * Valida si el usuario actual tiene uno de los roles permitidos.
 * Detiene la ejecución con un código 403 si no tiene permisos.
 */
function requireRole(PDO $pdo, array $allowedRoles): void
{
    $currentUser = getCurrentUser($pdo);

    if (!$currentUser) {
        http_response_code(401);
        echo json_encode(['error' => 'No autorizado']);
        exit();
    }

    $role = $currentUser['role'] ?? 'reader';

    if (!in_array($role, $allowedRoles, true)) {
        http_response_code(403);
        echo json_encode(['error' => 'Acceso denegado: permisos insuficientes']);
        exit();
    }
}