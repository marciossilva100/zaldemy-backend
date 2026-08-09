-- Executar manualmente no banco de produção/homologação. Distingue um
-- apelido gerado automaticamente (o padrão no cadastro) de um que o próprio
-- usuário escolheu/confirmou - usado pra decidir se mostra a tela de
-- onboarding "Escolha seu apelido" (só pra quem ainda não passou por ela).
SET @existe := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'usuarios'
      AND COLUMN_NAME = 'apelido_definido_pelo_usuario'
);
SET @sql := IF(@existe = 0, 'ALTER TABLE usuarios ADD COLUMN apelido_definido_pelo_usuario TINYINT(1) NOT NULL DEFAULT 0', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
