<?php
// Script temporário — APAGAR após rodar uma vez.
// Corrige duas categorias-modelo (tipo = 2) com dados incorretos que impediam
// o cadastro automático de categoria+frases pra novos usuários / troca de idioma:
//   - id 43 ("Filmes", pt->en): status_id estava 0, ficando invisível pra busca
//     em Idioma::cadastrarCategoriaFrases (que filtra status_id > 0).
//   - 13 categorias "Viagens" (pt->outros idiomas): public estava 0, então
//     Categorias::getAllFrases (que filtra c.public > 0) não copiava as frases.

require_once '../server.php';

header('Content-Type: application/json; charset=utf-8');

$chave = $_GET['chave'] ?? '';

if ($chave !== '890bSrFqkVB-nfwX10jAQyot1-v4TSEr') {
    http_response_code(403);
    echo json_encode(['erro' => 'Não autorizado']);
    exit;
}

try {
    $pdo->beginTransaction();

    $stmt1 = $pdo->prepare("UPDATE categorias SET status_id = 1 WHERE id = 43 AND tipo = 2");
    $stmt1->execute();
    $categoriaFilmesCorrigida = $stmt1->rowCount();

    $stmt2 = $pdo->prepare("UPDATE categorias SET public = 1 WHERE tipo = 2 AND public = 0");
    $stmt2->execute();
    $categoriasViagensCorrigidas = $stmt2->rowCount();

    $pdo->commit();

    echo json_encode([
        'success' => true,
        'categoria_filmes_corrigida' => $categoriaFilmesCorrigida,
        'categorias_viagens_corrigidas' => $categoriasViagensCorrigidas,
    ]);
} catch (Throwable $e) {
    $pdo->rollBack();
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'erro' => $e->getMessage(),
    ]);
}
