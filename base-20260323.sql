-- --------------------------------------------------------
-- Servidor:                     193.203.175.126
-- Versão do servidor:           11.8.3-MariaDB-log - MariaDB Server
-- OS do Servidor:               Linux
-- HeidiSQL Versão:              12.15.0.7171
-- --------------------------------------------------------

/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET NAMES utf8 */;
/*!50503 SET NAMES utf8mb4 */;
/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;

-- Copiando estrutura para tabela u712858045_zaldemy.canal_aquisicao
CREATE TABLE IF NOT EXISTS `canal_aquisicao` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `rede_social_id` int(11) NOT NULL,
  `data_registro` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `user_id` (`user_id`),
  KEY `rede_social_id` (`rede_social_id`),
  CONSTRAINT `canal_aquisicao_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `usuarios` (`id`),
  CONSTRAINT `canal_aquisicao_ibfk_2` FOREIGN KEY (`rede_social_id`) REFERENCES `redes_sociais` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=14 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Copiando dados para a tabela u712858045_zaldemy.canal_aquisicao: ~7 rows (aproximadamente)
INSERT INTO `canal_aquisicao` (`id`, `user_id`, `rede_social_id`, `data_registro`) VALUES
	(2, 32, 5, '2026-03-16 19:32:01'),
	(7, 47, 7, '2026-03-17 22:50:21'),
	(8, 49, 4, '2026-03-18 14:31:40'),
	(9, 51, 4, '2026-03-18 14:32:50'),
	(10, 50, 5, '2026-03-18 14:34:33'),
	(12, 53, 2, '2026-03-18 19:58:51'),
	(13, 54, 7, '2026-03-18 22:27:54');

-- Copiando estrutura para tabela u712858045_zaldemy.categorias
CREATE TABLE IF NOT EXISTS `categorias` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `categoria` varchar(50) DEFAULT NULL,
  `id_user` int(11) DEFAULT NULL,
  `public` int(11) DEFAULT 0,
  `status_id` int(11) DEFAULT 1,
  `id_categoria_publica` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `FK_categorias_usuarios` (`id_user`),
  CONSTRAINT `FK_categorias_usuarios` FOREIGN KEY (`id_user`) REFERENCES `usuarios` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=65 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- Copiando dados para a tabela u712858045_zaldemy.categorias: ~25 rows (aproximadamente)
INSERT INTO `categorias` (`id`, `categoria`, `id_user`, `public`, `status_id`, `id_categoria_publica`) VALUES
	(26, 'Tecnologia', 32, 1, 1, NULL),
	(31, 'Frases', 47, 1, 1, NULL),
	(35, 'BOATE', 51, 0, 1, NULL),
	(36, 'Tecnologia', 50, 0, 1, NULL),
	(37, 'Frases', 50, 0, 1, NULL),
	(38, 'tech', 50, 0, 1, NULL),
	(39, 'Tecnologia', 51, 0, 1, NULL),
	(42, 'Tecnologia', 47, 1, 1, 30),
	(43, 'Filmes', 47, 1, 1, NULL),
	(45, 'Filmes', 53, 0, 1, 43),
	(46, 'Filmes', 54, 0, 1, 43),
	(47, 'Frases', 54, 0, 1, 31),
	(48, 'Novelas', 54, 0, 1, NULL),
	(53, 'Viagens', 47, 1, 0, NULL),
	(54, 'Séries', 54, 1, 1, NULL),
	(55, 'Viagens', 50, 0, 1, 53),
	(56, 'Séries', 47, 0, 0, 54),
	(57, 'series', 47, 1, 0, NULL),
	(58, 'series', 47, 1, 0, NULL),
	(59, 'Series', 47, 1, 0, NULL),
	(60, 'series', 47, 1, 0, NULL),
	(61, 'series', 47, 1, 0, NULL),
	(62, 'series', 47, 1, 0, NULL),
	(63, 'series', 47, 1, 1, NULL),
	(64, 'Viagens', 47, 1, 1, NULL);

-- Copiando estrutura para tabela u712858045_zaldemy.frases
CREATE TABLE IF NOT EXISTS `frases` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `usuario_id` int(11) DEFAULT NULL,
  `texto_nativo` text NOT NULL,
  `texto_traduzido` text NOT NULL,
  `idioma_nativo` int(11) NOT NULL DEFAULT 0,
  `idioma_aprendendo` int(11) NOT NULL DEFAULT 0,
  `data_criacao` datetime NOT NULL DEFAULT current_timestamp(),
  `data_atualizacao` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `categoria_id` int(11) DEFAULT NULL,
  `status_id` int(11) DEFAULT NULL,
  `id_treino` int(11) DEFAULT NULL,
  `categoria_id_publica` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `fk_frases_categorias` (`categoria_id`),
  KEY `FK_frases_usuarios` (`usuario_id`),
  KEY `FK_frases_idiomas` (`idioma_nativo`),
  KEY `FK_frases_idiomas_2` (`idioma_aprendendo`),
  KEY `FK_frases_treino` (`id_treino`),
  CONSTRAINT `FK_frases_idiomas` FOREIGN KEY (`idioma_nativo`) REFERENCES `idiomas` (`id`),
  CONSTRAINT `FK_frases_idiomas_2` FOREIGN KEY (`idioma_aprendendo`) REFERENCES `idiomas` (`id`),
  CONSTRAINT `FK_frases_treino` FOREIGN KEY (`id_treino`) REFERENCES `treino` (`id`),
  CONSTRAINT `FK_frases_usuarios` FOREIGN KEY (`usuario_id`) REFERENCES `usuarios` (`id`),
  CONSTRAINT `fk_frases_categorias` FOREIGN KEY (`categoria_id`) REFERENCES `categorias` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=140 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- Copiando dados para a tabela u712858045_zaldemy.frases: ~67 rows (aproximadamente)
INSERT INTO `frases` (`id`, `usuario_id`, `texto_nativo`, `texto_traduzido`, `idioma_nativo`, `idioma_aprendendo`, `data_criacao`, `data_atualizacao`, `categoria_id`, `status_id`, `id_treino`, `categoria_id_publica`) VALUES
	(51, 32, 'oi', 'hey', 1, 2, '2026-03-16 20:21:47', '2026-03-17 15:01:16', 26, 1, 2, NULL),
	(52, 32, 'tudo bem?', 'all good?', 1, 2, '2026-03-16 19:20:18', '2026-03-17 15:01:16', 26, 1, 2, NULL),
	(53, 32, 'Gosto de voce', 'I like you', 1, 2, '2026-03-16 19:23:10', '2026-03-17 15:01:16', 26, 1, 2, NULL),
	(54, 32, 'Eu adoro estudar', 'I love studying', 1, 2, '2026-03-16 20:58:32', '2026-03-17 15:30:25', 26, 1, 2, NULL),
	(55, 32, 'Como voce se chama?', 'What is your name?', 1, 2, '2026-03-17 15:36:37', '2026-03-17 18:28:34', 26, 1, 2, NULL),
	(61, 47, 'Desde então ', 'Ever since ', 1, 2, '2026-03-17 22:54:12', '2026-03-19 11:47:31', 31, 1, 4, NULL),
	(62, 47, 'O que você mais gosta de fazer?', 'What do you like to do', 1, 2, '2026-03-17 22:54:47', '2026-03-19 11:47:31', 31, 1, 4, NULL),
	(63, 47, 'Eu gosto da música Birds da banda Imagine Dragons', 'I like the song Birds by the band Imagine Dragons', 1, 2, '2026-03-17 23:00:23', '2026-03-19 11:47:31', 31, 1, 4, NULL),
	(70, 51, 'Oi, quanto custa a dancinha?', 'hello, tu bumbum $$?', 1, 2, '2026-03-18 14:34:31', '2026-03-18 14:36:04', 35, 1, 2, NULL),
	(71, 50, 'Como voce se chama?', 'What is your name?', 1, 2, '2026-03-18 14:34:54', '2026-03-18 14:34:54', 36, 1, 1, NULL),
	(72, 50, 'Eu adoro estudar', 'I love studying', 1, 2, '2026-03-18 14:34:54', '2026-03-18 14:34:54', 36, 1, 1, NULL),
	(73, 50, 'Gosto de voce', 'I like you', 1, 2, '2026-03-18 14:34:54', '2026-03-18 14:34:54', 36, 1, 1, NULL),
	(74, 50, 'tudo bem?', 'all good?', 1, 2, '2026-03-18 14:34:54', '2026-03-18 14:34:54', 36, 1, 1, NULL),
	(75, 50, 'oi', 'hey', 1, 2, '2026-03-18 14:34:54', '2026-03-18 14:34:54', 36, 1, 1, NULL),
	(76, 50, 'Eu quero falar com voce algo muito importante', 'I want to speak to you about something very important', 1, 2, '2026-03-18 14:35:31', '2026-03-18 14:35:31', 36, 1, 1, NULL),
	(77, 50, 'Ja desisti', 'Teste', 1, 2, '2026-03-18 14:35:31', '2026-03-18 14:35:31', 36, 1, 1, NULL),
	(78, 50, 'Eu nao quero falar', 'I don\'t speak', 1, 2, '2026-03-18 14:35:31', '2026-03-18 14:35:31', 36, 1, 1, NULL),
	(79, 50, 'Eu gosto da música Birds da banda Imagine Dragons', 'I like the song Birds by the band Imagine Dragons', 1, 2, '2026-03-18 14:35:32', '2026-03-20 17:18:03', 37, 1, 2, NULL),
	(80, 50, 'O que você mais gosta de fazer?', 'What do you like to do', 1, 2, '2026-03-18 14:35:32', '2026-03-20 17:18:03', 37, 1, 2, NULL),
	(81, 50, 'Desde então', 'Ever since', 1, 2, '2026-03-18 14:35:32', '2026-03-20 17:18:03', 37, 1, 2, NULL),
	(82, 50, 'work', 'trabalho ', 1, 2, '2026-03-18 14:55:06', '2026-03-20 17:18:43', 38, 1, 2, NULL),
	(83, 51, 'Eu quero falar com voce algo muito importante', 'I want to speak to you about something very important', 1, 2, '2026-03-18 14:55:09', '2026-03-18 14:55:09', 39, 1, 1, NULL),
	(84, 51, 'Ja desisti', 'Teste', 1, 2, '2026-03-18 14:55:09', '2026-03-18 14:55:09', 39, 1, 1, NULL),
	(85, 51, 'Eu nao quero falar', 'I don\'t speak', 1, 2, '2026-03-18 14:55:09', '2026-03-18 14:55:09', 39, 1, 1, NULL),
	(97, 47, 'Eu quero falar com voce algo muito importante', 'I want to speak to you about something very important', 1, 2, '2026-03-18 18:43:02', '2026-03-18 22:02:26', 42, 0, 1, NULL),
	(98, 47, 'Ja desisti', 'Teste', 1, 2, '2026-03-18 18:43:02', '2026-03-18 21:58:37', 42, 0, 1, NULL),
	(99, 47, 'Eu nao quero falar', 'I don\'t speak', 1, 2, '2026-03-18 18:43:02', '2026-03-18 22:10:14', 42, 0, 2, NULL),
	(100, 47, 'Como voce se chama?', 'What is your name?', 1, 2, '2026-03-18 18:51:11', '2026-03-18 22:10:11', 42, 0, 2, NULL),
	(101, 47, 'Eu adoro estudar', 'I love studying', 1, 2, '2026-03-18 18:51:11', '2026-03-18 22:04:45', 42, 1, 2, NULL),
	(102, 47, 'Gosto de voce', 'I like you', 1, 2, '2026-03-18 18:51:11', '2026-03-18 22:10:28', 42, 0, 2, NULL),
	(103, 47, 'tudo bem?', 'all good?', 1, 2, '2026-03-18 18:51:11', '2026-03-18 22:10:17', 42, 0, 2, NULL),
	(104, 47, 'oi', 'hey', 1, 2, '2026-03-18 18:51:11', '2026-03-18 22:10:18', 42, 0, 2, NULL),
	(105, 47, 'Eu amo assistir filmes', 'I love watching movies', 1, 2, '2026-03-18 19:29:05', '2026-03-18 19:29:05', 43, 1, 1, NULL),
	(106, 47, 'Qual seu filme preferido?', 'What\'s your favorite movie?', 1, 2, '2026-03-18 19:29:22', '2026-03-18 19:29:22', 43, 1, 1, NULL),
	(107, 53, 'Qual seu filme preferido?', 'What\'s your favorite movie?', 1, 2, '2026-03-18 19:58:42', '2026-03-19 11:18:59', 45, 1, 2, NULL),
	(108, 53, 'Eu amo assistir filmes', 'I love watching movies', 1, 2, '2026-03-18 19:58:42', '2026-03-19 12:01:25', 45, 1, 2, NULL),
	(109, 47, 'Ela perguntou se eu gostei', 'She asked whether I liked it', 1, 2, '2026-03-18 22:06:18', '2026-03-18 22:10:20', 42, 0, 1, NULL),
	(110, 47, 'Para eu fazer qualquer coisa', 'that for me to do', 1, 2, '2026-03-18 22:06:58', '2026-03-18 22:10:22', 42, 0, 1, NULL),
	(111, 47, 'Ele nos enganou', 'he tricked us', 1, 2, '2026-03-18 22:07:32', '2026-03-18 22:10:06', 42, 0, 1, NULL),
	(112, 47, 'Parou de funcionar', 'It Just went dead', 1, 2, '2026-03-18 22:08:07', '2026-03-18 22:10:01', 42, 0, 1, NULL),
	(113, 47, 'Eu amo trabalhar como desenvolvedor ', 'I love working as a developer', 1, 2, '2026-03-18 22:10:48', '2026-03-18 22:10:48', 42, 1, 1, NULL),
	(114, 47, 'Você gosta de mexer em computadores?', 'Do you like tinkering with computers?', 1, 2, '2026-03-18 22:11:39', '2026-03-18 22:11:39', 42, 1, 1, NULL),
	(115, 54, 'Qual seu filme preferido?', 'What\'s your favorite movie?', 1, 2, '2026-03-18 22:26:52', '2026-03-18 22:26:52', 46, 1, 1, NULL),
	(116, 54, 'Eu amo assistir filmes', 'I love watching movies', 1, 2, '2026-03-18 22:26:52', '2026-03-18 22:26:52', 46, 1, 1, NULL),
	(117, 54, 'Eu gosto da música Birds da banda Imagine Dragons', 'I like the song Birds by the band Imagine Dragons', 1, 2, '2026-03-18 22:28:19', '2026-03-19 22:23:05', 47, 1, 4, NULL),
	(118, 54, 'O que você mais gosta de fazer?', 'What do you like to do', 1, 2, '2026-03-18 22:28:19', '2026-03-19 22:23:05', 47, 1, 4, NULL),
	(119, 54, 'Desde então', 'Ever since', 1, 2, '2026-03-18 22:28:19', '2026-03-19 22:23:05', 47, 1, 4, NULL),
	(120, 54, 'Eu amo você', 'I love you', 1, 2, '2026-03-18 22:37:41', '2026-03-22 21:12:43', 48, 1, 2, NULL),
	(121, 53, 'Eu quero assistir agora', 'I want to watch it now', 1, 2, '2026-03-19 11:54:59', '2026-03-19 11:54:59', 45, 1, 1, NULL),
	(122, 47, 'Eu amo viajar', 'I love traveling', 1, 2, '2026-03-19 22:00:19', '2026-03-19 22:00:19', 53, 1, 1, NULL),
	(123, 47, 'Eu me magoo facil ', 'I\'m Just not thick-skinned ', 1, 2, '2026-03-19 22:13:00', '2026-03-19 22:13:00', 31, 1, 1, NULL),
	(124, 47, 'Para eu fazer qualquer coisa', 'That for me to do', 1, 2, '2026-03-19 22:13:43', '2026-03-19 22:13:43', 31, 1, 1, NULL),
	(125, 47, 'Eu vou te ligar mais tarde', 'I\'m gonna call you later', 1, 2, '2026-03-19 22:15:06', '2026-03-20 23:00:49', 31, 1, 3, NULL),
	(126, 47, 'Propósito; finalidade', 'Purpose', 1, 2, '2026-03-19 22:16:01', '2026-03-20 23:00:49', 31, 1, 3, NULL),
	(127, 47, 'Bem naquela hora', 'So right then', 1, 2, '2026-03-19 22:16:54', '2026-03-20 23:00:49', 31, 1, 3, NULL),
	(128, 47, 'Eu vou viajar amanhã ', 'I\'m going to travel tomorrow', 1, 2, '2026-03-20 13:20:12', '2026-03-20 13:20:12', 53, 1, 1, NULL),
	(129, 47, 'Onde compro a passagem', 'Where do I buy the ticket', 1, 2, '2026-03-20 13:20:34', '2026-03-20 13:20:39', 53, 0, 1, NULL),
	(130, 47, 'Onde compro a passagem?', 'Where do I buy the ticket?', 1, 2, '2026-03-20 13:20:50', '2026-03-20 13:20:50', 53, 1, 1, NULL),
	(131, 50, 'Onde compro a passagem?', 'Where do I buy the ticket?', 1, 2, '2026-03-20 17:19:02', '2026-03-20 17:19:02', 55, 1, 1, NULL),
	(132, 50, 'Eu vou viajar amanhã', 'I\'m going to travel tomorrow', 1, 2, '2026-03-20 17:19:02', '2026-03-20 17:19:02', 55, 1, 1, NULL),
	(133, 50, 'Eu amo viajar', 'I love traveling', 1, 2, '2026-03-20 17:19:02', '2026-03-20 17:19:02', 55, 1, 1, NULL),
	(134, 47, 'Como voce se chama?', 'What is your name?', 1, 2, '2026-03-20 21:12:49', '2026-03-20 21:12:49', 42, 1, 1, NULL),
	(135, 47, 'Gosto de voce', 'I like you', 1, 2, '2026-03-20 21:12:49', '2026-03-20 21:12:49', 42, 1, 1, NULL),
	(136, 47, 'tudo bem?', 'all good?', 1, 2, '2026-03-20 21:12:49', '2026-03-20 21:12:49', 42, 1, 1, NULL),
	(137, 47, 'oi', 'hey', 1, 2, '2026-03-20 21:12:49', '2026-03-20 21:12:49', 42, 1, 1, NULL),
	(138, 47, 'Como compro a passagem de avião?', 'How do I buy a plane ticket?', 1, 2, '2026-03-22 20:22:21', '2026-03-22 20:22:21', 64, 1, 1, NULL),
	(139, 47, 'Eu quero viajar com você essa semana', 'I want to travel with you this week', 1, 2, '2026-03-22 20:22:46', '2026-03-22 20:22:46', 64, 1, 1, NULL);

-- Copiando estrutura para tabela u712858045_zaldemy.idioma_referencia
CREATE TABLE IF NOT EXISTS `idioma_referencia` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `idioma_nativo` int(11) DEFAULT NULL,
  `idioma_aprender` int(11) DEFAULT NULL,
  `id_user` int(11) DEFAULT NULL,
  `id_frase` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `FK__usuarios` (`id_user`),
  KEY `FK_idioma_referencia_idiomas` (`idioma_nativo`),
  KEY `FK_idioma_referencia_idiomas_2` (`idioma_aprender`),
  KEY `FK_idioma_referencia_frases` (`id_frase`),
  CONSTRAINT `FK__usuarios` FOREIGN KEY (`id_user`) REFERENCES `usuarios` (`id`),
  CONSTRAINT `FK_idioma_referencia_frases` FOREIGN KEY (`id_frase`) REFERENCES `frases` (`id`),
  CONSTRAINT `FK_idioma_referencia_idiomas` FOREIGN KEY (`idioma_nativo`) REFERENCES `idiomas` (`id`),
  CONSTRAINT `FK_idioma_referencia_idiomas_2` FOREIGN KEY (`idioma_aprender`) REFERENCES `idiomas` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=46 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- Copiando dados para a tabela u712858045_zaldemy.idioma_referencia: ~7 rows (aproximadamente)
INSERT INTO `idioma_referencia` (`id`, `idioma_nativo`, `idioma_aprender`, `id_user`, `id_frase`) VALUES
	(34, 1, 2, 42, NULL),
	(39, 1, 2, 47, NULL),
	(40, 3, 1, 49, NULL),
	(41, 1, 2, 51, NULL),
	(42, 1, 2, 50, NULL),
	(44, 1, 2, 53, NULL),
	(45, 1, 2, 54, NULL);

-- Copiando estrutura para tabela u712858045_zaldemy.idiomas
CREATE TABLE IF NOT EXISTS `idiomas` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `idioma` varchar(50) DEFAULT NULL,
  `sigla` varchar(50) DEFAULT NULL,
  `status_id` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=16 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- Copiando dados para a tabela u712858045_zaldemy.idiomas: ~15 rows (aproximadamente)
INSERT INTO `idiomas` (`id`, `idioma`, `sigla`, `status_id`) VALUES
	(1, 'Português', 'pt', NULL),
	(2, 'Inglês', 'en', NULL),
	(3, 'Espanhol', 'es', NULL),
	(4, 'Francês', 'fr', NULL),
	(5, 'Alemão', 'de', NULL),
	(6, 'Italiano', 'it', NULL),
	(7, 'Chinês (Mandarim)', 'zh', NULL),
	(8, 'Japonês', 'ja', NULL),
	(9, 'Russo', 'ru', NULL),
	(10, 'Árabe', 'ar', NULL),
	(11, 'Hindi', 'hi', NULL),
	(12, 'Coreano', 'ko', NULL),
	(13, 'Holandês', 'nl', NULL),
	(14, 'Turco', 'tr', NULL),
	(15, 'Polonês', 'pl', NULL);

-- Copiando estrutura para tabela u712858045_zaldemy.metricas
CREATE TABLE IF NOT EXISTS `metricas` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `frase_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `acertou` tinyint(1) NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `frase_id` (`frase_id`),
  KEY `user_id` (`user_id`),
  CONSTRAINT `FK_metricas_usuarios` FOREIGN KEY (`user_id`) REFERENCES `usuarios` (`id`) ON DELETE NO ACTION ON UPDATE NO ACTION,
  CONSTRAINT `fk_metricas_frase` FOREIGN KEY (`frase_id`) REFERENCES `frases` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=27 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Copiando dados para a tabela u712858045_zaldemy.metricas: ~25 rows (aproximadamente)
