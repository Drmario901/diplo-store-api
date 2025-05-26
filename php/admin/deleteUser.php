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

if (!isset($input['token'], $input['user_id'])) {
    http_response_code(400);
    echo json_encode(["message" => "Missing parameters: token or user_id"]);
    exit();
}

$token = $input['token'];
$userId = intval($input['user_id']);

try {
    $decoded = JWT::decode($token, new Key($secret_key, 'HS256'));

    if (intval($decoded->role) !== 2) {
        http_response_code(403);
        echo json_encode(["message" => "Access denied. Only admins allowed."]);
        exit();
    }

    $email = mysqli_real_escape_string($conexion, $decoded->email);
    $checkAdmin = mysqli_query($conexion, "SELECT id FROM users WHERE email = '$email' AND role_id = 2 LIMIT 1");

    if (!$checkAdmin || mysqli_num_rows($checkAdmin) === 0) {
        http_response_code(403);
        echo json_encode(["message" => "Access denied. Admin role not confirmed in database."]);
        exit();
    }

    $delete = mysqli_query($conexion, "DELETE FROM users WHERE id = $userId");

    if ($delete) {
        echo json_encode(["message" => "User deleted successfully."]);
    } else {
        http_response_code(500);
        echo json_encode(["message" => "Failed to delete user."]);
    }

} catch (Exception $e) {
    http_response_code(401);
    echo json_encode(["error" => "Invalid or expired token", "detail" => $e->getMessage()]);
}
