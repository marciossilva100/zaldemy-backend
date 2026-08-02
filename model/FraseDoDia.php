<?php

class FraseDoDia
{
    // Premium: 1 frase nova por dia. Limitado: 1 vitalícia (amostra grátis,
    // mesmo padrão do audio_ia_uso). Free: bloqueado.
    const LIMITE_DIARIO_PREMIUM = 1;
    const LIMITE_VITALICIO_LIMITADO = 1;

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
        $sql = "SELECT id, frase FROM frase_dia_ia
                WHERE user_id = :user_id AND status_id = 0
                ORDER BY id DESC LIMIT 1";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([':user_id' => $user_id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public static function obterFraseDoDia(PDO $pdo, OpenAiChat $chat, int $user_id, string $idiomaNome): array
    {
        $pendente = self::getPendente($pdo, $user_id);

        if ($pendente) {
            return ["success" => true, "id" => (int) $pendente['id'], "frase" => $pendente['frase']];
        }

        $systemPrompt = "Você é um professor de idiomas. Gere UMA frase de exemplo em {$idiomaNome}, natural e do dia a dia, "
            . "adequada pra um aluno ler em voz alta como exercício de pronúncia. Máximo 150 caracteres. "
            . "Gramaticalmente correta. Não repita estruturas óbvias como 'My name is'. "
            . "Responda APENAS com a frase, sem aspas.";

        $resultado = $chat->completar([
            ['role' => 'system', 'content' => $systemPrompt],
            ['role' => 'user', 'content' => 'Gere a frase.'],
        ]);

        if ($resultado['erro']) {
            return ["success" => false, "message" => "Não foi possível gerar a frase: " . $resultado['mensagem']];
        }

        $frase = mb_substr(trim($resultado['texto'], "\" \n\r\t"), 0, 150);

        $stmt = $pdo->prepare("INSERT INTO frase_dia_ia (user_id, frase, status_id) VALUES (:user_id, :frase, 0)");
        $stmt->execute([':user_id' => $user_id, ':frase' => $frase]);

        return ["success" => true, "id" => (int) $pdo->lastInsertId(), "frase" => $frase];
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
        $stmt = $pdo->prepare("SELECT frase FROM frase_dia_ia WHERE id = :id AND user_id = :user_id AND status_id = 0");
        $stmt->execute([':id' => $fraseId, ':user_id' => $user_id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            return ["success" => false, "message" => "Frase não encontrada ou já respondida."];
        }

        $frase = $row['frase'];

        // O nome do arquivo enviado pra API precisa ter a extensão certa pro
        // formato ser detectado corretamente - um mp3 com nome "audio.webm"
        // falha na transcrição.
        $nomeArquivo = "audio." . self::extensaoParaMime($mimeType);
        $transcricaoResult = $transcribe->transcrever($caminhoAudio, $nomeArquivo, $mimeType);

        if ($transcricaoResult['erro']) {
            return ["success" => false, "message" => "Não foi possível transcrever o áudio: " . $transcricaoResult['mensagem']];
        }

        $transcricao = $transcricaoResult['texto'];

        // Mesma checagem já aplicada em frases/categorias - a transcrição é
        // texto que o próprio usuário falou, sem moderação nenhuma antes disso.
        if (verificarConteudoImproprio($transcricao)) {
            return ["success" => false, "message" => "O áudio contém conteúdo impróprio."];
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
            SET status_id = 1, transcricao = :transcricao, nota = :nota,
                feedback_gramatica = :fg, feedback_pronuncia = :fp, feedback_fluencia = :ff
            WHERE id = :id
        ");
        $stmt->execute([
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
}
