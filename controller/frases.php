<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
$allowedOrigins = [
    "http://localhost:5173",
    "https://zaldemy.com",
    "https://www.zaldemy.com",
    "https://memly-jijk.vercel.app"
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
require_once 'authMiddleware.php';
require_once '../model/Frases.php';

// lê JSON do body
$input = json_decode(file_get_contents('php://input'), true);

// action vem do POST JSON
$action = $input['action'] ?? null;
$frase = new Frases;

try {

    if ($action === 'frases') {
        $frase->categoriaId = $input['category_id'] ?? null;

        if (!$frase->categoriaId) {
            http_response_code(400);
            echo json_encode(["error" => "categoria_id obrigatório"]);
            exit;
        }

        $response = $frase->listarFrases($user_id);

        echo json_encode($response);
        exit;
    }

    if ($action === 'frasesgeral') {
        $frase->categoriaId = $input['category_id'] ?? null;

        if (!$frase->categoriaId) {
            http_response_code(400);
            echo json_encode(["error" => "categoria_id obrigatório"]);
            exit;
        }

        $response = $frase->getAll();

        echo json_encode($response);
        exit;
    }


    if ($action === 'learn') {
        $frase->categoriaId = $input['category_id'] ?? null;

        if (!$frase->categoriaId) {
            http_response_code(400);
            echo json_encode(["error" => "categoria_id obrigatório"]);
            exit;
        }

        $response = $frase->listarFrasesAprender($user_id);

        echo json_encode($response);
        exit;
    }

    if ($action === 'review') {
        $frase->categoriaId = $input['category_id'] ?? null;

        if (!$frase->categoriaId) {
            http_response_code(400);
            echo json_encode(["error" => "categoria_id obrigatório"]);
            exit;
        }

        $response = $frase->listarFrasesReview($user_id);

        echo json_encode($response);
        exit;
    }

    if ($action === 'delete_phrase') {

        $frase->id = $input['id_phrase'] ?? null;

        if (!$frase->id) {
            http_response_code(400);
            echo json_encode(["error" => "id obrigatório"]);
            exit;
        }

        $response = $frase->excluirFrase($user_id);

        echo json_encode($response);
        exit;

    }

     if ($action === 'add_phrase') {
        $frase->categoriaId = $input['category_id'] ?? null;
        $frase->texto_nativo = $input['phrase'] ?? null;
        $frase->texto_traduzido = $input['translatedPhrase'] ?? null;

         if (!$frase->texto_nativo) {
            http_response_code(400);
            echo json_encode(["error" => "Frase obrigatório"]);
            exit;
        }

         if (!$frase->texto_traduzido) {
            http_response_code(400);
            echo json_encode(["error" => "Frase obrigatório"]);
            exit;
        }

        if (!$frase->categoriaId) {
            http_response_code(400);
            echo json_encode(["error" => "categoria_id obrigatório"]);
            exit;
        }
        $response = $frase->addFrases($user_id);

        echo json_encode($response);
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
        "file" => $e->getFile(),
        "line" => $e->getLine()
    ]);
}