INSERT INTO `metricas` (`id`, `frase_id`, `user_id`, `acertou`, `created_at`) VALUES
	(2, 54, 32, 1, '2026-03-17 15:01:16'),
	(3, 53, 32, 1, '2026-03-17 15:01:16'),
	(4, 52, 32, 1, '2026-03-17 15:01:16'),
	(5, 51, 32, 1, '2026-03-17 15:01:16'),
	(6, 54, 32, 1, '2026-03-17 15:02:38'),
	(7, 54, 32, 0, '2026-03-17 15:30:25'),
	(8, 55, 32, 1, '2026-03-17 15:42:31'),
	(9, 55, 32, 1, '2026-03-17 16:59:54'),
	(10, 55, 32, 1, '2026-03-17 17:00:12'),
	(11, 55, 32, 1, '2026-03-17 18:11:56'),
	(12, 108, 53, 0, '2026-03-19 11:18:59'),
	(13, 107, 53, 0, '2026-03-19 11:18:59'),
	(14, 108, 53, 0, '2026-03-19 11:27:57'),
	(15, 108, 53, 0, '2026-03-19 12:01:25'),
	(16, 63, 47, 1, '2026-03-19 13:37:07'),
	(17, 62, 47, 1, '2026-03-19 13:37:07'),
	(18, 61, 47, 1, '2026-03-19 13:37:07'),
	(19, 63, 47, 1, '2026-03-19 22:11:33'),
	(20, 62, 47, 1, '2026-03-19 22:11:33'),
	(21, 61, 47, 1, '2026-03-19 22:11:33'),
	(22, 82, 50, 1, '2026-03-20 17:15:31'),
	(23, 82, 50, 0, '2026-03-20 17:18:43'),
	(24, 63, 47, 1, '2026-03-21 13:59:01'),
	(25, 62, 47, 1, '2026-03-21 13:59:01'),
	(26, 61, 47, 1, '2026-03-21 13:59:01');

