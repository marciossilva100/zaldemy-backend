<?php
date_default_timezone_set('America/Sao_Paulo');

//$pass = "@Sdsfsfsfs8f7sdf78sd57f8sd9fs87fs8f";
$host = "localhost";
// $db   = "memly";
// $user = "root";
// $pass = "h@cker02";

$db   = "u712858045_zaldemy";
$user = "u712858045_mhp";
$pass = "u2Kd@Hl@0z&";

try {
    $pdo = new PDO(
        "mysql:host=$host;dbname=$db;charset=utf8mb4",
        $user,
        $pass,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
        ]
    );
    $pdo->exec("SET time_zone = '-03:00'");
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        "error" => $e->getMessage()
    ]);
    exit;
}
