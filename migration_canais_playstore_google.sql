-- Executar manualmente no banco de produção/homologação - os botões "Play
-- Store" e "Google" em ReferenciaUsuário.jsx nunca tiveram uma linha
-- correspondente em redes_sociais, então CanalAquisicao::registrarCanal()
-- sempre respondia "Rede social não encontrada" pra esses dois.
INSERT INTO redes_sociais (nome) VALUES ('PlayStore'), ('Google');
