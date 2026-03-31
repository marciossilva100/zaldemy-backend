<?php
declare(strict_types=1);
ini_set('display_errors', 1);
error_reporting(E_ALL);

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

header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once '../server.php';
require_once 'authMiddleware.php';
require_once '../model/DailyQuestionIA.php';
require_once __DIR__ . '/../dotenv.php';

carregarEnv(__DIR__ . '/../.env');

class DailyQuestionController
{
    private $ai;
    private $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;

        $apiKey = getenv('GROQ_API_KEY') ?: ($_ENV['GROQ_API_KEY'] ?? '');

        if (!$apiKey) {
            throw new Exception("API Key não configurada.");
        }

        $this->ai = new DailyQuestionIA($apiKey, $this->pdo);
    }

    /* ===============================
       SKIP
    =============================== */
    public function skipDailyQuestion()
    {
        try {
            $user_id = $this->getUserId();

            // pega a última pergunta de HOJE
            $sqlLast = "
                SELECT id 
                FROM perguntas_ia 
                WHERE user_id = :user_id
                AND DATE(data_criacao) = CURDATE()
                ORDER BY id DESC 
                LIMIT 1
            ";

            $stmtLast = $this->pdo->prepare($sqlLast);
            $stmtLast->execute([':user_id' => $user_id]);

            $row = $stmtLast->fetch(PDO::FETCH_ASSOC);

            if ($row) {
                // opcional: manter status se quiser histórico
                $sqlUpdate = "UPDATE perguntas_ia SET status_id = 1 WHERE id = :id";
                $stmtUpdate = $this->pdo->prepare($sqlUpdate);
                $stmtUpdate->execute([':id' => $row['id']]);
            }

            $this->json([
                'success' => true,
                'total_today' => $this->getTotalToday($user_id)
            ]);

        } catch (Exception $e) {
            $this->error($e);
        }
    }

    /* ===============================
       GET → GERAR PERGUNTA
    =============================== */
    public function getDailyQuestion()
    {
        try {
            $user_id = $this->getUserId();

            $phrases = $this->getUserPhrases($user_id);

            if (empty($phrases)) {
                throw new Exception("Usuário não possui frases cadastradas.");
            }

            $result = $this->ai->generateQuestion($phrases, $user_id);

            $this->json([
                'success' => true,
                'question' => $result['question'] ?? null,
                'total_today' => $this->getTotalToday($user_id), // 🔥 SEMPRE ATUALIZADO
                'limit_reached' => $result['limit_reached'] ?? false
            ]);

        } catch (Exception $e) {
            $this->error($e);
        }
    }

    /* ===============================
       POST → RESPONDER
    =============================== */
    public function answerDailyQuestion()
    {
        try {
            $user_id = $this->getUserId();
            $data = json_decode(file_get_contents('php://input'), true);

            if (!is_array($data)) {
                throw new Exception('JSON inválido.');
            }

            if (empty($data['question']) || empty($data['answer'])) {
                throw new Exception('Pergunta e resposta são obrigatórias.');
            }

            $phrases = $this->getUserPhrases($user_id);

            $result = $this->ai->evaluateAnswer(
                $phrases,
                $data['question'],
                $data['answer']
            );

            $this->json([
                'success' => true,
                'feedback' => $result['feedback'] ?? '',
                'is_correct' => $result['is_correct'] ?? false,
                'total_today' => $this->getTotalToday($user_id)
            ]);

        } catch (Exception $e) {
            $this->error($e);
        }
    }

    /* ===============================
       TOTAL DO DIA (VERSÃO CORRETA)
    =============================== */
    private function getTotalToday($user_id)
    {
        $sql = "SELECT COUNT(*) as total 
                FROM perguntas_ia 
                WHERE user_id = :user_id 
                AND DATE(data_criacao) = CURDATE()";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            ':user_id' => $user_id
        ]);

        return (int)$stmt->fetch(PDO::FETCH_ASSOC)['total'];
    }

    /* ===============================
       FRASES
    =============================== */
    private function getUserPhrases($user_id)
    {
        $sql = "
            SELECT texto_traduzido
            FROM frases
            WHERE texto_traduzido IS NOT NULL
            AND usuario_id = :user_id
            AND TRIM(texto_nativo) <> ''
            AND status_id > 0
        ";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':user_id' => $user_id]);

        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    /* ===============================
       AUTH
    =============================== */
    private function getUserId()
    {
        global $user_id;

        if (!isset($user_id)) {
            throw new Exception('Usuário não autenticado.');
        }

        return (int)$user_id;
    }

    /* ===============================
       HELPERS
    =============================== */
    private function json(array $data)
    {
        echo json_encode($data, JSON_UNESCAPED_UNICODE);
        exit;
    }

    private function error(Exception $e)
    {
        http_response_code(400);

        echo json_encode([
            'success' => false,
            'error' => $e->getMessage()
        ], JSON_UNESCAPED_UNICODE);

        exit;
    }
}

/* ===============================
   ROTA
=============================== */

try {
    $controller = new DailyQuestionController($pdo);

    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $controller->getDailyQuestion();
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $data = json_decode(file_get_contents('php://input'), true);

        if (isset($data['action']) && $data['action'] === 'skip') {
            $controller->skipDailyQuestion();
        } else {
            $controller->answerDailyQuestion();
        }
    }

} catch (Exception $e) {
    http_response_code(500);

    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}