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
    error_log("[libre-translate] erro inesperado: " . $e->getMessage() . " em " . $e->getFile() . ":" . $e->getLine());
    http_response_code(500);
    echo json_encode([
        "error" => "Erro no servidor",
    ]);
}
