<?php

$allowedOrigins = [
    "http://localhost:5173",
    "https://zaldemy.com",
    "https://www.zaldemy.com",
    "https://www.hml.zaldemy.com",
    "https://hml.zaldemy.com",
    "https://memly-jijk.vercel.app",
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
require_once '../model/Assinatura.php';
require_once 'authMiddleware.php';

// Fluxo de assinatura só faz sentido na versão web - o app Android usa
// Google Play Billing, nunca deve abrir esse checkout (ver PremiumModal.jsx
// no frontend, que já esconde esse botão quando roda dentro do app nativo).

$input = json_decode(file_get_contents('php://input'), true);
$action = $input['action'] ?? null;

try {

    if ($action === 'criar_checkout') {
        $dados = Assinatura::criarCheckoutSession($pdo, $user_id, $user['email']);
        echo json_encode($dados);
        exit;
    }

    if ($action === 'cancelar') {
        $dados = Assinatura::cancelarAssinatura($pdo, $user_id);
        echo json_encode($dados);
        exit;
    }

    if ($action === 'reativar') {
        $dados = Assinatura::reativarAssinatura($pdo, $user_id);
        echo json_encode($dados);
        exit;
    }

    http_response_code(400);
    echo json_encode(["success" => false, "message" => "Action inválida"]);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        "success" => false,
        "message" => $e->getMessage(),
    ]);
}
