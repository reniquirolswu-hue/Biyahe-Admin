<?php
// admin/api/routes.php
ob_start();

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Credentials: true");
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
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

// Strictly require Admin Authentication
if (empty($_SESSION['admin_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Admin authentication required.']);
    exit();
}

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    handleGet($conn);
    exit();
}

if ($method === 'POST') {
    handleCreate($conn);
    exit();
}

if ($method === 'PUT') {
    handleUpdate($conn);
    exit();
}

if ($method === 'DELETE') {
    handleDelete($conn);
    exit();
}

http_response_code(405);
echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
exit();

// ---------------------------------------------------------------------------

function handleGet($conn) {
    $id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);

    try {
        if ($id) {
            $stmt = $conn->prepare(
                'SELECT r.route_id, r.route_code, r.vehicle_type, r.is_active,
                        r.origin_terminal_id, r.destination_terminal_id,
                        ot.terminal_name AS origin_name, dt.terminal_name AS destination_name
                 FROM routes r
                 JOIN terminals ot ON ot.terminal_id = r.origin_terminal_id
                 JOIN terminals dt ON dt.terminal_id = r.destination_terminal_id
                 WHERE r.route_id = :id'
            );
            $stmt->execute([':id' => $id]);
            $route = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$route) {
                http_response_code(404);
                echo json_encode(['success' => false, 'message' => 'Route not found.']);
                return;
            }

            $wpStmt = $conn->prepare(
                'SELECT sequence_no, latitude, longitude
                 FROM waypoints
                 WHERE route_id = :id
                 ORDER BY sequence_no ASC'
            );
            $wpStmt->execute([':id' => $id]);
            $waypoints = $wpStmt->fetchAll(PDO::FETCH_ASSOC);

            $route['route_id']                = (int) $route['route_id'];
            $route['route_code']             = htmlspecialchars((string)($route['route_code'] ?? ''), ENT_QUOTES, 'UTF-8');
            $route['origin_terminal_id']      = (int) $route['origin_terminal_id'];
            $route['destination_terminal_id'] = (int) $route['destination_terminal_id'];
            $route['is_active']               = (bool) $route['is_active'];
            $route['waypoints']               = array_map(function ($wp) {
                return [
                    'sequence_no' => (int) $wp['sequence_no'],
                    'latitude'    => (float) $wp['latitude'],
                    'longitude'   => (float) $wp['longitude'],
                ];
            }, $waypoints);

            echo json_encode($route);
            return;
        }

        $stmt = $conn->query(
            'SELECT r.route_id, r.route_code, r.vehicle_type, r.is_active,
                    r.origin_terminal_id, r.destination_terminal_id,
                    ot.terminal_name AS origin_name, dt.terminal_name AS destination_name
             FROM routes r
             JOIN terminals ot ON ot.terminal_id = r.origin_terminal_id
             JOIN terminals dt ON dt.terminal_id = r.destination_terminal_id
             ORDER BY r.route_code ASC'
        );
        $routes = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $cleanedRoutes = [];
        foreach ($routes as $r) {
            $cleanedRoutes[] = [
                'route_id'                => (int) $r['route_id'],
                'route_code'             => htmlspecialchars((string)($r['route_code'] ?? ''), ENT_QUOTES, 'UTF-8'),
                'vehicle_type'           => $r['vehicle_type'],
                'origin_terminal_id'      => (int) $r['origin_terminal_id'],
                'destination_terminal_id' => (int) $r['destination_terminal_id'],
                'origin_name'             => htmlspecialchars((string)($r['origin_name'] ?? ''), ENT_QUOTES, 'UTF-8'),
                'destination_name'        => htmlspecialchars((string)($r['destination_name'] ?? ''), ENT_QUOTES, 'UTF-8'),
                'is_active'               => (bool) $r['is_active'],
            ];
        }

        echo json_encode($cleanedRoutes);
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Failed to fetch routes.']);
    }
}

function validateRoutePayload($input) {
    $errors = [];
    $routeCode   = isset($input['route_code']) ? (function_exists('sanitize_string') ? sanitize_string((string)$input['route_code']) : trim((string)$input['route_code'])) : '';
    $vehicleType = $input['vehicle_type'] ?? null;
    $originId    = filter_var($input['origin_terminal_id'] ?? null, FILTER_VALIDATE_INT);
    $destId      = filter_var($input['destination_terminal_id'] ?? null, FILTER_VALIDATE_INT);
    $waypoints   = $input['waypoints'] ?? [];

    if ($routeCode === '') {
        $errors[] = 'route_code is required.';
    }
    if (!in_array($vehicleType, ['Traditional', 'Modern'], true)) {
        $errors[] = 'vehicle_type must be "Traditional" or "Modern".';
    }
    if (!$originId) {
        $errors[] = 'origin_terminal_id must be a valid integer.';
    }
    if (!$destId) {
        $errors[] = 'destination_terminal_id must be a valid integer.';
    }
    if ($originId && $destId && $originId === $destId) {
        $errors[] = 'Origin and destination terminals must differ.';
    }
    if (!is_array($waypoints) || count($waypoints) === 0) {
        $errors[] = 'waypoints must be a non-empty array.';
    }

    return $errors;
}

