-- --------------------------------------------------------
-- Servidor:                     127.0.0.1
-- Versão do servidor:           8.0.43-0ubuntu0.24.04.2 - (Ubuntu)
-- OS do Servidor:               Linux
-- HeidiSQL Versão:              12.8.0.6908
-- --------------------------------------------------------

/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET NAMES utf8 */;
/*!50503 SET NAMES utf8mb4 */;
/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;

-- Copiando estrutura para tabela memly.categorias
CREATE TABLE IF NOT EXISTS `categorias` (
  `id` int NOT NULL AUTO_INCREMENT,
  `categoria` varchar(50) DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- Copiando dados para a tabela memly.categorias: ~3 rows (aproximadamente)
INSERT INTO `categorias` (`id`, `categoria`) VALUES
	(1, 'Frases'),
	(2, 'Verbos'),
	(3, 'Expressoes'),
	(4, 'Teste');

-- Copiando estrutura para tabela memly.frases
CREATE TABLE IF NOT EXISTS `frases` (
  `id` int NOT NULL AUTO_INCREMENT,
  `usuario_id` int DEFAULT NULL,
  `texto_nativo` text CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NOT NULL,
  `texto_traduzido` text NOT NULL,
  `idioma_nativo` text NOT NULL,
  `idioma_aprendendo` text NOT NULL,
  `data_criacao` timestamp NULL DEFAULT NULL,
  `data_atualizacao` timestamp NULL DEFAULT NULL,
  `categoria_id` int DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `fk_frases_categorias` (`categoria_id`),
  KEY `FK_frases_usuarios` (`usuario_id`),
  CONSTRAINT `fk_frases_categorias` FOREIGN KEY (`categoria_id`) REFERENCES `categorias` (`id`),
  CONSTRAINT `FK_frases_usuarios` FOREIGN KEY (`usuario_id`) REFERENCES `usuarios` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=7 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- Copiando dados para a tabela memly.frases: ~6 rows (aproximadamente)
INSERT INTO `frases` (`id`, `usuario_id`, `texto_nativo`, `texto_traduzido`, `idioma_nativo`, `idioma_aprendendo`, `data_criacao`, `data_atualizacao`, `categoria_id`) VALUES
	(1, 1, 'Ola', 'Hello', 'Portugues', 'Ingles', '2026-02-04 22:32:42', '2026-02-04 22:32:43', 1),
	(2, 1, 'deixa pra lá', 'nevermind', 'Portugues', 'Ingles', '2026-02-04 22:32:42', '2026-02-04 22:32:43', 1),
	(3, 1, 'conselho', 'piece advice', 'Portugues', 'Ingles', '2026-02-04 22:32:42', '2026-02-04 22:32:43', 1),
	(4, 1, 'contato', 'touch', 'Portugues', 'Ingles', '2026-02-04 22:32:42', '2026-02-04 22:32:43', 1),
	(5, 1, 'orgulhoso', 'proud', 'Portugues', 'Ingles', '2026-02-04 22:32:42', '2026-02-04 22:32:43', 1),
	(6, 1, 'lide com isso', 'handle it', 'Portugues', 'Ingles', '2026-02-04 22:32:42', '2026-02-04 22:32:43', 3);

-- Copiando estrutura para tabela memly.idiomas
CREATE TABLE IF NOT EXISTS `idiomas` (
  `id` int NOT NULL AUTO_INCREMENT,
  `idioma` varchar(50) DEFAULT NULL,
  `sigla` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=16 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- Copiando dados para a tabela memly.idiomas: ~0 rows (aproximadamente)
INSERT INTO `idiomas` (`id`, `idioma`, `sigla`) VALUES
	(1, 'Português', 'pt'),
	(2, 'Inglês', 'en'),
	(3, 'Espanhol', 'es'),
	(4, 'Francês', 'fr'),
	(5, 'Alemão', 'de'),
	(6, 'Italiano', 'it'),
	(7, 'Chinês (Mandarim)', 'zh'),
	(8, 'Japonês', 'ja'),
	(9, 'Russo', 'ru'),
	(10, 'Árabe', 'ar'),
	(11, 'Hindi', 'hi'),
	(12, 'Coreano', 'ko'),
	(13, 'Holandês', 'nl'),
	(14, 'Turco', 'tr'),
	(15, 'Polonês', 'pl');

-- Copiando estrutura para tabela memly.idioma_referencia
CREATE TABLE IF NOT EXISTS `idioma_referencia` (
  `id` int NOT NULL,
  `idioma_nativo` int DEFAULT NULL,
  `idioma_aprender` int DEFAULT NULL,
  `id_user` int DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `FK__usuarios` (`id_user`),
  KEY `FK_idioma_referencia_idiomas` (`idioma_nativo`),
  KEY `FK_idioma_referencia_idiomas_2` (`idioma_aprender`),
  CONSTRAINT `FK__usuarios` FOREIGN KEY (`id_user`) REFERENCES `usuarios` (`id`),
  CONSTRAINT `FK_idioma_referencia_idiomas` FOREIGN KEY (`idioma_nativo`) REFERENCES `idiomas` (`id`),
  CONSTRAINT `FK_idioma_referencia_idiomas_2` FOREIGN KEY (`idioma_aprender`) REFERENCES `idiomas` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- Copiando dados para a tabela memly.idioma_referencia: ~0 rows (aproximadamente)

-- Copiando estrutura para tabela memly.usuarios
CREATE TABLE IF NOT EXISTS `usuarios` (
  `id` int NOT NULL AUTO_INCREMENT,
  `nome` varchar(50) DEFAULT NULL,
  `email` varchar(50) DEFAULT NULL,
  `password` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci DEFAULT NULL,
  `update_date` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `create_date` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `email_verified` tinyint(1) NOT NULL DEFAULT '0',
  `email_token` varchar(64) DEFAULT NULL,
  `id_idioma_referencia` int DEFAULT NULL,
  `status_id` int DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `FK_usuarios_idioma_referencia` (`id_idioma_referencia`),
  CONSTRAINT `FK_usuarios_idioma_referencia` FOREIGN KEY (`id_idioma_referencia`) REFERENCES `idioma_referencia` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=33 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- Copiando dados para a tabela memly.usuarios: ~6 rows (aproximadamente)
INSERT INTO `usuarios` (`id`, `nome`, `email`, `password`, `update_date`, `create_date`, `email_verified`, `email_token`, `id_idioma_referencia`, `status_id`) VALUES
	(1, 'Marcios', 'marcios@gmail.com', '123', '2026-02-18 19:39:22', '2026-02-18 19:39:22', 0, NULL, NULL, NULL),
	(21, 'MARCIOS SILVA', 'marciosunicoe18@gmail.com', '$2y$10$PYQ0L5HSvwZrZKCjQy8T1.I8jaK6G.OZ/FzxPvebLX0ITpfxRzWNi', '2026-02-18 21:51:31', '2026-02-18 21:51:31', 0, '9ea34393f903fa03fe79209697769dbaf07ba00245b7c193af7f0fe2c9b74561', NULL, NULL),
	(22, 'MARCIOS SILVA', 'marciosuniwco18@gmail.com', '$2y$10$e/K9IF45Y6.KI0Tdco4HkO7oEZX7YrexM22LQmfBbDNAgcjHmE7cO', '2026-02-18 22:09:53', '2026-02-18 22:09:53', 0, '2466dedb69add30daa37580489fb4530b8bec108863aad792b5e128a15a23658', NULL, NULL),
	(23, 'MARCIOS SILVA', 'marciosunico18@gmail.com', '$2y$10$2V4ZzuxIsNJgCVfbym6eQeYmuJlyBDkiB5/f5Un4qdm23QcVMgeJO', '2026-02-18 22:24:29', '2026-02-18 22:24:29', 0, '9062319a8c274a8d804c02273840dc54ed3589e188257478d5f0b54a9be93e44', NULL, NULL),
	(24, 'MARCIOS SILVA', 'marciosunicso18@gmail.com', '$2y$10$NrzhxhVHNwisuq.Ii/8IUe91LX/op7z9VkzICCAsylt5isUhMCcX2', '2026-02-18 22:32:14', '2026-02-18 22:32:14', 0, '338f27392a289806f16387b4556587fd28ca9f09ca5b5b3b4666dad7c106666c', NULL, NULL),
	(32, 'marcios', 'marciossilva.dev@gmail.com', '$2y$10$JQ5n5DQbp6V3Lq9l45Co2uJOCtivj9PokAgokqQjmJHIjUSFk0a.O', '2026-02-19 17:00:22', '2026-02-19 17:00:02', 1, NULL, NULL, NULL);

/*!40103 SET TIME_ZONE=IFNULL(@OLD_TIME_ZONE, 'system') */;
/*!40101 SET SQL_MODE=IFNULL(@OLD_SQL_MODE, '') */;
/*!40014 SET FOREIGN_KEY_CHECKS=IFNULL(@OLD_FOREIGN_KEY_CHECKS, 1) */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40111 SET SQL_NOTES=IFNULL(@OLD_SQL_NOTES, 1) */;
