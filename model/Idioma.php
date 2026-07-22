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
            $sql .=" WHERE id <> (SELECT idioma_nativo FROM idioma_referencia WHERE id_user = :id_user AND idioma_nativo IS NOT NULL LIMIT 1)";
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

        $sql = "INSERT INTO idioma_referencia (idioma_nativo,id_user) VALUES (:idioma_nativo,:id_user)";

        $stmt = $pdo->prepare($sql);
        $stmt->bindValue(':idioma_nativo', $this->idioma_nativo, PDO::PARAM_INT);
        $stmt->bindValue(':id_user', $user_id, PDO::PARAM_INT);
        $stmt->execute();


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

      //  print_r($this->idioma_aprender);

      $this->cadastrarCategoriaFrases($user_id);


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


    public function cadastrarCategoriaFrases($user_id){

        global $pdo;

        // busca idioma_nativo e idioma_aprender do usuário
        $stmt = $pdo->prepare("
            SELECT idioma_nativo, idioma_aprender
            FROM idioma_referencia
            WHERE id_user = :id_user
            AND idioma_nativo > 0
            AND idioma_aprender > 0
            LIMIT 1
        ");
        $stmt->bindValue(':id_user', $user_id, PDO::PARAM_INT);
        $stmt->execute();

        $idiomaReferencia = $stmt->fetch(PDO::FETCH_ASSOC);

        $idiomaNativo = $idiomaReferencia['idioma_nativo'] ?? null;
        $idiomaAprender = $idiomaReferencia['idioma_aprender'] ?? null;

        // busca a categoria padrão (tipo = 2) correspondente ao par de idiomas
        $stmt = $pdo->prepare("
            SELECT id, categoria
            FROM categorias
            WHERE idioma_nativo = :idioma_nativo
            AND idioma_aprendendo = :idioma_aprendendo
            AND tipo = 2
            AND status_id > 0
            LIMIT 1
        ");
        $stmt->bindValue(':idioma_nativo', $idiomaNativo, $idiomaNativo === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
        $stmt->bindValue(':idioma_aprendendo', $idiomaAprender, $idiomaAprender === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
        $stmt->execute();

        $categoriaEncontrada = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$categoriaEncontrada) {
            return;
        }

        $categoria_id = (int) $categoriaEncontrada['id'];
        $categoria = $categoriaEncontrada['categoria'];

        $categoria_id_usuario = Categorias::cadastrarCategoria($pdo,$categoria,$user_id,$categoria_id);

        $frases = Categorias::getAllFrases($pdo,$categoria_id);
        $response = Categorias::addFrases($pdo,$user_id, $frases,$categoria_id_usuario['id']);
        return;

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

        $stmt = $pdo->prepare("SELECT idioma_nativo, idioma_aprender FROM idioma_referencia WHERE id_user = :id_user LIMIT 1");
        $stmt->bindValue(':id_user', $user_id, PDO::PARAM_INT);
        $stmt->execute();
        $referenciaAtual = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

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

        // só recadastra a categoria/frases padrão se o par de idiomas realmente mudou
        $idiomaNativoNovo = $this->idioma_nativo ?? ($referenciaAtual['idioma_nativo'] ?? null);
        $parIdiomasMudou = (int) ($referenciaAtual['idioma_aprender'] ?? 0) !== (int) $this->idioma_aprender
            || (int) ($referenciaAtual['idioma_nativo'] ?? 0) !== (int) $idiomaNativoNovo;

        if ($parIdiomasMudou) {
            $this->cadastrarCategoriaFrases($user_id);
        }


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