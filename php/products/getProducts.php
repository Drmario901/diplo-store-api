<?php
require __DIR__ . '../../conexion.php';
header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json; charset=UTF-8");

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(["message" => "Only GET allowed"]);
    exit();
}

$query = "SELECT id, name, price, category, description, image_url, slug, created_at FROM products ORDER BY created_at DESC";
$result = mysqli_query($conexion, $query);

$products = [];

while ($row = mysqli_fetch_assoc($result)) {
    $products[] = [
        "id" => (int)$row['id'],
        "name" => $row['name'],
        "price" => (float)$row['price'],
        "category" => $row['category'],
        "description" => $row['description'],
        "image_url" => $row['image_url'],
        "slug" => $row['slug'],
        "created_at" => $row['created_at']
    ];
}

echo json_encode([
    "products" => $products
]);
