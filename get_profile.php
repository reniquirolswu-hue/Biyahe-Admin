<?php
// admin/api/get_profile.php

ob_start();

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Credentials: true");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
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
    handleGetProfile($conn, $userId);
    exit();
}

if ($method === 'POST') {
    $action = $_GET['action'] ?? '';
    if ($action === 'logout') {
        handleLogout();
        exit();
    }
}

http_response_code(405);
echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
exit();

// ---------------------------------------------------------------------------

function handleGetProfile($conn, $userId) {
    try {
        // Updated SELECT query to include profile_image column
        $stmt = $conn->prepare('SELECT user_id, username, email, profile_image, date_created FROM users WHERE user_id = :user_id');
        $stmt->execute([':user_id' => $userId]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$user) {
            http_response_code(404);
            echo json_encode(['success' => false, 'message' => 'User not found.']);
            return;
        }

        // Detect HTTP or HTTPS protocol dynamically
        $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? "https://" : "http://";
        
        $filename = $user['profile_image'] ?? null;
        $profileImageUrl = $filename ? $protocol . $_SERVER['HTTP_HOST'] . "/Biyahe-Admin/uploads/avatars/" . $filename : null;

        echo json_encode([
            'success'       => true,
            'user_id'       => (int) $user['user_id'],
            'username'      => htmlspecialchars((string)($user['username'] ?? ''), ENT_QUOTES, 'UTF-8'),
            'email'         => htmlspecialchars((string)($user['email'] ?? ''), ENT_QUOTES, 'UTF-8'),
            'profile_image' => $profileImageUrl,
            'date_created'  => $user['date_created'] ?? null
        ]);
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
    }
}

function handleLogout() {
    $_SESSION = array();

    if (ini_get("session.use_cookies")) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000,
            $params["path"], $params["domain"],
            $params["secure"], $params["httponly"]
        );
    }

    session_destroy();

    echo json_encode(['success' => true, 'message' => 'Logged out successfully.']);
}
?>