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
    "https://memly-jijk.vercel.app",
    "https://localhost", // app nativo Android/iOS via Capacitor
    "capacitor://localhost" // WKWebView do Capacitor no iOS
];

if (isset($_SERVER['HTTP_ORIGIN']) && in_array($_SERVER['HTTP_ORIGIN'], $allowedOrigins)) {
    header("Access-Control-Allow-Origin: " . $_SERVER['HTTP_ORIGIN']);
}

header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Content-Type: application/json");
// Hospedagem (LiteSpeed/Hostinger) pode cachear GET sem Cache-Control - essa
// resposta é por usuário autenticado e muda a qualquer momento (ex: plano
// virando premium), nunca pode ser servida em cache por proxy/CDN/navegador.
header("Cache-Control: no-store, no-cache, must-revalidate");
header("Pragma: no-cache");

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
require_once '../model/CategoriaIA.php';
require_once '../model/Nivel.php';

try {

    $stmt = $pdo->prepare("
        SELECT id, nome, email, apelido, apelido_definido_pelo_usuario, foto_perfil, step, plano, nivel, interesses_definidos, guia_categoria_dispensado, guia_treino_dispensado, assinatura_cancelamento_previsto
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
        I2.sigla AS idioma_nativo,
        I2.idioma AS idioma_nativo_nome
        FROM idioma_referencia IR
        LEFT JOIN idiomas I1 ON I1.id = IR.idioma_aprender
        LEFT JOIN idiomas I2 ON I2.id = IR.idioma_nativo
        WHERE IR.id_user = :id_user
        AND IR.idioma_nativo > 0
        AND IR.idioma_aprender > 0
        LIMIT 1
    ");

    //print_r('teste');

    $stmt->bindParam(":id_user", $usuario['id'], PDO::PARAM_INT);
    $stmt->execute();

    $idioma_referencia = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$idioma_referencia) {
        $idioma_referencia = [
            "idioma_nativo" => null,
            "idioma_nativo_nome" => null,
            "idioma_aprender" => null
        ];
    }

    // Usado pra decidir, ANTES de abrir o formulário, se mostra a opção
    // "Criar categoria com IA" normal, com a coroa de premium, ou com o
    // aviso de cota esgotada - sem isso, quem já usou a amostra grátis
    // (limitado) ou a cota diária (premium) só descobria que não tinha mais
    // acesso depois de preencher o formulário e tentar enviar.
    //
    // $bloqueioCategoriaIA distingue os dois motivos de bloqueio possíveis
    // (mesmo shape de CategoriaIA::verificarAcesso): "premium_necessario"
    // (free, nunca teve acesso - mostra a coroa e o PremiumModal completo)
    // vs "limite_atingido" (premium ou limitado que já usaram a cota de
    // hoje/a amostra vitalícia - só um aviso enxuto, sem coroa nem
    // PremiumModal, já que quem já paga não precisa de vitrine de upsell -
    // reportado pelo usuário premium vendo a coroa indevidamente).
    $bloqueioCategoriaIA = CategoriaIA::verificarAcesso($pdo, (int) $usuario['id'], (int) ($usuario['plano'] ?? 0));
    $categoriaIaDisponivel = $bloqueioCategoriaIA === null;
    $nivelSugerido = Nivel::sugestaoPromocao($pdo, (int) $usuario['id']);

    echo json_encode([
        "authenticated" => true,
        "user" => [
            "id" => $usuario['id'],
            "name" => $usuario['nome'],
            "email" => $usuario['email'],
            "apelido" => $usuario['apelido'],
            "apelido_definido_pelo_usuario" => (bool) ($usuario['apelido_definido_pelo_usuario'] ?? false),
            "foto_perfil" => $usuario['foto_perfil'] ?? null,
            "step" =>  $usuario['step'] ?? null,
            "plano" => $usuario['plano'] ?? null,
            "nivel" => $usuario['nivel'] ?? null,
            "nivel_sugerido" => $nivelSugerido,
            "interesses_definidos" => (bool) ($usuario['interesses_definidos'] ?? false),
            "guia_categoria_dispensado" => (bool) ($usuario['guia_categoria_dispensado'] ?? false),
            "guia_treino_dispensado" => (bool) ($usuario['guia_treino_dispensado'] ?? false),
            "categoria_ia_disponivel" => $categoriaIaDisponivel,
            "categoria_ia_bloqueio" => $bloqueioCategoriaIA,
            "assinatura_cancelamento_previsto" => $usuario['assinatura_cancelamento_previsto'] ?? null,
            "native_language" => $idioma_referencia['idioma_nativo'] ?? null,
            // Nome do idioma nativo (ex: "Português", "Inglês") - usado no
            // front pra montar dinamicamente o rótulo "Palavra ou frase em
            // {idioma}" no ModalFrase, que antes ficava fixo em português
            // mesmo pra quem não tem português como nativo.
            "native_language_name" => $idioma_referencia['idioma_nativo_nome'] ?? null,
            "learning_language" => $idioma_referencia['idioma_aprender'] ?? null
        ]
    ]);

} catch (Throwable $e) {

    http_response_code(500);

    echo json_encode([
        "erro_real" => $e->getMessage()
    ]);

}