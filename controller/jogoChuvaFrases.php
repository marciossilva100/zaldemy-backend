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
require_once '../model/JogoChuvaFrases.php';
require_once '../model/PlanoLimitado.php';

header('Content-Type: application/json');

$input = json_decode(file_get_contents('php://input'), true);
$action = $input['action'] ?? null;
$categoriaId = (int) ($input['category_id'] ?? 0);
$plano = (int) ($user['plano'] ?? 0);

try {

    // Checagem de plano/amostra vitalícia - não depende de categoria (o jogo
    // pode juntar frases de várias categorias numa partida só), por isso vem
    // antes da exigência de category_id das outras actions.
    // ATENÇÃO: registra uso (gasta amostra do plano limitado) - só chamar
    // quando o usuário está de fato iniciando uma partida. Pra só CONSULTAR
    // se está bloqueado sem gastar amostra (ex: mostrar coroa no ícone do
    // jogo na Home), usar a action 'status_acesso' abaixo.
    if ($action === 'verificar_acesso') {
        $bloqueio = JogoChuvaFrases::verificarAcesso($pdo, $user_id, $plano);

        if ($bloqueio !== null) {
            echo json_encode($bloqueio);
            exit;
        }

        JogoChuvaFrases::registrarUso($pdo, $user_id);
        PlanoLimitado::verificarEDowngradear($pdo, $user_id, $plano);

        echo json_encode(["success" => true]);
        exit;
    }

    // Só consulta se o acesso está bloqueado, sem registrar uso nenhum -
    // seguro pra chamar a qualquer momento (ex: ao carregar a Home).
    if ($action === 'status_acesso') {
        $bloqueio = JogoChuvaFrases::verificarAcesso($pdo, $user_id, $plano);
        echo json_encode([
            "success" => true,
            "bloqueado" => $bloqueio !== null,
            "message" => $bloqueio['message'] ?? null,
        ]);
        exit;
    }

    if (!$categoriaId) {
        http_response_code(400);
        echo json_encode(["success" => false, "message" => "category_id obrigatório"]);
        exit;
    }

    if (!JogoChuvaFrases::categoriaPertenceAoUsuario($pdo, $user_id, $categoriaId)) {
        http_response_code(403);
        echo json_encode(["success" => false, "message" => "Categoria não encontrada"]);
        exit;
    }

    if ($action === 'buscar_recorde') {
        $recorde = JogoChuvaFrases::buscarRecorde($pdo, $user_id, $categoriaId);
        echo json_encode(["success" => true, "recorde" => $recorde]);
        exit;
    }

    if ($action === 'salvar_pontuacao') {
        $pontuacao = (int) ($input['pontuacao'] ?? 0);
        $recorde = JogoChuvaFrases::salvarPontuacao($pdo, $user_id, $categoriaId, $pontuacao);
        echo json_encode(["success" => true, "recorde" => $recorde]);
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
