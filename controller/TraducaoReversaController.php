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
require_once '../model/RotacaoFrasesIA.php';
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

    // Piso mínimo de frases garantidas por categoria elegível no pool
    // enviado pra IA - ver balancearPorCategoria() abaixo.
    const MINIMO_GARANTIDO_POR_CATEGORIA = 2;

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
    // A 1ª tentativa de corrigir isso (janela global maior, LIMIT 300) não
    // bastava: ORDER BY id_treino DESC é aplicado ANTES do LIMIT pra tabela
    // INTEIRA, não por categoria - se uma única categoria já tem, sozinha,
    // mais itens no nível mais alto (id_treino=4) do que o tamanho da
    // janela (caso real de produção: 1 categoria com 451 frases id_treino=4,
    // outras 5 categorias em id_treino=2/3 com só 1-15 frases cada), a
    // janela inteira é preenchida só por ela ANTES de qualquer categoria
    // menor ter chance de entrar - não importa quão maior a janela seja.
    // Corrigido de vez com ROW_NUMBER() OVER (PARTITION BY categoria_id...):
    // cada categoria é rankeada (por id_treino DESC, RAND()) DENTRO DE SI
    // MESMA, então o corte por rn <= N garante até N candidatos de CADA
    // categoria elegível, não da tabela inteira - nenhuma categoria consegue
    // mais "roubar o espaço" de outra na hora de montar os candidatos.
    private function getUserPhrases($user_id)
    {
        $sql = "SELECT categoria_id, texto_nativo FROM (
                    SELECT f.categoria_id, f.texto_nativo,
                        ROW_NUMBER() OVER (
                            PARTITION BY f.categoria_id
                            ORDER BY f.id_treino DESC, RAND()
                        ) AS rn
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
                ) candidatos
                WHERE rn <= " . self::MAX_FRASES_PROMPT;

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':user_id' => $user_id]);
        $linhas = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Afasta do pool as frases usadas como fonte nas últimas gerações
        // (dos 3 treinos de IA, ver RotacaoFrasesIA) - sem isso, frases de
        // categorias pequenas cabem quase sempre no sorteio de 50 e a IA
        // repete a mesma frase "fácil" toda vez que ela está disponível
        // (confirmado com dados reais: 1 frase em 40% de 50 gerações). Só
        // filtra se sobrar pelo menos 3 depois - com pouco vocabulário, é
        // melhor repetir do que gerar sem matéria-prima nenhuma.
        $excluir = array_flip(RotacaoFrasesIA::textosParaExcluir($this->pdo, $user_id));
        $semRecentes = array_values(array_filter(
            $linhas,
            fn($linha) => !isset($excluir[$linha['texto_nativo']])
        ));
        if (count($semRecentes) >= 3) {
            $linhas = $semRecentes;
        }

        return $this->balancearPorCategoria($linhas);
    }

    // Round-robin puro (testado antes) dava peso IGUAL pra toda categoria,
    // não importa o tamanho - uma categoria de 5 frases valia o mesmo que
    // uma de 455. Feedback do usuário: se uma categoria tem mais frases
    // estudadas, é natural/esperado que ela contribua mais - o problema
    // era só nenhuma categoria ficar em ZERO, não o volume ser desigual.
    // Por isso: reserva um piso mínimo (MINIMO_GARANTIDO_POR_CATEGORIA) de
    // cada categoria elegível pra garantir que nenhuma some, e preenche o
    // resto por sorteio livre entre TODAS as frases restantes - aí sim a
    // categoria maior naturalmente contribui mais, proporcional ao volume
    // real dela. Categorias são embaralhadas antes de reservar o piso, e a
    // reserva só é aplicada se ainda couber no limite total - protege
    // contra o caso de muitas categorias pequenas (ex: dezenas de
    // categorias de teste) tomando o pool inteiro só na garantia mínima.
    private function balancearPorCategoria(array $linhas): array
    {
        $porCategoria = [];
        foreach ($linhas as $linha) {
            $porCategoria[$linha['categoria_id']][] = $linha['texto_nativo'];
        }

        $idsCategorias = array_keys($porCategoria);
        shuffle($idsCategorias);

        $reservadas = [];
        $restante = [];

        foreach ($idsCategorias as $categoriaId) {
            $frasesCategoria = $porCategoria[$categoriaId];
            shuffle($frasesCategoria);
            $corte = min(count($frasesCategoria), self::MINIMO_GARANTIDO_POR_CATEGORIA);

            if (count($reservadas) + $corte <= self::MAX_FRASES_PROMPT) {
                $reservadas = array_merge($reservadas, array_slice($frasesCategoria, 0, $corte));
                $restante = array_merge($restante, array_slice($frasesCategoria, $corte));
            } else {
                $restante = array_merge($restante, $frasesCategoria);
            }
        }

        shuffle($restante);
        $selecionadas = $reservadas;
        $faltam = self::MAX_FRASES_PROMPT - count($selecionadas);

        if ($faltam > 0) {
            $selecionadas = array_merge($selecionadas, array_slice($restante, 0, $faltam));
        }

        return array_slice($selecionadas, 0, self::MAX_FRASES_PROMPT);
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
