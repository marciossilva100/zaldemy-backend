<?php

// Frase diária automática por categoria (plano Premium, opt-in por
// categoria - ver categorias.gerar_frase_diaria, ligado/desligado na edição
// de cada categoria) - resolve o relato do usuário: a IA só gera frases no
// momento em que a categoria é criada
// (CategoriaIA::criarComIA/criarParaOnboarding), nunca mais depois disso.
// Roda via cron (ver cron/gerar_frase_diaria_categorias.php), 1x por dia,
// adicionando 1 frase nova a cada categoria ativa de quem ativou a opção.
// Reaproveita CategoriaIA::gerarFrases (QUANTIDADE_FRASES=1 aqui) e
// Categorias::addFrases pro insert - mesmo padrão/mesmas garantias de
// dedup e nível de idioma.
class CategoriaFraseDiariaIA
{
    const QUANTIDADE_FRASES = 1;

    // Quantas frases já existentes mandar como "já tem isso, não repita" -
    // teto pra não estourar o prompt em categorias com centenas de frases
    // (mesmo raciocínio de MAX_FRASES_PROMPT em outros treinos de IA). As
    // mais RECENTES, não aleatórias - são as que têm mais chance de guiar o
    // estilo/nível que a IA já vinha usando pra essa categoria.
    const MAX_FRASES_EXISTENTES_NO_PROMPT = 20;

