<?php
// Configuración de errores para desarrollo
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Headers CORS para permitir peticiones desde Vercel o cualquier dominio
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With");
header("Access-Control-Max-Age: 3600");
header("Content-Type: application/json; charset=UTF-8");

// Responder inmediatamente a las peticiones preflight (OPTIONS) de React
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// Cargar bootstrap y middleware de autenticación
require_once __DIR__ . '/bootstrap.php';

// Validar usuario autenticado
$currentUser = getCurrentUser($pdo);
if (!$currentUser) {
    http_response_code(401);
    echo json_encode(['error' => 'No autorizado']);
    exit();
}

$workspaceId = $currentUser['workspace_id'];

// Router según el método HTTP
$requestMethod = $_SERVER['REQUEST_METHOD'];

switch ($requestMethod) {
    case 'GET':
        handleGetRequest();
        break;
    case 'POST':
        handlePostRequest();
        break;
    case 'PUT':
        handlePutRequest();
        break;
    case 'DELETE':
        handleDeleteRequest();
        break;
    default:
        http_response_code(405);
        echo json_encode(['error' => 'Method not allowed']);
        break;
}

// ------------------------------------------------------------------
// FUNCIONES DE MANEJO DE PETICIONES
// ------------------------------------------------------------------

function handleGetRequest() {
    global $pdo, $workspaceId;
    
    if (isset($_GET['search'])) {
        $searchTerm = '%' . $_GET['search'] . '%';
        $stmt = $pdo->prepare("SELECT id, title, key_signature, tempo, time_signature, artist FROM songs WHERE workspace_id = ? AND (title LIKE ? OR song_data LIKE ? OR artist LIKE ?)");
        $stmt->execute([$workspaceId, $searchTerm, $searchTerm, $searchTerm]);
        echo json_encode($stmt->fetchAll());
    } elseif (isset($_GET['id'])) {
        $stmt = $pdo->prepare("SELECT * FROM songs WHERE workspace_id = ? AND id = ?");
        $stmt->execute([$workspaceId, $_GET['id']]);
        $song = $stmt->fetch();
        
        if ($song) {
            $song['song_data'] = json_decode($song['song_data'], true);
            echo json_encode($song);
        } else {
            http_response_code(404);
            echo json_encode(['error' => 'Song not found']);
        }
    } else {
        $stmt = $pdo->prepare("SELECT id, title, key_signature, tempo, time_signature,artist FROM songs WHERE workspace_id = ?");
        $stmt->execute([$workspaceId]);
        echo json_encode($stmt->fetchAll());
    }
}

function handlePostRequest() {
    global $pdo, $workspaceId;
    
    $data = json_decode(file_get_contents('php://input'), true);
    
    $requiredFields = ['title', 'key_signature', 'tempo', 'time_signature', 'song_data', 'artist'];
    foreach ($requiredFields as $field) {
        if (!isset($data[$field])) {
            http_response_code(400);
            echo json_encode(['error' => "Missing required field: $field"]);
            return;
        }
    }
    
    try {
        $stmt = $pdo->prepare("INSERT INTO songs (workspace_id, title, key_signature, tempo, time_signature, song_data, artist) VALUES (?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([
            $workspaceId,
            $data['title'],
            $data['key_signature'],
            $data['tempo'],
            $data['time_signature'],
            json_encode($data['song_data']),
            $data['artist']
        ]);
        
        $songId = $pdo->lastInsertId();
        echo json_encode([
            'id' => $songId,
            'message' => 'Song created successfully',
            'data' => $data
        ]);
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Database error: ' . $e->getMessage()]);
    }
}

function handlePutRequest() {
    global $pdo, $workspaceId;
    
    $data = json_decode(file_get_contents('php://input'), true);
    
    $requiredFields = ['id', 'title', 'key_signature', 'tempo', 'time_signature', 'song_data', 'artist'];
    foreach ($requiredFields as $field) {
        if (!isset($data[$field])) {
            http_response_code(400);
            echo json_encode(['error' => "Missing required field: $field"]);
            return;
        }
    }
    
    try {
        $stmt = $pdo->prepare("UPDATE songs SET title = ?, key_signature = ?, tempo = ?, time_signature = ?, song_data = ?, artist = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ? AND workspace_id = ?");
        $stmt->execute([
            $data['title'],
            $data['key_signature'],
            $data['tempo'],
            $data['time_signature'],
            json_encode($data['song_data']),
            $data['artist'],
            $data['id'],
            $workspaceId
        ]);
        
        if ($stmt->rowCount() > 0) {
            echo json_encode(['message' => 'Song updated successfully']);
        } else {
            http_response_code(404);
            echo json_encode(['error' => 'Song not found or no changes made']);
        }
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Database error: ' . $e->getMessage()]);
    }
}

function handleDeleteRequest() {
    global $pdo, $workspaceId;
    
    $data = json_decode(file_get_contents('php://input'), true);
    $id = $_GET['id'] ?? $data['id'] ?? null;
    
    if (!$id) {
        http_response_code(400);
        echo json_encode(['error' => 'Missing song ID']);
        return;
    }
    
    try {
        $stmt = $pdo->prepare("DELETE FROM songs WHERE id = ? AND workspace_id = ?");
        $stmt->execute([$id, $workspaceId]);
        
        if ($stmt->rowCount() > 0) {
            echo json_encode(['message' => 'Song deleted successfully']);
        } else {
            http_response_code(404);
            echo json_encode(['error' => 'Song not found']);
        }
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Database error: ' . $e->getMessage()]);
    }
}