-- Copiando estrutura para tabela u712858045_zaldemy.perguntas_ia
CREATE TABLE IF NOT EXISTS `perguntas_ia` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) DEFAULT NULL,
  `status_id` int(11) NOT NULL,
  `question` text DEFAULT NULL,
  `data_criacao` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `FK_perguntas_ia_usuarios` (`user_id`),
  CONSTRAINT `FK_perguntas_ia_usuarios` FOREIGN KEY (`user_id`) REFERENCES `usuarios` (`id`) ON DELETE NO ACTION ON UPDATE NO ACTION
) ENGINE=InnoDB AUTO_INCREMENT=94 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Copiando dados para a tabela u712858045_zaldemy.perguntas_ia: ~51 rows (aproximadamente)
INSERT INTO `perguntas_ia` (`id`, `user_id`, `status_id`, `question`, `data_criacao`) VALUES
	(43, 47, 0, 'What do you like to do the most?', '2026-03-18 02:09:15'),
	(44, 47, 0, 'What do you like most about the song "Birds" by Imagine Dragons?', '2026-03-18 02:18:36'),
	(45, 47, 0, 'What is your favorite song by Imagine Dragons?', '2026-03-18 17:03:44'),
	(46, 47, 0, 'What do you like to do the most?  \n\n(Note: I selected this question because it\'s simple, beginner-level, directly based on the provided phrases ["O que você mais gosta de fazer?" and "Eu adoro estudar"/"Eu amo assistir filmes"], and avoids being generic by using the context of personal preferences mentioned in the sentences.)', '2026-03-20 01:25:25'),
	(47, 47, 0, 'What do you like to do the most?  \n\n(Note: I selected this question because it\'s simple, beginner-friendly, and directly based on the phrase "O que você mais gosta de fazer?" from the provided list.)', '2026-03-21 02:01:18'),
	(48, 47, 0, 'What is your favorite song by Imagine Dragons?', '2026-03-21 02:02:26'),
	(49, 47, 0, 'What is your favorite song by Imagine Dragons?', '2026-03-21 02:17:05'),
	(50, 47, 0, 'What is your favorite movie?', '2026-03-22 15:49:01'),
	(51, 47, 0, 'What is your favorite Imagine Dragons song?', '2026-03-22 15:49:54'),
	(52, 47, 0, 'What is your favorite movie?', '2026-03-22 15:50:49'),
	(53, 47, 0, 'What is your favorite movie?', '2026-03-22 15:50:56'),
	(54, 47, 0, 'What is your favorite song by Imagine Dragons?', '2026-03-22 15:51:27'),
	(55, 47, 0, 'What is your favorite song by Imagine Dragons?', '2026-03-22 15:52:42'),
	(56, 47, 0, 'What is your favorite movie?', '2026-03-22 15:52:46'),
	(57, 47, 0, '**What is your favorite Imagine Dragons song?**  \n\n(Note: This question is based on the phrase "Eu gosto da música Birds da banda Imagine Dragons" and is appropriate for a beginner level.)', '2026-03-22 15:53:10'),
	(58, 47, 0, 'What is your favorite Imagine Dragons song?', '2026-03-22 15:53:43'),
	(59, 47, 0, 'What is your favorite song by Imagine Dragons?', '2026-03-22 15:53:54'),
	(60, 47, 0, 'What is your favorite song by Imagine Dragons?', '2026-03-22 15:54:17'),
	(61, 47, 0, 'What is your favorite movie?', '2026-03-22 15:54:37'),
	(62, 47, 0, 'What is your favorite Imagine Dragons song?', '2026-03-22 15:54:58'),
	(63, 47, 0, 'What do you like to do the most?  \n\n(Note: I selected this question because it\'s simple, beginner-friendly, and directly based on the phrase "O que você mais gosta de fazer?" from the provided list.)', '2026-03-22 15:55:30'),
	(64, 47, 0, 'What is your favorite movie?', '2026-03-22 15:55:33'),
	(65, 47, 0, 'What is your favorite movie?', '2026-03-22 15:55:37'),
	(66, 47, 0, 'What do you like to do the most?  \n\n(Note: I selected this question because it\'s simple for beginners, relates directly to the provided phrases, and avoids being generic.)', '2026-03-22 15:55:54'),
	(67, 47, 0, 'What is your favorite movie?', '2026-03-22 15:55:56'),
	(68, 47, 0, 'What is your favorite song by Imagine Dragons?', '2026-03-22 15:56:19'),
	(69, 47, 0, 'What is your favorite movie?', '2026-03-22 15:57:34'),
	(70, 47, 0, 'What is your favorite song by Imagine Dragons?', '2026-03-22 15:58:32'),
	(71, 47, 0, 'What is your favorite movie?', '2026-03-22 16:11:45'),
	(72, 47, 0, 'Here\'s a beginner-level question based on the provided phrases:\n\nWhat do you like to do the most?', '2026-03-23 02:06:54'),
	(73, 47, 0, '**What do you like to do the most?**', '2026-03-23 02:11:35'),
	(74, 47, 0, 'Where can I buy the plane ticket?', '2026-03-23 23:06:38'),
	(75, 47, 0, '**What is your favorite Imagine Dragons song?**', '2026-03-23 23:07:24'),
	(76, 47, 0, 'What is your favorite movie?', '2026-03-23 23:08:25'),
	(77, 47, 0, 'What is your favorite movie?', '2026-03-23 23:08:36'),
	(78, 47, 0, 'What is your favorite Imagine Dragons song?', '2026-03-23 23:08:43'),
	(79, 47, 0, 'Here\'s one appropriate beginner-level question based on the context:\n\nWhat do you like to do the most?', '2026-03-23 23:08:51'),
	(80, 47, 0, 'What is your favorite movie?', '2026-03-23 23:09:11'),
	(81, 47, 0, 'Where can I buy the ticket for our trip this week?', '2026-03-23 23:09:21'),
	(82, 47, 0, 'What is your favorite Imagine Dragons song?', '2026-03-23 23:09:23'),
	(83, 47, 0, 'What is your favorite Imagine Dragons song?', '2026-03-23 23:09:25'),
	(84, 47, 0, 'What is your favorite Imagine Dragons song?', '2026-03-23 23:09:27'),
	(85, 47, 0, 'Here\'s a beginner-level question based on the provided phrases:\n\nWhat do you like to do the most?', '2026-03-23 23:09:30'),
	(86, 47, 0, 'Where can I buy the plane ticket?', '2026-03-23 23:09:43'),
	(87, 47, 0, 'Where can I buy the plane ticket?', '2026-03-23 23:09:47'),
	(88, 47, 0, 'Where can I buy the plane ticket?', '2026-03-23 23:09:55'),
	(89, 47, 0, 'What is your favorite Imagine Dragons song?', '2026-03-23 23:11:22'),
	(90, 47, 0, 'What is your favorite Imagine Dragons song?', '2026-03-23 23:47:08'),
	(91, 47, 0, 'Where can I buy the plane ticket?', '2026-03-23 23:47:20'),
	(92, 47, 0, 'What is your favorite Imagine Dragons song?', '2026-03-23 23:47:22'),
	(93, 47, 0, 'Where do I buy the plane ticket?', '2026-03-23 23:47:26');

