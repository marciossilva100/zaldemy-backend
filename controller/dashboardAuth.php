<?php

// Login do /dashboard-admin - propositalmente SEM passar por
// authMiddleware.php (token de usuário do app). É uma conta própria,
// guardada em dashboard_admin_contas, sem relação com a tabela `usuarios`.
$allowedOrigins = [
    "http://localhost:5173",
    "https://zaldemy.com",
    "https://www.zaldemy.com",
    "https://www.hml.zaldemy.com",
    "https://hml.zaldemy.com",
    "https://memly-jijk.vercel.app",
    "https://localhost",
    "capacitor://localhost"
];

if (isset($_SERVER['HTTP_ORIGIN']) && in_array($_SERVER['HTTP_ORIGIN'], $allowedOrigins)) {
    header("Access-Control-Allow-Origin: " . $_SERVER['HTTP_ORIGIN']);
}

header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once '../server.php';
require_once '../model/DashboardAuth.php';

$input = json_decode(file_get_contents('php://input'), true) ?? [];
$action = $input['action'] ?? null;

try {
    if ($action === 'criar_conta_inicial') {
        $usuario = (string) ($input['usuario'] ?? '');
        $senha = (string) ($input['senha'] ?? '');

        $response = DashboardAuth::criarContaInicial($pdo, $usuario, $senha);

    } elseif ($action === 'login') {
        $usuario = (string) ($input['usuario'] ?? '');
        $senha = (string) ($input['senha'] ?? '');

        if ($usuario === '' || $senha === '') {
            http_response_code(422);
            echo json_encode(['success' => false, 'message' => 'Usuário e senha obrigatórios.']);
            exit;
        }

        $response = DashboardAuth::login($pdo, $usuario, $senha);

    } elseif ($action === 'verificar_totp') {
        $token = (string) ($input['token'] ?? '');
        $codigo = (string) ($input['codigo'] ?? '');

        if ($token === '' || $codigo === '') {
            http_response_code(422);
            echo json_encode(['success' => false, 'message' => 'Token e código obrigatórios.']);
            exit;
        }

        $response = DashboardAuth::verificarTotp($pdo, $token, $codigo);

    } elseif ($action === 'logout') {
        $token = (string) ($input['token'] ?? '');
        DashboardAuth::logout($pdo, $token);
        $response = ['success' => true];

    } else {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => "Action inválida: $action"]);
        exit;
    }

    echo json_encode($response);

} catch (Throwable $e) {
    error_log("[dashboardAuth] erro inesperado: " . $e->getMessage() . " em " . $e->getFile() . ":" . $e->getLine());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Erro no servidor.']);
}
