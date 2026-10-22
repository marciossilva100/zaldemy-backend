<?php

// Endpoint público (sem autenticação) que informa o versionCode mínimo
// recomendado do app Android - o app nativo compara com o próprio build
// (App.getInfo().build, via Capacitor) e mostra um aviso pra atualizar via
// Play Store quando estiver desatualizado. Não precisa bater com o
// versionCode mais recente publicado, só com o mínimo que ainda é
// considerado aceitável - não necessariamente sobe a cada build (só quando
// uma versão tem um bug relevante o suficiente pra justificar o aviso).
//
// Atualizar esse valor é manual (editar esse arquivo e fazer deploy do
// backend, igual qualquer outra mudança de backend) - de propósito, não
// outro novo valor de .env/banco só pra isso, já que troca raramente.

$allowedOrigins = [
    "http://localhost:5173",
    "https://zaldemy.com",
    "https://www.zaldemy.com",
    "https://www.hml.zaldemy.com",
    "https://hml.zaldemy.com",
    "https://memly-jijk.vercel.app",
    "https://localhost", // app nativo Android/iOS via Capacitor
    "capacitor://localhost" // WKWebView do Capacitor no iOS
];

if (isset($_SERVER['HTTP_ORIGIN']) && in_array($_SERVER['HTTP_ORIGIN'], $allowedOrigins)) {
    header("Access-Control-Allow-Origin: " . $_SERVER['HTTP_ORIGIN']);
}

header('Content-Type: application/json');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Dois níveis, de propósito - a maioria das atualizações é só "recomendada"
// (banner dispensável, não atrapalha quem tá usando); "obrigatória" é pra
// quando um bug é grave o suficiente pra valer travar o app até atualizar
// (ex: o bug de regressão de onboarding corrigido em 22/10/2026 - se essa
// ferramenta já existisse, teria sido um bom caso de uso). 0 = nenhuma
// versão é bloqueada ainda.
$ANDROID_MIN_VERSION_RECOMENDADO = 29;
$ANDROID_MIN_VERSION_OBRIGATORIO = 0;

echo json_encode([
    "android_min_version_code" => $ANDROID_MIN_VERSION_RECOMENDADO,
    "android_min_version_code_obrigatorio" => $ANDROID_MIN_VERSION_OBRIGATORIO,
    "android_play_store_url" => "https://play.google.com/store/apps/details?id=com.zaldemy.app",
]);
