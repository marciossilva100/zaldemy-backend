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
    "https://memly-jijk.vercel.app",
    "https://localhost", // app nativo Android/iOS via Capacitor
    "capacitor://localhost" // WKWebView do Capacitor no iOS
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
require_once '../model/DailyQuestionOpenAI.php';
require_once '../model/PlanoLimitado.php';
require_once '../model/Nivel.php';
require_once __DIR__ . '/../api/OpenAiChat.php';
require_once __DIR__ . '/../api/OpenAiTranscribe.php';
require_once 'moderation.php';
require_once __DIR__ . '/../dotenv.php';

carregarEnv(__DIR__ . '/../.env');

class DailyQuestionController
{
    private $pdo;
    private $chat;
    private $transcribe;

    public function __construct(PDO $pdo, string $apiKey)
    {
        $this->pdo = $pdo;
        $this->chat = new OpenAiChat($apiKey);
        $this->transcribe = new OpenAiTranscribe($apiKey);
    }

    /* ===============================
       SKIP
    =============================== */
    public function skipDailyQuestion()
    {
        try {
            $user_id = $this->getUserId();

            $sql = "UPDATE perguntas_ia
                    SET status_id = 1
                    WHERE user_id = :user_id AND status_id = 0
                    ORDER BY id DESC LIMIT 1";

            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([':user_id' => $user_id]);

            $this->json(['success' => true]);
        } catch (Exception $e) {
            $this->error($e);
        }
    }

    /* ===============================
       GET → OBTER PERGUNTA
    =============================== */
    public function getDailyQuestion()
    {
        try {
            $user_id = $this->getUserId();

            $bloqueio = DailyQuestionOpenAI::verificarAcesso($this->pdo, $user_id, $this->getPlano());

            if ($bloqueio !== null) {
                $this->json($bloqueio);
                return;
            }

            $phrases = $this->getUserPhrases($user_id);
            $idioma = $this->getIdiomaAprendendo($user_id);
            $idiomaNativo = $this->getIdiomaNativo($user_id);
            $nivel = DailyQuestionOpenAI::getNivelNome($this->pdo, $user_id);

            $resultado = DailyQuestionOpenAI::obterPergunta($this->pdo, $this->chat, $user_id, $phrases, $idioma, $idiomaNativo, $nivel);

            if ($resultado['success']) {
                $plano = $this->getPlano();

                if ($plano === 1) {
                    $resultado['numero'] = DailyQuestionOpenAI::contarHoje($this->pdo, $user_id) + 1;
                    $resultado['total'] = DailyQuestionOpenAI::LIMITE_DIARIO_PREMIUM;
                } else {
                    $resultado['numero'] = DailyQuestionOpenAI::contarTotal($this->pdo, $user_id) + 1;
                    $resultado['total'] = DailyQuestionOpenAI::LIMITE_VITALICIO_LIMITADO;
                }
            }

            $this->json($resultado);
        } catch (Exception $e) {
            $this->error($e);
        }
    }

    /* ===============================
       POST → RESPONDER (áudio, multipart)
    =============================== */
    public function answerDailyQuestion()
    {
        try {
            $user_id = $this->getUserId();

            $bloqueio = DailyQuestionOpenAI::verificarAcesso($this->pdo, $user_id, $this->getPlano());

            if ($bloqueio !== null) {
                $this->json($bloqueio);
                return;
            }

            $perguntaId = (int) ($_POST['question_id'] ?? 0);

            if (!$perguntaId) {
                throw new Exception('question_id obrigatório.');
            }

            if (empty($_FILES['audio']) || $_FILES['audio']['error'] !== UPLOAD_ERR_OK) {
                throw new Exception('Áudio obrigatório.');
            }

            $resultado = DailyQuestionOpenAI::responder(
                $this->pdo,
                $this->chat,
                $this->transcribe,
                $user_id,
                $perguntaId,
                $_FILES['audio']['tmp_name'],
                $_FILES['audio']['type'] ?: 'audio/webm',
                $this->getIdiomaAprendendo($user_id),
                $this->getIdiomaNativo($user_id)
            );

            if ($resultado['success'] && !($resultado['pode_tentar_novamente'] ?? false)) {
                PlanoLimitado::verificarEDowngradear($this->pdo, $user_id, $this->getPlano());
            }

            $this->json($resultado);
        } catch (Exception $e) {
            $this->error($e);
        }
    }

