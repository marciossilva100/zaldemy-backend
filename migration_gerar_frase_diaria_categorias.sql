-- Executar manualmente no banco de produção/homologação antes do deploy do
-- recurso "frase diária automática por categoria" (plano Premium, opt-in).
ALTER TABLE `configuracoes`
  ADD COLUMN `gerar_frase_diaria_categorias` TINYINT(1) NOT NULL DEFAULT 0 AFTER `quantidade_frases_aprender`;

-- Evita gerar mais de uma frase por categoria no mesmo dia (cron pode rodar
-- mais de uma vez, ou ser reprocessado) - mesmo padrão de dedup de
-- notificacoes_enviadas (ver migration_push_notifications.sql).
CREATE TABLE IF NOT EXISTS `categoria_frase_diaria_ia` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `categoria_id` int(11) NOT NULL,
  `data_geracao` date NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_categoria_data` (`categoria_id`, `data_geracao`),
  KEY `FK_categoria_frase_diaria_categorias` (`categoria_id`),
  CONSTRAINT `FK_categoria_frase_diaria_categorias` FOREIGN KEY (`categoria_id`) REFERENCES `categorias` (`id`) ON DELETE CASCADE ON UPDATE NO ACTION
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
