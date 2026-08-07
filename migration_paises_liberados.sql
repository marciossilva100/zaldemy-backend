-- Executar manualmente no banco de produção/homologação. Lista dos países
-- onde o cadastro está liberado - fora dela, novo cadastro é bloqueado (quem
-- já tem conta continua acessando normalmente). Vazio = ninguém consegue se
-- cadastrar, então já entra com o Brasil liberado.
CREATE TABLE IF NOT EXISTS `paises_liberados` (
  `codigo` char(2) NOT NULL,
  `nome` varchar(100) NOT NULL,
  `criado_em` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`codigo`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO `paises_liberados` (`codigo`, `nome`) VALUES ('BR', 'Brasil');
