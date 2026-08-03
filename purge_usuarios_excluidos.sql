-- Apaga PERMANENTEMENTE todos os dados de usuários que passaram pelo fluxo
-- de exclusão de conta (Auth::excluirConta - status_id=0, e-mail anonimizado
-- como excluido_<id>_<timestamp>@zaldemy.local).
--
-- Filtra pelo padrão de e-mail, não só por status_id=0, porque nem toda conta
-- com status_id=0 é uma conta excluída (ex: contas administrativas
-- desativadas por outro motivo) - rodar só com status_id=0 arriscaria apagar
-- dados de contas que não deveriam ser tocadas.
--
-- Rodar dentro de uma transação e conferir o SELECT antes de dar COMMIT.

START TRANSACTION;

-- Conferir quem vai ser afetado antes de seguir:
-- SELECT id, email FROM usuarios WHERE status_id = 0 AND email LIKE 'excluido\_%@zaldemy.local';

CREATE TEMPORARY TABLE ids_excluidos AS
SELECT id FROM usuarios
WHERE status_id = 0
AND email LIKE 'excluido\_%@zaldemy.local';

-- 1) treino_data_atualizacao não tem user_id direto - via frases do usuário
DELETE t FROM treino_data_atualizacao t
INNER JOIN frases f ON f.id = t.id_frase
WHERE f.usuario_id IN (SELECT id FROM ids_excluidos);

-- 2) metricas referencia frases - apaga antes de apagar as frases
DELETE FROM metricas WHERE user_id IN (SELECT id FROM ids_excluidos);

-- 3) idioma_referencia referencia frases (id_frase) - apaga antes das frases
DELETE FROM idioma_referencia WHERE id_user IN (SELECT id FROM ids_excluidos);

-- 4) frases referencia categorias (categoria_id) - apaga antes das categorias
DELETE FROM frases WHERE usuario_id IN (SELECT id FROM ids_excluidos);

-- 5) categorias - só depois de não ter mais frase nenhuma apontando pra elas
DELETE FROM categorias WHERE id_user IN (SELECT id FROM ids_excluidos);

-- 6) tabelas com referência direta ao usuário, sem dependentes
DELETE FROM canal_aquisicao WHERE user_id IN (SELECT id FROM ids_excluidos);
DELETE FROM configuracoes WHERE user_id IN (SELECT id FROM ids_excluidos);
DELETE FROM frases_ia WHERE user_id IN (SELECT id FROM ids_excluidos);
DELETE FROM perguntas_ia WHERE user_id IN (SELECT id FROM ids_excluidos);
DELETE FROM acessos_usuario WHERE user_id IN (SELECT id FROM ids_excluidos);
DELETE FROM audio_ia_uso WHERE user_id IN (SELECT id FROM ids_excluidos);
DELETE FROM categoria_ia_uso WHERE user_id IN (SELECT id FROM ids_excluidos);
DELETE FROM frase_dia_ia WHERE user_id IN (SELECT id FROM ids_excluidos);
DELETE FROM traducao_ia_uso WHERE user_id IN (SELECT id FROM ids_excluidos);

-- 7) por último, os próprios usuários
DELETE FROM usuarios WHERE id IN (SELECT id FROM ids_excluidos);

DROP TEMPORARY TABLE ids_excluidos;

-- Conferir o resultado e só então: COMMIT;
-- Se algo parecer errado: ROLLBACK;
