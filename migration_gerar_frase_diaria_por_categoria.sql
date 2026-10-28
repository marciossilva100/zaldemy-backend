-- Executar manualmente no banco de produção/homologação. Substitui o
-- controle GLOBAL de configuracoes.gerar_frase_diaria_categorias (ver
-- migration_gerar_frase_diaria_categorias.sql) por um controle POR
-- CATEGORIA - pedido do usuário: o liga/desliga fica na edição de cada
-- categoria, não mais um único interruptor pra conta inteira. A coluna
-- configuracoes.gerar_frase_diaria_categorias fica sem uso (não removida,
-- mesmo padrão de tolerância a coluna/código morto já usado nesse projeto,
-- ex: api/ElevenLabs.php).
ALTER TABLE `categorias`
  ADD COLUMN `gerar_frase_diaria` TINYINT(1) NOT NULL DEFAULT 0 AFTER `tipo`;
