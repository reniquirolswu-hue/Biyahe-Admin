<?php
// login.php
require_once __DIR__ . '/config.php';

$data = json_decode(file_get_contents("php://input"), true) ?? [];

$username = isset($data['username']) ? (function_exists('sanitize_string') ? sanitize_string((string)$data['username']) : trim((string)$data['username'])) : '';
$password = $data['password'] ?? ''; // Kept raw so spaces/special characters aren't altered

// Basic validation
if (empty($username) || empty($password)) {
    http_response_code(400);
    echo json_encode(["success" => false, "message" => "All fields are required."]);
    exit();
}

try {
    // Look up the user by username
    $stmt = $conn->prepare("SELECT user_id, username, password, email FROM users WHERE username = :username");
    $stmt->execute(['username' => $username]);

    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user || !password_verify($password, $user['password'])) {
        usleep(300000); // Prevent timing attacks
        http_response_code(401);
        echo json_encode(["success" => false, "message" => "Invalid username or password."]);
        exit();
    }

    http_response_code(200);
    echo json_encode([
        "success" => true,
        "message" => "Login successful.",
        "email"   => htmlspecialchars((string)($user['email'] ?? ''), ENT_QUOTES, 'UTF-8')
    ]);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(["success" => false, "message" => "An error occurred during login."]);
}