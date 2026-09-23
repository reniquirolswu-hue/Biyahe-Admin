<?php
// admin/api/user_routes.php
ob_start();

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Credentials: true");
header("Access-Control-Allow-Methods: GET, OPTIONS");
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

// Require User Session
if (empty($_SESSION['user_id']) && empty($_SESSION['admin_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'User session required. Please log in.']);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Only GET requests are allowed.']);
    exit();
}

$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
$type = filter_input(INPUT_GET, 'type', FILTER_DEFAULT);

try {
    // Option A: Return terminals list/GeoJSON
    if ($type === 'terminals') {
        $terminalStmt = $conn->query('SELECT terminal_id, terminal_name, latitude, longitude FROM terminals ORDER BY terminal_name ASC');
        $terminals = $terminalStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $cleanedTerminals = array_map(function ($row) {
            return [
                'terminal_id'   => (int) $row['terminal_id'],
                'terminal_name' => htmlspecialchars((string)$row['terminal_name'], ENT_QUOTES, 'UTF-8'),
                'latitude'      => $row['latitude'] !== null ? (float) $row['latitude'] : null,
                'longitude'     => $row['longitude'] !== null ? (float) $row['longitude'] : null,
            ];
        }, $terminals);

        echo json_encode(['success' => true, 'terminals' => $cleanedTerminals]);
        exit();
    }

    // Option B: Get a single active route with waypoints
    if ($id) {
        $stmt = $conn->prepare(
            'SELECT r.route_id, r.route_code, r.vehicle_type,
                    r.origin_terminal_id, r.destination_terminal_id,
                    ot.terminal_name AS origin_name, ot.latitude AS origin_lat, ot.longitude AS origin_lng,
                    dt.terminal_name AS destination_name, dt.latitude AS dest_lat, dt.longitude AS dest_lng
             FROM routes r
             JOIN terminals ot ON ot.terminal_id = r.origin_terminal_id
             JOIN terminals dt ON dt.terminal_id = r.destination_terminal_id
             WHERE r.route_id = :id AND r.is_active = true'
        );
        $stmt->execute([':id' => $id]);
        $route = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$route) {
            http_response_code(404);
            echo json_encode(['success' => false, 'message' => 'Active route not found.']);
            exit();
        }

        $wpStmt = $conn->prepare(
            'SELECT sequence_no, latitude, longitude
             FROM waypoints
             WHERE route_id = :id
             ORDER BY sequence_no ASC'
        );
        $wpStmt->execute([':id' => $id]);
        $waypoints = $wpStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        echo json_encode([
            'success'          => true,
            'route_id'         => (int) $route['route_id'],
            'route_code'        => htmlspecialchars((string)($route['route_code'] ?? ''), ENT_QUOTES, 'UTF-8'),
            'vehicle_type'      => $route['vehicle_type'],
            'origin'           => [
                'terminal_id'   => (int) $route['origin_terminal_id'],
                'terminal_name' => htmlspecialchars((string)($route['origin_name'] ?? ''), ENT_QUOTES, 'UTF-8'),
                'latitude'      => $route['origin_lat'] !== null ? (float) $route['origin_lat'] : null,
                'longitude'     => $route['origin_lng'] !== null ? (float) $route['origin_lng'] : null,
            ],
            'destination'      => [
                'terminal_id'   => (int) $route['destination_terminal_id'],
                'terminal_name' => htmlspecialchars((string)($route['destination_name'] ?? ''), ENT_QUOTES, 'UTF-8'),
                'latitude'      => $route['dest_lat'] !== null ? (float) $route['dest_lat'] : null,
                'longitude'     => $route['dest_lng'] !== null ? (float) $route['dest_lng'] : null,
            ],
            'waypoints'        => array_map(function ($wp) {
                return [
                    'sequence_no' => (int) $wp['sequence_no'],
                    'latitude'    => (float) $wp['latitude'],
                    'longitude'   => (float) $wp['longitude'],
                ];
            }, $waypoints)
        ]);
        exit();
    }

    // Option C: Fetch all active routes
    $stmt = $conn->query(
        'SELECT r.route_id, r.route_code, r.vehicle_type,
                r.origin_terminal_id, r.destination_terminal_id,
                ot.terminal_name AS origin_name, dt.terminal_name AS destination_name
         FROM routes r
         JOIN terminals ot ON ot.terminal_id = r.origin_terminal_id
         JOIN terminals dt ON dt.terminal_id = r.destination_terminal_id
         WHERE r.is_active = true
         ORDER BY r.route_code ASC'
    );
    $routes = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $cleanedRoutes = array_map(function ($r) {
        return [
            'route_id'                => (int) $r['route_id'],
            'route_code'             => htmlspecialchars((string)($r['route_code'] ?? ''), ENT_QUOTES, 'UTF-8'),
            'vehicle_type'           => $r['vehicle_type'],
            'origin_terminal_id'      => (int) $r['origin_terminal_id'],
            'destination_terminal_id' => (int) $r['destination_terminal_id'],
            'origin_name'             => htmlspecialchars((string)($r['origin_name'] ?? ''), ENT_QUOTES, 'UTF-8'),
            'destination_name'        => htmlspecialchars((string)($r['destination_name'] ?? ''), ENT_QUOTES, 'UTF-8'),
        ];
    }, $routes);

    echo json_encode(['success' => true, 'routes' => $cleanedRoutes]);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database query failed.']);
}
?>