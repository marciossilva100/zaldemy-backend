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

    // Checagem de plano/cota diária/cooldown - só CONSULTA, não registra uso.
    // Antes registrava uso aqui, mas isso gastava cota E cooldown mesmo
    // quando a tentativa nunca chegava a virar uma partida de verdade (ex:
    // 'obter_rodadas' bloqueando logo em seguida por conteúdo insuficiente) -
    // um usuário limitado podia estourar o teto diário de partidas sem ter
    // jogado nenhuma vez. Agora quem registra é 'obter_rodadas', e só depois
    // de confirmar que dá pra gerar a partida de fato.
    if ($action === 'verificar_acesso') {
        $bloqueio = TiroCerteiro::verificarAcesso($pdo, $user_id, $plano);

        if ($bloqueio !== null) {
            echo json_encode($bloqueio);
            exit;
        }

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
    // Reconfere o bloqueio de plano/cota/cooldown aqui (chamada separada de
    // 'verificar_acesso', pode ter mudado entre uma e outra) e só registra
    // uso quando o conteúdo é suficiente e a geração de fato vai ser
    // tentada - ver comentário em 'verificar_acesso' acima.
    if ($action === 'obter_rodadas') {
        $bloqueio = TiroCerteiro::verificarAcesso($pdo, $user_id, $plano);

        if ($bloqueio !== null) {
            echo json_encode($bloqueio);
            exit;
        }

        // Diferente da Frase do Dia/Perguntas, aqui não exige frase já
        // "estudada" - getFrasesDoUsuario() já prioriza as estudadas mas cai
        // pra qualquer frase cadastrada quando não há 3 estudadas (ver
        // model/TiroCerteiro.php), então o bloqueio só faz sentido quando
        // não sobra frase nenhuma pra IA usar como base.
        $frases = TiroCerteiro::getFrasesDoUsuario($pdo, $user_id);

        if (empty($frases)) {
            echo json_encode([
                "success" => false,
                "conteudo_insuficiente" => true,
                "message" => "Adicione frases aos flashcards para jogar o Tiro Certeiro.",
            ]);
            exit;
        }

        TiroCerteiro::registrarUso($pdo, $user_id);
        PlanoLimitado::verificarEDowngradear($pdo, $user_id, $plano);

        // gpt-5-mini (não o nano padrão) - mesmo motivo da Frase do Dia e
        // Perguntas: nano mistura fragmentos sem relação ao compor várias
        // rodadas novas a partir do corpus de frases do usuário.
        $chat = new OpenAiChat($_ENV['OPEN_AI'], "gpt-5-mini");
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
