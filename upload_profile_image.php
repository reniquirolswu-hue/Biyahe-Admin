<?php
// Biyahe-Admin/upload_profile_image.php
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

$userId = $_SESSION['user_id'] ?? null;
if (!$userId) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'User session required.']);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || empty($_FILES['profile_image'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'No image uploaded.']);
    exit();
}

$file = $_FILES['profile_image'];
$allowedTypes = ['image/jpeg', 'image/png', 'image/webp'];

if (!in_array($file['type'], $allowedTypes)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Only JPG, PNG, and WEBP formats are allowed.']);
    exit();
}

// Ensure upload directory exists
$uploadDir = __DIR__ . '/uploads/avatars/';
if (!is_dir($uploadDir)) {
    mkdir($uploadDir, 0777, true);
}

// Generate unique filename
$ext = pathinfo($file['name'], PATHINFO_EXTENSION);
$filename = 'avatar_' . $userId . '_' . time() . '.' . $ext;
$targetPath = $uploadDir . $filename;

if (move_uploaded_file($file['tmp_name'], $targetPath)) {
    // Update image path in database
    $stmt = $conn->prepare('UPDATE users SET profile_image = :image WHERE user_id = :id');
    $stmt->execute([':image' => $filename, ':id' => $userId]);

    $baseUrl = "http://" . $_SERVER['HTTP_HOST'] . "/Biyahe-Admin/uploads/avatars/";
    echo json_encode([
        'success' => true,
        'message' => 'Profile picture updated successfully.',
        'profile_image' => $baseUrl . $filename
    ]);
} else {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Failed to save file on server.']);
}
?>