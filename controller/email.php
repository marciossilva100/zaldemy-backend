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

// Único email com um template de verdade (os outros 3 acima são só
// <h2>/<p> soltos) - pedido explícito do usuário ("um email estilizado
// lógico") pro aviso de cobrança falhada, que é mais sensível (risco real
// de perder o Premium) que os outros e merece passar confiança visual.
// Tabela + estilo inline (não CSS externo/flexbox) porque clientes de
// email, principalmente Outlook desktop, ignoram a maior parte de CSS
// moderno - esse é o jeito que realmente renderiza igual na maioria deles.
function enviarEmailPagamentoFalhou($email, $nome) {

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

        $primeiroNome = trim(explode(' ', (string) $nome)[0] ?? '');
        $saudacao = $primeiroNome !== '' ? "Olá, {$primeiroNome}!" : "Olá!";
        $link = "$appUrl/configuracoes";
        $logo = "$appUrl/icon-192.png";

        $mail->isHTML(true);
        $mail->Subject = 'Não conseguimos cobrar sua assinatura - Zaldemy';
        $mail->Body    = <<<HTML
            <!DOCTYPE html>
            <html lang="pt-BR">
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
                                        <h1 style="margin:0; color:#ffffff; font-size:20px; font-weight:600;">Não conseguimos cobrar sua assinatura</h1>
                                    </td>
                                </tr>
                                <tr>
                                    <td style="padding:16px 32px 0 32px; color:#c3cbda; font-size:14px; line-height:22px;">
                                        <p style="margin:0 0 12px 0;">{$saudacao}</p>
                                        <p style="margin:0 0 12px 0;">A última tentativa de cobrança da sua assinatura <strong style="color:#ffffff;">Zaldemy+</strong> falhou. Isso pode acontecer por saldo insuficiente, cartão vencido ou bloqueio do banco.</p>
                                        <p style="margin:0;">Seu acesso Premium continua ativo por enquanto, mas atualize sua forma de pagamento pra não perder o acesso quando as tentativas do Stripe acabarem.</p>
                                    </td>
                                </tr>
                                <tr>
                                    <td align="center" style="padding:24px 32px 8px 32px;">
                                        <table role="presentation" cellpadding="0" cellspacing="0">
                                            <tr>
                                                <td align="center" style="border-radius:999px; background-color:#4cb8c4;">
                                                    <a href="{$link}" target="_blank" style="display:inline-block; padding:12px 28px; color:#062229; font-size:14px; font-weight:700; text-decoration:none; border-radius:999px;">Atualizar forma de pagamento</a>
                                                </td>
                                            </tr>
                                        </table>
                                    </td>
                                </tr>
                                <tr>
                                    <td style="padding:24px 32px 28px 32px; border-top:1px solid #2a3547; margin-top:8px;">
                                        <p style="margin:16px 0 0 0; color:#6b7688; font-size:12px; line-height:18px; text-align:center;">Este é um email automático - não é possível responder a ele.<br>Zaldemy</p>
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
