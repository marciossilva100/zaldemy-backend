<?php

// "Melhorar com IA": diferente do botão gratuito de sugestão (Google Translate,
// tradução literal instantânea), esse usa o modelo de linguagem pra sugerir uma
// tradução mais natural/idiomática, como um nativo diria - por isso fica
// reservado a premium/limitado (mesmo padrão de amostra vitalícia já usado em
// FraseDoDia/DailyQuestionOpenAI/CategoriaIA).
class TraducaoIA
{
    const LIMITE_DIARIO_PREMIUM = 20;
    const LIMITE_VITALICIO_LIMITADO = 3;

    public static function contarHoje(PDO $pdo, int $user_id): int
    {
        $sql = "SELECT COUNT(*) as total FROM traducao_ia_uso
                WHERE user_id = :user_id AND DATE(data_criacao) = CURDATE()";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([':user_id' => $user_id]);
        return (int) $stmt->fetch(PDO::FETCH_ASSOC)['total'];
    }

    public static function contarTotal(PDO $pdo, int $user_id): int
    {
        $sql = "SELECT COUNT(*) as total FROM traducao_ia_uso WHERE user_id = :user_id";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([':user_id' => $user_id]);
        return (int) $stmt->fetch(PDO::FETCH_ASSOC)['total'];
    }

    public static function registrarUso(PDO $pdo, int $user_id): void
    {
        $stmt = $pdo->prepare("INSERT INTO traducao_ia_uso (user_id) VALUES (:user_id)");
        $stmt->execute([':user_id' => $user_id]);
    }

    public static function verificarAcesso(PDO $pdo, int $user_id, int $plano): ?array
    {
        if ($plano === 1) {
            if (self::contarHoje($pdo, $user_id) >= self::LIMITE_DIARIO_PREMIUM) {
                return ["success" => false, "limite_atingido" => true, "message" => "Você já usou " . self::LIMITE_DIARIO_PREMIUM . " melhorias de tradução com IA hoje. Volte amanhã!"];
            }
            return null;
        }

        if ($plano === 3) {
            if (self::contarTotal($pdo, $user_id) >= self::LIMITE_VITALICIO_LIMITADO) {
                return ["success" => false, "limite_atingido" => true, "message" => "Você já usou suas " . self::LIMITE_VITALICIO_LIMITADO . " melhorias grátis de tradução com IA. Vire premium para ter acesso diário."];
            }
            return null;
        }

        return ["success" => false, "premium_necessario" => true, "message" => "Melhorar a tradução com IA é um recurso exclusivo dos planos Premium e Limitado."];
    }

    private static function nomeIdioma(PDO $pdo, string $sigla): string
    {
        $stmt = $pdo->prepare("SELECT idioma FROM idiomas WHERE sigla = :sigla LIMIT 1");
        $stmt->execute([':sigla' => $sigla]);
        return $stmt->fetch(PDO::FETCH_ASSOC)['idioma'] ?? $sigla;
    }

    public static function melhorarTraducao(
        PDO $pdo,
        OpenAiChat $chat,
        int $user_id,
        int $plano,
        string $frase,
        string $siglaNativo,
        string $siglaAprendendo
    ): array {
        if (verificarConteudoImproprio($frase)) {
            return ["success" => false, "message" => "Este texto contém conteúdo impróprio."];
        }

        $bloqueio = self::verificarAcesso($pdo, $user_id, $plano);

        if ($bloqueio !== null) {
            return $bloqueio;
        }

        $idiomaNativoNome = self::nomeIdioma($pdo, $siglaNativo);
        $idiomaAprendendoNome = self::nomeIdioma($pdo, $siglaAprendendo);

        $systemPrompt = "Você é um professor de idiomas e tradutor nativo. O aluno quer dizer, em {$idiomaAprendendoNome}, a "
            . "seguinte frase escrita em {$idiomaNativoNome}: \"{$frase}\". Dê a tradução mais natural e idiomática possível, "
            . "como um falante nativo de {$idiomaAprendendoNome} diria no dia a dia (não uma tradução literal palavra por "
            . "palavra). Responda APENAS com a frase traduzida, sem aspas e sem explicações.";

        $resultado = $chat->completar([
            ['role' => 'system', 'content' => $systemPrompt],
            ['role' => 'user', 'content' => $frase],
        ], false, 200);

        if ($resultado['erro']) {
            return ["success" => false, "message" => "Não foi possível gerar a tradução: " . $resultado['mensagem']];
        }

        $traducao = trim($resultado['texto'], "\" \n\r\t");

        self::registrarUso($pdo, $user_id);

        return ["success" => true, "traducao" => $traducao];
    }
}
