<?php
date_default_timezone_set('America/Sao_Paulo');

require_once '../server.php';

class Onboarding
{
    public function finalizarTutorial($user_id): array
    {
        global $pdo;

        // só avança quem está no passo esperado (não deixa "voltar" o step de
        // quem já passou daqui, nem avançar de um passo inesperado)
        $sql = 'UPDATE usuarios SET step = 4 WHERE id = :id AND step = 3';
        $stmt = $pdo->prepare($sql);
        $stmt->bindValue(':id', $user_id, PDO::PARAM_INT);
        $stmt->execute();

        return [
            'success' => true,
            'message' => 'Tutorial finalizado com sucesso.'
        ];
    }
}
