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

    // Tamanho máximo de trecho (palavras ou caracteres, conforme o idioma)
    // considerado ao montar/buscar os n-gramas do destaque de vocabulário -
    // suficiente pra capturar frases inteiras curtas sem custo quadrático
    // alto em frases muito longas.
    const MAX_TAMANHO_TRECHO = 10;

    // Trecho mínimo de 2 palavras, mas com regra mais rígida pra bigramas
    // (ver trechoValidoPorPalavra): um bigrama só vale se AMBAS as palavras
    // forem "de conteúdo" (ver TAMANHO_MIN_PALAVRA_CONTEUDO), senão pares
    // puramente gramaticais ("and I", "on the", "because the") batem com
    // praticamente qualquer frase por coincidência - mas um bigrama de duas
    // palavras de conteúdo de verdade ("stay calm", "make sense") é um
    // reaproveitamento genuíno e não deveria ficar de fora só por ter 2
    // palavras. Trechos de 3+ palavras já bastam ter uma palavra de conteúdo.
    const MIN_TAMANHO_TRECHO_PALAVRAS = 2;
    const TAMANHO_MIN_PALAVRA_CONTEUDO = 4;

    // Chinês, japonês e coreano usam caracteres muito mais densos em
    // significado que os idiomas alfabéticos - 220 caracteres nesses idiomas
    // equivaleria a uma pergunta MUITO mais longa/complexa (e o cartão do
    // flashcard estouraria). Usa um máximo bem menor pra manter a pergunta
    // com complexidade equivalente. Mesma lógica em FraseDoDia.
    private static function limiteCaracteresPara(string $idiomaNome): int
    {
        $cjk = ['chin', 'japon', 'corean'];

        foreach ($cjk as $termo) {
            if (mb_stripos($idiomaNome, $termo) !== false) {
                return 90;
            }
        }

        return 220;
    }

    // Tradução tende a ficar um pouco mais longa que o original (idiomas
    // alfabéticos) ou seguir a mesma densidade (CJK) - dá uma margem
    // proporcional ao máximo já calculado pro idioma.
    private static function limiteTraducaoPara(string $idiomaNome): int
    {
        return (int) round(self::limiteCaracteresPara($idiomaNome) * 1.3);
    }

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

    // Nível de proficiência informado pelo usuário no cadastro (Nivel::registrar)
    // - usado no prompt pra ajustar a complexidade da pergunta gerada.
    public static function getNivelNome(PDO $pdo, int $user_id): string
    {
        $stmt = $pdo->prepare("SELECT nivel FROM usuarios WHERE id = :id");
        $stmt->execute([':id' => $user_id]);
        $nivel = $stmt->fetch(PDO::FETCH_ASSOC)['nivel'] ?? null;

        return Nivel::nomeParaPrompt($nivel !== null ? (int) $nivel : null);
    }

    // Gera também a tradução da pergunta pro idioma nativo - a tela
    // funciona como um flashcard (frente = pergunta gerada, verso = tradução).
    public static function obterPergunta(PDO $pdo, OpenAiChat $chat, int $user_id, array $phrases, string $idiomaNome, string $idiomaNativoNome, ?string $nivelNome = null): array
    {
        $nivelNome = $nivelNome ?? Nivel::nomeParaPrompt(null);
        $pendente = self::getPendente($pdo, $user_id);

        if ($pendente && !empty($pendente['question_traducao'])) {
            return [
                "success" => true,
                "id" => (int) $pendente['id'],
                "question" => $pendente['question'],
                "traducao" => $pendente['question_traducao'],
                "question_destacada" => self::destacarPalavrasConhecidas($pendente['question'], $phrases, $idiomaNome),
            ];
        }

        // Pendência órfã (gerada antes da coluna question_traducao existir) - descarta
        // e gera uma nova em vez de mostrar um flashcard sem verso.
        if ($pendente) {
            $pdo->prepare("DELETE FROM perguntas_ia WHERE id = :id")->execute([':id' => $pendente['id']]);
        }

        if (self::contarFrasesEstudadas($pdo, $user_id) < 3) {
            return ["success" => false, "message" => "Adicione mais frases aos flashcards para gerar perguntas melhores."];
        }

        $phrases = array_filter($phrases, fn($p) => str_word_count($p) >= 3);
        $phrases = array_values($phrases);

        if (count($phrases) < 3) {
            return ["success" => false, "message" => "Adicione mais frases aos flashcards para gerar perguntas melhores."];
        }

        // $phrases já vem limitado (getUserPhrases) e ordenado por prioridade
        // de treino - embaralha só a ordem de apresentação, sem mudar quais
        // frases entram.
        shuffle($phrases);

        // Mesma lista (já filtrada e embaralhada) usada no prompt e no
        // destaque - o destaque só deve marcar o que a IA realmente viu.
        $phrasesOriginais = $phrases;

        $maxPergunta = self::limiteCaracteresPara($idiomaNome);
        $limiteTraducao = self::limiteTraducaoPara($idiomaNativoNome);
        $phrases = array_map(fn($p) => mb_substr($p, 0, $maxPergunta), $phrases);
        $phrasesText = implode("\n", $phrases);

        $systemPrompt = "Você é um professor de idiomas. Crie UMA pergunta simples em {$idiomaNome}, pra um aluno de "
            . "nível {$nivelNome}, respondível oralmente em uma frase, e também a tradução dela em {$idiomaNativoNome}. "
            . "Ajuste o vocabulário e a complexidade gramatical da pergunta pro nível do aluno - iniciante pede "
            . "estruturas simples e vocabulário básico; intermediário pode incluir conectivos e tempos verbais "
            . "variados; avançado pode usar vocabulário mais rico e estruturas mais elaboradas. Use o MÁXIMO possível "
            . "(pelo menos 80%) do vocabulário da pergunta a partir das frases fornecidas pelo aluno a seguir, pra "
            . "focar no que ele está estudando de verdade - só use palavras genéricas do idioma (artigos, conectivos, "
            . "concordância gramatical) quando forem realmente necessárias pra pergunta soar natural, não como "
            . "preenchimento. Máximo {$maxPergunta} caracteres na "
            . "pergunta. Não use aspas. "
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

        $question = self::truncarPreservandoPalavras(trim((string) $decodificado['pergunta'], "\" \n\r\t"), $maxPergunta);
        $traducao = self::truncarPreservandoPalavras(trim((string) ($decodificado['traducao'] ?? ''), "\" \n\r\t"), $limiteTraducao);

        $stmt = $pdo->prepare("INSERT INTO perguntas_ia (user_id, status_id, question, question_traducao) VALUES (:user_id, 0, :question, :traducao)");
        $stmt->execute([':user_id' => $user_id, ':question' => $question, ':traducao' => $traducao]);

        return [
            "success" => true,
            "id" => (int) $pdo->lastInsertId(),
            "question" => $question,
            "traducao" => $traducao,
            "question_destacada" => self::destacarPalavrasConhecidas($question, $phrasesOriginais, $idiomaNome),
        ];
    }

    // Marca no texto gerado quais TRECHOS (2+ palavras/caracteres seguidos)
    // vêm de uma frase que o aluno já estuda - o frontend usa isso pra
    // destacar visualmente. Não marca palavras soltas isoladas (artigo,
    // preposição etc. bateriam com qualquer frase por coincidência e não diz
    // nada sobre o que o aluno realmente está estudando) - só quando o texto
    // gerado reaproveita um pedaço de verdade de alguma frase dele. Idiomas
    // CJK (sem espaço entre palavras) usam granularidade de caractere; os
    // demais, de palavra.
    private static function destacarPalavrasConhecidas(string $texto, array $phrases, string $idiomaNome): array
    {
        $cjk = false;
        foreach (['chin', 'japon', 'corean'] as $termo) {
            if (mb_stripos($idiomaNome, $termo) !== false) {
                $cjk = true;
                break;
            }
        }

        return $cjk
            ? self::destacarTrechosPorCaractere($texto, $phrases)
            : self::destacarTrechosPorPalavra($texto, $phrases);
    }

    // Constrói o conjunto de todos os n-gramas (sequências contíguas de 2+
    // palavras, até MAX_TAMANHO_TRECHO) presentes nas frases do aluno, pra
    // busca O(1) por trecho candidato.
    private static function construirNGramasPalavras(array $phrases): array
    {
        $ngramas = [];

        foreach ($phrases as $frase) {
            preg_match_all('/[\p{L}\p{N}\']+/u', mb_strtolower($frase), $m);
            $palavras = $m[0];
            $total = count($palavras);
            $maxTam = min(self::MAX_TAMANHO_TRECHO, $total);

            for ($tam = self::MIN_TAMANHO_TRECHO_PALAVRAS; $tam <= $maxTam; $tam++) {
                for ($ini = 0; $ini <= $total - $tam; $ini++) {
                    $trecho = array_slice($palavras, $ini, $tam);

                    if (self::trechoValidoPorPalavra($trecho)) {
                        $ngramas[implode(' ', $trecho)] = true;
                    }
                }
            }
        }

        return $ngramas;
    }

    // Evita destacar trechos formados só por palavras funcionais curtas
    // (artigos, preposições, pronomes, conjunções - "and I", "on the",
    // "because the") que batem por coincidência gramatical com praticamente
    // qualquer frase, sem dizer nada de real sobre o vocabulário do aluno.
    // Bigramas são mais rigorosos (exigem as DUAS palavras de conteúdo,
    // senão "and I"/"on the" passariam) - trechos de 3+ palavras já bastam
    // ter uma, já que o resto tem mais chance de ser conectivo/artigo
    // natural da frase em vez de coincidência pura.
    private static function trechoValidoPorPalavra(array $palavras): bool
    {
        if (count($palavras) === 2) {
            foreach ($palavras as $palavra) {
                if (mb_strlen($palavra) < self::TAMANHO_MIN_PALAVRA_CONTEUDO) {
                    return false;
                }
            }
            return true;
        }

        foreach ($palavras as $palavra) {
            if (mb_strlen($palavra) >= self::TAMANHO_MIN_PALAVRA_CONTEUDO) {
                return true;
            }
        }
        return false;
    }

    private static function destacarTrechosPorPalavra(string $texto, array $phrases): array
    {
        $ngramas = self::construirNGramasPalavras($phrases);

        // preserva separadores (espaços/pontuação) como tokens próprios, pra
        // devolver o texto completo pro frontend renderizar
        $tokens = preg_split('/([\p{L}\p{N}\']+)/u', $texto, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY);

        $palavrasTexto = [];
        foreach ($tokens as $i => $token) {
            if (preg_match('/^[\p{L}\p{N}\']+$/u', $token) === 1) {
                $palavrasTexto[] = ['indiceToken' => $i, 'palavra' => mb_strtolower($token)];
            }
        }

        $destacado = array_fill(0, count($tokens), false);
        $totalPalavras = count($palavrasTexto);
        $i = 0;

        // busca gulosa: em cada posição, tenta o maior trecho possível antes
        // de desistir e avançar uma palavra - garante que "eu gosto de viajar
        // muito" destaque o trecho inteiro, não só pedaços de 2 em 2.
        while ($i < $totalPalavras) {
            $encontrou = false;
            $maxTam = min(self::MAX_TAMANHO_TRECHO, $totalPalavras - $i);

            for ($tam = $maxTam; $tam >= 2; $tam--) {
                $palavras = array_map(fn($p) => $p['palavra'], array_slice($palavrasTexto, $i, $tam));

                if (isset($ngramas[implode(' ', $palavras)])) {
                    $primeiroIndice = $palavrasTexto[$i]['indiceToken'];
                    $ultimoIndice = $palavrasTexto[$i + $tam - 1]['indiceToken'];

                    for ($t = $primeiroIndice; $t <= $ultimoIndice; $t++) {
                        $destacado[$t] = true;
                    }

                    $i += $tam;
                    $encontrou = true;
                    break;
                }
            }

            if (!$encontrou) {
                $i++;
            }
        }

        $resultado = [];
        foreach ($tokens as $idx => $token) {
            $resultado[] = ['texto' => $token, 'destaque' => $destacado[$idx]];
        }
        return $resultado;
    }

    private static function destacarTrechosPorCaractere(string $texto, array $phrases): array
    {
        $ngramas = [];

        foreach ($phrases as $frase) {
            $caracteres = preg_split('//u', $frase, -1, PREG_SPLIT_NO_EMPTY);
            $caracteres = array_values(array_filter($caracteres, fn($c) => preg_match('/\p{L}/u', $c) === 1));
            $total = count($caracteres);
            $maxTam = min(self::MAX_TAMANHO_TRECHO, $total);

            for ($tam = 2; $tam <= $maxTam; $tam++) {
                for ($ini = 0; $ini <= $total - $tam; $ini++) {
                    $ngramas[implode('', array_slice($caracteres, $ini, $tam))] = true;
                }
            }
        }

        $tokens = preg_split('//u', $texto, -1, PREG_SPLIT_NO_EMPTY);
        $destacado = array_fill(0, count($tokens), false);
        $totalTokens = count($tokens);
        $i = 0;

        while ($i < $totalTokens) {
            $encontrou = false;
            $maxTam = min(self::MAX_TAMANHO_TRECHO, $totalTokens - $i);

            for ($tam = $maxTam; $tam >= 2; $tam--) {
                $trecho = implode('', array_slice($tokens, $i, $tam));

                if (isset($ngramas[$trecho])) {
                    for ($t = $i; $t < $i + $tam; $t++) {
                        $destacado[$t] = true;
                    }

                    $i += $tam;
                    $encontrou = true;
                    break;
                }
            }

            if (!$encontrou) {
                $i++;
            }
        }

        $resultado = [];
        foreach ($tokens as $idx => $token) {
            $resultado[] = ['texto' => $token, 'destaque' => $destacado[$idx]];
        }
        return $resultado;
    }

    // mb_substr corta no meio de uma palavra quando o texto passa do limite -
    // isso deixava traduções cortadas de forma feia. Corta no último espaço
    // antes do limite. Chinês/japonês não usam espaço entre palavras, então
    // cai pra pontuação (。！？，、e equivalentes ocidentais) nesses casos.
    // Mesma lógica usada em FraseDoDia::truncarPreservandoPalavras.
    private static function truncarPreservandoPalavras(string $texto, int $limite): string
    {
        if (mb_strlen($texto) <= $limite) {
            return $texto;
        }

        $cortado = mb_substr($texto, 0, $limite);
        $pontoDeCorte = mb_strrpos($cortado, ' ');

        if ($pontoDeCorte === false) {
            foreach (['。', '！', '？', '、', '，', '.', '!', '?', ','] as $pontuacao) {
                $posicao = mb_strrpos($cortado, $pontuacao);
                if ($posicao !== false && ($pontoDeCorte === false || $posicao > $pontoDeCorte)) {
                    $pontoDeCorte = $posicao + 1;
                }
            }
        }

        if ($pontoDeCorte !== false) {
            $cortado = mb_substr($cortado, 0, $pontoDeCorte);
        }

        return rtrim($cortado, " ,;:-、，");
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
        string $mimeType,
        string $idiomaNome,
        string $idiomaNativoNome
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
            . "transcrição da resposta falada (hesitação/frase incompleta aparece como texto desconexo). "
            . "REQUISITO OBRIGATÓRIO: o aluno precisa responder em {$idiomaNome} (o mesmo idioma da pergunta) - se a "
            . "transcrição estiver em outro idioma (incluindo o idioma nativo do aluno), a resposta é automaticamente "
            . "incorreta (nota baixa, no máximo 3, correto=false), mesmo que o conteúdo em si responda bem à pergunta. "
            . "Nesse caso, o feedback deve deixar claro que a resposta precisa ser em {$idiomaNome}. Se estiver no "
            . "idioma certo, avalie normalmente se responde à pergunta e a qualidade gramatical/fluência. Dê nota de 0 "
            . "a 10, se está correto, e explique os principais erros em {$idiomaNativoNome} (máx 200 caracteres). "
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
