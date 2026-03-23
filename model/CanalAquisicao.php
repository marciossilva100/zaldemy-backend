<?php
date_default_timezone_set('America/Sao_Paulo');

require_once '../server.php';

class CanalAquisicao
{

    public $rede_social;
    public $response = array();

    public function registrarCanal($user_id): array
    {

        global $pdo;

        if (empty(trim($this->rede_social))) {
            return [
                'success' => false,
                'message' => 'Rede social não informada.'
            ];
        }

        // buscar id da rede social
        $sql = "SELECT id 
                FROM redes_sociais 
                WHERE nome = :nome 
                LIMIT 1";

        $stmt = $pdo->prepare($sql);
        $stmt->bindValue(':nome', $this->rede_social, PDO::PARAM_STR);
        $stmt->execute();

        $rede = $stmt->fetch(PDO::FETCH_ASSOC);

        if (empty($rede['id'])) {
            return [
                'success' => false,
                'message' => 'Rede social não encontrada.'
            ];
        }

        $rede_social_id = $rede['id'];

        // verificar se usuário já registrou
        $sql = "SELECT id 
                FROM canal_aquisicao
                WHERE user_id = :user_id
                LIMIT 1";

        $stmt = $pdo->prepare($sql);
        $stmt->bindValue(':user_id', $user_id, PDO::PARAM_INT);
        $stmt->execute();

        $existe = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!empty($existe['id'])) {
            return [
                'success' => false,
                'message' => 'Canal de aquisição já registrado.'
            ];
        }

        // inserir registro
        $sql = "INSERT INTO canal_aquisicao
                (user_id, rede_social_id)
                VALUES
                (:user_id, :rede_social_id)";

        $stmt = $pdo->prepare($sql);
        $stmt->bindValue(':user_id', $user_id, PDO::PARAM_INT);
        $stmt->bindValue(':rede_social_id', $rede_social_id, PDO::PARAM_INT);
        $stmt->execute();

        $sql = 'UPDATE usuarios SET step = 3 WHERE id = :id LIMIT 1';
        $stmt = $pdo->prepare($sql);
        $stmt->bindValue(':id', $user_id, PDO::PARAM_INT);
        $stmt->execute();

        return [
            'success' => true,
            'message' => 'Canal de aquisição registrado com sucesso.'
        ];
    }

}