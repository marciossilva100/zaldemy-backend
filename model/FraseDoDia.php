<?php

class FraseDoDia
{
    // Premium: 1 frase nova por dia. Limitado: 1 vitalícia (amostra grátis,
    // mesmo padrão do audio_ia_uso). Free: bloqueado. Em ambos os planos, a
    // MESMA frase pode ser respondida até MAX_TENTATIVAS_POR_FRASE vezes
    // antes de contar pro limite (ver responder()) - só esgota de verdade
    // (e passa a valer pro histórico/limite) depois da última tentativa.
    const LIMITE_DIARIO_PREMIUM = 1;
    const LIMITE_VITALICIO_LIMITADO = 1;
    const MAX_TENTATIVAS_POR_FRASE = 2;
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

    // Trecho mínimo de 1 palavra - uma frase do aluno pode ser uma única
    // palavra (ex: "Trip"), e essa palavra sozinha ainda é vocabulário real
    // dele; excluir tamanho 1 do destaque deixava esse tipo de frase
    // estruturalmente impossível de ser reconhecida, não importa o que a IA
    // escrevesse. trechoValidoPorPalavra já filtra o tamanho 1 pela regra de
    // "palavra de conteúdo" (TAMANHO_MIN_PALAVRA_CONTEUDO) - só uma palavra
    // isolada longa o bastante conta, palavras gramaticais curtas ("it",
    // "on") continuam de fora. Bigramas têm regra mais rígida (ver
    // trechoValidoPorPalavra): só valem se AMBAS as palavras forem "de
    // conteúdo", senão pares puramente gramaticais ("and I", "on the")
    // bateriam com praticamente qualquer frase por coincidência - mas um
    // bigrama de duas palavras de conteúdo de verdade ("stay calm", "make
    // sense") é um reaproveitamento genuíno. Trechos de 3+ palavras já
    // bastam ter uma palavra de conteúdo.
    const MIN_TAMANHO_TRECHO_PALAVRAS = 1;
    const TAMANHO_MIN_PALAVRA_CONTEUDO = 4;

    // Chinês, japonês e coreano usam caracteres muito mais densos em
    // significado que os idiomas alfabéticos - 200-220 caracteres nesses
    // idiomas equivaleria a um texto MUITO mais longo/complexo (e o cartão
    // do flashcard estouraria). Usa uma faixa bem menor pra manter a frase
    // com complexidade equivalente. Mesma lógica em DailyQuestionOpenAI.
    // Iniciante recebe uma faixa bem menor - a faixa padrão (200-220) exige
    // pelo menos duas orações ligadas por conectivo (ver systemPrompt), o
    // que é complexidade demais pra quem ainda tá começando, mesmo com
    // vocabulário simples. Avançado recebe uma faixa um pouco maior - testado
    // na prática, com a mesma faixa do intermediário a IA gerava frases quase
    // idênticas em tamanho e complexidade pros dois níveis; um pouco mais de
    // espaço ajuda a instrução de vocabulário/estrutura mais elaborada (ver
    // systemPrompt) a se manifestar de verdade, não só na teoria do prompt.
    private static function faixaCaracteresPara(string $idiomaNome, ?string $nivelNome = null): array
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
        $ehAvancado = $nivelNome !== null && mb_stripos($nivelNome, 'avan') !== false;

        if ($ehIniciante) {
            return $ehCjk ? [30, 45] : [90, 110];
        }

        if ($ehAvancado) {
            return $ehCjk ? [90, 110] : [230, 260];
        }

