<?php
// admin_login.php
session_start();
require_once 'config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(["success" => false, "message" => "Method not allowed."]);
    exit();
}

$data = json_decode(file_get_contents("php://input"), true) ?? [];

$username = sanitize_string($data['username'] ?? '');
$password = $data['password'] ?? '';

if (empty($username) || empty($password)) {
    http_response_code(400);
    echo json_encode(["success" => false, "message" => "Username and password are required."]);
    exit();
}

try {
    $stmt = $conn->prepare(
        "SELECT admin_id, admin_uuid, admin_username, admin_password, admin_email
         FROM admins
         WHERE admin_username = :username"
    );
    $stmt->execute(['username' => $username]);
    $admin = $stmt->fetch();

    if (!$admin || !password_verify($password, $admin['admin_password'])) {
        usleep(300000); // Slight delay helps mitigate brute-force
        http_response_code(401);
        echo json_encode(["success" => false, "message" => "Invalid username or password."]);
        exit();
    }

    // Prevent session fixation
    session_regenerate_id(true);

    $_SESSION['admin_id']       = $admin['admin_id'];
    $_SESSION['admin_uuid']     = $admin['admin_uuid'];
    $_SESSION['admin_username'] = $admin['admin_username'];
    $_SESSION['logged_in_at']   = time();

    echo json_encode([
        "success" => true,
        "message" => "Login successful.",
        "admin"   => [
            "admin_id"   => (int) $admin['admin_id'],
            "admin_uuid" => $admin['admin_uuid'],
            "username"   => $admin['admin_username'],
            "email"      => $admin['admin_email']
        ]
    ]);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(["success" => false, "message" => "Server error. Please try again later."]);
}