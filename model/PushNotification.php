<?php

use Minishlink\WebPush\WebPush;
use Minishlink\WebPush\Subscription;

// Notificações push via Web Push (PWA) - lembrete de treino disponível,
// streak em risco e reengajamento de quem sumiu. WebPush (minishlink/
// web-push) manda a mensagem de verdade; esse model cuida do CRUD de
// subscription, do envio propriamente dito e das consultas de quem é
// elegível pra cada tipo de notificação.
class PushNotification
{
    // Um usuário pode ter mais de uma subscription (vários dispositivos/
    // navegadores) - upsert pela chave (user_id, endpoint_hash), não
    // substitui as outras.
    public static function salvarSubscription(PDO $pdo, int $user_id, string $endpoint, string $p256dh, string $auth, ?string $userAgent): void
    {
        $endpointHash = hash('sha256', $endpoint);

        $sql = "INSERT INTO push_subscriptions (user_id, endpoint, endpoint_hash, p256dh, auth_key, user_agent)
                VALUES (:user_id, :endpoint, :endpoint_hash, :p256dh, :auth_key, :user_agent)
                ON DUPLICATE KEY UPDATE
                    endpoint = VALUES(endpoint),
                    p256dh = VALUES(p256dh),
                    auth_key = VALUES(auth_key),
                    user_agent = VALUES(user_agent)";

        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            ':user_id' => $user_id,
            ':endpoint' => $endpoint,
            ':endpoint_hash' => $endpointHash,
            ':p256dh' => $p256dh,
            ':auth_key' => $auth,
            ':user_agent' => $userAgent,
        ]);
    }

    public static function removerSubscription(PDO $pdo, int $user_id, string $endpoint): void
    {
        $endpointHash = hash('sha256', $endpoint);

        $stmt = $pdo->prepare("DELETE FROM push_subscriptions WHERE user_id = :user_id AND endpoint_hash = :endpoint_hash");
        $stmt->execute([':user_id' => $user_id, ':endpoint_hash' => $endpointHash]);
    }

    public static function listarSubscriptions(PDO $pdo, int $user_id): array
    {
        $stmt = $pdo->prepare("SELECT id, endpoint, p256dh, auth_key FROM push_subscriptions WHERE user_id = :user_id");
        $stmt->execute([':user_id' => $user_id]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private static function removerSubscriptionPorId(PDO $pdo, int $id): void
    {
        $stmt = $pdo->prepare("DELETE FROM push_subscriptions WHERE id = :id");
        $stmt->execute([':id' => $id]);
    }

    // Envia pra TODAS as subscriptions do usuário - remove do banco
    // qualquer subscription que a resposta indique expirada/inválida
    // (404/410), sem interromper o envio pros outros dispositivos do mesmo
    // usuário.
    public static function enviarParaUsuario(PDO $pdo, WebPush $webPush, int $user_id, string $titulo, string $corpo, string $url): void
    {
        $subscriptions = self::listarSubscriptions($pdo, $user_id);

        if (empty($subscriptions)) {
            return;
        }

        $payload = json_encode(['titulo' => $titulo, 'corpo' => $corpo, 'url' => $url]);

        foreach ($subscriptions as $sub) {
            $subscription = Subscription::create([
                'endpoint' => $sub['endpoint'],
                'keys' => ['p256dh' => $sub['p256dh'], 'auth' => $sub['auth_key']],
            ]);

            // Uma subscription corrompida/inválida (ex: chaves quebradas)
            // lança exceção na hora de criptografar o payload, em vez de só
            // devolver um relatório de falha como um 404/410 normal - sem
            // capturar aqui, um usuário com dado ruim derrubava o script
            // inteiro e ninguém mais recebia notificação nessa rodada do
            // cron. Trata como subscription inválida: remove e segue pros
            // próximos dispositivos/usuários.
            try {
                $report = $webPush->sendOneNotification($subscription, $payload);

                if ($report->isSubscriptionExpired()) {
                    self::removerSubscriptionPorId($pdo, (int) $sub['id']);
                }
            } catch (\Throwable $e) {
                self::removerSubscriptionPorId($pdo, (int) $sub['id']);
            }
        }
    }

    // Mesmo envio de enviarParaUsuario, mas devolve o resultado real de
    // cada subscription (sucesso/motivo da falha) em vez de engolir tudo
    // silenciosamente - usado só pela action de teste (enviar_teste), pra
    // dar um diagnóstico de verdade em vez de sempre dizer "enviado" mesmo
    // quando o envio falhou.
    public static function enviarParaUsuarioComDiagnostico(PDO $pdo, WebPush $webPush, int $user_id, string $titulo, string $corpo, string $url): array
    {
        $subscriptions = self::listarSubscriptions($pdo, $user_id);

        if (empty($subscriptions)) {
            return [];
        }

        $payload = json_encode(['titulo' => $titulo, 'corpo' => $corpo, 'url' => $url]);
        $resultados = [];

        foreach ($subscriptions as $sub) {
            $subscription = Subscription::create([
                'endpoint' => $sub['endpoint'],
                'keys' => ['p256dh' => $sub['p256dh'], 'auth' => $sub['auth_key']],
            ]);

            try {
                $report = $webPush->sendOneNotification($subscription, $payload);

                if ($report->isSubscriptionExpired()) {
                    self::removerSubscriptionPorId($pdo, (int) $sub['id']);
                }

                $resultados[] = [
                    'sucesso' => $report->isSuccess(),
                    'motivo' => $report->getReason(),
                    'expirada' => $report->isSubscriptionExpired(),
                ];
            } catch (\Throwable $e) {
                self::removerSubscriptionPorId($pdo, (int) $sub['id']);
                $resultados[] = [
                    'sucesso' => false,
                    'motivo' => $e->getMessage(),
                    'expirada' => false,
                ];
            }
        }

        return $resultados;
    }

    public static function jaFoiNotificadoHoje(PDO $pdo, int $user_id, string $tipo): bool
    {
        $stmt = $pdo->prepare(
            "SELECT id FROM notificacoes_enviadas
             WHERE user_id = :user_id AND tipo = :tipo AND data_envio = CURDATE()
             LIMIT 1"
        );
        $stmt->execute([':user_id' => $user_id, ':tipo' => $tipo]);

        return (bool) $stmt->fetch();
    }

    // Pro reengajamento a janela é maior que 1 dia - senão manda todo dia
    // enquanto o usuário continuar sumido, o que é justamente o oposto do
    // objetivo (evitar spammar quem já está sendo ignorado).
    public static function foiNotificadoRecentemente(PDO $pdo, int $user_id, string $tipo, int $dias): bool
    {
        $stmt = $pdo->prepare(
            "SELECT id FROM notificacoes_enviadas
             WHERE user_id = :user_id AND tipo = :tipo
               AND data_envio >= DATE_SUB(CURDATE(), INTERVAL :dias DAY)
             LIMIT 1"
        );
        $stmt->bindValue(':user_id', $user_id, PDO::PARAM_INT);
        $stmt->bindValue(':tipo', $tipo, PDO::PARAM_STR);
        $stmt->bindValue(':dias', $dias, PDO::PARAM_INT);
        $stmt->execute();

        return (bool) $stmt->fetch();
    }

    public static function registrarNotificacaoEnviada(PDO $pdo, int $user_id, string $tipo): void
    {
        $stmt = $pdo->prepare(
            "INSERT INTO notificacoes_enviadas (user_id, tipo, data_envio)
             VALUES (:user_id, :tipo, CURDATE())
             ON DUPLICATE KEY UPDATE data_envio = VALUES(data_envio)"
        );
        $stmt->execute([':user_id' => $user_id, ':tipo' => $tipo]);
    }

    // Usuários (premium ou limitado, com subscription ativa) que ainda não
    // usaram hoje pelo menos 1 dos 4 recursos diários - mesmas tabelas já
    // usadas pelos respectivos contarHoje() de cada model.
    public static function usuariosComTreinoDisponivel(PDO $pdo): array
    {
        $sql = "SELECT DISTINCT u.id
                FROM usuarios u
                INNER JOIN push_subscriptions ps ON ps.user_id = u.id
                WHERE u.plano IN (1, 3)
                  AND (u.status_id IS NULL OR u.status_id <> 0)
                  AND (
                    NOT EXISTS (SELECT 1 FROM frase_dia_ia f WHERE f.user_id = u.id AND f.status_id = 1 AND DATE(f.data_criacao) = CURDATE())
                    OR NOT EXISTS (SELECT 1 FROM perguntas_ia p WHERE p.user_id = u.id AND p.status_id = 1 AND DATE(p.data_criacao) = CURDATE())
                    OR NOT EXISTS (SELECT 1 FROM jogo_chuva_uso j WHERE j.user_id = u.id AND DATE(j.data_criacao) = CURDATE())
                    OR NOT EXISTS (SELECT 1 FROM tiro_certeiro_uso t WHERE t.user_id = u.id AND DATE(t.data_criacao) = CURDATE())
                  )";

        return array_map('intval', $pdo->query($sql)->fetchAll(PDO::FETCH_COLUMN));
    }

    // Candidatos a "streak em risco": subscription ativa e nenhuma
    // atividade (linha em metricas) hoje ainda. O streak em si (>0) é
    // conferido depois, um a um, reaproveitando Metricas::getStreak() -
    // mesmo critério exato que já define o streak em qualquer outra tela,
    // em vez de duplicar a lógica recursiva aqui.
    public static function candidatosStreakEmRisco(PDO $pdo): array
    {
        $sql = "SELECT DISTINCT u.id
                FROM usuarios u
                INNER JOIN push_subscriptions ps ON ps.user_id = u.id
                WHERE (u.status_id IS NULL OR u.status_id <> 0)
                  AND NOT EXISTS (
                    SELECT 1 FROM metricas m WHERE m.user_id = u.id AND DATE(m.created_at) = CURDATE()
                  )";

        return array_map('intval', $pdo->query($sql)->fetchAll(PDO::FETCH_COLUMN));
    }

    // Usuários (com subscription ativa) sem nenhuma atividade nos últimos
    // $dias dias - mesma fonte (metricas) usada no streak, é o sinal mais
    // confiável de "uso de verdade" disponível hoje (acessos_usuario só
    // registra login, não sessões de estudo).
    public static function usuariosInativos(PDO $pdo, int $dias): array
    {
        $stmt = $pdo->prepare(
            "SELECT DISTINCT u.id
             FROM usuarios u
             INNER JOIN push_subscriptions ps ON ps.user_id = u.id
             WHERE (u.status_id IS NULL OR u.status_id <> 0)
               AND NOT EXISTS (
                 SELECT 1 FROM metricas m WHERE m.user_id = u.id AND m.created_at >= DATE_SUB(CURDATE(), INTERVAL :dias DAY)
               )"
        );
        $stmt->bindValue(':dias', $dias, PDO::PARAM_INT);
        $stmt->execute();

        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }
}
