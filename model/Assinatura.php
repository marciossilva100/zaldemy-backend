<?php

require_once __DIR__ . '/../vendor/autoload.php';

use Stripe\StripeClient;
use Stripe\Webhook;
use Stripe\Exception\SignatureVerificationException;

// Assinatura do Zaldemy+ via Stripe (versão web - o app Android usa Google
// Play Billing separadamente, por exigência da política da Play Store).
class Assinatura
{
    private static function client(): StripeClient
    {
        return new StripeClient($_ENV['STRIPE_SECRET_KEY']);
    }

    // Cria (ou reaproveita) o Customer no Stripe e devolve a URL de checkout
    // pra assinatura recorrente do Zaldemy+.
    public static function criarCheckoutSession(PDO $pdo, int $user_id, string $email): array
    {
        $stripe = self::client();

        $stmt = $pdo->prepare("SELECT stripe_customer_id FROM usuarios WHERE id = :id");
        $stmt->execute([':id' => $user_id]);
        $customerId = $stmt->fetch(PDO::FETCH_ASSOC)['stripe_customer_id'] ?? null;

        if (!$customerId) {
            $customer = $stripe->customers->create([
                'email' => $email,
                'metadata' => ['user_id' => $user_id],
            ]);
            $customerId = $customer->id;

            $stmt = $pdo->prepare("UPDATE usuarios SET stripe_customer_id = :cid WHERE id = :id");
            $stmt->execute([':cid' => $customerId, ':id' => $user_id]);
        }

        $appUrl = $_ENV['APP_URL'] ?? 'https://zaldemy.com';

        $session = $stripe->checkout->sessions->create([
            'customer' => $customerId,
            'mode' => 'subscription',
            'line_items' => [[
                'price' => $_ENV['STRIPE_PRICE_ID'],
                'quantity' => 1,
            ]],
            'success_url' => $appUrl . '/assinatura/sucesso?session_id={CHECKOUT_SESSION_ID}',
            'cancel_url' => $appUrl . '/assinatura/cancelado',
            'client_reference_id' => (string) $user_id,
        ]);

        return ['success' => true, 'url' => $session->url];
    }

    // Agenda o cancelamento pro fim do período já pago (cancel_at_period_end) -
    // não corta o acesso na hora, já que o usuário pagou por esse período.
    // O rebaixamento de plano de verdade só acontece quando o Stripe manda o
    // evento customer.subscription.deleted (processarWebhook), no fim do ciclo.
    public static function cancelarAssinatura(PDO $pdo, int $user_id): array
    {
        $stmt = $pdo->prepare("SELECT stripe_subscription_id FROM usuarios WHERE id = :id");
        $stmt->execute([':id' => $user_id]);
        $subscriptionId = $stmt->fetch(PDO::FETCH_ASSOC)['stripe_subscription_id'] ?? null;

        if (!$subscriptionId) {
            return ['success' => false, 'message' => 'Nenhuma assinatura ativa encontrada.'];
        }

        $stripe = self::client();
        $subscription = $stripe->subscriptions->update($subscriptionId, ['cancel_at_period_end' => true]);

        $previsto = date('Y-m-d H:i:s', $subscription->current_period_end);

        $stmt = $pdo->prepare("UPDATE usuarios SET assinatura_cancelamento_previsto = :previsto WHERE id = :id");
        $stmt->execute([':previsto' => $previsto, ':id' => $user_id]);

        return ['success' => true, 'cancelamento_previsto' => $previsto];
    }

    // Desfaz um cancelamento agendado (ainda dentro do período pago) -
    // simplesmente reverte cancel_at_period_end antes do fim do ciclo.
    public static function reativarAssinatura(PDO $pdo, int $user_id): array
    {
        $stmt = $pdo->prepare("SELECT stripe_subscription_id FROM usuarios WHERE id = :id");
        $stmt->execute([':id' => $user_id]);
        $subscriptionId = $stmt->fetch(PDO::FETCH_ASSOC)['stripe_subscription_id'] ?? null;

        if (!$subscriptionId) {
            return ['success' => false, 'message' => 'Nenhuma assinatura encontrada.'];
        }

        $stripe = self::client();
        $stripe->subscriptions->update($subscriptionId, ['cancel_at_period_end' => false]);

        $stmt = $pdo->prepare("UPDATE usuarios SET assinatura_cancelamento_previsto = NULL WHERE id = :id");
        $stmt->execute([':id' => $user_id]);

        return ['success' => true];
    }

