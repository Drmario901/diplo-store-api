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
    $userId = intval($decoded->sub);

    $query = "SELECT first_name, last_name FROM users WHERE id = '$userId' LIMIT 1";
    $result = mysqli_query($conexion, $query);

    if (!$result || mysqli_num_rows($result) === 0) {
        http_response_code(404);
        echo json_encode(["message" => "User not found"]);
        exit();
    }

    $user = mysqli_fetch_assoc($result);
    echo json_encode($user);
    exit();

} catch (Exception $e) {
    http_response_code(401);
    echo json_encode([
        "error" => "Invalid or expired token",
        "detail" => $e->getMessage()
    ]);
    exit();
}
