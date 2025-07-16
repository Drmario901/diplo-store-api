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

$first_name = isset($input['first_name']) ? trim($input['first_name']) : '';
$last_name = isset($input['last_name']) ? trim($input['last_name']) : '';
$email = isset($input['email']) ? trim($input['email']) : '';
$password = isset($input['password']) ? $input['password'] : '';

if (empty($first_name) || empty($last_name) || empty($email) || empty($password)) {
    http_response_code(400);
    echo json_encode(["message" => "First name, last name, email and password are required"]);
    exit();
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    http_response_code(400);
    echo json_encode(["message" => "Invalid email format"]);
    exit();
}

$first_name = mysqli_real_escape_string($conexion, $first_name);
$last_name = mysqli_real_escape_string($conexion, $last_name);
$email = mysqli_real_escape_string($conexion, $email);

$query_check = "SELECT id FROM users WHERE email = '$email' LIMIT 1";
$result_check = mysqli_query($conexion, $query_check);

if ($result_check && mysqli_num_rows($result_check) > 0) {
    http_response_code(409);
    echo json_encode(["message" => "User already exists"]);
    exit();
}

$hashed_password = password_hash($password, PASSWORD_DEFAULT);

$query_insert = "INSERT INTO users (first_name, last_name, email, password, role_id) VALUES ('$first_name', '$last_name', '$email', '$hashed_password', DEFAULT)";
$result_insert = mysqli_query($conexion, $query_insert);


if ($result_insert) {
    $user_id = mysqli_insert_id($conexion);

    $query_role = "SELECT role_id FROM users WHERE id = $user_id LIMIT 1";
    $result_role = mysqli_query($conexion, $query_role);
    $role_id = null;
    if ($result_role && $row = mysqli_fetch_assoc($result_role)) {
        $role_id = $row['role_id'];
    }

    $issuedAt = time();
    $expirationTime = $issuedAt + 3600;

    $payload = [
        'iat' => $issuedAt,
        'exp' => $expirationTime,
        'sub' => $user_id,
        'email' => $email,
        'role' => $role_id
    ];

    $jwt = JWT::encode($payload, $secret_key, 'HS256');

    http_response_code(201);
    echo json_encode([
        "message" => "User registered successfully",
        "token" => $jwt,
        "user_id" => $user_id,
        "email" => $email,
        "role" => $role_id
    ]);
} else {
    http_response_code(500);
    echo json_encode(["message" => "Error registering user"]);
}
