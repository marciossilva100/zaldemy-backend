<?php

class FraseDoDia
{
    // Premium: 1 frase nova por dia. Limitado: 1 vitalícia (amostra grátis,
    // mesmo padrão do audio_ia_uso). Free: bloqueado.
    const LIMITE_DIARIO_PREMIUM = 1;
    const LIMITE_VITALICIO_LIMITADO = 1;
    const MAX_TENTATIVAS_POR_FRASE = 3;

    public static function contarHoje(PDO $pdo, int $user_id): int
    {
        $sql = "SELECT COUNT(*) as total FROM frase_dia_ia
                WHERE user_id = :user_id AND status_id = 1
                  AND DATE(data_criacao) = CURDATE()";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([':user_id' => $user_id]);
        return (int) $stmt->fetch(PDO::FETCH_ASSOC)['total'];
    }

    public static function contarTotal(PDO $pdo, int $user_id): int
    {
        $sql = "SELECT COUNT(*) as total FROM frase_dia_ia
                WHERE user_id = :user_id AND status_id = 1";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([':user_id' => $user_id]);
        return (int) $stmt->fetch(PDO::FETCH_ASSOC)['total'];
    }

    public static function verificarAcesso(PDO $pdo, int $user_id, int $plano): ?array
    {
        if ($plano === 1) {
            if (self::contarHoje($pdo, $user_id) >= self::LIMITE_DIARIO_PREMIUM) {
                return ["success" => false, "limite_atingido" => true, "message" => "Você já fez a frase do dia de hoje. Volte amanhã!"];
            }
            return null;
        }

        if ($plano === 3) {
            if (self::contarTotal($pdo, $user_id) >= self::LIMITE_VITALICIO_LIMITADO) {
                return ["success" => false, "limite_atingido" => true, "message" => "Você já usou sua amostra grátis da frase do dia. Vire premium para ter acesso diário."];
            }
            return null;
        }

        return ["success" => false, "premium_necessario" => true, "message" => "A frase do dia é um recurso exclusivo do plano Premium."];
    }

