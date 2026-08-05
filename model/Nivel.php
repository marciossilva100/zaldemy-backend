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
}
