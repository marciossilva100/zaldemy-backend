-- Tela "como você conheceu o Zaldemy" (ReferenciaUsuário.jsx) só tinha 8
-- opções fixas (redes sociais específicas), sem nenhuma saída pra quem não
-- se encaixa em nenhuma delas (indicação de amigo, busca orgânica, etc.) -
-- esse usuário ficava sem conseguir avançar o onboarding (step preso em 2,
-- nenhum registro em canal_aquisicao). Adiciona uma opção "Outro" genérica.
INSERT INTO redes_sociais (nome) VALUES ('Outro');
