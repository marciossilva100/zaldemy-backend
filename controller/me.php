<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

$allowedOrigins = [
    "http://localhost:5173",
    "https://zaldemy.com",
    "https://www.zaldemy.com",
    "https://www.hml.zaldemy.com",
    "https://hml.zaldemy.com",
    "https://memly-jijk.vercel.app"
];

if (isset($_SERVER['HTTP_ORIGIN']) && in_array($_SERVER['HTTP_ORIGIN'], $allowedOrigins)) {
    header("Access-Control-Allow-Origin: " . $_SERVER['HTTP_ORIGIN']);
}

header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Content-Type: application/json");

// ======================
// Pegar token do header
// ======================

// $headers = getallheaders();

// if (!isset($headers['Authorization'])) {
//     echo json_encode([
//         "authenticated" => false
//     ]);
//     exit;
// }

$authHeader = null;

if (isset($_SERVER['HTTP_AUTHORIZATION'])) {
    $authHeader = $_SERVER['HTTP_AUTHORIZATION'];
} elseif (isset($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
    $authHeader = $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
}

if (!$authHeader) {
    echo json_encode([
        "authenticated" => false
    ]);
    exit;
}

$token = str_replace("Bearer ", "", $authHeader);

// $token = str_replace("Bearer ", "", $headers['Authorization']);

require_once '../server.php';

try {

    $stmt = $pdo->prepare("
        SELECT id, nome, email, step,plano
        FROM usuarios 
        WHERE auth_token = :token 
        LIMIT 1
    ");

    $stmt->bindParam(":token", $token, PDO::PARAM_STR);
    $stmt->execute();

    $usuario = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$usuario) {
        echo json_encode([
            "authenticated" => false
        ]);
        exit;
    }

    // Buscar idiomas do usuário
    $stmt = $pdo->prepare("SELECT 
        I1.sigla AS idioma_aprender,
        I2.sigla AS idioma_nativo
        FROM idioma_referencia IR
        LEFT JOIN idiomas I1 ON I1.id = IR.idioma_aprender
        LEFT JOIN idiomas I2 ON I2.id = IR.idioma_nativo
        WHERE IR.id_user = :id_user
        LIMIT 1
    ");

    //print_r('teste');

    $stmt->bindParam(":id_user", $usuario['id'], PDO::PARAM_INT);
    $stmt->execute();

    $idioma_referencia = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$idioma_referencia) {
        $idioma_referencia = [
            "idioma_nativo" => null,
            "idioma_aprender" => null
        ];
    }

    echo json_encode([
        "authenticated" => true,
        "user" => [
            "id" => $usuario['id'],
            "name" => $usuario['nome'],
            "email" => $usuario['email'],
            "step" =>  $usuario['step'] ?? null,
            "plano" => $usuario['plano'] ?? null,
            "native_language" => $idioma_referencia['idioma_nativo'] ?? null,
            "learning_language" => $idioma_referencia['idioma_aprender'] ?? null
        ]
    ]);

} catch (Throwable $e) {

    http_response_code(500);

    echo json_encode([
        "erro_real" => $e->getMessage()
    ]);

}