-- Copiando estrutura para tabela u712858045_zaldemy.planos
CREATE TABLE IF NOT EXISTS `planos` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `plano` varchar(50) DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- Copiando dados para a tabela u712858045_zaldemy.planos: ~3 rows (aproximadamente)
INSERT INTO `planos` (`id`, `plano`) VALUES
	(1, 'premium'),
	(2, 'free'),
	(3, 'limitado');

-- Copiando estrutura para tabela u712858045_zaldemy.redes_sociais
CREATE TABLE IF NOT EXISTS `redes_sociais` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `nome` varchar(100) NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=17 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Copiando dados para a tabela u712858045_zaldemy.redes_sociais: ~16 rows (aproximadamente)
INSERT INTO `redes_sociais` (`id`, `nome`) VALUES
	(1, 'Facebook'),
	(2, 'Instagram'),
	(3, 'WhatsApp'),
	(4, 'TikTok'),
	(5, 'YouTube'),
	(6, 'X (Twitter)'),
	(7, 'LinkedIn'),
	(8, 'Pinterest'),
	(9, 'Snapchat'),
	(10, 'Reddit'),
	(11, 'Telegram'),
	(12, 'Discord'),
	(13, 'Kwai'),
	(14, 'Threads'),
	(15, 'Tumblr'),
	(16, 'Twitch');

