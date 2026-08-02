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
require_once '../model/FraseDoDia.php';
require_once __DIR__ . '/../api/OpenAiChat.php';
require_once __DIR__ . '/../api/OpenAiTranscribe.php';

header('Content-Type: application/json');

$plano = (int) ($user['plano'] ?? 0);

// action vem via POST (JSON no "obter", multipart form-data no "responder")
$action = $_POST['action'] ?? null;

if ($action === null) {
    $input = json_decode(file_get_contents('php://input'), true) ?? [];
    $action = $input['action'] ?? null;
}

try {

    if ($action === 'obter') {
        $bloqueio = FraseDoDia::verificarAcesso($pdo, $user_id, $plano);

        if ($bloqueio !== null) {
            echo json_encode($bloqueio);
            exit;
        }

        $chat = new OpenAiChat($_ENV['OPEN_AI']);
        $idioma = FraseDoDia::getIdiomaAprendendo($pdo, $user_id);

        $resultado = FraseDoDia::obterFraseDoDia($pdo, $chat, $user_id, $idioma);

        echo json_encode($resultado);
        exit;
    }

    if ($action === 'responder') {
        $bloqueio = FraseDoDia::verificarAcesso($pdo, $user_id, $plano);

        if ($bloqueio !== null) {
            echo json_encode($bloqueio);
            exit;
        }

        $fraseId = (int) ($_POST['frase_id'] ?? 0);

        if (!$fraseId) {
            http_response_code(400);
            echo json_encode(["success" => false, "message" => "frase_id obrigatório"]);
            exit;
        }

        if (empty($_FILES['audio']) || $_FILES['audio']['error'] !== UPLOAD_ERR_OK) {
            http_response_code(400);
            echo json_encode(["success" => false, "message" => "Áudio obrigatório"]);
            exit;
        }

        $chat = new OpenAiChat($_ENV['OPEN_AI']);
        $transcribe = new OpenAiTranscribe($_ENV['OPEN_AI']);

        $resultado = FraseDoDia::responder(
            $pdo,
            $chat,
            $transcribe,
            $user_id,
            $fraseId,
            $_FILES['audio']['tmp_name'],
            $_FILES['audio']['type'] ?: 'audio/webm'
        );

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
