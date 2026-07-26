<?php

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// =========================
// 🔐 LOAD .ENV
// =========================
require_once __DIR__ . '/../dotenv.php';
carregarEnv(__DIR__ . '/../.env');

if (!isset($_ENV['ELEVENLABS_API_KEY'])) {
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

// Voz natural (ElevenLabs) é exclusiva do plano premium, com limite diário
// pra manter o custo por chamada da API sob controle.
const AUDIO_IA_LIMITE_DIARIO = 50;

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

function registrarUsoAudioIa(PDO $pdo, int $userId): void
{
    $stmt = $pdo->prepare("INSERT INTO audio_ia_uso (user_id) VALUES (:user_id)");
    $stmt->execute([':user_id' => $userId]);
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
require_once __DIR__ . '/../api/ElevenLabs.php';

$eleven = new ElevenLabs($_ENV['ELEVENLABS_API_KEY']);

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

        if ((int) ($user['plano'] ?? 0) !== 1) {
            header('Content-Type: application/json');
            echo json_encode(["erro" => false, "premium_necessario" => true]);
            exit;
        }

        $usoHoje = usoAudioIaHoje($pdo, $user_id);

        if ($usoHoje >= AUDIO_IA_LIMITE_DIARIO) {
            header('Content-Type: application/json');
            echo json_encode(["erro" => false, "limite_atingido" => true]);
            exit;
        }

        // 🔥 agora com cache ativado
        $result = $eleven->gerarAudio($texto, $idioma, true);

        if ($result["erro"]) {
            throw new Exception($result["mensagem"]);
        }

        // Só conta contra o limite diário quando de fato chama a API (cache não tem custo)
        if (empty($result["cache"])) {
            registrarUsoAudioIa($pdo, $user_id);
        }

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

        if ((int) ($user['plano'] ?? 0) !== 1) {
            echo json_encode(["erro" => false, "premium_necessario" => true]);
            exit;
        }

        $usoHoje = usoAudioIaHoje($pdo, $user_id);

        if ($usoHoje >= AUDIO_IA_LIMITE_DIARIO) {
            echo json_encode(["erro" => false, "limite_atingido" => true]);
            exit;
        }

        $result = $eleven->gerarAudio($texto, $idioma, true);

        if (empty($result["cache"])) {
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