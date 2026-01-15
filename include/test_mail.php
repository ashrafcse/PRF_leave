<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . '/smtp_mail.php';

$to = 'ashrafuzzaman@prfbd.org'; // Outlook mailbox
$subject = 'Microsoft 365 SMTP Test';
$body = '
<h2>SMTP Test Successful</h2>
<p>This email was sent using Microsoft 365 SMTP.</p>
<p>Date: ' . date('Y-m-d H:i:s') . '</p>
';

if (sendSMTPMail($to, $subject, $body)) {
    echo "✅ Email sent successfully via Microsoft 365 SMTP!";
} else {
    echo "❌ Email failed. Check error_log.";
}
