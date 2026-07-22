<?php
// Script temporário de DIAGNÓSTICO (não altera nada) — APAGAR depois de usar.
// Investiga por que a categoria/frases padrão às vezes não aparece ao trocar
// de idioma no Header.

require_once '../server.php';

header('Content-Type: application/json; charset=utf-8');

$chave = $_GET['chave'] ?? '';

if ($chave !== 'JISoufSCtCQCuUMP-ApoZ6cMTMxzomEi') {
    http_response_code(403);
    echo json_encode(['erro' => 'Não autorizado']);
    exit;
}

try {
    $resultado = [];

    // 1) Categorias-modelo (tipo=2) com status_id <= 0 ou public <= 0 -- essas
    // ficam invisíveis (status_id) ou sem frases copiadas (public) na hora do cadastro.
    $stmt = $pdo->query("
        SELECT c.id, c.categoria, c.public, c.status_id,
               n.sigla AS nativo, a.sigla AS aprendendo
        FROM categorias c
        LEFT JOIN idiomas n ON n.id = c.idioma_nativo
        LEFT JOIN idiomas a ON a.id = c.idioma_aprendendo
        WHERE c.tipo = 2
        AND (c.status_id <= 0 OR c.public <= 0)
        ORDER BY c.idioma_nativo, c.idioma_aprendendo
    ");
    $resultado['templates_com_problema'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // 2) Pares (nativo, aprendendo) que deveriam ter categoria-modelo mas não tem
    // nenhuma linha tipo=2 válida (status_id>0) pra esse par.
    $stmt = $pdo->query("SELECT id, sigla FROM idiomas ORDER BY id");
    $idiomas = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $stmt = $pdo->query("
        SELECT idioma_nativo, idioma_aprendendo
        FROM categorias
        WHERE tipo = 2 AND status_id > 0
    ");
    $paresValidos = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $paresValidos[$row['idioma_nativo'] . '-' . $row['idioma_aprendendo']] = true;
    }

    $paresFaltando = [];
    foreach ($idiomas as $n) {
        foreach ($idiomas as $a) {
            if ($n['id'] == $a['id']) continue;
            $chavePar = $n['id'] . '-' . $a['id'];
            if (!isset($paresValidos[$chavePar])) {
                $paresFaltando[] = $n['sigla'] . ' -> ' . $a['sigla'];
            }
        }
    }
    $resultado['pares_sem_categoria_modelo'] = $paresFaltando;

    // 3) Quantos usuários têm mais de uma linha em idioma_referencia (deveria ser 1 por usuário)
    $stmt = $pdo->query("
        SELECT id_user, COUNT(*) AS total
        FROM idioma_referencia
        GROUP BY id_user
        HAVING COUNT(*) > 1
    ");
    $resultado['usuarios_com_idioma_referencia_duplicada'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode(['success' => true, 'resultado' => $resultado], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'erro' => $e->getMessage()]);
}
