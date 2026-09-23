<?php
// update_profile.php

ob_start();

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Credentials: true");
header("Access-Control-Allow-Methods: POST, OPTIONS");
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
$data = json_decode(file_get_contents("php://input"), true) ?? [];

$newUsername = trim((string)($data['username'] ?? ''));
$newEmail    = trim((string)($data['email'] ?? ''));
$currentPass = $data['current_password'] ?? '';
$newPass     = $data['new_password'] ?? '';

if (empty($newUsername) || empty($newEmail)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Username and email cannot be empty.']);
    exit();
}

try {
    // 1. Fetch current user data for password verification & duplicate checks
    $stmt = $conn->prepare('SELECT password FROM users WHERE user_id = :user_id');
    $stmt->execute([':user_id' => $userId]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'User not found.']);
        exit();
    }

    // 2. Check if username or email is already taken by ANOTHER user
    $checkStmt = $conn->prepare('SELECT user_id FROM users WHERE (username = :username OR email = :email) AND user_id != :user_id');
    $checkStmt->execute([
        ':username' => $newUsername,
        ':email'    => $newEmail,
        ':user_id'  => $userId
    ]);
    if ($checkStmt->fetch()) {
        http_response_code(409);
        echo json_encode(['success' => false, 'message' => 'Username or email is already in use.']);
        exit();
    }

    // 3. Handle password change if requested
    $updatePassword = false;
    if (!empty($newPass)) {
        if (empty($currentPass) || !password_verify($currentPass, $user['password'])) {
            http_response_code(401);
            echo json_encode(['success' => false, 'message' => 'Current password is incorrect.']);
            exit();
        }
        $updatePassword = true;
    }

    // 4. Update Database
    if ($updatePassword) {
        $hashedPass = password_hash($newPass, PASSWORD_DEFAULT);
        $updateStmt = $conn->prepare('UPDATE users SET username = :username, email = :email, password = :password WHERE user_id = :user_id');
        $updateStmt->execute([
            ':username' => $newUsername,
            ':email'    => $newEmail,
            ':password' => $hashedPass,
            ':user_id'  => $userId
        ]);
    } else {
        $updateStmt = $conn->prepare('UPDATE users SET username = :username, email = :email WHERE user_id = :user_id');
        $updateStmt->execute([
            ':username' => $newUsername,
            ':email'    => $newEmail,
            ':user_id'  => $userId
        ]);
    }

    // Update Session
    $_SESSION['username'] = $newUsername;

    echo json_encode(['success' => true, 'message' => 'Profile updated successfully!']);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
}
?>