-- Copiando estrutura para tabela u712858045_zaldemy.treino
CREATE TABLE IF NOT EXISTS `treino` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `status` varchar(50) DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- Copiando dados para a tabela u712858045_zaldemy.treino: ~4 rows (aproximadamente)
INSERT INTO `treino` (`id`, `status`) VALUES
	(1, 'nao_conheco'),
	(2, 'memorizando'),
	(3, 'em_treino'),
	(4, 'memorizado');

-- Copiando estrutura para tabela u712858045_zaldemy.treino_data_atualizacao
CREATE TABLE IF NOT EXISTS `treino_data_atualizacao` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `data_atualizacao` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `id_frase` int(11) DEFAULT NULL,
  `id_treino` int(11) DEFAULT NULL,
  `status_id` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `FK__frases` (`id_frase`),
  KEY `FK_treino_data_atualizacao_treino` (`id_treino`),
  CONSTRAINT `FK__frases` FOREIGN KEY (`id_frase`) REFERENCES `frases` (`id`),
  CONSTRAINT `FK_treino_data_atualizacao_treino` FOREIGN KEY (`id_treino`) REFERENCES `treino` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=344 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- Copiando dados para a tabela u712858045_zaldemy.treino_data_atualizacao: ~100 rows (aproximadamente)
