<?php

$allowedOrigins = [
    "http://localhost:5173",
    "https://zaldemy.com",
    "https://www.zaldemy.com",
    "https://hml.zaldemy.com",
    "https://www.hml.zaldemy.com",
    "https://memly-jijk.vercel.app",
    "https://localhost", // app nativo Android/iOS via Capacitor
    "capacitor://localhost" // WKWebView do Capacitor no iOS
];

if (isset($_SERVER['HTTP_ORIGIN']) && in_array($_SERVER['HTTP_ORIGIN'], $allowedOrigins)) {
    header("Access-Control-Allow-Origin: " . $_SERVER['HTTP_ORIGIN']);
}

header("Access-Control-Allow-Methods: GET, POST, OPTIONS");

// 🔥 pega headers automaticamente (resolve 99% dos CORS)
if (isset($_SERVER['HTTP_ACCESS_CONTROL_REQUEST_HEADERS'])) {
    header("Access-Control-Allow-Headers: " . $_SERVER['HTTP_ACCESS_CONTROL_REQUEST_HEADERS']);
} else {
    header("Access-Control-Allow-Headers: Content-Type, Authorization");
}

header("Access-Control-Allow-Credentials: true");
header("Content-Type: application/json; charset=utf-8");
header("Vary: Origin");

// 🔥 responde preflight imediatamente
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'secure' => true,
    'httponly' => true,
    'samesite' => 'None'
]);

session_start();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(["erro" => "Método não permitido"]);
    exit;
}

$input = file_get_contents("php://input");
$data = json_decode($input, true);

if (!is_array($data)) {
    http_response_code(400);
    echo json_encode(["erro" => "JSON inválido"]);
    exit;
}

$action = isset($data['action']) ? $data['action'] : null;

if (!$action) {
    http_response_code(400);
    echo json_encode(["erro" => "Ação não informada"]);
    exit;
}



require_once '../server.php';
require_once 'email.php';
require_once '../model/Auth.php';

try {

    // =====================================================
    // REGISTER
    // =====================================================
    if ($action === 'register') {

        $nome     = isset($data['name']) ? trim($data['name']) : '';
        $email    = isset($data['email']) ? trim($data['email']) : '';
        $password = isset($data['password']) ? $data['password'] : '';
        $confirm_password = isset($data['confirm_password']) ? $data['confirm_password'] : '';

        $erros = [];

        if ($nome === '' || strlen($nome) > 50) {
            $erros[] = "Nome inválido (máx. 50 caracteres)";
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $erros[] = "Email inválido";
        }

        if (strlen($password) < 6) {
            $erros[] = "Senha deve ter no mínimo 6 caracteres";
        }

        if ($password !== $confirm_password) {
            $erros[] = "As senhas não coincidem";
        }

        if (!empty($erros)) {
            http_response_code(422);
            echo json_encode(["erro" => $erros]);
            exit;
        }

        $stmt = $pdo->prepare("SELECT id FROM usuarios WHERE email = :email LIMIT 1");
        $stmt->bindParam(":email", $email, PDO::PARAM_STR);
        $stmt->execute();

        if ($stmt->fetch()) {
            http_response_code(409);
            echo json_encode(["erro" => "Email já cadastrado"]);
            exit;
        }

        $hash = password_hash($password, PASSWORD_DEFAULT);

        $token = bin2hex(random_bytes(32));

        $stmt = $pdo->prepare("
            INSERT INTO usuarios (nome, email, password, email_token, plano)
            VALUES (:nome, :email, :password, :token, 1)
        ");

        $stmt->bindParam(":nome", $nome, PDO::PARAM_STR);
        $stmt->bindParam(":email", $email, PDO::PARAM_STR);
        $stmt->bindParam(":password", $hash, PDO::PARAM_STR);
        $stmt->bindParam(":token", $token, PDO::PARAM_STR);
        $stmt->execute();

        $user_id = $pdo->lastInsertId();

        enviarEmailVerificacao($email, $token);



        echo json_encode([
            "sucesso" => true,
            "mensagem" => "Usuário cadastrado. Verifique seu email."
        ]);
        exit;
    }

    // =====================================================
    // LOGIN
    // =====================================================
    if ($action === 'login') {

        $email    = isset($data['email']) ? trim($data['email']) : '';
        $password = isset($data['password']) ? $data['password'] : '';

        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || $password === '') {
            http_response_code(422);
            echo json_encode(["erro" => "Credenciais inválidas"]);
            exit;
        }

        $stmt = $pdo->prepare("SELECT id, nome, email,email_verified, `password`,step FROM usuarios WHERE email = :email LIMIT 1");
        $stmt->bindParam(":email", $email, PDO::PARAM_STR);
        $stmt->execute();

        $usuario = $stmt->fetch();

        if (!$usuario || !password_verify($password, $usuario['password'])) {
            http_response_code(401);
            echo json_encode(["erro" => "Email ou senha incorretos"]);
            exit;
        }

        if ($usuario['email_verified'] == 0) {
            http_response_code(403);
            echo json_encode([
                "erro" => "Confirme seu email antes de entrar"
            ]);
            exit;
        }

        $stmt = $pdo->prepare("SELECT 
            I1.sigla AS idioma_aprender,
            I2.sigla AS idioma_nativo
            FROM idioma_referencia IR
            LEFT JOIN idiomas I1 ON I1.id = IR.idioma_aprender
            LEFT JOIN idiomas I2 ON I2.id = IR.idioma_nativo
            WHERE IR.id_user = :id_user
            LIMIT 1");
            
        $stmt->bindParam(":id_user", $usuario['id'], PDO::PARAM_STR);
        $stmt->execute();

        $idioma_referencia = $stmt->fetch();

        // ===============================
        // GERAR TOKEN
        // ===============================

        $auth_token = bin2hex(random_bytes(32));

        $stmt = $pdo->prepare("
            UPDATE usuarios 
            SET auth_token = :token 
            WHERE id = :id
        ");

        $stmt->bindParam(":token", $auth_token, PDO::PARAM_STR);
        $stmt->bindParam(":id", $usuario['id'], PDO::PARAM_INT);
        $stmt->execute();

        unset($usuario['password']);

        echo json_encode([
            "sucesso" => true,
            "token" => $auth_token,
            "usuario" => $usuario
        ]);

        exit;
    }


    if ($action === "login_google") {

        if (!isset($data['token'])) {
            echo json_encode([
                "success" => false,
                "message" => "Token não enviado"
            ]);
            exit;
        }

        $auth = new Auth($pdo);

        $result = $auth->loginGoogle($data['token']);

        echo json_encode($result);
        exit;
    }

    http_response_code(400);
    echo json_encode(["erro" => "Ação inválida"]);
    exit;

} catch (Throwable $e) {

    http_response_code(500);

    echo json_encode([
        "erro_real" => $e->getMessage()
    ]);

    exit;
}