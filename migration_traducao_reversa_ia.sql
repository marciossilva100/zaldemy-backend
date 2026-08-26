-- Executar manualmente no banco de produção antes do deploy do backend.
--
-- Cria a tabela do novo modo de treino de IA "Tradução Reversa": a IA gera
-- um texto curto no idioma NATIVO do usuário (baseado nas frases que ele já
-- estudou de verdade, id_treino >= 2) e ele traduz falando ou digitando
-- para o idioma que está aprendendo - o inverso de Perguntas/Frase do Dia,
-- que geram no idioma aprendido. Mesmo padrão de colunas de perguntas_ia,
-- só com nomes do domínio novo (não é uma "pergunta").
--
-- Seguro rodar mais de uma vez (idempotente): CREATE TABLE IF NOT EXISTS.

CREATE TABLE IF NOT EXISTS `traducao_reversa_ia` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) DEFAULT NULL,
  `status_id` int(11) NOT NULL,                  -- 0 = pendente, 1 = fechada/conta pro limite
  `texto_nativo` text DEFAULT NULL,              -- texto gerado, no idioma NATIVO do usuário
  `texto_traduzido_gabarito` text DEFAULT NULL,  -- tradução de referência (idioma aprendendo)
  `data_criacao` timestamp NOT NULL DEFAULT current_timestamp(),
  `resposta` text DEFAULT NULL,                  -- resposta do usuário (falada/transcrita ou digitada)
  `nota` int(11) DEFAULT NULL,
  `feedback` text DEFAULT NULL,
  `tentativas` int(11) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `FK_traducao_reversa_ia_usuarios` (`user_id`),
  CONSTRAINT `FK_traducao_reversa_ia_usuarios` FOREIGN KEY (`user_id`) REFERENCES `usuarios` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
