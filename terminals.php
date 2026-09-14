<?php
// admin/api/terminals.php
// GET  -> list all terminals (for the Origin/Destination dropdowns)
// POST -> create a new terminal (from the "+ New" map-click flow)

require_once __DIR__ . '/config.php';
// config.php already sets Content-Type: application/json and gives us $conn (PDO, Postgres)

session_start();

if (empty($_SESSION['admin_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Not logged in.']);
    exit();
}

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    try {
        $stmt = $conn->query(
            'SELECT terminal_id, terminal_name, latitude, longitude
             FROM terminals
             ORDER BY terminal_name ASC'
        );
        $terminals = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Cast numeric strings to actual numbers so the frontend gets real
        // floats/ints, not strings, from PDO's default string-typed columns.
        foreach ($terminals as &$t) {
            $t['terminal_id'] = (int) $t['terminal_id'];
            $t['latitude']    = $t['latitude']  !== null ? (float) $t['latitude']  : null;
            $t['longitude']   = $t['longitude'] !== null ? (float) $t['longitude'] : null;
        }
        unset($t);

        http_response_code(200);
        echo json_encode($terminals);
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Failed to fetch terminals: ' . $e->getMessage()]);
    }
    exit();
}

if ($method === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);

    $name      = trim($input['terminal_name'] ?? '');
    $latitude  = $input['latitude']  ?? null;
    $longitude = $input['longitude'] ?? null;

    if ($name === '' || $latitude === null || $longitude === null) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'terminal_name, latitude, and longitude are required.']);
        exit();
    }

    try {
        $stmt = $conn->prepare(
            'INSERT INTO terminals (terminal_name, latitude, longitude)
             VALUES (:name, :lat, :lng)
             RETURNING terminal_id, terminal_name, latitude, longitude'
        );
        $stmt->execute([
            ':name' => $name,
            ':lat'  => $latitude,
            ':lng'  => $longitude,
        ]);
        $terminal = $stmt->fetch(PDO::FETCH_ASSOC);
        $terminal['terminal_id'] = (int) $terminal['terminal_id'];
        $terminal['latitude']    = (float) $terminal['latitude'];
        $terminal['longitude']   = (float) $terminal['longitude'];

        http_response_code(201);
        echo json_encode($terminal);
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Failed to create terminal: ' . $e->getMessage()]);
    }
    exit();
}

http_response_code(405);
echo json_encode(['success' => false, 'message' => 'Method not allowed.']);