<?php

// Endpoint chamado diretamente pelo Stripe (não pelo app) - sem
// autenticação por token, a validação de quem está chamando é feita pela
// assinatura no header Stripe-Signature (Assinatura::processarWebhook).

require_once '../server.php';
require_once '../model/Assinatura.php';

header('Content-Type: application/json');

$payload = file_get_contents('php://input');
$sigHeader = $_SERVER['HTTP_STRIPE_SIGNATURE'] ?? '';

try {
    $resultado = Assinatura::processarWebhook($pdo, $payload, $sigHeader);

    if (!$resultado['success']) {
        http_response_code(400);
    }

    echo json_encode($resultado);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        "success" => false,
        "message" => $e->getMessage(),
    ]);
}
