-- Executar manualmente no banco de produção/homologação.
--
-- Guarda a(s) categoria(s) escolhidas à mão pelo aluno junto com cada
-- geração dos 3 treinos de IA - o backend passa a lembrar essa escolha
-- pro RESTO DO DIA (mesmo se o frontend, por qualquer motivo, deixar de
-- reenviar category_ids numa geração seguinte - ex: app suspenso/recarregado
-- pelo SO em segundo plano sem o usuário perceber, único cenário que
-- reproduzia o vazamento de categoria mesmo com todo o fluxo do frontend já
-- corrigido). Sem essa memória no servidor, uma geração sem category_ids
-- caía pro sorteio automático entre TODAS as categorias do aluno.
ALTER TABLE `perguntas_ia`
  ADD COLUMN `categoria_ids_escolhidos` VARCHAR(50) DEFAULT NULL AFTER `idioma_aprender`;

ALTER TABLE `frase_dia_ia`
  ADD COLUMN `categoria_ids_escolhidos` VARCHAR(50) DEFAULT NULL AFTER `idioma_aprender`;

ALTER TABLE `traducao_reversa_ia`
  ADD COLUMN `categoria_ids_escolhidos` VARCHAR(50) DEFAULT NULL AFTER `idioma_aprender`;
