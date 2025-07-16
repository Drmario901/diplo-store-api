<?php
require __DIR__ . '../../conexion.php';
require __DIR__ . '/../../vendor/autoload.php';

use Firebase\JWT\JWT;
use Firebase\JWT\Key;

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/../../');
$dotenv->load();

$secret_key = $_ENV['JWT_SECRET'];

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");
header("Content-Type: application/json; charset=UTF-8");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(["message" => "Only POST allowed"]);
    exit();
}

$inputJSON = file_get_contents('php://input');
$input = json_decode($inputJSON, true);

if (!isset($input['token'])) {
    http_response_code(400);
    echo json_encode(["message" => "Token required"]);
    exit();
}

$token = $input['token'];

try {
    $decoded = JWT::decode($token, new Key($secret_key, 'HS256'));

    if (intval($decoded->role) !== 2) {
        http_response_code(403);
        echo json_encode(["message" => "Access denied. Not an admin."]);
        exit();
    }

    $email = mysqli_real_escape_string($conexion, $decoded->email);
    $check = mysqli_query($conexion, "SELECT id FROM users WHERE email = '$email' AND role_id = 2 LIMIT 1");

    if (!$check || mysqli_num_rows($check) === 0) {
        http_response_code(403);
        echo json_encode(["message" => "Access denied. Admin role not verified."]);
        exit();
    }

    echo json_encode(["message" => "Access granted", "admin" => true]);

} catch (Exception $e) {
    http_response_code(401);
    echo json_encode(["error" => "Invalid or expired token", "detail" => $e->getMessage()]);
}
