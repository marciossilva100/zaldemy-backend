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

header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once '../server.php';
require_once 'authMiddleware.php';
require_once '../vendor/autoload.php';
require_once '../model/PushNotification.php';

use Minishlink\WebPush\WebPush;

header('Content-Type: application/json');

$input = json_decode(file_get_contents('php://input'), true);
$action = $input['action'] ?? null;

try {

    // Salva/atualiza a subscription desse dispositivo/navegador - o usuário
    // pode ter mais de uma (ex: celular + desktop), cada uma é independente.
    if ($action === 'registrar_subscription') {
        $endpoint = $input['endpoint'] ?? null;
        $p256dh = $input['keys']['p256dh'] ?? null;
        $auth = $input['keys']['auth'] ?? null;

        if (!$endpoint || !$p256dh || !$auth) {
            http_response_code(400);
            echo json_encode(["success" => false, "message" => "Subscription inválida."]);
            exit;
        }

        $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? null;
        PushNotification::salvarSubscription($pdo, $user_id, $endpoint, $p256dh, $auth, $userAgent);

        echo json_encode(["success" => true]);
        exit;
    }

    // Remove a subscription desse dispositivo/navegador (usuário desativou
    // as notificações, ou o browser invalidou a subscription local).
    if ($action === 'remover_subscription') {
        $endpoint = $input['endpoint'] ?? null;

        if (!$endpoint) {
            http_response_code(400);
            echo json_encode(["success" => false, "message" => "Endpoint não informado."]);
            exit;
        }

        PushNotification::removerSubscription($pdo, $user_id, $endpoint);

        echo json_encode(["success" => true]);
        exit;
    }

    // Manda uma notificação de teste só pro próprio usuário autenticado -
    // pra confirmar que a cadeia inteira funciona (subscription salva,
    // chaves VAPID certas, service worker recebendo) sem precisar de
    // acesso ao terminal do servidor pra rodar o cron manualmente.
    if ($action === 'enviar_teste') {
        $subscriptions = PushNotification::listarSubscriptions($pdo, $user_id);

        if (empty($subscriptions)) {
            echo json_encode(["success" => false, "message" => "Nenhuma notificação ativada nesse dispositivo/conta ainda."]);
            exit;
        }

        $webPush = new WebPush([
            'VAPID' => [
                'subject' => $_ENV['VAPID_SUBJECT'],
                'publicKey' => $_ENV['VAPID_PUBLIC_KEY'],
                'privateKey' => $_ENV['VAPID_PRIVATE_KEY'],
            ],
        ]);

        $resultados = PushNotification::enviarParaUsuarioComDiagnostico(
            $pdo,
            $webPush,
            $user_id,
            'Notificação de teste',
            'Se você está vendo isso, as notificações do Zaldemy estão funcionando!',
            '/home'
        );

        $algumSucesso = array_reduce($resultados, fn($carry, $r) => $carry || $r['sucesso'], false);

        echo json_encode([
            "success" => $algumSucesso,
            "resultados" => $resultados,
            "message" => $algumSucesso ? null : "Nenhum envio teve sucesso - veja 'resultados' pro motivo exato.",
        ]);
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
