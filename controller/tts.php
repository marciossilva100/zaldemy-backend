<?php

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// =========================
// 🔐 LOAD .ENV
// =========================
require_once __DIR__ . '/../dotenv.php';
carregarEnv(__DIR__ . '/../.env');

if (!isset($_ENV['OPEN_AI'])) {
    die(json_encode([
        "erro" => true,
        "mensagem" => "API KEY não configurada"
    ]));
}

// =========================
// 🔒 CORS
// =========================
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

header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Tts-Preload');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// =========================
// 🔐 AUTENTICAÇÃO (necessária pra saber o plano e controlar o limite diário)
// =========================
require_once '../server.php';
require_once 'authMiddleware.php';
require_once '../model/Configuracoes.php';
require_once '../model/PlanoLimitado.php';

// Voz natural (OpenAI TTS) é liberada pro plano premium (limite diário, pra
// manter o custo por chamada da API sob controle) e, como amostra grátis,
// pro plano limitado (limite vitalício único - depois disso só virando
// premium pra continuar usando voz natural).
const AUDIO_IA_LIMITE_DIARIO = 120;
const AUDIO_IA_LIMITE_VITALICIO_LIMITADO = 10;

// Preload (Flashcards/DigitarTexto/TiroCerteiro) busca o áudio antes do
// usuário pedir, sem saber se ele vai chegar a ouvir - perto do fim da cota
// diária, reserva essa quantidade de gerações só pra quando o usuário
// realmente clicar em ouvir, pra não gastar os últimos slots do dia em
// áudio que talvez nunca seja escutado. Não se aplica ao limitado (cota
// vitalícia de amostra, não diária).
const AUDIO_IA_RESERVA_PRELOAD_PREMIUM = 5;

// O custo é por caractere, não por chamada - sem esse teto, o limite de
// chamadas acima não protege o custo de verdade (uma única chamada com
// texto gigante custaria muito mais que o previsto). Aplica pra todo
// mundo que tiver acesso, mesmo cap independente do plano.
const AUDIO_IA_LIMITE_CARACTERES_POR_CHAMADA = 300;

function usoAudioIaHoje(PDO $pdo, int $userId): int
{
    $sql = "SELECT COUNT(*) as total
            FROM audio_ia_uso
            WHERE user_id = :user_id
              AND DATE(data_criacao) = CURDATE()";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([':user_id' => $userId]);

    return (int) $stmt->fetch(PDO::FETCH_ASSOC)['total'];
}

function usoAudioIaTotal(PDO $pdo, int $userId): int
{
    $sql = "SELECT COUNT(*) as total
            FROM audio_ia_uso
            WHERE user_id = :user_id";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([':user_id' => $userId]);

    return (int) $stmt->fetch(PDO::FETCH_ASSOC)['total'];
}

function registrarUsoAudioIa(PDO $pdo, int $userId): void
{
    $stmt = $pdo->prepare("INSERT INTO audio_ia_uso (user_id) VALUES (:user_id)");
    $stmt->execute([':user_id' => $userId]);
}

// Verifica se o plano do usuário pode usar voz natural e, se puder, se ainda
// está dentro do limite (diário pro premium, vitalício pro limitado).
// Retorna null se pode prosseguir, ou o array de resposta JSON pra retornar
// direto (bloqueando o acesso). $isPreload distingue uma busca antecipada
// (sem garantia de que o áudio será ouvido) de uma reprodução pedida de
// verdade - só a segunda pode consumir os últimos slots reservados do dia.
function verificarAcessoAudioIa(PDO $pdo, int $userId, int $plano, bool $isPreload = false): ?array
{
    if ($plano === 1) {
        $usoHoje = usoAudioIaHoje($pdo, $userId);

        if ($usoHoje >= AUDIO_IA_LIMITE_DIARIO) {
            return ["erro" => false, "limite_atingido" => true];
        }

        if ($isPreload && (AUDIO_IA_LIMITE_DIARIO - $usoHoje) <= AUDIO_IA_RESERVA_PRELOAD_PREMIUM) {
            // Não é o limite de verdade (por isso não usa "limite_atingido",
            // que marcaria a cota como esgotada no cliente) - só recusa essa
            // busca antecipada específica pra sobrar cota pra um clique real
            // em "ouvir" mais tarde hoje.
            return ["erro" => false, "preload_reservado" => true];
        }

        return null;
    }

    if ($plano === 3) {
        if (usoAudioIaTotal($pdo, $userId) >= AUDIO_IA_LIMITE_VITALICIO_LIMITADO) {
            return ["erro" => false, "limite_atingido" => true];
        }
        return null;
    }

    return ["erro" => false, "premium_necessario" => true];
}