    /* ===============================
       GET → HISTÓRICO DE RESPOSTAS
    =============================== */
    public function getHistorico()
    {
        try {
            $user_id = $this->getUserId();

            $sql = "SELECT question, transcricao, nota, feedback, data_criacao
                    FROM perguntas_ia
                    WHERE user_id = :user_id AND status_id = 1 AND nota IS NOT NULL
                    ORDER BY id DESC
                    LIMIT 30";

            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([':user_id' => $user_id]);

            $this->json([
                'success' => true,
                'historico' => $stmt->fetchAll(PDO::FETCH_ASSOC)
            ]);
        } catch (Exception $e) {
            $this->error($e);
        }
    }

    // Só frases do par de idioma (nativo/aprendendo) ATUAL do usuário -
    // sem esse filtro, alguém que já trocou de idioma de estudo (ou tem
    // frases de mais de um par cadastradas) tinha tudo misturado indo pra
    // IA junto, gerando pergunta sem relação nenhuma com o que está
    // estudando agora. Mesmo filtro de idioma_referencia já usado em
    // Categorias::contarCategoriasAtivas.
    private function getUserPhrases($user_id)
    {
        $sql = "SELECT f.texto_traduzido
                FROM frases f
                INNER JOIN idioma_referencia ir
                    ON ir.idioma_nativo = f.idioma_nativo
                    AND ir.idioma_aprender = f.idioma_aprendendo
                    AND ir.id_user = :user_id
                WHERE f.texto_traduzido IS NOT NULL
                AND f.usuario_id = :user_id
                AND TRIM(f.texto_nativo) <> ''
                AND f.status_id > 0";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':user_id' => $user_id]);

        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    private function getIdiomaAprendendo($user_id): string
    {
        $sql = "SELECT i.idioma
                FROM idioma_referencia ir
                JOIN idiomas i ON i.id = ir.idioma_aprender
                WHERE ir.id_user = :user_id
                LIMIT 1";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':user_id' => $user_id]);
        $idioma = $stmt->fetch(PDO::FETCH_ASSOC)['idioma'] ?? null;

        return $idioma ?: 'inglês';
    }

    private function getIdiomaNativo($user_id): string
    {
        $sql = "SELECT i.idioma
                FROM idioma_referencia ir
                JOIN idiomas i ON i.id = ir.idioma_nativo
                WHERE ir.id_user = :user_id
                LIMIT 1";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':user_id' => $user_id]);
        $idioma = $stmt->fetch(PDO::FETCH_ASSOC)['idioma'] ?? null;

        return $idioma ?: 'português';
    }

    private function getUserId()
    {
        global $user_id;

        if (!isset($user_id)) {
            throw new Exception('Usuário não autenticado.');
        }

        return (int) $user_id;
    }

    private function getPlano()
    {
        global $user;

        return (int) ($user['plano'] ?? 0);
    }

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
            'message' => $e->getMessage()
        ], JSON_UNESCAPED_UNICODE);

        exit;
    }
}

/* ===============================
   ROTA
=============================== */

try {
    if (!isset($_ENV['OPEN_AI'])) {
        throw new Exception('API KEY não configurada.');
    }

    $controller = new DailyQuestionController($pdo, $_ENV['OPEN_AI']);

    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        if (($_GET['action'] ?? null) === 'historico') {
            $controller->getHistorico();
        } else {
            $controller->getDailyQuestion();
        }
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $action = $_POST['action'] ?? null;

        if ($action === 'skip') {
            $controller->skipDailyQuestion();
        } else {
            $controller->answerDailyQuestion();
        }
    }

} catch (Exception $e) {
    http_response_code(500);

    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
