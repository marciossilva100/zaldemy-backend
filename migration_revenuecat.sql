-- Executar manualmente no banco de produção antes do deploy da compra
-- Premium dentro do app Android (Google Play Billing via RevenueCat).
-- Colunas separadas das de Stripe de propósito - são duas fontes de
-- assinatura independentes (web/Stripe vs Android/Google Play), cada uma
-- com sua própria trava de webhook fora de ordem (mesmo raciocínio de
-- assinatura_webhook_processado_em, ver model/Assinatura.php) - misturar os
-- dois timestamps deixaria um evento do Stripe bloqueando a aplicação de um
-- evento do RevenueCat (ou vice-versa) sem relação nenhuma entre eles.
ALTER TABLE usuarios
  ADD COLUMN revenuecat_original_transaction_id VARCHAR(255) NULL AFTER stripe_subscription_id,
  ADD COLUMN revenuecat_webhook_processado_em BIGINT UNSIGNED NULL AFTER revenuecat_original_transaction_id;
