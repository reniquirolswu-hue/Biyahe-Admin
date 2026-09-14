<?php
// admin/api/routes.php
// GET    -> list all routes, or a single route + its waypoints via ?id=
// POST   -> create a route AND its waypoints together
// PUT    -> update a route AND replace its waypoints (?id= required)
// (route table has no geometry column; the drawn line is stored as ordered waypoint rows)

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

http_response_code(405);
echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
exit();

// ---------------------------------------------------------------------------

function handleGet($conn) {
    $id = $_GET['id'] ?? null;

    try {
        if ($id) {
            // Single route, including its ordered waypoints — used to populate
            // the editor's form/map when the admin clicks a route to edit it.
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

            $route['route_id'] = (int) $route['route_id'];
            $route['origin_terminal_id'] = (int) $route['origin_terminal_id'];
            $route['destination_terminal_id'] = (int) $route['destination_terminal_id'];
            $route['is_active'] = (bool) $route['is_active'];
            $route['waypoints'] = array_map(function ($wp) {
                return [
                    'sequence_no' => (int) $wp['sequence_no'],
                    'latitude'    => (float) $wp['latitude'],
                    'longitude'   => (float) $wp['longitude'],
                ];
            }, $waypoints);

            http_response_code(200);
            echo json_encode($route);
            return;
        }

        // Full list — used to render the Route Management list from the
        // database instead of static markup.
        $stmt = $conn->query(
            'SELECT r.route_id, r.route_code, r.vehicle_type, r.is_active,
                    r.origin_terminal_id, r.destination_terminal_id,
                    ot.terminal_name AS origin_name, dt.terminal_name AS destination_name
             FROM routes r
             JOIN terminals ot ON ot.terminal_id = r.origin_terminal_id
             JOIN terminals dt ON dt.terminal_id = r.destination_terminal_id
             ORDER BY r.route_code ASC'
        );
        $routes = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($routes as &$r) {
            $r['route_id'] = (int) $r['route_id'];
            $r['origin_terminal_id'] = (int) $r['origin_terminal_id'];
            $r['destination_terminal_id'] = (int) $r['destination_terminal_id'];
            $r['is_active'] = (bool) $r['is_active'];
        }
        unset($r);

        http_response_code(200);
        echo json_encode($routes);
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Failed to fetch routes: ' . $e->getMessage()]);
    }
}

// Shared validation for POST (create) and PUT (update) — same shape of payload.
function validateRoutePayload($input) {
    $errors = [];
    $routeCode   = trim($input['route_code'] ?? '');
    $vehicleType = $input['vehicle_type'] ?? null;
    $originId    = $input['origin_terminal_id'] ?? null;
    $destId      = $input['destination_terminal_id'] ?? null;
    $waypoints   = $input['waypoints'] ?? [];

    if ($routeCode === '')                                        $errors[] = 'route_code is required.';
    if (!in_array($vehicleType, ['Traditional', 'Modern'], true))  $errors[] = 'vehicle_type must be "Traditional" or "Modern".';
    if (!$originId)                                                $errors[] = 'origin_terminal_id is required.';
    if (!$destId)                                                  $errors[] = 'destination_terminal_id is required.';
    if ($originId && $destId && $originId == $destId)              $errors[] = 'Origin and destination terminals must differ.';
    if (!is_array($waypoints) || count($waypoints) === 0)          $errors[] = 'waypoints must be a non-empty array.';

    return $errors;
}

function insertWaypoints($conn, $routeId, $waypoints) {
    $waypointStmt = $conn->prepare(
        'INSERT INTO waypoints (route_id, sequence_no, latitude, longitude)
         VALUES (:route_id, :sequence_no, :latitude, :longitude)'
    );
    foreach ($waypoints as $wp) {
        $waypointStmt->execute([
            ':route_id'    => $routeId,
            ':sequence_no' => $wp['sequence_no'],
            ':latitude'    => $wp['latitude'],
            ':longitude'   => $wp['longitude'],
        ]);
    }
}

function handleCreate($conn) {
    $input = json_decode(file_get_contents('php://input'), true);
    $errors = validateRoutePayload($input);

    if (!empty($errors)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => implode(' ', $errors)]);
        return;
    }

    // created_by comes from the logged-in admin's session, never from the client —
    // admin_login.php sets $_SESSION['admin_id'] on a successful login.
    $createdBy   = $_SESSION['admin_id'];
    $routeCode   = trim($input['route_code']);
    $vehicleType = $input['vehicle_type'];
    $originId    = $input['origin_terminal_id'];
    $destId      = $input['destination_terminal_id'];
    $isActive    = array_key_exists('is_active', $input) ? (bool) $input['is_active'] : true;
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
            ':is_active'    => $isActive,
        ]);
        $routeId = $routeStmt->fetchColumn();

        insertWaypoints($conn, $routeId, $waypoints);

        $conn->commit();

        http_response_code(201);
        echo json_encode(['success' => true, 'route_id' => (int) $routeId]);
    } catch (PDOException $e) {
        $conn->rollBack();
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Failed to save route: ' . $e->getMessage()]);
    }
}

function handleUpdate($conn) {
    $routeId = $_GET['id'] ?? null;

    if (!$routeId) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Route id is required (?id=).']);
        return;
    }

    $input = json_decode(file_get_contents('php://input'), true);
    $errors = validateRoutePayload($input);

    if (!empty($errors)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => implode(' ', $errors)]);
        return;
    }

    $routeCode   = trim($input['route_code']);
    $vehicleType = $input['vehicle_type'];
    $originId    = $input['origin_terminal_id'];
    $destId      = $input['destination_terminal_id'];
    $isActive    = array_key_exists('is_active', $input) ? (bool) $input['is_active'] : true;
    $waypoints   = $input['waypoints'];

    try {
        $conn->beginTransaction();

        $updateStmt = $conn->prepare(
            'UPDATE routes
             SET route_code = :route_code,
                 vehicle_type = :vehicle_type,
                 origin_terminal_id = :origin_id,
                 destination_terminal_id = :dest_id,
                 is_active = :is_active
             WHERE route_id = :id
             RETURNING route_id'
        );
        $updateStmt->execute([
            ':route_code'   => $routeCode,
            ':vehicle_type' => $vehicleType,
            ':origin_id'    => $originId,
            ':dest_id'      => $destId,
            ':is_active'    => $isActive,
            ':id'           => $routeId,
        ]);

        if (!$updateStmt->fetchColumn()) {
            $conn->rollBack();
            http_response_code(404);
            echo json_encode(['success' => false, 'message' => 'Route not found.']);
            return;
        }

        // Replace waypoints wholesale — simplest way to keep the ordered
        // sequence_no list consistent with whatever the admin re-drew, rather
        // than trying to diff old vs. new point-by-point.
        $deleteStmt = $conn->prepare('DELETE FROM waypoints WHERE route_id = :id');
        $deleteStmt->execute([':id' => $routeId]);

        insertWaypoints($conn, $routeId, $waypoints);

        $conn->commit();

        http_response_code(200);
        echo json_encode(['success' => true, 'route_id' => (int) $routeId]);
    } catch (PDOException $e) {
        $conn->rollBack();
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Failed to update route: ' . $e->getMessage()]);
    }
}