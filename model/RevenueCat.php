<?php

require_once __DIR__ . '/PushNotification.php';
require_once __DIR__ . '/Assinatura.php'; // Assinatura::avisarPagamentoFalhouParaUsuario()

// Assinatura Premium comprada de dentro do app Android (Google Play
// Billing via RevenueCat) - fonte independente da assinatura via Stripe
// (model/Assinatura.php, comprada pelo site). As duas atualizam o mesmo
// campo usuarios.plano, mas cada uma com sua própria trava de webhook fora
// de ordem (ver migration_revenuecat.sql) - nunca compartilham timestamp
// nem colunas de controle.
//
// app_user_id do RevenueCat É o id do usuário no nosso banco (ver
// Purchases.logIn(String(user.id)) no frontend, chamado logo após o login)
// - diferente do Stripe, não precisa de uma coluna "customer_id" própria só
// pra resolver qual usuário é: o id já vem pronto no evento.
class RevenueCat
{
    // Confirma que a requisição é do RevenueCat de verdade - eles mandam o
    // segredo configurado no dashboard deles como Bearer token no header
    // Authorization. Sem isso, qualquer um que descobrisse a URL poderia
    // forjar eventos de compra e liberar Premium de graça pra si mesmo.
    public static function autenticarRequisicao(): bool
    {
        $headers = getallheaders();
        $auth = $headers['Authorization'] ?? $headers['authorization'] ?? '';
        $esperado = 'Bearer ' . ($_ENV['REVENUECAT_WEBHOOK_SECRET'] ?? '');

        return !empty($_ENV['REVENUECAT_WEBHOOK_SECRET']) && hash_equals($esperado, $auth);
    }

    public static function processarWebhook(PDO $pdo, array $payload): array
    {
        $event = $payload['event'] ?? null;

        if (!$event || empty($event['app_user_id'])) {
            return ['success' => false, 'message' => 'Payload inválido.'];
        }

        $userId = (int) $event['app_user_id'];
        if ($userId <= 0) {
            // app_user_id anônimo do RevenueCat (antes de logIn, ou
            // RC_ANONYMOUS_ID) - não corresponde a nenhum usuário nosso,
            // nada a fazer.
            return ['success' => true];
        }

        // event_timestamp_ms é de quando o RevenueCat GEROU o evento, não
        // de quando chegou aqui - mesmo papel de event.created no Stripe,
        // protege contra entrega fora de ordem (RevenueCat reenvia eventos
        // que falharam, sem garantia de ordem de chegada).
        $eventoEm = (int) ($event['event_timestamp_ms'] ?? 0);
        $originalTransactionId = $event['original_transaction_id'] ?? null;
        $tipo = $event['type'] ?? '';

        switch ($tipo) {
            case 'INITIAL_PURCHASE':
            case 'RENEWAL':
            case 'UNCANCELLATION':
            case 'PRODUCT_CHANGE':
                self::ativarPremium($pdo, $userId, $originalTransactionId, $eventoEm);
                break;

            case 'CANCELLATION':
                // Usuário cancelou mas ainda tem acesso até expiration_at_ms
                // (mesmo comportamento do Stripe com cancel_at_period_end) -
                // só registra a data prevista, plano continua 1 até o
                // EXPIRATION de verdade chegar.
                $expiraEm = !empty($event['expiration_at_ms'])
                    ? date('Y-m-d H:i:s', (int) ($event['expiration_at_ms'] / 1000))
                    : null;
                self::registrarCancelamentoPrevisto($pdo, $userId, $originalTransactionId, $expiraEm, $eventoEm);
                break;

            case 'EXPIRATION':
                self::desativarPremium($pdo, $userId, $originalTransactionId, $eventoEm);
                break;

            case 'BILLING_ISSUE':
                self::notificarPagamentoFalhou($pdo, $userId);
                break;
        }

        return ['success' => true];
    }

