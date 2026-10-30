CREATE TABLE IF NOT EXISTS nivel_usuario_idioma (
    id INT NOT NULL AUTO_INCREMENT,
    id_user INT NOT NULL,
    idioma_nativo INT NOT NULL,
    idioma_aprender INT NOT NULL,
    nivel INT NOT NULL DEFAULT 1,
    nivel_sugestao_dispensada INT DEFAULT NULL,
    data_criacao DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    data_atualizacao DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uk_nivel_usuario_idioma (id_user, idioma_nativo, idioma_aprender),
    KEY idx_nivel_usuario_idioma_user (id_user),
    CONSTRAINT fk_nivel_usuario_idioma_usuario FOREIGN KEY (id_user) REFERENCES usuarios (id)
);

-- Migra o nível global atual (usuarios.nivel) pro par de idioma ATIVO hoje
-- de cada usuário (idioma_referencia) - sem isso, todo mundo perderia o
-- nível já configurado assim que essa tabela passasse a ser a fonte de
-- verdade. Pares de idioma que o usuário ainda venha a estudar no futuro
-- (ou já tenha estudado antes, mas não seja o par ativo agora) começam sem
-- linha aqui e caem no padrão intermediário até serem definidos.
INSERT INTO nivel_usuario_idioma (id_user, idioma_nativo, idioma_aprender, nivel)
SELECT ir.id_user, ir.idioma_nativo, ir.idioma_aprender, COALESCE(u.nivel, 2)
FROM idioma_referencia ir
INNER JOIN usuarios u ON u.id = ir.id_user
WHERE ir.idioma_nativo IS NOT NULL AND ir.idioma_aprender IS NOT NULL
ON DUPLICATE KEY UPDATE nivel = VALUES(nivel);
