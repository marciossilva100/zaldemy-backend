<?php

$allowedOrigins = [
    "http://localhost:5173",
    "https://zaldemy.com",
    "https://www.zaldemy.com",
    "https://www.hml.zaldemy.com",
    "https://hml.zaldemy.com",
    "https://memly-jijk.vercel.app",
    "https://localhost", // app nativo Android/iOS via Capacitor
    "capacitor://localhost" // WKWebView do Capacitor no iOS
];

if (isset($_SERVER['HTTP_ORIGIN']) && in_array($_SERVER['HTTP_ORIGIN'], $allowedOrigins)) {
    header("Access-Control-Allow-Origin: " . $_SERVER['HTTP_ORIGIN']);
}

header('Content-Type: application/json');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once '../server.php';
require_once '../model/Nivel.php';
require_once 'authMiddleware.php';

// lê JSON do body
$input = json_decode(file_get_contents('php://input'), true);

$action = $input['action'] ?? null;

try {

    if ($action === 'set_level') {
        $nivel = (int) ($input['nivel'] ?? 0);

        if (!$nivel) {
            http_response_code(400);
            echo json_encode(["success" => false, "message" => "Nível não informado"]);
            exit;
        }

        $dados = Nivel::registrar($pdo, $user_id, $nivel);
        echo json_encode($dados);
        exit;
    }

    http_response_code(400);
    echo json_encode(["error" => "Action inválida"]);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        "error" => "Erro no servidor",
        "message" => $e->getMessage(),
    ]);
}