INSERT INTO `treino_data_atualizacao` (`id`, `data_atualizacao`, `id_frase`, `id_treino`, `status_id`) VALUES
	(226, '2026-03-17 22:54:12', 61, 1, 1),
	(227, '2026-03-17 22:54:47', 62, 1, 1),
	(228, '2026-03-17 23:00:23', 63, 1, 1),
	(229, '2026-03-18 14:03:24', 63, 3, 1),
	(230, '2026-03-18 14:03:24', 62, 3, 1),
	(231, '2026-03-18 14:03:24', 61, 3, 1),
	(238, '2026-03-18 14:34:31', 70, 1, 1),
	(239, '2026-03-18 14:34:54', 71, 1, 1),
	(240, '2026-03-18 14:34:54', 72, 1, 1),
	(241, '2026-03-18 14:34:54', 73, 1, 1),
	(242, '2026-03-18 14:34:54', 74, 1, 1),
	(243, '2026-03-18 14:34:54', 75, 1, 1),
	(244, '2026-03-18 14:35:31', 76, 1, 1),
	(245, '2026-03-18 14:35:31', 77, 1, 1),
	(246, '2026-03-18 14:35:31', 78, 1, 1),
	(247, '2026-03-18 14:35:32', 79, 1, 1),
	(248, '2026-03-18 14:35:32', 80, 1, 1),
	(249, '2026-03-18 14:35:32', 81, 1, 1),
	(250, '2026-03-18 14:36:04', 70, 2, 1),
	(251, '2026-03-18 14:55:06', 82, 1, 1),
	(252, '2026-03-18 14:55:09', 83, 1, 1),
	(253, '2026-03-18 14:55:09', 84, 1, 1),
	(255, '2026-03-20 17:15:12', 82, 3, 1),
	(267, '2026-03-18 18:43:02', 97, 1, 1),
	(268, '2026-03-18 18:43:02', 98, 1, 1),
	(269, '2026-03-18 18:43:02', 99, 1, 1),
	(270, '2026-03-18 18:51:11', 100, 1, 1),
	(271, '2026-03-18 18:51:11', 101, 1, 1),
	(272, '2026-03-18 18:51:11', 102, 1, 1),
	(273, '2026-03-18 18:51:11', 103, 1, 1),
	(274, '2026-03-18 18:51:11', 104, 1, 1),
	(275, '2026-03-18 19:29:05', 105, 1, 1),
	(276, '2026-03-18 19:29:22', 106, 1, 1),
	(277, '2026-03-18 19:58:42', 107, 1, 1),
	(278, '2026-03-18 19:58:42', 108, 1, 1),
	(279, '2026-03-18 22:04:21', 104, 2, 1),
	(280, '2026-03-18 22:04:21', 103, 2, 1),
	(281, '2026-03-18 22:04:21', 102, 2, 1),
	(282, '2026-03-18 22:04:21', 100, 2, 1),
	(283, '2026-03-18 22:04:21', 99, 2, 1),
	(284, '2026-03-18 22:04:45', 101, 2, 1),
	(285, '2026-03-18 22:06:18', 109, 1, 1),
	(286, '2026-03-18 22:06:58', 110, 1, 1),
	(287, '2026-03-18 22:07:32', 111, 1, 1),
	(288, '2026-03-18 22:08:07', 112, 1, 1),
	(289, '2026-03-18 22:10:48', 113, 1, 1),
	(290, '2026-03-18 22:11:39', 114, 1, 1),
	(291, '2026-03-18 22:26:52', 115, 1, 1),
	(292, '2026-03-18 22:26:52', 116, 1, 1),
	(293, '2026-03-18 22:28:19', 117, 1, 1),
	(294, '2026-03-18 22:28:19', 118, 1, 1),
	(295, '2026-03-18 22:28:19', 119, 1, 1),
	(296, '2026-03-19 22:22:28', 119, 3, 1),
	(297, '2026-03-19 22:22:28', 118, 3, 1),
	(298, '2026-03-19 22:22:28', 117, 3, 1),
	(299, '2026-03-18 22:37:41', 120, 1, 1),
	(300, '2026-03-19 10:46:45', 108, 3, 1),
	(301, '2026-03-19 11:01:58', 107, 3, 1),
	(302, '2026-03-19 11:18:49', 108, 4, 1),
	(303, '2026-03-19 11:18:49', 107, 4, 1),
	(304, '2026-03-19 11:19:22', 108, 3, 1),
	(305, '2026-03-19 11:18:59', 107, 2, 1),
	(306, '2026-03-19 11:27:48', 108, 4, 1),
	(307, '2026-03-19 11:28:51', 108, 3, 1),
	(308, '2026-03-19 11:29:08', 108, 4, 1),
	(309, '2026-03-19 11:47:32', 63, 4, 1),
	(310, '2026-03-19 11:47:32', 62, 4, 1),
	(311, '2026-03-19 11:47:32', 61, 4, 1),
	(312, '2026-03-19 11:54:59', 121, 1, 1),
	(313, '2026-03-19 12:01:25', 108, 2, 1),
	(314, '2026-03-19 22:00:19', 122, 1, 1),
	(315, '2026-03-19 22:13:00', 123, 1, 1),
	(316, '2026-03-19 22:13:43', 124, 1, 1),
	(317, '2026-03-19 22:15:06', 125, 1, 1),
	(318, '2026-03-19 22:16:01', 126, 1, 1),
	(319, '2026-03-19 22:16:54', 127, 1, 1),
	(320, '2026-03-20 23:00:49', 127, 3, 1),
	(321, '2026-03-20 23:00:49', 126, 3, 1),
	(322, '2026-03-20 23:00:49', 125, 3, 1),
	(323, '2026-03-19 22:23:05', 119, 4, 1),
	(324, '2026-03-19 22:23:05', 118, 4, 1),
	(325, '2026-03-19 22:23:05', 117, 4, 1),
	(326, '2026-03-20 13:20:12', 128, 1, 1),
	(327, '2026-03-20 13:20:34', 129, 1, 1),
	(328, '2026-03-20 13:20:50', 130, 1, 1),
	(329, '2026-03-20 17:15:19', 82, 4, 1),
	(330, '2026-03-20 17:18:03', 81, 2, 1),
	(331, '2026-03-20 17:18:03', 80, 2, 1),
	(332, '2026-03-20 17:18:03', 79, 2, 1),
	(333, '2026-03-20 17:18:43', 82, 2, 1),
	(334, '2026-03-20 17:19:02', 131, 1, 1),
	(335, '2026-03-20 17:19:02', 132, 1, 1),
	(336, '2026-03-20 17:19:02', 133, 1, 1),
	(337, '2026-03-20 21:12:49', 134, 1, 1),
	(338, '2026-03-20 21:12:49', 135, 1, 1),
	(339, '2026-03-20 21:12:49', 136, 1, 1),
	(340, '2026-03-20 21:12:49', 137, 1, 1),
	(341, '2026-03-22 20:22:21', 138, 1, 1),
	(342, '2026-03-22 20:22:46', 139, 1, 1),
	(343, '2026-03-22 21:12:43', 120, 2, 1);

