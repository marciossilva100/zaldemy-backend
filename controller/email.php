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
