-- Rastreia quais frases do usuário foram usadas como matéria-prima pelos
-- treinos de IA (Frase do Dia, Perguntas, Tradução Reversa - compartilham o
-- mesmo vocabulário) recentemente, pra evitar que as mesmas frases "fáceis"
-- de categorias pequenas sejam escolhidas repetidamente enquanto o resto do
-- vocabulário nunca aparece (confirmado com dados reais: uma única frase
-- aparecendo em 36-40% das gerações, 85%+ do vocabulário nunca usado).
-- Seguro rodar mais de uma vez (idempotente): CREATE TABLE IF NOT EXISTS.

CREATE TABLE IF NOT EXISTS `frases_uso_recente_ia` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `texto` varchar(500) NOT NULL,
  `data_criacao` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_user_data` (`user_id`, `data_criacao`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
