<?php
// ini_set('display_errors', 1);
// ini_set('display_startup_errors', 1);
// error_reporting(E_ALL);
// ini_set('display_errors', 0);
// error_reporting(0);

$allowedOrigins = [
    "http://localhost:5173",
    "https://zaldemy.com",
    "https://www.zaldemy.com",
    "https://www.hml.zaldemy.com",
    "https://hml.zaldemy.com",
    "https://memly-jijk.vercel.app"
];

if (isset($_SERVER['HTTP_ORIGIN']) && in_array($_SERVER['HTTP_ORIGIN'], $allowedOrigins)) {
    header("Access-Control-Allow-Origin: " . $_SERVER['HTTP_ORIGIN']);
}
header('Content-Type: application/json');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}


require_once '../model/Idioma.php';
require_once 'authMiddleware.php';

// lê JSON do body
$input = json_decode(file_get_contents('php://input'), true);

// action vem do POST JSON
$action = $input['action'] ?? null;
$idioma = new Idioma;

try {

    if ($action === 'list_languages') {
        $dados = $idioma->listarIdiomas(null,$user_id);
        echo json_encode($dados);
        exit;
    }

     if ($action === 'list_languages_learning') {
        $dados = $idioma->listarIdiomas('learning',$user_id);
        echo json_encode($dados);
        exit;
    }

    if ($action === 'set_native_language') {
        $idioma->idioma_nativo = $input['native_language'] ?? null;
        $dados = $idioma->setIdiomaNativo($user_id);
        echo json_encode($dados);
        exit;
    }

     if ($action === 'set_learning_language') {
        $idioma->idioma_aprender = $input['learning_language'] ?? null;
        $dados = $idioma->setIdiomaAprender($user_id);
        echo json_encode($dados);
        exit;
    }

    if ($action === 'update_learning_reference') {
        $idioma->idioma_aprender = $input['learning_language'] ?? null;
        $idioma->idioma_nativo = $input['learning_native'] ?? $input['native_language'] ?? null;
        $dados = $idioma->setIdiomaReferencia($user_id);
        echo json_encode($dados);
        exit;
    }

    http_response_code(400);
    echo json_encode(["error" => "Action inválida"]);

 } catch (Throwable $e) {
    http_response_code(500);
    // Retorna o erro real para debug
    echo json_encode([
        "error" => "Erro no servidor",
        "message" => $e->getMessage(),
    ]);
}
