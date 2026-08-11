-- Executar manualmente no banco de produção/homologação antes do deploy das
-- notificações push (Web Push via PWA).
CREATE TABLE IF NOT EXISTS `push_subscriptions` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `endpoint` text NOT NULL,
  `endpoint_hash` char(64) NOT NULL,
  `p256dh` varchar(255) NOT NULL,
  `auth_key` varchar(255) NOT NULL,
  `user_agent` varchar(255) DEFAULT NULL,
  `data_criacao` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_user_endpoint` (`user_id`, `endpoint_hash`),
  KEY `FK_push_subscriptions_usuarios` (`user_id`),
  CONSTRAINT `FK_push_subscriptions_usuarios` FOREIGN KEY (`user_id`) REFERENCES `usuarios` (`id`) ON DELETE CASCADE ON UPDATE NO ACTION
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Log de envio, evita notificar o mesmo usuário mais de uma vez por tipo no
-- mesmo dia (treino_disponivel, streak_risco) ou repetidamente em pouco
-- tempo (reengajamento, checado com uma janela maior que 1 dia no PHP).
CREATE TABLE IF NOT EXISTS `notificacoes_enviadas` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `tipo` varchar(50) NOT NULL,
  `data_envio` date NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_user_tipo_data` (`user_id`, `tipo`, `data_envio`),
  KEY `FK_notificacoes_enviadas_usuarios` (`user_id`),
  CONSTRAINT `FK_notificacoes_enviadas_usuarios` FOREIGN KEY (`user_id`) REFERENCES `usuarios` (`id`) ON DELETE CASCADE ON UPDATE NO ACTION
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
