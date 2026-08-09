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

header('Content-Type: application/json');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once '../server.php';
require_once 'authMiddleware.php';
require_once '../model/Configuracoes.php';
require_once '../model/Auth.php';
require_once '../model/AcessoUsuario.php';
require_once '../model/Apelido.php';


// lê JSON do body
$input = json_decode(file_get_contents('php://input'), true);

// action vem do POST JSON
$action = $input['action'] ?? null;

try {

    if ($action === 'obter') {
        $dados = Configuracoes::getConfiguracoes($pdo, $user_id);

        echo json_encode($dados);
        exit;
    }

    if ($action === 'atualizar_quantidade_frases_aprender') {
        $quantidade = $input['quantidade_frases_aprender'] ?? null;

        if ($quantidade === null) {
            http_response_code(400);
            echo json_encode(["error" => "quantidade_frases_aprender obrigatória"]);
            exit;
        }

        $dados = Configuracoes::atualizarQuantidadeFrasesAprender($pdo, $user_id, $quantidade);

        if (!$dados['success']) {
            http_response_code(400);
        }

        echo json_encode($dados);
        exit;
    }

    if ($action === 'atualizar_velocidade_tts') {
        $velocidade = $input['velocidade_tts'] ?? null;

        if ($velocidade === null) {
            http_response_code(400);
            echo json_encode(["error" => "velocidade_tts obrigatória"]);
            exit;
        }

        $dados = Configuracoes::atualizarVelocidadeTts($pdo, $user_id, $velocidade);

        if (!$dados['success']) {
            http_response_code(400);
        }

        echo json_encode($dados);
        exit;
    }

    if ($action === 'atualizar_voz_tts') {
        $voz = $input['voz_tts'] ?? null;

        if (!$voz) {
            http_response_code(400);
            echo json_encode(["error" => "voz_tts obrigatória"]);
            exit;
        }

        $dados = Configuracoes::atualizarVozTts($pdo, $user_id, $voz);

        if (!$dados['success']) {
            http_response_code(400);
        }

        echo json_encode($dados);
        exit;
    }

    if ($action === 'atualizar_apelido') {
        $apelidoDesejado = trim((string) ($input['apelido'] ?? ''));

        if ($apelidoDesejado === '') {
            http_response_code(400);
            echo json_encode(["success" => false, "message" => "Informe um apelido."]);
            exit;
        }

        $resultado = Apelido::normalizarOuGerar($pdo, $apelidoDesejado, $user_id);

        if (!$resultado['sucesso']) {
            http_response_code(400);
            echo json_encode(["success" => false, "message" => $resultado['erro']]);
            exit;
        }

        $stmt = $pdo->prepare("UPDATE usuarios SET apelido = :apelido, apelido_definido_pelo_usuario = 1 WHERE id = :id");
        $stmt->execute([':apelido' => $resultado['apelido'], ':id' => $user_id]);

        echo json_encode(["success" => true, "apelido" => $resultado['apelido']]);
        exit;
    }

    if ($action === 'excluir_conta') {
        $auth = new Auth($pdo);
        $dados = $auth->excluirConta($user_id);

        if (!$dados['success']) {
            http_response_code(400);
        }

        echo json_encode($dados);
        exit;
    }

    if ($action === 'historico_acessos') {
        echo json_encode([
            "success" => true,
            "acessos" => AcessoUsuario::listar($pdo, $user_id)
        ]);
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
