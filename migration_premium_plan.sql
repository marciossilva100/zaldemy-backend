-- Executar manualmente no banco de produção/homolog antes do deploy do
-- backend da branch feature/premium-plan (mesclada em develop).

-- ============================================================
-- 1) perguntas_ia (tabela já existe) - novas colunas
-- ============================================================
ALTER TABLE perguntas_ia
  ADD COLUMN transcricao TEXT DEFAULT NULL,
  ADD COLUMN nota INT DEFAULT NULL,
  ADD COLUMN feedback TEXT DEFAULT NULL,
  ADD COLUMN tentativas INT NOT NULL DEFAULT 0,
  ADD COLUMN question_traducao TEXT DEFAULT NULL;

-- ============================================================
-- 2) frase_dia_ia (tabela nova - recurso "Frase do Dia")
-- ============================================================
CREATE TABLE frase_dia_ia (
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
CREATE TABLE categoria_ia_uso (
  id INT NOT NULL AUTO_INCREMENT,
  user_id INT NOT NULL,
  data_criacao DATETIME DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_user (user_id)
);

-- ============================================================
-- 4) traducao_ia_uso (tabela nova - controle de uso da Tradução por IA)
-- ============================================================
CREATE TABLE traducao_ia_uso (
  id INT NOT NULL AUTO_INCREMENT,
  user_id INT NOT NULL,
  data_criacao DATETIME DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_user (user_id)
);

-- ============================================================
-- 5) acessos_usuario (tabela nova - histórico de acessos/login)
-- ============================================================
CREATE TABLE acessos_usuario (
  id INT NOT NULL AUTO_INCREMENT,
  user_id INT NOT NULL,
  ip VARCHAR(45) DEFAULT NULL,
  user_agent VARCHAR(255) DEFAULT NULL,
  data_acesso DATETIME DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_user (user_id)
);
