<?php
declare(strict_types=1);
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

$allowedOrigins = [
    "http://localhost:5173",
    "https://zaldemy.com",
    "https://www.zaldemy.com",
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

/* ======================================================
   DEPENDÊNCIAS
====================================================== */
require_once '../server.php';
require_once 'authMiddleware.php';
require_once '../model/DailyQuestionIA.php';

require_once __DIR__ . '/../dotenv.php';
carregarEnv(__DIR__ . '/../.env');

/* ======================================================
   CONTROLLER
====================================================== */
class DailyQuestionController
{
    private $ai;
    private $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;

        $apiKey = getenv('OPENROUTER_API_KEY') ?: ($_ENV['OPENROUTER_API_KEY'] ?? '');

        if (!$apiKey) {
            throw new Exception("API Key não configurada.");
        }

        $this->ai = new DailyQuestionIA($apiKey, $this->pdo);
    }

    public function skipDailyQuestion()
    {
        try {
            $user_id = $this->getUserId();

            $data = json_decode(file_get_contents('php://input'), true);

            if (empty($data['question'])) {
                throw new Exception('Pergunta é obrigatória.');
            }

            // marca como "respondida" (mesma lógica de acerto)
            $sql = "
                UPDATE perguntas_ia 
                SET status_id = 1 
                WHERE user_id = :user_id 
                AND question = :question
                ORDER BY id DESC 
                LIMIT 1
            ";

            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([
                ':user_id' => $user_id,
                ':question' => $data['question']
            ]);

            $this->json([
                'success' => true
            ]);

        } catch (Exception $e) {
            $this->error($e);
        }
    }

    /* ======================================================
       GET → gerar pergunta
    ====================================================== */
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
                'total_today' => $result['total_today'] ?? 0,
                'limit_reached' => $result['limit_reached'] ?? false
            ]);

        } catch (Exception $e) {
            $this->error($e);
        }
    }

    /* ======================================================
       POST → avaliar resposta
    ====================================================== */
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

            // ✅ AJUSTE AQUI (PEGA ARRAY)
            $result = $this->ai->evaluateAnswer(
                $phrases,
                $data['question'],
                $data['answer']
            );

            // ✅ RETORNA feedback + is_correct
            $this->json([
                'success' => true,
                'feedback' => $result['feedback'] ?? '',
                'is_correct' => $result['is_correct'] ?? false
            ]);

        } catch (Exception $e) {
            $this->error($e);
        }
    }

    /* ======================================================
       BUSCA FRASES DO USUÁRIO
    ====================================================== */
    private function getUserPhrases($user_id)
    {
        $sql = "
            SELECT texto_nativo
            FROM frases
            WHERE texto_nativo IS NOT NULL
              AND usuario_id = :user_id
              AND TRIM(texto_nativo) <> ''
        ";

        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':user_id', $user_id, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    /* ======================================================
       PEGAR USER ID (SEM MEXER NO MIDDLEWARE)
    ====================================================== */
    private function getUserId()
    {
        global $user_id;

        if (!isset($user_id)) {
            throw new Exception('Usuário não autenticado.');
        }

        return (int) $user_id;
    }

    /* ======================================================
       HELPERS
    ====================================================== */
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

/* ======================================================
   EXECUÇÃO DA ROTA
====================================================== */

try {
    $controller = new DailyQuestionController($pdo);

    $method = $_SERVER['REQUEST_METHOD'];

    if ($method === 'GET') {
        $controller->getDailyQuestion();
    }

   if ($method === 'POST') {
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
    ], JSON_UNESCAPED_UNICODE);
}