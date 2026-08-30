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
require_once '../model/RotacaoFrasesIA.php';
require_once '../model/PlanoLimitado.php';
require_once '../model/Nivel.php';
require_once __DIR__ . '/../api/OpenAiChat.php';
require_once __DIR__ . '/../api/OpenAiTranscribe.php';
require_once 'moderation.php';
require_once __DIR__ . '/../dotenv.php';

carregarEnv(__DIR__ . '/../.env');

class DailyQuestionController
{
    // Limite de frases enviadas pro prompt da IA - mandar tudo que o usuário
    // já tem cadastrado gastaria tokens/tempo de processamento à toa,
    // principalmente pra quem tem centenas de frases. Prioriza as mais bem
    // estudadas (id_treino mais alto - "memorizado" antes de "em treino"
    // antes de "memorizando"), nunca as ainda não estudadas (id_treino=1).
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
        $this->chat = new OpenAiChat($apiKey);
        // gpt-5-mini só pra gerar a pergunta - testado direto na API, combina
        // os trechos das frases do aluno de forma bem mais coerente que o
        // nano nessa tarefa específica. A correção da resposta (mais simples,
        // não precisa "compor" texto novo a partir de várias frases soltas)
        // continua no nano de $this->chat.
        $this->chatGeracao = new OpenAiChat($apiKey, "gpt-5-mini");
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
    // Checagem leve (sem gerar nada) pro ModalIA decidir se mostra o selo de
    // premium no botão antes mesmo do usuário clicar - mesma regra de
    // verificarAcesso, incluindo a amostra vitalícia do limitado expirada.
    public function verificarAcessoRoute()
    {
        try {
            $user_id = $this->getUserId();
            $bloqueio = DailyQuestionOpenAI::verificarAcesso($this->pdo, $user_id, $this->getPlano());
            $this->json(["acesso" => $bloqueio === null]);
        } catch (Exception $e) {
            $this->error($e);
        }
    }

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

            $resultado = DailyQuestionOpenAI::obterPergunta($this->pdo, $this->chatGeracao, $user_id, $phrases, $idioma, $idiomaNativo, $nivel);

