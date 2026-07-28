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
        if (isset($_GET['action']) && $_GET['action'] === 'catalog') {
            handleGetRequestCatalog();
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
        handlePutRequest();
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
// FUNCIONES DE MANEJO DE PETICIONES (GET)
// ------------------------------------------------------------------

function handleGetRequestSetList() {
    global $pdo, $workspaceId;

    // 1. SI SE CONSULTA UNA CANCIÓN ESPECÍFICA (?id=X) - SIN LYRICS AQUÍ
    if (isset($_GET['id'])) {
        $stmt = $pdo->prepare("
            SELECT s.id, s.title, s.artist, s.key_signature, s.tempo, s.time_signature, s.song_data, s.workspace_id
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

    // 2. CONSULTA SETLISTS QUE TENGAN AL MENOS 1 CANCIÓN
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

    // 3. TRAER LAS CANCIONES PERTENECIENTES A LOS SETLISTS (SIN LYRICS)
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

    echo json_encode(['setlists' => array_values($setlistsMap)]);
}

function handleGetRequestCatalog() {
    global $pdo, $workspaceId;

    $search = $_GET['search'] ?? '';

    // Si viene un ID específico para editar la canción en el catálogo (SIN LYRICS AQUÍ)
    if (isset($_GET['id'])) {
        $stmt = $pdo->prepare("
            SELECT id, title, artist, key_signature, tempo, time_signature, song_data
            FROM songs
            WHERE workspace_id = ? AND id = ?
        ");
        $stmt->execute([$workspaceId, $_GET['id']]);
        $song = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($song) {
            echo json_encode($song);
        } else {
            http_response_code(404);
            echo json_encode(['error' => 'Canción no encontrada']);
        }
        return;
    }

    if (empty(trim($search))) {
        echo json_encode([]);
        return;
    }

    // Búsqueda general en catálogo (SIN LYRICS)
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

// ------------------------------------------------------------------
// FUNCIONES DE MANEJO DE PETICIONES (POST / CREAR)
// ------------------------------------------------------------------

function handlePostRequest() {
    global $pdo, $workspaceId;
    $data = json_decode(file_get_contents("php://input"), true);

    // Caso A: Añadir canción a un setlist
    if (isset($data['id_setlist']) && isset($data['id_song'])) {
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

        echo json_encode([
            'success' => true,
            'id_set_list_song' => $pdo->lastInsertId(),
            'display_order' => $nextOrder,
            'message' => 'Canción añadida al setlist'
        ]);
        return;
    }

    // Caso B: Crear una nueva canción en la tabla `songs` (IGNORA COMPLETAMENTE LYRICS)
    if (isset($data['title'])) {
        $title = trim($data['title']);
        $artist = trim($data['artist'] ?? '');
        $keySignature = trim($data['key_signature'] ?? '');
        $tempo = trim($data['tempo'] ?? '');
        $timeSignature = trim($data['time_signature'] ?? '');
        $songData = isset($data['song_data']) ? json_encode($data['song_data']) : null;

        $stmt = $pdo->prepare("
            INSERT INTO songs (workspace_id, title, artist, key_signature, tempo, time_signature, song_data)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([$workspaceId, $title, $artist, $keySignature, $tempo, $timeSignature, $songData]);

        echo json_encode([
            'success' => true,
            'id' => $pdo->lastInsertId(),
            'message' => 'Canción creada exitosamente'
        ]);
        return;
    }

    http_response_code(400);
    echo json_encode(['error' => 'Parámetros insuficientes para la creación']);
}

// ------------------------------------------------------------------
// FUNCIONES DE MANEJO DE PETICIONES (PUT / EDITAR)
// ------------------------------------------------------------------

function handlePutRequest() {
    global $pdo, $workspaceId;
    $data = json_decode(file_get_contents("php://input"), true);

    // Caso A: Actualizar una canción existente por su ID (IGNORA COMPLETAMENTE LYRICS)
    if (isset($data['id'])) {
        $songId = $data['id'];

        $stmtCheck = $pdo->prepare("SELECT id FROM songs WHERE id = ? AND workspace_id = ?");
        $stmtCheck->execute([$songId, $workspaceId]);
        if (!$stmtCheck->fetch()) {
            http_response_code(403);
            echo json_encode(['error' => 'No autorizado o canción no encontrada']);
            return;
        }

        $title = trim($data['title'] ?? '');
        $artist = trim($data['artist'] ?? '');
        $keySignature = trim($data['key_signature'] ?? '');
        $tempo = trim($data['tempo'] ?? '');
        $timeSignature = trim($data['time_signature'] ?? '');
        $songData = isset($data['song_data']) ? json_encode($data['song_data']) : null;

        $stmtUpd = $pdo->prepare("
            UPDATE songs 
            SET title = ?, artist = ?, key_signature = ?, tempo = ?, time_signature = ?, song_data = ?
            WHERE id = ? AND workspace_id = ?
        ");
        $stmtUpd->execute([$title, $artist, $keySignature, $tempo, $timeSignature, $songData, $songId, $workspaceId]);

        echo json_encode(['success' => true, 'message' => 'Canción actualizada correctamente']);
        return;
    }

    // Caso B: Actualizar nombre o relaciones de un Setlist
    if (isset($data['id_setlist'])) {
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
    }

    http_response_code(400);
    echo json_encode(['error' => 'Datos inválidos para la actualización']);
}

// ------------------------------------------------------------------
// FUNCIONES DE MANEJO DE PETICIONES (DELETE)
// ------------------------------------------------------------------

function handleDeleteRequest() {
    global $pdo, $workspaceId;

    if (isset($_GET['song_id'])) {
        $songId = $_GET['song_id'];
        $stmtCheck = $pdo->prepare("SELECT id FROM songs WHERE id = ? AND workspace_id = ?");
        $stmtCheck->execute([$songId, $workspaceId]);
        if (!$stmtCheck->fetch()) {
            http_response_code(403);
            echo json_encode(['error' => 'No autorizado']);
            return;
        }

        $stmt = $pdo->prepare("DELETE FROM songs WHERE id = ? AND workspace_id = ?");
        $stmt->execute([$songId, $workspaceId]);
        echo json_encode(['success' => true, 'message' => 'Canción eliminada del catálogo']);
        return;
    }

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