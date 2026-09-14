<?php
// admin/api/terminals.php
ob_start();

// Correct relative path to config.php from admin/api/
require_once __DIR__ . '/../../config.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

ob_clean();
header('Content-Type: application/json; charset=utf-8');

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
        $terminals = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        foreach ($terminals as &$t) {
            $t['terminal_id']   = (int) $t['terminal_id'];
            $t['terminal_name'] = htmlspecialchars((string)($t['terminal_name'] ?? ''), ENT_QUOTES, 'UTF-8');
            $t['latitude']      = $t['latitude']  !== null ? (float) $t['latitude']  : null;
            $t['longitude']     = $t['longitude'] !== null ? (float) $t['longitude'] : null;
        }
        unset($t);

        http_response_code(200);
        echo json_encode($terminals);
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Failed to fetch terminals.']);
    }
    exit();
}

if ($method === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true) ?? [];

    $rawName   = $input['terminal_name'] ?? '';
    $name      = function_exists('sanitize_string') ? sanitize_string((string)$rawName) : trim((string)$rawName);
    
    $latitude  = filter_var($input['latitude'] ?? null, FILTER_VALIDATE_FLOAT);
    $longitude = filter_var($input['longitude'] ?? null, FILTER_VALIDATE_FLOAT);

    // Validation
    if ($name === '' || $latitude === false || $longitude === false) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Valid terminal_name, latitude, and longitude are required.']);
        exit();
    }

    if ($latitude < -90.0 || $latitude > 90.0 || $longitude < -180.0 || $longitude > 180.0) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Latitude must be between -90 and 90, and Longitude between -180 and 180.']);
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
            ':lat'  => (float) $latitude,
            ':lng'  => (float) $longitude,
        ]);
        
        $terminal = $stmt->fetch(PDO::FETCH_ASSOC);

        $terminal['terminal_id']   = (int) $terminal['terminal_id'];
        $terminal['terminal_name'] = htmlspecialchars((string)$terminal['terminal_name'], ENT_QUOTES, 'UTF-8');
        $terminal['latitude']      = (float) $terminal['latitude'];
        $terminal['longitude']     = (float) $terminal['longitude'];

        http_response_code(201);
        echo json_encode($terminal);
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Failed to create terminal.']);
    }
    exit();
}

http_response_code(405);
echo json_encode(['success' => false, 'message' => 'Method not allowed.']);