    // Só premium (plano=1) - diferente dos treinos de IA (Perguntas/Frase do
    // Dia/Tradução Reversa), que também liberam pro limitado com cota menor,
    // esse recurso multiplica o custo por CATEGORIA, não por usuário/dia -
    // um usuário limitado pode ter várias categorias, então não dá pra
    // replicar o mesmo padrão de "cota menor" sem ainda crescer sem limite
    // junto com o número de categorias. Decisão confirmada com o usuário.
    // Usuários com pelo menos 1 categoria ligada (categorias.gerar_frase_diaria
    // já é forçado a 0 pra não-premium em Categorias::editarCategoria, mas
    // confere de novo aqui pro caso de upgrade->downgrade de plano deixar
    // alguma categoria "presa" ligada).
    public static function usuariosElegiveis(PDO $pdo): array
    {
        $sql = "SELECT DISTINCT u.id
                FROM usuarios u
                INNER JOIN categorias c ON c.id_user = u.id
                WHERE u.plano = 1
                AND c.gerar_frase_diaria = 1
                AND c.status_id > 0";
        $stmt = $pdo->query($sql);

        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    // Só considera "estudando de verdade" uma categoria com pelo menos 1
    // frase TREINADA (id_treino >= 2, mesmo critério já usado em
    // TraducaoReversaOpenAI::contarFrasesEstudadas) e atualizada dentro
    // dessa janela - não id_treino=1 (frase só cadastrada/semeada na
    // criação da categoria, nunca praticada de verdade: addFrases() grava
    // essa linha com id_treino=1 na hora da criação, então usar só
    // "atualizado recentemente" sem o id_treino>=2 deixaria passar
    // categoria recém-criada que o usuário nunca abriu pra treinar).
    const JANELA_INATIVIDADE_DIAS = 7;

    // Categorias do usuário com o recurso LIGADO (categorias.gerar_frase_diaria,
    // ver Categorias::editarCategoria) que ainda não receberam frase hoje
    // (ver categoria_frase_diaria_ia, mesmo padrão de dedup de
    // notificacoes_enviadas - protege contra o cron rodar 2x no mesmo dia)
    // E que o usuário treinou de verdade nos últimos
    // JANELA_INATIVIDADE_DIAS dias - pedido explícito do usuário: mesmo com
    // o recurso ligado nela, uma categoria que ele não está estudando não
    // deve ficar recebendo frase nova todo dia à toa.
    public static function categoriasElegiveis(PDO $pdo, int $user_id): array
    {
        $sql = "SELECT c.id, c.categoria, c.idioma_nativo, c.idioma_aprendendo
                FROM categorias c
                WHERE c.id_user = :user_id
                AND c.status_id > 0
                AND c.gerar_frase_diaria = 1
                AND NOT EXISTS (
                    SELECT 1 FROM categoria_frase_diaria_ia g
                    WHERE g.categoria_id = c.id AND g.data_geracao = CURDATE()
                )
                AND EXISTS (
                    SELECT 1 FROM frases f
                    INNER JOIN treino_data_atualizacao t ON t.id_frase = f.id
                    WHERE f.categoria_id = c.id
                    AND f.status_id > 0
                    AND t.id_treino >= 2
                    AND t.data_atualizacao >= DATE_SUB(NOW(), INTERVAL " . self::JANELA_INATIVIDADE_DIAS . " DAY)
                )";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([':user_id' => $user_id]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private static function nomeIdioma(PDO $pdo, int $idiomaId): string
    {
        $stmt = $pdo->prepare("SELECT idioma FROM idiomas WHERE id = :id");
        $stmt->execute([':id' => $idiomaId]);

        return $stmt->fetch(PDO::FETCH_ASSOC)['idioma'] ?? '';
    }

    private static function frasesExistentes(PDO $pdo, int $categoriaId): array
    {
        $sql = "SELECT texto_nativo FROM frases
                WHERE categoria_id = :categoria_id AND status_id > 0
                ORDER BY id DESC LIMIT " . self::MAX_FRASES_EXISTENTES_NO_PROMPT;
        $stmt = $pdo->prepare($sql);
        $stmt->execute([':categoria_id' => $categoriaId]);

        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    // Mesmo prompt base de CategoriaIA::gerarFrases, com 2 diferenças: pede
    // só 1 frase (QUANTIDADE_FRASES) e lista as frases já existentes na
    // categoria pra IA não repetir o mesmo conteúdo todo dia - sem isso,
    // testado direto na API, a IA convergia nas mesmas 2-3 frases óbvias do
    // tema em gerações seguidas.
    private static function gerarFrase(OpenAiChat $chat, string $topico, array $frasesExistentes, string $idiomaNativoNome, string $idiomaAprendendoNome, string $nivelNome): array
    {
        $listaExistentes = empty($frasesExistentes)
            ? ''
            : "O aluno já tem estas frases nessa categoria - a frase nova tem que ser sobre o MESMO tema, mas "
                . "claramente diferente de todas elas (não repita o mesmo assunto específico nem a mesma estrutura "
                . "de frase): " . implode(' / ', array_map(fn($f) => "\"{$f}\"", $frasesExistentes)) . ". ";

        $systemPrompt = "Você é um professor de idiomas. Gere " . self::QUANTIDADE_FRASES . " frase curta e útil sobre o "
            . "tema \"{$topico}\", em {$idiomaNativoNome} com a tradução correspondente em {$idiomaAprendendoNome}, pra um "
            . "aluno de nível {$nivelNome}. Ajuste o vocabulário e a complexidade gramatical pro nível dele - iniciante "
            . "pede estruturas simples e vocabulário básico do dia a dia; intermediário pode incluir conectivos e tempos "
            . "verbais variados; avançado pode usar vocabulário mais rico e estruturas mais elaboradas. "
            . "Frase natural, do dia a dia, máximo 80 caracteres. {$listaExistentes}"
            . "IMPORTANTE sobre o tipo de frase: se o tema pedir claramente perguntas de verdade (ex: \"perguntas de "
            . "entrevista de emprego\", \"perguntas para puxar assunto\"), a frase DEVE SER uma pergunta real, "
            . "terminando com \"?\". Caso contrário, gere uma frase afirmativa (declarativa). "
            . "As frases em {$idiomaNativoNome} precisam seguir rigorosamente as regras gramaticais corretas desse "
            . "idioma - concordância de gênero e número, conjugação verbal e regência corretas. Revise mentalmente a "
            . "frase gerada antes de responder, como um falante nativo culto revisaria. "
            . 'Responda em JSON: {"frases": [{"nativo": "...", "traduzido": "..."}]}';

        $resultado = $chat->completar([
            ['role' => 'system', 'content' => $systemPrompt],
            ['role' => 'user', 'content' => "Tema: {$topico}"],
        ], true, 400);

        if ($resultado['erro']) {
            return ["erro" => true, "mensagem" => $resultado['mensagem']];
        }

        $decodificado = json_decode($resultado['texto'], true);
        $frases = $decodificado['frases'] ?? null;

        if (!is_array($frases) || count($frases) === 0) {
            return ["erro" => true, "mensagem" => "A IA não retornou frases válidas."];
        }

        return ["erro" => false, "frases" => $frases];
    }

    // Gera e insere 1 frase nova pra essa categoria, e registra em
    // categoria_frase_diaria_ia pra não gerar de novo hoje. Retorna null em
    // erro (cron segue pras próximas categorias/usuários, 1 falha pontual
    // não deve travar o lote inteiro - mesmo padrão de
    // enviar_notificacoes_push.php).
    public static function gerarParaCategoria(PDO $pdo, OpenAiChat $chat, int $user_id, array $categoria): ?int
    {
        $idiomaNativoNome = self::nomeIdioma($pdo, (int) $categoria['idioma_nativo']);
        $idiomaAprendendoNome = self::nomeIdioma($pdo, (int) $categoria['idioma_aprendendo']);

        if ($idiomaNativoNome === '' || $idiomaAprendendoNome === '') {
            return null;
        }

        $nivelNome = Nivel::obterNomeDoUsuario($pdo, $user_id);
        $frasesExistentes = self::frasesExistentes($pdo, (int) $categoria['id']);

        $geracao = self::gerarFrase($chat, $categoria['categoria'], $frasesExistentes, $idiomaNativoNome, $idiomaAprendendoNome, $nivelNome);

        if ($geracao['erro']) {
            error_log("[categoria-frase-diaria] categoria {$categoria['id']}: " . $geracao['mensagem']);
            return null;
        }

        $frasesParaInserir = array_map(fn($f) => [
            'texto_nativo' => $f['nativo'] ?? '',
            'texto_traduzido' => $f['traduzido'] ?? '',
            'idioma_nativo' => $categoria['idioma_nativo'],
            'idioma_aprendendo' => $categoria['idioma_aprendendo'],
        ], $geracao['frases']);

        $resultado = Categorias::addFrases($pdo, $user_id, $frasesParaInserir, (int) $categoria['id']);

        // Registra MESMO quando a IA devolveu uma frase que acabou ignorada
        // por dedup (ex: coincidência com uma já existente) - o objetivo é
        // não gastar outra chamada de API na mesma categoria hoje, não
        // garantir que o insert teve efeito.
        $stmt = $pdo->prepare("INSERT INTO categoria_frase_diaria_ia (categoria_id, data_geracao) VALUES (:categoria_id, CURDATE())");
        $stmt->execute([':categoria_id' => $categoria['id']]);

        return $resultado['inseridas'];
    }
}
