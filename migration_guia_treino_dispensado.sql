-- Executar manualmente no banco de produção/homologação - nova flag pro
-- guia "toque em Treinar" na Home (mesmo padrão de guia_categoria_dispensado).
ALTER TABLE usuarios
  ADD COLUMN guia_treino_dispensado TINYINT(1) NOT NULL DEFAULT 0;
