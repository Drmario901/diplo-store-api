<?php 
header("HTTP/1.1 403 Forbidden");
header("Content-Type: application/json");

echo json_encode([
    "error" => 403,
    "message" => "Unauthorized"
]);

exit();
