<?php

require __DIR__ . '/../../vendor/autoload.php';
require __DIR__ . '/../conexion.php';

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/../../');
$dotenv->load();

\Stripe\Stripe::setApiKey($_ENV['STRIPE_SECRET_KEY']);

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");
header("Content-Type: application/json; charset=UTF-8");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(["message" => "Only GET allowed"]);
    exit();
}

$query = "SELECT o.id AS order_id, o.stripe_session_id, o.total, o.created_at, u.first_name, u.email
          FROM orders o
          JOIN users u ON u.id = o.user_id
          ORDER BY o.created_at DESC
          LIMIT 30";

$resultado = mysqli_query($conexion, $query);
$ordenes = [];
$ventasTotales = 0;
$ordenesCompletadas = 0;

while ($row = mysqli_fetch_assoc($resultado)) {
    $stripe_session_id = $row['stripe_session_id'];
    $stripe_data = [];

    try {
        $session = \Stripe\Checkout\Session::retrieve($stripe_session_id);

        if ($session && $session->payment_status === 'paid') {
            $montoStripe = $session->amount_total / 100;
            $ventasTotales += $montoStripe;
            $ordenesCompletadas++;

            $stripe_data = [
                'stripe_status' => $session->payment_status,
                'stripe_total' => $montoStripe,
                'stripe_currency' => strtoupper($session->currency),
                'stripe_payment_intent' => $session->payment_intent,
                'stripe_email' => $session->customer_email,
                'stripe_metadata' => $session->metadata
            ];
        }
    } catch (Exception $e) {
        $stripe_data = [
            'stripe_error' => 'Sesión no encontrada o inválida',
            'stripe_session_id' => $stripe_session_id
        ];
    }

    $ordenes[] = [
        'order_id' => $row['order_id'],
        'usuario' => $row['first_name'],
        'correo' => $row['email'],
        'monto_db' => $row['total'],
        'fecha' => $row['created_at'],
        'stripe' => $stripe_data
    ];
}

$queryUsuarios = "SELECT id, first_name, email, created_at FROM users WHERE role_id = 1 ORDER BY created_at DESC";
$resultUsuarios = mysqli_query($conexion, $queryUsuarios);
$usuarios = [];

while ($user = mysqli_fetch_assoc($resultUsuarios)) {
    $usuarios[] = [
        'id' => $user['id'],
        'nombre' => $user['first_name'],
        'correo' => $user['email'],
        'fecha_registro' => $user['created_at']
    ];
}

$response = [
    'ordenes_recientes' => $ordenes,
    'usuarios_comunes' => $usuarios,
    'ventas_totales' => round($ventasTotales, 2),
    'ordenes_completadas' => $ordenesCompletadas
];

echo json_encode($response);
