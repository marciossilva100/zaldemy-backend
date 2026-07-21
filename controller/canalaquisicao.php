<?php
// ini_set('display_errors', 1);
// ini_set('display_startup_errors', 1);
// error_reporting(E_ALL);

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

require_once '../model/CanalAquisicao.php';
require_once 'authMiddleware.php';

// lê JSON do body
$input = json_decode(file_get_contents('php://input'), true);

// action enviada pelo React
$action = $input['action'] ?? null;

$canal = new CanalAquisicao;

try {

    if ($action === 'register_channel') {

        $canal->rede_social = $input['rede_social'] ?? null;

        if (!$canal->rede_social) {
            http_response_code(400);
            echo json_encode(["error" => "Rede social não informada"]);
            exit;
        }

        $dados = $canal->registrarCanal($user_id);

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