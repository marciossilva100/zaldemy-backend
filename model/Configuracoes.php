<?php

class Configuracoes
{
    // Vozes nativas do gpt-4o-mini-tts (OpenAI) - o usuário escolhe direto
    // qual delas quer, sem abstração de gênero.
    const VOZES_TTS_VALIDAS = [
        'alloy', 'ash', 'ballad', 'cedar', 'coral', 'echo', 'fable',
        'marin', 'nova', 'onyx', 'sage', 'shimmer', 'verse'
    ];
    const VOZ_TTS_PADRAO = 'nova';

    public static function setConfiguracoes(PDO $pdo, $user_id): array
    {
        // verificar se já existe configuração para o usuário
        $sql = "SELECT id
                FROM configuracoes
                WHERE user_id = :user_id
                LIMIT 1";

        $stmt = $pdo->prepare($sql);
        $stmt->bindValue(':user_id', $user_id, PDO::PARAM_INT);
        $stmt->execute();

        $existe = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!empty($existe['id'])) {
            return [
                'success' => true,
                'message' => 'Configuração já existente.'
            ];
        }

        // inserir configuração padrão
        $sql = "INSERT INTO configuracoes
                (quantidade_frases_aprender, user_id, voz_tts)
                VALUES
                (:quantidade_frases_aprender, :user_id, :voz_tts)";

        $stmt = $pdo->prepare($sql);
        $stmt->bindValue(':quantidade_frases_aprender', 8, PDO::PARAM_INT);
        $stmt->bindValue(':user_id', $user_id, PDO::PARAM_INT);
        $stmt->bindValue(':voz_tts', self::VOZ_TTS_PADRAO, PDO::PARAM_STR);
        $stmt->execute();

        return [
            'success' => true,
            'message' => 'Configuração criada com sucesso.'
        ];
    }

    public static function getConfiguracoes(PDO $pdo, $user_id): array
    {
        // garante que existe uma configuração antes de buscar
        self::setConfiguracoes($pdo, $user_id);

        $sql = "SELECT quantidade_frases_aprender, voz_tts
                FROM configuracoes
                WHERE user_id = :user_id
                LIMIT 1";

        $stmt = $pdo->prepare($sql);
        $stmt->bindValue(':user_id', $user_id, PDO::PARAM_INT);
        $stmt->execute();

        $configuracao = $stmt->fetch(PDO::FETCH_ASSOC);

        return [
            'success' => true,
            'quantidade_frases_aprender' => (int) ($configuracao['quantidade_frases_aprender'] ?? 8),
            'voz_tts' => $configuracao['voz_tts'] ?? self::VOZ_TTS_PADRAO
        ];
    }

    public static function getVozTts(PDO $pdo, $user_id): string
    {
        $sql = "SELECT voz_tts FROM configuracoes WHERE user_id = :user_id LIMIT 1";

        $stmt = $pdo->prepare($sql);
        $stmt->bindValue(':user_id', $user_id, PDO::PARAM_INT);
        $stmt->execute();

        $voz = $stmt->fetch(PDO::FETCH_ASSOC)['voz_tts'] ?? null;

        return in_array($voz, self::VOZES_TTS_VALIDAS, true) ? $voz : self::VOZ_TTS_PADRAO;
    }

    public static function atualizarVozTts(PDO $pdo, $user_id, $voz): array
    {
        if (!in_array($voz, self::VOZES_TTS_VALIDAS, true)) {
            return [
                'success' => false,
                'message' => 'Voz inválida.'
            ];
        }

        // garante que existe uma configuração antes de atualizar
        self::setConfiguracoes($pdo, $user_id);

        $sql = "UPDATE configuracoes
                SET voz_tts = :voz_tts
                WHERE user_id = :user_id";

        $stmt = $pdo->prepare($sql);
        $stmt->bindValue(':voz_tts', $voz, PDO::PARAM_STR);
        $stmt->bindValue(':user_id', $user_id, PDO::PARAM_INT);
        $stmt->execute();

        return [
            'success' => true,
            'message' => 'Voz atualizada com sucesso.',
            'voz_tts' => $voz
        ];
    }

    public static function atualizarQuantidadeFrasesAprender(PDO $pdo, $user_id, $quantidade): array
    {
        $quantidade = (int) $quantidade;

        if ($quantidade < 1 || $quantidade > 8) {
            return [
                'success' => false,
                'message' => 'A quantidade deve ser um número entre 1 e 8.'
            ];
        }

        // garante que existe uma configuração antes de atualizar
        self::setConfiguracoes($pdo, $user_id);

        $sql = "UPDATE configuracoes
                SET quantidade_frases_aprender = :quantidade_frases_aprender
                WHERE user_id = :user_id";

        $stmt = $pdo->prepare($sql);
        $stmt->bindValue(':quantidade_frases_aprender', $quantidade, PDO::PARAM_INT);
        $stmt->bindValue(':user_id', $user_id, PDO::PARAM_INT);
        $stmt->execute();

        return [
            'success' => true,
            'message' => 'Quantidade atualizada com sucesso.',
            'quantidade_frases_aprender' => $quantidade
        ];
    }

}
