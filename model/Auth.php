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
                VALUES (:nome, :email, 1, 2)"
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
           // $this->cadastrarCategoriaFrases($userId);

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

        $categoria_id = 43;
        $pdo = $this->pdo;


            // Agora retorna frases já com a URL do áudio
            $categoria = Categorias::getById($pdo,$categoria_id);
    
            $categoria_id_usuario = Categorias::cadastrarCategoria($pdo,$categoria,$user_id,$categoria_id);

            $frases = Categorias::getAllFrases($pdo,$categoria_id);
            $response = Categorias::addFrases($pdo,$user_id, $frases,$categoria_id_usuario['id']);
            return;
            
    }


}