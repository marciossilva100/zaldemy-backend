<?php

// Minijogo "Tiro Certeiro" - mesma mecânica de acesso/cota da Chuva de
// Frases (ver JogoChuvaFrases.php), mas o conteúdo (frase-alvo e opções)
// é gerado por IA a partir do vocabulário do usuário, nunca copiado
// literalmente das frases salvas dele (que podem conter erros dele
// mesmo). Uma chamada de IA gera o lote inteiro de rodadas de uma vez -
// gerar rodada por rodada custaria uma chamada por tiro, inviável pro
// ritmo do jogo.
class TiroCerteiro
{
    // Premium (1) joga sem limite - mesmo padrão da Chuva de Frases, o
    // jogo não usa nenhuma API paga de voz (não toca áudio). Limitado (3)
    // ganha o MESMO teto diário da Chuva de Frases (não vitalício).
    const LIMITE_DIARIO_LIMITADO = 2;

    const MAX_FRASES_PROMPT = 50;
    const MAX_TENTATIVAS_GERACAO = 5;
    const QTD_RODADAS = 15;

    // Premium não tem teto diário (ver verificarAcesso), então esse cooldown
    // é o único freio contra alguém automatizar "jogar de novo" repetidas
    // vezes só pra gerar chamadas de IA - cada partida nova custa 1 chamada
    // ao gerar as rodadas. 5s é imperceptível pra quem está jogando de
    // verdade (nenhuma partida termina em menos tempo que isso), mas
    // inviabiliza um script disparando a cada poucos milissegundos.
    const COOLDOWN_SEGUNDOS = 5;

    public static function contarHoje(PDO $pdo, int $user_id): int
    {
        $sql = "SELECT COUNT(*) as total FROM tiro_certeiro_uso
                WHERE user_id = :user_id AND DATE(data_criacao) = CURDATE()";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([':user_id' => $user_id]);

        return (int) $stmt->fetch(PDO::FETCH_ASSOC)['total'];
    }

    public static function segundosDesdeUltimoUso(PDO $pdo, int $user_id): ?int
    {
        $sql = "SELECT TIMESTAMPDIFF(SECOND, MAX(data_criacao), NOW()) as segundos
                FROM tiro_certeiro_uso WHERE user_id = :user_id";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([':user_id' => $user_id]);
        $segundos = $stmt->fetch(PDO::FETCH_ASSOC)['segundos'] ?? null;

        return $segundos === null ? null : (int) $segundos;
    }

    public static function registrarUso(PDO $pdo, int $user_id): void
    {
        $stmt = $pdo->prepare("INSERT INTO tiro_certeiro_uso (user_id) VALUES (:user_id)");
        $stmt->execute([':user_id' => $user_id]);
    }

    // premium (1): sem limite. limitado (3): teto diário. free (2):
    // bloqueado - jogo é recurso exclusivo dos planos Premium e Limitado.
    public static function verificarAcesso(PDO $pdo, int $user_id, int $plano): ?array
    {
        $segundosDesdeUltimoUso = self::segundosDesdeUltimoUso($pdo, $user_id);
        if ($segundosDesdeUltimoUso !== null && $segundosDesdeUltimoUso < self::COOLDOWN_SEGUNDOS) {
            return [
                "success" => false,
                "cooldown" => true,
                "message" => "Aguarde alguns segundos antes de começar outra partida.",
            ];
        }

        if ($plano === 1) {
            return null;
        }

        if ($plano === 3) {
            if (self::contarHoje($pdo, $user_id) >= self::LIMITE_DIARIO_LIMITADO) {
                return [
                    "success" => false,
                    "limite_atingido" => true,
                    "message" => "Você já jogou suas " . self::LIMITE_DIARIO_LIMITADO . " partidas de hoje do jogo Tiro Certeiro. Volte amanhã ou vire premium para jogar sem limite.",
                ];
            }
            return null;
        }

        return [
            "success" => false,
            "premium_necessario" => true,
            "message" => "O jogo Tiro Certeiro é um recurso exclusivo dos planos Premium e Limitado.",
        ];
    }

    // Vocabulário do usuário no par de idioma atual, pra alimentar o
    // prompt de geração - mesmo padrão de FraseDoDia::getFrasesDoUsuario.
    public static function getFrasesDoUsuario(PDO $pdo, int $user_id): array
    {
        $frases = self::buscarFrasesPorEstagio($pdo, $user_id, true);

        if (count($frases) < 3) {
            $frases = self::buscarFrasesPorEstagio($pdo, $user_id, false);
        }

        return $frases;
    }

    private static function buscarFrasesPorEstagio(PDO $pdo, int $user_id, bool $exigirTreinoMinimo): array
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
                AND f.status_id > 0"
                . ($exigirTreinoMinimo ? " AND f.id_treino >= 2" : "") . "
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

