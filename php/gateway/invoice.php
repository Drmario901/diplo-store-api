<?php

require __DIR__ . '/../../vendor/autoload.php';
require __DIR__ . '/../conexion.php';

use Dompdf\Dompdf;
use Dompdf\Options;
use Stripe\Stripe;
use Stripe\Checkout\Session;

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/../../');
$dotenv->load();
Stripe::setApiKey($_ENV['STRIPE_SECRET_KEY']);

header("Content-Type: application/pdf");

if (!isset($_GET['id'])) {
    http_response_code(400);
    echo "ID de orden requerido.";
    exit();
}

$order_id = intval($_GET['id']);

$query = "SELECT o.id AS order_id, o.total, o.created_at, o.stripe_session_id,
                 u.first_name, u.last_name, u.email
          FROM orders o
          JOIN users u ON u.id = o.user_id
          WHERE o.id = '$order_id'
          LIMIT 1";

$result = mysqli_query($conexion, $query);

if (!$result || mysqli_num_rows($result) === 0) {
    echo "Orden no encontrada.";
    exit();
}

$orden = mysqli_fetch_assoc($result);

$items_html = '';
$stripe_total = 0.00;
$stripe_currency = 'USD';

try {
    $session = Session::retrieve($orden['stripe_session_id']);
    $line_items = Session::allLineItems($session->id);

    foreach ($line_items->data as $item) {
        $name = $item->description ?? 'Producto';
        $quantity = $item->quantity ?? 0;
        $amount_each = isset($item->amount_total, $item->quantity) && $item->quantity > 0
            ? round($item->amount_total / $item->quantity / 100, 2)
            : 0.00;
        $total = isset($item->amount_total)
            ? round($item->amount_total / 100, 2)
            : 0.00;

        $stripe_total += $total;

        $items_html .= "
            <tr>
                <td>$name</td>
                <td>$quantity</td>
                <td>\$$amount_each</td>
                <td>\$$total</td>
            </tr>
        ";
    }

    $stripe_currency = strtoupper($session->currency ?? 'USD');

} catch (Exception $e) {
    $items_html = '
        <tr>
            <td colspan="4" style="color:red;">Error al obtener los productos desde Stripe</td>
        </tr>
    ';
}

$html = '
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Factura Orden #' . $orden['order_id'] . '</title>
    <style>
        body {
            font-family: DejaVu Sans, sans-serif;
            margin: 0;
            padding: 0;
            font-size: 13px;
            color: #333;
            background-color: #f7f9fc;
        }
        .container {
            padding: 40px;
        }
        .header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            background-color: #00a78e;
            padding: 20px;
            color: white;
        }
        .header img {
            height: 40px;
        }
        .company-name {
            font-size: 20px;
            font-weight: bold;
            text-align: center;
            width: 100%;
        }
        .title {
            text-align: center;
            margin: 30px 0 10px;
            font-size: 24px;
            color: #00a78e;
        }
        .info {
            margin-bottom: 25px;
            background: white;
            padding: 20px;
            border-radius: 8px;
        }
        .info p {
            margin: 4px 0;
        }
        .info strong {
            color: #00a78e;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 25px;
            background: white;
            border-radius: 8px;
            overflow: hidden;
        }
        table thead {
            background-color: #e0f7f4;
        }
        th, td {
            padding: 12px;
            border: 1px solid #ccc;
            text-align: left;
        }
        .total {
            text-align: right;
            font-size: 16px;
            margin-top: 10px;
            color: #333;
        }
        .footer {
            text-align: center;
            font-size: 11px;
            color: #777;
            margin-top: 20px;
            border-top: 1px solid #ddd;
            padding-top: 10px;
        }
    </style>
</head>
<body>
    <div class="header">
        <div class="company-name">DIPLOSTORE</div>
    </div>

    <div class="container">
        <div class="title">Factura de Orden</div>

        <div class="info">
            <p><strong>Orden N°:</strong> ' . $orden['order_id'] . '</p>
            <p><strong>Fecha:</strong> ' . date("d/m/Y h:i A", strtotime($orden['created_at'])) . '</p>
            <p><strong>Cliente:</strong> ' . $orden['first_name'] . ' ' . $orden['last_name'] . '</p>
            <p><strong>Email:</strong> ' . $orden['email'] . '</p>
        </div>

        <table>
            <thead>
                <tr>
                    <th>Producto</th>
                    <th>Cantidad</th>
                    <th>Precio Unitario ($)</th>
                    <th>Total ($)</th>
                </tr>
            </thead>
            <tbody>' . $items_html . '</tbody>
        </table>

        <p class="total"><strong>Total pagado: $' . number_format($stripe_total, 2) . ' ' . $stripe_currency . '</strong></p>

        <div class="footer">
            Factura automatizada por DIPLOSTORE. Gracias por su compra.
        </div>
    </div>
</body>
</html>
';

$options = new Options();
$options->set('defaultFont', 'DejaVu Sans');
$dompdf = new Dompdf($options);
$dompdf->loadHtml($html);
$dompdf->setPaper('A4', 'portrait');
$dompdf->render();

$dompdf->stream("orden_{$orden['order_id']}.pdf", ["Attachment" => false]);
exit();
