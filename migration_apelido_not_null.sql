-- Rodar SÓ depois de confirmar que nenhum usuário está com apelido NULL
-- (migration_apelido.sql + dev-backfill-apelido.php já executados).
-- Conferir antes: SELECT COUNT(*) FROM usuarios WHERE apelido IS NULL;
-- (tem que dar 0)
ALTER TABLE usuarios MODIFY apelido VARCHAR(20) NOT NULL;
