-- Executar manualmente no banco de produção/homologação depois do deploy da
-- troca de modelo de voz natural (gpt-4o-mini-tts -> tts-1-hd, ver
-- api/OpenAiTts.php - o modelo antigo às vezes "fugia" do texto no meio do
-- áudio em frases longas/complexas). coral e ash não existem no tts-1-hd -
-- remapeia quem já tinha escolhido uma dessas duas pro equivalente novo
-- (shimmer/echo, ver Configuracoes::VOZES_TTS_VALIDAS), preservando a
-- escolha em vez de resetar todo mundo pra voz padrão (nova).
UPDATE `configuracoes` SET `voz_tts` = 'shimmer' WHERE `voz_tts` = 'coral';
UPDATE `configuracoes` SET `voz_tts` = 'echo' WHERE `voz_tts` = 'ash';
