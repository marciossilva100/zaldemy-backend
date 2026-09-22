<?php

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../dotenv.php';
carregarEnv(__DIR__ . '/../.env');

function enviarEmailVerificacao($email, $token) {

    $mail = new PHPMailer(true);

    try {

        $mail->isSMTP();
        $mail->CharSet    = 'UTF-8';
        $mail->Host       = $_ENV['MAIL_HOST'] ?? 'smtp.hostinger.com';
        $mail->SMTPAuth   = true;
        $mail->Username   = $_ENV['MAIL_USERNAME'] ?? 'adm@zaldemy.com';
        $mail->Password   = $_ENV['MAIL_PASSWORD'] ?? '';
        $mail->SMTPSecure = $_ENV['MAIL_ENCRYPTION'] ?? 'ssl';
        $mail->Port       = $_ENV['MAIL_PORT'] ?? 465;

        $fromEmail = $_ENV['MAIL_FROM_ADDRESS'] ?? 'adm@zaldemy.com';
        $fromName  = $_ENV['MAIL_FROM_NAME'] ?? 'Zaldemy';
        $appUrl    = rtrim($_ENV['APP_URL'] ?? 'https://zaldemy.com', '/');

        $mail->setFrom($fromEmail, $fromName);
        $mail->addAddress($email);

        $link = "$appUrl/emailverificado?token=$token";

        $mail->isHTML(true);
        $mail->Subject = 'Confirme seu email - Zaldemy';
        $mail->Body    = "
            <h2>Confirme sua conta Zaldemy</h2>
            <p>Clique no link abaixo para verificar seu email:</p>
            <a href='$link'>$link</a>
        ";

        $mail->send();

        return true;

    } catch (Exception $e) {
        return false;
    }
}

function enviarEmailRedefinicaoSenha($email, $token) {

    $mail = new PHPMailer(true);

    try {

        $mail->isSMTP();
        $mail->CharSet    = 'UTF-8';
        $mail->Host       = $_ENV['MAIL_HOST'] ?? 'smtp.hostinger.com';
        $mail->SMTPAuth   = true;
        $mail->Username   = $_ENV['MAIL_USERNAME'] ?? 'adm@zaldemy.com';
        $mail->Password   = $_ENV['MAIL_PASSWORD'] ?? '';
        $mail->SMTPSecure = $_ENV['MAIL_ENCRYPTION'] ?? 'ssl';
        $mail->Port       = $_ENV['MAIL_PORT'] ?? 465;

        $fromEmail = $_ENV['MAIL_FROM_ADDRESS'] ?? 'adm@zaldemy.com';
        $fromName  = $_ENV['MAIL_FROM_NAME'] ?? 'Zaldemy';
        $appUrl    = rtrim($_ENV['APP_URL'] ?? 'https://zaldemy.com', '/');

        $mail->setFrom($fromEmail, $fromName);
        $mail->addAddress($email);

        $link = "$appUrl/redefinirsenha?token=$token";

        $mail->isHTML(true);
        $mail->Subject = 'Redefinição de senha - Zaldemy';
        $mail->Body    = "
            <h2>Redefinir sua senha do Zaldemy</h2>
            <p>Clique no link abaixo para escolher uma nova senha. Esse link expira em 1 hora:</p>
            <a href='$link'>$link</a>
            <p>Se você não pediu essa redefinição, ignore este email.</p>
        ";

        $mail->send();

        return true;

    } catch (Exception $e) {
        return false;
    }
}

