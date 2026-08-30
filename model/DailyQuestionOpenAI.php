<?php

// Versão OpenAI (gpt-5-nano) do recurso de perguntas diárias - substitui
// model/DailyQuestionIA.php (Groq) no fluxo ativo, mas não o exclui (mesmo
// padrão de api/ElevenLabs.php: fica intocado, só sem uso).
// Diferença principal: resposta agora é por voz (gravada, transcrita) em vez
// de texto digitado, e o limite é diário pros dois planos (maior pro
// premium) em vez de um número fixo pra todo mundo.
class DailyQuestionOpenAI
{
    const LIMITE_DIARIO_PREMIUM = 5;
    const LIMITE_DIARIO_LIMITADO = 1;
    const MAX_TENTATIVAS_POR_PERGUNTA = 3;
    const MAX_TENTATIVAS_GERACAO = 5;

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
    // Iniciante recebe um limite bem menor - pergunta curta e direta é mais
    // fácil de entender e responder oralmente em uma frase pra quem ainda tá
    // começando; o limite maior (220/90) fica pra intermediário/avançado.
    private static function limiteCaracteresPara(string $idiomaNome, ?string $nivelNome = null): int
    {
        $cjk = ['chin', 'japon', 'corean'];
        $ehCjk = false;

        foreach ($cjk as $termo) {
            if (mb_stripos($idiomaNome, $termo) !== false) {
                $ehCjk = true;
                break;
            }
        }

        $ehIniciante = $nivelNome !== null && mb_stripos($nivelNome, 'iniciante') !== false;

        if ($ehIniciante) {
            return $ehCjk ? 40 : 100;
        }

        return $ehCjk ? 90 : 220;
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

    public static function verificarAcesso(PDO $pdo, int $user_id, int $plano): ?array
    {
        if ($plano === 1) {
            if (self::contarHoje($pdo, $user_id) >= self::LIMITE_DIARIO_PREMIUM) {
                return ["success" => false, "limite_atingido" => true, "message" => "Você já respondeu " . self::LIMITE_DIARIO_PREMIUM . " perguntas hoje. Volte amanhã!"];
            }
            return null;
        }

        if ($plano === 3) {
            if (self::contarHoje($pdo, $user_id) >= self::LIMITE_DIARIO_LIMITADO) {
                return ["success" => false, "limite_atingido" => true, "message" => "Você já respondeu " . self::LIMITE_DIARIO_LIMITADO . " pergunta hoje. Volte amanhã ou vire premium para mais perguntas por dia."];
            }
            return null;
        }

        return ["success" => false, "premium_necessario" => true, "message" => "Perguntas diárias por IA são um recurso exclusivo do plano Premium."];
    }

    private static function getPendente(PDO $pdo, int $user_id): ?array
    {
        $sql = "SELECT id, question, question_traducao, DATE(data_criacao) = CURDATE() AS eh_de_hoje
                FROM perguntas_ia
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
    //
    // Checa f.id_treino >= 2 TAMBÉM (não só o histórico) - treino_data_atualizacao
    // pode dessincronizar do campo atual da frase (updateRepeat só avança o
    // histórico se achar uma linha com o valor antigo exato, então uma
    // dessincronia antiga nunca se corrige sozinha) - sem esse OR, um usuário
    // com frases genuinamente treinadas (id_treino >= 2 na tabela frases)
    // ficava travado no gate pra sempre, reportado mais de uma vez.
    private static function contarFrasesEstudadas(PDO $pdo, int $user_id): int
    {
        $sql = "SELECT COUNT(DISTINCT f.id) as total
                FROM frases f
                INNER JOIN idioma_referencia ir
                    ON ir.idioma_nativo = f.idioma_nativo
                    AND ir.idioma_aprender = f.idioma_aprendendo
                    AND ir.id_user = :user_id
                LEFT JOIN treino_data_atualizacao t
                    ON t.id_frase = f.id
                    AND t.id_treino >= 2
                WHERE f.usuario_id = :user_id
                AND f.status_id > 0
                AND (f.id_treino >= 2 OR t.id IS NOT NULL)";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([':user_id' => $user_id]);
        return (int) $stmt->fetch(PDO::FETCH_ASSOC)['total'];
    }

    // Usado só pra recalcular o destaque de uma pergunta PENDENTE (já gerada,
    // ainda não respondida) - reabrir a tela sorteia um novo lote aleatório de
    // até 50 frases (mesmo filtro de par de idioma do controller), quase
    // certamente diferente do lote que gerou o texto original, fazendo o
    // destaque sumir mesmo a pergunta reaproveitando vocabulário de verdade.
    // Sem LIMIT porque isso é só computação local (n-gramas), não vai pra IA.
    public static function getTodasFrasesElegiveis(PDO $pdo, int $user_id): array
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
                AND f.status_id > 0";

        $stmt = $pdo->prepare($sql);
        $stmt->execute([':user_id' => $user_id]);

        return $stmt->fetchAll(PDO::FETCH_COLUMN);
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

        if ($pendente && !empty($pendente['question_traducao']) && !empty($pendente['eh_de_hoje'])) {
            $todasFrases = self::getTodasFrasesElegiveis($pdo, $user_id);

            return [
                "success" => true,
                "id" => (int) $pendente['id'],
                "question" => $pendente['question'],
                "traducao" => $pendente['question_traducao'],
                "question_destacada" => self::destacarPalavrasConhecidas($pendente['question'], $todasFrases, $idiomaNome),
            ];
        }

        // Pendência órfã (gerada antes da coluna question_traducao existir,
        // OU abandonada de um dia anterior - usuário gerou a pergunta e
        // nunca respondeu nem pulou) - descarta e gera uma nova em vez de
        // reaproveitar. Sem o filtro de data, uma pergunta de dias atrás
        // "ressuscitava" como se fosse a de hoje, e como a data de criação
        // dela continuava antiga, respondê-la não contava pro contador
        // diário (numero ficava travado em 1, reportado pelo usuário).
        if ($pendente) {
            $pdo->prepare("DELETE FROM perguntas_ia WHERE id = :id")->execute([':id' => $pendente['id']]);
        }

        if (self::contarFrasesEstudadas($pdo, $user_id) < 3) {
            return ["success" => false, "conteudo_insuficiente" => true, "message" => "Treine pelo menos 3 frases nos flashcards para desbloquear as perguntas faladas com IA."];
        }

        $phrases = array_filter($phrases, fn($p) => str_word_count($p) >= 3);
        $phrases = array_values($phrases);

        // Motivo diferente da checagem acima de propósito - aqui o usuário já
        // treinou frases suficientes, só que curtas demais (ex: só palavras
        // soltas como "Trip"). "message" é só texto de log/debug em
        // português - o front NUNCA deve exibir esse texto direto pro
        // usuário (quebraria os outros 14 idiomas do app); "frases_curtas"
        // é o campo que o front usa pra escolher a tradução certa.
        if (count($phrases) < 3) {
            return ["success" => false, "conteudo_insuficiente" => true, "frases_curtas" => true, "message" => "Treine frases mais completas nos flashcards (não só palavras soltas) para gerar perguntas melhores."];
        }

        // $phrases já vem limitado (getUserPhrases) e ordenado por prioridade
        // de treino - embaralha só a ordem de apresentação, sem mudar quais
        // frases entram.
        shuffle($phrases);

        // Mesma lista (já filtrada e embaralhada) usada no prompt e no
        // destaque - o destaque só deve marcar o que a IA realmente viu.
        $phrasesOriginais = $phrases;

        $maxPergunta = self::limiteCaracteresPara($idiomaNome, $nivelNome);
        $limiteTraducao = self::limiteTraducaoPara($idiomaNativoNome);
        $phrases = array_map(fn($p) => mb_substr($p, 0, $maxPergunta), $phrases);
        $phrasesText = implode("\n", $phrases);

        // Prompt validado com testes reais na API (não só teoria) contra frases
        // de um usuário de verdade - a versão anterior (que pedia pra usar o
        // MÁXIMO de trechos possível) gerava perguntas incoerentes com
        // frequência (ex: misturava "você é novo aqui" com "você chegará lá",
        // duas cenas de lugares diferentes só por compartilharem uma palavra
        // parecida). Trocado o incentivo de "usar o máximo" por "coerência
        // antes de quantidade" - testado de novo com as mesmas frases
        // problemáticas e outro lote de frases desconexas, sem repetir esse
        // tipo de mistura em nenhuma tentativa.
        //
        // Efeito colateral descoberto depois (usuário reportou só ver
        // conteúdo de uma categoria específica): a instrução "é preferível
        // usar 1 frase só" fazia a IA ancorar quase sempre numa frase/tema só
        // por geração, mesmo com frases de 5+ categorias diferentes
        // disponíveis no pool - testado com 54 frases reais de 5 categorias,
        // 8 gerações: só 3 categorias apareceram. Trocado o padrão pra
        // "combine quando for plausível" (testado de novo com o mesmo lote:
        // mais categorias representadas), mantendo as mesmas travas de
        // coerência (lugar/tempo/interlocutor) como exceção, não como regra.
        $systemPrompt = "Você é um professor de idiomas. Crie UMA pergunta simples em {$idiomaNome}, pra um aluno de "
            . "nível {$nivelNome}, respondível oralmente em uma frase, e também a tradução dela em {$idiomaNativoNome}. "
            . "Ajuste o vocabulário e a complexidade gramatical da pergunta pro nível do aluno - iniciante pede "
            . "uma pergunta CURTA e direta, com estruturas simples e vocabulário básico (evite orações "
            . "subordinadas ou múltiplas ideias na mesma pergunta); intermediário pode incluir conectivos e "
            . "tempos verbais variados; avançado pode usar vocabulário mais rico e estruturas mais elaboradas. "
            . "Monte a pergunta usando trechos das frases fornecidas pelo aluno a seguir - elas são a matéria-prima "
            . "principal da pergunta, não apenas uma referência solta de vocabulário. As frases vêm de temas "
            . "variados do dia a dia do aluno - SEMPRE QUE FOR PLAUSÍVEL, combine trechos de frases de temas "
            . "DIFERENTES dentro de uma mesma cena real (ex: uma situação sobre compras pode mencionar algo de "
            . "saúde, tipo comprar remédio numa farmácia) - varie as fontes em vez de girar sempre em torno de uma "
            . "frase só, desde que a combinação faça sentido como uma cena real e coerente. Só use 1 frase só (ou "
            . "nenhuma combinação, criando algo simples inspirado "
            . "em UMA delas) quando as frases disponíveis realmente não combinarem de forma natural - coerência "
            . "sempre vem antes de quantidade de trechos, mas dentro do que for coerente, misturar mais de uma "
            . "fonte é melhor que usar sempre só uma. O conteúdo (temas, ações, situações) tem que vir claramente "
            . "do que está nessas frases, não de uma ideia nova inventada do zero. As frases do aluno "
            . "vêm de conversas soltas e diferentes entre si - muitas só fazem sentido dentro do contexto original "
            . "delas (ex: uma resposta a alguém específico, uma instrução dirigida a outra pessoa, uma expressão "
            . "sobre um assunto que não tem nada a ver com outra frase da lista mesmo se compartilharem uma "
            . "palavra). Preste atenção especial a: (1) quem fala e sobre quem/com quem se fala (sujeito, pessoa "
            . "gramatical, referências a outras pessoas); (2) referências de lugar e tempo (aqui/lá, hoje/aquele "
            . "dia, isso/aquilo) - NUNCA misture uma frase que fala de estar EM algum lugar com outra que fala de "
            . "IR pra outro lugar diferente, ou uma frase sobre HOJE com outra sobre um dia diferente, só porque "
            . "compartilham uma palavra parecida. Exemplo do que NÃO fazer: frase A = \"você é novo aqui, certo?\" "
            . "(sobre estar em um lugar) + frase B = \"você chegará lá\" (sobre chegar em OUTRO lugar, viagem) "
            . "viram \"Você é novo aqui, o que gosta de fazer quando chega lá?\" - isso é incoerente, são dois "
            . "lugares/situações diferentes coladas só por acaso terem uma palavra parecida (aqui/lá). Nesse caso "
            . "use só a frase A ou só a B, nunca as duas juntas. LIMITE RÍGIDO: NUNCA use trechos de mais de 2 "
            . "frases-fonte diferentes na mesma pergunta. CUIDADO ESPECIAL: quando as frases do aluno forem "
            . "afirmações soltas sobre a vida/identidade dele (onde nasceu, do que gosta, no que trabalha, o que "
            . "deseja), a tentação natural é criar uma pergunta que já emenda várias delas de uma vez (ex: \"Você "
            . "nasceu no Brasil, ama trabalhar com computadores e espera inspirar outras pessoas - o que te "
            . "motiva a continuar?\") - isso é o MESMO erro de listar fatos soltos, só que disfarçado de pergunta "
            . "de entrevista/autoapresentação. Nesse caso, escolha só 1 ou 2 desses fatos (nunca mais) e pergunte "
            . "sobre uma cena curta e específica em torno deles. A pergunta final tem que soar como se fosse dita "
            . "por UM narrador, sobre UMA cena real e específica só. Se um trecho só encaixar ajustando pessoa "
            . "gramatical, tempo verbal ou outro detalhe gramatical pra combinar com o resto, ajuste; se não der "
            . "pra encaixar sem parecer forçado, não use esse trecho. Revise mentalmente a pergunta final antes de "
            . "responder pra garantir que ela é SEM REDUNDÂNCIA - nunca use duas palavras/expressões diferentes "
            . "fazendo o mesmo papel na mesma frase (ex: \"and then... afterward\" repete a mesma ideia de sequência "
            . "temporal duas vezes; o mesmo vale pra cortesia duplicada, tipo \"please\" no início E no fim). Escolha "
            . "só uma. O campo \"pergunta\" tem que conter SÓ a "
            . "pergunta final em si, ESCRITA INTEIRAMENTE EM {$idiomaNome} do início ao fim (nunca troque de "
            . "idioma no meio nem responda em outro idioma) - nunca inclua comentários, explicações ou qualquer "
            . "menção de como/por que você combinou as frases. "
            . "Máximo {$maxPergunta} caracteres na "
            . "pergunta. Não use aspas. "
            . 'Responda em JSON: {"pergunta": "...", "traducao": "..."}';

        // A IA nem sempre respeita o limite de caracteres à primeira tentativa -
        // tenta encurtar a MESMA pergunta (até MAX_TENTATIVAS_GERACAO vezes)
        // em vez de truncar o texto cru, que corta a pergunta no meio (ex:
        // "...and I'll stay focused to", sem terminar o pensamento).
        $question = null;
        $traducao = null;

        for ($tentativa = 1; $tentativa <= self::MAX_TENTATIVAS_GERACAO; $tentativa++) {
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

            $questionCandidata = trim((string) $decodificado['pergunta'], "\" \n\r\t");
            $traducaoCandidata = trim((string) ($decodificado['traducao'] ?? ''), "\" \n\r\t");

            if (mb_strlen($questionCandidata) > $maxPergunta) {
                $encurtada = self::encurtarPergunta($chat, $questionCandidata, $idiomaNome, $idiomaNativoNome, $maxPergunta);
                if ($encurtada !== null) {
                    $questionCandidata = $encurtada['pergunta'];
                    $traducaoCandidata = $encurtada['traducao'];
                }
            }

            // Além do tamanho, a pergunta precisa terminar de fato com "?" - o
            // limite de caracteres é bem menor que o da frase do dia, então o
            // corte no encurtamento tem mais chance de comer o final e deixar a
            // pergunta incompleta. Confere aqui em vez de confiar só no prompt.
            $terminaComInterrogacao = mb_substr(rtrim($questionCandidata), -1) === '?';

            // O prompt já pede "no máximo 2 frases-fonte", mas isso é só uma
            // instrução de linguagem natural - confirmado com 50 gerações
            // reais (Frase do Dia) que 16% delas ainda combinavam 3+ fontes,
            // principalmente quando as frases do pool são do mesmo tema e
            // "encaixam bem" juntas. Verifica de verdade em vez de confiar só
            // na instrução.
            $excedeuFontes = count(RotacaoFrasesIA::frasesFonteDetectadas($phrasesOriginais, $questionCandidata)) > 2;

            if (mb_strlen($questionCandidata) <= $maxPergunta && $terminaComInterrogacao && !$excedeuFontes) {
                $question = $questionCandidata;
                $traducao = self::truncarPreservandoPalavras($traducaoCandidata, $limiteTraducao);
                break;
            }

            // Guarda a última tentativa como fallback caso nenhuma acerte o limite -
            // mantém a pergunta completa (mesmo que um pouco fora do limite) em vez
            // de truncar no meio, o que deixaria o final sem sentido.
            $question = $questionCandidata;
            $traducao = $traducaoCandidata;
        }

        $stmt = $pdo->prepare("INSERT INTO perguntas_ia (user_id, status_id, question, question_traducao) VALUES (:user_id, 0, :question, :traducao)");
        $stmt->execute([':user_id' => $user_id, ':question' => $question, ':traducao' => $traducao]);
        // Captura o id JÁ AQUI - ver comentário equivalente em
        // FraseDoDia::obterFraseDoDia (lastInsertId() depois de
        // registrarUsadas() retorna 0, confirmado isolado).
        $novoId = (int) $pdo->lastInsertId();

        // Marca quais frases do pool foram de fato usadas nesta geração,
        // pra elas saírem de circulação por um tempo (ver RotacaoFrasesIA).
        RotacaoFrasesIA::registrarUsadas($pdo, $user_id, $phrasesOriginais, $question);

        return [
            "success" => true,
            "id" => $novoId,
            "question" => $question,
            "traducao" => $traducao,
            // Contra TODO o catálogo elegível do usuário, não só o pool de até
            // 50 frases usado nesta geração - senão o destaque nunca marca
            // palavra conhecida que só aparece em frases de fora do pool
            // sorteado desta vez (mesmo catálogo já usado no ramo de
            // pendência acima, em getPendente/$todasFrases - eram
            // inconsistentes, reportado pelo usuário como "não mostra todas
            // as palavras que já estudo").
            "question_destacada" => self::destacarPalavrasConhecidas($question, self::getTodasFrasesElegiveis($pdo, $user_id), $idiomaNome),
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
    // A IA costuma gerar contrações com apóstrofo tipográfico (U+2019, "I’ll")
    // enquanto as frases que o aluno digitou usam o apóstrofo reto (U+0027,
    // "I'll") - sem normalizar, as duas formas tokenizam igual mas nunca batem
    // como string, quebrando o destaque de qualquer trecho com contração. Só
    // usado nas chaves de comparação, nunca no texto exibido.
    private static function normalizarApostrofo(string $texto): string
    {
        return str_replace('’', "'", $texto);
    }

    private static function construirNGramasPalavras(array $phrases): array
    {
        $ngramas = [];

        foreach ($phrases as $frase) {
            preg_match_all('/[\p{L}\p{N}\'’]+/u', self::normalizarApostrofo(mb_strtolower($frase)), $m);
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
        $tokens = preg_split('/([\p{L}\p{N}\'’]+)/u', $texto, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY);

        $palavrasTexto = [];
        foreach ($tokens as $i => $token) {
            if (preg_match('/^[\p{L}\p{N}\'’]+$/u', $token) === 1) {
                // normaliza o apóstrofo só pra comparação - o texto original (com o
                // apóstrofo tipográfico que a IA costuma usar) continua intacto no
                // token exibido pro usuário.
                $palavrasTexto[] = ['indiceToken' => $i, 'palavra' => self::normalizarApostrofo(mb_strtolower($token))];
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
                $bateu = isset($ngramas[implode(' ', $palavras)]);

                // Se não bateu em cheio, tenta de novo removendo UMA palavra curta
                // (gramatical - artigo, preposição etc.) de dentro do trecho. A IA
                // às vezes reescreve a frase do aluno inserindo uma palavra assim
                // pra soar mais natural/correto (ex: frase do aluno "go for walk",
                // texto gerado "go for a walk") - sem essa tolerância, essa
                // reaproveitagem legítima nunca seria reconhecida, mesmo sendo
                // claramente a mesma frase de origem.
                if (!$bateu && $tam >= 3) {
                    foreach ($palavras as $idx => $palavra) {
                        if (mb_strlen($palavra) >= self::TAMANHO_MIN_PALAVRA_CONTEUDO) {
                            continue;
                        }
                        $reduzido = $palavras;
                        unset($reduzido[$idx]);
                        if (isset($ngramas[implode(' ', $reduzido)])) {
                            $bateu = true;
                            break;
                        }
                    }
                }

                if ($bateu) {
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

    // Encurta uma pergunta longa demais em vez de truncar o texto cru - truncar
    // corta no meio de uma oração, o que é pior que a pergunta ficar um pouco
    // fora do limite. Pedir pra IA reescrever mais curta preserva o sentido e
    // garante que a pergunta termine de forma gramaticalmente completa.
    private static function encurtarPergunta(OpenAiChat $chat, string $pergunta, string $idiomaNome, string $idiomaNativoNome, int $maxPergunta): ?array
    {
        $tamanhoAtual = mb_strlen($pergunta);

        $prompt = "A pergunta a seguir, em {$idiomaNome}, tem {$tamanhoAtual} caracteres e está longa demais. "
            . "Reescreva ela removendo um detalhe, mantendo o sentido principal, até ficar com no máximo "
            . "{$maxPergunta} caracteres no total. A pergunta reescrita precisa continuar sendo uma pergunta "
            . "completa, terminando com \"?\" (nunca cortada no meio), e continuar em {$idiomaNome} (nunca mude de "
            . "idioma). Também gere a tradução em {$idiomaNativoNome}. Pergunta original: \"{$pergunta}\". "
            . 'Responda em JSON: {"pergunta": "...", "traducao": "..."}';

        $resultado = $chat->completar([
            ['role' => 'system', 'content' => $prompt],
        ], true, 400);

        if ($resultado['erro']) {
            return null;
        }

        $decodificado = json_decode($resultado['texto'], true);

        if (!is_array($decodificado) || empty($decodificado['pergunta'])) {
            return null;
        }

        return [
            'pergunta' => trim((string) $decodificado['pergunta'], "\" \n\r\t"),
            'traducao' => trim((string) ($decodificado['traducao'] ?? ''), "\" \n\r\t"),
        ];
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
    // Mapeia o nome do idioma (ex: "Inglês") pra sigla ISO-639-1 (ex: "en") -
    // hint pro Whisper na transcrição do áudio de resposta (ver comentário em
    // OpenAiTranscribe::transcrever).
    private static function siglaIdioma(PDO $pdo, string $nomeIdioma): ?string
    {
        $stmt = $pdo->prepare("SELECT sigla FROM idiomas WHERE idioma = :nome LIMIT 1");
        $stmt->execute([':nome' => $nomeIdioma]);
        return $stmt->fetch(PDO::FETCH_ASSOC)['sigla'] ?? null;
    }

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
        $row = self::buscarPerguntaPendente($pdo, $perguntaId, $user_id);

        if (!$row) {
            return ["success" => false, "message" => "Pergunta não encontrada ou já respondida."];
        }

        $tentativaAtual = (int) $row['tentativas'] + 1;

        $nomeArquivo = "audio." . self::extensaoParaMime($mimeType);
        $transcricaoResult = $transcribe->transcrever($caminhoAudio, $nomeArquivo, $mimeType, self::siglaIdioma($pdo, $idiomaNome));

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

        return self::avaliarESalvarResposta($pdo, $chat, $perguntaId, $row, $tentativaAtual, $transcricao, $idiomaNome, $idiomaNativoNome, true);
    }

    // Mesmo fluxo de avaliação do áudio, mas pra quem prefere digitar a
    // resposta em vez de gravar (botão "Digitar" na tela de Perguntas) - sem
    // etapa de transcrição, o texto digitado já é a resposta final.
    public static function responderTexto(
        PDO $pdo,
        OpenAiChat $chat,
        int $user_id,
        int $perguntaId,
        string $respostaTexto,
        string $idiomaNome,
        string $idiomaNativoNome
    ): array {
        $row = self::buscarPerguntaPendente($pdo, $perguntaId, $user_id);

        if (!$row) {
            return ["success" => false, "message" => "Pergunta não encontrada ou já respondida."];
        }

        $respostaTexto = trim($respostaTexto);

        if ($respostaTexto === '') {
            return ["success" => false, "message" => "Digite uma resposta."];
        }

        $tentativaAtual = (int) $row['tentativas'] + 1;

        return self::avaliarESalvarResposta($pdo, $chat, $perguntaId, $row, $tentativaAtual, $respostaTexto, $idiomaNome, $idiomaNativoNome, false);
    }

    private static function buscarPerguntaPendente(PDO $pdo, int $perguntaId, int $user_id): ?array
    {
        $stmt = $pdo->prepare("SELECT question, tentativas FROM perguntas_ia WHERE id = :id AND user_id = :user_id AND status_id = 0");
        $stmt->execute([':id' => $perguntaId, ':user_id' => $user_id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    private static function avaliarESalvarResposta(
        PDO $pdo,
        OpenAiChat $chat,
        int $perguntaId,
        array $row,
        int $tentativaAtual,
        string $resposta,
        string $idiomaNome,
        string $idiomaNativoNome,
        bool $ehAudio
    ): array {
        $question = $row['question'];

        // Mesma checagem já aplicada em frases/categorias - a resposta é
        // texto que o próprio usuário produziu, sem moderação nenhuma antes disso.
        if (verificarConteudoImproprio($resposta)) {
            $esgotou = self::registrarTentativaSemNota($pdo, $perguntaId, $tentativaAtual, $resposta);

            return [
                "success" => false,
                "pode_tentar_novamente" => !$esgotou,
                "message" => "A resposta contém conteúdo impróprio."
            ];
        }

        // Foco da correção muda conforme a origem: quem grava está treinando
        // fala (a transcrição pode trazer hesitação/texto desconexo, e erros
        // de pronúncia às vezes aparecem como palavras estranhas/erradas na
        // transcrição); quem digita está treinando escrita de verdade
        // (ortografia, gramática, concordância avaliadas com precisão, sem
        // margem pra "ruído de transcrição").
        if ($ehAudio) {
            $tipoResposta = "ORAL (transcrita automaticamente a partir do áudio)";
            $focoAvaliacao = "Avalie principalmente se a resposta faz sentido pra pergunta e a fluência/gramática da "
                . "fala. Como o texto vem de transcrição automática, palavras estranhas ou fora de contexto podem "
                . "indicar erro de pronúncia do aluno (e não erro de digitação) - quando notar isso, mencione no "
                . "feedback como possível problema de pronúncia daquela palavra, sem penalizar tanto quanto um erro "
                . "gramatical real.";
        } else {
            $tipoResposta = "ESCRITA (digitada pelo próprio aluno)";
            $focoAvaliacao = "Avalie com rigor a escrita: ortografia, gramática, concordância (verbal e nominal), "
                . "pontuação e uso correto das palavras, além de se a resposta faz sentido pra pergunta. Como o "
                . "aluno digitou a resposta, não há motivo pra tolerância com erros - aponte no feedback os erros "
                . "de escrita mais importantes de forma específica.";
        }

        // Testado direto na API: gpt-5-nano confundia resposta curta/incompleta
        // com idioma errado (ex: pergunta "How old are you?", resposta "25" -
        // vinha "não está em inglês", mesmo sem nenhuma palavra em outro
        // idioma). A exceção de números/nomes próprios abaixo existe só pra
        // corrigir esse falso positivo - testado várias vezes que sem ela o
        // nano reincide, e que a exceção sozinha (sem manter "automaticamente
        // incorreta" pra palavras de outro idioma) deixava passar respostas
        // realmente em português.
        $systemPrompt = "Você é um professor de idiomas avaliando a resposta {$tipoResposta} de um aluno. Vai receber a "
            . "pergunta e a resposta do aluno. "
            . "REQUISITO OBRIGATÓRIO: o aluno precisa responder em {$idiomaNome} (o mesmo idioma da pergunta) - se a "
            . "resposta tiver palavras de outro idioma (verbos, artigos, conectivos, substantivos etc, incluindo do "
            . "idioma nativo do aluno), ela é automaticamente incorreta (nota baixa, no máximo 3, correto=false), "
            . "mesmo que o conteúdo em si responda bem à pergunta, e o feedback deve deixar claro que a resposta "
            . "precisa ser em {$idiomaNome}. EXCEÇÃO: números, nomes próprios e datas sozinhos (ex: \"25\", \"John\", "
            . "\"3 pm\") não têm idioma - nesse caso NÃO conte como idioma errado nem penalize por estar "
            . "\"incompleta\"/sem frase completa, avalie normalmente o conteúdo. Se estiver no idioma certo, avalie "
            . "se responde à pergunta e sua qualidade. {$focoAvaliacao} Dê nota de 0 "
            . "a 10, se está correto, e explique os principais erros em {$idiomaNativoNome} (máx 200 caracteres). "
            . 'Responda em JSON: {"nota": 0-10, "correto": true ou false, "feedback": "..."}';

        $correcaoResult = $chat->completar([
            ['role' => 'system', 'content' => $systemPrompt],
            ['role' => 'user', 'content' => "Pergunta: {$question}\nResposta do aluno: {$resposta}"],
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
            ':transcricao' => $resposta,
            ':nota' => $nota,
            ':feedback' => $feedback,
            ':id' => $perguntaId,
        ]);

        return [
            "success" => true,
            "transcricao" => $resposta,
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
