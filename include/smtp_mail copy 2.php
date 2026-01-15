<?php
require_once __DIR__ . '/phpmailer/class.phpmailer.php';
require_once __DIR__ . '/phpmailer/class.smtp.php';

function sendSMTPMail($to, $subject, $bodyHtml)
{
    echo "Hello";
    $mail = new PHPMailer(true);

    try {
        // SMTP config for Microsoft 365
        $mail->isSMTP();
        $mail->Host       = 'smtp.office365.com';
        $mail->SMTPAuth   = true;
        $mail->Username   = 'bhossain@prfbd.org';   // Outlook email
        $mail->Password   = 'BayazidHossain@2000';        // 🔐 App Password
        $mail->SMTPSecure = 'tls';
        $mail->Port       = 587;

        // Encoding
        $mail->CharSet = 'UTF-8';

        // Sender
        $mail->setFrom('bhossain@prfbd.org', 'PRF Leave System');

        // Recipient (dynamic)
        $mail->addAddress('ashrafuzzaman@prfbd.org');

        // Email content
        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body    = $bodyHtml;

        // Send
        $mail->send();
        return true;

    } catch (phpmailerException $e) { echo 'SMTP Mail Error: ' . $e->getMessage();
        error_log('SMTP Mail Error: ' . $e->getMessage());
        return false;
    }
}
