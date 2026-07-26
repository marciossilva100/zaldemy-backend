-- Executar manualmente no banco de produção/homologação antes do deploy do
-- limite diário de geração de áudio (ElevenLabs).
-- Cada linha = uma chamada de fato feita à API (cache hit não conta).
CREATE TABLE IF NOT EXISTS `audio_ia_uso` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `data_criacao` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `FK_audio_ia_uso_usuarios` (`user_id`),
  CONSTRAINT `FK_audio_ia_uso_usuarios` FOREIGN KEY (`user_id`) REFERENCES `usuarios` (`id`) ON DELETE NO ACTION ON UPDATE NO ACTION
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
