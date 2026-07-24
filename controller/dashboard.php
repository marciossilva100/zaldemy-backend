<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

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

header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once '../server.php';
require_once '../model/Dashboard.php';
require_once 'authMiddleware.php';

// Dashboard administrativo: acesso restrito a e-mails autorizados
$adminEmails = [
    'marciosunico18@gmail.com',
];

if (!in_array($user['email'] ?? '', $adminEmails, true)) {
    http_response_code(403);
    echo json_encode(["error" => "Acesso não autorizado"]);
    exit;
}

$input = $_SERVER['REQUEST_METHOD'] === 'POST'
    ? json_decode(file_get_contents('php://input'), true)
    : $_GET;

$action = $input['action'] ?? 'dashboard';

$dashboardModel = new Dashboard();

try {
    if ($action === 'dashboard') {
        $dias = (int) ($input['dias'] ?? 30);

        $response = [
            'resumo' => $dashboardModel->getResumo($pdo),
            'crescimento' => $dashboardModel->getCrescimentoUsuarios($pdo, $dias),
            'usuarios_por_plano' => $dashboardModel->getUsuariosPorPlano($pdo),
            'canais_aquisicao' => $dashboardModel->getCanaisAquisicao($pdo),
            'idiomas_mais_aprendidos' => $dashboardModel->getIdiomasMaisAprendidos($pdo),
        ];
    } else {
        http_response_code(400);
        echo json_encode(["error" => "Action inválida: $action"]);
        exit;
    }

    echo json_encode($response);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        "error" => "Erro no servidor",
        "message" => $e->getMessage(),
        "file" => $e->getFile(),
        "line" => $e->getLine()
    ]);
}