    // IS NULL cobre a 1ª vez (usuário nunca teve evento do RevenueCat
    // processado ainda) - mesmo padrão de ativarAssinatura() em
    // model/Assinatura.php.
    private static function ativarPremium(PDO $pdo, int $userId, ?string $originalTransactionId, int $eventoEm): void
    {
        $stmt = $pdo->prepare(
            "UPDATE usuarios
             SET plano = 1, assinatura_status = 'active', assinatura_cancelamento_previsto = NULL,
                 revenuecat_original_transaction_id = :otid, revenuecat_webhook_processado_em = :evt
             WHERE id = :id AND (revenuecat_webhook_processado_em IS NULL OR revenuecat_webhook_processado_em < :evt2)"
        );
        $stmt->execute([
            ':otid' => $originalTransactionId,
            ':evt' => $eventoEm,
            ':evt2' => $eventoEm,
            ':id' => $userId,
        ]);
    }

    private static function registrarCancelamentoPrevisto(PDO $pdo, int $userId, ?string $originalTransactionId, ?string $expiraEm, int $eventoEm): void
    {
        // AND revenuecat_original_transaction_id = :otid - protege contra
        // evento de uma assinatura ANTIGA já substituída por uma nova
        // (mesmo raciocínio de atualizarStatusPorCustomer() no Stripe).
        $stmt = $pdo->prepare(
            "UPDATE usuarios
             SET assinatura_cancelamento_previsto = :previsto, revenuecat_webhook_processado_em = :evt
             WHERE id = :id AND revenuecat_original_transaction_id = :otid
             AND (revenuecat_webhook_processado_em IS NULL OR revenuecat_webhook_processado_em < :evt2)"
        );
        $stmt->execute([
            ':previsto' => $expiraEm,
            ':evt' => $eventoEm,
            ':evt2' => $eventoEm,
            ':id' => $userId,
            ':otid' => $originalTransactionId,
        ]);
    }

    // Cancelamento/expiração de premium cai pra limitado (3), não free (2) -
    // mesmo padrão de desativarAssinatura() no Stripe (ver comentário lá).
    private static function desativarPremium(PDO $pdo, int $userId, ?string $originalTransactionId, int $eventoEm): void
    {
        $stmt = $pdo->prepare(
            "UPDATE usuarios
             SET plano = 3, assinatura_status = 'canceled', assinatura_cancelamento_previsto = NULL, revenuecat_webhook_processado_em = :evt
             WHERE id = :id AND revenuecat_original_transaction_id = :otid
             AND (revenuecat_webhook_processado_em IS NULL OR revenuecat_webhook_processado_em < :evt2)"
        );
        $stmt->execute([
            ':evt' => $eventoEm,
            ':evt2' => $eventoEm,
            ':id' => $userId,
            ':otid' => $originalTransactionId,
        ]);
    }

    // Mesmo aviso (push + email, no máx 1x/dia) já usado pelo Stripe pra
    // cobrança falhada (ver notificarPagamentoFalhou em model/Assinatura.php)
    // - aqui é mais simples porque já temos o userId direto, sem precisar
    // resolver por customer_id.
    private static function notificarPagamentoFalhou(PDO $pdo, int $userId): void
    {
        $tipo = 'pagamento_falhou';

        if (PushNotification::jaFoiNotificadoHoje($pdo, $userId, $tipo)) {
            return;
        }

        $stmt = $pdo->prepare("
            SELECT u.id, u.nome, u.email, i.sigla AS idioma_nativo
            FROM usuarios u
            LEFT JOIN idioma_referencia ir ON ir.id_user = u.id
            LEFT JOIN idiomas i ON i.id = ir.idioma_nativo
            WHERE u.id = :id
            LIMIT 1
        ");
        $stmt->execute([':id' => $userId]);
        $usuario = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$usuario) {
            return;
        }

        Assinatura::avisarPagamentoFalhouParaUsuario($pdo, $usuario);
    }
}
