<?php
// quick_test.php - Direct test
error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "<h2>Direct PHPMailer Test</h2>";

// Load PHPMailer directly
require_once __DIR__ . '/include/PHPMailer/src/PHPMailer.php';
require_once __DIR__ . '/include/PHPMailer/src/SMTP.php';
require_once __DIR__ . '/include/PHPMailer/src/Exception.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception;

// Create instance
$mail = new PHPMailer(true);

try {
    echo "1. PHPMailer instance created<br>";
    
    // Server settings
    $mail->isSMTP();
    $mail->Host = 'smtp.gmail.com';
    $mail->Port = 587;
    $mail->SMTPSecure = 'tls';
    $mail->SMTPAuth = true;
    $mail->Username = 'ashraf.nrl@gmail.com';
    $mail->Password = 'jrmijiroqulcevbs'; // Replace with your App Password
    
    // XAMPP/Gmail settings
    $mail->SMTPOptions = [
        'ssl' => [
            'verify_peer' => false,
            'verify_peer_name' => false,
            'allow_self_signed' => true
        ]
    ];
    
    echo "2. SMTP configured<br>";
    
    // Sender/Recipient
    $mail->setFrom('ashraf.nrl@gmail.com', 'PRF Test');
    $mail->addAddress('zamanitc@gmail.com');
    
    echo "3. Sender/recipient set<br>";
    
    // Content
    $mail->isHTML(true);
    $mail->Subject = 'Direct PHPMailer Test';
    $mail->Body = '<h1>Test Email</h1><p>If you see this, PHPMailer works!</p>';
    
    echo "4. Content set<br>";
    
    // Send
    if ($mail->send()) {
        echo "<div style='color:green; font-weight:bold; padding:10px; border:2px solid green;'>
              ✓ Email sent successfully!</div>";
    } else {
        echo "<div style='color:red; padding:10px; border:2px solid red;'>
              ✗ Send failed: " . $mail->ErrorInfo . "</div>";
    }
    
} catch (Exception $e) {
    echo "<div style='color:red; padding:10px; border:2px solid red;'>
          Exception: " . $e->getMessage() . "</div>";
}
?>