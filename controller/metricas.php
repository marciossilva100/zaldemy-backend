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

header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once '../model/Metricas.php';
require_once 'authMiddleware.php';

// Lê os dados de entrada
$input = $_SERVER['REQUEST_METHOD'] === 'POST' 
    ? json_decode(file_get_contents('php://input'), true) 
    : $_GET;

$action = $input['action'] ?? null;

// Instancia a Model
$metricasModel = new Metricas();

// Idioma atualmente selecionado pelo usuário (definido no Header).
// As métricas ficam restritas a esse par nativo/aprendendo.
$idiomaAtual = $metricasModel->getIdiomaAtual($user_id);
$idioma_nativo = $idiomaAtual['idioma_nativo'] ?? null;
$idioma_aprendendo = $idiomaAtual['idioma_aprendendo'] ?? null;

try {
    $response = [];

    // ========== DASHBOARD COMPLETO ==========
    if ($action === 'dashboard') {
        $periodo = $input['periodo'] ?? '30d';

        $response = [
            'grafico' => $metricasModel->getDesempenho($user_id, $periodo, $idioma_nativo, $idioma_aprendendo),
            'comparativos' => $metricasModel->getComparativoSemanal($user_id, $idioma_nativo, $idioma_aprendendo),
            'categorias' => $metricasModel->getCategorias($user_id, $idioma_nativo, $idioma_aprendendo),
            'tempo_medio' => $metricasModel->getTempoMedio($user_id),
            'streak' => $metricasModel->getStreak($user_id, $idioma_nativo, $idioma_aprendendo),
            'melhor_streak' => $metricasModel->getMelhorStreak($user_id, $idioma_nativo, $idioma_aprendendo),
            'melhor_sequencia_acertos' => $metricasModel->getMelhorSequenciaAcertos($user_id, $idioma_nativo, $idioma_aprendendo),
            'resumo' => $metricasModel->getResumo($user_id, $idioma_nativo, $idioma_aprendendo)
        ];
    }

    // ========== GRÁFICO DE DESEMPENHO ==========
    elseif ($action === 'metricas_desempenho') {
        $periodo = $input['periodo'] ?? '30d';
        $response = $metricasModel->getDesempenho($user_id, $periodo, $idioma_nativo, $idioma_aprendendo);
    }

    // ========== RESUMO (KPI) ==========
    elseif ($action === 'metricas_resumo') {
        $response = $metricasModel->getResumo($user_id, $idioma_nativo, $idioma_aprendendo);
    }

    // ========== REGISTRAR RESPOSTA ==========
    elseif ($action === 'registrar') {
        $frase_id = $input['frase_id'] ?? null;
        $acertou = $input['acertou'] ?? null;

        if (!$frase_id) {
            http_response_code(400);
            echo json_encode(["error" => "frase_id obrigatório"]);
            exit;
        }

        if (!isset($acertou)) {
            http_response_code(400);
            echo json_encode(["error" => "acertou obrigatório"]);
            exit;
        }

        $response = $metricasModel->registrar($user_id, $frase_id, $acertou);
    }

    // ========== LISTAR FRASES COM MÉTRICAS ==========
    elseif ($action === 'listar_frases_metricas') {
        $response = [
            'frases' => $metricasModel->listarFrasesComMetricas($user_id, $idioma_nativo, $idioma_aprendendo)
        ];
    }

    // ========== COMPARATIVO SEMANAL ==========
    elseif ($action === 'comparativo_semanal') {
        $response = $metricasModel->getComparativoSemanal($user_id, $idioma_nativo, $idioma_aprendendo);
    }

    // ========== CATEGORIAS ==========
    elseif ($action === 'categorias') {
        $response = $metricasModel->getCategorias($user_id, $idioma_nativo, $idioma_aprendendo);
    }

    // ========== STREAK ==========
    elseif ($action === 'streak') {
        $response = [
            'streak' => $metricasModel->getStreak($user_id, $idioma_nativo, $idioma_aprendendo)
        ];
    }

    // ========== DESEMPENHO POR IDIOMA ==========
    elseif ($action === 'desempenho_idioma') {
        $response = $metricasModel->getDesempenhoPorIdioma($user_id);
    }

    // ========== AÇÃO INVÁLIDA ==========
    else {
        http_response_code(400);
        echo json_encode(["error" => "Action inválida: $action"]);
        exit;
    }

    // Retorna a resposta
    echo json_encode($response);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        "error" => "Erro no servidor",
        "message" => $e->getMessage(),
        "file" => $e->getFile(),
        "line" => $e->getLine()
    ]);
}