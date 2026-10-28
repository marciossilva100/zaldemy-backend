<?php

// Script de linha de comando (cron) - gera 1 frase nova por dia pra cada
// categoria ativa de quem ativou a opção em Configurações (plano Premium
// apenas, ver CategoriaFraseDiariaIA::usuariosElegiveis). Resolve o relato
// do usuário: hoje a IA só gera frases no momento em que a categoria é
// criada, nunca mais depois disso.
// Uso: php cron/gerar_frase_diaria_categorias.php

chdir(__DIR__);

require_once __DIR__ . '/../server.php';
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../model/CategoriaFraseDiariaIA.php';
require_once __DIR__ . '/../model/Categorias.php';
require_once __DIR__ . '/../model/Nivel.php';
require_once __DIR__ . '/../api/OpenAiChat.php';

if (!isset($_ENV['OPEN_AI'])) {
    fwrite(STDERR, "OPEN_AI não configurado no .env\n");
    exit(1);
}

$chat = new OpenAiChat($_ENV['OPEN_AI'], 'gpt-5-mini');

$usuarios = CategoriaFraseDiariaIA::usuariosElegiveis($pdo);
$categoriasProcessadas = 0;
$frasesInseridas = 0;

foreach ($usuarios as $userId) {
    foreach (CategoriaFraseDiariaIA::categoriasElegiveis($pdo, (int) $userId) as $categoria) {
        $inseridas = CategoriaFraseDiariaIA::gerarParaCategoria($pdo, $chat, (int) $userId, $categoria);
        $categoriasProcessadas++;
        $frasesInseridas += $inseridas ?? 0;

        // Garante a conexão viva entre categorias - loop pode levar minutos
        // em contas com muitas categorias, tempo suficiente pra derrubar
        // uma conexão MySQL ociosa em hospedagem compartilhada (mesmo
        // cuidado de garantirConexaoViva() usado nos outros treinos de IA).
        garantirConexaoViva($pdo);
    }
}

echo "{$categoriasProcessadas} categoria(s) processada(s), {$frasesInseridas} frase(s) inserida(s).\n";

// Crontab (ajustar horário se quiser, caminho já é o real de produção):
// 0 5 * * * php /home/u712858045/domains/zaldemy.com/public_html/api/cron/gerar_frase_diaria_categorias.php >> /home/u712858045/domains/zaldemy.com/public_html/api/cron/categoria_frase_diaria.log 2>&1
