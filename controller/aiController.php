<?php

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

header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Content-Type: application/json; charset=utf-8');

// Preflight
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}


ini_set('display_errors', 0);
error_reporting(E_ALL);


require_once '../server.php';
require_once 'authMiddleware.php';
require_once '../model/ai.php';

require_once __DIR__ . '/../dotenv.php';
carregarEnv(__DIR__ . '/../.env');

/**
 * =========================
 * BUSCA FRASES DO BANCO
 * =========================
 */
$sql = "
    SELECT texto_nativo
    FROM frases
    WHERE texto_nativo IS NOT NULL
      AND TRIM(texto_nativo) <> ''
";

$stmt = $pdo->prepare($sql);
$stmt->execute();

$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (count($rows) < 2) {
    echo json_encode([
        'success' => false,
        'error'   => 'Quantidade insuficiente de frases em inglês',
        'found'   => count($rows)
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * ARRAY FINAL DE FRASES (INGLÊS)
 */
$phrases = array_map(function ($row) {
    return trim($row['texto_nativo']);
}, $rows);


/**
 * =========================
 * API KEY
 * =========================
 */

$apiKey = getenv('GROQ_API_KEY') ?: ($_ENV['GROQ_API_KEY'] ?? '');


try {
    $generator = new EnglishParagraphGenerator($apiKey);

    $result = $generator->generateCohesiveParagraph($phrases);

    /**
     * =========================
     * SEPARA EN / PT-BR
     * =========================
     */
    $text = $result['paragraph'];

    $english = '';
    $portuguese = '';

    if (preg_match('/ENGLISH:\s*(.*?)PORTUGUESE \(PT-BR\):\s*(.*)/s', $text, $m)) {
        $english    = trim($m[1]);
        $portuguese = trim($m[2]);
    } else {
        // fallback
        $english = trim($text);
    }

    /**
     * =========================
     * RETORNO FINAL (MESMO CONTRATO)
     * =========================
     */
    echo json_encode([
        'success'       => true,
        'cached'        => false, // mantido por compatibilidade
        'traduzido'       => $english,
        'nativo'        => $portuguese,
        'stats'         => $result['word_stats'],
        'phrases_used'  => $result['phrases_used'],
        'model'         => $result['model_used']
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'error'   => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}
