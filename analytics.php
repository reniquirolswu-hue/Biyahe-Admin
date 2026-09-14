<?php
// analytics.php (project root, alongside routes.php, terminals.php, dashboard.php, config.php)
//
// Returns real analytics computed from the database for admin/analytics.html.
// Requires an authenticated admin session (set by admin_login.php).
//
// Table names used here are PLURAL to match the actual schema:
//   users, admins, terminals, routes, saved_routes, landmarks,
//   route_landmarks, waypoints

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
    // ---------------------------------------------------------------
    // Core counts
    // ---------------------------------------------------------------
    $totalUsers     = (int) $conn->query('SELECT COUNT(*) FROM users')->fetchColumn();
    $totalAdmins    = (int) $conn->query('SELECT COUNT(*) FROM admins')->fetchColumn();
    $totalTerminals = (int) $conn->query('SELECT COUNT(*) FROM terminals')->fetchColumn();
    $totalLandmarks = (int) $conn->query('SELECT COUNT(*) FROM landmarks')->fetchColumn();
    $totalSaved     = (int) $conn->query('SELECT COUNT(*) FROM saved_routes')->fetchColumn();
    $totalWaypoints = (int) $conn->query('SELECT COUNT(*) FROM waypoints')->fetchColumn();

    // ---------------------------------------------------------------
    // Route active / inactive split
    // ---------------------------------------------------------------
    $routeStmt = $conn->query('SELECT is_active, COUNT(*) AS cnt FROM routes GROUP BY is_active');
    $activeRoutes = 0;
    $inactiveRoutes = 0;
    foreach ($routeStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        if ($row['is_active']) {
            $activeRoutes = (int) $row['cnt'];
        } else {
            $inactiveRoutes = (int) $row['cnt'];
        }
    }
    $totalRoutes = $activeRoutes + $inactiveRoutes;
    $activeRoutePct = $totalRoutes > 0 ? round(($activeRoutes / $totalRoutes) * 100, 1) : 0.0;

    // ---------------------------------------------------------------
    // Vehicle type breakdown (Modern vs Traditional)
    // ---------------------------------------------------------------
    $vehicleStmt = $conn->query('SELECT vehicle_type, COUNT(*) AS cnt FROM routes GROUP BY vehicle_type');
    $vehicleBreakdown = ['Modern' => 0, 'Traditional' => 0];
    foreach ($vehicleStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $vehicleBreakdown[$row['vehicle_type']] = (int) $row['cnt'];
    }

    // ---------------------------------------------------------------
    // 7-day new-signup trend (users.date_created)
    // ---------------------------------------------------------------
    $signupStmt = $conn->query("
        SELECT to_char(d.day, 'Dy') AS label,
               to_char(d.day, 'YYYY-MM-DD') AS iso,
               COUNT(u.user_id) AS cnt
        FROM generate_series((CURRENT_DATE - INTERVAL '6 days')::date, CURRENT_DATE::date, INTERVAL '1 day') AS d(day)
        LEFT JOIN users u ON DATE(u.date_created) = d.day
        GROUP BY d.day
        ORDER BY d.day
    ");
    $signupRows = $signupStmt->fetchAll(PDO::FETCH_ASSOC);

    // ---------------------------------------------------------------
    // 7-day route-save trend (saved_routes.date_saved)
    // ---------------------------------------------------------------
    $savedStmt = $conn->query("
        SELECT to_char(d.day, 'Dy') AS label,
               to_char(d.day, 'YYYY-MM-DD') AS iso,
               COUNT(sr.route_id) AS cnt
        FROM generate_series((CURRENT_DATE - INTERVAL '6 days')::date, CURRENT_DATE::date, INTERVAL '1 day') AS d(day)
        LEFT JOIN saved_routes sr ON DATE(sr.date_created) = d.day
        GROUP BY d.day
        ORDER BY d.day
    ");
    $savedRows = $savedStmt->fetchAll(PDO::FETCH_ASSOC);

    $trendLabels  = array_map(fn($r) => $r['label'], $signupRows);
    $signupCounts = array_map(fn($r) => (int) $r['cnt'], $signupRows);
    $savedCounts  = array_map(fn($r) => (int) $r['cnt'], $savedRows);

    echo json_encode([
        "success" => true,
        "stats" => [
            "total_users"        => $totalUsers,
            "total_admins"       => $totalAdmins,
            "total_people"       => $totalUsers + $totalAdmins,
            "total_routes"       => $totalRoutes,
            "active_routes"      => $activeRoutes,
            "inactive_routes"    => $inactiveRoutes,
            "active_route_pct"   => $activeRoutePct,
            "total_terminals"    => $totalTerminals,
            "total_landmarks"    => $totalLandmarks,
            "total_saved_routes" => $totalSaved,
            "total_waypoints"    => $totalWaypoints,
        ],
        "user_distribution" => [
            "admins"    => $totalAdmins,
            "commuters" => $totalUsers,
        ],
        "vehicle_breakdown" => $vehicleBreakdown,
        "trend" => [
            "labels"       => $trendLabels,
            "signups"      => $signupCounts,
            "saved_routes" => $savedCounts,
        ],
    ]);

} catch (PDOException $e) {
    http_response_code(500);
    // TEMPORARY: includes the real DB error so we can pinpoint the bad
    // table/column. Remove $e->getMessage() from this response before
    // shipping — it can leak schema details to anyone hitting the endpoint.
    echo json_encode(["success" => false, "message" => "Server error while fetching analytics: " . $e->getMessage()]);
}