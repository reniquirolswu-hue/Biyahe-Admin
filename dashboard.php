<?php
// dashboard.php
session_start();
require_once __DIR__ . '/config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(["success" => false, "message" => "Method not allowed."]);
    exit();
}

if (empty($_SESSION['admin_id'])) {
    http_response_code(401);
    echo json_encode(["success" => false, "message" => "Not authenticated."]);
    exit();
}

try {
    // Core stats
    $totalUsers     = (int) $conn->query('SELECT COUNT(*) FROM users')->fetchColumn();
    $totalAdmins    = (int) $conn->query('SELECT COUNT(*) FROM admins')->fetchColumn();
    $totalTerminals = (int) $conn->query('SELECT COUNT(*) FROM terminals')->fetchColumn();
    $totalLandmarks = (int) $conn->query('SELECT COUNT(*) FROM landmarks')->fetchColumn();

    $routeStmt = $conn->query('SELECT is_active, COUNT(*) AS cnt FROM routes GROUP BY is_active');
    $activeRoutes = 0;
    $inactiveRoutes = 0;
    foreach ($routeStmt->fetchAll() as $row) {
        if ($row['is_active']) {
            $activeRoutes = (int) $row['cnt'];
        } else {
            $inactiveRoutes = (int) $row['cnt'];
        }
    }
    $totalRoutes = $activeRoutes + $inactiveRoutes;

    // Top terminal hubs
    $hubStmt = $conn->query("
        SELECT t.terminal_id,
               t.terminal_name,
               COUNT(r.route_id) AS route_count
        FROM terminals t
        LEFT JOIN routes r
          ON r.origin_terminal_id = t.terminal_id
          OR r.destination_terminal_id = t.terminal_id
        GROUP BY t.terminal_id, t.terminal_name
        ORDER BY route_count DESC, t.terminal_name ASC
        LIMIT 6
    ");
    $hubs = array_map(function ($row) {
        return [
            "terminal_id" => (int) $row['terminal_id'],
            "name"        => htmlspecialchars($row['terminal_name'], ENT_QUOTES, 'UTF-8'),
            "route_count" => (int) $row['route_count'],
        ];
    }, $hubStmt->fetchAll());

    // GeoJSON: terminals
    $terminalStmt = $conn->query('SELECT terminal_id, terminal_name, latitude, longitude FROM terminals');
    $terminalFeatures = [];
    foreach ($terminalStmt->fetchAll() as $row) {
        if ($row['latitude'] === null || $row['longitude'] === null) {
            continue;
        }
        $terminalFeatures[] = [
            "type" => "Feature",
            "geometry" => [
                "type" => "Point",
                "coordinates" => [(float) $row['longitude'], (float) $row['latitude']]
            ],
            "properties" => [
                "id"   => (int) $row['terminal_id'],
                "name" => htmlspecialchars($row['terminal_name'], ENT_QUOTES, 'UTF-8'),
            ]
        ];
    }

    // GeoJSON: landmarks
    $landmarkStmt = $conn->query('SELECT landmark_id, landmark_name, latitude, longitude FROM landmarks');
    $landmarkFeatures = [];
    foreach ($landmarkStmt->fetchAll() as $row) {
        if ($row['latitude'] === null || $row['longitude'] === null) {
            continue;
        }
        $landmarkFeatures[] = [
            "type" => "Feature",
            "geometry" => [
                "type" => "Point",
                "coordinates" => [(float) $row['longitude'], (float) $row['latitude']]
            ],
            "properties" => [
                "id"   => (int) $row['landmark_id'],
                "name" => htmlspecialchars($row['landmark_name'], ENT_QUOTES, 'UTF-8'),
            ]
        ];
    }

    echo json_encode([
        "success" => true,
        "stats" => [
            "total_users"     => $totalUsers,
            "total_admins"    => $totalAdmins,
            "total_people"    => $totalUsers + $totalAdmins,
            "total_routes"    => $totalRoutes,
            "active_routes"   => $activeRoutes,
            "inactive_routes" => $inactiveRoutes,
            "total_terminals" => $totalTerminals,
            "total_landmarks" => $totalLandmarks,
        ],
        "hubs" => $hubs,
        "map" => [
            "terminals" => ["type" => "FeatureCollection", "features" => $terminalFeatures],
            "landmarks" => ["type" => "FeatureCollection", "features" => $landmarkFeatures],
        ],
    ]);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(["success" => false, "message" => "Server error while fetching dashboard data."]);
}