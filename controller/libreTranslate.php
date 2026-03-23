<?php

$allowedOrigins = [
    "http://localhost:5173",
    "https://zaldemy.com",
    "https://www.zaldemy.com",
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


// lê JSON do body
$input = json_decode(file_get_contents('php://input'), true);

// action vem do POST JSON
$action = $input['action'] ?? null;

require_once '../api/LibreTranslate.php';


$libre = new LibreTranslate;


try {

    $libre->text = $input['phrase'];
    $libre->sourceLang = $input['sourceLang'];
    $libre->targetLang = $input['targetLang'];
    $dados = $libre->translateText();

    echo json_encode($dados);
  
 } catch (Throwable $e) {
    http_response_code(500);
    // Retorna o erro real para debug
    echo json_encode([
        "error" => "Erro no servidor",
        "message" => $e->getMessage(),
        "file" => $e->getFile(),
        "line" => $e->getLine()
    ]);
}
