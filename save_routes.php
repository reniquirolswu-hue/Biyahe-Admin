<?php
// admin/api/save_routes.php

ob_start();

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Credentials: true");
header("Access-Control-Allow-Methods: GET, POST, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With, Cookie");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once __DIR__ . '/config.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

ob_clean();
header('Content-Type: application/json; charset=utf-8');

if (empty($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized. Please log in.']);
    exit();
}

$userId = (int) $_SESSION['user_id'];
$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    handleGet($conn, $userId);
    exit();
}

if ($method === 'POST') {
    handleCreate($conn, $userId);
    exit();
}

if ($method === 'DELETE') {
    handleDelete($conn, $userId);
    exit();
}

http_response_code(405);
echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
exit();

// ---------------------------------------------------------------------------

/** GET: list this user's saved routes */
function handleGet($conn, $userId) {
    try {
        // Updated sr.date_created instead of sr.date_saved
        $stmt = $conn->prepare(
            'SELECT r.route_id, r.route_code, r.vehicle_type, r.is_active,
                    r.origin_terminal_id, r.destination_terminal_id,
                    ot.terminal_name AS origin_name, dt.terminal_name AS destination_name,
                    sr.date_created AS date_saved
             FROM saved_routes sr
             JOIN routes r ON r.route_id = sr.route_id
             JOIN terminals ot ON ot.terminal_id = r.origin_terminal_id
             JOIN terminals dt ON dt.terminal_id = r.destination_terminal_id
             WHERE sr.user_id = :user_id
             ORDER BY sr.date_created DESC'
        );
        $stmt->execute([':user_id' => $userId]);
        $rows = $stmt->fetchAll() ?: [];

        $cleaned = [];
        foreach ($rows as $r) {
            $cleaned[] = [
                'route_id'                => (int) $r['route_id'],
                'route_code'              => htmlspecialchars((string)($r['route_code'] ?? ''), ENT_QUOTES, 'UTF-8'),
                'vehicle_type'            => $r['vehicle_type'],
                'origin_terminal_id'      => (int) $r['origin_terminal_id'],
                'destination_terminal_id' => (int) $r['destination_terminal_id'],
                'origin_name'             => htmlspecialchars((string)($r['origin_name'] ?? ''), ENT_QUOTES, 'UTF-8'),
                'destination_name'        => htmlspecialchars((string)($r['destination_name'] ?? ''), ENT_QUOTES, 'UTF-8'),
                'is_active'               => (bool) $r['is_active'],
                'date_saved'              => $r['date_saved'],
            ];
        }

        echo json_encode($cleaned);
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
    }
}

/** POST: save a route */
function handleCreate($conn, $userId) {
    $input   = json_decode(file_get_contents('php://input'), true) ?? [];
    $routeId = filter_var($input['route_id'] ?? null, FILTER_VALIDATE_INT);

    if (!$routeId) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'route_id must be a valid integer.']);
        return;
    }

    try {
        $stmt = $conn->prepare(
            'INSERT INTO saved_routes (user_id, route_id)
             VALUES (:user_id, :route_id)
             ON CONFLICT (user_id, route_id) DO NOTHING
             RETURNING route_id, date_created AS date_saved'
        );
        $stmt->execute([':user_id' => $userId, ':route_id' => $routeId]);
        $row = $stmt->fetch();

        http_response_code(201);
        echo json_encode([
            'success' => true,
            'route_id' => (int) ($row['route_id'] ?? $routeId),
            'date_saved' => $row['date_saved'] ?? date('Y-m-d H:i:s')
        ]);
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
    }
}

/** DELETE: unsave a route */
function handleDelete($conn, $userId) {
    $routeId = filter_input(INPUT_GET, 'route_id', FILTER_VALIDATE_INT);

    if (!$routeId) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Valid route_id parameter required.']);
        return;
    }

    try {
        $stmt = $conn->prepare('DELETE FROM saved_routes WHERE user_id = :user_id AND route_id = :route_id');
        $stmt->execute([':user_id' => $userId, ':route_id' => $routeId]);

        echo json_encode([
            'success' => true,
            'message' => 'Route removed from saved routes.',
            'route_id' => $routeId
        ]);
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
    }
}
?>