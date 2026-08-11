<?php

// Script de linha de comando (cron) - primeira automação agendada desse
// backend. Roda direto via CLI, não é acessado por HTTP. Uso:
//   php cron/enviar_notificacoes_push.php <treino_disponivel|streak_risco|reengajamento>
// Configurar 3 entradas de crontab, uma por tipo, nos horários certos (ver
// README/comentário no fim do arquivo).

// model/Metricas.php usa require_once relativo ('../server.php'), resolvido
// contra o diretório de trabalho do processo PHP - garante que funciona
// mesmo chamado direto pelo caminho completo no crontab (sem precisar de um
// "cd" antes).
chdir(__DIR__);

require_once __DIR__ . '/../server.php';
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../model/PushNotification.php';
require_once __DIR__ . '/../model/Metricas.php';

use Minishlink\WebPush\WebPush;

$tipo = $argv[1] ?? null;
$tiposValidos = ['treino_disponivel', 'streak_risco', 'reengajamento'];

if (!in_array($tipo, $tiposValidos, true)) {
    fwrite(STDERR, "Uso: php enviar_notificacoes_push.php <" . implode('|', $tiposValidos) . ">\n");
    exit(1);
}

$webPush = new WebPush([
    'VAPID' => [
        'subject' => $_ENV['VAPID_SUBJECT'],
        'publicKey' => $_ENV['VAPID_PUBLIC_KEY'],
        'privateKey' => $_ENV['VAPID_PRIVATE_KEY'],
    ],
]);

$enviados = 0;

// Ainda sobra pelo menos 1 dos 4 recursos diários (frase do dia, perguntas,
// chuva de frases, tiro certeiro) sem usar hoje.
if ($tipo === 'treino_disponivel') {
    foreach (PushNotification::usuariosComTreinoDisponivel($pdo) as $userId) {
        if (PushNotification::jaFoiNotificadoHoje($pdo, $userId, $tipo)) {
            continue;
        }

        PushNotification::enviarParaUsuario(
            $pdo,
            $webPush,
            $userId,
            'Seu treino de hoje está esperando!',
            'Você ainda tem conteúdo disponível hoje. Que tal praticar um pouco?',
            '/home'
        );
        PushNotification::registrarNotificacaoEnviada($pdo, $userId, $tipo);
        $enviados++;
    }
}

// Streak atual > 0 (mesmo critério de Metricas::getStreak) e nenhuma
// atividade hoje ainda - roda à noite, um "último aviso" antes do dia virar.
if ($tipo === 'streak_risco') {
    $metricas = new Metricas();

    foreach (PushNotification::candidatosStreakEmRisco($pdo) as $userId) {
        if (PushNotification::jaFoiNotificadoHoje($pdo, $userId, $tipo)) {
            continue;
        }

        $streak = $metricas->getStreak($userId);
        if ($streak <= 0) {
            continue;
        }

        PushNotification::enviarParaUsuario(
            $pdo,
            $webPush,
            $userId,
            'Sua sequência está em risco!',
            "Você tem uma sequência de {$streak} dia(s) - estude hoje pra não perder.",
            '/home'
        );
        PushNotification::registrarNotificacaoEnviada($pdo, $userId, $tipo);
        $enviados++;
    }
}

// Sem atividade há alguns dias - janela de "já notificado" bem maior que 1
// dia pra não repetir esse aviso todo dia enquanto o usuário some.
if ($tipo === 'reengajamento') {
    $diasInatividade = 3;
    $janelaSemNotificarDias = 7;

    foreach (PushNotification::usuariosInativos($pdo, $diasInatividade) as $userId) {
        if (PushNotification::foiNotificadoRecentemente($pdo, $userId, $tipo, $janelaSemNotificarDias)) {
            continue;
        }

        PushNotification::enviarParaUsuario(
            $pdo,
            $webPush,
            $userId,
            'Sentimos sua falta!',
            'Faz um tempo que você não estuda. Volte quando quiser continuar de onde parou.',
            '/home'
        );
        PushNotification::registrarNotificacaoEnviada($pdo, $userId, $tipo);
        $enviados++;
    }
}

echo "[{$tipo}] {$enviados} notificação(ões) enviada(s).\n";

// Crontab (ajustar horário se quiser, caminho já é o real de produção):
// 0 10 * * * php /home/u712858045/domains/zaldemy.com/public_html/api/cron/enviar_notificacoes_push.php treino_disponivel >> /home/u712858045/domains/zaldemy.com/public_html/api/cron/push.log 2>&1
// 0 20 * * * php /home/u712858045/domains/zaldemy.com/public_html/api/cron/enviar_notificacoes_push.php streak_risco     >> /home/u712858045/domains/zaldemy.com/public_html/api/cron/push.log 2>&1
// 0 9  * * * php /home/u712858045/domains/zaldemy.com/public_html/api/cron/enviar_notificacoes_push.php reengajamento    >> /home/u712858045/domains/zaldemy.com/public_html/api/cron/push.log 2>&1