// Verifica o limite e já registra o uso, tudo dentro de um lock nomeado do
// MySQL (GET_LOCK/RELEASE_LOCK) por usuário - sem isso, "verificar se ainda
// tem cota" e "registrar o uso" eram dois passos separados sem trava
// nenhuma, e várias requisições simultâneas (ex: clicando em várias frases
// rapidamente numa lista, cada uma abrindo sua própria chamada) podiam todas
// "ver" a cota livre ao mesmo tempo, antes de qualquer uma delas registrar a
// sua - passando todas juntas e estourando o limite de verdade. O lock é
// liberado logo depois de reservar a linha (rápido, só banco), ANTES da
// chamada de verdade pra API de voz (lenta) - se a geração falhar depois,
// a reserva é desfeita (removida) pra não gastar cota por um erro.
// Retorna null se conseguiu reservar (já registrado - chame
// desfazerReservaAudioIa se a geração falhar), ou o array de bloqueio.
function reservarUsoAudioIa(PDO $pdo, int $userId, int $plano, bool $isPreload = false): ?array
{
    $chaveLock = "audio_ia_uso_{$userId}";

    $stmt = $pdo->prepare("SELECT GET_LOCK(:chave, 5)");
    $stmt->execute([':chave' => $chaveLock]);
    $conseguiuLock = (bool) $stmt->fetchColumn();

    if (!$conseguiuLock) {
        // Não deveria acontecer (timeout de 5s é folgado pra uma operação
        // rápida) - trata como bloqueio genérico em vez de deixar passar.
        return ["erro" => true, "mensagem" => "Não foi possível processar a solicitação, tente novamente."];
    }

    try {
        $bloqueio = verificarAcessoAudioIa($pdo, $userId, $plano, $isPreload);

        if ($bloqueio !== null) {
            return $bloqueio;
        }

        registrarUsoAudioIa($pdo, $userId);
        return null;
    } finally {
        $stmt = $pdo->prepare("SELECT RELEASE_LOCK(:chave)");
        $stmt->execute([':chave' => $chaveLock]);
    }
}

function desfazerReservaAudioIa(PDO $pdo, int $userId): void
{
    // Remove a reserva mais recente desse usuário - a única criada pela
    // chamada de reservarUsoAudioIa que acabou de falhar na geração.
    $stmt = $pdo->prepare(
        "DELETE FROM audio_ia_uso WHERE id = (
            SELECT id FROM (
                SELECT id FROM audio_ia_uso WHERE user_id = :user_id ORDER BY id DESC LIMIT 1
            ) AS ultima
        )"
    );
    $stmt->execute([':user_id' => $userId]);
}

// =========================
// 📥 INPUT
// =========================
// stream_audio aceita GET (querystring) além de POST - GET permite o
// service worker do front cachear a resposta por URL (ver
// vite.config.js), evitando gerar áudio de novo pra um replay da mesma
// frase. As outras actions continuam só POST (JSON no corpo).
$rawInput = file_get_contents('php://input');
$input = json_decode($rawInput, true) ?? [];

$action = $_GET['action'] ?? ($input['action'] ?? null);

// =========================
// 📦 CLASS
// =========================
require_once __DIR__ . '/../api/OpenAiTts.php';

$tts = new OpenAiTts($_ENV['OPEN_AI']);