    private static function getPendente(PDO $pdo, int $user_id): ?array
    {
        $sql = "SELECT id, frase, frase_traducao FROM frase_dia_ia
                WHERE user_id = :user_id AND status_id = 0
                ORDER BY id DESC LIMIT 1";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([':user_id' => $user_id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    // Só conta como "estudada" a frase que já passou pelo menos uma vez pelo
    // treino 2 (memorizando) - treino_data_atualizacao é um histórico (várias
    // linhas por frase ao longo do tempo), então cobre o caso de ter voltado
    // pro treino 1 depois. Frases recém-cadastradas (inclusive as da categoria
    // criada automaticamente no cadastro, categorias.tipo=3) nunca chegaram
    // lá, então não contam pra liberar o recurso - só existir a frase não
    // significa que o aluno já estudou aquele conteúdo. Mesma checagem usada
    // em DailyQuestionOpenAI::contarFrasesEstudadas.
    private static function contarFrasesEstudadas(PDO $pdo, int $user_id): int
    {
        $sql = "SELECT COUNT(DISTINCT f.id) as total
                FROM frases f
                INNER JOIN idioma_referencia ir
                    ON ir.idioma_nativo = f.idioma_nativo
                    AND ir.idioma_aprender = f.idioma_aprendendo
                    AND ir.id_user = :user_id
                INNER JOIN treino_data_atualizacao t
                    ON t.id_frase = f.id
                    AND t.id_treino >= 2
                WHERE f.usuario_id = :user_id
                AND f.status_id > 0";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([':user_id' => $user_id]);
        return (int) $stmt->fetch(PDO::FETCH_ASSOC)['total'];
    }

    // $phrases: frases do próprio usuário no par de idioma atual (mesmo
    // filtro usado em Perguntas) - a frase gerada usa palavras/temas
    // parecidos com o que o aluno já estuda, em vez de ser genérica. Sem
    // frases suficientes, bloqueia (mesmo padrão de Perguntas) em vez de
    // gerar algo genérico que não cumpriria a promessa de personalização.
    // Gera também a tradução pro idioma nativo - a tela funciona como um
    // flashcard (frente = frase gerada, verso = tradução).
    public static function obterFraseDoDia(PDO $pdo, OpenAiChat $chat, int $user_id, string $idiomaNome, string $idiomaNativoNome, array $phrases = []): array
    {
        $pendente = self::getPendente($pdo, $user_id);

        if ($pendente) {
            return [
                "success" => true,
                "id" => (int) $pendente['id'],
                "frase" => $pendente['frase'],
                "traducao" => $pendente['frase_traducao'],
            ];
        }

        if (self::contarFrasesEstudadas($pdo, $user_id) < 3) {
            return ["success" => false, "message" => "Adicione mais frases aos flashcards para gerar sua frase do dia."];
        }

        $phrases = array_values(array_filter($phrases, fn($p) => str_word_count($p) >= 2));

        if (count($phrases) < 3) {
            return ["success" => false, "message" => "Adicione mais frases aos flashcards para gerar sua frase do dia."];
        }

        shuffle($phrases);
        $phrasesText = implode("\n", array_map(fn($p) => mb_substr($p, 0, 220), array_slice($phrases, 0, 30)));

        $systemPrompt = "Você é um professor de idiomas. Gere UMA frase de exemplo em {$idiomaNome}, natural e do dia a dia, "
            . "adequada pra um aluno ler em voz alta como exercício de pronúncia, e também a tradução dela em {$idiomaNativoNome}. "
            . "Baseie pelo menos 80% do vocabulário da frase nas frases que o aluno já estuda (fornecidas a seguir), pra "
            . "reforçar o que ele está aprendendo - a frase não precisa ser idêntica a nenhuma delas. O restante do "
            . "vocabulário pode ser palavras comuns do idioma, inclusive artigos, conectivos e concordância gramatical "
            . "necessários pra frase soar natural. Máximo 220 caracteres na frase. Gramaticalmente correta. Não repita "
            . "estruturas óbvias como 'My name is'. "
            . 'Responda em JSON: {"frase": "...", "traducao": "..."}';

        $userContent = "Frases que o aluno já estuda:\n" . $phrasesText;

        $resultado = $chat->completar([
            ['role' => 'system', 'content' => $systemPrompt],
            ['role' => 'user', 'content' => $userContent],
        ], true, 400);

        if ($resultado['erro']) {
            return ["success" => false, "message" => "Não foi possível gerar a frase: " . $resultado['mensagem']];
        }

        $decodificado = json_decode($resultado['texto'], true);

        if (!is_array($decodificado) || empty($decodificado['frase'])) {
            return ["success" => false, "message" => "Resposta inválida da IA."];
        }

        $frase = mb_substr(trim((string) $decodificado['frase'], "\" \n\r\t"), 0, 220);
        $traducao = mb_substr(trim((string) ($decodificado['traducao'] ?? ''), "\" \n\r\t"), 0, 220);

        $stmt = $pdo->prepare("INSERT INTO frase_dia_ia (user_id, frase, frase_traducao, status_id) VALUES (:user_id, :frase, :traducao, 0)");
        $stmt->execute([':user_id' => $user_id, ':frase' => $frase, ':traducao' => $traducao]);

        return ["success" => true, "id" => (int) $pdo->lastInsertId(), "frase" => $frase, "traducao" => $traducao];
    }

    // Usado quando a tentativa não chega a gerar nota (áudio vazio ou
    // conteúdo impróprio) - mesmo assim consome uma tentativa e uma chamada
    // de IA de verdade (transcrição), então tem que contar pro limite de
    // MAX_TENTATIVAS_POR_FRASE, senão dá pra ficar gravando áudio vazio pra
    // sempre sem nunca fechar a frase (gastando tokens à toa).
    private static function registrarTentativaSemNota(PDO $pdo, int $fraseId, int $tentativaAtual, string $transcricao): bool
    {
        $esgotou = $tentativaAtual >= self::MAX_TENTATIVAS_POR_FRASE;

        $stmt = $pdo->prepare("
            UPDATE frase_dia_ia
            SET status_id = :status_id, tentativas = :tentativas, transcricao = :transcricao
            WHERE id = :id
        ");
        $stmt->execute([
            ':status_id' => $esgotou ? 1 : 0,
            ':tentativas' => $tentativaAtual,
            ':transcricao' => $transcricao,
            ':id' => $fraseId,
        ]);

        return $esgotou;
    }

    public static function responder(
        PDO $pdo,
        OpenAiChat $chat,
        OpenAiTranscribe $transcribe,
        int $user_id,
        int $fraseId,
        string $caminhoAudio,
        string $mimeType
    ): array {
        $stmt = $pdo->prepare("SELECT frase, tentativas FROM frase_dia_ia WHERE id = :id AND user_id = :user_id AND status_id = 0");
        $stmt->execute([':id' => $fraseId, ':user_id' => $user_id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            return ["success" => false, "message" => "Frase não encontrada ou já respondida."];
        }

        $frase = $row['frase'];
        $tentativaAtual = (int) $row['tentativas'] + 1;

        // O nome do arquivo enviado pra API precisa ter a extensão certa pro
        // formato ser detectado corretamente - um mp3 com nome "audio.webm"
        // falha na transcrição.
        $nomeArquivo = "audio." . self::extensaoParaMime($mimeType);
        $transcricaoResult = $transcribe->transcrever($caminhoAudio, $nomeArquivo, $mimeType);

        if ($transcricaoResult['erro']) {
            return ["success" => false, "message" => "Não foi possível transcrever o áudio: " . $transcricaoResult['mensagem']];
        }

        $transcricao = $transcricaoResult['texto'];

        // A API pode responder 200 com texto vazio (áudio silencioso, baixo
        // demais, ruído só) - sem essa checagem isso seguia pra correção com
        // uma transcrição vazia, gerando um feedback sem sentido.
        if (trim($transcricao) === '') {
            $esgotou = self::registrarTentativaSemNota($pdo, $fraseId, $tentativaAtual, $transcricao);

            return [
                "success" => false,
                "audio_vazio" => true,
                "pode_tentar_novamente" => !$esgotou,
                "message" => $esgotou
                    ? "Não conseguimos identificar sua fala nas últimas tentativas."
                    : "Não conseguimos identificar sua fala no áudio. Tente gravar de novo, falando mais perto do microfone."
            ];
        }

        // Mesma checagem já aplicada em frases/categorias - a transcrição é
        // texto que o próprio usuário falou, sem moderação nenhuma antes disso.
        if (verificarConteudoImproprio($transcricao)) {
            $esgotou = self::registrarTentativaSemNota($pdo, $fraseId, $tentativaAtual, $transcricao);

            return [
                "success" => false,
                "pode_tentar_novamente" => !$esgotou,
                "message" => "O áudio contém conteúdo impróprio."
            ];
        }

        $systemPrompt = "Você é um professor de idiomas avaliando a leitura em voz alta de um aluno. "
            . "Vai receber a frase original e a transcrição de voz-pra-texto do que o aluno disse. "
            . "Divergências entre elas podem indicar erro de pronúncia, palavra trocada/omitida ou hesitação. "
            . "Avalie gramática, pronúncia (com base na divergência da transcrição) e fluência. "
            . "Dê nota de 0 a 10 e feedback curto (máx 150 caracteres cada campo) em português, gentil e específico. "
            . 'Responda em JSON: {"nota": 0-10, "feedback_gramatica": "...", "feedback_pronuncia": "...", "feedback_fluencia": "..."}';

        $correcaoResult = $chat->completar([
            ['role' => 'system', 'content' => $systemPrompt],
            ['role' => 'user', 'content' => "Frase original: {$frase}\nTranscrição: {$transcricao}"],
        ], true, 500);

        if ($correcaoResult['erro']) {
            return ["success" => false, "message" => "Não foi possível corrigir: " . $correcaoResult['mensagem']];
        }

        $correcao = json_decode($correcaoResult['texto'], true);

        if (!is_array($correcao) || !isset($correcao['nota'])) {
            return ["success" => false, "message" => "Resposta inválida da IA."];
        }

        $nota = max(0, min(10, (int) $correcao['nota']));
        $fbGramatica = mb_substr((string) ($correcao['feedback_gramatica'] ?? ''), 0, 250);
        $fbPronuncia = mb_substr((string) ($correcao['feedback_pronuncia'] ?? ''), 0, 250);
        $fbFluencia = mb_substr((string) ($correcao['feedback_fluencia'] ?? ''), 0, 250);

        $stmt = $pdo->prepare("
            UPDATE frase_dia_ia
            SET status_id = 1, tentativas = :tentativas, transcricao = :transcricao, nota = :nota,
                feedback_gramatica = :fg, feedback_pronuncia = :fp, feedback_fluencia = :ff
            WHERE id = :id
        ");
        $stmt->execute([
            ':tentativas' => $tentativaAtual,
            ':transcricao' => $transcricao,
            ':nota' => $nota,
            ':fg' => $fbGramatica,
            ':fp' => $fbPronuncia,
            ':ff' => $fbFluencia,
            ':id' => $fraseId,
        ]);

        return [
            "success" => true,
            "transcricao" => $transcricao,
            "nota" => $nota,
            "feedback_gramatica" => $fbGramatica,
            "feedback_pronuncia" => $fbPronuncia,
            "feedback_fluencia" => $fbFluencia,
        ];
    }

    private static function extensaoParaMime(string $mimeType): string
    {
        $mapa = [
            'audio/webm' => 'webm',
            'audio/ogg' => 'ogg',
            'audio/mp4' => 'm4a',
            'audio/mpeg' => 'mp3',
            'audio/mp3' => 'mp3',
            'audio/wav' => 'wav',
            'audio/x-wav' => 'wav',
        ];

        return $mapa[$mimeType] ?? 'webm';
    }

    // Nome do idioma que o usuário está aprendendo, pro prompt de geração.
    // Frases do próprio usuário, só do par de idioma (nativo/aprendendo)
    // ATUAL - mesmo filtro usado em DailyQuestionController::getUserPhrases,
    // pra não misturar frases de um idioma que o usuário já trocou.
    public static function getFrasesDoUsuario(PDO $pdo, int $user_id): array
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

        $stmt = $pdo->prepare($sql);
        $stmt->execute([':user_id' => $user_id]);

        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    public static function getIdiomaAprendendo(PDO $pdo, int $user_id): string
    {
        $sql = "SELECT i.idioma
                FROM idioma_referencia ir
                JOIN idiomas i ON i.id = ir.idioma_aprender
                WHERE ir.id_user = :user_id
                LIMIT 1";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([':user_id' => $user_id]);
        $idioma = $stmt->fetch(PDO::FETCH_ASSOC)['idioma'] ?? null;

        return $idioma ?: 'inglês';
    }

    public static function getIdiomaNativo(PDO $pdo, int $user_id): string
    {
        $sql = "SELECT i.idioma
                FROM idioma_referencia ir
                JOIN idiomas i ON i.id = ir.idioma_nativo
                WHERE ir.id_user = :user_id
                LIMIT 1";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([':user_id' => $user_id]);
        $idioma = $stmt->fetch(PDO::FETCH_ASSOC)['idioma'] ?? null;

        return $idioma ?: 'português';
    }
}
