<?php

class FraseDoDia
{
    // Premium: 1 frase nova por dia. Limitado: 1 vitalícia (amostra grátis,
    // mesmo padrão do audio_ia_uso). Free: bloqueado.
    const LIMITE_DIARIO_PREMIUM = 1;
    const LIMITE_VITALICIO_LIMITADO = 1;
    const MAX_TENTATIVAS_POR_FRASE = 3;
    const MAX_TENTATIVAS_GERACAO = 5;

    // Limite de frases enviadas pro prompt da IA - mandar tudo que o usuário
    // já tem cadastrado gastaria tokens/tempo de processamento à toa,
    // principalmente pra quem tem centenas de frases. Prioriza as mais bem
    // estudadas (id_treino mais alto - "memorizado" antes de "em treino"
    // antes de "memorizando"), nunca as ainda não estudadas (id_treino=1).
    const MAX_FRASES_PROMPT = 50;

    // Tamanho máximo de trecho (palavras ou caracteres, conforme o idioma)
    // considerado ao montar/buscar os n-gramas do destaque de vocabulário -
    // suficiente pra capturar frases inteiras curtas sem custo quadrático
    // alto em frases muito longas.
    const MAX_TAMANHO_TRECHO = 10;

    // Trecho mínimo de 3 palavras (não 2) pra idiomas alfabéticos - com só 2,
    // pares puramente gramaticais ("and I", "on the", "because the") batem
    // com praticamente qualquer frase por coincidência, especialmente pra
    // quem já tem muitas frases cadastradas (o vocabulário fica tão amplo
    // que qualquer combinação comum de artigo/pronome/preposição acaba
    // aparecendo em alguma frase). Cada trecho também precisa ter pelo menos
    // uma palavra "de conteúdo" (ver temPalavraDeConteudo) - só bater 3
    // palavras funcionais seguidas ainda não diz nada real.
    const MIN_TAMANHO_TRECHO_PALAVRAS = 3;
    const TAMANHO_MIN_PALAVRA_CONTEUDO = 4;

    // Chinês, japonês e coreano usam caracteres muito mais densos em
    // significado que os idiomas alfabéticos - 200-220 caracteres nesses
    // idiomas equivaleria a um texto MUITO mais longo/complexo (e o cartão
    // do flashcard estouraria). Usa uma faixa bem menor pra manter a frase
    // com complexidade equivalente. Mesma lógica em DailyQuestionOpenAI.
    private static function faixaCaracteresPara(string $idiomaNome): array
    {
        $cjk = ['chin', 'japon', 'corean'];

        foreach ($cjk as $termo) {
            if (mb_stripos($idiomaNome, $termo) !== false) {
                return [60, 90];
            }
        }

        return [200, 220];
    }

