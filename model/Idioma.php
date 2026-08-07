<?php

require_once '../server.php';

session_start();

class Idioma
{

    public $idioma_nativo;
    public $idioma_aprender;
    public $user_id;

    public static function listarIdiomas($modo = null,$user_id): array
    {

        global $pdo; // 👈 precisa disso

        $sql = "
            SELECT 
                id,
                idioma,
                sigla
            FROM idiomas
        ";

        if($modo =='learning'){
            // COALESCE(..., 0) é essencial aqui: se o usuário ainda não tem
            // idioma_nativo salvo em idioma_referencia, a subquery retorna NULL,
            // e "id <> NULL" nunca é verdadeiro pra nenhuma linha - a lista
            // inteira vinha vazia em vez de simplesmente não excluir nada.
            $sql .=" WHERE id <> COALESCE((SELECT idioma_nativo FROM idioma_referencia WHERE id_user = :id_user AND idioma_nativo IS NOT NULL LIMIT 1), 0)";
        }

        $sql .=" ORDER BY id ASC";

       // print_r($sql);

        $stmt = $pdo->prepare($sql);

        if($modo =='learning')
            $stmt->bindValue(':id_user', $user_id, PDO::PARAM_INT);

        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC);

    }

    public function setIdiomaNativo($user_id): array
    {

        //print_r($_SESSION);exit;
        global $pdo; // 👈 precisa disso

        $sql = "
            SELECT id
            FROM usuarios
            WHERE step > 0 AND id = :id
        ";

        $stmt = $pdo->prepare($sql);
        $stmt->bindValue(':id', $user_id, PDO::PARAM_INT);
        $stmt->execute();

        $result = $stmt->fetch();

        if (!empty($result['id'])) {
            // $sql = 'UPDATE FROM idioma_referencia SET idioma_nativo = :idioma_nativo WHERE id = :id LIMIT 1';
            
            // $stmt = $pdo->prepare($sql);
            // $stmt->bindValue(':id', $_SESSION['user_id'], PDO::PARAM_STR);
            // $stmt->execute();
            return [];
        }

        // alguns fluxos (ex: login com Google) já criam a linha em idioma_referencia
        // (com idioma_nativo/idioma_aprender NULL) antes desse passo. Usa upsert
        // atômico (INSERT ... ON DUPLICATE KEY UPDATE, com UNIQUE KEY em id_user)
        // em vez de "SELECT existe? -> decide" -- essa segunda forma tem uma
        // brecha de corrida (duas requisições quase simultâneas podem ver "não
        // existe" ao mesmo tempo e ambas inserirem, duplicando a linha).
        $sql = "
            INSERT INTO idioma_referencia (idioma_nativo, id_user)
            VALUES (:idioma_nativo, :id_user)
            ON DUPLICATE KEY UPDATE idioma_nativo = VALUES(idioma_nativo)
        ";

        $stmt = $pdo->prepare($sql);
        $stmt->bindValue(':idioma_nativo', $this->idioma_nativo, PDO::PARAM_INT);
        $stmt->bindValue(':id_user', $user_id, PDO::PARAM_INT);
        $stmt->execute();

        // Categoria automática única foi substituída pela escolha de 3
        // categorias de interesse no onboarding (EscolherCategoriasInteresse.jsx
        // + CategoriaIA::criarParaOnboarding) - não cadastra mais nada aqui.

        $sql = 'UPDATE usuarios SET step = 1 WHERE id = :id LIMIT 1';
        $stmt = $pdo->prepare($sql);
        $stmt->bindValue(':id', $user_id, PDO::PARAM_INT);
        $stmt->execute();

        $_SESSION['step'] = 1;


        $sql = "SELECT sigla FROM idiomas WHERE id = :idioma_id LIMIT 1";

        $stmt = $pdo->prepare($sql);
        $stmt->bindValue(':idioma_id', $this->idioma_nativo, PDO::PARAM_INT);
        $stmt->execute();

        $result = $stmt->fetch();

        $_SESSION['native_language'] = $result['sigla'];

        return [
            'success' => true,
            'message' => 'Idioma inserido com sucesso',
            'id' => (int) $pdo->lastInsertId()
        ];

    }

    public function setIdiomaAprender($user_id): array
    {

        //  print_r($_SESSION);exit;
        global $pdo; // 👈 precisa disso

        $sql = "
            SELECT id
            FROM usuarios
            WHERE step > 1 AND step < 3 AND id = :id
        ";

        $stmt = $pdo->prepare($sql);
        $stmt->bindValue(':id', $user_id, PDO::PARAM_STR);
        $stmt->execute();

        $result = $stmt->fetch();

        if (!empty($result['id'])) {
            // $sql = 'UPDATE FROM idioma_referencia SET idioma_nativo = :idioma_nativo WHERE id = :id LIMIT 1';
            
            // $stmt = $pdo->prepare($sql);
            // $stmt->bindValue(':id', $_SESSION['user_id'], PDO::PARAM_STR);
            // $stmt->execute();
            return [];
        }

        $sql = 'UPDATE idioma_referencia SET idioma_aprender = :idioma_aprender 
        WHERE id_user = :id_user AND idioma_nativo > 0 LIMIT 1';

        $stmt = $pdo->prepare($sql);
        $stmt->bindValue(':idioma_aprender', $this->idioma_aprender, PDO::PARAM_INT);
        $stmt->bindValue(':id_user', $user_id, PDO::PARAM_INT);
        $stmt->execute();

        $sql = 'UPDATE usuarios SET step = 2 WHERE id = :id LIMIT 1';
        $stmt = $pdo->prepare($sql);
        $stmt->bindValue(':id', $user_id, PDO::PARAM_INT);
        $stmt->execute();

        $_SESSION['step'] = 2;

        $sql = "SELECT sigla FROM idiomas WHERE id = :idioma_id LIMIT 1";

        $stmt = $pdo->prepare($sql);
        $stmt->bindValue(':idioma_id', $this->idioma_aprender, PDO::PARAM_INT);
        $stmt->execute();

        $result = $stmt->fetch();

        $_SESSION['learning_language'] =  $result['sigla'];

        return [
            'success' => true,
            'message' => 'Idioma inserido com sucesso',
            'id' => (int) $pdo->lastInsertId()
        ];

    }


    public static function buscarPorId(int $id): ?array
    {

        global $pdo; 

        $sql = "SELECT * FROM idiomas WHERE id = :id LIMIT 1";

        $stmt = $pdo->prepare($sql);
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);
        $stmt->execute();

        $resultado = $stmt->fetch();

        return $resultado ?: null;

    }

    public function setIdiomaReferencia($user_id): array
    {
        return $this->atualizarReferenciaAprendizado($user_id);
    }

    public function atualizarReferenciaAprendizado($user_id): array
    {
        global $pdo;

        $sql = "UPDATE idioma_referencia
                SET idioma_aprender = :idioma_aprender";

        if ($this->idioma_nativo !== null) {
            $sql .= ", idioma_nativo = :idioma_nativo";
        }

        $sql .= " WHERE id_user = :id_user LIMIT 1";

        $stmt = $pdo->prepare($sql);
        $stmt->bindValue(':idioma_aprender', $this->idioma_aprender, PDO::PARAM_INT);

        if ($this->idioma_nativo !== null) {
            $stmt->bindValue(':idioma_nativo', $this->idioma_nativo, PDO::PARAM_INT);
        }

        $stmt->bindValue(':id_user', $user_id, PDO::PARAM_INT);
        $stmt->execute();

        $sql = "SELECT sigla FROM idiomas WHERE id = :idioma_id LIMIT 1";

        $stmt = $pdo->prepare($sql);
        $stmt->bindValue(':idioma_id', $this->idioma_aprender, PDO::PARAM_INT);
        $stmt->execute();

        $resultado = $stmt->fetch();

        if (!empty($resultado['sigla'])) {
            $_SESSION['learning_language'] = $resultado['sigla'];
        }

        return [
            'success' => true,
            'message' => 'Idioma atualizado com sucesso'
        ];
    }

}