// Textos do email de pagamento falhado nos 16 idiomas que o app suporta
// (mesmo conjunto de src/locales/ no front) - pedido explícito do usuário
// ("o email deve estar no idioma nativo do usuário"). %s vira o primeiro
// nome quando disponível (ver montagem da saudação mais abaixo). Sigla
// desconhecida ou nula cai em português (mesmo padrão de fallback já usado
// em FraseDoDia::getIdiomaNativo).
function textosEmailPagamentoFalhou(?string $sigla): array {
    $textos = [
        'pt' => [
            'lang' => 'pt-BR', 'dir' => 'ltr',
            'subject' => 'Não conseguimos cobrar sua assinatura - Zaldemy',
            'saudacao_nome' => 'Olá, %s!', 'saudacao_sem_nome' => 'Olá!',
            'p1' => 'A última tentativa de cobrança da sua assinatura Zaldemy+ falhou. Isso pode acontecer por saldo insuficiente, cartão vencido ou bloqueio do banco.',
            'p2' => 'Seu acesso Premium continua ativo por enquanto, mas atualize sua forma de pagamento pra não perder o acesso quando as tentativas do Stripe acabarem.',
            'botao' => 'Atualizar forma de pagamento',
            'rodape' => 'Este é um email automático - não é possível responder a ele.',
        ],
        'en' => [
            'lang' => 'en', 'dir' => 'ltr',
            'subject' => "We couldn't charge your subscription - Zaldemy",
            'saudacao_nome' => 'Hi, %s!', 'saudacao_sem_nome' => 'Hi!',
            'p1' => "The latest charge attempt for your Zaldemy+ subscription failed. This can happen due to insufficient funds, an expired card, or a bank block.",
            'p2' => "Your Premium access is still active for now, but please update your payment method so you don't lose access once Stripe's retry attempts run out.",
            'botao' => 'Update payment method',
            'rodape' => "This is an automated email - please don't reply to it.",
        ],
        'es' => [
            'lang' => 'es', 'dir' => 'ltr',
            'subject' => 'No pudimos cobrar tu suscripción - Zaldemy',
            'saudacao_nome' => '¡Hola, %s!', 'saudacao_sem_nome' => '¡Hola!',
            'p1' => 'El último intento de cobro de tu suscripción Zaldemy+ falló. Esto puede pasar por fondos insuficientes, tarjeta vencida o bloqueo del banco.',
            'p2' => 'Tu acceso Premium sigue activo por ahora, pero actualiza tu forma de pago para no perderlo cuando se agoten los intentos de Stripe.',
            'botao' => 'Actualizar forma de pago',
            'rodape' => 'Este es un correo automático - no es posible responder a él.',
        ],
        'fr' => [
            'lang' => 'fr', 'dir' => 'ltr',
            'subject' => "Nous n'avons pas pu débiter votre abonnement - Zaldemy",
            'saudacao_nome' => 'Bonjour, %s !', 'saudacao_sem_nome' => 'Bonjour !',
            'p1' => "La dernière tentative de prélèvement de votre abonnement Zaldemy+ a échoué. Cela peut être dû à un solde insuffisant, une carte expirée ou un blocage bancaire.",
            'p2' => "Votre accès Premium reste actif pour le moment, mais mettez à jour votre moyen de paiement pour ne pas le perdre une fois les tentatives de Stripe épuisées.",
            'botao' => 'Mettre à jour le moyen de paiement',
            'rodape' => 'Ceci est un email automatique - merci de ne pas y répondre.',
        ],
        'de' => [
            'lang' => 'de', 'dir' => 'ltr',
            'subject' => 'Wir konnten dein Abo nicht abbuchen - Zaldemy',
            'saudacao_nome' => 'Hallo, %s!', 'saudacao_sem_nome' => 'Hallo!',
            'p1' => 'Der letzte Abbuchungsversuch für dein Zaldemy+-Abo ist fehlgeschlagen. Das kann an unzureichendem Guthaben, einer abgelaufenen Karte oder einer Bank-Sperre liegen.',
            'p2' => 'Dein Premium-Zugang ist vorerst weiterhin aktiv, aber aktualisiere deine Zahlungsmethode, damit du den Zugang nicht verlierst, sobald die Versuche von Stripe ausgeschöpft sind.',
            'botao' => 'Zahlungsmethode aktualisieren',
            'rodape' => 'Dies ist eine automatische E-Mail - eine Antwort ist nicht möglich.',
        ],
        'it' => [
            'lang' => 'it', 'dir' => 'ltr',
            'subject' => 'Non siamo riusciti ad addebitare il tuo abbonamento - Zaldemy',
            'saudacao_nome' => 'Ciao, %s!', 'saudacao_sem_nome' => 'Ciao!',
            'p1' => "L'ultimo tentativo di addebito del tuo abbonamento Zaldemy+ non è riuscito. Può succedere per fondi insufficienti, carta scaduta o blocco della banca.",
            'p2' => 'Il tuo accesso Premium resta attivo per ora, ma aggiorna il metodo di pagamento per non perderlo quando i tentativi di Stripe finiranno.',
            'botao' => 'Aggiorna metodo di pagamento',
            'rodape' => "Questa è un'email automatica - non è possibile rispondere.",
        ],
        'zh' => [
            'lang' => 'zh', 'dir' => 'ltr',
            'subject' => '我们未能成功扣款您的订阅 - Zaldemy',
            'saudacao_nome' => '你好，%s！', 'saudacao_sem_nome' => '你好！',
            'p1' => '您的 Zaldemy+ 订阅最近一次扣款失败。可能是余额不足、银行卡过期或银行拦截导致的。',
            'p2' => '您的高级版权限目前仍然有效，但请尽快更新支付方式，以免在 Stripe 重试次数用完后失去访问权限。',
            'botao' => '更新支付方式',
            'rodape' => '这是一封自动发送的邮件，无法回复。',
        ],
        'ja' => [
            'lang' => 'ja', 'dir' => 'ltr',
            'subject' => 'サブスクリプションの請求に失敗しました - Zaldemy',
            'saudacao_nome' => '%sさん、こんにちは!', 'saudacao_sem_nome' => 'こんにちは!',
            'p1' => 'Zaldemy+の最新の請求に失敗しました。残高不足、カードの有効期限切れ、銀行側のブロックなどが原因の可能性があります。',
            'p2' => '現在もプレミアムアクセスは有効ですが、Stripeの再試行が終わる前に支払い方法を更新してアクセスを維持してください。',
            'botao' => '支払い方法を更新',
            'rodape' => 'これは自動送信メールです。返信はできません。',
        ],
        'ru' => [
            'lang' => 'ru', 'dir' => 'ltr',
            'subject' => 'Не удалось списать оплату за подписку - Zaldemy',
            'saudacao_nome' => 'Привет, %s!', 'saudacao_sem_nome' => 'Привет!',
            'p1' => 'Последняя попытка списания за подписку Zaldemy+ не удалась. Это может произойти из-за нехватки средств, просроченной карты или блокировки банком.',
            'p2' => 'Доступ к Premium пока активен, но обновите способ оплаты, чтобы не потерять доступ после того, как у Stripe закончатся попытки.',
            'botao' => 'Обновить способ оплаты',
            'rodape' => 'Это автоматическое письмо - ответ на него не отправляется.',
        ],
        'ar' => [
            'lang' => 'ar', 'dir' => 'rtl',
            'subject' => 'لم نتمكن من تحصيل قيمة اشتراكك - Zaldemy',
            'saudacao_nome' => 'مرحبًا، %s!', 'saudacao_sem_nome' => 'مرحبًا!',
            'p1' => 'فشلت آخر محاولة لتحصيل قيمة اشتراكك في Zaldemy+. قد يحدث هذا بسبب عدم كفاية الرصيد، أو انتهاء صلاحية البطاقة، أو حظر من البنك.',
            'p2' => 'لا يزال وصولك إلى Premium نشطًا حاليًا، لكن يُرجى تحديث وسيلة الدفع حتى لا تفقد الوصول بعد انتهاء محاولات Stripe.',
            'botao' => 'تحديث وسيلة الدفع',
            'rodape' => 'هذه رسالة تلقائية - لا يمكن الرد عليها.',
        ],
        'hi' => [
            'lang' => 'hi', 'dir' => 'ltr',
            'subject' => 'हम आपकी सदस्यता का भुगतान नहीं ले पाए - Zaldemy',
            'saudacao_nome' => 'नमस्ते, %s!', 'saudacao_sem_nome' => 'नमस्ते!',
            'p1' => 'आपकी Zaldemy+ सदस्यता के लिए आखिरी भुगतान प्रयास विफल हो गया। ऐसा अपर्याप्त बैलेंस, एक्सपायर हो चुके कार्ड, या बैंक की रोक की वजह से हो सकता है।',
            'p2' => 'आपकी प्रीमियम एक्सेस अभी भी सक्रिय है, लेकिन Stripe के प्रयास खत्म होने से पहले अपना भुगतान तरीका अपडेट करें ताकि आप एक्सेस न खोएं।',
            'botao' => 'भुगतान तरीका अपडेट करें',
            'rodape' => 'यह एक स्वचालित ईमेल है - इसका जवाब देना संभव नहीं है।',
        ],
        'ko' => [
            'lang' => 'ko', 'dir' => 'ltr',
            'subject' => '구독 결제에 실패했습니다 - Zaldemy',
            'saudacao_nome' => '안녕하세요, %s님!', 'saudacao_sem_nome' => '안녕하세요!',
            'p1' => 'Zaldemy+ 구독의 최근 결제 시도가 실패했습니다. 잔액 부족, 카드 만료, 은행 차단 등이 원인일 수 있습니다.',
            'p2' => '프리미엄 액세스는 아직 활성 상태지만, Stripe의 재시도가 끝나기 전에 결제 수단을 업데이트해 주세요.',
            'botao' => '결제 수단 업데이트',
            'rodape' => '이 메일은 자동으로 발송되었으며 답장은 받지 않습니다.',
        ],
        'nl' => [
            'lang' => 'nl', 'dir' => 'ltr',
            'subject' => 'We konden je abonnement niet afschrijven - Zaldemy',
            'saudacao_nome' => 'Hoi, %s!', 'saudacao_sem_nome' => 'Hoi!',
            'p1' => 'De laatste afschrijvingspoging voor je Zaldemy+ abonnement is mislukt. Dit kan komen door onvoldoende saldo, een verlopen kaart of een blokkade van de bank.',
            'p2' => 'Je Premium-toegang is voorlopig nog actief, maar werk je betaalmethode bij zodat je geen toegang verliest zodra de pogingen van Stripe op zijn.',
            'botao' => 'Betaalmethode bijwerken',
            'rodape' => 'Dit is een automatische e-mail - hierop reageren is niet mogelijk.',
        ],
        'tr' => [
            'lang' => 'tr', 'dir' => 'ltr',
            'subject' => 'Aboneliğinizden ödeme alamadık - Zaldemy',
            'saudacao_nome' => 'Merhaba, %s!', 'saudacao_sem_nome' => 'Merhaba!',
            'p1' => "Zaldemy+ aboneliğiniz için son ödeme denemesi başarısız oldu. Bunun nedeni yetersiz bakiye, süresi dolmuş kart veya banka engeli olabilir.",
            'p2' => "Premium erişiminiz şimdilik hâlâ aktif, ancak Stripe'ın denemeleri bitmeden ödeme yönteminizi güncelleyin ki erişiminizi kaybetmeyin.",
            'botao' => 'Ödeme yöntemini güncelle',
            'rodape' => 'Bu otomatik bir e-postadır - yanıtlanamaz.',
        ],
        'pl' => [
            'lang' => 'pl', 'dir' => 'ltr',
            'subject' => 'Nie udało się pobrać opłaty za subskrypcję - Zaldemy',
            'saudacao_nome' => 'Cześć, %s!', 'saudacao_sem_nome' => 'Cześć!',
            'p1' => 'Ostatnia próba pobrania opłaty za subskrypcję Zaldemy+ nie powiodła się. Może to wynikać z niewystarczających środków, nieważnej karty lub blokady banku.',
            'p2' => 'Twój dostęp Premium jest na razie nadal aktywny, ale zaktualizuj metodę płatności, aby go nie stracić po wyczerpaniu prób Stripe.',
            'botao' => 'Zaktualizuj metodę płatności',
            'rodape' => 'To automatyczna wiadomość - nie ma możliwości odpowiedzi.',
        ],
        'lt' => [
            'lang' => 'lt', 'dir' => 'ltr',
            'subject' => 'Nepavyko nuskaityti mokėjimo už prenumeratą - Zaldemy',
            'saudacao_nome' => 'Sveiki, %s!', 'saudacao_sem_nome' => 'Sveiki!',
            'p1' => 'Paskutinis bandymas nuskaityti mokėjimą už jūsų Zaldemy+ prenumeratą nepavyko. Taip gali nutikti dėl nepakankamo balanso, pasibaigusios kortelės galiojimo arba banko blokavimo.',
            'p2' => 'Jūsų Premium prieiga kol kas vis dar aktyvi, tačiau atnaujinkite mokėjimo būdą, kad neprarastumėte prieigos, kai baigsis „Stripe“ bandymai.',
            'botao' => 'Atnaujinti mokėjimo būdą',
            'rodape' => 'Tai automatinis laiškas - į jį atsakyti negalima.',
        ],
    ];

    return $textos[$sigla] ?? $textos['pt'];
}

