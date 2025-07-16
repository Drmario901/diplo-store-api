<?php

error_reporting(E_ALL);
ini_set('display_errors', 1);

require __DIR__ . '/../conexion.php';
require __DIR__ . '/../../vendor/autoload.php';

use Stripe\Stripe;
use Stripe\Checkout\Session;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/../../');
$dotenv->load();

$stripe_secret = $_ENV['STRIPE_SECRET_KEY'];
$jwt_secret = $_ENV['JWT_SECRET'];

Stripe::setApiKey($stripe_secret);

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

if (!isset($input['token']) || !isset($input['items']) || !is_array($input['items'])) {
    http_response_code(400);
    echo json_encode(["message" => "Token and items are required"]);
    exit();
}

try {
    $decoded = JWT::decode($input['token'], new Key($jwt_secret, 'HS256'));
    $user_id = intval($decoded->sub);
} catch (Exception $e) {
    http_response_code(401);
    echo json_encode([
        "error" => "Invalid or expired token",
        "detail" => $e->getMessage()
    ]);
    exit();
}

$user_query = mysqli_query($conexion, "SELECT first_name, email FROM users WHERE id = '$user_id' LIMIT 1");
$user = mysqli_fetch_assoc($user_query);

if (!$user) {
    http_response_code(404);
    echo json_encode(["message" => "User not found"]);
    exit();
}

$line_items = [];
$total_amount = 0;

foreach ($input['items'] as $item) {
    if (!isset($item['name'], $item['price'], $item['quantity'], $item['image'])) {
        continue;
    }

    $unit_amount = intval(floatval($item['price']) * 100);
    $quantity = intval($item['quantity']);
    $total_amount += ($unit_amount * $quantity) / 100;

    $line_items[] = [
        'price_data' => [
            'currency' => 'usd',
            'product_data' => [
                'name' => $item['name'],
                'images' => [$item['image']],
            ],
            'unit_amount' => $unit_amount,
        ],
        'quantity' => $quantity,
    ];
}

if (empty($line_items)) {
    http_response_code(400);
    echo json_encode(["message" => "No valid items to process"]);
    exit();
}

try {
    $session = Session::create([
        'payment_method_types' => ['card'],
        'line_items' => $line_items,
        'mode' => 'payment',
        'success_url' => $_ENV['STRIPE_SUCCESS_URL'] ?? 'https://diplostore.fwh.is/pago-exitoso',
        'cancel_url' => $_ENV['STRIPE_CANCEL_URL'] ?? 'https://diplostore.fwh.is/pago-cancelado',
        'customer_email' => $user['email'], 
        'metadata' => [                    
            'user_id' => $user_id,
            'nombre' => $user['first_name']
        ]
    ]);

    $user_id_esc = mysqli_real_escape_string($conexion, $user_id);
    $total_esc = mysqli_real_escape_string($conexion, $total_amount);
    $session_id = mysqli_real_escape_string($conexion, $session->id);

    $sql = "INSERT INTO orders (user_id, total, stripe_session_id) 
            VALUES ('$user_id_esc', '$total_esc', '$session_id')";

    $query = mysqli_query($conexion, $sql);

    if (!$query) {
        http_response_code(500);
        echo json_encode(["error" => "Database error", "detail" => mysqli_error($conexion)]);
        exit();
    }

    echo json_encode(['url' => $session->url]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        "error" => "Stripe error",
        "detail" => $e->getMessage()
    ]);
    exit();
}
