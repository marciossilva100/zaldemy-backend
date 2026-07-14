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

require_once '../server.php';
require_once 'authMiddleware.php';
require_once '../model/Categorias.php';


// lê JSON do body
$input = json_decode(file_get_contents('php://input'), true);

// action vem do POST JSON
$action = $input['action'] ?? null;

try {
    if ($action === 'listar-com-quantidade') {
       
        $dados = Categorias::listarComQuantidade($pdo,$user_id);

        //função para adicionar frases manualmente
       $result = Categorias::addFrasesFromJson($pdo,47,__DIR__ . '/frases_convertidas.json');

        echo json_encode($dados);
        exit;
    }

    if ($action === 'get_all') {
        // Pega a página da requisição (GET ou POST)
        $page = isset($_REQUEST['page']) ? (int) $_REQUEST['page'] : 1;

        // Garante que não seja menor que 1
        $page = max($page, 1);

        $dados = Categorias::getAll($pdo,$user_id, $page);

        echo json_encode($dados);
        exit;
    }

    if ($action === 'adicionar_categoria') {
        $categoria = $input['categoria'] ?? null;
        $categoria_publica = $input['categoria_publica'] ?? null;

        if (!$categoria) {
            http_response_code(400);
            echo json_encode(["error" => "categoria obrigatória"]);
            exit;
        }

        // Agora retorna frases já com a URL do áudio
        $frases = Categorias::cadastrarCategoria($pdo,$categoria,$user_id,null,$categoria_publica);

        echo json_encode($frases);
        exit;
    }

    if ($action === 'editar_categoria') {
        $categoria_id = $input['categoria_id'] ?? null;
        $categoria = $input['categoria'] ?? null;

        if (!$categoria) {
            http_response_code(400);
            echo json_encode(["error" => "categoria obrigatória"]);
            exit;
        }

        // Agora retorna frases já com a URL do áudio
        $frases = Categorias::editarCategoria($pdo,$categoria_id,$categoria,$user_id);

        echo json_encode($frases);
        exit;
    }

    if ($action === 'excluir_categoria') {
        $categoria_id = $input['categoria_id'] ?? null;

        if (!$categoria_id) {
            http_response_code(400);
            echo json_encode(["error" => "categoria_id obrigatória"]);
            exit;
        }

        // Agora retorna frases já com a URL do áudio
        $frases = Categorias::excluirCategoria($pdo,$categoria_id,$user_id);

        echo json_encode($frases);
        exit;
    }

    if ($action === 'adicionar_compartilhado') {

        $categoria_id = $input['category_id'] ?? null;

        if (!$categoria_id) {
            http_response_code(400);
            echo json_encode(["error" => "categoria_id obrigatória"]);
            exit;
        }

        // Agora retorna frases já com a URL do áudio
        $categoria = Categorias::getById($pdo,$categoria_id);
  
        $categoria_id_usuario = Categorias::cadastrarCategoria($pdo,$categoria,$user_id,$categoria_id);

        $frases = Categorias::getAllFrases($pdo,$categoria_id);
        $response = Categorias::addFrases($pdo,$user_id, $frases,$categoria_id_usuario['id']);
       // print_r($response);


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
