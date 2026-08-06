<?php

// Nível de proficiência que o usuário informa no cadastro (iniciante,
// intermediário, avançado) - usado como contexto pras features de IA
// (Perguntas e Frase do Dia) gerarem conteúdo no nível certo de dificuldade.
class Nivel
{
    const INICIANTE = 1;
    const INTERMEDIARIO = 2;
    const AVANCADO = 3;

    private static function valido(int $nivel): bool
    {
        return in_array($nivel, [self::INICIANTE, self::INTERMEDIARIO, self::AVANCADO], true);
    }

    public static function registrar(PDO $pdo, int $user_id, int $nivel): array
    {
        if (!self::valido($nivel)) {
            return ["success" => false, "message" => "Nível inválido."];
        }

        $stmt = $pdo->prepare("UPDATE usuarios SET nivel = :nivel WHERE id = :id");
        $stmt->execute([':nivel' => $nivel, ':id' => $user_id]);

        return ["success" => true, "nivel" => $nivel];
    }

    // Busca o nível salvo do usuário (Nivel::registrar, no cadastro) já
    // convertido pro texto usado nos prompts de IA - evita repetir a mesma
    // query SELECT nivel FROM usuarios em cada recurso que gera conteúdo.
    public static function obterNomeDoUsuario(PDO $pdo, int $user_id): string
    {
        $stmt = $pdo->prepare("SELECT nivel FROM usuarios WHERE id = :id");
        $stmt->execute([':id' => $user_id]);
        $nivel = $stmt->fetch(PDO::FETCH_ASSOC)['nivel'] ?? null;

        return self::nomeParaPrompt($nivel !== null ? (int) $nivel : null);
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

    // Decide se sugere promover o usuário pro próximo nível, com base nas
    // últimas tentativas avaliadas. Retorna o nível sugerido (int) ou null
    // se não há sugestão (sem dados suficientes, já é avançado, desempenho
    // não bateu o critério, ou essa mesma sugestão já foi dispensada antes).
    public static function sugestaoPromocao(PDO $pdo, int $user_id): ?int
    {
        $stmt = $pdo->prepare("SELECT nivel, nivel_sugestao_dispensada FROM usuarios WHERE id = :id");
        $stmt->execute([':id' => $user_id]);
        $usuario = $stmt->fetch(PDO::FETCH_ASSOC);

        $nivelAtual = (int) ($usuario['nivel'] ?? self::INTERMEDIARIO);
        $nivelAtual = self::valido($nivelAtual) ? $nivelAtual : self::INTERMEDIARIO;

        if ($nivelAtual >= self::AVANCADO) {
            return null;
        }

        $nivelSugerido = $nivelAtual + 1;

        $dispensada = $usuario['nivel_sugestao_dispensada'] ?? null;
        if ($dispensada !== null && (int) $dispensada === $nivelSugerido) {
            return null;
        }

        $sql = "SELECT nota FROM (
                    SELECT nota, data_criacao FROM frase_dia_ia
                        WHERE user_id = :user_id1 AND status_id = 1 AND nota IS NOT NULL
                    UNION ALL
                    SELECT nota, data_criacao FROM perguntas_ia
                        WHERE user_id = :user_id2 AND status_id = 1 AND nota IS NOT NULL
                ) tentativas
                ORDER BY data_criacao DESC
                LIMIT " . self::MIN_TENTATIVAS_SUGESTAO;

        $stmt = $pdo->prepare($sql);
        $stmt->execute([':user_id1' => $user_id, ':user_id2' => $user_id]);
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

    // Guarda que o usuário recusou a sugestão de subir pro nível informado -
    // sugestaoPromocao não repete a mesma sugestão depois disso (mas volta a
    // sugerir se o desempenho justificar um nível seguinte mais tarde).
    public static function dispensarSugestao(PDO $pdo, int $user_id, int $nivelSugerido): void
    {
        $stmt = $pdo->prepare("UPDATE usuarios SET nivel_sugestao_dispensada = :nivel WHERE id = :id");
        $stmt->execute([':nivel' => $nivelSugerido, ':id' => $user_id]);
    }
}
