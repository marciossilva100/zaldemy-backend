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

header('Content-Type: application/json');

$input = json_decode(file_get_contents('php://input'), true);
$action = $input['action'] ?? null;
$categoriaId = (int) ($input['category_id'] ?? 0);

try {

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