function insertWaypoints($conn, $routeId, array $waypoints) {
    $waypointStmt = $conn->prepare(
        'INSERT INTO waypoints (route_id, sequence_no, latitude, longitude)
         VALUES (:route_id, :sequence_no, :latitude, :longitude)'
    );
    foreach ($waypoints as $wp) {
        $waypointStmt->execute([
            ':route_id'    => (int) $routeId,
            ':sequence_no' => (int) ($wp['sequence_no'] ?? 0),
            ':latitude'    => (float) ($wp['latitude'] ?? 0.0),
            ':longitude'   => (float) ($wp['longitude'] ?? 0.0),
        ]);
    }
}

function handleCreate($conn) {
    $input = json_decode(file_get_contents('php://input'), true) ?? [];
    $errors = validateRoutePayload($input);

    if (!empty($errors)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => implode(' ', $errors)]);
        return;
    }

    $createdBy   = $_SESSION['admin_id'];
    $routeCode   = function_exists('sanitize_string') ? sanitize_string((string)$input['route_code']) : trim((string)$input['route_code']);
    $vehicleType = $input['vehicle_type'];
    $originId    = (int) $input['origin_terminal_id'];
    $destId      = (int) $input['destination_terminal_id'];
    $isActive    = isset($input['is_active']) ? filter_var($input['is_active'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? true : true;
    $waypoints   = $input['waypoints'];

    try {
        $conn->beginTransaction();

        $routeStmt = $conn->prepare(
            'INSERT INTO routes (created_by, origin_terminal_id, destination_terminal_id, route_code, vehicle_type, is_active)
             VALUES (:created_by, :origin_id, :dest_id, :route_code, :vehicle_type, :is_active)
             RETURNING route_id'
        );
        $routeStmt->execute([
            ':created_by'   => $createdBy,
            ':origin_id'    => $originId,
            ':dest_id'      => $destId,
            ':route_code'   => $routeCode,
            ':vehicle_type' => $vehicleType,
            ':is_active'    => $isActive ? 'true' : 'false',
        ]);
        $routeId = $routeStmt->fetchColumn();

        insertWaypoints($conn, $routeId, $waypoints);

        $conn->commit();

        http_response_code(201);
        echo json_encode(['success' => true, 'route_id' => (int) $routeId]);
    } catch (PDOException $e) {
        if ($conn->inTransaction()) {
            $conn->rollBack();
        }
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Failed to save route.']);
    }
}

function handleUpdate($conn) {
    $routeId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);

    if (!$routeId) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Valid route id is required (?id=).']);
        return;
    }

    $input = json_decode(file_get_contents('php://input'), true) ?? [];
    $errors = validateRoutePayload($input);

    if (!empty($errors)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => implode(' ', $errors)]);
        return;
    }

    $routeCode   = function_exists('sanitize_string') ? sanitize_string((string)$input['route_code']) : trim((string)$input['route_code']);
    $vehicleType = $input['vehicle_type'];
    $originId    = (int) $input['origin_terminal_id'];
    $destId      = (int) $input['destination_terminal_id'];
    $isActive    = isset($input['is_active']) ? filter_var($input['is_active'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? true : true;
    $waypoints   = $input['waypoints'];

    try {
        $conn->beginTransaction();

        $updateStmt = $conn->prepare(
            'UPDATE routes
             SET origin_terminal_id = :origin_id,
                 destination_terminal_id = :dest_id,
                 route_code = :route_code,
                 vehicle_type = :vehicle_type,
                 is_active = :is_active
             WHERE route_id = :route_id'
        );
        $updateStmt->execute([
            ':origin_id'    => $originId,
            ':dest_id'      => $destId,
            ':route_code'   => $routeCode,
            ':vehicle_type' => $vehicleType,
            ':is_active'    => $isActive ? 'true' : 'false',
            ':route_id'     => $routeId,
        ]);

        $delWpStmt = $conn->prepare('DELETE FROM waypoints WHERE route_id = :route_id');
        $delWpStmt->execute([':route_id' => $routeId]);

        insertWaypoints($conn, $routeId, $waypoints);

        $conn->commit();

        echo json_encode(['success' => true, 'route_id' => $routeId]);
    } catch (PDOException $e) {
        if ($conn->inTransaction()) {
            $conn->rollBack();
        }
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Failed to update route.']);
    }
}

function handleDelete($conn) {
    $routeId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);

    if (!$routeId) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Valid route id is required (?id=).']);
        return;
    }

    try {
        $conn->beginTransaction();

        $delWpStmt = $conn->prepare('DELETE FROM waypoints WHERE route_id = :route_id');
        $delWpStmt->execute([':route_id' => $routeId]);

        $delRouteStmt = $conn->prepare('DELETE FROM routes WHERE route_id = :route_id');
        $delRouteStmt->execute([':route_id' => $routeId]);

        $conn->commit();

        echo json_encode([
            'success' => true,
            'message' => 'Route and its waypoints were successfully deleted.',
            'deleted_route_id' => $routeId
        ]);
    } catch (PDOException $e) {
        if ($conn->inTransaction()) {
            $conn->rollBack();
        }
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Failed to delete route and its waypoints.']);
    }
}
?>