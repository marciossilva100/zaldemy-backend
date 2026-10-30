<?php

// Nível de proficiência que o usuário informa (iniciante, intermediário,
// avançado) - usado como contexto pras features de IA gerarem conteúdo no
// nível certo de dificuldade. É por PAR DE IDIOMA (idioma_nativo +
// idioma_aprender), não global ao usuário - alguém pode ser avançado em
// inglês e iniciante em alemão ao mesmo tempo. Guardado em
// nivel_usuario_idioma, uma linha por (usuário, par de idioma); usuarios.nivel
// continua existindo só como cache do último par usado (ex: onboarding, que
// roda antes de qualquer linha nessa tabela existir pro par recém-escolhido).
class Nivel
{
    const INICIANTE = 1;
    const INTERMEDIARIO = 2;
    const AVANCADO = 3;

    private static function valido(int $nivel): bool
    {
        return in_array($nivel, [self::INICIANTE, self::INTERMEDIARIO, self::AVANCADO], true);
    }

    // Par de idioma ATIVO no momento (idioma_referencia - ver Idioma.php) -
    // usado como fallback quando quem chama não tem um par específico em mãos
    // (ex: Configurações, que mostra/edita o nível do que está selecionado
    // agora nas bandeiras da Home).
    private static function parAtual(PDO $pdo, int $user_id): ?array
    {
        $stmt = $pdo->prepare("SELECT idioma_nativo, idioma_aprender FROM idioma_referencia WHERE id_user = :id LIMIT 1");
        $stmt->execute([':id' => $user_id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row || !$row['idioma_nativo'] || !$row['idioma_aprender']) {
            return null;
        }

        return [(int) $row['idioma_nativo'], (int) $row['idioma_aprender']];
    }

    // Registra o nível pro par de idioma informado (ou pro par ATIVO agora,
    // se nenhum for passado - caso do seletor em Configurações). Mantém
    // usuarios.nivel atualizado também, só como cache/compat de quem ainda
    // lê a coluna direto.
    public static function registrar(PDO $pdo, int $user_id, int $nivel, ?int $idiomaNativo = null, ?int $idiomaAprender = null): array
    {
        if (!self::valido($nivel)) {
            return ["success" => false, "message" => "Nível inválido."];
        }

        if ($idiomaNativo === null || $idiomaAprender === null) {
            $par = self::parAtual($pdo, $user_id);
            if (!$par) {
                return ["success" => false, "message" => "Selecione seu idioma nativo e de aprendizagem antes de continuar."];
            }
            [$idiomaNativo, $idiomaAprender] = $par;
        }

        $stmt = $pdo->prepare("
            INSERT INTO nivel_usuario_idioma (id_user, idioma_nativo, idioma_aprender, nivel)
            VALUES (:id, :nativo, :aprender, :nivel)
            ON DUPLICATE KEY UPDATE nivel = VALUES(nivel)
        ");
        $stmt->execute([
            ':id' => $user_id,
            ':nativo' => $idiomaNativo,
            ':aprender' => $idiomaAprender,
            ':nivel' => $nivel,
        ]);

        $stmt = $pdo->prepare("UPDATE usuarios SET nivel = :nivel WHERE id = :id");
        $stmt->execute([':nivel' => $nivel, ':id' => $user_id]);

        return ["success" => true, "nivel" => $nivel];
    }

    // Verdadeiro só quando já existe uma linha pra esse par em
    // nivel_usuario_idioma (nível realmente escolhido alguma vez, manualmente
    // ou migrado) - diferente de obterNivel(), que sempre devolve um valor
    // concreto (default iniciante). Usado só pelo onboarding
    // (EscolherNivel.jsx), pra saber se já pode pular a etapa de escolher
    // nível ou se o par é novo e ainda precisa perguntar.
    public static function nivelDefinido(PDO $pdo, int $user_id, ?int $idiomaNativo = null, ?int $idiomaAprender = null): bool
    {
        if ($idiomaNativo === null || $idiomaAprender === null) {
            $par = self::parAtual($pdo, $user_id);
            if (!$par) {
                return false;
            }
            [$idiomaNativo, $idiomaAprender] = $par;
        }

        $stmt = $pdo->prepare("
            SELECT 1 FROM nivel_usuario_idioma
            WHERE id_user = :id AND idioma_nativo = :nativo AND idioma_aprender = :aprender
            LIMIT 1
        ");
        $stmt->execute([':id' => $user_id, ':nativo' => $idiomaNativo, ':aprender' => $idiomaAprender]);

        return (bool) $stmt->fetchColumn();
    }

    // Nível (int) pro par de idioma informado - ou pro par ATIVO agora, se
    // nenhum par for passado. Sem linha ainda pra esse par (nunca definido
    // manualmente nem migrado), cai no padrão iniciante - ver
    // nivelDefinido() pra distinguir "nunca escolhido" de "escolhido como
    // iniciante mesmo".
    public static function obterNivel(PDO $pdo, int $user_id, ?int $idiomaNativo = null, ?int $idiomaAprender = null): int
    {
        if ($idiomaNativo === null || $idiomaAprender === null) {
            $par = self::parAtual($pdo, $user_id);
            if (!$par) {
                return self::INICIANTE;
            }
            [$idiomaNativo, $idiomaAprender] = $par;
        }

        $stmt = $pdo->prepare("
            SELECT nivel FROM nivel_usuario_idioma
            WHERE id_user = :id AND idioma_nativo = :nativo AND idioma_aprender = :aprender
            LIMIT 1
        ");
        $stmt->execute([':id' => $user_id, ':nativo' => $idiomaNativo, ':aprender' => $idiomaAprender]);
        $nivel = $stmt->fetch(PDO::FETCH_ASSOC)['nivel'] ?? null;
        $nivel = $nivel !== null ? (int) $nivel : null;

        // Sem linha ainda pra esse par (par novo, nunca estudado antes) -
        // começa como iniciante, nunca herda o nível de outro par (pedido
        // explícito do usuário: alguém intermediário em inglês que começa do
        // zero em alemão não deve ganhar conteúdo de alemão nível
        // intermediário).
        return ($nivel !== null && self::valido($nivel)) ? $nivel : self::INICIANTE;
    }

    // Busca o nível (pro par informado, ou pro par ativo) já convertido pro
    // texto usado nos prompts de IA - evita repetir a mesma lógica em cada
    // recurso que gera conteúdo.
    public static function obterNomeDoUsuario(PDO $pdo, int $user_id, ?int $idiomaNativo = null, ?int $idiomaAprender = null): string
    {
        return self::nomeParaPrompt(self::obterNivel($pdo, $user_id, $idiomaNativo, $idiomaAprender));
    }

    public static function nomeParaPrompt(?int $nivel): string
    {
        return match ($nivel) {
            self::INICIANTE => 'iniciante (A1/A2)',
            self::INTERMEDIARIO => 'intermediário (B1/B2)',
            self::AVANCADO => 'avançado (C1/C2)',
            default => 'intermediário (B1/B2)',
        };
    }

    // Quantas tentativas avaliadas mais recentes (Frase do Dia + Perguntas
    // por IA, as duas fontes de nota 0-10 que usam nível) precisam ter nota
    // alta pra sugerir a promoção de nível - critério rígido de propósito
    // (todas as N, não uma média): uma sugestão errada é pior que uma
    // atrasada, já que subir o nível manualmente é só um toque em
    // Configurações.
    const MIN_TENTATIVAS_SUGESTAO = 5;
    const NOTA_MINIMA_SUGESTAO = 8;

    // Decide se sugere promover o usuário pro próximo nível NO PAR DE IDIOMA
    // ATIVO agora, com base nas últimas tentativas avaliadas DESSE PAR (antes
    // misturava tentativas de qualquer par de idioma já praticado - alguém
    // avançado em inglês podia ser sugerido a "virar avançado" em alemão só
    // por desempenho em inglês). Retorna o nível sugerido (int) ou null se
    // não há sugestão (sem par ativo, sem dados suficientes, já é avançado,
    // desempenho não bateu o critério, ou essa sugestão já foi dispensada
    // pra esse par).
    public static function sugestaoPromocao(PDO $pdo, int $user_id): ?int
    {
        $par = self::parAtual($pdo, $user_id);
        if (!$par) {
            return null;
        }
        [$idiomaNativo, $idiomaAprender] = $par;

        $nivelAtual = self::obterNivel($pdo, $user_id, $idiomaNativo, $idiomaAprender);

        if ($nivelAtual >= self::AVANCADO) {
            return null;
        }

        $nivelSugerido = $nivelAtual + 1;

        $stmt = $pdo->prepare("
            SELECT nivel_sugestao_dispensada FROM nivel_usuario_idioma
            WHERE id_user = :id AND idioma_nativo = :nativo AND idioma_aprender = :aprender
            LIMIT 1
        ");
        $stmt->execute([':id' => $user_id, ':nativo' => $idiomaNativo, ':aprender' => $idiomaAprender]);
        $dispensada = $stmt->fetch(PDO::FETCH_ASSOC)['nivel_sugestao_dispensada'] ?? null;

        if ($dispensada !== null && (int) $dispensada === $nivelSugerido) {
            return null;
        }

        $sql = "SELECT nota FROM (
                    SELECT nota, data_criacao FROM frase_dia_ia
                        WHERE user_id = :user_id1 AND status_id = 1 AND nota IS NOT NULL
                        AND idioma_nativo = :nativo1 AND idioma_aprender = :aprender1
                    UNION ALL
                    SELECT nota, data_criacao FROM perguntas_ia
                        WHERE user_id = :user_id2 AND status_id = 1 AND nota IS NOT NULL
                        AND idioma_nativo = :nativo2 AND idioma_aprender = :aprender2
                ) tentativas
                ORDER BY data_criacao DESC
                LIMIT " . self::MIN_TENTATIVAS_SUGESTAO;

        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            ':user_id1' => $user_id, ':nativo1' => $idiomaNativo, ':aprender1' => $idiomaAprender,
            ':user_id2' => $user_id, ':nativo2' => $idiomaNativo, ':aprender2' => $idiomaAprender,
        ]);
        $notas = $stmt->fetchAll(PDO::FETCH_COLUMN);

        if (count($notas) < self::MIN_TENTATIVAS_SUGESTAO) {
            return null;
        }

        foreach ($notas as $nota) {
            if ((int) $nota < self::NOTA_MINIMA_SUGESTAO) {
                return null;
            }
        }

        return $nivelSugerido;
    }

    // Guarda que o usuário recusou a sugestão de subir pro nível informado,
    // NO PAR DE IDIOMA ATIVO agora - sugestaoPromocao não repete a mesma
    // sugestão pra esse par depois disso (mas volta a sugerir se o
    // desempenho justificar um nível seguinte mais tarde, ou continua
    // sugerindo normalmente nos outros pares de idioma).
    public static function dispensarSugestao(PDO $pdo, int $user_id, int $nivelSugerido): void
    {
        $par = self::parAtual($pdo, $user_id);
        if (!$par) {
            return;
        }
        [$idiomaNativo, $idiomaAprender] = $par;

        $stmt = $pdo->prepare("
            INSERT INTO nivel_usuario_idioma (id_user, idioma_nativo, idioma_aprender, nivel_sugestao_dispensada)
            VALUES (:id, :nativo, :aprender, :sugestao)
            ON DUPLICATE KEY UPDATE nivel_sugestao_dispensada = VALUES(nivel_sugestao_dispensada)
        ");
        $stmt->execute([
            ':id' => $user_id,
            ':nativo' => $idiomaNativo,
            ':aprender' => $idiomaAprender,
            ':sugestao' => $nivelSugerido,
        ]);
    }
}
