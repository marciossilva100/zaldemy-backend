<?php

// Categoria criada por IA: o usuário informa só um tópico e a IA gera as
// frases (nativo + tradução) já dentro do par de idiomas atual dele. Reaproveita
// Categorias::cadastrarCategoria (mesma validação de nome/duplicidade) e
// Categorias::addFrases (mesmo bulk-insert usado hoje na importação de
// categoria compartilhada) - só adiciona a geração via IA e o próprio
// controle de acesso por plano, no mesmo padrão de controller/tts.php.
class CategoriaIA
{
    const LIMITE_DIARIO_PREMIUM = 3;
    const LIMITE_VITALICIO_LIMITADO = 1;
    const QUANTIDADE_FRASES = 8;

    public static function contarHoje(PDO $pdo, int $user_id): int
    {
        $sql = "SELECT COUNT(*) as total FROM categoria_ia_uso
                WHERE user_id = :user_id AND DATE(data_criacao) = CURDATE()";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([':user_id' => $user_id]);
        return (int) $stmt->fetch(PDO::FETCH_ASSOC)['total'];
    }

    public static function contarTotal(PDO $pdo, int $user_id): int
    {
        $sql = "SELECT COUNT(*) as total FROM categoria_ia_uso WHERE user_id = :user_id";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([':user_id' => $user_id]);
        return (int) $stmt->fetch(PDO::FETCH_ASSOC)['total'];
    }

    public static function registrarUso(PDO $pdo, int $user_id): void
    {
        $stmt = $pdo->prepare("INSERT INTO categoria_ia_uso (user_id) VALUES (:user_id)");
        $stmt->execute([':user_id' => $user_id]);
    }

    // premium (1): limite diário. limitado (3): amostra vitalícia. free (2):
    // bloqueado - recurso só entra em premium e limitado, confirmado com o usuário.
    public static function verificarAcesso(PDO $pdo, int $user_id, int $plano): ?array
    {
        if ($plano === 1) {
            if (self::contarHoje($pdo, $user_id) >= self::LIMITE_DIARIO_PREMIUM) {
                return ["success" => false, "limite_atingido" => true, "message" => "Você já gerou " . self::LIMITE_DIARIO_PREMIUM . " categorias com IA hoje. Volte amanhã!"];
            }
            return null;
        }

        if ($plano === 3) {
            if (self::contarTotal($pdo, $user_id) >= self::LIMITE_VITALICIO_LIMITADO) {
                return ["success" => false, "limite_atingido" => true, "message" => "Você já usou sua amostra grátis de categoria criada por IA. Vire premium para gerar mais."];
            }
            return null;
        }

        return ["success" => false, "premium_necessario" => true, "message" => "Criar categorias com IA é um recurso exclusivo dos planos Premium e Limitado."];
    }

