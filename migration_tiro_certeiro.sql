CREATE TABLE IF NOT EXISTS `tiro_certeiro_uso` (
  `id` int NOT NULL AUTO_INCREMENT,
  `user_id` int NOT NULL,
  `data_criacao` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS `tiro_certeiro_recorde` (
  `id` int NOT NULL AUTO_INCREMENT,
  `user_id` int NOT NULL,
  `melhor_pontuacao` int NOT NULL DEFAULT 0,
  `data_atualizacao` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_tiro_certeiro_recorde_user` (`user_id`),
  KEY `idx_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
