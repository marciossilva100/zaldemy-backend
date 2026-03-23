<?php

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require '../vendor/autoload.php';

function enviarEmailVerificacao($email, $token) {

    $mail = new PHPMailer(true);

    try {

        $mail->isSMTP();
        $mail->Host       = 'smtp.gmail.com';
        $mail->SMTPAuth   = true;
        $mail->Username   = 'marciosunico18@gmail.com';
        $mail->Password   = 'ofzw rdgs egfk ytly';
        $mail->SMTPSecure = 'tls';
        $mail->Port       = 587;

        $mail->setFrom('marciosunico18@gmail.com', 'Memly');
        $mail->addAddress($email);

        $link = "https://memly-jijk.vercel.app/emailverificado?token=$token";


        $mail->isHTML(true);
        $mail->Subject = 'Confirme seu email';
        $mail->Body    = "
            <h2>Confirme sua conta</h2>
            <p>Clique no link abaixo para verificar seu email:</p>
            <a href='$link'>$link</a>
        ";

        $mail->send();

        return true;

    } catch (Exception $e) {
        return false;
    }
}