// Único email com um template de verdade (os outros 3 acima são só
// <h2>/<p> soltos) - pedido explícito do usuário ("um email estilizado
// lógico") pro aviso de cobrança falhada, que é mais sensível (risco real
// de perder o Premium) que os outros e merece passar confiança visual.
// Tabela + estilo inline (não CSS externo/flexbox) porque clientes de
// email, principalmente Outlook desktop, ignoram a maior parte de CSS
// moderno - esse é o jeito que realmente renderiza igual na maioria deles.
// $idiomaSigla: código de 2 letras (mesmo de src/locales/ no front) do
// idioma NATIVO do usuário - pedido explícito do usuário pra não mandar
// email sempre em português pra quem não fala o idioma.
function enviarEmailPagamentoFalhou($email, $nome, ?string $idiomaSigla = 'pt') {

    $mail = new PHPMailer(true);

    try {

        $mail->isSMTP();
        $mail->CharSet    = 'UTF-8';
        $mail->Host       = $_ENV['MAIL_HOST'] ?? 'smtp.hostinger.com';
        $mail->SMTPAuth   = true;
        $mail->Username   = $_ENV['MAIL_USERNAME'] ?? 'adm@zaldemy.com';
        $mail->Password   = $_ENV['MAIL_PASSWORD'] ?? '';
        $mail->SMTPSecure = $_ENV['MAIL_ENCRYPTION'] ?? 'ssl';
        $mail->Port       = $_ENV['MAIL_PORT'] ?? 465;

        $fromEmail = $_ENV['MAIL_FROM_ADDRESS'] ?? 'adm@zaldemy.com';
        $fromName  = $_ENV['MAIL_FROM_NAME'] ?? 'Zaldemy';
        $appUrl    = rtrim($_ENV['APP_URL'] ?? 'https://zaldemy.com', '/');

        $mail->setFrom($fromEmail, $fromName);
        $mail->addAddress($email);

        $t = textosEmailPagamentoFalhou($idiomaSigla);

        $primeiroNome = trim(explode(' ', (string) $nome)[0] ?? '');
        $saudacao = $primeiroNome !== ''
            ? sprintf($t['saudacao_nome'], $primeiroNome)
            : $t['saudacao_sem_nome'];
        $link = "$appUrl/configuracoes";
        $logo = "$appUrl/icon-192.png";
        // H1 do corpo sem o "- Zaldemy" (só faz sentido no assunto do
        // email, já tem o logo Zaldemy logo acima no corpo).
        $headline = preg_replace('/ - Zaldemy$/', '', $t['subject']);

        $mail->isHTML(true);
        $mail->Subject = $t['subject'];
        $mail->Body    = <<<HTML
            <!DOCTYPE html>
            <html lang="{$t['lang']}" dir="{$t['dir']}">
            <body style="margin:0; padding:0; background-color:#0d1425; font-family:Arial, Helvetica, sans-serif;">
                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color:#0d1425; padding:32px 16px;">
                    <tr>
                        <td align="center">
                            <table role="presentation" width="480" cellpadding="0" cellspacing="0" style="max-width:480px; width:100%; background-color:#151f30; border:1px solid #2a3547; border-radius:16px; overflow:hidden;">
                                <tr>
                                    <td align="center" style="padding:28px 32px 0 32px;">
                                        <img src="{$logo}" width="48" height="48" alt="Zaldemy" style="display:block; border-radius:12px;">
                                    </td>
                                </tr>
                                <tr>
                                    <td align="center" style="padding:16px 32px 0 32px;">
                                        <div style="width:56px; height:56px; line-height:56px; border-radius:50%; background-color:#2a1f10; font-size:28px;">⚠️</div>
                                    </td>
                                </tr>
                                <tr>
                                    <td align="center" style="padding:16px 32px 0 32px;">
                                        <h1 style="margin:0; color:#ffffff; font-size:20px; font-weight:600;">{$headline}</h1>
                                    </td>
                                </tr>
                                <tr>
                                    <td style="padding:16px 32px 0 32px; color:#c3cbda; font-size:14px; line-height:22px;">
                                        <p style="margin:0 0 12px 0;">{$saudacao}</p>
                                        <p style="margin:0 0 12px 0;">{$t['p1']}</p>
                                        <p style="margin:0;">{$t['p2']}</p>
                                    </td>
                                </tr>
                                <tr>
                                    <td align="center" style="padding:24px 32px 8px 32px;">
                                        <table role="presentation" cellpadding="0" cellspacing="0">
                                            <tr>
                                                <td align="center" style="border-radius:999px; background-color:#4cb8c4;">
                                                    <a href="{$link}" target="_blank" style="display:inline-block; padding:12px 28px; color:#062229; font-size:14px; font-weight:700; text-decoration:none; border-radius:999px;">{$t['botao']}</a>
                                                </td>
                                            </tr>
                                        </table>
                                    </td>
                                </tr>
                                <tr>
                                    <td style="padding:24px 32px 28px 32px; border-top:1px solid #2a3547; margin-top:8px;">
                                        <p style="margin:16px 0 0 0; color:#6b7688; font-size:12px; line-height:18px; text-align:center;">{$t['rodape']}<br>Zaldemy</p>
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>
                </table>
            </body>
            </html>
            HTML;

        $mail->send();

        return true;

    } catch (Exception $e) {
        return false;
    }
}

