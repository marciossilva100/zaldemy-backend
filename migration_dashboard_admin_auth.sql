-- Login próprio do /dashboard-admin, totalmente independente da conta de
-- usuário do app (Marcios pediu de propósito: se a senha da conta do
-- Zaldemy for comprometida, o dashboard administrativo continua protegido).
-- Usa autenticação de dois fatores por app autenticador (TOTP, RFC 6238 -
-- Google Authenticator/Authy), não por e-mail.
CREATE TABLE IF NOT EXISTS `dashboard_admin_contas` (
    `id` INT NOT NULL AUTO_INCREMENT,
    `usuario` VARCHAR(100) NOT NULL,
    `senha_hash` VARCHAR(255) NOT NULL,
    `totp_secret` VARCHAR(64) NULL,
    `totp_confirmado` TINYINT(1) NOT NULL DEFAULT 0,
    `tentativas_falhas` INT NOT NULL DEFAULT 0,
    `bloqueado_until` DATETIME NULL,
    `data_criacao` DATETIME DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_dashboard_admin_usuario` (`usuario`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- Cobre os dois estágios do login: 'pendente_totp' (senha já confirmada,
-- esperando o código do app autenticador - expira em poucos minutos) e
-- 'ativa' (sessão de admin de verdade, depois do TOTP confirmado).
CREATE TABLE IF NOT EXISTS `dashboard_admin_sessoes` (
    `id` INT NOT NULL AUTO_INCREMENT,
    `conta_id` INT NOT NULL,
    `token` VARCHAR(128) NOT NULL,
    `status` ENUM('pendente_totp', 'ativa') NOT NULL DEFAULT 'pendente_totp',
    `expira_em` DATETIME NOT NULL,
    `data_criacao` DATETIME DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_dashboard_admin_sessoes_token` (`token`),
    KEY `idx_dashboard_admin_sessoes_conta` (`conta_id`),
    CONSTRAINT `fk_dashboard_admin_sessoes_conta` FOREIGN KEY (`conta_id`) REFERENCES `dashboard_admin_contas` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