    // Gera o lote inteiro de rodadas numa chamada só. Cada rodada: uma
    // palavra/frase curta no idioma nativo ("alvo"), a tradução certa
    // ("certa") e 2 traduções erradas mas plausíveis ("erradas") - 3
    // opções no total, pro jogo mostrar em 3 alvos simultâneos na tela.
    // Nunca copia as frases do usuário literalmente: usa como matéria-prima
    // de vocabulário/tema pra compor itens NOVOS, curtos (1 palavra ou
    // expressão curta, não frases inteiras como na Frase do Dia).
    public static function gerarRodadas(PDO $pdo, OpenAiChat $chat, array $frases, string $idiomaNome, string $idiomaNativoNome, string $nivelNome): array
    {
        shuffle($frases);
        $frasesText = implode("\n", array_map(fn($p) => mb_substr($p, 0, 200), $frases));

        $systemPrompt = "Você é um professor de idiomas criando um jogo de reflexo de tradução em {$idiomaNome} "
            . "pra um aluno de nível {$nivelNome}. Vai receber frases que o aluno já estuda, em {$idiomaNome} - use "
            . "elas só como inspiração de vocabulário e tema (nunca copie uma frase inteira, nunca repita um trecho "
            . "grande literalmente). "
            . "Gere exatamente " . self::QTD_RODADAS . " rodadas independentes. Cada rodada tem: uma palavra ou "
            . "expressão CURTA (no máximo 2 palavras) em {$idiomaNativoNome} ('alvo'), a tradução certa dela em "
            . "{$idiomaNome} ('certa', também no máximo 2 palavras), e exatamente 1 tradução errada mas plausível "
            . "em {$idiomaNome} ('errada', também no máximo 2 palavras) - parecida o bastante pra exigir atenção "
            . "real (mesmo campo semântico, palavra parecida ou erro comum de aluno), nunca absurdamente diferente "
            . "nem óbvia demais. "
            . "REQUISITOS OBRIGATÓRIOS: (1) as " . self::QTD_RODADAS . " rodadas precisam ser todas diferentes "
            . "entre si, sem repetir a mesma palavra-alvo nem a mesma tradução certa duas vezes; (2) 'alvo', "
            . "'certa' e 'errada' de uma rodada precisam ser 3 textos DIFERENTES entre si; (3) ajuste a "
            . "dificuldade do vocabulário ao nível do aluno (iniciante: palavras básicas do dia a dia; avançado: "
            . "vocabulário menos óbvio, expressões idiomáticas). "
            . 'Responda em JSON: {"rodadas": [{"alvo": "...", "certa": "...", "errada": "..."}, ...]}';

        $userContent = "Frases que o aluno já estuda:\n" . $frasesText;

        $ultimoResultado = [];

        for ($tentativa = 1; $tentativa <= self::MAX_TENTATIVAS_GERACAO; $tentativa++) {
            $resultado = $chat->completar([
                ['role' => 'system', 'content' => $systemPrompt],
                ['role' => 'user', 'content' => $userContent],
            ], true, 3000);

            if ($resultado['erro']) {
                return $ultimoResultado ?: ["erro" => true, "mensagem" => $resultado['mensagem']];
            }

            $decodificado = json_decode($resultado['texto'], true);
            $rodadas = self::validarRodadas($decodificado['rodadas'] ?? null);

            if ($rodadas !== null) {
                return $rodadas;
            }

            // Mantém o melhor resultado parcial visto até agora, caso todas as
            // tentativas falhem em bater a quantidade exata - melhor entregar
            // um lote menor (o front só precisa ler o array, não um tamanho
            // fixo) do que travar o jogo numa API instável.
            $parcial = self::filtrarRodadasValidas($decodificado['rodadas'] ?? []);
            if (count($parcial) > count($ultimoResultado)) {
                $ultimoResultado = $parcial;
            }
        }

        return $ultimoResultado;
    }

    // Valida o lote inteiro: precisa ter exatamente QTD_RODADAS itens, cada
    // um com os campos certos e sem textos duplicados dentro da rodada nem
    // "certa" repetida entre rodadas (evitaria duas rodadas com a mesma
    // resposta certa, confuso pro jogador que já decorou a última).
    private static function validarRodadas(?array $rodadas): ?array
    {
        if (!is_array($rodadas) || count($rodadas) !== self::QTD_RODADAS) {
            return null;
        }

        $filtradas = self::filtrarRodadasValidas($rodadas);

        return count($filtradas) === self::QTD_RODADAS ? $filtradas : null;
    }

    private static function filtrarRodadasValidas(array $rodadas): array
    {
        $validas = [];
        $certasUsadas = [];

        foreach ($rodadas as $rodada) {
            if (!is_array($rodada)) {
                continue;
            }

            $alvo = trim((string) ($rodada['alvo'] ?? ''));
            $certa = trim((string) ($rodada['certa'] ?? ''));
            $errada = trim((string) ($rodada['errada'] ?? ''));

            if ($alvo === '' || $certa === '' || $errada === '') {
                continue;
            }

            $textos = [$alvo, $certa, $errada];
            if (count(array_unique(array_map('mb_strtolower', $textos))) !== count($textos)) {
                continue; // algum dos 3 textos repetido dentro da própria rodada
            }

            $chaveCerta = mb_strtolower($certa);
            if (isset($certasUsadas[$chaveCerta])) {
                continue; // "certa" repetida entre rodadas diferentes
            }

            if (verificarConteudoImproprio($alvo) || verificarConteudoImproprio($certa)
                || verificarConteudoImproprio($errada)) {
                continue;
            }

            $certasUsadas[$chaveCerta] = true;
            $validas[] = ["alvo" => $alvo, "certa" => $certa, "errada" => $errada];
        }

        return $validas;
    }

    public static function buscarRecorde(PDO $pdo, int $user_id): int
    {
        $sql = "SELECT melhor_pontuacao FROM tiro_certeiro_recorde WHERE user_id = :user_id";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([':user_id' => $user_id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ? (int) $row['melhor_pontuacao'] : 0;
    }

    public static function salvarPontuacao(PDO $pdo, int $user_id, int $pontuacao): int
    {
        $sql = "INSERT INTO tiro_certeiro_recorde (user_id, melhor_pontuacao)
                VALUES (:user_id, :pontuacao)
                ON DUPLICATE KEY UPDATE melhor_pontuacao = GREATEST(melhor_pontuacao, VALUES(melhor_pontuacao))";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            ':user_id' => $user_id,
            ':pontuacao' => max(0, $pontuacao),
        ]);

        return self::buscarRecorde($pdo, $user_id);
    }
}
