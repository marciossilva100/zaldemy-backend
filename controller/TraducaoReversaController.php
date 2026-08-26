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
require_once '../model/TraducaoReversaOpenAI.php';
require_once '../model/PlanoLimitado.php';
require_once '../model/Nivel.php';
require_once __DIR__ . '/../api/OpenAiChat.php';
require_once __DIR__ . '/../api/OpenAiTranscribe.php';
require_once 'moderation.php';
require_once __DIR__ . '/../dotenv.php';

carregarEnv(__DIR__ . '/../.env');

class TraducaoReversaController
{
    // Mesmo limite/motivo de DailyQuestionController - manda tudo cadastrado
    // gastaria tokens à toa pra quem tem centenas de frases.
    const MAX_FRASES_PROMPT = 50;

    private $pdo;
    private $chat;
    private $chatGeracao;
    private $transcribe;

    public function __construct(PDO $pdo, string $apiKey)
    {
        $this->pdo = $pdo;
        // gpt-5-mini pros dois passos (geração E avaliação) - diferente de
        // Perguntas (nano só corrige). Testado direto na API: nano errava
        // consistentemente paráfrases válidas com negação dupla ("sempre
        // levo X" -> "nunca viajo sem X"), marcando como sentido errado
        // mesmo com instrução explícita no prompt; mini acerta esse caso.
        $this->chat = new OpenAiChat($apiKey, "gpt-5-mini");
        $this->chatGeracao = new OpenAiChat($apiKey, "gpt-5-mini");
        $this->transcribe = new OpenAiTranscribe($apiKey);
    }

    /* ===============================
       SKIP
    =============================== */
    public function skip()
    {
        try {
            $user_id = $this->getUserId();

            $sql = "UPDATE traducao_reversa_ia
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
       GET → OBTER TEXTO
    =============================== */
    public function verificarAcessoRoute()
    {
        try {
            $user_id = $this->getUserId();
            $bloqueio = TraducaoReversaOpenAI::verificarAcesso($this->pdo, $user_id, $this->getPlano());
            $this->json(["acesso" => $bloqueio === null]);
        } catch (Exception $e) {
            $this->error($e);
        }
    }

    public function getTexto()
    {
        try {
            $user_id = $this->getUserId();

            $bloqueio = TraducaoReversaOpenAI::verificarAcesso($this->pdo, $user_id, $this->getPlano());

            if ($bloqueio !== null) {
                $this->json($bloqueio);
                return;
            }

            $phrases = $this->getUserPhrases($user_id);
            $idiomaNativo = $this->getIdiomaNativo($user_id);
            $idiomaAprendendo = $this->getIdiomaAprendendo($user_id);
            $nivel = TraducaoReversaOpenAI::getNivelNome($this->pdo, $user_id);

            $resultado = TraducaoReversaOpenAI::obterTexto($this->pdo, $this->chatGeracao, $user_id, $phrases, $idiomaNativo, $idiomaAprendendo, $nivel);

            if ($resultado['success']) {
                $resultado['idioma_alvo'] = $idiomaAprendendo;

                $plano = $this->getPlano();

                if ($plano === 1) {
                    $resultado['numero'] = TraducaoReversaOpenAI::contarHoje($this->pdo, $user_id) + 1;
                    $resultado['total'] = TraducaoReversaOpenAI::LIMITE_DIARIO_PREMIUM;
                } else {
                    $resultado['numero'] = TraducaoReversaOpenAI::contarHoje($this->pdo, $user_id) + 1;
                    $resultado['total'] = TraducaoReversaOpenAI::LIMITE_DIARIO_LIMITADO;
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
    public function responderAudio()
    {
        try {
            $user_id = $this->getUserId();

            $bloqueio = TraducaoReversaOpenAI::verificarAcesso($this->pdo, $user_id, $this->getPlano());

            if ($bloqueio !== null) {
                $this->json($bloqueio);
                return;
            }

            $id = (int) ($_POST['id'] ?? 0);

            if (!$id) {
                throw new Exception('id obrigatório.');
            }

            if (empty($_FILES['audio']) || $_FILES['audio']['error'] !== UPLOAD_ERR_OK) {
                throw new Exception('Áudio obrigatório.');
            }

            $resultado = TraducaoReversaOpenAI::responder(
                $this->pdo,
                $this->chat,
                $this->transcribe,
                $user_id,
                $id,
                $_FILES['audio']['tmp_name'],
                $_FILES['audio']['type'] ?: 'audio/webm',
                $this->getIdiomaNativo($user_id),
                $this->getIdiomaAprendendo($user_id)
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
       POST → RESPONDER (texto digitado)
    =============================== */
    public function responderTexto()
    {
        try {
            $user_id = $this->getUserId();

            $bloqueio = TraducaoReversaOpenAI::verificarAcesso($this->pdo, $user_id, $this->getPlano());

            if ($bloqueio !== null) {
                $this->json($bloqueio);
                return;
            }

            $id = (int) ($_POST['id'] ?? 0);
            $respostaTexto = (string) ($_POST['resposta'] ?? '');

            if (!$id) {
                throw new Exception('id obrigatório.');
            }

            $resultado = TraducaoReversaOpenAI::responderTexto(
                $this->pdo,
                $this->chat,
                $user_id,
                $id,
                $respostaTexto,
                $this->getIdiomaNativo($user_id),
                $this->getIdiomaAprendendo($user_id)
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

            // Mesmo raciocínio de DailyQuestionController::getHistorico() -
            // status_id=1 sem nota é um texto pulado ou que esgotou tentativas
            // sem nunca ter sido avaliado, não deve sumir do histórico.
            $sql = "SELECT texto_nativo, resposta, nota, feedback, texto_traduzido_gabarito, data_criacao
                    FROM traducao_reversa_ia
                    WHERE user_id = :user_id AND status_id = 1
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

    // Só frases do par de idioma ATUAL do usuário - mesmo filtro de
    // DailyQuestionController::getUserPhrases. SEM fallback relaxado
    // (diferente de Perguntas/Frase do Dia): o conteúdo tem que vir de
    // frases com id_treino >= 2 sempre, senão cai em conteúdo_insuficiente -
    // pedido explícito do usuário.
    private function getUserPhrases($user_id)
    {
        $sql = "SELECT f.texto_nativo
                FROM frases f
                INNER JOIN idioma_referencia ir
                    ON ir.idioma_nativo = f.idioma_nativo
                    AND ir.idioma_aprender = f.idioma_aprendendo
                    AND ir.id_user = :user_id
                WHERE f.texto_nativo IS NOT NULL
                AND f.usuario_id = :user_id
                AND TRIM(f.texto_nativo) <> ''
                AND f.status_id > 0
                AND f.id_treino >= 2
                ORDER BY f.id_treino DESC, RAND()
                LIMIT " . self::MAX_FRASES_PROMPT;

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

    $controller = new TraducaoReversaController($pdo, $_ENV['OPEN_AI']);

    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        if (($_GET['action'] ?? null) === 'historico') {
            $controller->getHistorico();
        } elseif (($_GET['action'] ?? null) === 'verificar_acesso') {
            $controller->verificarAcessoRoute();
        } else {
            $controller->getTexto();
        }
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $action = $_POST['action'] ?? null;

        if ($action === 'skip') {
            $controller->skip();
        } elseif ($action === 'responder_texto') {
            $controller->responderTexto();
        } else {
            $controller->responderAudio();
        }
    }

} catch (Exception $e) {
    http_response_code(500);

    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
