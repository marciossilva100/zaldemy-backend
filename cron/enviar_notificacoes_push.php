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
require_once __DIR__ . '/../model/FcmSender.php';
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

// Conta de serviço do Firebase é opcional - enquanto o arquivo não existir
// nesse servidor, $fcmSender fica null e só o Web Push é enviado (ver
// PushNotification::enviarFcmParaUsuario).
$caminhoFcm = $_ENV['FIREBASE_SERVICE_ACCOUNT_PATH'] ?? (__DIR__ . '/../firebase-service-account.json');
$fcmSender = null;
if (file_exists($caminhoFcm)) {
    try {
        $fcmSender = new FcmSender($caminhoFcm);
    } catch (\Throwable $e) {
        fwrite(STDERR, "[push-fcm] falha ao inicializar FcmSender: {$e->getMessage()}\n");
    }
}

// Pools de variações por tipo - sem isso, os 3 tipos mandavam sempre o
// MESMO título/corpo (só o número de dias de streak ou a frase em treino
// mudavam, quando existiam) - notificação idêntica toda semana treina o
// usuário a ignorar/silenciar (reportado pelo usuário). array_rand() sorteia
// 1 de cada vez; não precisa de controle de "não repetir a última" porque
// esses envios já são espaçados (1x/dia no máximo, 1x/semana no
// reengajamento) - a chance de pegar a mesma 2x seguidas é baixa e inofensiva.
function escolher(array $pool): string
{
    return $pool[array_rand($pool)];
}

$TITULOS_TREINO_DISPONIVEL = [
    'Seu treino de hoje está esperando!',
    'Ainda dá tempo de praticar hoje!',
    'Um treino rápido te espera',
    'Hoje tem treino disponível pra você',
    'Bora praticar um pouco?',
];

$CORPOS_TREINO_DISPONIVEL_GENERICO = [
    'Você ainda tem conteúdo disponível hoje. Que tal praticar um pouco?',
    'Separamos um treino rapidinho pra você continuar evoluindo.',
    'Poucos minutos hoje já fazem diferença no seu aprendizado.',
    'Ainda dá tempo de manter o ritmo hoje.',
    'Seu próximo treino está pronto quando você quiser.',
];

// {texto} = frase em treino mais atrasada, com aspas já aplicadas no lugar
// certo pela interpolação abaixo.
$CORPOS_TREINO_DISPONIVEL_COM_FRASE = [
    'Lembra como se diz "%s"? Pratique hoje!',
    'Ainda lembra "%s"? Vamos revisar?',
    '"%s" ainda está esperando por você no treino de hoje.',
    'Que tal revisar "%s" agora?',
    'Não esqueça de treinar "%s" hoje!',
];

$TITULOS_STREAK_RISCO = [
    'Sua sequência está em risco!',
    'Não deixe sua sequência quebrar!',
    'Falta pouco pra perder sua sequência',
    'Sua ofensiva está por um fio!',
    'Hoje é o dia de manter sua sequência',
];

// %d = dias de sequência atual.
$CORPOS_STREAK_RISCO = [
    'Você tem uma sequência de %d dia(s) - estude hoje pra não perder.',
    '%d dia(s) de sequência até agora. Não deixe acabar hoje!',
    'Faltam só alguns minutos pra manter seus %d dia(s) de sequência.',
    'Sua sequência de %d dia(s) está esperando só mais um treino hoje.',
    'Você construiu %d dia(s) de sequência - vale a pena continuar hoje.',
];

$TITULOS_REENGAJAMENTO = [
    'Sentimos sua falta!',
    'Faz tempo que a gente não se vê por aqui',
    'Que tal voltar a estudar?',
    'Seu progresso está te esperando',
    'Bora continuar de onde parou?',
];

$CORPOS_REENGAJAMENTO = [
    'Faz um tempo que você não estuda. Volte quando quiser continuar de onde parou.',
    'Seu conteúdo continua te esperando, sem pressa nenhuma.',
    'Alguns minutos hoje já ajudam a retomar o ritmo.',
    'Você já tinha avançado bastante - que tal continuar?',
    'Nada foi perdido - seu progresso está salvo e te esperando.',
];

$enviados = 0;

// Ainda sobra pelo menos 1 dos 4 recursos diários (frase do dia, perguntas,
// chuva de frases, tiro certeiro) sem usar hoje.
if ($tipo === 'treino_disponivel') {
    foreach (PushNotification::usuariosComTreinoDisponivel($pdo) as $userId) {
        if (PushNotification::jaFoiNotificadoHoje($pdo, $userId, $tipo)) {
            continue;
        }

        // Com uma frase real "em treino" (id_treino=3) disponível, a
        // notificação mostra ela em vez do texto genérico - desperta mais
        // curiosidade que só "você tem conteúdo disponível" (decisão de
        // produto, ver conversa com o usuário). Sem frase nesse estágio
        // ainda, cai pro texto genérico de sempre. Sorteia entre as até 5
        // mais atrasadas (não sempre a mesma única mais atrasada) - sem
        // isso, quem nunca treina uma frase específica via sempre ELA,
        // todo dia, até treinar (reportado pelo usuário: mesma frase há
        // dias, mesmo já variando o título/corpo da mensagem).
        $frases = PushNotification::frasesEmTreinoMaisAtrasadas($pdo, $userId);
        $frase = $frases ? $frases[array_rand($frases)] : null;

        if ($frase) {
            $texto = mb_strlen($frase['texto_nativo']) > 60
                ? mb_substr($frase['texto_nativo'], 0, 60) . '...'
                : $frase['texto_nativo'];
            $corpo = sprintf(escolher($CORPOS_TREINO_DISPONIVEL_COM_FRASE), $texto);
        } else {
            $corpo = escolher($CORPOS_TREINO_DISPONIVEL_GENERICO);
        }

        PushNotification::enviarParaUsuario(
            $pdo,
            $webPush,
            $userId,
            escolher($TITULOS_TREINO_DISPONIVEL),
            $corpo,
            '/home',
            $fcmSender
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
            escolher($TITULOS_STREAK_RISCO),
            sprintf(escolher($CORPOS_STREAK_RISCO), $streak),
            '/home',
            $fcmSender
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
            escolher($TITULOS_REENGAJAMENTO),
            escolher($CORPOS_REENGAJAMENTO),
            '/home',
            $fcmSender
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
