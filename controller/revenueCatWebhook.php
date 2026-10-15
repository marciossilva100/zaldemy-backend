<?php

// Endpoint chamado diretamente pelo RevenueCat (não pelo app) - sem
// autenticação por token de usuário, a validação de quem está chamando é
// feita pelo header Authorization (segredo configurado no dashboard do
// RevenueCat, ver RevenueCat::autenticarRequisicao). Mesmo papel de
// controller/stripeWebhook.php, pra assinatura comprada dentro do app
// Android via Google Play Billing em vez de pelo site via Stripe.

require_once '../server.php';
require_once '../model/RevenueCat.php';

header('Content-Type: application/json');

if (!RevenueCat::autenticarRequisicao()) {
    http_response_code(401);
    echo json_encode(["success" => false, "message" => "Não autorizado."]);
    exit;
}

$payload = json_decode(file_get_contents('php://input'), true);

if (!is_array($payload)) {
    http_response_code(400);
    echo json_encode(["success" => false, "message" => "Payload inválido."]);
    exit;
}

try {
    $resultado = RevenueCat::processarWebhook($pdo, $payload);

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
