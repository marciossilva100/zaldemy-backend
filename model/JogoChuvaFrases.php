<?php

// Minijogo "Chuva de Frases" - só guarda a melhor pontuação por categoria
// (não o histórico de cada partida). A geração de frases/categorias em si
// reaproveita Frases::listarFrases e Categorias::listarComQuantidade, já
// usados por outras telas - esse model cuida só do recorde.
class JogoChuvaFrases
{
    // Premium (1) joga sem limite - o jogo não usa nenhuma API paga (a voz é
    // sempre a padrão gratuita, nunca a natural). Limitado (3) ganha uma
    // amostra vitalícia, mesmo padrão dos outros recursos em PlanoLimitado.
    const LIMITE_VITALICIO_LIMITADO = 5;

    public static function contarTotal(PDO $pdo, int $user_id): int
    {
        $sql = "SELECT COUNT(*) as total FROM jogo_chuva_uso WHERE user_id = :user_id";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([':user_id' => $user_id]);

        return (int) $stmt->fetch(PDO::FETCH_ASSOC)['total'];
    }

    public static function registrarUso(PDO $pdo, int $user_id): void
    {
        $stmt = $pdo->prepare("INSERT INTO jogo_chuva_uso (user_id) VALUES (:user_id)");
        $stmt->execute([':user_id' => $user_id]);
    }

    // premium (1): sem limite. limitado (3): amostra vitalícia. free (2):
    // bloqueado - jogo é recurso exclusivo dos planos Premium e Limitado.
    public static function verificarAcesso(PDO $pdo, int $user_id, int $plano): ?array
    {
        if ($plano === 1) {
            return null;
        }

        if ($plano === 3) {
            if (self::contarTotal($pdo, $user_id) >= self::LIMITE_VITALICIO_LIMITADO) {
                return [
                    "success" => false,
                    "limite_atingido" => true,
                    "message" => "Você já jogou suas " . self::LIMITE_VITALICIO_LIMITADO . " partidas grátis do jogo Chuva de Frases. Vire premium para jogar sem limite.",
                ];
            }
            return null;
        }

        return [
            "success" => false,
            "premium_necessario" => true,
            "message" => "O jogo Chuva de Frases é um recurso exclusivo dos planos Premium e Limitado.",
        ];
    }

    public static function categoriaPertenceAoUsuario(PDO $pdo, int $user_id, int $categoriaId): bool
    {
        $sql = "SELECT COUNT(*) as total FROM categorias
                WHERE id = :categoria_id AND id_user = :user_id AND status_id > 0";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([':categoria_id' => $categoriaId, ':user_id' => $user_id]);

        return (int) $stmt->fetch(PDO::FETCH_ASSOC)['total'] > 0;
    }

    public static function buscarRecorde(PDO $pdo, int $user_id, int $categoriaId): int
    {
        $sql = "SELECT melhor_pontuacao FROM jogo_chuva_recorde
                WHERE user_id = :user_id AND categoria_id = :categoria_id";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([':user_id' => $user_id, ':categoria_id' => $categoriaId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ? (int) $row['melhor_pontuacao'] : 0;
    }

    // Só atualiza se a pontuação nova for maior que a salva (GREATEST cobre
    // o insert inicial também, comparando com 0). Retorna o recorde final
    // (pode ser a pontuação nova ou a antiga, se ela já era maior).
    public static function salvarPontuacao(PDO $pdo, int $user_id, int $categoriaId, int $pontuacao): int
    {
        $sql = "INSERT INTO jogo_chuva_recorde (user_id, categoria_id, melhor_pontuacao)
                VALUES (:user_id, :categoria_id, :pontuacao)
                ON DUPLICATE KEY UPDATE melhor_pontuacao = GREATEST(melhor_pontuacao, VALUES(melhor_pontuacao))";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            ':user_id' => $user_id,
            ':categoria_id' => $categoriaId,
            ':pontuacao' => max(0, $pontuacao),
        ]);

        return self::buscarRecorde($pdo, $user_id, $categoriaId);
    }
}
