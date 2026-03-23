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
    "https://memly-jijk.vercel.app"
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

        // 🔥 agora com cache ativado
        $result = $eleven->gerarAudio($texto, $idioma, true);

        if ($result["erro"]) {
            throw new Exception($result["mensagem"]);
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

        $result = $eleven->gerarAudio($texto, $idioma, true);

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