-- Copiando estrutura para tabela u712858045_zaldemy.usuarios
CREATE TABLE IF NOT EXISTS `usuarios` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `nome` varchar(50) DEFAULT NULL,
  `email` varchar(50) DEFAULT NULL,
  `password` varchar(255) DEFAULT NULL,
  `update_date` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `create_date` datetime NOT NULL DEFAULT current_timestamp(),
  `email_verified` tinyint(1) NOT NULL DEFAULT 0,
  `email_token` varchar(64) DEFAULT NULL,
  `step` int(11) DEFAULT NULL,
  `id_idioma_referencia` int(11) DEFAULT NULL,
  `plano` int(11) DEFAULT NULL,
  `status_id` int(11) DEFAULT NULL,
  `auth_token` varchar(64) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `FK_usuarios_idioma_referencia` (`id_idioma_referencia`),
  KEY `FK_usuarios_planos` (`plano`),
  CONSTRAINT `FK_usuarios_idioma_referencia` FOREIGN KEY (`id_idioma_referencia`) REFERENCES `idioma_referencia` (`id`),
  CONSTRAINT `FK_usuarios_planos` FOREIGN KEY (`plano`) REFERENCES `planos` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=55 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- Copiando dados para a tabela u712858045_zaldemy.usuarios: ~9 rows (aproximadamente)
