<?php

// Chat de dúvidas anexado ao feedback de uma pergunta já respondida (treino
// de Perguntas por IA) - deliberadamente bem limitado: poucas mensagens,
// escopo travado só na pergunta em questão (não é um chat livre), pra tirar
// dúvida pontual sobre vocabulário/gramática/porque acertou ou errou, sem
// virar um custo de IA sem teto nem uma conversa genérica sobre qualquer
// assunto. O teto de mensagens por pergunta, somado ao teto diário que já
// existe pra quantas perguntas dá pra responder por dia (DailyQuestionOpenAI),
// limita o custo total sem precisar de um teto diário separado só pro chat.
class DuvidaPerguntaIA
{
    const MAX_MENSAGENS_USUARIO = 3;

    public static function contarMensagensUsuario(PDO $pdo, int $perguntaId, int $user_id): int
    {
        $stmt = $pdo->prepare(
            "SELECT COUNT(*) as total FROM perguntas_ia_duvidas
             WHERE pergunta_id = :pergunta_id AND user_id = :user_id AND role = 'user'"
        );
        $stmt->execute([':pergunta_id' => $perguntaId, ':user_id' => $user_id]);
        return (int) $stmt->fetch(PDO::FETCH_ASSOC)['total'];
    }

    public static function listarHistorico(PDO $pdo, int $perguntaId, int $user_id): array
    {
        $stmt = $pdo->prepare(
            "SELECT role, mensagem FROM perguntas_ia_duvidas
             WHERE pergunta_id = :pergunta_id AND user_id = :user_id
             ORDER BY id ASC"
        );
        $stmt->execute([':pergunta_id' => $perguntaId, ':user_id' => $user_id]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // Confere que a pergunta é do usuário E já foi respondida (status_id=1,
    // com nota) - o chat de dúvida só faz sentido depois do feedback
    // existir. Travar por user_id evita um usuário mandar dúvida sobre o
    // pergunta_id de outra pessoa só adivinhando o número.
    private static function buscarPerguntaRespondida(PDO $pdo, int $perguntaId, int $user_id): ?array
    {
        $stmt = $pdo->prepare(
            "SELECT question, question_traducao, transcricao, nota, feedback
             FROM perguntas_ia
             WHERE id = :id AND user_id = :user_id AND status_id = 1 AND nota IS NOT NULL"
        );
        $stmt->execute([':id' => $perguntaId, ':user_id' => $user_id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public static function responder(
        PDO $pdo,
        OpenAiChat $chat,
        int $user_id,
        int $perguntaId,
        string $mensagemUsuario,
        string $idiomaNativoNome
    ): array {
        $mensagemUsuario = trim($mensagemUsuario);

        if ($mensagemUsuario === '') {
            return ["success" => false, "message" => "Digite sua dúvida."];
        }

        if (verificarConteudoImproprio($mensagemUsuario)) {
            return ["success" => false, "message" => "Esta mensagem contém conteúdo impróprio."];
        }

        $pergunta = self::buscarPerguntaRespondida($pdo, $perguntaId, $user_id);

        if (!$pergunta) {
            return ["success" => false, "message" => "Pergunta não encontrada."];
        }

        if (self::contarMensagensUsuario($pdo, $perguntaId, $user_id) >= self::MAX_MENSAGENS_USUARIO) {
            return ["success" => false, "limite_atingido" => true, "message" => "Você já usou as " . self::MAX_MENSAGENS_USUARIO . " dúvidas desse chat."];
        }

        $historico = self::listarHistorico($pdo, $perguntaId, $user_id);

        // O feedback já dado (e a nota) entram no contexto pra IA poder
        // explicar "por que errei essa parte", não só repetir o enunciado.
        $systemPrompt = "Você ajuda um aluno de idiomas a tirar UMA dúvida pontual sobre uma pergunta específica "
            . "de um exercício que ele já respondeu e já recebeu nota/feedback. Contexto da pergunta: "
            . "\"{$pergunta['question']}\" (tradução: \"{$pergunta['question_traducao']}\"). Resposta que o aluno "
            . "deu: \"{$pergunta['transcricao']}\". Nota recebida: {$pergunta['nota']}/10. Feedback já dado: "
            . "\"{$pergunta['feedback']}\". Responda SOMENTE dúvidas relacionadas a essa pergunta específica "
            . "(vocabulário usado nela, gramática, por que a resposta dele foi considerada certa ou errada, "
            . "como ele poderia ter respondido melhor). Se o aluno perguntar qualquer coisa sem relação com "
            . "essa pergunta específica, recuse educadamente e lembre que esse chat é só pra tirar dúvida sobre "
            . "essa pergunta. Responda SEMPRE em {$idiomaNativoNome} (idioma nativo do aluno), curto e direto "
            . "(no máximo 3-4 frases), nunca em outro idioma.";

        $messages = [['role' => 'system', 'content' => $systemPrompt]];
        foreach ($historico as $m) {
            $messages[] = ['role' => $m['role'] === 'assistant' ? 'assistant' : 'user', 'content' => $m['mensagem']];
        }
        $messages[] = ['role' => 'user', 'content' => $mensagemUsuario];

        $resultado = $chat->completar($messages, false, 300);

        if ($resultado['erro']) {
            return ["success" => false, "message" => "Não foi possível responder: " . $resultado['mensagem']];
        }

        $resposta = $resultado['texto'];

        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare("INSERT INTO perguntas_ia_duvidas (pergunta_id, user_id, role, mensagem) VALUES (:pergunta_id, :user_id, 'user', :mensagem)");
            $stmt->execute([':pergunta_id' => $perguntaId, ':user_id' => $user_id, ':mensagem' => $mensagemUsuario]);

            $stmt = $pdo->prepare("INSERT INTO perguntas_ia_duvidas (pergunta_id, user_id, role, mensagem) VALUES (:pergunta_id, :user_id, 'assistant', :mensagem)");
            $stmt->execute([':pergunta_id' => $perguntaId, ':user_id' => $user_id, ':mensagem' => $resposta]);

            $pdo->commit();
        } catch (Exception $e) {
            $pdo->rollBack();
            return ["success" => false, "message" => "Erro ao salvar a conversa."];
        }

        return [
            "success" => true,
            "resposta" => $resposta,
            "mensagens_restantes" => self::MAX_MENSAGENS_USUARIO - self::contarMensagensUsuario($pdo, $perguntaId, $user_id),
        ];
    }
}
