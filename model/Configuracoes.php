<?php

class Configuracoes
{
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
                (quantidade_frases_aprender, user_id)
                VALUES
                (:quantidade_frases_aprender, :user_id)";

        $stmt = $pdo->prepare($sql);
        $stmt->bindValue(':quantidade_frases_aprender', 8, PDO::PARAM_INT);
        $stmt->bindValue(':user_id', $user_id, PDO::PARAM_INT);
        $stmt->execute();

        return [
            'success' => true,
            'message' => 'Configuração criada com sucesso.'
        ];
    }


}