function enviarEmailNotificacaoNovoCadastro($nome, $email) {

    $mail = new PHPMailer(true);

    try {

        $mail->isSMTP();
        $mail->CharSet    = 'UTF-8';
        $mail->Host       = $_ENV['MAIL_HOST'] ?? 'smtp.hostinger.com';
        $mail->SMTPAuth   = true;
        $mail->Username   = $_ENV['MAIL_USERNAME'] ?? 'adm@zaldemy.com';
        $mail->Password   = $_ENV['MAIL_PASSWORD'] ?? '';
        $mail->SMTPSecure = $_ENV['MAIL_ENCRYPTION'] ?? 'ssl';
        $mail->Port       = $_ENV['MAIL_PORT'] ?? 465;

        $fromEmail  = $_ENV['MAIL_FROM_ADDRESS'] ?? 'adm@zaldemy.com';
        $fromName   = $_ENV['MAIL_FROM_NAME'] ?? 'Zaldemy';
        $adminEmail = $_ENV['ADMIN_NOTIFICATION_EMAIL'] ?? 'marciosunico37@gmail.com';

        $mail->setFrom($fromEmail, $fromName);
        $mail->addAddress($adminEmail);

        $mail->isHTML(true);
        $mail->Subject = 'Novo usuário cadastrado - Zaldemy';
        $mail->Body    = "
            <h2>Novo cadastro no Zaldemy</h2>
            <p><strong>Nome:</strong> $nome</p>
            <p><strong>Email:</strong> $email</p>
        ";

        $mail->send();

        return true;

    } catch (Exception $e) {
        return false;
    }
}
