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

header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

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

// Voz natural (ElevenLabs) é liberada pro plano premium (limite diário, pra
// manter o custo por chamada da API sob controle) e, como amostra grátis,
// pro plano limitado (limite vitalício único - depois disso só virando
// premium pra continuar usando voz natural).
const AUDIO_IA_LIMITE_DIARIO = 50;
const AUDIO_IA_LIMITE_VITALICIO_LIMITADO = 10;

// O custo do ElevenLabs é por caractere, não por chamada - sem esse teto,
// o limite de chamadas acima não protege o custo de verdade (uma única
// chamada com texto gigante custaria muito mais que o previsto). Aplica
// pra todo mundo que tiver acesso, mesmo cap independente do plano.
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
// direto (bloqueando o acesso).
function verificarAcessoAudioIa(PDO $pdo, int $userId, int $plano): ?array
{
    if ($plano === 1) {
        if (usoAudioIaHoje($pdo, $userId) >= AUDIO_IA_LIMITE_DIARIO) {
            return ["erro" => false, "limite_atingido" => true];
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

// =========================
// 📥 INPUT
// =========================
$rawInput = file_get_contents('php://input');
$input = json_decode($rawInput, true) ?? [];

$action = $input['action'] ?? null;

// =========================
// 📦 CLASS
// =========================
// Trocado de ElevenLabs pra OpenAI (TTS) - api/ElevenLabs.php continua
// intacto e com a mesma interface (gerarAudio), então voltar atrás é só
// trocar essas duas linhas de novo.
require_once __DIR__ . '/../api/OpenAiTts.php';

$eleven = new OpenAiTts($_ENV['OPEN_AI']);

try {

    // =========================
    // 🔊 STREAM (MP3)
    // =========================
    if ($action === "stream_audio") {

        $texto  = $input['texto'] ?? null;
        $idioma = $input['idioma'] ?? "pt";

        if (!$texto) {
            throw new Exception("Texto não informado");
        }

        if (mb_strlen($texto) > AUDIO_IA_LIMITE_CARACTERES_POR_CHAMADA) {
            throw new Exception("Texto excede o limite de " . AUDIO_IA_LIMITE_CARACTERES_POR_CHAMADA . " caracteres por chamada de áudio.");
        }

        $bloqueio = verificarAcessoAudioIa($pdo, $user_id, (int) ($user['plano'] ?? 0));

        if ($bloqueio !== null) {
            header('Content-Type: application/json');
            echo json_encode($bloqueio);
            exit;
        }

        // 🔥 agora com cache ativado
        $vozPreferida = Configuracoes::getVozTts($pdo, $user_id);
        $result = $eleven->gerarAudio($texto, $idioma, true, $vozPreferida);

        if ($result["erro"]) {
            throw new Exception($result["mensagem"]);
        }

        // Toda reprodução conta contra o limite, cache ou não - senão o
        // usuário podia reproduzir a mesma frase infinitas vezes de graça
        // sem nunca gastar a cota.
        registrarUsoAudioIa($pdo, $user_id);

        if (empty($result["audio"])) {
            throw new Exception("Áudio vazio");
        }

        // 🔥 limpa QUALQUER saída antes do áudio
        while (ob_get_level()) {
            ob_end_clean();
        }

        // =========================
        // 🎧 HEADERS DE ÁUDIO
        // =========================
        header("Content-Type: audio/mpeg");
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

        $bloqueio = verificarAcessoAudioIa($pdo, $user_id, (int) ($user['plano'] ?? 0));

        if ($bloqueio !== null) {
            echo json_encode($bloqueio);
            exit;
        }

        $vozPreferida = Configuracoes::getVozTts($pdo, $user_id);
        $result = $eleven->gerarAudio($texto, $idioma, true, $vozPreferida);

        // Toda reprodução conta contra o limite, cache ou não - mas só se
        // realmente saiu áudio (erro da API não deveria consumir a cota).
        if (!$result["erro"]) {
            registrarUsoAudioIa($pdo, $user_id);
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