INSERT INTO `usuarios` (`id`, `nome`, `email`, `password`, `update_date`, `create_date`, `email_verified`, `email_token`, `step`, `id_idioma_referencia`, `plano`, `status_id`, `auth_token`) VALUES
	(32, 'marcios', 'marciossilva.dev@gmail.com', '$2y$10$JQ5n5DQbp6V3Lq9l45Co2uJOCtivj9PokAgokqQjmJHIjUSFk0a.O', '2026-03-20 17:05:15', '2026-02-19 17:00:02', 1, NULL, 3, NULL, 1, NULL, 'f0a11a55500b251f42430621390afdf226e6efb04d255b33d16e1d89f3f655d3'),
	(40, 'adm LNU', 'adm@zaldemy.com', NULL, '2026-03-13 21:29:28', '2026-03-13 21:04:19', 1, NULL, NULL, NULL, NULL, 0, 'c807050e33b451530cce325304bf6fc73ceb46c68d7fb0411ed135be87af62fb'),
	(42, 'alicia correia paixão', 'aliciacorreiapaixao@gmail.com', NULL, '2026-03-18 01:43:31', '2026-03-15 22:17:43', 1, NULL, 2, NULL, 2, NULL, '40f444f34f15aed6e2f419adb7273a57e150d330fa8e36d6d904e40814500403'),
	(47, 'Marcios Silva', 'marciosunico18@gmail.com', NULL, '2026-03-23 20:07:19', '2026-03-17 22:48:39', 1, NULL, 3, NULL, 1, NULL, 'cea4c3c5e3e3140fd6b142375535b397fffa52787d9270b27ee77e913eb92636'),
	(49, 'MARCOS NASCIMENTO', 'marcosofthard2008@gmail.com', NULL, '2026-03-21 02:11:13', '2026-03-18 14:31:21', 1, NULL, 3, NULL, 1, NULL, '87bc66ae8a2fd96b2a2be2a69a678d837445c358c23fda5cb8acec1b76128a13'),
	(50, 'Daniel Monteiro', 'daniel@kfe.com.br', '$2y$10$AEIlSlbC4CV8FFw9wcBCyO1G83dhcQcbMFHPGr5Ars9BExXCja1ku', '2026-03-21 02:11:31', '2026-03-18 14:32:06', 0, '4da849ead85f61f56c35ab95c1500ef5608328e4ad00c88bee4e23fbe195b0fb', 3, NULL, 1, NULL, '0014807a3abd6447cef7415c3499b1f9faa189d567fc8c7a34eb079f4611cfc5'),
	(51, 'Marcos Nascimento', 'kfe@kfe.com.br', NULL, '2026-03-21 02:10:55', '2026-03-18 14:32:28', 1, NULL, 3, NULL, 1, NULL, '98dbd4b4364cc57b16f4c7a149a122e964974148d6bc9ae72dc61533b6718572'),
	(53, 'Marcios Paixão', 'marcios@kfe.com.br', NULL, '2026-03-20 12:00:23', '2026-03-18 19:58:42', 1, NULL, 3, NULL, 2, NULL, '2c8c156e379b8f190d735abb2cbd51d54bf7ab63a75e810b8441e0730cb775bd'),
	(54, 'Andresa Correia paixão', 'correiapaixaoandresa@gmail.com', NULL, '2026-03-22 21:00:12', '2026-03-18 22:26:52', 1, NULL, 3, NULL, 2, NULL, 'abe733f18c5ad526cfad7257a2ca4f568a4898d994719e60ebafec5847b499fd');

/*!40103 SET TIME_ZONE=IFNULL(@OLD_TIME_ZONE, 'system') */;
/*!40101 SET SQL_MODE=IFNULL(@OLD_SQL_MODE, '') */;
/*!40014 SET FOREIGN_KEY_CHECKS=IFNULL(@OLD_FOREIGN_KEY_CHECKS, 1) */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40111 SET SQL_NOTES=IFNULL(@OLD_SQL_NOTES, 1) */;