    public static function getIdiomas(PDO $pdo, int $user_id): ?array
    {
        $sql = "SELECT
                    ir.idioma_nativo AS nativo_id,
                    n.idioma AS nativo_nome,
                    ir.idioma_aprender AS aprendendo_id,
                    a.idioma AS aprendendo_nome
                FROM idioma_referencia ir
                JOIN idiomas n ON n.id = ir.idioma_nativo
                JOIN idiomas a ON a.id = ir.idioma_aprender
                WHERE ir.id_user = :user_id
                LIMIT 1";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([':user_id' => $user_id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    private static function gerarFrases(OpenAiChat $chat, string $topico, string $idiomaNativoNome, string $idiomaAprendendoNome, string $nivelNome): array
    {
        $systemPrompt = "Você é um professor de idiomas. Gere " . self::QUANTIDADE_FRASES . " frases curtas e úteis sobre o "
            . "tema \"{$topico}\", em {$idiomaNativoNome} com a tradução correspondente em {$idiomaAprendendoNome}, pra um "
            . "aluno de nível {$nivelNome}. Ajuste o vocabulário e a complexidade gramatical pro nível dele - iniciante "
            . "pede estruturas simples e vocabulário básico do dia a dia; intermediário pode incluir conectivos e tempos "
            . "verbais variados; avançado pode usar vocabulário mais rico e estruturas mais elaboradas. "
            . "Frases naturais, do dia a dia, variadas entre si (não repita a mesma estrutura), máximo 80 caracteres cada. "
            . "IMPORTANTE: todas as frases têm que ser afirmações (declarativas) - nunca perguntas. Não gere nenhuma "
            . "frase terminando com \"?\" nem em {$idiomaNativoNome} nem em {$idiomaAprendendoNome}. "
            . 'Responda em JSON: {"frases": [{"nativo": "...", "traduzido": "..."}, ...]}';

        $resultado = $chat->completar([
            ['role' => 'system', 'content' => $systemPrompt],
            ['role' => 'user', 'content' => "Tema: {$topico}"],
        ], true, 1200);

        if ($resultado['erro']) {
            return ["erro" => true, "mensagem" => $resultado['mensagem']];
        }

        $decodificado = json_decode($resultado['texto'], true);
        $frases = $decodificado['frases'] ?? null;

        if (!is_array($frases) || count($frases) === 0) {
            return ["erro" => true, "mensagem" => "A IA não retornou frases válidas."];
        }

        // Segurança além do prompt: a IA às vezes ignora a instrução e gera
        // alguma pergunta mesmo assim - descarta qualquer frase (nativo ou
        // traduzido) que termine com "?" em vez de deixar passar.
        $frases = array_values(array_filter($frases, function ($f) {
            $nativo = rtrim((string) ($f['nativo'] ?? ''));
            $traduzido = rtrim((string) ($f['traduzido'] ?? ''));

            return !str_ends_with($nativo, '?') && !str_ends_with($traduzido, '?');
        }));

        if (count($frases) === 0) {
            return ["erro" => true, "mensagem" => "A IA só retornou perguntas - tente novamente."];
        }

        return ["erro" => false, "frases" => $frases];
    }

    public static function criarComIA(PDO $pdo, OpenAiChat $chat, int $user_id, int $plano, string $topico, int $categoriaPublica): array
    {
        if (verificarConteudoImproprio($topico)) {
            return ["success" => false, "message" => "Este texto contém conteúdo impróprio."];
        }

        $bloqueio = self::verificarAcesso($pdo, $user_id, $plano);

        if ($bloqueio !== null) {
            return $bloqueio;
        }

        $idiomas = self::getIdiomas($pdo, $user_id);

        if (!$idiomas) {
            return ["success" => false, "message" => "Selecione seu idioma nativo e de aprendizagem antes de continuar."];
        }

        $nivelNome = Nivel::obterNomeDoUsuario($pdo, $user_id);
        $geracao = self::gerarFrases($chat, $topico, $idiomas['nativo_nome'], $idiomas['aprendendo_nome'], $nivelNome);

        if ($geracao['erro']) {
            return ["success" => false, "message" => "Não foi possível gerar as frases: " . $geracao['mensagem']];
        }

        $resultadoCategoria = Categorias::cadastrarCategoria($pdo, $topico, $user_id, null, $categoriaPublica);

        if (!$resultadoCategoria['success']) {
            return $resultadoCategoria;
        }

        $categoriaId = $resultadoCategoria['id'];

        $frasesParaInserir = array_map(fn($f) => [
            'texto_nativo' => $f['nativo'] ?? '',
            'texto_traduzido' => $f['traduzido'] ?? '',
            'idioma_nativo' => $idiomas['nativo_id'],
            'idioma_aprendendo' => $idiomas['aprendendo_id'],
        ], $geracao['frases']);

        $resultadoFrases = Categorias::addFrases($pdo, $user_id, $frasesParaInserir, $categoriaId);

        self::registrarUso($pdo, $user_id);

        return [
            "success" => true,
            "message" => "Categoria criada com sucesso",
            "id" => $categoriaId,
            "inseridas" => $resultadoFrases['inseridas'],
        ];
    }

    // Variante usada na escolha de categorias de interesse no onboarding
    // (substituiu a antiga categoria única copiada automaticamente no
    // cadastro) - sem checagem de acesso/limite de plano (disponível pra
    // todo mundo nesse momento) e sem contar pro uso de "Criar com IA".
    // tipo=3 marca como criada automaticamente, fora do limite de categorias
    // do plano (mesmo padrão da antiga categoria única).
    public static function criarParaOnboarding(PDO $pdo, OpenAiChat $chat, int $user_id, string $topico): array
    {
        $idiomas = self::getIdiomas($pdo, $user_id);

        if (!$idiomas) {
            return ["success" => false, "message" => "Selecione seu idioma nativo e de aprendizagem antes de continuar."];
        }

        $nivelNome = Nivel::obterNomeDoUsuario($pdo, $user_id);
        $geracao = self::gerarFrases($chat, $topico, $idiomas['nativo_nome'], $idiomas['aprendendo_nome'], $nivelNome);

        if ($geracao['erro']) {
            return ["success" => false, "message" => "Não foi possível gerar as frases: " . $geracao['mensagem']];
        }

        $resultadoCategoria = Categorias::cadastrarCategoria(
            $pdo, $topico, $user_id, null, 0, 3, $idiomas['nativo_id'], $idiomas['aprendendo_id']
        );

        if (!$resultadoCategoria['success']) {
            return $resultadoCategoria;
        }

        $categoriaId = $resultadoCategoria['id'];

        $frasesParaInserir = array_map(fn($f) => [
            'texto_nativo' => $f['nativo'] ?? '',
            'texto_traduzido' => $f['traduzido'] ?? '',
            'idioma_nativo' => $idiomas['nativo_id'],
            'idioma_aprendendo' => $idiomas['aprendendo_id'],
        ], $geracao['frases']);

        $resultadoFrases = Categorias::addFrases($pdo, $user_id, $frasesParaInserir, $categoriaId);

        return [
            "success" => true,
            "id" => $categoriaId,
            "categoria" => $topico,
            "inseridas" => $resultadoFrases['inseridas'],
        ];
    }
}
