<?php

// Versão OpenAI (gpt-5-nano) do recurso de perguntas diárias - substitui
// model/DailyQuestionIA.php (Groq) no fluxo ativo, mas não o exclui (mesmo
// padrão de api/ElevenLabs.php: fica intocado, só sem uso).
// Diferença principal: resposta agora é por voz (gravada, transcrita) em vez
// de texto digitado, e o limite passa a ser diário (premium) / vitalício
// (limitado) em vez de um número fixo pra todo mundo.
class DailyQuestionOpenAI
{
    const LIMITE_DIARIO_PREMIUM = 5;
    const LIMITE_VITALICIO_LIMITADO = 3;
    const MAX_TENTATIVAS_POR_PERGUNTA = 3;

    public static function contarHoje(PDO $pdo, int $user_id): int
    {
        $sql = "SELECT COUNT(*) as total FROM perguntas_ia
                WHERE user_id = :user_id AND status_id = 1
                  AND DATE(data_criacao) = CURDATE()";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([':user_id' => $user_id]);
        return (int) $stmt->fetch(PDO::FETCH_ASSOC)['total'];
    }

    public static function contarTotal(PDO $pdo, int $user_id): int
    {
        $sql = "SELECT COUNT(*) as total FROM perguntas_ia
                WHERE user_id = :user_id AND status_id = 1";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([':user_id' => $user_id]);
        return (int) $stmt->fetch(PDO::FETCH_ASSOC)['total'];
    }

    public static function verificarAcesso(PDO $pdo, int $user_id, int $plano): ?array
    {
        if ($plano === 1) {
            if (self::contarHoje($pdo, $user_id) >= self::LIMITE_DIARIO_PREMIUM) {
                return ["success" => false, "limite_atingido" => true, "message" => "Você já respondeu " . self::LIMITE_DIARIO_PREMIUM . " perguntas hoje. Volte amanhã!"];
            }
            return null;
        }

        if ($plano === 3) {
            if (self::contarTotal($pdo, $user_id) >= self::LIMITE_VITALICIO_LIMITADO) {
                return ["success" => false, "limite_atingido" => true, "message" => "Você já usou suas " . self::LIMITE_VITALICIO_LIMITADO . " perguntas grátis. Vire premium para ter acesso diário."];
            }
            return null;
        }

        return ["success" => false, "premium_necessario" => true, "message" => "Perguntas diárias por IA são um recurso exclusivo do plano Premium."];
    }

