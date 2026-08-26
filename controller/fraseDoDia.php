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
require_once '../model/PlanoLimitado.php';
require_once '../model/Nivel.php';
require_once __DIR__ . '/../api/OpenAiChat.php';
require_once __DIR__ . '/../api/OpenAiTranscribe.php';
require_once 'moderation.php';

header('Content-Type: application/json');

$plano = (int) ($user['plano'] ?? 0);

// action vem via POST (JSON no "obter", multipart form-data no "responder")
$action = $_POST['action'] ?? null;

if ($action === null) {
    $input = json_decode(file_get_contents('php://input'), true) ?? [];
    $action = $input['action'] ?? null;
}

try {

    // Checagem leve (sem gerar nada) pro ModalIA decidir se mostra o selo de
    // premium no botão antes mesmo do usuário clicar - mesma regra de
    // verificarAcesso, incluindo a amostra vitalícia do limitado expirada.
    if ($action === 'verificar_acesso') {
        $bloqueio = FraseDoDia::verificarAcesso($pdo, $user_id, $plano);
        echo json_encode(["acesso" => $bloqueio === null]);
        exit;
    }

    if ($action === 'obter') {
        $bloqueio = FraseDoDia::verificarAcesso($pdo, $user_id, $plano);

        if ($bloqueio !== null) {
            echo json_encode($bloqueio);
            exit;
        }

        // gpt-5-mini só pra gerar a frase - testado direto na API, combina os
        // trechos das frases do aluno de forma bem mais coerente que o nano
        // nessa tarefa específica de "compor" texto novo a partir de várias
        // frases soltas.
        $chat = new OpenAiChat($_ENV['OPEN_AI'], "gpt-5-mini");
        $idioma = FraseDoDia::getIdiomaAprendendo($pdo, $user_id);
        $idiomaNativo = FraseDoDia::getIdiomaNativo($pdo, $user_id);
        $frases = FraseDoDia::getFrasesDoUsuario($pdo, $user_id);
        $nivel = FraseDoDia::getNivelNome($pdo, $user_id);

        $resultado = FraseDoDia::obterFraseDoDia($pdo, $chat, $user_id, $idioma, $idiomaNativo, $frases, $nivel);

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
            $_FILES['audio']['type'] ?: 'audio/webm',
            FraseDoDia::getIdiomaAprendendo($pdo, $user_id),
            FraseDoDia::getIdiomaNativo($pdo, $user_id)
        );

        if ($resultado['success']) {
            PlanoLimitado::verificarEDowngradear($pdo, $user_id, $plano);
        }

        echo json_encode($resultado);
        exit;
    }

    if ($action === 'historico') {
        // Limitado só vê a amostra grátis que ganhou no mesmo dia - no dia
        // seguinte ela some do histórico (é uma amostra vitalícia única, não
        // um recurso recorrente como o do premium, que mantém tudo pra sempre).
        $filtroLimitado = $plano === 3 ? " AND DATE(data_criacao) = CURDATE()" : "";

        // Mesmo raciocínio de DailyQuestionController::getHistorico() -
        // status_id=1 sem nota é uma frase que esgotou as tentativas sem
        // nunca ter sido avaliada (áudio vazio/conteúdo impróprio repetidos),
        // não deve sumir do histórico.
        $sql = "SELECT frase, transcricao, nota, feedback_gramatica, feedback_pronuncia, feedback_fluencia, data_criacao
                FROM frase_dia_ia
                WHERE user_id = :user_id AND status_id = 1{$filtroLimitado}
                ORDER BY id DESC
                LIMIT 30";

        $stmt = $pdo->prepare($sql);
        $stmt->execute([':user_id' => $user_id]);

        echo json_encode(["success" => true, "historico" => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
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
