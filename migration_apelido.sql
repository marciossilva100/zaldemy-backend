-- Executar manualmente no banco de produção/homologação, NESTA ORDEM:
-- 1) Este arquivo (adiciona a coluna, ainda sem NOT NULL - usuários
--    existentes começam com apelido NULL).
-- 2) dev-backfill-apelido.php (script único, gera um apelido automático
--    exclusivo pra cada usuário existente que ainda está com NULL).
-- 3) migration_apelido_not_null.sql (só depois de confirmar que não sobrou
--    nenhum usuário com apelido NULL).
SET @existe := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'usuarios'
      AND COLUMN_NAME = 'apelido'
);
SET @sql := IF(@existe = 0, 'ALTER TABLE usuarios ADD COLUMN apelido VARCHAR(20) DEFAULT NULL', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- UNIQUE permite múltiplos NULL simultaneamente no MySQL (não é violação),
-- então pode ser criado já nessa etapa, antes do backfill.
SET @existe_indice := (
    SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'usuarios'
      AND INDEX_NAME = 'uk_usuarios_apelido'
);
SET @sql := IF(@existe_indice = 0, 'ALTER TABLE usuarios ADD UNIQUE KEY uk_usuarios_apelido (apelido)', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
