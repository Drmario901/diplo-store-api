<?php
require __DIR__ . '../../conexion.php';
require __DIR__ . '/../../vendor/autoload.php';

use Firebase\JWT\JWT;
use Firebase\JWT\Key;

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/../../');
$dotenv->load();

$jwt_secret = $_ENV['JWT_SECRET'];
$storyblok_management_token = $_ENV['STORYBLOK_MANAGEMENT_TOKEN'];
$storyblok_space = $_ENV['STORYBLOK_SPACE_ID'];

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

if (!isset($_POST['token'], $_POST['name'], $_POST['price'], $_POST['category'], $_POST['description'])) {
    http_response_code(400);
    echo json_encode(["message" => "Missing required fields"]);
    exit();
}

function generateSlug($name, $conexion) {
    $slug = strtolower(trim($name));
    $slug = iconv('UTF-8', 'ASCII//TRANSLIT', $slug); 
    $slug = preg_replace('/[^a-z0-9\s-]/', '', $slug);
    $slug = preg_replace('/[\s-]+/', '-', $slug);
    $slug = trim($slug, '-');

    $baseSlug = $slug;
    $counter = 2;
    while (mysqli_num_rows(mysqli_query($conexion, "SELECT id FROM products WHERE slug = '$slug'")) > 0) {
        $slug = $baseSlug . '-' . $counter;
        $counter++;
    }
    return $slug;
}

$token = $_POST['token'];

try {
    $decoded = JWT::decode($token, new Key($jwt_secret, 'HS256'));
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

    $name = mysqli_real_escape_string($conexion, $_POST['name']);
    $price = floatval($_POST['price']);
    $category = mysqli_real_escape_string($conexion, $_POST['category']);
    $descriptionText = mysqli_real_escape_string($conexion, $_POST['description']);
    $slug = generateSlug($name, $conexion);

    $image_data = null;

    if (isset($_FILES['image']) && $_FILES['image']['error'] === UPLOAD_ERR_OK) {
        $tmp_name = $_FILES['image']['tmp_name'];
        $original_name = $_FILES['image']['name'];
        $mime_type = mime_content_type($tmp_name);

        $cfile = curl_file_create($tmp_name, $mime_type, $original_name);
        $initCh = curl_init("https://api.storyblok.com/v1/spaces/$storyblok_space/assets");
        curl_setopt($initCh, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($initCh, CURLOPT_POST, true);
        curl_setopt($initCh, CURLOPT_POSTFIELDS, [
            'file' => $cfile,
            'filename' => $original_name
        ]);
        curl_setopt($initCh, CURLOPT_HTTPHEADER, [
            "Authorization: $storyblok_management_token"
        ]);

        $initResponse = curl_exec($initCh);
        $initHttpCode = curl_getinfo($initCh, CURLINFO_HTTP_CODE);
        curl_close($initCh);

        $initData = json_decode($initResponse, true);

        if ($initHttpCode === 200 && isset($initData['post_url'], $initData['fields'])) {
            $s3Fields = $initData['fields'];
            $postUrl = $initData['post_url'];
            $s3Fields['file'] = new CURLFile($tmp_name, $mime_type, $original_name);

            $s3Ch = curl_init($postUrl);
            curl_setopt($s3Ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($s3Ch, CURLOPT_POST, true);
            curl_setopt($s3Ch, CURLOPT_POSTFIELDS, $s3Fields);
            curl_exec($s3Ch);
            $s3HttpCode = curl_getinfo($s3Ch, CURLINFO_HTTP_CODE);
            curl_close($s3Ch);

            if ($s3HttpCode === 204 && isset($initData['id'])) {
                $image_data = [
                    'id' => $initData['id'],
                    'filename' => $initData['public_url'],
                    'fieldtype' => 'asset'
                ];
            } else {
                http_response_code(500);
                echo json_encode(["message" => "Failed uploading to S3"]);
                exit();
            }
        } else {
            http_response_code(500);
            echo json_encode(["message" => "Failed to initiate image upload"]);
            exit();
        }
    }

    $storyContent = [
        'component' => 'products',
        'name' => $name,
        'price' => (string)$price,
        'category' => $category,
        'description' => [
            'type' => 'doc',
            'content' => [[
                'type' => 'paragraph',
                'content' => [[
                    'type' => 'text',
                    'text' => $descriptionText
                ]]
            ]]
        ]
    ];

    if ($image_data) {
        $storyContent['image'] = $image_data;
    }

    $storyData = [
        'story' => [
            'name' => $name,
            'slug' => $slug,
            'parent_id' => 674927834,
            'content' => $storyContent
        ],
        'publish' => 1
    ];

    $ch = curl_init("https://api.storyblok.com/v1/spaces/$storyblok_space/stories");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($storyData));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        "Authorization: $storyblok_management_token"
    ]);
    curl_setopt($ch, CURLOPT_POST, true);

    $storyResponse = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $storyResponseData = json_decode($storyResponse, true);
    if ($httpCode !== 200 && $httpCode !== 201) {
        http_response_code(500);
        echo json_encode(["message" => "Failed to create story"]);
        exit();
    }

    $image_url = $image_data ? mysqli_real_escape_string($conexion, $image_data['filename']) : null;

    $insertQuery = "
        INSERT INTO products (name, price, category, description, image_url, slug, created_at)
        VALUES ('$name', $price, '$category', '$descriptionText', " . ($image_url ? "'$image_url'" : "NULL") . ", '$slug', NOW())
    ";
    $insert = mysqli_query($conexion, $insertQuery);

    if (!$insert) {
        http_response_code(500);
        echo json_encode(["message" => "Product created but failed to save in DB"]);
        exit();
    }

    echo json_encode([
        "message" => "Product created successfully",
        "story" => $storyResponseData,
        "image_uploaded" => $image_url !== null,
        "slug" => $slug,
        "image_url" => $image_url
    ]);

} catch (Exception $e) {
    http_response_code(401);
    echo json_encode(["error" => "Invalid or expired token"]);
}
