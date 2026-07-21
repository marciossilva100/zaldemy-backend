<?php

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
 * FUNÇÃO PARA EXTRAIR INGLÊS E PORTUGUÊS
 * =========================
 */
function extrairTextos(string $texto): array
{
    $english = '';
    $portuguese = '';

    // Padrão 1: Formato ideal com ambos os idiomas
    if (preg_match('/ENGLISH:\s*(.*?)(?:PORTUGUESE|PORTUGUSE)\s*(?:\(PT-BR\))?:\s*(.*)/s', $texto, $m)) {
        $english    = trim($m[1]);
        $portuguese = trim($m[2]);
    }
    // Padrão 2: Fallback - só encontrou ENGLISH
    elseif (preg_match('/ENGLISH:\s*(.+)/s', $texto, $m)) {
        $english = trim($m[1]);
        if (preg_match('/(?:PORTUGUESE|PORTUGUSE)\s*(?:\(PT-BR\))?:\s*(.+)/s', $texto, $m2)) {
            $portuguese = trim($m2[1]);
        }
    }
    // Padrão 3: Nenhum marcador encontrado - assume tudo como inglês
    else {
        $english = trim($texto);
    }

    return [$english, $portuguese];
}

/**
 * =========================
 * PEGAR USER
 * =========================
 */
$userId = $user['id']; // vindo do authMiddleware

/**
 * =========================
 * VERIFICAR SE JÁ EXISTE HOJE
 * =========================
 */
$sql = "
    SELECT frase
    FROM frases_ia
    WHERE user_id = :user_id
      AND DATE(data_criacao) = CURDATE()
    LIMIT 1
";

$stmt = $pdo->prepare($sql);
$stmt->execute(['user_id' => $userId]);

$fraseHoje = $stmt->fetch(PDO::FETCH_ASSOC);

if ($fraseHoje) {
    $texto = $fraseHoje['frase'];
    [$english, $portuguese] = extrairTextos($texto);

    echo json_encode([
        'success'    => true,
        'cached'     => true,
        'traduzido'  => $english,
        'nativo'     => $portuguese
    ], JSON_UNESCAPED_UNICODE);

    exit;
}

/**
 * =========================
 * BUSCA FRASES DO BANCO (SORTEIO ALEATÓRIO)
 * =========================
 */

// Define quantas frases sortear (entre 5 e 10)
$quantidadeFrases = rand(5, 10);

$sql = "
    SELECT texto_traduzido
    FROM frases
    WHERE texto_traduzido IS NOT NULL
      AND TRIM(texto_traduzido) <> ''
    ORDER BY RAND()
    LIMIT :limite
";

$stmt = $pdo->prepare($sql);
$stmt->bindValue(':limite', $quantidadeFrases, PDO::PARAM_INT);
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
 * ARRAY FINAL DE FRASES (EMBARALHADO)
 */
$phrases = array_map(function ($row) {
    return trim($row['texto_traduzido']);
}, $rows);

// Embaralha novamente para garantir ordem aleatória
shuffle($phrases);

/**
 * =========================
 * API KEY
 * =========================
 */
$apiKey = getenv('GROQ_API_KEY') ?: ($_ENV['GROQ_API_KEY'] ?? '');

try {
    $generator = new EnglishParagraphGenerator($apiKey);

    $result = $generator->generateCohesiveParagraph($phrases);

    $text = $result['paragraph'];

    /**
     * =========================
     * VALIDAR SE O TEXTO EM INGLÊS NÃO CONTÉM PORTUGUÊS
     * =========================
     */
    [$english, $portuguese] = extrairTextos($text);

    $palavrasPortuguesas = [
        'você', 'para', 'como', 'mas', 'porque', 'tudo bem', 'isso',
        'mais', 'tempo', 'leva', 'não', 'uma', 'os', 'as', 'eles',
        'elas', 'está', 'são', 'com', 'que', 'dos', 'das', 'é', 'eu',
        'muito', 'bom', 'ótimo', 'filme', 'personagem', 'incrível',
        'principal', 'também', 'gosto', 'semana', 'todo', 'toda',
        'todos', 'todas', 'fazer', 'coisa', 'coisas', 'algo', 'nada',
        'aqui', 'ali', 'lá', 'cá', 'bem', 'mal', 'vez', 'vezes'
    ];

    $englishLower = strtolower($english);
    foreach ($palavrasPortuguesas as $palavra) {
        if (preg_match('/\b' . preg_quote($palavra, '/') . '\b/', $englishLower)) {
            throw new Exception("Texto em inglês contém palavras em português. Geração inválida.");
        }
    }

    /**
     * =========================
     * SALVAR NO BANCO
     * =========================
     */
    $sql = "
        INSERT INTO frases_ia (frase, user_id)
        VALUES (:frase, :user_id)
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        'frase'    => $text,
        'user_id'  => $userId
    ]);

    echo json_encode([
        'success'       => true,
        'cached'        => false,
        'traduzido'     => $english,
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