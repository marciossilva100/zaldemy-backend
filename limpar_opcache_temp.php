<?php
// Script temporário - apaga depois de usar uma vez.
// Força o PHP a recarregar os arquivos do disco em vez de usar o bytecode
// compilado em cache (OPcache), que não é invalidado automaticamente só
// porque o conteúdo do arquivo mudou via git pull em alguns hosts.

if (function_exists('opcache_reset')) {
    $ok = opcache_reset();
    echo $ok ? "OPcache limpo com sucesso.\n" : "opcache_reset() retornou false (talvez já estava vazio ou desabilitado pra essa execução).\n";
} else {
    echo "opcache_reset() não existe nesse ambiente (OPcache pode estar desligado via CLI only ou indisponível).\n";
}

if (function_exists('opcache_get_status')) {
    $status = opcache_get_status(false);
    echo "OPcache habilitado: " . (($status['opcache_enabled'] ?? false) ? 'sim' : 'não') . "\n";
}
