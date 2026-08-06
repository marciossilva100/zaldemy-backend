-- Executar manualmente no banco de produção/homologação antes do deploy da
-- sugestão automática de evolução de nível (iniciante -> intermediário ->
-- avançado). Guarda o nível-alvo que o usuário já dispensou, pra não repetir
-- a mesma sugestão depois de recusada.
ALTER TABLE usuarios
    ADD COLUMN nivel_sugestao_dispensada INT DEFAULT NULL AFTER nivel;
