<?php

// Modo de treino de IA "Tradução Reversa" - o INVERSO de Perguntas/Frase do
// Dia: a IA gera um texto curto no idioma NATIVO do usuário (baseado nas
// frases que ele já estudou de verdade) e ele traduz falando ou digitando
// pro idioma que está aprendendo. Estrutura espelha DailyQuestionOpenAI.php
// (mesmo padrão de limite diário, geração baseada em vocabulário do aluno,
// avaliação por IA tolerante a paráfrase) - ver esse arquivo pra contexto
// de decisões já testadas e validadas (ex: exceção de números/nomes
// próprios na checagem de idioma).
class TraducaoReversaOpenAI
{
    const LIMITE_DIARIO_PREMIUM = 5;
    const LIMITE_DIARIO_LIMITADO = 2;
    const MAX_TENTATIVAS_POR_TEXTO = 3;
    const MAX_TENTATIVAS_GERACAO = 5;

    // Mesma lógica de FraseDoDia/DailyQuestionOpenAI: CJK é muito mais denso
    // em significado por caractere, então usa um teto bem menor pra manter
    // a complexidade equivalente.
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

    private static function limiteTraducaoPara(string $idiomaNome): int
    {
        return (int) round(self::limiteCaracteresPara($idiomaNome) * 1.3);
    }

