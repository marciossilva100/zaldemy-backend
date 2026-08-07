<?php

$allowedOrigins = [
    "http://localhost:5173",
    "https://zaldemy.com",
    "https://www.zaldemy.com",
    "https://www.hml.zaldemy.com",
    "https://hml.zaldemy.com",
    "https://memly-jijk.vercel.app",
    "https://localhost", // app nativo Android/iOS via Capacitor
    "capacitor://localhost" // WKWebView do Capacitor no iOS
];

if (isset($_SERVER['HTTP_ORIGIN']) && in_array($_SERVER['HTTP_ORIGIN'], $allowedOrigins)) {
    header("Access-Control-Allow-Origin: " . $_SERVER['HTTP_ORIGIN']);
}

header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once '../server.php';
require_once 'authMiddleware.php';
require_once '../model/Perfil.php';

header('Content-Type: application/json');

// Upload de foto vem como multipart/form-data (não JSON), então a action
// chega em $_POST, não no corpo JSON como no resto dos controllers.
$action = $_POST['action'] ?? null;

try {
    if ($action === 'upload_foto') {
        if (empty($_FILES['foto'])) {
            http_response_code(400);
            echo json_encode(["success" => false, "message" => "Nenhuma imagem enviada."]);
            exit;
        }

        $resultado = Perfil::uploadFoto($pdo, $user_id, $_FILES['foto']);
        echo json_encode($resultado);
        exit;
    }

    if ($action === 'remover_foto') {
        $resultado = Perfil::removerFoto($pdo, $user_id);
        echo json_encode($resultado);
        exit;
    }

    http_response_code(400);
    echo json_encode(["success" => false, "message" => "Action inválida"]);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        "success" => false,
        "message" => $e->getMessage(),
    ]);
}