    // Verifica a assinatura do webhook (garante que a chamada veio mesmo do
    // Stripe) e atualiza o plano do usuário conforme o evento recebido.
    public static function processarWebhook(PDO $pdo, string $payload, string $sigHeader): array
    {
        try {
            $event = Webhook::constructEvent($payload, $sigHeader, $_ENV['STRIPE_WEBHOOK_SECRET']);
        } catch (SignatureVerificationException $e) {
            return ['success' => false, 'message' => 'Assinatura inválida.'];
        } catch (\UnexpectedValueException $e) {
            return ['success' => false, 'message' => 'Payload inválido.'];
        }

        switch ($event->type) {
            case 'checkout.session.completed':
                $session = $event->data->object;
                self::ativarAssinatura($pdo, $session->client_reference_id, $session->customer, $session->subscription);
                break;

            case 'customer.subscription.updated':
                $subscription = $event->data->object;
                $previsto = $subscription->cancel_at_period_end
                    ? date('Y-m-d H:i:s', $subscription->current_period_end)
                    : null;
                self::atualizarStatusPorCustomer($pdo, $subscription->customer, $subscription->id, $subscription->status, $previsto);
                break;

            case 'customer.subscription.deleted':
                $subscription = $event->data->object;
                self::desativarAssinatura($pdo, $subscription->customer, $subscription->id);
                break;
        }

        return ['success' => true];
    }

    private static function ativarAssinatura(PDO $pdo, ?string $userId, string $customerId, string $subscriptionId): void
    {
        // Limpa qualquer cancelamento agendado de uma assinatura anterior -
        // esse é um checkout novo.
        $sql = $userId
            ? "UPDATE usuarios SET plano = 1, assinatura_status = 'active', assinatura_cancelamento_previsto = NULL, stripe_customer_id = :cid, stripe_subscription_id = :sid WHERE id = :id"
            : "UPDATE usuarios SET plano = 1, assinatura_status = 'active', assinatura_cancelamento_previsto = NULL, stripe_subscription_id = :sid WHERE stripe_customer_id = :cid";

        $stmt = $pdo->prepare($sql);
        $params = [':cid' => $customerId, ':sid' => $subscriptionId];
        if ($userId) {
            $params[':id'] = (int) $userId;
        }
        $stmt->execute($params);
    }

    private static function atualizarStatusPorCustomer(PDO $pdo, string $customerId, string $subscriptionId, string $status, ?string $cancelamentoPrevisto): void
    {
        // Enquanto o Stripe ainda está tentando cobrar (past_due) ou a
        // assinatura está ativa/em trial, mantém o plano premium - só rebaixa
        // quando o evento subscription.deleted chegar de fato (assinatura
        // encerrada, seja por cancelamento ou esgotamento das tentativas).
        // Sincroniza o cancelamento agendado mesmo se ele tiver sido feito
        // direto pelo painel do Stripe (não só pelo app).
        //
        // AND stripe_subscription_id = :sid - o Stripe não garante ordem de
        // entrega dos webhooks. Um cliente que cancelou uma assinatura e
        // assinou de novo (ex: durante testes) tem duas subscriptions
        // diferentes ao longo do tempo; sem essa checagem, um evento
        // atrasado/reentregue da assinatura ANTIGA (já substituída por
        // ativarAssinatura() numa assinatura nova) sobrescrevia o estado
        // correto da assinatura atual com dados desatualizados da antiga -
        // reproduzido de verdade: campo de data de cancelamento voltou pro
        // valor de uma assinatura de teste já cancelada, escondendo que a
        // assinatura atual (nova) tinha uma data de cancelamento diferente.
        $stmt = $pdo->prepare(
            "UPDATE usuarios SET assinatura_status = :status, assinatura_cancelamento_previsto = :previsto
             WHERE stripe_customer_id = :cid AND stripe_subscription_id = :sid"
        );
        $stmt->execute([
            ':status' => $status,
            ':previsto' => $cancelamentoPrevisto,
            ':cid' => $customerId,
            ':sid' => $subscriptionId,
        ]);
    }

    // Cancelamento de premium cai pra limitado (3), não free (2) - free é
    // reservado pra outros casos, não é o destino padrão de quem já foi
    // assinante. Limitado ainda dá acesso (com cota) aos recursos de IA,
    // jogos etc., em vez de cortar tudo de uma vez.
    //
    // AND stripe_subscription_id = :sid - mesmo motivo de
    // atualizarStatusPorCustomer: sem essa checagem, um subscription.deleted
    // atrasado de uma assinatura ANTIGA (já substituída por uma nova, ex:
    // cliente cancelou e assinou de novo) rebaixava um cliente que já tinha
    // uma assinatura nova e válida ativa - o pior caso dos dois, porque
    // cortaria acesso premium pago de verdade por causa de um evento de uma
    // assinatura que não existe mais.
    private static function desativarAssinatura(PDO $pdo, string $customerId, string $subscriptionId): void
    {
        $stmt = $pdo->prepare(
            "UPDATE usuarios SET plano = 3, assinatura_status = 'canceled', assinatura_cancelamento_previsto = NULL
             WHERE stripe_customer_id = :cid AND stripe_subscription_id = :sid"
        );
        $stmt->execute([':cid' => $customerId, ':sid' => $subscriptionId]);
    }
}