    private static function getPendente(PDO $pdo, int $user_id): ?array
    {
        $sql = "SELECT id, question, question_traducao FROM perguntas_ia
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
    // significa que o aluno já estudou aquele conteúdo.
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

    // Gera também a tradução da pergunta pro idioma nativo - a tela
    // funciona como um flashcard (frente = pergunta gerada, verso = tradução).
    public static function obterPergunta(PDO $pdo, OpenAiChat $chat, int $user_id, array $phrases, string $idiomaNome, string $idiomaNativoNome): array
    {
        $pendente = self::getPendente($pdo, $user_id);

        if ($pendente) {
            return [
                "success" => true,
                "id" => (int) $pendente['id'],
                "question" => $pendente['question'],
                "traducao" => $pendente['question_traducao'],
            ];
        }

        if (self::contarFrasesEstudadas($pdo, $user_id) < 3) {
            return ["success" => false, "message" => "Adicione mais frases aos flashcards para gerar perguntas melhores."];
        }

        $phrases = array_filter($phrases, fn($p) => str_word_count($p) >= 3);
        $phrases = array_values($phrases);

        if (count($phrases) < 3) {
            return ["success" => false, "message" => "Adicione mais frases aos flashcards para gerar perguntas melhores."];
        }

        shuffle($phrases);
        $phrases = array_slice($phrases, 0, 300);
        $phrases = array_map(fn($p) => mb_substr($p, 0, 220), $phrases);
        $phrasesText = implode("\n", $phrases);

        $systemPrompt = "Você é um professor de idiomas. Crie UMA pergunta simples em {$idiomaNome}, respondível oralmente em "
            . "uma frase, e também a tradução dela em {$idiomaNativoNome}. Baseie pelo menos 80% do vocabulário da pergunta "
            . "nas frases fornecidas pelo aluno a seguir, pra focar no que ele está estudando. O restante do vocabulário "
            . "pode ser palavras comuns do idioma, inclusive artigos, conectivos e concordância gramatical necessários pra "
            . "pergunta soar natural. Máximo 220 caracteres na pergunta. Não use aspas. "
            . 'Responda em JSON: {"pergunta": "...", "traducao": "..."}';

        $resultado = $chat->completar([
            ['role' => 'system', 'content' => $systemPrompt],
            ['role' => 'user', 'content' => "Frases:\n" . $phrasesText],
        ], true, 400);

        if ($resultado['erro']) {
            return ["success" => false, "message" => "Não foi possível gerar a pergunta: " . $resultado['mensagem']];
        }

        $decodificado = json_decode($resultado['texto'], true);

        if (!is_array($decodificado) || empty($decodificado['pergunta'])) {
            return ["success" => false, "message" => "Resposta inválida da IA."];
        }

        $question = mb_substr(trim((string) $decodificado['pergunta'], "\" \n\r\t"), 0, 220);
        $traducao = mb_substr(trim((string) ($decodificado['traducao'] ?? ''), "\" \n\r\t"), 0, 220);

        $stmt = $pdo->prepare("INSERT INTO perguntas_ia (user_id, status_id, question, question_traducao) VALUES (:user_id, 0, :question, :traducao)");
        $stmt->execute([':user_id' => $user_id, ':question' => $question, ':traducao' => $traducao]);

        return ["success" => true, "id" => (int) $pdo->lastInsertId(), "question" => $question, "traducao" => $traducao];
    }

    // Usado quando a tentativa não chega a gerar nota (áudio vazio ou
    // conteúdo impróprio) - mesmo assim consome uma tentativa e uma chamada
    // de IA de verdade (transcrição), então tem que contar pro limite de
    // MAX_TENTATIVAS_POR_PERGUNTA, senão dá pra ficar gravando áudio vazio
    // pra sempre sem nunca fechar a pergunta.
    private static function registrarTentativaSemNota(PDO $pdo, int $perguntaId, int $tentativaAtual, string $transcricao): bool
    {
        $esgotou = $tentativaAtual >= self::MAX_TENTATIVAS_POR_PERGUNTA;

        $stmt = $pdo->prepare("
            UPDATE perguntas_ia
            SET status_id = :status_id, tentativas = :tentativas, transcricao = :transcricao
            WHERE id = :id
        ");
        $stmt->execute([
            ':status_id' => $esgotou ? 1 : 0,
            ':tentativas' => $tentativaAtual,
            ':transcricao' => $transcricao,
            ':id' => $perguntaId,
        ]);

        return $esgotou;
    }

    public static function responder(
        PDO $pdo,
        OpenAiChat $chat,
        OpenAiTranscribe $transcribe,
        int $user_id,
        int $perguntaId,
        string $caminhoAudio,
        string $mimeType
    ): array {
        $stmt = $pdo->prepare("SELECT question, tentativas FROM perguntas_ia WHERE id = :id AND user_id = :user_id AND status_id = 0");
        $stmt->execute([':id' => $perguntaId, ':user_id' => $user_id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            return ["success" => false, "message" => "Pergunta não encontrada ou já respondida."];
        }

        $question = $row['question'];
        $tentativaAtual = (int) $row['tentativas'] + 1;

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
            $esgotou = self::registrarTentativaSemNota($pdo, $perguntaId, $tentativaAtual, $transcricao);

            return [
                "success" => false,
                "audio_vazio" => true,
                "pode_tentar_novamente" => !$esgotou,
                "message" => $esgotou
                    ? "Não conseguimos identificar sua fala nas últimas tentativas. Vamos pra próxima pergunta."
                    : "Não conseguimos identificar sua fala no áudio. Tente gravar de novo, falando mais perto do microfone."
            ];
        }

        // Mesma checagem já aplicada em frases/categorias - a transcrição é
        // texto que o próprio usuário falou, sem moderação nenhuma antes disso.
        if (verificarConteudoImproprio($transcricao)) {
            $esgotou = self::registrarTentativaSemNota($pdo, $perguntaId, $tentativaAtual, $transcricao);

            return [
                "success" => false,
                "pode_tentar_novamente" => !$esgotou,
                "message" => "O áudio contém conteúdo impróprio."
            ];
        }

        $systemPrompt = "Você é um professor de idiomas avaliando a resposta ORAL de um aluno. Vai receber a pergunta e a "
            . "transcrição da resposta falada (hesitação/frase incompleta aparece como texto desconexo). Avalie se responde "
            . "à pergunta e a qualidade gramatical/fluência. Dê nota de 0 a 10, se está correto, e explique os principais "
            . "erros em português (máx 200 caracteres). "
            . 'Responda em JSON: {"nota": 0-10, "correto": true ou false, "feedback": "..."}';

        $correcaoResult = $chat->completar([
            ['role' => 'system', 'content' => $systemPrompt],
            ['role' => 'user', 'content' => "Pergunta: {$question}\nTranscrição da resposta: {$transcricao}"],
        ], true, 500);

        if ($correcaoResult['erro']) {
            return ["success" => false, "message" => "Não foi possível corrigir: " . $correcaoResult['mensagem']];
        }

        $correcao = json_decode($correcaoResult['texto'], true);

        if (!is_array($correcao) || !isset($correcao['nota'])) {
            return ["success" => false, "message" => "Resposta inválida da IA."];
        }

        $nota = max(0, min(10, (int) $correcao['nota']));
        $correto = (bool) ($correcao['correto'] ?? false);
        $feedback = mb_substr((string) ($correcao['feedback'] ?? ''), 0, 300);

        // Só marca como respondida (e conta pro limite) quando a resposta é
        // boa o suficiente, OU quando já esgotou as tentativas dessa pergunta
        // (não adianta deixar tentar de novo pra sempre - gasta tokens de IA
        // à toa numa pergunta que o aluno não está conseguindo acertar).
        // Enquanto isso não acontece, status_id continua 0 - getPendente()
        // devolve a mesma pergunta de novo, sem gastar uma tentativa do plano.
        $passou = $nota >= 5 && $correto;
        $esgotouTentativas = $tentativaAtual >= self::MAX_TENTATIVAS_POR_PERGUNTA;
        $statusFinal = ($passou || $esgotouTentativas) ? 1 : 0;

        $stmt = $pdo->prepare("
            UPDATE perguntas_ia
            SET status_id = :status_id, tentativas = :tentativas, transcricao = :transcricao, nota = :nota, feedback = :feedback
            WHERE id = :id
        ");
        $stmt->execute([
            ':status_id' => $statusFinal,
            ':tentativas' => $tentativaAtual,
            ':transcricao' => $transcricao,
            ':nota' => $nota,
            ':feedback' => $feedback,
            ':id' => $perguntaId,
        ]);

        return [
            "success" => true,
            "transcricao" => $transcricao,
            "nota" => $nota,
            "correto" => $correto,
            "feedback" => $feedback,
            "pode_tentar_novamente" => !$passou && !$esgotouTentativas,
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
}
