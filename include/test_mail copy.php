<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . '/smtp_mail.php'; // your function file

$subject = 'SMTP Test Email - PRF';
$body = '
<h2>SMTP Test Successful</h2>
<p>This email confirms that SMTP is working.</p>
<p>Date: ' . date('Y-m-d H:i:s') . '</p>
';

$result = sendSMTPMail(
    'ashrafuzzaman@prfbd.org', // send to yourself
    $subject,
    $body
);

if ($result) {
    echo "✅ Email sent successfully!";
} else {
    echo "❌ Email sending failed. Check error_log.";
}
