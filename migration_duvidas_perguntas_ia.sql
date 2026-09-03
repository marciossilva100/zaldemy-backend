-- Executar manualmente no banco de produção/homologação antes do deploy do
-- chat de dúvidas no feedback de Perguntas (treino de IA).
CREATE TABLE IF NOT EXISTS `perguntas_ia_duvidas` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `pergunta_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `role` varchar(20) NOT NULL,
  `mensagem` text NOT NULL,
  `data_criacao` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `FK_perguntas_ia_duvidas_pergunta` (`pergunta_id`),
  KEY `FK_perguntas_ia_duvidas_usuarios` (`user_id`),
  CONSTRAINT `FK_perguntas_ia_duvidas_pergunta` FOREIGN KEY (`pergunta_id`) REFERENCES `perguntas_ia` (`id`) ON DELETE CASCADE,
  CONSTRAINT `FK_perguntas_ia_duvidas_usuarios` FOREIGN KEY (`user_id`) REFERENCES `usuarios` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
