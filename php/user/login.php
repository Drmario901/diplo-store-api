<?php 

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

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
    echo json_encode(["message" => "Invalid JSON", "error" => json_last_error_msg()]);
    exit();
}

$email = isset($input['email']) ? trim($input['email']) : '';
$password = isset($input['password']) ? $input['password'] : '';

if (empty($email) || empty($password)) {
    http_response_code(400);
    echo json_encode(["message" => "Email and password are required"]);
    exit();
}

$email = mysqli_real_escape_string($conexion, $email);

$query = "SELECT id, email, password, role_id FROM users WHERE email = '$email' LIMIT 1";
$result = mysqli_query($conexion, $query);

if (!$result || mysqli_num_rows($result) === 0) {
    http_response_code(401);
    echo json_encode(["message" => "Credenciales invalidas"]);
    exit();
}

$user = mysqli_fetch_assoc($result);

if (!password_verify($password, $user['password'])) {
    http_response_code(401);
    echo json_encode(["message" => "Credenciales invalidas"]);
    exit();
}

$issuedAt = time();
$expirationTime = $issuedAt + 3600; 

$payload = [
    'iat' => $issuedAt,
    'exp' => $expirationTime,
    'sub' => $user['id'],
    'email' => $user['email'],
    'role' => $user['role_id']
];

$jwt = JWT::encode($payload, $secret_key, 'HS256');

$redirectTo = ($user['role_id'] == 1) ? '/productos' : '/admin/dashboard';
http_response_code(200);
echo json_encode([
    "message" => "Login successful",
    "token" => $jwt,
    "user_id" => $user['id'],
    "email" => $user['email'], 
    "role" => $user['role_id'], 
    "redirect" => $redirectTo
]);
