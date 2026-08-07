-- Executar manualmente no banco de produção/homologação. Guarda que o
-- usuário já dispensou o balão "criar sua primeira categoria" (ao clicar no
-- botão de adicionar) - pra ele nunca mais aparecer depois disso, mesmo que
-- o usuário ainda não tenha criado categoria nenhuma.
SET @existe := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'usuarios'
      AND COLUMN_NAME = 'guia_categoria_dispensado'
);
SET @sql := IF(@existe = 0, 'ALTER TABLE usuarios ADD COLUMN guia_categoria_dispensado TINYINT(1) NOT NULL DEFAULT 0', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
