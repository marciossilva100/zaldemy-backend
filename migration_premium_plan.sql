-- Executar manualmente no banco de produção antes do deploy do backend.
--
-- Seguro rodar mais de uma vez (idempotente): CREATE TABLE usa IF NOT
-- EXISTS (padrão, funciona em qualquer MySQL/MariaDB). Pra ADD COLUMN,
-- cada coluna checa a própria existência em information_schema antes de
-- alterar - funciona igual nos dois bancos (ADD COLUMN IF NOT EXISTS é só
-- do MariaDB, MySQL rejeita). Testado localmente (MySQL 8).
--
-- Levantamento feito comparando o banco local (com tudo desta sessão) com
-- o último backup do servidor (zaldemy-20260728.sql) - cobre tabelas que
-- nunca chegaram a ser migradas, não só as mais recentes.

-- ============================================================
-- 1) perguntas_ia (tabela já existe) - novas colunas
-- ============================================================
SET @existe := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='perguntas_ia' AND COLUMN_NAME='transcricao');
SET @sql := IF(@existe = 0, 'ALTER TABLE perguntas_ia ADD COLUMN transcricao TEXT DEFAULT NULL', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @existe := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='perguntas_ia' AND COLUMN_NAME='nota');
SET @sql := IF(@existe = 0, 'ALTER TABLE perguntas_ia ADD COLUMN nota INT DEFAULT NULL', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @existe := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='perguntas_ia' AND COLUMN_NAME='feedback');
SET @sql := IF(@existe = 0, 'ALTER TABLE perguntas_ia ADD COLUMN feedback TEXT DEFAULT NULL', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @existe := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='perguntas_ia' AND COLUMN_NAME='tentativas');
SET @sql := IF(@existe = 0, 'ALTER TABLE perguntas_ia ADD COLUMN tentativas INT NOT NULL DEFAULT 0', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @existe := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='perguntas_ia' AND COLUMN_NAME='question_traducao');
SET @sql := IF(@existe = 0, 'ALTER TABLE perguntas_ia ADD COLUMN question_traducao TEXT DEFAULT NULL', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ============================================================
-- 2) frase_dia_ia (tabela nova - recurso "Frase do Dia")
-- ============================================================
CREATE TABLE IF NOT EXISTS frase_dia_ia (
  id INT NOT NULL AUTO_INCREMENT,
  user_id INT NOT NULL,
  frase TEXT NOT NULL,
  transcricao TEXT DEFAULT NULL,
  nota INT DEFAULT NULL,
  feedback_gramatica TEXT DEFAULT NULL,
  feedback_pronuncia TEXT DEFAULT NULL,
  feedback_fluencia TEXT DEFAULT NULL,
  status_id INT NOT NULL DEFAULT 0,
  data_criacao DATETIME DEFAULT CURRENT_TIMESTAMP,
  tentativas INT NOT NULL DEFAULT 0,
  frase_traducao TEXT DEFAULT NULL,
  PRIMARY KEY (id),
  KEY idx_user_data (user_id, data_criacao)
);

-- ============================================================
-- 3) categoria_ia_uso (tabela nova - controle de uso da Categoria por IA)
-- ============================================================
CREATE TABLE IF NOT EXISTS categoria_ia_uso (
  id INT NOT NULL AUTO_INCREMENT,
  user_id INT NOT NULL,
  data_criacao DATETIME DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_user (user_id)
);

-- ============================================================
-- 4) traducao_ia_uso (tabela nova - controle de uso da Tradução por IA)
-- ============================================================
CREATE TABLE IF NOT EXISTS traducao_ia_uso (
  id INT NOT NULL AUTO_INCREMENT,
  user_id INT NOT NULL,
  data_criacao DATETIME DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_user (user_id)
);

-- ============================================================
-- 5) acessos_usuario (tabela nova - histórico de acessos/login)
-- ============================================================
CREATE TABLE IF NOT EXISTS acessos_usuario (
  id INT NOT NULL AUTO_INCREMENT,
  user_id INT NOT NULL,
  ip VARCHAR(45) DEFAULT NULL,
  user_agent VARCHAR(255) DEFAULT NULL,
  data_acesso DATETIME DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_user (user_id)
);

