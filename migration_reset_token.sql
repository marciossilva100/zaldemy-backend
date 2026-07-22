-- Executar manualmente no banco de produção/homologação antes do deploy do fluxo de "esqueci senha".
ALTER TABLE usuarios
    ADD COLUMN reset_token VARCHAR(64) DEFAULT NULL AFTER email_token,
    ADD COLUMN reset_token_expira DATETIME DEFAULT NULL AFTER reset_token;
