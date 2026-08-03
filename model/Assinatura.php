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
                self::atualizarStatusPorCustomer($pdo, $subscription->customer, $subscription->id, $subscription->status);
                break;

            case 'customer.subscription.deleted':
                $subscription = $event->data->object;
                self::desativarAssinatura($pdo, $subscription->customer);
                break;
        }

        return ['success' => true];
    }

    private static function ativarAssinatura(PDO $pdo, ?string $userId, string $customerId, string $subscriptionId): void
    {
        $sql = $userId
            ? "UPDATE usuarios SET plano = 1, assinatura_status = 'active', stripe_customer_id = :cid, stripe_subscription_id = :sid WHERE id = :id"
            : "UPDATE usuarios SET plano = 1, assinatura_status = 'active', stripe_subscription_id = :sid WHERE stripe_customer_id = :cid";

        $stmt = $pdo->prepare($sql);
        $params = [':cid' => $customerId, ':sid' => $subscriptionId];
        if ($userId) {
            $params[':id'] = (int) $userId;
        }
        $stmt->execute($params);
    }

    private static function atualizarStatusPorCustomer(PDO $pdo, string $customerId, string $subscriptionId, string $status): void
    {
        // Enquanto o Stripe ainda está tentando cobrar (past_due) ou a
        // assinatura está ativa/em trial, mantém o plano premium - só rebaixa
        // quando o evento subscription.deleted chegar de fato (assinatura
        // encerrada, seja por cancelamento ou esgotamento das tentativas).
        $stmt = $pdo->prepare(
            "UPDATE usuarios SET assinatura_status = :status, stripe_subscription_id = :sid WHERE stripe_customer_id = :cid"
        );
        $stmt->execute([':status' => $status, ':sid' => $subscriptionId, ':cid' => $customerId]);
    }

    private static function desativarAssinatura(PDO $pdo, string $customerId): void
    {
        $stmt = $pdo->prepare(
            "UPDATE usuarios SET plano = 2, assinatura_status = 'canceled' WHERE stripe_customer_id = :cid"
        );
        $stmt->execute([':cid' => $customerId]);
    }
}
