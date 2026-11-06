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

header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once '../server.php';
require_once '../model/Dashboard.php';
require_once '../model/DashboardAuth.php';

// Dashboard administrativo: login PRÓPRIO (dashboardAuth.php), não o token
// de usuário do app - de propósito, pra ficar protegido mesmo se a conta
// pessoal do Zaldemy for comprometida. Ver DashboardAuth::validarSessaoAtiva.
$headers = getallheaders();
$authHeader = $headers['Authorization'] ?? $headers['authorization'] ?? $_SERVER['HTTP_AUTHORIZATION'] ?? null;
$adminToken = $authHeader ? str_replace('Bearer ', '', $authHeader) : null;

if (!$adminToken || !DashboardAuth::validarSessaoAtiva($pdo, $adminToken)) {
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
            'atividade_diaria' => $dashboardModel->getAtividadeDiariaPorTipo($pdo, $dias),
            'uso_por_modo_treino' => $dashboardModel->getUsoPorModoTreino($pdo, $dias),
        ];
    } elseif ($action === 'listar_usuarios') {
        $pagina = (int) ($input['pagina'] ?? 1);
        $porPagina = (int) ($input['por_pagina'] ?? 20);
        $busca = trim((string) ($input['busca'] ?? ''));

        $response = $dashboardModel->getUsuarios($pdo, $pagina, $porPagina, $busca);

    } elseif ($action === 'detalhes_usuario') {
        $userId = (int) ($input['user_id'] ?? 0);

        if ($userId <= 0) {
            http_response_code(422);
            echo json_encode(["error" => "user_id obrigatório"]);
            exit;
        }

        $detalhes = $dashboardModel->getDetalhesUsuario($pdo, $userId, 30);

        if (!$detalhes) {
            http_response_code(404);
            echo json_encode(["error" => "Usuário não encontrado"]);
            exit;
        }

        $response = $detalhes;

    } else {
        http_response_code(400);
        echo json_encode(["error" => "Action inválida: $action"]);
        exit;
    }

    echo json_encode($response);

} catch (Throwable $e) {
    error_log("[dashboard] erro inesperado: " . $e->getMessage() . " em " . $e->getFile() . ":" . $e->getLine());
    http_response_code(500);
    echo json_encode([
        "error" => "Erro no servidor",
    ]);
}
