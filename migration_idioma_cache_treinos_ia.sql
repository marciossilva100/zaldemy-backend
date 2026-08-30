-- Bug real: frase_dia_ia/perguntas_ia/traducao_reversa_ia (cache da
-- pendência "de hoje" de cada treino de IA) não guardavam qual par de
-- idiomas gerou aquele conteúdo. Se o usuário trocasse de idioma
-- aprendendo no mesmo dia, getPendente() continuava devolvendo a
-- pendência antiga (de outro idioma) porque não tinha coluna pra filtrar
-- por isso - reportado como "treino permite acessar com frases em inglês
-- mesmo com o idioma da Home setado pra outro".
-- Rodar só uma vez (não é idempotente - ADD COLUMN sem IF NOT EXISTS).
ALTER TABLE frase_dia_ia
    ADD COLUMN idioma_nativo INT NULL,
    ADD COLUMN idioma_aprender INT NULL;

ALTER TABLE perguntas_ia
    ADD COLUMN idioma_nativo INT NULL,
    ADD COLUMN idioma_aprender INT NULL;

ALTER TABLE traducao_reversa_ia
    ADD COLUMN idioma_nativo INT NULL,
    ADD COLUMN idioma_aprender INT NULL;
