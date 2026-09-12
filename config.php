<?php
// config.php
header('Content-Type: application/json');

$host = "localhost";      // or your server IP if pgAdmin is remote
$port = "5432";
$dbname = "Biyahe";    // your database name
$user = "postgres";       // your pgAdmin username
$password = "root"; // your pgAdmin password

try {
    $conn = new PDO("pgsql:host=$host;port=$port;dbname=$dbname", $user, $password);
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(["success" => false, "message" => "Connection failed: " . $e->getMessage()]);
    exit();
}