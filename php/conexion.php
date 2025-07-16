<?php
// DB CONFIG

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require __DIR__. '/../vendor/autoload.php';

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/../');
$dotenv->load();

$db_host = $_ENV['DB_HOST'];
$db_usuario = $_ENV['DB_USERNAME'];
$db_contrasena = $_ENV['DB_PASSWORD'];
$base_datos = $_ENV['DB_DATABASE'];
$port = $_ENV['DB_PORT'];

$conexion = new mysqli($db_host, $db_usuario, $db_contrasena, $base_datos, $port);

if ($conexion->connect_error) {
    die($conexion->connect_error);
}

$conexion->set_charset("utf8");
?>