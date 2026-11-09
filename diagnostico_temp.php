<?php
// Script temporário de diagnóstico - apagar depois de usar.
header('Content-Type: text/plain; charset=utf-8');

require_once __DIR__ . '/model/Configuracoes.php';

echo "=== Verificando model/Configuracoes.php ===\n";
echo "Caminho real do arquivo carregado: " . (new ReflectionClass('Configuracoes'))->getFileName() . "\n";
echo "Data de modificação do arquivo: " . date('Y-m-d H:i:s', filemtime((new ReflectionClass('Configuracoes'))->getFileName())) . "\n\n";

$metodosEsperados = [
    'atualizarVelocidadeCardFrente',
    'atualizarVelocidadeCardVerso',
    'atualizarVelocidadeVozNaturalIa',
];

foreach ($metodosEsperados as $metodo) {
    echo "$metodo: " . (method_exists('Configuracoes', $metodo) ? "EXISTE" : "NÃO EXISTE") . "\n";
}

echo "\n=== Verificando controller/configuracoes.php ===\n";
$controllerPath = __DIR__ . '/controller/configuracoes.php';
echo "Data de modificação: " . date('Y-m-d H:i:s', filemtime($controllerPath)) . "\n";
$conteudo = file_get_contents($controllerPath);
$acoesEsperadas = [
    'atualizar_velocidade_card_frente',
    'atualizar_velocidade_card_verso',
    'atualizar_velocidade_voz_natural_ia',
];
foreach ($acoesEsperadas as $acao) {
    echo "Action '$acao' no controller: " . (strpos($conteudo, $acao) !== false ? "ENCONTRADA" : "NÃO ENCONTRADA") . "\n";
}

echo "\n=== Testando a coluna no banco ===\n";
try {
    require_once __DIR__ . '/server.php';
    $stmt = $pdo->query("SHOW COLUMNS FROM configuracoes LIKE 'velocidade%'");
    $colunas = $stmt->fetchAll(PDO::FETCH_COLUMN);
    echo "Colunas encontradas: " . (empty($colunas) ? "NENHUMA" : implode(', ', $colunas)) . "\n";
} catch (Throwable $e) {
    echo "Erro ao consultar banco: " . $e->getMessage() . "\n";
}

echo "\n=== PHP info ===\n";
echo "Versão PHP: " . phpversion() . "\n";
echo "OPcache habilitado: " . (function_exists('opcache_get_status') && opcache_get_status(false) ? 'sim' : 'não') . "\n";