    // Tradução tende a ficar um pouco mais longa que o original (idiomas
    // alfabéticos) ou seguir a mesma densidade (CJK) - dá uma margem
    // proporcional ao máximo já calculado pro idioma, em vez de um número
    // fixo que corta traduções em idiomas CJK bem antes da hora ou trunca
    // sem folga nenhuma nos idiomas alfabéticos.
    private static function limiteTraducaoPara(string $idiomaNome): int
    {
        [, $max] = self::faixaCaracteresPara($idiomaNome);

        return (int) round($max * 1.3);
    }

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
    public static function obterFraseDoDia(PDO $pdo, OpenAiChat $chat, int $user_id, string $idiomaNome, string $idiomaNativoNome, array $phrases = [], ?string $nivelNome = null): array
    {
        $nivelNome = $nivelNome ?? Nivel::nomeParaPrompt(null);
        $pendente = self::getPendente($pdo, $user_id);

        if ($pendente && !empty($pendente['frase_traducao'])) {
            return [
                "success" => true,
                "id" => (int) $pendente['id'],
                "frase" => $pendente['frase'],
                "traducao" => $pendente['frase_traducao'],
                "frase_destacada" => self::destacarPalavrasConhecidas($pendente['frase'], $phrases, $idiomaNome),
            ];
        }

        // Pendência órfã (gerada antes da coluna frase_traducao existir) - descarta
        // e gera uma nova em vez de mostrar um flashcard sem verso.
        if ($pendente) {
            $pdo->prepare("DELETE FROM frase_dia_ia WHERE id = :id")->execute([':id' => $pendente['id']]);
        }

        if (self::contarFrasesEstudadas($pdo, $user_id) < 3) {
            return ["success" => false, "message" => "Adicione mais frases aos flashcards para gerar sua frase do dia."];
        }

        // Só filtra frases vazias - NÃO exige mais de 1 palavra nem um total
        // mínimo aqui: quem já passou no gate de contarFrasesEstudadas() tem
        // conteúdo suficiente pra IA usar, mesmo que as frases individuais
        // sejam curtas (ex: só "Trip"). O prompt (via $vocabularioEscasso)
        // já lida com pouco vocabulário sem precisar bloquear o recurso.
        $phrases = array_values(array_filter($phrases, fn($p) => trim($p) !== ''));

        // $phrases já vem limitado (getFrasesDoUsuario) e ordenado por
        // prioridade de treino - embaralha só a ordem de apresentação, sem
        // mudar quais frases entram.
        shuffle($phrases);

        // Mesma lista (já filtrada e embaralhada) usada no prompt e no
        // destaque - o destaque só deve marcar o que a IA realmente viu.
        $phrasesOriginais = $phrases;

        [$min, $max] = self::faixaCaracteresPara($idiomaNome);
        $limiteTraducao = self::limiteTraducaoPara($idiomaNativoNome);
        $phrasesText = implode("\n", array_map(fn($p) => mb_substr($p, 0, $max), $phrases));

        // Quando o aluno tem pouco vocabulário estudado (ex: acabou de passar
        // no gate de 3 frases treinadas, todas curtas), exigir 80% de uso desse
        // vocabulário bate de frente com o requisito de tamanho da frase -
        // forçaria repetir sempre as mesmas poucas palavras de forma forçada.
        // Relaxa a exigência de vocabulário nesse caso, priorizando uma frase
        // natural e no tamanho certo.
        $totalPalavrasEstudadas = array_sum(array_map('str_word_count', $phrases));
        $vocabularioEscasso = $totalPalavrasEstudadas < 40;

        $instrucaoVocabulario = $vocabularioEscasso
            ? "O aluno ainda tem pouco vocabulário estudado. Use o que fizer sentido das frases dele a seguir, mas "
                . "tem liberdade de completar com palavras comuns do idioma pra formar uma frase natural e completa - "
                . "não force repetir sempre as mesmas palavras só pra bater um percentual; priorize soar natural e "
                . "ficar no tamanho certo."
            : "Use o MÁXIMO possível (pelo menos 80%) do vocabulário da frase a partir das frases que o aluno já "
                . "estuda (fornecidas a seguir), pra reforçar o que ele está aprendendo de verdade - a frase não "
                . "precisa ser idêntica a nenhuma delas. Só use palavras genéricas do idioma (artigos, conectivos, "
                . "concordância gramatical) quando forem realmente necessárias pra frase soar natural, não como "
                . "preenchimento.";

        $systemPrompt = "Você é um professor de idiomas escrevendo uma frase de exemplo em {$idiomaNome} pra um aluno "
            . "de nível {$nivelNome}. Ajuste o vocabulário e a complexidade gramatical da frase pro nível dele - "
            . "iniciante pede estruturas simples e vocabulário básico do dia a dia; intermediário pode incluir "
            . "conectivos e tempos verbais variados; avançado pode usar vocabulário mais rico e estruturas mais "
            . "elaboradas. "
            . "REQUISITO MAIS IMPORTANTE: a frase precisa ter entre {$min} e {$max} caracteres, contando espaços e "
            . "pontuação - conte os caracteres mentalmente antes de responder e, se estiver fora dessa faixa, "
            . "reescreva até acertar. Frases curtas de uma oração só (tipo 'I like coffee.') SEMPRE ficam curtas "
            . "demais - por isso a frase precisa ter pelo menos duas orações ligadas por conectivos (e, mas, porque, "
            . "quando, embora, já que, etc.) ou uma oração com detalhes extras (tempo, lugar, motivo), pra "
            . "naturalmente ocupar esse espaço. A frase deve ser natural e do dia a dia, adequada pra um aluno ler em "
            . "voz alta como exercício de pronúncia, e sempre uma afirmação (declarativa) - nunca uma pergunta. "
            . "{$instrucaoVocabulario} Gramaticalmente correta. Não repita estruturas óbvias como 'My name is'. "
            . 'Responda em JSON: {"frase": "...", "traducao": "..."}';

        $userContent = "Frases que o aluno já estuda:\n" . $phrasesText;

        // O prompt já pede a faixa certa de caracteres, mas a IA nem sempre
        // obedece à risca - tenta de novo (até MAX_TENTATIVAS_GERACAO vezes)
        // até a frase cair de fato nessa faixa, em vez de confiar só na
        // instrução.
        $frase = null;
        $traducao = null;

        for ($tentativa = 1; $tentativa <= self::MAX_TENTATIVAS_GERACAO; $tentativa++) {
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

            $fraseCandidata = trim((string) $decodificado['frase'], "\" \n\r\t");
            $traducaoCandidata = trim((string) ($decodificado['traducao'] ?? ''), "\" \n\r\t");

            // Se saiu curta, tenta expandir a MESMA frase (tarefa mais fácil pro
            // modelo acertar do que gerar já com o tamanho certo do zero) antes
            // de desistir e regenerar tudo de novo.
            if (mb_strlen($fraseCandidata) < $min) {
                $expandida = self::expandirFrase($chat, $fraseCandidata, $idiomaNome, $idiomaNativoNome, $min, $max);
                if ($expandida !== null) {
                    $fraseCandidata = $expandida['frase'];
                    $traducaoCandidata = $expandida['traducao'];
                }
            }

            if (mb_strlen($fraseCandidata) >= $min && mb_strlen($fraseCandidata) <= $max) {
                $frase = $fraseCandidata;
                $traducao = self::truncarPreservandoPalavras($traducaoCandidata, $limiteTraducao);
                break;
            }

            // Guarda a última tentativa como fallback caso nenhuma acerte a faixa.
            $frase = self::truncarPreservandoPalavras($fraseCandidata, $max);
            $traducao = self::truncarPreservandoPalavras($traducaoCandidata, $limiteTraducao);
        }

        $stmt = $pdo->prepare("INSERT INTO frase_dia_ia (user_id, frase, frase_traducao, status_id) VALUES (:user_id, :frase, :traducao, 0)");
        $stmt->execute([':user_id' => $user_id, ':frase' => $frase, ':traducao' => $traducao]);

        return [
            "success" => true,
            "id" => (int) $pdo->lastInsertId(),
            "frase" => $frase,
            "traducao" => $traducao,
            "frase_destacada" => self::destacarPalavrasConhecidas($frase, $phrasesOriginais, $idiomaNome),
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

                    if (self::temPalavraDeConteudo($trecho)) {
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
    private static function temPalavraDeConteudo(array $palavras): bool
    {
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

            for ($tam = $maxTam; $tam >= self::MIN_TAMANHO_TRECHO_PALAVRAS; $tam--) {
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
    // isso deixava traduções cortadas de forma feia (ex: "vale a pen" em vez
    // de "vale a pena..."). Corta no último espaço antes do limite. Chinês/
    // japonês não usam espaço entre palavras, então cai pra pontuação (。！？，、
    // e equivalentes ocidentais) como ponto de corte nesses casos.
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

    // Expande uma frase curta demais em vez de descartar e gerar tudo de novo -
    // pedir pra IA completar um texto existente até um tamanho é uma tarefa
    // mais confiável do que pedir pra acertar o tamanho certo já na primeira
    // geração.
    private static function expandirFrase(OpenAiChat $chat, string $frase, string $idiomaNome, string $idiomaNativoNome, int $min, int $max): ?array
    {
        $tamanhoAtual = mb_strlen($frase);

        $prompt = "A frase a seguir, em {$idiomaNome}, tem {$tamanhoAtual} caracteres e está curta demais. Reescreva "
            . "ela adicionando um detalhe ou uma oração extra (ligada por 'e', 'porque', 'quando', 'mas', 'já que', "
            . "etc.), mantendo o sentido original, até ficar com EXATAMENTE entre {$min} e {$max} caracteres no total. "
            . "Também gere a tradução em {$idiomaNativoNome}. Frase original: \"{$frase}\". "
            . 'Responda em JSON: {"frase": "...", "traducao": "..."}';

        $resultado = $chat->completar([
            ['role' => 'system', 'content' => $prompt],
        ], true, 400);

        if ($resultado['erro']) {
            return null;
        }

        $decodificado = json_decode($resultado['texto'], true);

        if (!is_array($decodificado) || empty($decodificado['frase'])) {
            return null;
        }

        return [
            'frase' => trim((string) $decodificado['frase'], "\" \n\r\t"),
            'traducao' => trim((string) ($decodificado['traducao'] ?? ''), "\" \n\r\t"),
        ];
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
        string $mimeType,
        string $idiomaNome,
        string $idiomaNativoNome
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
            . "Vai receber a frase original (em {$idiomaNome}) e a transcrição de voz-pra-texto do que o aluno disse. "
            . "REQUISITO OBRIGATÓRIO: o aluno precisa ler em {$idiomaNome} - se a transcrição estiver em outro idioma "
            . "(por exemplo, se ele leu a tradução em vez da frase original), a leitura é automaticamente incorreta "
            . "(nota no máximo 3 em todos os campos), e o feedback de pronúncia deve deixar claro que ele leu no "
            . "idioma errado. Se estiver no idioma certo, divergências entre a frase original e a transcrição podem "
            . "indicar erro de pronúncia, palavra trocada/omitida ou hesitação - avalie gramática, pronúncia (com base "
            . "na divergência da transcrição) e fluência normalmente. "
            . "Dê nota de 0 a 10 e feedback curto (máx 150 caracteres cada campo) em {$idiomaNativoNome}, gentil e "
            . "específico. "
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
                AND f.status_id > 0
                AND f.id_treino >= 2
                ORDER BY f.id_treino DESC, RAND()
                LIMIT " . self::MAX_FRASES_PROMPT;

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

    // Nível de proficiência informado pelo usuário no cadastro (Nivel::registrar)
    // - usado no prompt pra ajustar a complexidade da frase gerada.
    public static function getNivelNome(PDO $pdo, int $user_id): string
    {
        $stmt = $pdo->prepare("SELECT nivel FROM usuarios WHERE id = :id");
        $stmt->execute([':id' => $user_id]);
        $nivel = $stmt->fetch(PDO::FETCH_ASSOC)['nivel'] ?? null;

        return Nivel::nomeParaPrompt($nivel !== null ? (int) $nivel : null);
    }
}
