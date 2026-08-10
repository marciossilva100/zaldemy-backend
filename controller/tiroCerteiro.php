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

require_once '../server.php';
require_once 'authMiddleware.php';
require_once '../model/TiroCerteiro.php';
require_once '../model/PlanoLimitado.php';
require_once '../model/Nivel.php';
require_once __DIR__ . '/../api/OpenAiChat.php';
require_once 'moderation.php';

header('Content-Type: application/json');

$input = json_decode(file_get_contents('php://input'), true);
$action = $input['action'] ?? null;
$plano = (int) ($user['plano'] ?? 0);

try {

    // Checagem de plano/cota diária - registra uso. Só chamar quando o
    // usuário está de fato iniciando uma partida. Pra só CONSULTAR se está
    // bloqueado sem gastar cota (ex: mostrar coroa no hub de jogos), usar
    // a action 'status_acesso' abaixo. Mesmo padrão de jogoChuvaFrases.php.
    if ($action === 'verificar_acesso') {
        $bloqueio = TiroCerteiro::verificarAcesso($pdo, $user_id, $plano);

        if ($bloqueio !== null) {
            echo json_encode($bloqueio);
            exit;
        }

        TiroCerteiro::registrarUso($pdo, $user_id);
        PlanoLimitado::verificarEDowngradear($pdo, $user_id, $plano);

        echo json_encode(["success" => true]);
        exit;
    }

    // Só consulta se o acesso está bloqueado, sem registrar uso nenhum -
    // seguro pra chamar a qualquer momento (ex: ao carregar o hub de jogos).
    if ($action === 'status_acesso') {
        $bloqueio = TiroCerteiro::verificarAcesso($pdo, $user_id, $plano);
        echo json_encode([
            "success" => true,
            "bloqueado" => $bloqueio !== null,
            "message" => $bloqueio['message'] ?? null,
        ]);
        exit;
    }

    // Gera o lote de rodadas da partida - chama a IA uma vez só (custo e
    // latência de uma chamada por tiro seriam inviáveis pro ritmo do jogo).
    // Não registra uso nem consome cota aqui - isso já foi feito por
    // 'verificar_acesso' antes do jogo começar de fato.
    if ($action === 'obter_rodadas') {
        if (TiroCerteiro::contarFrasesEstudadas($pdo, $user_id) < 3) {
            echo json_encode([
                "success" => false,
                "conteudo_insuficiente" => true,
                "message" => "Adicione mais frases aos flashcards para jogar o Tiro Certeiro.",
            ]);
            exit;
        }

        // gpt-5-mini (não o nano padrão) - mesmo motivo da Frase do Dia e
        // Perguntas: nano mistura fragmentos sem relação ao compor várias
        // rodadas novas a partir do corpus de frases do usuário.
        $chat = new OpenAiChat($_ENV['OPEN_AI'], "gpt-5-mini");
        $frases = TiroCerteiro::getFrasesDoUsuario($pdo, $user_id);
        $idioma = TiroCerteiro::getIdiomaAprendendo($pdo, $user_id);
        $idiomaNativo = TiroCerteiro::getIdiomaNativo($pdo, $user_id);
        $nivel = Nivel::obterNomeDoUsuario($pdo, $user_id);

        $rodadas = TiroCerteiro::gerarRodadas($pdo, $chat, $frases, $idioma, $idiomaNativo, $nivel);

        if (isset($rodadas['erro']) || empty($rodadas)) {
            echo json_encode(["success" => false, "message" => $rodadas['mensagem'] ?? "Não foi possível gerar as rodadas."]);
            exit;
        }

        echo json_encode(["success" => true, "rodadas" => $rodadas]);
        exit;
    }

    if ($action === 'buscar_recorde') {
        $recorde = TiroCerteiro::buscarRecorde($pdo, $user_id);
        echo json_encode(["success" => true, "recorde" => $recorde]);
        exit;
    }

    if ($action === 'salvar_pontuacao') {
        $pontuacao = (int) ($input['pontuacao'] ?? 0);
        $recorde = TiroCerteiro::salvarPontuacao($pdo, $user_id, $pontuacao);
        echo json_encode(["success" => true, "recorde" => $recorde]);
        exit;
    }

    http_response_code(400);
    echo json_encode(["success" => false, "message" => "Action inválida"]);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        "success" => false,
        "message" => $e->getMessage(),
    ]);
}
