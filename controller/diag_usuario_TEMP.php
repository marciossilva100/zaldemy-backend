<?php
// Script temporário de DIAGNÓSTICO (não altera nada) — APAGAR depois de usar.
// Mostra o estado completo de um usuário específico (por email): idioma_referencia,
// categorias que ele tem, e a categoria-modelo (tipo=2) correspondente ao par atual.

require_once '../server.php';

header('Content-Type: application/json; charset=utf-8');

$chave = $_GET['chave'] ?? '';
$email = $_GET['email'] ?? '';

if ($chave !== '1MjK5FHkZnDeMzkidPo-FBMX5Jn45uoC') {
    http_response_code(403);
    echo json_encode(['erro' => 'Não autorizado']);
    exit;
}

if (!$email) {
    http_response_code(400);
    echo json_encode(['erro' => 'Informe ?email=']);
    exit;
}

try {
    $stmt = $pdo->prepare("SELECT id, nome, email, step, plano FROM usuarios WHERE email = :email LIMIT 1");
    $stmt->bindValue(':email', $email, PDO::PARAM_STR);
    $stmt->execute();
    $usuario = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$usuario) {
        echo json_encode(['erro' => 'Usuário não encontrado']);
        exit;
    }

    $userId = $usuario['id'];
    $resultado = ['usuario' => $usuario];

    // idioma_referencia atual
    $stmt = $pdo->prepare("
        SELECT ir.id, ir.idioma_nativo, ir.idioma_aprender,
               n.sigla AS nativo_sigla, a.sigla AS aprender_sigla
        FROM idioma_referencia ir
        LEFT JOIN idiomas n ON n.id = ir.idioma_nativo
        LEFT JOIN idiomas a ON a.id = ir.idioma_aprender
        WHERE ir.id_user = :id_user
    ");
    $stmt->bindValue(':id_user', $userId, PDO::PARAM_INT);
    $stmt->execute();
    $resultado['idioma_referencia'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // categorias do proprio usuario
    $stmt = $pdo->prepare("
        SELECT c.id, c.categoria, c.public, c.status_id, c.tipo, c.id_categoria_publica,
               n.sigla AS nativo_sigla, a.sigla AS aprender_sigla,
               (SELECT COUNT(*) FROM frases f WHERE f.categoria_id = c.id AND f.status_id > 0) AS total_frases
        FROM categorias c
        LEFT JOIN idiomas n ON n.id = c.idioma_nativo
        LEFT JOIN idiomas a ON a.id = c.idioma_aprendendo
        WHERE c.id_user = :id_user
        ORDER BY c.id
    ");
    $stmt->bindValue(':id_user', $userId, PDO::PARAM_INT);
    $stmt->execute();
    $resultado['categorias_do_usuario'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // categoria-modelo (tipo=2) pra cada par que aparece em idioma_referencia
    if (!empty($resultado['idioma_referencia'])) {
        $par = $resultado['idioma_referencia'][0];
        if ($par['idioma_nativo'] && $par['idioma_aprender']) {
            $stmt = $pdo->prepare("
                SELECT c.id, c.categoria, c.public, c.status_id,
                       (SELECT COUNT(*) FROM frases f WHERE f.categoria_id = c.id AND f.status_id > 0) AS total_frases
                FROM categorias c
                WHERE c.idioma_nativo = :nativo AND c.idioma_aprendendo = :aprender AND c.tipo = 2
            ");
            $stmt->bindValue(':nativo', $par['idioma_nativo'], PDO::PARAM_INT);
            $stmt->bindValue(':aprender', $par['idioma_aprender'], PDO::PARAM_INT);
            $stmt->execute();
            $resultado['categoria_modelo_do_par_atual'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
    }

    echo json_encode(['success' => true, 'resultado' => $resultado], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'erro' => $e->getMessage()]);
}
