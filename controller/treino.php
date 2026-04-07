<?php

// ini_set('display_errors', 1);
// ini_set('display_startup_errors', 1);
// error_reporting(E_ALL);

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

header('Access-Control-Allow-Methods: GET, POST, OPTIONS, HEAD');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}
$input = json_decode(file_get_contents('php://input'), true);
$action = $input['action'] ?? $_GET['action'] ?? null;

require_once '../model/Treino.php';
if ($action !== 'voice') {
    require_once 'authMiddleware.php';
}
require_once '../api/LibreTranslate.php';

// lê JSON do body

// action vem do POST JSON
$action = $input['action'] ?? $_GET['action'] ?? null;
$treino = new Treino;

try {

    // if($action == 'learn'){
    //     $treino->updatedIncorrectList = $input['updatedList'] ?? null;
    //     $treino->category_id = $input['category_id'] ?? null;

    //     $dados['correct'] = $treino->treino(2,$user_id);

    //     echo json_encode($dados);
    //     exit;
    // }

    // if ($action === 'trainee_finish') {

    //     $treino->updatedIncorrectList = $input['frase_id'] ?? null;
    //     $treino->category_id = $input['category_id'] ?? null;

    //     $dados = [];

    //     $dados['correct'] = $treino->treino(4,$user_id);
        
    //     // if (!empty($input['updatedIncorrectList'])) {
    //     //     $treino->updatedList = $input['updatedIncorrectList'] ?? null;
    //     //     $dados['incorrect'] = $treino->treino(1,$user_id);
    //     // }

    //     echo json_encode($dados);
    //     exit;
    // }

    // if ($action === 'review') {

    //     $treino->updatedList = $input['updatedList'] ?? null;
    //     $treino->category_id = $input['category_id'] ?? null;

    //     $dados = [];
        
    //     if (!empty($input['updatedIncorrectList'])) {
    //         $treino->updatedList = $input['updatedIncorrectList'] ?? null;
    //         $dados['incorrect'] = $treino->treino(2,$user_id);
    //     }

    //     echo json_encode($dados);
    //     exit;
    // }

    if ($action === 'review' || $action === 'trainee_finish' || $action === 'learn') {

        $treino->acerto = $input['statusCorrectPhrase'];

        $tipo_treino = 2;

        if($action === 'learn'){
            if(empty($treino->acerto))
                $tipo_treino = 1;
        } 

        if($action === 'trainee_finish'){
            if(!empty($treino->acerto))
                $tipo_treino = 4;
            else{
                $tipo_treino = 1;
            }
        } 

        if($action === 'review'){
            if(!empty($treino->acerto)){
                $tipo_treino = 4;
            }
        } 

        $treino->updatedList = $input['frase_id'] ?? [];
       // $treino->updatedIncorrectList = $input['updatedIncorrectList'] ?? [];
        $treino->category_id = $input['category_id'] ?? null;

        // $treino->acertos = $input['acertos'] ?? 0;
        // $treino->erros = $input['erros'] ?? 0;
        // $treino->total = $input['total'] ?? 0;
        // $treino->porcentagem = $input['porcentagem'] ?? 0;

        $dados = $treino->treino($tipo_treino, $user_id);
        $treino->metricasFrase($user_id);

        echo json_encode($dados);
        exit;

    }


    // if($action == 'update_repeat'){
    //     $treino->category_id = $input['category_id'] ?? null;

    //     $dados = $treino->updateRepeat();
    //     echo json_encode($dados);
    //     exit;
    // }

    if($action == 'update_repeat'){
        $treino->category_id = $input['category_id'] ?? null;
        $set_id_treino = 3;
        $id_treino = 2;

        $dados = $treino->updateRepeat($set_id_treino,$id_treino,$user_id);
        echo json_encode($dados);
        exit;
    }


    if($action == 'retornarTreino'){
        $dados = $treino->retornarTreino(4,$user_id);
        echo json_encode($dados);
        exit;
    }



    if ($action === 'traine') {
        $treino->category_id = $input['category_id'] ?? null;

        if (!$treino->category_id) {
            http_response_code(400);
            echo json_encode(["error" => "categoria_id obrigatório"]);
            exit;
        }

        $response = $treino->repeatPhrases($user_id);

        echo json_encode($response);
        exit;
    }

   if ($action === 'voice') {
    // 🔥 LIMPA QUALQUER SAÍDA ANTERIOR
    if (ob_get_level()) ob_end_clean();
    
    $texto = $input['text'] ?? $_GET['text'] ?? null;
    $lang  = $input['lang'] ?? $_GET['lang'] ?? null;

    if (!$texto || !$lang) {
        http_response_code(400);
        exit;
    }

    $translate = new LibreTranslate();
    $translate->text = $texto;
    $audio = $translate->getAudio($lang);

    if (!$audio) {
        http_response_code(500);
        exit;
    }

    // 🔥 LIMPA NOVAMENTE ANTES DOS HEADERS
    while (ob_get_level()) ob_end_clean();
    
    header("Content-Type: audio/mpeg");
    header("Content-Length: " . strlen($audio));
    header("Cache-Control: no-cache");
    
    echo $audio;
    exit;
}
    if($action == 'training_stats'){

        $treino->category_id = $input['category_id'] ?? null;
        $dados = $treino->estatisticasTreino($user_id);
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
        "file" => $e->getFile(),
        "line" => $e->getLine()
    ]);
}
