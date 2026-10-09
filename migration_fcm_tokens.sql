-- Executar manualmente no banco de produção/homologação antes do deploy das
-- notificações push nativas (Firebase Cloud Messaging, app Android via
-- Capacitor) - complementa push_subscriptions, que é só Web Push (PWA).
-- Token é único globalmente (não por usuário+token): cada instalação do app
-- gera um token próprio, não tem como dois usuários compartilharem o mesmo.
CREATE TABLE IF NOT EXISTS `fcm_tokens` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `token` varchar(255) NOT NULL,
  `user_agent` varchar(255) DEFAULT NULL,
  `data_criacao` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_token` (`token`),
  KEY `FK_fcm_tokens_usuarios` (`user_id`),
  CONSTRAINT `FK_fcm_tokens_usuarios` FOREIGN KEY (`user_id`) REFERENCES `usuarios` (`id`) ON DELETE CASCADE ON UPDATE NO ACTION
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
