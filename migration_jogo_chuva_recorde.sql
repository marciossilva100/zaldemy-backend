-- Executar manualmente no banco de produção/homologação antes do deploy do
-- minijogo "Chuva de Frases". Guarda só a melhor pontuação por usuário e
-- categoria (não o histórico de cada partida).
CREATE TABLE IF NOT EXISTS `jogo_chuva_recorde` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `categoria_id` int(11) NOT NULL,
  `melhor_pontuacao` int(11) NOT NULL DEFAULT 0,
  `data_atualizacao` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_jogo_chuva_recorde_user_categoria` (`user_id`, `categoria_id`),
  KEY `FK_jogo_chuva_recorde_usuarios` (`user_id`),
  KEY `FK_jogo_chuva_recorde_categorias` (`categoria_id`),
  CONSTRAINT `FK_jogo_chuva_recorde_usuarios` FOREIGN KEY (`user_id`) REFERENCES `usuarios` (`id`) ON DELETE NO ACTION ON UPDATE NO ACTION,
  CONSTRAINT `FK_jogo_chuva_recorde_categorias` FOREIGN KEY (`categoria_id`) REFERENCES `categorias` (`id`) ON DELETE NO ACTION ON UPDATE NO ACTION
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