    public static function contarHoje(PDO $pdo, int $user_id): int
    {
        $sql = "SELECT COUNT(*) as total FROM traducao_reversa_ia
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
                return ["success" => false, "limite_atingido" => true, "message" => "Você já traduziu " . self::LIMITE_DIARIO_PREMIUM . " textos hoje. Volte amanhã!"];
            }
            return null;
        }

        if ($plano === 3) {
            if (self::contarHoje($pdo, $user_id) >= self::LIMITE_DIARIO_LIMITADO) {
                return ["success" => false, "limite_atingido" => true, "message" => "Você já traduziu " . self::LIMITE_DIARIO_LIMITADO . " texto" . (self::LIMITE_DIARIO_LIMITADO > 1 ? "s" : "") . " hoje. Volte amanhã ou vire premium para mais traduções por dia."];
            }
            return null;
        }

        return ["success" => false, "premium_necessario" => true, "message" => "Tradução Reversa por IA é um recurso exclusivo do plano Premium."];
    }

    private static function getPendente(PDO $pdo, int $user_id): ?array
    {
        $sql = "SELECT id, texto_nativo, texto_traduzido_gabarito, DATE(data_criacao) = CURDATE() AS eh_de_hoje
                FROM traducao_reversa_ia
                WHERE user_id = :user_id AND status_id = 0
                ORDER BY id DESC LIMIT 1";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([':user_id' => $user_id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    // Mesmo gate de Perguntas/Frase do Dia: só libera pra quem já estudou
    // pelo menos algumas frases de verdade (id_treino>=2 alguma vez,
    // cobrindo o histórico pra não travar em caso de dessincronia antiga -
    // ver comentário equivalente em DailyQuestionOpenAI.php).
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

    public static function getNivelNome(PDO $pdo, int $user_id): string
    {
        $stmt = $pdo->prepare("SELECT nivel FROM usuarios WHERE id = :id");
        $stmt->execute([':id' => $user_id]);
        $nivel = $stmt->fetch(PDO::FETCH_ASSOC)['nivel'] ?? null;

        return Nivel::nomeParaPrompt($nivel !== null ? (int) $nivel : null);
    }

    // $phrases já vem filtrado pelo controller com f.id_treino >= 2
    // OBRIGATÓRIO (sem fallback relaxado, diferente de Perguntas/Frase do
    // Dia) - pedido explícito do usuário: o conteúdo tem que vir de frases
    // que ele já estudou de verdade, sem exceção.
    public static function obterTexto(PDO $pdo, OpenAiChat $chat, int $user_id, array $phrases, string $idiomaNativoNome, string $idiomaAprendendoNome, ?string $nivelNome = null): array
    {
        $nivelNome = $nivelNome ?? Nivel::nomeParaPrompt(null);
        $pendente = self::getPendente($pdo, $user_id);

        if ($pendente && !empty($pendente['eh_de_hoje'])) {
            return [
                "success" => true,
                "id" => (int) $pendente['id'],
                "texto" => $pendente['texto_nativo'],
            ];
        }

        // Pendência abandonada de um dia anterior (usuário gerou o texto e
        // nunca respondeu nem pulou) - descarta e gera um novo em vez de
        // reaproveitar. Sem esse filtro, um texto de dias atrás "ressuscita"
        // como se fosse o de hoje, e como a data de criação dele continua
        // antiga, respondê-lo não conta pro contador diário (mesmo bug já
        // corrigido em DailyQuestionOpenAI.php, replicado aqui por engano ao
        // copiar a estrutura).
        if ($pendente) {
            $pdo->prepare("DELETE FROM traducao_reversa_ia WHERE id = :id")->execute([':id' => $pendente['id']]);
        }

        if (self::contarFrasesEstudadas($pdo, $user_id) < 3) {
            return ["success" => false, "conteudo_insuficiente" => true, "message" => "Treine pelo menos 3 frases nos flashcards para desbloquear a Tradução Reversa com IA."];
        }

        $phrases = array_filter($phrases, fn($p) => str_word_count($p) >= 3);
        $phrases = array_values($phrases);

        if (count($phrases) < 3) {
            return ["success" => false, "conteudo_insuficiente" => true, "frases_curtas" => true, "message" => "Treine frases mais completas nos flashcards (não só palavras soltas) para gerar textos melhores."];
        }

        shuffle($phrases);

        $maxTexto = self::limiteCaracteresPara($idiomaNativoNome, $nivelNome);
        $limiteGabarito = self::limiteTraducaoPara($idiomaAprendendoNome);
        $phrases = array_map(fn($p) => mb_substr($p, 0, $maxTexto), $phrases);
        $phrasesText = implode("\n", $phrases);

        // Mesma disciplina de "coerência antes de quantidade" já validada em
        // DailyQuestionOpenAI::obterPergunta - testado de novo aqui direto
        // na API (frases reais + frases propositalmente desconexas) antes
        // de aceitar o prompt.
        //
        // Efeito colateral descoberto depois (usuário reportou só ver
        // conteúdo de uma categoria específica): "é preferível usar 1 frase
        // só" fazia a IA ancorar quase sempre num tema só por geração, mesmo
        // com frases de várias categorias disponíveis - testado com frases
        // reais de 5 categorias, 8 gerações: só 3 categorias apareceram.
        // Trocado o padrão pra "combine quando for plausível" (testado de
        // novo: mais categorias representadas), mantendo as mesmas travas
        // de coerência (lugar/tempo/interlocutor) como exceção, não regra.
        $systemPrompt = "Você é um professor de idiomas. Crie UM pequeno texto (1 a 2 frases), em {$idiomaNativoNome}, "
            . "pra um aluno de nível {$nivelNome} praticar tradução do {$idiomaNativoNome} para {$idiomaAprendendoNome}. "
            . "Ajuste o vocabulário e a complexidade gramatical do texto pro nível do aluno - iniciante pede frases "
            . "curtas e diretas, com estruturas simples (evite orações subordinadas ou múltiplas ideias na mesma "
            . "frase); intermediário pode incluir conectivos e tempos verbais variados; avançado pode usar "
            . "vocabulário mais rico e estruturas mais elaboradas. Monte o texto usando trechos das frases "
            . "fornecidas pelo aluno a seguir - elas são a matéria-prima principal do texto, não apenas uma "
            . "referência solta de vocabulário. As frases vêm de temas variados do dia a dia do aluno - SEMPRE "
            . "QUE FOR PLAUSÍVEL, combine trechos de frases de temas DIFERENTES dentro de uma mesma cena real (ex: "
            . "uma situação sobre compras pode mencionar algo de saúde, tipo comprar remédio numa farmácia) - "
            . "varie as fontes em vez de girar "
            . "sempre em torno de uma frase só, desde que a combinação faça sentido como uma cena real e coerente. "
            . "Só use 1 frase só (ou nenhuma combinação, criando algo simples inspirado em UMA delas) quando as "
            . "frases disponíveis realmente não combinarem de forma natural - coerência sempre vem antes de "
            . "quantidade de trechos, mas dentro do que for coerente, misturar mais de uma fonte é melhor que usar "
            . "sempre só uma. O conteúdo (temas, ações, situações) tem que vir claramente do que está nessas "
            . "frases, não de uma ideia nova inventada do zero. LIMITE RÍGIDO: NUNCA use trechos de mais de 2 "
            . "frases-fonte diferentes no mesmo texto, mesmo que o texto tenha 2 orações - \"1 a 2 frases\" é sobre "
            . "o TEXTO final, não uma licença pra encaixar 1 frase-fonte por oração. Se, entre as frases "
            . "disponíveis, nenhum par de 2 combinar numa cena só, use apenas 1 frase-fonte (nunca 3 ou mais). "
            . "Encadear 3+ frases desconexas separadas por ';' ou '.', cada uma sobre uma pessoa/situação "
            . "diferente, só pra aproveitar mais vocabulário, vira uma lista de fatos soltos sem lógica entre si "
            . "(ex: \"Você é novo aqui? Vou à academia toda manhã. Ela disse que compraria pão mas esqueceu.\" - "
            . "três afirmações sobre pessoas/situações diferentes, sem nenhuma relação real entre si, coladas só "
            . "porque estavam na lista), NÃO um texto natural - isso é ERRADO mesmo que cada frase individual "
            . "esteja gramaticalmente correta. Preste atenção "
            . "especial a: (1) quem fala e sobre "
            . "quem/com quem se fala; (2) referências de lugar e tempo - NUNCA misture uma frase que fala de estar EM algum "
            . "lugar com outra que fala de IR pra outro lugar diferente, ou uma frase sobre HOJE com outra sobre "
            . "um dia diferente, só porque compartilham uma palavra parecida. O texto final tem que soar como se "
            . "fosse dito por UM narrador, sobre UMA cena real e específica só. Se um trecho só encaixar ajustando "
            . "pessoa gramatical, tempo verbal ou outro detalhe gramatical pra combinar com o resto, ajuste; se "
            . "não der pra encaixar sem parecer forçado, não use esse trecho. Depois de criar o texto em "
            . "{$idiomaNativoNome}, produza também a tradução de referência dele em {$idiomaAprendendoNome} - essa "
            . "tradução deve ser natural e idiomática (como um nativo do idioma-alvo diria), não literal palavra "
            . "por palavra. O campo \"texto\" tem que conter SÓ o texto final em si, ESCRITO INTEIRAMENTE EM "
            . "{$idiomaNativoNome} do início ao fim (nunca troque de idioma no meio nem responda em outro idioma) "
            . "- nunca inclua comentários, explicações ou qualquer menção de como/por que você combinou as frases "
            . "do aluno. Máximo {$maxTexto} caracteres no texto original. Não use aspas. "
            . 'Responda em JSON: {"texto": "...", "traducao_gabarito": "..."}';

        $texto = null;
        $gabarito = null;

        for ($tentativa = 1; $tentativa <= self::MAX_TENTATIVAS_GERACAO; $tentativa++) {
            $resultado = $chat->completar([
                ['role' => 'system', 'content' => $systemPrompt],
                ['role' => 'user', 'content' => "Frases:\n" . $phrasesText],
            ], true, 400);

            if ($resultado['erro']) {
                return ["success" => false, "message" => "Não foi possível gerar o texto: " . $resultado['mensagem']];
            }

            $decodificado = json_decode($resultado['texto'], true);

            if (!is_array($decodificado) || empty($decodificado['texto'])) {
                return ["success" => false, "message" => "Resposta inválida da IA."];
            }

            $textoCandidato = trim((string) $decodificado['texto'], "\" \n\r\t");
            $gabaritoCandidato = trim((string) ($decodificado['traducao_gabarito'] ?? ''), "\" \n\r\t");

            if (mb_strlen($textoCandidato) <= $maxTexto) {
                $texto = $textoCandidato;
                $gabarito = self::truncarPreservandoPalavras($gabaritoCandidato, $limiteGabarito);
                break;
            }

            // Guarda a última tentativa como fallback (texto completo, mesmo
            // que um pouco acima do limite) em vez de truncar cru no meio.
            $texto = $textoCandidato;
            $gabarito = $gabaritoCandidato;
        }

        $stmt = $pdo->prepare("INSERT INTO traducao_reversa_ia (user_id, status_id, texto_nativo, texto_traduzido_gabarito) VALUES (:user_id, 0, :texto, :gabarito)");
        $stmt->execute([':user_id' => $user_id, ':texto' => $texto, ':gabarito' => $gabarito]);

        return [
            "success" => true,
            "id" => (int) $pdo->lastInsertId(),
            "texto" => $texto,
        ];
    }

    // mb_substr corta no meio de uma palavra - corta no último espaço antes
    // do limite (ou pontuação, pra CJK). Mesma lógica de
    // DailyQuestionOpenAI::truncarPreservandoPalavras/FraseDoDia.
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

    private static function registrarTentativaSemNota(PDO $pdo, int $id, int $tentativaAtual, string $resposta): bool
    {
        $esgotou = $tentativaAtual >= self::MAX_TENTATIVAS_POR_TEXTO;

        $stmt = $pdo->prepare("
            UPDATE traducao_reversa_ia
            SET status_id = :status_id, tentativas = :tentativas, resposta = :resposta
            WHERE id = :id
        ");
        $stmt->execute([
            ':status_id' => $esgotou ? 1 : 0,
            ':tentativas' => $tentativaAtual,
            ':resposta' => $resposta,
            ':id' => $id,
        ]);

        return $esgotou;
    }

    public static function responder(
        PDO $pdo,
        OpenAiChat $chat,
        OpenAiTranscribe $transcribe,
        int $user_id,
        int $id,
        string $caminhoAudio,
        string $mimeType,
        string $idiomaNativoNome,
        string $idiomaAprendendoNome
    ): array {
        $row = self::buscarPendente($pdo, $id, $user_id);

        if (!$row) {
            return ["success" => false, "message" => "Texto não encontrado ou já respondido."];
        }

        $tentativaAtual = (int) $row['tentativas'] + 1;

        $nomeArquivo = "audio." . self::extensaoParaMime($mimeType);
        $transcricaoResult = $transcribe->transcrever($caminhoAudio, $nomeArquivo, $mimeType);

        if ($transcricaoResult['erro']) {
            return ["success" => false, "message" => "Não foi possível transcrever o áudio: " . $transcricaoResult['mensagem']];
        }

        $resposta = $transcricaoResult['texto'];

        if (trim($resposta) === '') {
            $esgotou = self::registrarTentativaSemNota($pdo, $id, $tentativaAtual, $resposta);

            return [
                "success" => false,
                "audio_vazio" => true,
                "pode_tentar_novamente" => !$esgotou,
                "message" => $esgotou
                    ? "Não conseguimos identificar sua fala nas últimas tentativas. Vamos pro próximo texto."
                    : "Não conseguimos identificar sua fala no áudio. Tente gravar de novo, falando mais perto do microfone."
            ];
        }

        return self::avaliarESalvarResposta($pdo, $chat, $id, $row, $tentativaAtual, $resposta, $idiomaNativoNome, $idiomaAprendendoNome, true);
    }

    public static function responderTexto(
        PDO $pdo,
        OpenAiChat $chat,
        int $user_id,
        int $id,
        string $respostaTexto,
        string $idiomaNativoNome,
        string $idiomaAprendendoNome
    ): array {
        $row = self::buscarPendente($pdo, $id, $user_id);

        if (!$row) {
            return ["success" => false, "message" => "Texto não encontrado ou já respondido."];
        }

        $respostaTexto = trim($respostaTexto);

        if ($respostaTexto === '') {
            return ["success" => false, "message" => "Digite uma tradução."];
        }

        $tentativaAtual = (int) $row['tentativas'] + 1;

        return self::avaliarESalvarResposta($pdo, $chat, $id, $row, $tentativaAtual, $respostaTexto, $idiomaNativoNome, $idiomaAprendendoNome, false);
    }

    private static function buscarPendente(PDO $pdo, int $id, int $user_id): ?array
    {
        $stmt = $pdo->prepare("SELECT texto_nativo, texto_traduzido_gabarito, tentativas FROM traducao_reversa_ia WHERE id = :id AND user_id = :user_id AND status_id = 0");
        $stmt->execute([':id' => $id, ':user_id' => $user_id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    private static function avaliarESalvarResposta(
        PDO $pdo,
        OpenAiChat $chat,
        int $id,
        array $row,
        int $tentativaAtual,
        string $resposta,
        string $idiomaNativoNome,
        string $idiomaAprendendoNome,
        bool $ehAudio
    ): array {
        $textoOriginal = $row['texto_nativo'];
        $gabarito = $row['texto_traduzido_gabarito'];

        if (verificarConteudoImproprio($resposta)) {
            $esgotou = self::registrarTentativaSemNota($pdo, $id, $tentativaAtual, $resposta);

            return [
                "success" => false,
                "pode_tentar_novamente" => !$esgotou,
                "message" => "A resposta contém conteúdo impróprio."
            ];
        }

        if ($ehAudio) {
            $tipoResposta = "ORAL (transcrita automaticamente a partir do áudio)";
            $focoAvaliacao = "Avalie principalmente se o sentido foi preservado e a fluência/gramática da fala. Como "
                . "o texto vem de transcrição automática, palavras estranhas ou fora de contexto podem indicar "
                . "erro de pronúncia do aluno (e não erro de digitação) - quando notar isso, mencione no feedback "
                . "como possível problema de pronúncia daquela palavra, sem penalizar tanto quanto um erro "
                . "gramatical real.";
        } else {
            $tipoResposta = "ESCRITA (digitada pelo próprio aluno)";
            $focoAvaliacao = "Avalie com rigor a escrita: ortografia, gramática, concordância, pontuação e uso "
                . "correto das palavras. Como o aluno digitou a resposta, não há motivo pra tolerância com erros - "
                . "aponte no feedback os erros de escrita mais importantes de forma específica.";
        }

        // Testado direto na API (com gpt-5-mini): "sempre levo X" traduzido
        // como "nunca viajo sem X" (negação dupla logicamente equivalente) é
        // uma paráfrase válida, mas precisa dizer isso explicitamente no
        // prompt, senão o modelo marca como mudança de sentido. gpt-5-nano
        // continuava errando esse caso mesmo com a instrução explícita -
        // por isso essa avaliação usa gpt-5-mini (diferente de Perguntas,
        // que usa nano pra corrigir) - testado e confirmado que mini acerta
        // consistentemente onde nano falhava.
        $systemPrompt = "Você é um professor de idiomas avaliando a tradução {$tipoResposta} de um aluno. Vai "
            . "receber um texto original em {$idiomaNativoNome}, a tradução de referência em "
            . "{$idiomaAprendendoNome} (só como apoio seu, não mostre ela pro aluno) e a tradução que o aluno "
            . "produziu. REQUISITO OBRIGATÓRIO: a tradução do aluno precisa estar em {$idiomaAprendendoNome} - se "
            . "tiver palavras de outro idioma (verbos, artigos, conectivos, substantivos etc, incluindo do idioma "
            . "nativo do aluno), ela é automaticamente incorreta (nota baixa, no máximo 3, correto=false), mesmo "
            . "que o sentido esteja certo, e o feedback deve deixar claro que a tradução precisa ser em "
            . "{$idiomaAprendendoNome}. EXCEÇÃO: números, nomes próprios e datas sozinhos não têm idioma - não "
            . "penalize por isso. Se estiver no idioma certo, avalie se o SENTIDO do texto original foi "
            . "preservado - não precisa ser tradução literal palavra por palavra: paráfrases, sinônimos e "
            . "construções gramaticais diferentes (ex: reescrever uma afirmação como negação dupla logicamente "
            . "equivalente, tipo \"sempre levo X\" virar \"nunca viajo sem X\") contam como corretos, desde que o "
            . "significado final seja o mesmo. Só considere o sentido errado se a tradução realmente disser outra "
            . "coisa (trocar a ação, o objeto, quem faz o quê, inverter um sentimento positivo/negativo etc), não "
            . "só por usar palavras ou estrutura gramatical diferentes da tradução de referência. Avalie também a "
            . "qualidade gramatical da tradução em {$idiomaAprendendoNome}. {$focoAvaliacao} Dê nota de 0 a 10, se "
            . "está correto, e explique os principais erros em {$idiomaNativoNome} (máx 200 caracteres). "
            . 'Responda em JSON: {"nota": 0-10, "correto": true ou false, "feedback": "..."}';

        $correcaoResult = $chat->completar([
            ['role' => 'system', 'content' => $systemPrompt],
            ['role' => 'user', 'content' => "Texto original: {$textoOriginal}\nTradução de referência: {$gabarito}\nTradução do aluno: {$resposta}"],
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

        $passou = $nota >= 5 && $correto;
        $esgotouTentativas = $tentativaAtual >= self::MAX_TENTATIVAS_POR_TEXTO;
        $statusFinal = ($passou || $esgotouTentativas) ? 1 : 0;

        $stmt = $pdo->prepare("
            UPDATE traducao_reversa_ia
            SET status_id = :status_id, tentativas = :tentativas, resposta = :resposta, nota = :nota, feedback = :feedback
            WHERE id = :id
        ");
        $stmt->execute([
            ':status_id' => $statusFinal,
            ':tentativas' => $tentativaAtual,
            ':resposta' => $resposta,
            ':nota' => $nota,
            ':feedback' => $feedback,
            ':id' => $id,
        ]);

        return [
            "success" => true,
            "resposta" => $resposta,
            "nota" => $nota,
            "correto" => $correto,
            "feedback" => $feedback,
            "traducao_gabarito" => $gabarito,
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
            'audio/wav' => 'wav',
            'audio/x-wav' => 'wav',
        ];

        return $mapa[$mimeType] ?? 'webm';
    }
}
