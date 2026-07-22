<?php
// Script temporário — APAGAR após rodar uma vez.
// 1) Remove linhas duplicadas de idioma_referencia (mantém a de maior id, mais
//    recente, por usuário) -- afeta só os usuários 63,64,68,81,82,83,84.
//    Usuário 47 NUNCA é tocado (fora da lista, e a query nem abrange ele).
// 2) Adiciona UNIQUE KEY em id_user pra impedir duplicata futura.

require_once '../server.php';

header('Content-Type: application/json; charset=utf-8');

$chave = $_GET['chave'] ?? '';

if ($chave !== 'paLw5tWDRhJe6YoQn6XzaUjqOJeQeTo3') {
    http_response_code(403);
    echo json_encode(['erro' => 'Não autorizado']);
    exit;
}

$usuariosAfetados = [63, 64, 68, 81, 82, 83, 84];

// proteção extra: nunca deixa o 47 entrar nessa lista, mesmo que alguém edite o array acima
$usuariosAfetados = array_values(array_diff($usuariosAfetados, [47]));

try {
    $pdo->beginTransaction();

    $placeholders = implode(',', array_fill(0, count($usuariosAfetados), '?'));

    // mantém, por usuário, só a linha de maior id (mais recente)
    $sqlDelete = "
        DELETE ir1 FROM idioma_referencia ir1
        INNER JOIN idioma_referencia ir2
            ON ir1.id_user = ir2.id_user AND ir1.id < ir2.id
        WHERE ir1.id_user IN ($placeholders)
        AND ir1.id_user <> 47
    ";
    $stmt = $pdo->prepare($sqlDelete);
    $stmt->execute($usuariosAfetados);
    $linhasRemovidas = $stmt->rowCount();

    // confirma que não sobrou nenhuma duplicata em NENHUM usuário antes de travar a constraint
    $stmt = $pdo->query("SELECT id_user, COUNT(*) as total FROM idioma_referencia GROUP BY id_user HAVING COUNT(*) > 1");
    $duplicatasRestantes = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $constraintAdicionada = false;
    if (empty($duplicatasRestantes)) {
        $pdo->exec("ALTER TABLE idioma_referencia ADD UNIQUE KEY uk_idioma_referencia_id_user (id_user)");
        $constraintAdicionada = true;
    }

    $pdo->commit();

    echo json_encode([
        'success' => true,
        'linhas_duplicadas_removidas' => $linhasRemovidas,
        'duplicatas_restantes' => $duplicatasRestantes,
        'constraint_unique_adicionada' => $constraintAdicionada,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    $pdo->rollBack();
    http_response_code(500);
    echo json_encode(['success' => false, 'erro' => $e->getMessage()]);
}
