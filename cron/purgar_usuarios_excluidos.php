<?php

// Apaga PERMANENTEMENTE os dados de usuários que passaram pelo fluxo de
// exclusão de conta (Auth::excluirConta - status_id=0, e-mail anonimizado
// como excluido_<id>_<timestamp>@zaldemy.local). Até aqui essa limpeza era
// manual via purge_usuarios_excluidos.sql (mantido na raiz como referência);
// este script automatiza a mesma lógica pra rodar via crontab.
// Uso: php cron/purgar_usuarios_excluidos.php
chdir(__DIR__);

require_once __DIR__ . '/../server.php';

$stmt = $pdo->query(
    "SELECT id FROM usuarios
     WHERE status_id = 0
     AND email LIKE 'excluido\\_%@zaldemy.local'"
);
$ids = $stmt->fetchAll(PDO::FETCH_COLUMN);

if (empty($ids)) {
    echo "Nenhum usuário excluído pendente de purga.\n";
    exit;
}

$placeholders = implode(',', array_fill(0, count($ids), '?'));

$pdo->beginTransaction();

try {
    // Levantado via information_schema contra o banco real (não só o que
    // purge_usuarios_excluidos.sql cobria) - várias tabelas de uso/histórico
    // tinham ficado de fora da limpeza original e seguiriam guardando dados
    // pessoais mesmo depois da "exclusão definitiva". Ordem: dependências
    // antes das tabelas que elas referenciam (perguntas_ia_duvidas antes de
    // perguntas_ia, jogo_chuva_recorde e frases antes de categorias).
    $pdo->prepare("
        DELETE t FROM treino_data_atualizacao t
        INNER JOIN frases f ON f.id = t.id_frase
        WHERE f.usuario_id IN ({$placeholders})
    ")->execute($ids);

    $pdo->prepare("DELETE FROM metricas WHERE user_id IN ({$placeholders})")->execute($ids);
    $pdo->prepare("DELETE FROM idioma_referencia WHERE id_user IN ({$placeholders})")->execute($ids);
    $pdo->prepare("DELETE FROM perguntas_ia_duvidas WHERE user_id IN ({$placeholders})")->execute($ids);
    $pdo->prepare("DELETE FROM jogo_chuva_recorde WHERE user_id IN ({$placeholders})")->execute($ids);
    $pdo->prepare("DELETE FROM frases WHERE usuario_id IN ({$placeholders})")->execute($ids);
    $pdo->prepare("DELETE FROM categorias WHERE id_user IN ({$placeholders})")->execute($ids);
    $pdo->prepare("DELETE FROM canal_aquisicao WHERE user_id IN ({$placeholders})")->execute($ids);
    $pdo->prepare("DELETE FROM configuracoes WHERE user_id IN ({$placeholders})")->execute($ids);
    $pdo->prepare("DELETE FROM frases_ia WHERE user_id IN ({$placeholders})")->execute($ids);
    $pdo->prepare("DELETE FROM perguntas_ia WHERE user_id IN ({$placeholders})")->execute($ids);
    $pdo->prepare("DELETE FROM acessos_usuario WHERE user_id IN ({$placeholders})")->execute($ids);
    $pdo->prepare("DELETE FROM categoria_ia_uso WHERE user_id IN ({$placeholders})")->execute($ids);
    $pdo->prepare("DELETE FROM frase_dia_ia WHERE user_id IN ({$placeholders})")->execute($ids);
    $pdo->prepare("DELETE FROM traducao_ia_uso WHERE user_id IN ({$placeholders})")->execute($ids);
    $pdo->prepare("DELETE FROM traducao_reversa_ia WHERE user_id IN ({$placeholders})")->execute($ids);
    $pdo->prepare("DELETE FROM audio_ia_uso WHERE user_id IN ({$placeholders})")->execute($ids);
    $pdo->prepare("DELETE FROM frases_uso_recente_ia WHERE user_id IN ({$placeholders})")->execute($ids);
    $pdo->prepare("DELETE FROM jogo_chuva_uso WHERE user_id IN ({$placeholders})")->execute($ids);
    $pdo->prepare("DELETE FROM tiro_certeiro_recorde WHERE user_id IN ({$placeholders})")->execute($ids);
    $pdo->prepare("DELETE FROM tiro_certeiro_uso WHERE user_id IN ({$placeholders})")->execute($ids);
    $pdo->prepare("DELETE FROM notificacoes_enviadas WHERE user_id IN ({$placeholders})")->execute($ids);
    $pdo->prepare("DELETE FROM push_subscriptions WHERE user_id IN ({$placeholders})")->execute($ids);
    $pdo->prepare("DELETE FROM usuarios WHERE id IN ({$placeholders})")->execute($ids);

    $pdo->commit();
    echo count($ids) . " usuário(s) excluído(s) purgado(s) permanentemente.\n";
} catch (Throwable $e) {
    $pdo->rollBack();
    fwrite(STDERR, "Erro ao purgar usuários excluídos: " . $e->getMessage() . "\n");
    exit(1);
}

// Crontab (ajustar caminho se mudar o domínio/hosting):
// 0 3 * * * php /home/u712858045/domains/zaldemy.com/public_html/api/cron/purgar_usuarios_excluidos.php >> /home/u712858045/domains/zaldemy.com/public_html/api/cron/purge.log 2>&1
