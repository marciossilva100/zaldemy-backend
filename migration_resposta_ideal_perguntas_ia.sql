-- Executar manualmente no banco de produção/homologação antes do deploy da
-- exibição da "resposta ideal" no feedback de Perguntas (treino de IA).
ALTER TABLE `perguntas_ia`
  ADD COLUMN `resposta_ideal` TEXT DEFAULT NULL AFTER `feedback`;
