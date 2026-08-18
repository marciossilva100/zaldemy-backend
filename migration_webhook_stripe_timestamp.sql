-- Executar manualmente no banco de produção antes do deploy do backend.
--
-- Seguro rodar mais de uma vez (idempotente): ADD COLUMN checa a própria
-- existência em information_schema antes de alterar (MySQL não suporta
-- ADD COLUMN IF NOT EXISTS, só MariaDB).
--
-- Guarda o timestamp (Unix, event.created do Stripe) do último webhook de
-- assinatura processado com sucesso pra cada usuário - usado em
-- Assinatura::atualizarStatusPorCustomer/desativarAssinatura pra ignorar
-- eventos entregues fora de ordem (o Stripe reenvia eventos que falharam
-- por até alguns dias, sem garantir ordem de entrega).

SET @existe := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='usuarios' AND COLUMN_NAME='assinatura_webhook_processado_em');
SET @sql := IF(@existe = 0, 'ALTER TABLE usuarios ADD COLUMN assinatura_webhook_processado_em INT UNSIGNED DEFAULT NULL', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
