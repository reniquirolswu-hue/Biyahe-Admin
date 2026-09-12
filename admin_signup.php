<?php
// admin_signup.php
require_once 'config.php';

$data = json_decode(file_get_contents("php://input"), true);

$username = trim($data['username'] ?? '');
$password = trim($data['password'] ?? '');
$confirmPassword = trim($data['confirm_password'] ?? $data['confirmPassword'] ?? '');
$email = trim($data['email'] ?? '');

// Basic validation
if (empty($username) || empty($password) || empty($confirmPassword) || empty($email)) {
    echo json_encode(["success" => false, "message" => "All fields are required."]);
    exit();
}

if (strlen($username) < 3) {
    echo json_encode(["success" => false, "message" => "Username must be at least 3 characters."]);
    exit();
}

if ($password !== $confirmPassword) {
    echo json_encode(["success" => false, "message" => "Passwords do not match."]);
    exit();
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    echo json_encode(["success" => false, "message" => "Invalid email format."]);
    exit();
}

try {
    // Check if username or email already exists
    $checkStmt = $conn->prepare("SELECT admin_id FROM admins WHERE admin_username = :username OR admin_email = :email");
    $checkStmt->execute(['username' => $username, 'email' => $email]);

    if ($checkStmt->rowCount() > 0) {
        echo json_encode(["success" => false, "message" => "Username or email already exists."]);
        exit();
    }

    // Hash password before storing
    $hashedPassword = password_hash($password, PASSWORD_BCRYPT);

    $stmt = $conn->prepare("INSERT INTO admins (admin_username, admin_password, admin_email) VALUES (:username, :password, :email)");
    $stmt->execute([
        'username' => $username,
        'password' => $hashedPassword,
        'email' => $email
    ]);

    echo json_encode(["success" => true, "message" => "Admin account created successfully."]);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(["success" => false, "message" => "Error: " . $e->getMessage()]);
}