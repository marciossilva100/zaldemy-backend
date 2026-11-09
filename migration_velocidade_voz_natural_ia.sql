-- Executar manualmente no banco de produção/homologação antes do deploy da
-- 3ª configuração de velocidade - só pro verso dos treinos de IA (Frase do
-- Dia, Perguntas, Tradução Reversa), que sempre tenta voz natural primeiro
-- pro texto no idioma que o aluno está aprendendo. Independente de
-- velocidade_card_frente/velocidade_card_verso (que continuam valendo em
-- todo o resto do app - Flashcards, DigitarTexto etc.).
ALTER TABLE `configuracoes`
  ADD COLUMN `velocidade_voz_natural_ia` DECIMAL(3,2) DEFAULT 1.00 AFTER `velocidade_card_verso`;
