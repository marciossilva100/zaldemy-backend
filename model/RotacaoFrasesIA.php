<?php

// Compartilhado pelos 3 treinos de IA que usam o vocabulário do usuário como
// matéria-prima (FraseDoDia, DailyQuestionOpenAI, TraducaoReversaOpenAI) -
// eles competem pelo mesmo pool de frases, então a rotação precisa ser uma
// só entre os três, não uma por treino (senão o mesmo texto ainda repetiria
// entre treinos diferentes).
//
// Problema que isso resolve: o pool de candidatos (até 50 frases) é
// sorteado de novo a cada geração (ORDER BY RAND()), mas frases de
// categorias pequenas cabem quase sempre nesse sorteio - então mesmo com o
// pool variando, a MESMA frase "fácil" fica disponível quase toda vez, e a
// IA a escolhe repetidamente. Confirmado com dados reais de produção: uma
// única frase apareceu em 36-40% de 50 gerações, e 85%+ do vocabulário
// nunca apareceu nem uma vez. Em vez de confiar que a IA varia sozinha,
// o sistema agora afasta do pool qualquer frase usada nas últimas
// LIMITE_ROTACAO gerações, forçando rotação pelo vocabulário inteiro.
class RotacaoFrasesIA
{
    const LIMITE_ROTACAO = 20;

    // Textos das frases usadas como fonte nas últimas LIMITE_ROTACAO
    // gerações (dos 3 treinos juntos) - excluir esses do próximo pool.
    public static function textosParaExcluir(PDO $pdo, int $user_id): array
    {
        $stmt = $pdo->prepare(
            "SELECT texto FROM frases_uso_recente_ia
             WHERE user_id = :user_id
             ORDER BY id DESC
             LIMIT " . self::LIMITE_ROTACAO
        );
        $stmt->execute([':user_id' => $user_id]);

        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    // Detecta quais frases do POOL que foi realmente enviado pra IA (não o
    // catálogo inteiro do usuário - só as candidatas dessa geração
    // específica, o que evita confundir frases de texto idêntico entre
    // categorias diferentes) aparecem no texto gerado, e registra como
    // usadas. Comparação por trecho de 4 palavras (não a frase inteira),
    // já que a IA normalmente adapta levemente a frase original (pessoa
    // gramatical, tempo verbal) ao encaixar no texto final.
    public static function registrarUsadas(PDO $pdo, int $user_id, array $poolEnviado, string $textoGerado): void
    {
        $normalizar = function (string $s): string {
            $s = mb_strtolower($s);
            $s = preg_replace('/[^\p{L}\p{N}\s]/u', '', $s);
            return trim(preg_replace('/\s+/', ' ', $s));
        };

        $palavrasGeradas = explode(' ', $normalizar($textoGerado));
        $janelasGeradas = [];
        for ($i = 0; $i + 4 <= count($palavrasGeradas); $i++) {
            $janelasGeradas[implode(' ', array_slice($palavrasGeradas, $i, 4))] = true;
        }

        if (empty($janelasGeradas)) {
            return;
        }

        $usadas = [];
        foreach ($poolEnviado as $fraseOriginal) {
            $palavrasFrase = explode(' ', $normalizar($fraseOriginal));
            if (count($palavrasFrase) < 4) {
                continue; // fragmento curto demais pra comparar com segurança
            }
            for ($i = 0; $i + 4 <= count($palavrasFrase); $i++) {
                $janela = implode(' ', array_slice($palavrasFrase, $i, 4));
                if (isset($janelasGeradas[$janela])) {
                    $usadas[] = $fraseOriginal;
                    break;
                }
            }
        }

        if (empty($usadas)) {
            return;
        }

        $stmt = $pdo->prepare(
            "INSERT INTO frases_uso_recente_ia (user_id, texto) VALUES (:user_id, :texto)"
        );
        foreach (array_unique($usadas) as $texto) {
            $stmt->execute([':user_id' => $user_id, ':texto' => mb_substr($texto, 0, 500)]);
        }

        // Mantém a tabela pequena - só precisa das últimas LIMITE_ROTACAO
        // linhas por usuário pra decidir a exclusão, o resto é lixo.
        $pdo->prepare(
            "DELETE FROM frases_uso_recente_ia
             WHERE user_id = :user_id
             AND id NOT IN (
                 SELECT id FROM (
                     SELECT id FROM frases_uso_recente_ia
                     WHERE user_id = :user_id2
                     ORDER BY id DESC
                     LIMIT " . self::LIMITE_ROTACAO . "
                 ) manter
             )"
        )->execute([':user_id' => $user_id, ':user_id2' => $user_id]);
    }
}
