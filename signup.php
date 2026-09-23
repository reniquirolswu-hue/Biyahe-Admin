<?php
// Set JSON response header at the very top
header('Content-Type: application/json; charset=utf-8');

// Disable HTML error displays so PHP warnings/errors don't corrupt the JSON response
ini_set('display_errors', 0);
error_reporting(E_ALL);

require_once 'config.php';

// Helper sanitizer function if not already defined in config.php
if (!function_exists('sanitize_string')) {
    function sanitize_string($input) {
        return htmlspecialchars(strip_tags(trim($input ?? '')), ENT_QUOTES, 'UTF-8');
    }
}

$data = json_decode(file_get_contents("php://input"), true) ?? [];

$username        = sanitize_string($data['username'] ?? '');
$email           = filter_var(trim($data['email'] ?? ''), FILTER_SANITIZE_EMAIL);
$password        = $data['password'] ?? '';
$confirmPassword = $data['confirmPassword'] ?? '';

// Validation checks
if (empty($username) || empty($password) || empty($confirmPassword) || empty($email)) {
    http_response_code(400);
    echo json_encode(["success" => false, "message" => "All fields are required."]);
    exit();
}

if (strlen($username) < 3 || strlen($username) > 50) {
    http_response_code(400);
    echo json_encode(["success" => false, "message" => "Username must be between 3 and 50 characters."]);
    exit();
}

if ($password !== $confirmPassword) {
    http_response_code(400);
    echo json_encode(["success" => false, "message" => "Passwords do not match."]);
    exit();
}

if (strlen($password) < 8) {
    http_response_code(400);
    echo json_encode(["success" => false, "message" => "Password must be at least 8 characters long."]);
    exit();
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    http_response_code(400);
    echo json_encode(["success" => false, "message" => "Invalid email format."]);
    exit();
}

try {
    // Check if username or email already exists
    $checkStmt = $conn->prepare("SELECT user_id FROM users WHERE username = :username OR email = :email");
    $checkStmt->execute(['username' => $username, 'email' => $email]);

    if ($checkStmt->fetch()) {
        http_response_code(409);
        echo json_encode(["success" => false, "message" => "Username or email already exists."]);
        exit();
    }

    // Hash password before storing
    $hashedPassword = password_hash($password, PASSWORD_BCRYPT);

    $stmt = $conn->prepare("INSERT INTO users (username, password, email) VALUES (:username, :password, :email)");
    $stmt->execute([
        'username' => $username,
        'password' => $hashedPassword,
        'email'    => $email
    ]);

    http_response_code(201);
    echo json_encode(["success" => true, "message" => "Account created successfully."]);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        "success" => false, 
        "message" => "Database error occurred."
    ]);
}
?>