<?php
// login.php
require_once __DIR__ . '/config.php';

// Enable CORS & Allow Credentials for Mobile App Session Persistence
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Credentials: true");
header("Content-Type: application/json; charset=UTF-8");

// Start PHP Session
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$data = json_decode(file_get_contents("php://input"), true) ?? [];

$username = isset($data['username']) ? (function_exists('sanitize_string') ? sanitize_string((string)$data['username']) : trim((string)$data['username'])) : '';
$password = $data['password'] ?? ''; 

// Basic validation
if (empty($username) || empty($password)) {
    http_response_code(400);
    echo json_encode(["success" => false, "message" => "All fields are required."]);
    exit();
}

try {
    // Look up the user by username or email
    $stmt = $conn->prepare("SELECT user_id, username, password, email FROM users WHERE username = :username OR email = :username");
    $stmt->execute(['username' => $username]);

    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user || !password_verify($password, $user['password'])) {
        usleep(300000); // Prevent timing attacks
        http_response_code(401);
        echo json_encode(["success" => false, "message" => "Invalid username or password."]);
        exit();
    }

    // Set Session Variables
    $_SESSION['user_id'] = $user['user_id'];
    $_SESSION['username'] = $user['username'];
    $_SESSION['logged_in'] = true;

    http_response_code(200);
    echo json_encode([
        "success" => true,
        "message" => "Login successful.",
        "username" => $user['username'],
        "email"   => htmlspecialchars((string)($user['email'] ?? ''), ENT_QUOTES, 'UTF-8')
    ]);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(["success" => false, "message" => "An error occurred during login."]);
}
?>