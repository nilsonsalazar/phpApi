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

// Cargar bootstrap y middleware de autenticación desde el directorio raíz
require_once __DIR__ . '/../bootstrap.php';

// Validar usuario autenticado
$currentUser = getCurrentUser($pdo);
if (!$currentUser) {
    http_response_code(401);
    echo json_encode(['error' => 'No autorizado']);
    exit();
}

$workspaceId = $currentUser['workspace_id'];

// Obtener el método HTTP de la petición
$requestMethod = $_SERVER['REQUEST_METHOD'];

// Router según el método HTTP
switch ($requestMethod) {
    case 'GET':
        // Decidimos qué función llamar según el parámetro action
        if (isset($_GET['action']) && $_GET['action'] === 'catalog') {
            handleGetRequestCatalog();
        } elseif (isset($_GET['action']) && $_GET['action'] === 'lyrics') {
            handleGetRequestLyrics();
        } else {
            handleGetRequestSetList();
        }
        break;
    case 'POST':
        requireRole($pdo, ['admin', 'editor']);
        handlePostRequest();
        break;
    case 'PUT':
        requireRole($pdo, ['admin', 'editor']);
        // Verificamos si la acción es actualizar letra o si viene el parámetro action en la URL o body
        $inputData = json_decode(file_get_contents("php://input"), true);
        if ((isset($_GET['action']) && $_GET['action'] === 'updatelyric') || (isset($inputData['action']) && $inputData['action'] === 'updatelyric')) {
            handlePutLyric($inputData);
        } else {
            handlePutRequest($inputData);
        }
        break;
    case 'DELETE':
        requireRole($pdo, ['admin']);
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

/**
 * NUEVO MÉTODO: Traer expresamente el campo lyrics y metadatos básicos de la canción por ID
 */
function handleGetRequestLyrics() {
    global $pdo, $workspaceId;

    $songId = $_GET['id'] ?? null;

    if (!$songId) {
        http_response_code(400);
        echo json_encode(['error' => 'Parámetro id de canción requerido']);
        return;
    }

    $stmt = $pdo->prepare("
        SELECT lyrics
        FROM songs
        WHERE workspace_id = ? AND id = ?
    ");
    $stmt->execute([$workspaceId, $songId]);
    $song = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($song) {
        echo json_encode([
            'success' => true,
            'song' => [
                'lyrics' => $song['lyrics']
            ]
        ]);
    } else {
        http_response_code(404);
        echo json_encode(['error' => 'Canción no encontrada']);
    }
}

/**
 * NUEVO MÉTODO: Actualizar específicamente el campo lyrics de una canción
 */
function handlePutLyric($data) {
    global $pdo, $workspaceId;

    $songId = $data['id'] ?? $_GET['id'] ?? null;
    $lyrics = $data['lyrics'] ?? null;

    if (!$songId) {
        http_response_code(400);
        echo json_encode(['error' => 'Parámetro id de canción requerido']);
        return;
    }

    // Validar que la canción pertenezca al workspace actual
    $stmtCheck = $pdo->prepare("SELECT id FROM songs WHERE id = ? AND workspace_id = ?");
    $stmtCheck->execute([$songId, $workspaceId]);
    if (!$stmtCheck->fetch()) {
        http_response_code(403);
        echo json_encode(['error' => 'No autorizado o canción no encontrada']);
        return;
    }

    $stmtUpd = $pdo->prepare("UPDATE songs SET lyrics = ? WHERE id = ? AND workspace_id = ?");
    $stmtUpd->execute([$lyrics, $songId, $workspaceId]);

    echo json_encode([
        'success' => true,
        'message' => 'Letra de la canción actualizada correctamente'
    ]);
}

function handleGetRequestSetList() {
    global $pdo, $workspaceId;

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

    if (empty($setlists)) {
        echo json_encode(['setlists' => []]);
        return;
    }

    $setlistsMap = [];
    foreach ($setlists as $sl) {
        $setlistsMap[$sl['id']] = [
            'id' => $sl['id'],
            'setlist_name' => $sl['setlist_name'],
            'display_order' => $sl['display_order'],
            'songs' => []
        ];
    }

    $setlistIds = array_keys($setlistsMap);
    $inClause = implode(',', array_fill(0, count($setlistIds), '?'));

    $stmtSongs = $pdo->prepare("
        SELECT 
            sls.id_setlist,
            s.id AS song_id,
            s.title,
            s.artist,
            s.key_signature,
            s.tempo,
            s.time_signature,
            sls.display_order AS display_order,
            sls.id as id_set_list_song
        FROM set_list_songs sls
        INNER JOIN songs s ON s.id = sls.id_song
        WHERE sls.id_setlist IN ($inClause)
        ORDER BY sls.id ASC
    ");
    $stmtSongs->execute($setlistIds);
    $songsRows = $stmtSongs->fetchAll(PDO::FETCH_ASSOC);

    foreach ($songsRows as $row) {
        $setId = $row['id_setlist'];
        if (isset($setlistsMap[$setId])) {
            $setlistsMap[$setId]['songs'][] = [
                'id' => $row['song_id'],
                'title' => $row['title'],
                'artist' => $row['artist'],
                'key_signature' => $row['key_signature'],
                'tempo' => $row['tempo'],
                'time_signature' => $row['time_signature'],
                'id_set_list_song' => $row['id_set_list_song'],
                'display_order' => $row['display_order']
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
    $data = json_decode(file_get_contents("php://input"), true);

    if (!isset($data['id_setlist']) || !isset($data['id_song'])) {
        http_response_code(400);
        echo json_encode(['error' => 'Faltan parámetros id_setlist o id_song']);
        return;
    }

    $idSetlist = $data['id_setlist'];
    $idSong = $data['id_song'];

    $stmtCheck = $pdo->prepare("SELECT id FROM set_lists WHERE id = ? AND workspace_id = ?");
    $stmtCheck->execute([$idSetlist, $workspaceId]);
    if (!$stmtCheck->fetch()) {
        http_response_code(403);
        echo json_encode(['error' => 'No autorizado']);
        return;
    }

    $stmtOrder = $pdo->prepare("SELECT COALESCE(MAX(display_order), 0) + 1 FROM set_list_songs WHERE id_setlist = ?");
    $stmtOrder->execute([$idSetlist]);
    $nextOrder = $stmtOrder->fetchColumn();

    $stmtInsert = $pdo->prepare("INSERT INTO set_list_songs (id_setlist, id_song, display_order) VALUES (?, ?, ?)");
    $stmtInsert->execute([$idSetlist, $idSong, $nextOrder]);

    $newId = $pdo->lastInsertId();

    echo json_encode([
        'success' => true,
        'id_set_list_song' => $newId,
        'display_order' => $nextOrder,
        'message' => 'Canción añadida al setlist'
    ]);
}

function handlePutRequest($data) {
    global $pdo, $workspaceId;

    if (!isset($data['id_setlist'])) {
        http_response_code(400);
        echo json_encode(['error' => 'Falta el id_setlist']);
        return;
    }

    $idSetlist = $data['id_setlist'];

    $stmtCheck = $pdo->prepare("SELECT id FROM set_lists WHERE id = ? AND workspace_id = ?");
    $stmtCheck->execute([$idSetlist, $workspaceId]);
    if (!$stmtCheck->fetch()) {
        http_response_code(403);
        echo json_encode(['error' => 'No autorizado o setlist no encontrado']);
        return;
    }

    if (isset($data['setlist_name'])) {
        $newName = trim($data['setlist_name']);
        $stmtUpd = $pdo->prepare("UPDATE set_lists SET setlist_name = ? WHERE id = ? AND workspace_id = ?");
        $stmtUpd->execute([$newName, $idSetlist, $workspaceId]);

        echo json_encode(['success' => true, 'message' => 'Nombre del setlist actualizado correctamente']);
        return;
    }

    if (isset($data['songs']) && is_array($data['songs'])) {
        $stmtDel = $pdo->prepare("DELETE FROM set_list_songs WHERE id_setlist = ?");
        $stmtDel->execute([$idSetlist]);

        $stmtIns = $pdo->prepare("INSERT INTO set_list_songs (id_setlist, id_song, display_order) VALUES (?, ?, ?)");
        foreach ($data['songs'] as $index => $song) {
            $stmtIns->execute([$idSetlist, $song['id'], $index + 1]);
        }

        echo json_encode(['success' => true, 'message' => 'Setlist actualizado correctamente']);
        return;
    }

    http_response_code(400);
    echo json_encode(['error' => 'Datos inválidos para la actualización']);
}

function handleDeleteRequest() {
    global $pdo, $workspaceId;

    $idSetlist = $_GET['id_setlist'] ?? null;
    $idSetListSong = $_GET['id_set_list_song'] ?? null;

    if (!$idSetlist) {
        http_response_code(400);
        echo json_encode(['error' => 'Parámetro id_setlist requerido']);
        return;
    }

    $stmtCheck = $pdo->prepare("SELECT id FROM set_lists WHERE id = ? AND workspace_id = ?");
    $stmtCheck->execute([$idSetlist, $workspaceId]);
    if (!$stmtCheck->fetch()) {
        http_response_code(403);
        echo json_encode(['error' => 'No autorizado']);
        return;
    }

    if ($idSetListSong) {
        $stmt = $pdo->prepare("DELETE FROM set_list_songs WHERE id = ? AND id_setlist = ?");
        $stmt->execute([$idSetListSong, $idSetlist]);
        echo json_encode(['success' => true, 'message' => 'Canción eliminada del setlist']);
    } else {
        $stmt = $pdo->prepare("DELETE FROM set_list_songs WHERE id_setlist = ?");
        $stmt->execute([$idSetlist]);
        echo json_encode(['success' => true, 'message' => 'Setlist limpiado correctamente']);
    }
}

function handleGetRequestCatalog() {
    global $pdo, $workspaceId;

    $search = $_GET['search'] ?? '';

    if (empty(trim($search))) {
        echo json_encode([]);
        return;
    }

    $stmt = $pdo->prepare("
        SELECT id, title, artist, key_signature, tempo, time_signature
        FROM songs
        WHERE workspace_id = ? 
          AND (LOWER(title) LIKE ? OR LOWER(artist) LIKE ?)
        ORDER BY title ASC
        LIMIT 50
    ");
    $searchTerm = '%' . strtolower(trim($search)) . '%';
    $stmt->execute([$workspaceId, $searchTerm, $searchTerm]);
    
    $songs = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode($songs);
}