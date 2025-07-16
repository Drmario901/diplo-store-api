<?php

require __DIR__ . '/../../vendor/autoload.php';
require __DIR__ . '/../conexion.php';

use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Stripe\Stripe;
use Stripe\Checkout\Session;

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/../../');
$dotenv->load();

Stripe::setApiKey($_ENV['STRIPE_SECRET_KEY']);
$jwt_secret = $_ENV['JWT_SECRET'];

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

$inputJSON = file_get_contents("php://input");
$input = json_decode($inputJSON, true);

if (!isset($input['token'])) {
    http_response_code(400);
    echo json_encode(["message" => "Token requerido"]);
    exit();
}

try {
    $decoded = JWT::decode($input['token'], new Key($jwt_secret, 'HS256'));
    $user_id = intval($decoded->sub);
} catch (Exception $e) {
    http_response_code(401);
    echo json_encode(["error" => "Token inválido", "detail" => $e->getMessage()]);
    exit();
}

$query = "SELECT o.id AS order_id, o.stripe_session_id, o.total, o.created_at, u.first_name, u.email
          FROM orders o
          JOIN users u ON u.id = o.user_id
          WHERE u.id = '$user_id'
          ORDER BY o.created_at DESC";

$resultado = mysqli_query($conexion, $query);
$ordenes = [];

while ($row = mysqli_fetch_assoc($resultado)) {
    $stripe_session_id = $row['stripe_session_id'];
    $stripe_data = [];
    $items = [];
    $payment_status = 'unpaid';
    $invoice_url = null;

    try {
        $session = Session::retrieve($stripe_session_id);

        if ($session) {
            $line_items = Session::allLineItems($session->id);

            foreach ($line_items->data as $item) {
                $items[] = [
                    'name' => $item->description ?? 'Producto',
                    'quantity' => $item->quantity ?? 0,
                    'amount_each' => isset($item->amount_total, $item->quantity) && $item->quantity > 0
                        ? round($item->amount_total / $item->quantity / 100, 2)
                        : 0.00,
                    'total' => isset($item->amount_total)
                        ? round($item->amount_total / 100, 2)
                        : 0.00
                ];
            }

            $payment_status = $session->payment_status ?? 'unpaid';

            $stripe_data = [
                'stripe_status' => $payment_status,
                'stripe_total' => isset($session->amount_total) ? round($session->amount_total / 100, 2) : 0.00,
                'stripe_currency' => strtoupper($session->currency ?? 'usd'),
                'stripe_payment_intent' => $session->payment_intent ?? 'N/A',
                'stripe_email' => $session->customer_email ?? 'N/A',
                'stripe_metadata' => $session->metadata ?? []
            ];

            if ($payment_status === 'paid') {
                $invoice_url = 'https://diplostore.fwh.is/diplo-store-api/api/gateway/invoice?id=' . $row['order_id'];
            }
        } else {
            throw new Exception("Sesion no encontrada");
        }

    } catch (Exception $e) {
        $stripe_data = [
            'stripe_status' => 'incomplete',
            'stripe_total' => 0.00,
            'stripe_currency' => 'USD',
            'stripe_payment_intent' => 'N/A',
            'stripe_email' => 'N/A',
            'stripe_metadata' => [],
            'stripe_error' => 'Sesion no encontrada'
        ];
    }

    $ordenes[] = [
        'order_id' => $row['order_id'],
        'usuario' => $row['first_name'],
        'correo' => $row['email'],
        'monto_db' => number_format(floatval($row['total']), 2, '.', ''),
        'fecha' => $row['created_at'],
        'stripe' => $stripe_data,
        'items' => $items,
        'invoice_url' => $invoice_url
    ];
}

echo json_encode($ordenes);
