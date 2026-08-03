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

header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once __DIR__ . '/../dotenv.php';
carregarEnv(__DIR__ . '/../.env');

if (!isset($_ENV['OPEN_AI'])) {
    header('Content-Type: application/json');
    die(json_encode(["success" => false, "message" => "API KEY não configurada"]));
}

require_once '../server.php';
require_once 'authMiddleware.php';
require_once '../model/Categorias.php';
require_once '../model/CategoriaIA.php';
require_once '../model/PlanoLimitado.php';
require_once __DIR__ . '/../api/OpenAiChat.php';
require_once 'moderation.php';

header('Content-Type: application/json');

$input = json_decode(file_get_contents('php://input'), true);
$action = $input['action'] ?? null;
$plano = (int) ($user['plano'] ?? 0);

try {

    if ($action === 'criar') {
        $topico = trim($input['categoria'] ?? '');
        $categoriaPublica = (int) ($input['categoria_publica'] ?? 0);

        if ($topico === '') {
            http_response_code(400);
            echo json_encode(["success" => false, "message" => "Informe o tema da categoria"]);
            exit;
        }

        $chat = new OpenAiChat($_ENV['OPEN_AI']);

        $resultado = CategoriaIA::criarComIA($pdo, $chat, $user_id, $plano, $topico, $categoriaPublica);

        if ($resultado['success']) {
            PlanoLimitado::verificarEDowngradear($pdo, $user_id, $plano);
        }

        echo json_encode($resultado);
        exit;
    }

    http_response_code(400);
    echo json_encode(["success" => false, "message" => "Action inválida"]);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        "success" => false,
        "message" => $e->getMessage(),
        "file" => $e->getFile(),
        "line" => $e->getLine()
    ]);
}