try {

    // =========================
    // 🔍 VERIFICAR LIMITE (sem gastar cota) - checagem leve pro front
    // sincronizar o estado ANTES de tentar tocar qualquer áudio, evitando
    // que o cache do navegador (service worker) sirva uma voz natural já
    // baixada antes sem nunca descobrir que a cota acabou nesse meio tempo.
    // =========================
    if ($action === "verificar_limite") {
        header('Content-Type: application/json');
        // Resposta por usuário, muda a qualquer momento (upgrade de plano) -
        // nunca pode ficar em cache de proxy/CDN/navegador (diferente do
        // stream_audio abaixo, que É pra ficar em cache no service worker).
        header("Cache-Control: no-store, no-cache, must-revalidate");
        header("Pragma: no-cache");
        $bloqueio = verificarAcessoAudioIa($pdo, $user_id, (int) ($user['plano'] ?? 0));
        echo json_encode(["limite_atingido" => $bloqueio !== null && !empty($bloqueio['limite_atingido'])]);
        exit;
    }

    // =========================
    // 🔊 STREAM (MP3)
    // =========================
    if ($action === "stream_audio") {

        $texto  = $_GET['texto'] ?? ($input['texto'] ?? null);
        $idioma = $_GET['idioma'] ?? ($input['idioma'] ?? "pt");

        if (!$texto) {
            throw new Exception("Texto não informado");
        }

        if (mb_strlen($texto) > AUDIO_IA_LIMITE_CARACTERES_POR_CHAMADA) {
            throw new Exception("Texto excede o limite de " . AUDIO_IA_LIMITE_CARACTERES_POR_CHAMADA . " caracteres por chamada de áudio.");
        }

        $plano = (int) ($user['plano'] ?? 0);

        // Cabeçalho (não querystring) de propósito - a querystring é a
        // chave de cache do service worker (ver comentário acima em
        // gerarAudio/vite.config.js); se "preload" entrasse na URL, o
        // preload e o play explícito da MESMA frase virariam URLs
        // diferentes e nunca reaproveitariam o cache um do outro.
        $headers = getallheaders();
        $isPreload = ($headers['X-Tts-Preload'] ?? $headers['x-tts-preload'] ?? '') === '1';

        // Verifica E já registra o uso, atomicamente (ver comentário em
        // reservarUsoAudioIa) - antes de chamar a API de voz de verdade, pra
        // nenhuma requisição concorrente conseguir passar pela checagem
        // antes de outra registrar a sua.
        $bloqueio = reservarUsoAudioIa($pdo, $user_id, $plano, $isPreload);

        if ($bloqueio !== null) {
            // Sem isso, essa resposta (limite atingido) volta com HTTP 200 -
            // o service worker cacheia QUALQUER 200 pra essa URL pra sempre
            // (CacheFirst, 1 ano, ver src/sw.js), então essa frase específica
            // ficava travada nesse resultado de rejeição indefinidamente,
            // mesmo depois do limite deixar de se aplicar (upgrade de plano,
            // virada de dia) - o navegador nunca mais chegava a tentar de
            // novo pela rede.
            http_response_code(429);
            header('Content-Type: application/json');
            echo json_encode($bloqueio);
            exit;
        }

        // 🔥 agora com cache ativado
        $vozPreferida = Configuracoes::getVozTts($pdo, $user_id);
        $velocidadePreferida = Configuracoes::getVelocidadeTts($pdo, $user_id);
        $result = $tts->gerarAudio($texto, $idioma, true, $vozPreferida, $velocidadePreferida);

        if ($result["erro"]) {
            desfazerReservaAudioIa($pdo, $user_id);
            throw new Exception($result["mensagem"]);
        }

        PlanoLimitado::verificarEDowngradear($pdo, $user_id, $plano);

        if (empty($result["audio"])) {
            desfazerReservaAudioIa($pdo, $user_id);
            throw new Exception("Áudio vazio");
        }

        // 🔥 limpa QUALQUER saída antes do áudio
        while (ob_get_level()) {
            ob_end_clean();
        }

        // =========================
        // 🎧 HEADERS DE ÁUDIO
        // =========================
        header("Content-Type: audio/wav");
        header("Content-Length: " . strlen($result["audio"]));
        header("Accept-Ranges: bytes");

        // =========================
        // 🚀 CACHE CONTROL (AGORA PODE CACHEAR)
        // =========================
        header("Cache-Control: public, max-age=31536000"); // 1 ano
        header("Expires: " . gmdate("D, d M Y H:i:s", time() + 31536000) . " GMT");

        // =========================
        // 🔥 DEBUG PROFISSIONAL
        // =========================
        header("X-Cache: " . ($result["cache"] ? "HIT" : "MISS"));
        header("X-Audio-Size: " . strlen($result["audio"]));

        echo $result["audio"];
        exit;
    }

    // =========================
    // 🎧 JSON (debug)
    // =========================
    elseif ($action === "gerar_audio") {

        header('Content-Type: application/json');

        $texto  = $input['texto'] ?? null;
        $idioma = $input['idioma'] ?? "pt";

        if (!$texto) {
            throw new Exception("Texto não informado");
        }

        if (mb_strlen($texto) > AUDIO_IA_LIMITE_CARACTERES_POR_CHAMADA) {
            throw new Exception("Texto excede o limite de " . AUDIO_IA_LIMITE_CARACTERES_POR_CHAMADA . " caracteres por chamada de áudio.");
        }

        $plano = (int) ($user['plano'] ?? 0);
        $bloqueio = reservarUsoAudioIa($pdo, $user_id, $plano);

        if ($bloqueio !== null) {
            echo json_encode($bloqueio);
            exit;
        }

        $vozPreferida = Configuracoes::getVozTts($pdo, $user_id);
        $velocidadePreferida = Configuracoes::getVelocidadeTts($pdo, $user_id);
        $result = $tts->gerarAudio($texto, $idioma, true, $vozPreferida, $velocidadePreferida);

        if ($result["erro"]) {
            desfazerReservaAudioIa($pdo, $user_id);
        } else {
            PlanoLimitado::verificarEDowngradear($pdo, $user_id, $plano);
        }

        echo json_encode([
            "erro" => $result["erro"],
            "cache" => $result["cache"],
            "audio_size" => isset($result["audio"]) ? strlen($result["audio"]) : 0
        ]);
    }

    else {
        throw new Exception("Ação inválida");
    }

} catch (Throwable $e) {

    while (ob_get_level()) {
        ob_end_clean();
    }

    header('Content-Type: application/json');
    http_response_code(500);

    echo json_encode([
        "erro" => true,
        "mensagem" => $e->getMessage()
    ]);
}
