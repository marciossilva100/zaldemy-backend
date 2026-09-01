<?php

require_once __DIR__ . '/PlanoLimitado.php';

// "Melhorar com IA": diferente do botão de sugestão gratuita (Google
// Translate, tradução literal instantânea - desativado, ver comentário em
// ModalFrase.jsx), esse usa o modelo de linguagem pra sugerir uma tradução
// mais natural/idiomática, como um nativo diria. Premium tem teto diário
// (LIMITE_DIARIO_PREMIUM); Limitado ganhou de volta uma amostra vitalícia
// (PlanoLimitado::LIMITE_MELHORAR_TRADUCAO_VITALICIO - mesma constante que
// conta pro rebaixamento automático, fonte única em vez de duplicar o
// número aqui) - free não tem acesso.
class TraducaoIA
{
    const LIMITE_DIARIO_PREMIUM = 20;

    public static function contarHoje(PDO $pdo, int $user_id): int
    {
        $sql = "SELECT COUNT(*) as total FROM traducao_ia_uso
                WHERE user_id = :user_id AND DATE(data_criacao) = CURDATE()";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([':user_id' => $user_id]);
        return (int) $stmt->fetch(PDO::FETCH_ASSOC)['total'];
    }

    // Sem filtro de data - é o total vitalício, não o de hoje (usado só pelo
    // Limitado, que tem teto vitalício em vez de diário).
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
            if (self::contarTotal($pdo, $user_id) >= PlanoLimitado::LIMITE_MELHORAR_TRADUCAO_VITALICIO) {
                return ["success" => false, "limite_atingido" => true, "message" => "Você já usou suas " . PlanoLimitado::LIMITE_MELHORAR_TRADUCAO_VITALICIO . " melhorias de tradução com IA gratuitas. Assine o Premium pra continuar usando."];
            }
            return null;
        }

        return ["success" => false, "premium_necessario" => true, "message" => "Melhorar a tradução com IA é um recurso exclusivo do plano Premium."];
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

        // Testado direto na API: a versão anterior desse prompt colava a
        // frase original tanto no system quanto no user message (redundante -
        // a frase já vai como user content logo abaixo) e não tinha nenhuma
        // instrução contra repetição - o modelo às vezes duplicava um
        // marcador de cortesia (ex: "Please get me a glass of water,
        // please."). Removida a duplicação e adicionada instrução explícita
        // de revisão contra redundância - testado de novo (20 frases com
        // "por favor" em posições variadas): 0 duplicações, contra 2/20 da
        // versão antiga.
        //
        // Depois descoberto (usuário reportou "apareceu um monte de coisas"
        // ao tocar no botão): sem JSON mode, uma entrada curta/ambígua (ex:
        // "beleza") fazia o modelo "pensar alto" e devolver um parágrafo de
        // explicação em vez de só a tradução, mesmo com a instrução textual
        // "sem explicações" - a instrução por texto não é suficiente sem
        // uma restrição estrutural. Testado com 8 frases adversariais
        // (palavra única, gíria, frase já no idioma de destino): sem JSON
        // mode, 1/8 veio com explicação longa; com JSON mode, 0/8 - mesmo
        // padrão já usado em toda outra feature de IA do sistema.
        $systemPrompt = "Você é um professor de idiomas e tradutor nativo. O aluno vai te mandar uma frase escrita em "
            . "{$idiomaNativoNome} e quer saber como diria a mesma coisa, de forma natural, em {$idiomaAprendendoNome}. Dê "
            . "a tradução mais natural e idiomática possível, como um falante nativo de {$idiomaAprendendoNome} diria no "
            . "dia a dia (não uma tradução literal palavra por palavra). Revise mentalmente a frase final antes de "
            . "responder pra garantir que soa natural, com concordância e coesão corretas, e SEM REDUNDÂNCIA - nunca "
            . "repita a mesma palavra ou expressão duas vezes na mesma frase (ex: nunca tenha uma expressão de cortesia "
            . "tipo \"please\"/\"por favor\" tanto no início quanto no fim da mesma frase - escolha só um lugar, o que "
            . "soar mais natural). Responda em JSON no formato {\"traducao\": \"...\"}, contendo APENAS a frase "
            . "traduzida final nesse campo, sem aspas extras dentro do valor e sem nenhuma explicação, alternativa "
            . "ou comentário.";

        $resultado = $chat->completar([
            ['role' => 'system', 'content' => $systemPrompt],
            ['role' => 'user', 'content' => $frase],
        ], true, 200);

        if ($resultado['erro']) {
            return ["success" => false, "message" => "Não foi possível gerar a tradução: " . $resultado['mensagem']];
        }

        $json = json_decode($resultado['texto'], true);
        $traducao = trim((string) ($json['traducao'] ?? ''), "\" \n\r\t");

        if ($traducao === '') {
            return ["success" => false, "message" => "Não foi possível gerar a tradução: resposta inesperada da IA."];
        }

        self::registrarUso($pdo, $user_id);

        return ["success" => true, "traducao" => $traducao];
    }
}