-- ============================================================
-- 6) audio_ia_uso (tabela nova - controle de uso da voz natural/TTS
--    premium) - mais antiga que as outras, mas nunca tinha sido migrada
-- ============================================================
CREATE TABLE IF NOT EXISTS audio_ia_uso (
  id INT NOT NULL AUTO_INCREMENT,
  user_id INT NOT NULL,
  data_criacao DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_user_data (user_id, data_criacao)
);

-- ============================================================
-- 7) usuarios.nivel (coluna nova - nível de proficiência informado no
--    cadastro, usado pra ajustar a dificuldade das perguntas/frases de IA)
-- ============================================================
SET @existe := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='usuarios' AND COLUMN_NAME='nivel');
SET @sql := IF(@existe = 0, 'ALTER TABLE usuarios ADD COLUMN nivel INT DEFAULT NULL', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ============================================================
-- 8) usuarios - colunas novas da assinatura Zaldemy+ via Stripe
-- ============================================================
SET @existe := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='usuarios' AND COLUMN_NAME='stripe_customer_id');
SET @sql := IF(@existe = 0, 'ALTER TABLE usuarios ADD COLUMN stripe_customer_id VARCHAR(255) DEFAULT NULL', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @existe := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='usuarios' AND COLUMN_NAME='stripe_subscription_id');
SET @sql := IF(@existe = 0, 'ALTER TABLE usuarios ADD COLUMN stripe_subscription_id VARCHAR(255) DEFAULT NULL', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @existe := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='usuarios' AND COLUMN_NAME='assinatura_status');
SET @sql := IF(@existe = 0, 'ALTER TABLE usuarios ADD COLUMN assinatura_status VARCHAR(50) DEFAULT NULL', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ============================================================
-- 9) configuracoes.voz_tts / velocidade_tts (preferência de voz e
--    velocidade da narração) - também nunca tinha sido migrada
-- ============================================================
SET @existe := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='configuracoes' AND COLUMN_NAME='voz_tts');
SET @sql := IF(@existe = 0, "ALTER TABLE configuracoes ADD COLUMN voz_tts VARCHAR(20) DEFAULT 'nova'", 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @existe := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='configuracoes' AND COLUMN_NAME='velocidade_tts');
SET @sql := IF(@existe = 0, "ALTER TABLE configuracoes ADD COLUMN velocidade_tts DECIMAL(3,2) DEFAULT '1.00'", 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ============================================================
-- 10) usuarios.interesses_definidos (coluna nova - marca se o usuário já
--     escolheu as 3 categorias de interesse no onboarding). Faltando essa
--     coluna, controller/me.php quebra em TODA chamada (SELECT inclui a
--     coluna), o que derruba o checkAuth e faz o login (inclusive o de
--     Google) voltar pra tela de login sem erro visível - mesmo sintoma já
--     visto antes com a coluna usuarios.nivel faltando.
-- ============================================================
SET @existe := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='usuarios' AND COLUMN_NAME='interesses_definidos');
SET @sql := IF(@existe = 0, 'ALTER TABLE usuarios ADD COLUMN interesses_definidos TINYINT(1) NOT NULL DEFAULT 0', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ============================================================
-- 11) usuarios.assinatura_cancelamento_previsto (coluna nova - data em que a
--     assinatura Zaldemy+ vai efetivamente encerrar, quando o usuário pediu
--     cancelamento mas ainda está dentro do período já pago). NULL = nenhum
--     cancelamento agendado.
-- ============================================================
SET @existe := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='usuarios' AND COLUMN_NAME='assinatura_cancelamento_previsto');
SET @sql := IF(@existe = 0, 'ALTER TABLE usuarios ADD COLUMN assinatura_cancelamento_previsto DATETIME DEFAULT NULL', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