            if ($resultado['success']) {
                $plano = $this->getPlano();

                if ($plano === 1) {
                    $resultado['numero'] = DailyQuestionOpenAI::contarHoje($this->pdo, $user_id) + 1;
                    $resultado['total'] = DailyQuestionOpenAI::LIMITE_DIARIO_PREMIUM;
                } else {
                    $resultado['numero'] = DailyQuestionOpenAI::contarHoje($this->pdo, $user_id) + 1;
                    $resultado['total'] = DailyQuestionOpenAI::LIMITE_DIARIO_LIMITADO;
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
       POST → RESPONDER (texto digitado)
    =============================== */
    public function answerDailyQuestionTexto()
    {
        try {
            $user_id = $this->getUserId();

            $bloqueio = DailyQuestionOpenAI::verificarAcesso($this->pdo, $user_id, $this->getPlano());

            if ($bloqueio !== null) {
                $this->json($bloqueio);
                return;
            }

            $perguntaId = (int) ($_POST['question_id'] ?? 0);
            $respostaTexto = (string) ($_POST['resposta'] ?? '');

            if (!$perguntaId) {
                throw new Exception('question_id obrigatório.');
            }

            $resultado = DailyQuestionOpenAI::responderTexto(
                $this->pdo,
                $this->chat,
                $user_id,
                $perguntaId,
                $respostaTexto,
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

            // status_id=1 sem nota é uma pergunta que foi gerada mas nunca
            // chegou a ter nota (pulada, ou áudio vazio/conteúdo impróprio
            // esgotando as tentativas) - antes ficava invisível no histórico,
            // como se a pergunta nunca tivesse existido. Mostra também,
            // o front trata nota null como "não respondida".
            $sql = "SELECT question, transcricao, nota, feedback, data_criacao
                    FROM perguntas_ia
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

    // Só frases do par de idioma (nativo/aprendendo) ATUAL do usuário -
    // sem esse filtro, alguém que já trocou de idioma de estudo (ou tem
    // frases de mais de um par cadastradas) tinha tudo misturado indo pra
    // IA junto, gerando pergunta sem relação nenhuma com o que está
    // estudando agora. Mesmo filtro de idioma_referencia já usado em
    // Categorias::contarCategoriasAtivas.
    private function getUserPhrases($user_id)
    {
        $phrases = $this->buscarFrasesPorEstagio($user_id, true);

        // O gate que libera o recurso (DailyQuestionOpenAI::contarFrasesEstudadas)
        // conta pelo histórico (já alcançou id_treino>=2 alguma vez), mas essa
        // busca usa o estágio ATUAL da frase - se as frases regrediram todas
        // de volta pro estágio 1 depois de já terem liberado o recurso, essa
        // busca podia vir vazia mesmo com o gate liberado. Cai pra buscar sem
        // esse filtro (mantendo a priorização por estágio) em vez de bloquear
        // a geração por falta de frases que na verdade existem.
        if (count($phrases) < 3) {
            $phrases = $this->buscarFrasesPorEstagio($user_id, false);
        }

        return $phrases;
    }

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
    private function buscarFrasesPorEstagio($user_id, bool $exigirTreinoMinimo)
    {
        $sql = "SELECT categoria_id, texto_traduzido FROM (
                    SELECT f.categoria_id, f.texto_traduzido,
                        ROW_NUMBER() OVER (
                            PARTITION BY f.categoria_id
                            ORDER BY f.id_treino DESC, RAND()
                        ) AS rn
                    FROM frases f
                    INNER JOIN idioma_referencia ir
                        ON ir.idioma_nativo = f.idioma_nativo
                        AND ir.idioma_aprender = f.idioma_aprendendo
                        AND ir.id_user = :user_id
                    WHERE f.texto_traduzido IS NOT NULL
                    AND f.usuario_id = :user_id
                    AND TRIM(f.texto_nativo) <> ''
                    AND f.status_id > 0"
                    . ($exigirTreinoMinimo ? " AND f.id_treino >= 2" : "") . "
                ) candidatos
                WHERE rn <= " . self::MAX_FRASES_PROMPT;

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':user_id' => $user_id]);
        $linhas = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Afasta do pool as frases usadas como fonte nas últimas gerações
        // (dos 3 treinos de IA, ver RotacaoFrasesIA) - sem isso, frases de
        // categorias pequenas cabem quase sempre no sorteio de 50 e a IA
        // repete a mesma frase "fácil" toda vez que ela está disponível
        // (confirmado com dados reais: 1 frase em 36% de 50 gerações). Só
        // filtra se sobrar pelo menos 3 depois - com pouco vocabulário, é
        // melhor repetir do que gerar sem matéria-prima nenhuma.
        $excluir = array_flip(RotacaoFrasesIA::textosParaExcluir($this->pdo, $user_id));
        $semRecentes = array_values(array_filter(
            $linhas,
            fn($linha) => !isset($excluir[$linha['texto_traduzido']])
        ));
        if (count($semRecentes) >= 3) {
            $linhas = $semRecentes;
        }

        return $this->balancearPorCategoria($linhas);
    }

    // Agrupa por categoria (cada uma já vem ordenada por prioridade de
    // treino, graças ao ORDER BY da query acima) e intercala 1 frase de
    // cada categoria por volta, até bater o limite final - garante que
    // nenhuma categoria domine o pool só por ter mais frases (ou frases
    // mais adiantadas no treino) que as outras.
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
            $porCategoria[$linha['categoria_id']][] = $linha['texto_traduzido'];
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

    $controller = new DailyQuestionController($pdo, $_ENV['OPEN_AI']);

    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        if (($_GET['action'] ?? null) === 'historico') {
            $controller->getHistorico();
        } elseif (($_GET['action'] ?? null) === 'verificar_acesso') {
            $controller->verificarAcessoRoute();
        } else {
            $controller->getDailyQuestion();
        }
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $action = $_POST['action'] ?? null;

        if ($action === 'skip') {
            $controller->skipDailyQuestion();
        } elseif ($action === 'responder_texto') {
            $controller->answerDailyQuestionTexto();
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
