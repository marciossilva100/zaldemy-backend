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
        $sql = "SELECT id, question FROM perguntas_ia
                WHERE user_id = :user_id AND status_id = 0
                ORDER BY id DESC LIMIT 1";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([':user_id' => $user_id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public static function obterPergunta(PDO $pdo, OpenAiChat $chat, int $user_id, array $phrases, string $idiomaNome): array
    {
        $pendente = self::getPendente($pdo, $user_id);

        if ($pendente) {
            return ["success" => true, "id" => (int) $pendente['id'], "question" => $pendente['question']];
        }

        $phrases = array_filter($phrases, fn($p) => str_word_count($p) >= 3);
        $phrases = array_values($phrases);

        if (count($phrases) < 3) {
            return ["success" => false, "message" => "Adicione mais frases aos flashcards para gerar perguntas melhores."];
        }

        shuffle($phrases);
        $phrases = array_slice($phrases, 0, 300);
        $phrases = array_map(fn($p) => mb_substr($p, 0, 200), $phrases);
        $phrasesText = implode("\n", $phrases);

        $systemPrompt = "Você é um professor de idiomas. Crie UMA pergunta simples em {$idiomaNome} baseada apenas nas frases "
            . "fornecidas pelo aluno, respondível oralmente em uma frase. Máximo 150 caracteres. Não use aspas. "
            . "Responda apenas com a pergunta.";

        $resultado = $chat->completar([
            ['role' => 'system', 'content' => $systemPrompt],
            ['role' => 'user', 'content' => "Frases:\n" . $phrasesText],
        ]);

        if ($resultado['erro']) {
            return ["success" => false, "message" => "Não foi possível gerar a pergunta: " . $resultado['mensagem']];
        }

        $question = mb_substr(trim($resultado['texto'], "\" \n\r\t"), 0, 150);

        $stmt = $pdo->prepare("INSERT INTO perguntas_ia (user_id, status_id, question) VALUES (:user_id, 0, :question)");
        $stmt->execute([':user_id' => $user_id, ':question' => $question]);

        return ["success" => true, "id" => (int) $pdo->lastInsertId(), "question" => $question];
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
        $stmt = $pdo->prepare("SELECT question FROM perguntas_ia WHERE id = :id AND user_id = :user_id AND status_id = 0");
        $stmt->execute([':id' => $perguntaId, ':user_id' => $user_id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            return ["success" => false, "message" => "Pergunta não encontrada ou já respondida."];
        }

        $question = $row['question'];

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
            return ["success" => false, "message" => "Não conseguimos identificar sua fala no áudio. Tente gravar de novo, falando mais perto do microfone."];
        }

        // Mesma checagem já aplicada em frases/categorias - a transcrição é
        // texto que o próprio usuário falou, sem moderação nenhuma antes disso.
        if (verificarConteudoImproprio($transcricao)) {
            return ["success" => false, "message" => "O áudio contém conteúdo impróprio."];
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

        $stmt = $pdo->prepare("
            UPDATE perguntas_ia
            SET status_id = 1, transcricao = :transcricao, nota = :nota, feedback = :feedback
            WHERE id = :id
        ");
        $stmt->execute([
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
