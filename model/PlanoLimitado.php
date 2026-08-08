<?php

// O plano limitado dá uma amostra vitalícia de cada recurso de IA (voz
// natural, Frase do Dia, Categoria com IA). Quando o usuário esgota todas as
// amostras que efetivamente experimentou, não sobra mais nenhuma amostra
// grátis das ferramentas que ele usa - a partir daí ele é, na prática, um
// usuário free (plano 2), então o próprio sistema faz esse rebaixamento
// automático em vez de deixar o usuário "preso" num plano que não libera
// mais nada mas também não é tratado como free em nenhuma outra parte do
// app.
//
// Perguntas e o jogo Chuva de Frases NÃO entram aqui - viraram teto diário
// (não vitalício, ver DailyQuestionOpenAI/JogoChuvaFrases), então nunca
// "esgotam de vez" e não devem contar pra esse rebaixamento. Melhorar
// tradução com IA também não entra - virou recurso exclusivo do Premium,
// Limitado nunca teve acesso a ela (ver TraducaoIA::verificarAcesso).
//
// Ferramenta nunca tentada (uso = 0) não conta contra o usuário - só entra
// na conta quem ele já usou pelo menos uma vez. Ferramenta tentada mas com
// amostra ainda sobrando (0 < uso < limite) BLOQUEIA o rebaixamento, já que
// ainda existe amostra grátis disponível pra ele nela.
//
// Exige pelo menos MIN_FERRAMENTAS_TENTADAS diferentes tentadas antes de
// sequer avaliar o esgotamento - sem isso, Categoria com IA e Frase do Dia
// (limite vitalício de 1 uso cada) derrubariam a conta pra free com um único
// clique isolado, sem o usuário nem chegar a ver as outras ferramentas.
class PlanoLimitado
{
    const LIMITE_AUDIO_VITALICIO = 10;
    const LIMITE_FRASE_DIA_VITALICIO = 1;
    const LIMITE_CATEGORIA_IA_VITALICIO = 1;
    const MIN_FERRAMENTAS_TENTADAS = 3;

    private static function contar(PDO $pdo, string $tabela, int $user_id): int
    {
        $stmt = $pdo->prepare("SELECT COUNT(*) as total FROM {$tabela} WHERE user_id = :user_id");
        $stmt->execute([':user_id' => $user_id]);
        return (int) $stmt->fetch(PDO::FETCH_ASSOC)['total'];
    }

    private static function contarComStatus(PDO $pdo, string $tabela, int $user_id): int
    {
        $stmt = $pdo->prepare("SELECT COUNT(*) as total FROM {$tabela} WHERE user_id = :user_id AND status_id = 1");
        $stmt->execute([':user_id' => $user_id]);
        return (int) $stmt->fetch(PDO::FETCH_ASSOC)['total'];
    }

    public static function todasFerramentasTentadasEsgotadas(PDO $pdo, int $user_id): bool
    {
        $usos = [
            [self::contar($pdo, 'audio_ia_uso', $user_id), self::LIMITE_AUDIO_VITALICIO],
            [self::contarComStatus($pdo, 'frase_dia_ia', $user_id), self::LIMITE_FRASE_DIA_VITALICIO],
            [self::contar($pdo, 'categoria_ia_uso', $user_id), self::LIMITE_CATEGORIA_IA_VITALICIO],
        ];

        $ferramentasTentadas = 0;

        foreach ($usos as [$usado, $limite]) {
            if ($usado === 0) {
                continue; // nunca tentada - não conta contra o usuário
            }

            $ferramentasTentadas++;

            if ($usado < $limite) {
                return false; // tentou, mas ainda tem amostra sobrando nessa
            }
        }

        // Só rebaixa se o usuário já tiver dado uma chance real ao plano
        // (várias ferramentas diferentes), não só uma tentativa isolada.
        return $ferramentasTentadas >= self::MIN_FERRAMENTAS_TENTADAS;
    }

    // Chame depois de qualquer registro de uso bem-sucedido de um recurso de
    // IA. Não faz nada se o plano não for 3 (limitado) - só ele pode ser
    // rebaixado por esgotamento de amostra.
    public static function verificarEDowngradear(PDO $pdo, int $user_id, int $plano): void
    {
        if ($plano !== 3) {
            return;
        }

        if (self::todasFerramentasTentadasEsgotadas($pdo, $user_id)) {
            $stmt = $pdo->prepare("UPDATE usuarios SET plano = 2 WHERE id = :id AND plano = 3");
            $stmt->execute([':id' => $user_id]);
        }
    }
}
