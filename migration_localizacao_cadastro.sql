-- Executar manualmente no banco de produção/homologação antes do deploy -
-- guarda IP e localização (país/região/cidade, via geoip do IP) no momento
-- do cadastro, pra saber de onde cada novo usuário se cadastrou. Captura é
-- feita 1 única vez, no registro (controller/auth.php, action "register").
ALTER TABLE `usuarios`
  ADD COLUMN `ip_cadastro` varchar(45) DEFAULT NULL AFTER `apelido_definido_pelo_usuario`,
  ADD COLUMN `pais_cadastro` varchar(100) DEFAULT NULL AFTER `ip_cadastro`,
  ADD COLUMN `regiao_cadastro` varchar(100) DEFAULT NULL AFTER `pais_cadastro`,
  ADD COLUMN `cidade_cadastro` varchar(100) DEFAULT NULL AFTER `regiao_cadastro`;
