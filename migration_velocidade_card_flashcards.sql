-- Executar manualmente no banco de produção/homologação antes do deploy da
-- velocidade por lado do cartão (frente/verso) nos Flashcards - independente
-- da velocidade por tipo de voz (velocidade_tts/velocidade_tts_padrao, que
-- continuam controlando as outras telas sem cartão). Mesmo padrão da
-- migration_velocidade_tts_padrao.sql.
ALTER TABLE `configuracoes`
  ADD COLUMN `velocidade_card_frente` DECIMAL(3,2) DEFAULT 1.00 AFTER `velocidade_tts_padrao`,
  ADD COLUMN `velocidade_card_verso` DECIMAL(3,2) DEFAULT 1.00 AFTER `velocidade_card_frente`;
