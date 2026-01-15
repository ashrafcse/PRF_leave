<?php
require_once __DIR__ . '/phpmailer/class.phpmailer.php';
require_once __DIR__ . '/phpmailer/class.smtp.php';

function sendSMTPMail($to, $subject, $bodyHtml)
{
    // DEBUGGING: Log the email attempt
    error_log("DEBUG SMTP: Attempting to send email to: " . $to . " | Subject: " . $subject);
    
    $mail = new PHPMailer(true);

    try {
        // SMTP config for Microsoft 365
        $mail->isSMTP();
        $mail->Host       = 'smtp.office365.com';
        $mail->SMTPAuth   = true;
        $mail->Username   = 'noreply@prfbd.org';   // Outlook email
        $mail->Password   = 'PRF@slt321';          // App Password
        $mail->SMTPSecure = 'tls';
        $mail->Port       = 587;

        // SMTP Debugging (uncomment for troubleshooting)
        // $mail->SMTPDebug = 2;
        // $mail->Debugoutput = function($str, $level) {
        //     error_log("SMTP Debug: $str");
        // };

        // Timeout settings
        $mail->Timeout = 30;

        // Encoding
        $mail->CharSet = 'UTF-8';

        // Sender
        $mail->setFrom('noreply@prfbd.org', 'PRF Leave System');
        
        // Reply-To (optional)
        $mail->addReplyTo('noreply@prfbd.org', 'PRF Leave System');

        // IMPORTANT FIX: Use the $to parameter passed to the function
        // Handle multiple recipients if $to is an array
        if (is_array($to)) {
            foreach ($to as $email) {
                if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    $mail->addAddress($email);
                }
            }
        } else {
            // Single recipient
            if (filter_var($to, FILTER_VALIDATE_EMAIL)) {
                $mail->addAddress($to);
            } else {
                error_log("ERROR SMTP: Invalid email address: " . $to);
                return false;
            }
        }

        // Email content
        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body    = $bodyHtml;
        
        // Alternative plain text body
        $mail->AltBody = strip_tags($bodyHtml);

        // Send
        $result = $mail->send();
        
        if ($result) {
            error_log("SUCCESS SMTP: Email sent successfully");
        } else {
            error_log("FAILED SMTP: Email not sent");
        }
        
        return $result;

    } catch (phpmailerException $e) {
        error_log('SMTP Mail Error: ' . $e->getMessage());
        return false;
    } catch (Exception $e) {
        error_log('General Mail Error: ' . $e->getMessage());
        return false;
    }
}