        return $ehCjk ? [60, 90] : [200, 220];
    }

    // Tradução tende a ficar um pouco mais longa que o original (idiomas
    // alfabéticos) ou seguir a mesma densidade (CJK) - dá uma margem
    // proporcional ao máximo já calculado pro idioma, em vez de um número
    // fixo que corta traduções em idiomas CJK bem antes da hora ou trunca
    // sem folga nenhuma nos idiomas alfabéticos.
    private static function limiteTraducaoPara(string $idiomaNome, ?string $nivelNome = null): int
    {
        [, $max] = self::faixaCaracteresPara($idiomaNome, $nivelNome);

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

    // O limitado pode continuar vendo/respondendo a frase pendente no mesmo
    // dia em que ela foi gerada (a amostra vitalícia só é "gasta" de verdade
    // quando ele responde), mas se voltar em outro dia sem ter respondido,
    // a amostra é considerada perdida - senão a amostra grátis ficaria
    // disponível pra sempre, bastando nunca responder.
    private static function temPendenteExpirada(PDO $pdo, int $user_id): bool
    {
        $sql = "SELECT COUNT(*) as total FROM frase_dia_ia
                WHERE user_id = :user_id AND status_id = 0
                  AND DATE(data_criacao) < CURDATE()";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([':user_id' => $user_id]);
        return (int) $stmt->fetch(PDO::FETCH_ASSOC)['total'] > 0;
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
            if (self::contarTotal($pdo, $user_id) >= self::LIMITE_VITALICIO_LIMITADO || self::temPendenteExpirada($pdo, $user_id)) {
                return ["success" => false, "limite_atingido" => true, "message" => "Você já usou sua amostra grátis da frase do dia. Vire premium para ter acesso diário."];
            }
            return null;
        }

        return ["success" => false, "premium_necessario" => true, "message" => "A frase do dia é um recurso exclusivo do plano Premium."];
    }

    // Só considera pendente de HOJE - uma pendência esquecida de um dia
    // anterior (ex: usuário nunca voltou pra usar a 2ª tentativa) não pode
    // ficar bloqueando a geração da frase nova do premium pra sempre. Pro
    // limitado isso nunca chega a importar aqui: uma pendência de dia
    // anterior já é barrada antes, em verificarAcesso() (temPendenteExpirada).
    private static function getPendente(PDO $pdo, int $user_id): ?array
    {
        $sql = "SELECT id, frase, frase_traducao FROM frase_dia_ia
                WHERE user_id = :user_id AND status_id = 0
                  AND DATE(data_criacao) = CURDATE()
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
            $todasFrases = self::getTodasFrasesElegiveis($pdo, $user_id);

            return [
                "success" => true,
                "id" => (int) $pendente['id'],
                "frase" => $pendente['frase'],
                "traducao" => $pendente['frase_traducao'],
                "frase_destacada" => self::destacarPalavrasConhecidas($pendente['frase'], $todasFrases, $idiomaNome),
            ];
        }

        // Pendência órfã (gerada antes da coluna frase_traducao existir) - descarta
        // e gera uma nova em vez de mostrar um flashcard sem verso.
        if ($pendente) {
            $pdo->prepare("DELETE FROM frase_dia_ia WHERE id = :id")->execute([':id' => $pendente['id']]);
        }

        if (self::contarFrasesEstudadas($pdo, $user_id) < 3) {
            return ["success" => false, "conteudo_insuficiente" => true, "message" => "Treine pelo menos 3 frases nos flashcards para desbloquear a frase do dia."];
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

        [$min, $max] = self::faixaCaracteresPara($idiomaNome, $nivelNome);
        $limiteTraducao = self::limiteTraducaoPara($idiomaNativoNome, $nivelNome);
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
            // Testado direto na API: a versão anterior pedia pra usar o
            // MÁXIMO de trechos possível, e mesmo com os avisos de coerência
            // logo depois, a IA continuava colando frases de temas de vida
            // totalmente diferentes (ex: "nasci no Brasil" + "amo
            // computadores" + "vou pra academia toda manhã" + "preciso de
            // motivação" numa lista só de fatos soltos ligados por "e"/
            // "então" - reportado pelo usuário como falta de sentido/coesão).
            // Trocado o incentivo de "usar o máximo" por "coerência antes de
            // quantidade" (mesmo princípio já corrigido em
            // DailyQuestionOpenAI.php) - testado de novo com o mesmo lote de
            // frases desconexas, sem repetir esse tipo de mistura.
            //
            // Efeito colateral descoberto depois (usuário reportou só ver
            // conteúdo de uma categoria específica): "prefira usar só 1
            // frase como base" fazia a IA ancorar quase sempre num tema só
            // por geração. Trocado pra "combine quando for plausível" - mas
            // essa mudança ficou CONTRADITÓRIA com a instrução de tamanho
            // (ver $systemPrompt abaixo), que dizia "nunca acrescente um
            // segundo assunto sem relação" bem ao lado de "sempre que for
            // plausível, combine temas diferentes" - duas instruções
            // puxando o modelo pra direções opostas na mesma resposta.
            // Resultado: o EXATO bug original voltou (usuário reportou de
            // novo o mesmo exemplo "nasci no Brasil" + "amo computadores" +
            // "entender como funciona" + "motivação" + "inspirar outros").
            // Reconciliado com um teto numérico explícito (mesma técnica que
            // funcionou em TraducaoReversaOpenAI::obterTexto): no máximo 2
            // frases-fonte por frase, nunca 3+, e a instrução de tamanho
            // (abaixo) e de mistura de categoria (aqui) agora dizem a MESMA
            // coisa em vez de coisas opostas. Testado com o lote exato do
            // bug reportado (17 gerações, ver commit) sem reproduzir mais o
            // encadeamento de 3+ fatos soltos.
            : "Monte a frase usando trechos das frases que o aluno já estuda a seguir - elas são a matéria-prima "
                . "principal da frase, não apenas uma referência solta de vocabulário. LIMITE RÍGIDO: NUNCA use "
                . "trechos de mais de 2 frases-fonte diferentes na mesma frase final - mesmo que o aluno tenha "
                . "várias frases de temas variados disponíveis, combine no máximo 2 delas, e só quando as duas "
                . "realmente formarem uma cena real única (ex: uma situação sobre compras pode mencionar algo de "
                . "saúde, tipo comprar remédio numa farmácia, antes de uma viagem). ESSE LIMITE DE 2 VALE TAMBÉM "
                . "QUANDO AS FRASES SÃO DO MESMO TEMA/CATEGORIA - frases sobre o mesmo assunto (ex: várias sobre "
                . "cozinhar) encaixam fácil umas nas outras justamente por serem parecidas, o que é uma armadilha: "
                . "\"I slice vegetables thin for the salad... I want to taste the dish to adjust the seasoning... "
                . "before I clean the counter and organize the kitchen\" está ERRADO mesmo sendo tudo sobre "
                . "culinária e mesmo soando fluente - são 3 frases-fonte diferentes encadeadas, não 2. Escolha só "
                . "as 2 melhores (ou 1) desse mesmo grupo temático, nunca 3 ou mais, mesmo que combinem bem entre "
                . "si. Se nenhum par de 2 frases "
                . "combinar bem, use só 1 frase-fonte como base (nunca 0, nunca 3+) - coerência sempre vem antes de "
                . "quantidade de trechos ou de variar categorias. O conteúdo (tema, ação, situação) tem que vir "
                . "claramente do que está nessas frases, não de uma ideia nova inventada do zero. As "
                . "frases do aluno vêm de conversas soltas e diferentes entre si - "
                . "muitas só fazem sentido dentro do contexto original delas (ex: uma resposta a alguém específico, "
                . "uma instrução dirigida a outra pessoa, um assunto que não tem nada a ver com outra frase da "
                . "lista). NUNCA encadeie 3 ou mais frases-fonte sobre assuntos de vida diferentes (ex: onde "
                . "nasceu + hobby + academia + objetivo de aprendizado + motivação + sonho futuro) numa lista de "
                . "fatos colados por 'e'/'porque'/'então' - isso é ERRADO mesmo que cada fato individual esteja "
                . "gramaticalmente correto e mesmo que ajude a bater o tamanho pedido; vira uma lista de fatos "
                . "soltos sem lógica entre si, não uma frase natural. CUIDADO ESPECIAL: quando as frases do aluno "
                . "forem afirmações soltas sobre a vida/identidade dele (onde nasceu, do que gosta, no que "
                . "trabalha, o que deseja), a tentação natural é escrever uma frase de AUTOAPRESENTAÇÃO "
                . "encadeando várias delas com 'e' (ex: \"Nasci no Brasil e amo trabalhar com computadores, e "
                . "estou tentando entender como funciona porque preciso de motivação para continuar e espero "
                . "inspirar outras pessoas.\") - isso é o MESMO erro de listar fatos soltos, só disfarçado de "
                . "gênero de texto válido. Escolha só 1 (ou, seguindo o limite acima, no máximo 2) desses fatos e "
                . "elabore uma cena curta e específica em torno dele, em vez de listar quem a pessoa é. A frase "
                . "final tem que soar como se fosse "
                . "dita por UM narrador, sobre UMA única cena que ele quer contar. Se um trecho só encaixar "
                . "ajustando pessoa gramatical ou tempo verbal pra combinar com o resto, ajuste; se não der pra "
                . "encaixar sem parecer forçado, não use esse trecho.";

        $systemPrompt = "Você é um professor de idiomas escrevendo uma frase de exemplo em {$idiomaNome} pra um aluno "
            . "de nível {$nivelNome}. Ajuste o vocabulário e a complexidade gramatical da frase pro nível dele - "
            . "iniciante pede estruturas simples e vocabulário básico do dia a dia; intermediário pode incluir "
            . "conectivos e tempos verbais variados; avançado precisa se destacar claramente do intermediário: use "
            . "vocabulário menos comum (não óbvio, nada de palavras básicas de novo), expressões idiomáticas ou "
            . "phrasal verbs quando fizer sentido, oração subordinada (relativa, condicional ou concessiva) e "
            . "variação de tempo/modo verbal - não apenas mais uma oração colada com 'e'/'porque'. "
            . "REQUISITO MAIS IMPORTANTE: a frase precisa ter entre {$min} e {$max} caracteres, contando espaços e "
            . "pontuação - conte os caracteres mentalmente antes de responder e, se estiver fora dessa faixa, "
            . "reescreva até acertar. Frases curtas de uma oração só (tipo 'I like coffee.') SEMPRE ficam curtas "
            . "demais. IMPORTANTE: pra chegar no tamanho certo, a PRIMEIRA opção é sempre elaborar com MAIS "
            . "DETALHE sobre a MESMA cena/ideia central (quando, onde, por quê, como a pessoa se sente, o que ela "
            . "pretende fazer a respeito) - essa é a forma mais segura de crescer a frase sem perder coerência. "
            . "Só recorra a combinar com uma 2ª frase-fonte (ver regra de limite mais abaixo) se elaborar detalhe "
            . "não bastar pra atingir o tamanho, e nunca vá além dessas 2 frases-fonte só pra ganhar caracteres. "
            . "Uma frase mais longa mas sobre UMA cena só é sempre melhor que uma frase no tamanho certo que "
            . "mistura vários assuntos de vida sem relação. A frase deve ser natural e do dia a dia, adequada pra um aluno ler em "
            . "voz alta como exercício de pronúncia, e tem que ser inteiramente uma afirmação (declarativa) do "
            . "início ao fim - nenhuma oração dentro da frase pode ser uma pergunta, nem mesmo uma pergunta "
            . "retórica ou tag question (tipo 'certo?', 'não é?'); o texto final não pode conter nenhum ponto de "
            . "interrogação. "
            . "{$instrucaoVocabulario} Gramaticalmente correta. Não repita estruturas óbvias como 'My name is'. "
            . "O campo \"frase\" tem que conter SÓ a frase final em si, ESCRITA INTEIRAMENTE EM {$idiomaNome} do "
            . "início ao fim (nunca troque de idioma no meio nem responda em outro idioma) - nunca inclua "
            . "comentários, explicações ou qualquer menção de como/por que você combinou as frases do aluno. "
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

            // Se saiu curta ou longa demais, tenta ajustar a MESMA frase (tarefa
            // mais fácil pro modelo acertar do que gerar já com o tamanho certo
            // do zero) antes de desistir e regenerar tudo de novo.
            if (mb_strlen($fraseCandidata) < $min) {
                $expandida = self::expandirFrase($chat, $fraseCandidata, $idiomaNome, $idiomaNativoNome, $min, $max);
                if ($expandida !== null) {
                    $fraseCandidata = $expandida['frase'];
                    $traducaoCandidata = $expandida['traducao'];
                }
            } elseif (mb_strlen($fraseCandidata) > $max) {
                $encurtada = self::encurtarFrase($chat, $fraseCandidata, $idiomaNome, $idiomaNativoNome, $min, $max);
                if ($encurtada !== null) {
                    $fraseCandidata = $encurtada['frase'];
                    $traducaoCandidata = $encurtada['traducao'];
                }
            }

            // Além do tamanho, a frase nunca pode virar pergunta (nem retórica/tag
            // question) no meio - o prompt já pede isso, mas confere aqui também
            // em vez de confiar só na instrução.
            $temInterrogacao = mb_strpos($fraseCandidata, '?') !== false;

            if (mb_strlen($fraseCandidata) >= $min && mb_strlen($fraseCandidata) <= $max && !$temInterrogacao) {
                $frase = $fraseCandidata;
                $traducao = self::truncarPreservandoPalavras($traducaoCandidata, $limiteTraducao);
                break;
            }

            // Guarda a última tentativa como fallback caso nenhuma acerte a faixa
            // exata - mantém a frase completa (mesmo que um pouco fora da faixa)
            // em vez de truncar no meio de uma oração, o que deixaria o final sem
            // sentido. A tradução também não é truncada pelo mesmo motivo.
            $frase = $fraseCandidata;
            $traducao = $traducaoCandidata;
        }

        $stmt = $pdo->prepare("INSERT INTO frase_dia_ia (user_id, frase, frase_traducao, status_id) VALUES (:user_id, :frase, :traducao, 0)");
        $stmt->execute([':user_id' => $user_id, ':frase' => $frase, ':traducao' => $traducao]);

        // Marca quais frases do pool foram de fato usadas nesta geração,
        // pra elas saírem de circulação por um tempo (ver RotacaoFrasesIA).
        RotacaoFrasesIA::registrarUsadas($pdo, $user_id, $phrasesOriginais, $frase);

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

    // A IA costuma gerar contrações com apóstrofo tipográfico (U+2019, "I’ll")
    // enquanto as frases que o aluno digitou usam o apóstrofo reto (U+0027,
    // "I'll") - sem normalizar, as duas formas tokenizam igual mas nunca batem
    // como string, quebrando o destaque de qualquer trecho com contração. Só
    // usado nas chaves de comparação, nunca no texto exibido.
    private static function normalizarApostrofo(string $texto): string
    {
        return str_replace('’', "'", $texto);
    }

    // Constrói o conjunto de todos os n-gramas (sequências contíguas de 2+
    // palavras, até MAX_TAMANHO_TRECHO) presentes nas frases do aluno, pra
    // busca O(1) por trecho candidato.
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

            for ($tam = $maxTam; $tam >= self::MIN_TAMANHO_TRECHO_PALAVRAS; $tam--) {
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
            . "A frase reescrita tem que continuar em {$idiomaNome} (nunca mude de idioma). Também gere a tradução "
            . "em {$idiomaNativoNome}. Frase original: \"{$frase}\". "
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

    // Encurta uma frase longa demais em vez de truncar o texto cru - truncar
    // corta no meio de uma oração (ex: "...and I'll stay focused to", sem
    // terminar o pensamento), o que é pior que a frase ficar um pouco fora da
    // faixa de tamanho. Pedir pra IA reescrever mais curta preserva o sentido
    // e garante que a frase termine de forma gramaticalmente completa.
    private static function encurtarFrase(OpenAiChat $chat, string $frase, string $idiomaNome, string $idiomaNativoNome, int $min, int $max): ?array
    {
        $tamanhoAtual = mb_strlen($frase);

        $prompt = "A frase a seguir, em {$idiomaNome}, tem {$tamanhoAtual} caracteres e está longa demais. Reescreva "
            . "ela removendo um detalhe ou uma oração, mantendo o sentido principal, até ficar com EXATAMENTE entre "
            . "{$min} e {$max} caracteres no total. A frase reescrita precisa terminar de forma completa (nunca "
            . "cortada no meio de uma oração) e continuar em {$idiomaNome} (nunca mude de idioma). Também gere a "
            . "tradução em {$idiomaNativoNome}. Frase original: "
            . "\"{$frase}\". "
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

        // Mesmo padrão do Perguntas (avaliarESalvarResposta): só fecha a
        // frase (status_id=1, passa a valer pro histórico/limite diário ou
        // vitalício) quando a tentativa atual esgota o total permitido -
        // antes disso, status_id continua 0 e getPendente() devolve essa
        // mesma frase de novo, deixando o usuário tentar melhorar a nota.
        $esgotouTentativas = $tentativaAtual >= self::MAX_TENTATIVAS_POR_FRASE;

        $stmt = $pdo->prepare("
            UPDATE frase_dia_ia
            SET status_id = :status_id, tentativas = :tentativas, transcricao = :transcricao, nota = :nota,
                feedback_gramatica = :fg, feedback_pronuncia = :fp, feedback_fluencia = :ff
            WHERE id = :id
        ");
        $stmt->execute([
            ':status_id' => $esgotouTentativas ? 1 : 0,
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
            "pode_tentar_novamente" => !$esgotouTentativas,
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
        $frases = self::buscarFrasesPorEstagio($pdo, $user_id, true);

        // O gate que libera o recurso (contarFrasesEstudadas) conta pelo
        // histórico (já alcançou id_treino>=2 alguma vez), mas essa busca
        // usa o estágio ATUAL da frase - se as frases regrediram todas de
        // volta pro estágio 1 depois de já terem liberado o recurso, essa
        // busca podia vir vazia mesmo com o gate liberado. Cai pra buscar
        // sem esse filtro (mantendo a priorização por estágio) em vez de
        // gerar conteúdo sem vocabulário nenhum do aluno.
        if (count($frases) < 3) {
            $frases = self::buscarFrasesPorEstagio($pdo, $user_id, false);
        }

        return $frases;
    }

    // A 1ª tentativa de corrigir isso (janela global maior, LIMIT 300) não
    // bastava: ORDER BY id_treino DESC é aplicado ANTES do LIMIT pra tabela
    // INTEIRA, não por categoria - se uma única categoria já tem, sozinha,
    // mais itens no nível mais alto (id_treino=4) do que o tamanho da
    // janela (caso real de produção: 1 categoria com 451 frases id_treino=4,
    // outras 5 categorias em id_treino=2/3 com só 1-15 frases cada), a
    // janela inteira é preenchida só por ela ANTES de qualquer categoria
    // menor ter chance de entrar - não importa quão maior a janela seja.
    // Corrigido de vez com ROW_NUMBER() OVER (PARTITION BY categoria_id...):
    // cada categoria é rankeada (por id_treino DESC, RAND()) DENTRO DE SI
    // MESMA, então o corte por rn <= N garante até N candidatos de CADA
    // categoria elegível, não da tabela inteira - nenhuma categoria consegue
    // mais "roubar o espaço" de outra na hora de montar os candidatos.
    // Testado com dados reais (6 categorias elegíveis, uma com 451 frases
    // em id_treino=4 e as outras 5 com 1-15 frases em id_treino=2/3): antes,
    // só 2 das 6 apareciam nos candidatos; depois, as 6.
    private static function buscarFrasesPorEstagio(PDO $pdo, int $user_id, bool $exigirTreinoMinimo): array
    {
        $sql = "SELECT categoria_id, texto_traduzido FROM (
                    SELECT f.categoria_id, f.texto_traduzido,
                        ROW_NUMBER() OVER (
                            PARTITION BY f.categoria_id
                            ORDER BY f.id_treino DESC, RAND()
                        ) AS rn
                    FROM frases f
                    INNER JOIN idioma_referencia ir
                        ON ir.idioma_nativo = f.idioma_nativo
                        AND ir.idioma_aprender = f.idioma_aprendendo
                        AND ir.id_user = :user_id
                    WHERE f.texto_traduzido IS NOT NULL
                    AND f.usuario_id = :user_id
                    AND TRIM(f.texto_nativo) <> ''
                    AND f.status_id > 0"
                    . ($exigirTreinoMinimo ? " AND f.id_treino >= 2" : "") . "
                ) candidatos
                WHERE rn <= " . self::MAX_FRASES_PROMPT;

        $stmt = $pdo->prepare($sql);
        $stmt->execute([':user_id' => $user_id]);

        // Mesmo filtro de DailyQuestionOpenAI/TraducaoReversaOpenAI (que já
        // tinham isso, só FraseDoDia não tinha) - sem ele, uma categoria
        // cheia de fragmentos/palavras soltas (ex: listas de vocabulário
        // tipo "*Motivation", "don't give up" sem contexto) conta como
        // "grande" no balanceamento por categoria sem oferecer matéria-prima
        // usável de verdade - a IA acaba preterindo essa categoria mesmo
        // tendo mais espaço reservado pra ela, porque não tem frase completa
        // pra aproveitar (confirmado com dados reais de produção: categoria
        // com 451 itens, 383 sem nem pontuação final, e a IA só usava ela em
        // ~20% das gerações mesmo dominando o pool numericamente).
        $linhas = array_values(array_filter(
            $stmt->fetchAll(PDO::FETCH_ASSOC),
            fn($linha) => str_word_count($linha['texto_traduzido']) >= 3
        ));

        // Afasta do pool as frases usadas como fonte nas últimas gerações
        // (dos 3 treinos de IA, ver RotacaoFrasesIA) - sem isso, frases de
        // categorias pequenas cabem quase sempre no sorteio de 50 e a IA
        // repete a mesma frase "fácil" toda vez que ela está disponível
        // (confirmado com dados reais: 1 frase em 36-40% de 50 gerações).
        // Só filtra se sobrar pelo menos 3 depois - com pouco vocabulário,
        // é melhor repetir do que gerar sem matéria-prima nenhuma.
        $excluir = array_flip(RotacaoFrasesIA::textosParaExcluir($pdo, $user_id));
        $semRecentes = array_values(array_filter(
            $linhas,
            fn($linha) => !isset($excluir[$linha['texto_traduzido']])
        ));
        if (count($semRecentes) >= 3) {
            $linhas = $semRecentes;
        }

        return self::balancearPorCategoria($linhas);
    }

    // Agrupa por categoria (cada uma já vem ordenada por prioridade de
    // treino, graças ao ORDER BY da query acima) e intercala 1 frase de
    // cada categoria por volta, até bater o limite final - garante que
    // nenhuma categoria domine o pool só por ter mais frases (ou frases
    // mais adiantadas no treino) que as outras. Mesmo algoritmo de
    // TiroCerteiro::buscarFrasesPorEstagio.
    // Round-robin puro (testado antes) dava peso IGUAL pra toda categoria,
    // não importa o tamanho - uma categoria de 5 frases valia o mesmo que
    // uma de 455. Feedback do usuário: se uma categoria tem mais frases
    // estudadas, é natural/esperado que ela contribua mais - o problema
    // era só nenhuma categoria ficar em ZERO, não o volume ser desigual.
    // Por isso: reserva um piso mínimo (MINIMO_GARANTIDO_POR_CATEGORIA) de
    // cada categoria elegível pra garantir que nenhuma some, e preenche o
    // resto por sorteio livre entre TODAS as frases restantes - aí sim a
    // categoria maior naturalmente contribui mais, proporcional ao volume
    // real dela. Categorias são embaralhadas antes de reservar o piso, e a
    // reserva só é aplicada se ainda couber no limite total - protege
    // contra o caso de muitas categorias pequenas (ex: dezenas de
    // categorias de teste) tomando o pool inteiro só na garantia mínima.
    const MINIMO_GARANTIDO_POR_CATEGORIA = 2;

    private static function balancearPorCategoria(array $linhas): array
    {
        $porCategoria = [];
        foreach ($linhas as $linha) {
            $porCategoria[$linha['categoria_id']][] = $linha['texto_traduzido'];
        }

        $idsCategorias = array_keys($porCategoria);
        shuffle($idsCategorias);

        $reservadas = [];
        $restante = [];

        foreach ($idsCategorias as $categoriaId) {
            $frasesCategoria = $porCategoria[$categoriaId];
            shuffle($frasesCategoria);
            $corte = min(count($frasesCategoria), self::MINIMO_GARANTIDO_POR_CATEGORIA);

            if (count($reservadas) + $corte <= self::MAX_FRASES_PROMPT) {
                $reservadas = array_merge($reservadas, array_slice($frasesCategoria, 0, $corte));
                $restante = array_merge($restante, array_slice($frasesCategoria, $corte));
            } else {
                $restante = array_merge($restante, $frasesCategoria);
            }
        }

        shuffle($restante);
        $selecionadas = $reservadas;
        $faltam = self::MAX_FRASES_PROMPT - count($selecionadas);

        if ($faltam > 0) {
            $selecionadas = array_merge($selecionadas, array_slice($restante, 0, $faltam));
        }

        return array_slice($selecionadas, 0, self::MAX_FRASES_PROMPT);
    }

    // Usado só pra recalcular o destaque de uma frase PENDENTE (já gerada,
    // ainda não respondida) - reabrir a tela sorteia um novo lote aleatório de
    // até 50 frases via getFrasesDoUsuario(), quase certamente diferente do
    // lote que gerou o texto original (ex: usuário com 664 frases no par tem
    // ~7% de chance de cada frase repetir no sorteio), fazendo o destaque
    // sumir mesmo com a frase gerada reaproveitando vocabulário de verdade.
    // Sem LIMIT porque isso é só computação local (n-gramas), não vai pra IA -
    // o limite de 50 existe só pra não gastar tokens à toa na geração.
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
