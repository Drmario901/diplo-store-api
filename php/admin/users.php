<?php

error_reporting(E_ALL);
ini_set('display_errors', 1);

require __DIR__ . '../../conexion.php';
require __DIR__ . '/../../vendor/autoload.php';

use Firebase\JWT\JWT;
use Firebase\JWT\Key;

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/../../');
$dotenv->load();

$secret_key = $_ENV['JWT_SECRET'];

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
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

if (json_last_error() !== JSON_ERROR_NONE) {
    http_response_code(400);
    echo json_encode(["message" => "Invalid JSON"]);
    exit();
}

if (!isset($input['token'])) {
    http_response_code(401);
    echo json_encode(["message" => "Token not provided"]);
    exit();
}

$token = $input['token'];

try {
    $decoded = JWT::decode($token, new Key($secret_key, 'HS256'));

    if (!isset($decoded->role) || intval($decoded->role) !== 2) {
        http_response_code(403);
        echo json_encode(["message" => "Access denied. Admins only (role 2 required in token)."]);
        exit();
    }

    $email = mysqli_real_escape_string($conexion, $decoded->email);

    $validateQuery = "
        SELECT id 
        FROM users 
        WHERE email = '$email' AND role_id = 2 
        LIMIT 1
    ";

    $validateResult = mysqli_query($conexion, $validateQuery);

    if (!$validateResult || mysqli_num_rows($validateResult) === 0) {
        http_response_code(403);
        echo json_encode(["message" => "Access denied. Email is not associated with an admin role."]);
        exit();
    }

    $query = "
        SELECT 
            u.id, 
            u.first_name, 
            u.last_name, 
            u.email, 
            r.id AS role_id, 
            r.name AS role_name
        FROM users u
        INNER JOIN roles r ON u.role_id = r.id
    ";

    $result = mysqli_query($conexion, $query);

    if (!$result) {
        http_response_code(500);
        echo json_encode(["message" => "Database query failed"]);
        exit();
    }

    $users = [];
    while ($row = mysqli_fetch_assoc($result)) {
        $users[] = $row;
    }

    echo json_encode($users);
    exit();

} catch (Exception $e) {
    http_response_code(401);
    echo json_encode([
        "error" => "Invalid or expired token",
        "detail" => $e->getMessage()
    ]);
    exit();
}
