<?php
date_default_timezone_set('America/Sao_Paulo');

// Permite sobrescrever a conexão via .env (não versionado) pra rodar num
// banco local sem tocar nas credenciais de produção abaixo, que continuam
// sendo o fallback padrão se as variáveis não existirem no .env.
require_once __DIR__ . '/dotenv.php';
carregarEnv(__DIR__ . '/.env');

$host = $_ENV['DB_HOST'] ?? "localhost";
$port = $_ENV['DB_PORT'] ?? "3306";
$db   = $_ENV['DB_DATABASE'] ?? "u712858045_zaldemy";
$user = $_ENV['DB_USERNAME'] ?? "u712858045_mhp";
$pass = $_ENV['DB_PASSWORD'] ?? "u2Kd@Hl@0z&";

try {
    $pdo = new PDO(
        "mysql:host=$host;port=$port;dbname=$db;charset=utf8mb4",
        $user,
        $pass,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
        ]
    );
    $pdo->exec("SET time_zone = '-03:00'");
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        "error" => $e->getMessage()
    ]);
    exit;
}
