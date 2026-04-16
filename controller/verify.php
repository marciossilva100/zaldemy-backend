<?php

$allowedOrigins = [
    "http://localhost:5173",
    "https://zaldemy.com",
    "https://www.zaldemy.com",
    "https://www.hml.zaldemy.com",
    "https://memly-jijk.vercel.app",
    "https://hml.zaldemy.com"
];

if (isset($_SERVER['HTTP_ORIGIN']) && in_array($_SERVER['HTTP_ORIGIN'], $allowedOrigins)) {
    header("Access-Control-Allow-Origin: " . $_SERVER['HTTP_ORIGIN']);
}
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Content-Type: application/json; charset=utf-8");

// Responde preflight
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once '../server.php';
require_once 'authMiddleware.php';

$token = $_GET['token'] ?? null;

if (!$token) {
    echo json_encode([
        "success" => false,
        "error" => "Token não informado"
    ]);
    exit;
}

$stmt = $pdo->prepare("
    SELECT id FROM usuarios 
    WHERE email_token = :token 
    AND email_verified = 0
    LIMIT 1
");

$stmt->bindParam(":token", $token);
$stmt->execute();

$user = $stmt->fetch();

if (!$user) {
    echo json_encode([
        "success" => false,
        "error" => "Token inválido ou já utilizado"
    ]);
    exit;
}

$stmt = $pdo->prepare("
    UPDATE usuarios 
    SET email_verified = 1,
        email_token = NULL
    WHERE id = :id
");

$stmt->bindParam(":id", $user['id']);
$stmt->execute();

echo json_encode([
    "success" => true
]);


exit;
