<?php
date_default_timezone_set('America/Sao_Paulo');
require_once 'Categorias.php';

class Auth {

    private $pdo;

    public function __construct($pdo) {
        $this->pdo = $pdo;
    }

    public function getUserByToken($token) {

        $stmt = $this->pdo->prepare(
            "SELECT id, nome, email 
             FROM usuarios 
             WHERE auth_token = :token 
             LIMIT 1"
        );

        $stmt->bindParam(":token", $token);
        $stmt->execute();

        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    public function loginGoogle($googleToken) {

        // endpoint correto para access_token
        $url = "https://www.googleapis.com/oauth2/v1/userinfo?access_token=" . $googleToken;

        $response = file_get_contents($url);

        if (!$response) {
            return [
                "sucesso" => false,
                "erro" => "Erro ao validar token"
            ];
        }

        $google = json_decode($response);

        if (!$google || !isset($google->email)) {
            return [
                "sucesso" => false,
                "erro" => "Token inválido"
            ];
        }

        $email = $google->email;
        $nome  = $google->name ?? '';
        $foto  = $google->picture ?? null;

        // verifica se usuário existe
        $stmt = $this->pdo->prepare(
            "SELECT id, step FROM usuarios WHERE email = :email LIMIT 1"
        );

        $stmt->bindParam(":email", $email);
        $stmt->execute();

        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$user) {

            // cria usuário automaticamente
            $stmt = $this->pdo->prepare(
                "INSERT INTO usuarios 
                (nome, email, email_verified, plano) 
                VALUES (:nome, :email, 1, 1)"
            );

            $stmt->bindParam(":nome", $nome);
            $stmt->bindParam(":email", $email);
            $stmt->execute();

            $userId = $this->pdo->lastInsertId();

            // 🔥 CRIAR REGISTRO DE IDIOMA (ESSENCIAL)
            $stmt = $this->pdo->prepare("
                INSERT INTO idioma_referencia (id_user, idioma_nativo, idioma_aprender)
                VALUES (:user, NULL, NULL)
            ");
            $stmt->bindParam(":user", $userId);
            $stmt->execute();

            // já existente
           $this->cadastrarCategoriaFrases($userId);

        } else {

            $userId = $user['id'];
        }

        // gerar token de sessão
        $authToken = bin2hex(random_bytes(32));

        $stmt = $this->pdo->prepare(
            "UPDATE usuarios 
            SET auth_token = :token 
            WHERE id = :id"
        );

        $stmt->bindParam(":token", $authToken);
        $stmt->bindParam(":id", $userId);
        $stmt->execute();

        return [
            "sucesso" => true,
            "token" => $authToken,
            "user_id" => $userId,
            "email" => $email,
            "nome" => $nome,
            "foto" => $foto,
            "step" => $user['step']
        ];
    }

    public function cadastrarCategoriaFrases($user_id){

        $pdo = $this->pdo;

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

}