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

        // Marca assinatura_webhook_processado_em com o agora (não um
        // event->created, essa ação é direta via API, não um webhook) -
        // sem isso, um webhook atrasado de ANTES dessa ação (campo ainda
        // NULL ou com timestamp velho) passava pela trava de ordenação em
        // atualizarStatusPorCustomer/desativarAssinatura e sobrescrevia
        // esse valor que acabamos de confirmar direto com o Stripe.
        $stmt = $pdo->prepare("UPDATE usuarios SET assinatura_cancelamento_previsto = :previsto, assinatura_webhook_processado_em = :agora WHERE id = :id");
        $stmt->execute([':previsto' => $previsto, ':agora' => time(), ':id' => $user_id]);

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

        // Mesmo motivo do timestamp em cancelarAssinatura() - marca o agora
        // pra travar contra webhook atrasado de antes dessa ação.
        $stmt = $pdo->prepare("UPDATE usuarios SET assinatura_cancelamento_previsto = NULL, assinatura_webhook_processado_em = :agora WHERE id = :id");
        $stmt->execute([':agora' => time(), ':id' => $user_id]);

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

        // event->created (Unix, quando o Stripe gerou o evento - não quando
        // chegou aqui) grava em assinatura_webhook_processado_em e serve de
        // trava contra entrega fora de ordem em TODOS os handlers abaixo -
        // ver comentário detalhado em atualizarStatusPorCustomer().
        $eventoEm = (int) $event->created;

        switch ($event->type) {
            case 'checkout.session.completed':
                $session = $event->data->object;
                self::ativarAssinatura($pdo, $session->client_reference_id, $session->customer, $session->subscription, $eventoEm);
                break;

            case 'customer.subscription.updated':
                $subscription = $event->data->object;
                $previsto = $subscription->cancel_at_period_end
                    ? date('Y-m-d H:i:s', $subscription->current_period_end)
                    : null;
                self::atualizarStatusPorCustomer($pdo, $subscription->customer, $subscription->id, $subscription->status, $previsto, $eventoEm);
                break;

            case 'customer.subscription.deleted':
                $subscription = $event->data->object;
                self::desativarAssinatura($pdo, $subscription->customer, $subscription->id, $eventoEm);
                break;
        }

        return ['success' => true];
    }

    // $eventoEm (Unix, event.created do Stripe) grava em
    // assinatura_webhook_processado_em e é checado nos 3 handlers - o
    // Stripe não garante ordem de entrega dos webhooks e reenvia eventos
    // que falharam por até alguns dias, então um evento atrasado sempre
    // pode chegar DEPOIS de um mais novo já ter sido aplicado. Sem essa
    // trava por timestamp, o estado da assinatura podia "voltar no tempo" -
    // reproduzido de verdade: reativar e cancelar de novo trouxe de volta
    // a data de cancelamento de um evento antigo, atrasado, da mesma
    // assinatura. IS NULL cobre a 1ª vez (usuário nunca teve um evento
    // processado ainda).
    private static function ativarAssinatura(PDO $pdo, ?string $userId, string $customerId, string $subscriptionId, int $eventoEm): void
    {
        // Limpa qualquer cancelamento agendado de uma assinatura anterior -
        // esse é um checkout novo.
        $sql = $userId
            ? "UPDATE usuarios SET plano = 1, assinatura_status = 'active', assinatura_cancelamento_previsto = NULL, stripe_customer_id = :cid, stripe_subscription_id = :sid, assinatura_webhook_processado_em = :evt WHERE id = :id AND (assinatura_webhook_processado_em IS NULL OR assinatura_webhook_processado_em < :evt2)"
            : "UPDATE usuarios SET plano = 1, assinatura_status = 'active', assinatura_cancelamento_previsto = NULL, stripe_subscription_id = :sid, assinatura_webhook_processado_em = :evt WHERE stripe_customer_id = :cid AND (assinatura_webhook_processado_em IS NULL OR assinatura_webhook_processado_em < :evt2)";

        $stmt = $pdo->prepare($sql);
        $params = [':cid' => $customerId, ':sid' => $subscriptionId, ':evt' => $eventoEm, ':evt2' => $eventoEm];
        if ($userId) {
            $params[':id'] = (int) $userId;
        }
        $stmt->execute($params);
    }

    private static function atualizarStatusPorCustomer(PDO $pdo, string $customerId, string $subscriptionId, string $status, ?string $cancelamentoPrevisto, int $eventoEm): void
    {
        // Enquanto o Stripe ainda está tentando cobrar (past_due) ou a
        // assinatura está ativa/em trial, mantém o plano premium - só rebaixa
        // quando o evento subscription.deleted chegar de fato (assinatura
        // encerrada, seja por cancelamento ou esgotamento das tentativas).
        // Sincroniza o cancelamento agendado mesmo se ele tiver sido feito
        // direto pelo painel do Stripe (não só pelo app).
        //
        // AND stripe_subscription_id = :sid - protege contra evento de uma
        // assinatura ANTIGA já substituída por uma nova (cliente cancelou e
        // assinou de novo). AND assinatura_webhook_processado_em < :evt
        // protege contra entrega fora de ordem da MESMA assinatura (ver
        // comentário em ativarAssinatura) - as duas checagens são
        // necessárias, cobrem casos diferentes.
        $stmt = $pdo->prepare(
            "UPDATE usuarios SET assinatura_status = :status, assinatura_cancelamento_previsto = :previsto, assinatura_webhook_processado_em = :evt
             WHERE stripe_customer_id = :cid AND stripe_subscription_id = :sid
             AND (assinatura_webhook_processado_em IS NULL OR assinatura_webhook_processado_em < :evt2)"
        );
        $stmt->execute([
            ':status' => $status,
            ':previsto' => $cancelamentoPrevisto,
            ':evt' => $eventoEm,
            ':evt2' => $eventoEm,
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
    // assinatura que não existe mais. AND assinatura_webhook_processado_em
    // < :evt protege contra entrega fora de ordem da MESMA assinatura.
    private static function desativarAssinatura(PDO $pdo, string $customerId, string $subscriptionId, int $eventoEm): void
    {
        $stmt = $pdo->prepare(
            "UPDATE usuarios SET plano = 3, assinatura_status = 'canceled', assinatura_cancelamento_previsto = NULL, assinatura_webhook_processado_em = :evt
             WHERE stripe_customer_id = :cid AND stripe_subscription_id = :sid
             AND (assinatura_webhook_processado_em IS NULL OR assinatura_webhook_processado_em < :evt2)"
        );
        $stmt->execute([':cid' => $customerId, ':sid' => $subscriptionId, ':evt' => $eventoEm, ':evt2' => $eventoEm]);
    }
}
