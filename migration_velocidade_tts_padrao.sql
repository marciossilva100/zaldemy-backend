-- Executar manualmente no banco de produção/homologação antes do deploy do
-- controle de velocidade separado pra voz padrão (Google TTS) - antes a
-- mesma coluna `velocidade_tts` controlava voz natural E voz padrão juntas.
ALTER TABLE `configuracoes`
  ADD COLUMN `velocidade_tts_padrao` DECIMAL(3,2) DEFAULT 1.00 AFTER `velocidade_tts`;
