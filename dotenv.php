<?php

function carregarEnv($path)
{
    if (!file_exists($path)) return;

    $linhas = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

    foreach ($linhas as $linha) {
        if (str_starts_with(trim($linha), '#')) continue;

        [$nome, $valor] = explode('=', $linha, 2);

        $nome = trim($nome);
        $valor = trim($valor);

        $_ENV[$nome] = $valor;
        putenv("$nome=$valor");
    }
}