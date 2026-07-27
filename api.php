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
        $stmt = $pdo->prepare("SELECT id, title, key_signature, tempo, time_signature, artist FROM songs WHERE workspace_id = ? AND (title LIKE ? OR artist LIKE ?)");
        $stmt->execute([$workspaceId, $searchTerm, $searchTerm]);
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
function handleGetRequestSetList() {
    global $pdo, $workspaceId;

    // ------------------------------------------------------------------
    // 1. SI SE CONSULTA UNA CANCIÓN ESPECÍFICA (?id=X)
    // ------------------------------------------------------------------
    if (isset($_GET['id'])) {
        $stmt = $pdo->prepare("
            SELECT s.*
            FROM songs s
            WHERE s.workspace_id = ? AND s.id = ?
        ");
        $stmt->execute([$workspaceId, $_GET['id']]);
        $song = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($song) {
            if (!empty($song['song_data'])) {
                $song['song_data'] = json_decode($song['song_data'], true);
            }
            echo json_encode($song);
        } else {
            http_response_code(404);
            echo json_encode(['error' => 'Song not found']);
        }
        return;
    }

    // ------------------------------------------------------------------
    // 2. CONSULTA 1: Obtener solo setlists que TENGAN al menos 1 canción
    // ------------------------------------------------------------------
    $stmtSetlists = $pdo->prepare("
        SELECT 
            sl.id,
            sl.setlist_name,
            sl.display_order
        FROM set_lists sl
        WHERE sl.workspace_id = ?
          AND EXISTS (
              SELECT 1 
              FROM set_list_songs sls 
              WHERE sls.id_setlist = sl.id
          )
        ORDER BY sl.display_order ASC
    ");
    $stmtSetlists->execute([$workspaceId]);
    $setlists = $stmtSetlists->fetchAll(PDO::FETCH_ASSOC);

    // Si no hay ningún setlist con canciones, devolvemos un array vacío
    if (empty($setlists)) {
        echo json_encode(['setlists' => []]);
        return;
    }

    // Indexar por ID en PHP e inicializar el array de canciones
    $setlistsMap = [];
    foreach ($setlists as $sl) {
        $setlistsMap[$sl['id']] = [
            'id' => $sl['id'],
            'setlist_name' => $sl['setlist_name'],
            'display_order' => $sl['display_order'],
            'songs' => []
        ];
    }

    // Obtener las claves/IDs de los setlists encontrados
    $setlistIds = array_keys($setlistsMap);
    $inClause = implode(',', array_fill(0, count($setlistIds), '?'));

    // ------------------------------------------------------------------
    // 3. CONSULTA 2: Traer las canciones pertenecientes a esos setlists
    // ------------------------------------------------------------------
    $stmtSongs = $pdo->prepare("
        SELECT 
            sls.id_setlist,
            s.id AS song_id,
            s.title,
            s.artist,
            s.key_signature,
            s.tempo,
            s.time_signature
        FROM set_list_songs sls
        INNER JOIN songs s ON s.id = sls.id_song
        WHERE sls.id_setlist IN ($inClause)
        ORDER BY sls.id ASC
    ");
    $stmtSongs->execute($setlistIds);
    $songsRows = $stmtSongs->fetchAll(PDO::FETCH_ASSOC);

    // ------------------------------------------------------------------
    // 4. AGRUPACIÓN Y CONSTRUCCIÓN DEL JSON FINAL
    // ------------------------------------------------------------------
    foreach ($songsRows as $row) {
        $setId = $row['id_setlist'];
        if (isset($setlistsMap[$setId])) {
            $setlistsMap[$setId]['songs'][] = [
                'id' => $row['song_id'],
                'title' => $row['title'],
                'artist' => $row['artist'],
                'key_signature' => $row['key_signature'],
                'tempo' => $row['tempo'],
                'time_signature' => $row['time_signature']
            ];
        }
    }

    $response = [
        'setlists' => array_values($setlistsMap)
    ];

    echo json_encode($response);
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