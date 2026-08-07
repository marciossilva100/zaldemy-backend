-- Executar manualmente no banco de produção/homologação antes do deploy do
-- fluxo de foto de perfil.
ALTER TABLE usuarios
    ADD COLUMN foto_perfil VARCHAR(255) DEFAULT NULL AFTER nome;
