<?php
// admin_profile.php
// GET  -> returns the logged-in admin's current username/email from the DB.
// PUT  -> updates username/email for the logged-in admin, and keeps the
//         session in sync so the sidebar/topbar reflect the change right away.
//
// Password changes are intentionally NOT handled here — that stays on
// change-password.html / its own endpoint, same as the existing app.

session_start();
require_once 'config.php';

header('Content-Type: application/json');

if (!isset($_SESSION['admin_id'])) {
    http_response_code(401);
    echo json_encode(["success" => false, "message" => "Not authenticated."]);
    exit();
}

$adminId = $_SESSION['admin_id'];

// ---------------------------------------------------------------------
// GET — fetch fresh from the DB (not just the session) so edits made
// elsewhere (or by another admin) are always reflected.
// ---------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    try {
        $stmt = $conn->prepare(
            "SELECT admin_id, admin_uuid, admin_username, admin_email
             FROM admins
             WHERE admin_id = :id"
        );
        $stmt->execute(['id' => $adminId]);
        $admin = $stmt->fetch();

        if (!$admin) {
            http_response_code(404);
            echo json_encode(["success" => false, "message" => "Admin not found."]);
            exit();
        }

        echo json_encode([
            "success" => true,
            "admin" => [
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
    exit();
}

// ---------------------------------------------------------------------
// PUT — update username/email for the logged-in admin only. The WHERE
// admin_id = :id (from the session, never from the request body) is what
// stops one admin from editing another admin's row.
// ---------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'PUT') {
    $data = json_decode(file_get_contents("php://input"), true) ?? [];

    $username = isset($data['username']) ? sanitize_string($data['username']) : '';
    $email    = isset($data['email']) ? trim($data['email']) : '';

    if (empty($username) || empty($email)) {
        http_response_code(400);
        echo json_encode(["success" => false, "message" => "Username and email are required."]);
        exit();
    }

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        http_response_code(400);
        echo json_encode(["success" => false, "message" => "Please enter a valid email address."]);
        exit();
    }

    try {
        // Block collisions with a DIFFERENT admin's username/email.
        $check = $conn->prepare(
            "SELECT admin_id FROM admins
             WHERE (admin_username = :username OR admin_email = :email)
               AND admin_id != :id"
        );
        $check->execute(['username' => $username, 'email' => $email, 'id' => $adminId]);
        if ($check->fetch()) {
            http_response_code(409);
            echo json_encode(["success" => false, "message" => "That username or email is already in use."]);
            exit();
        }

        $stmt = $conn->prepare(
            "UPDATE admins
             SET admin_username = :username, admin_email = :email
             WHERE admin_id = :id"
        );
        $stmt->execute(['username' => $username, 'email' => $email, 'id' => $adminId]);

        // Keep the session copy in sync so it matches the DB immediately.
        $_SESSION['admin_username'] = $username;

        echo json_encode([
            "success" => true,
            "message" => "Profile updated successfully.",
            "admin" => [
                "admin_id" => (int) $adminId,
                "username" => $username,
                "email"    => $email
            ]
        ]);
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(["success" => false, "message" => "Server error. Please try again later."]);
    }
    exit();
}

http_response_code(405);
echo json_encode(["success" => false, "message" => "Method not allowed."]);