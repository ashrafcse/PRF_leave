<?php
// test_php56.php - Place in PRF_Leave root
echo "<h2>PHP 5.6 Compatibility Test</h2>";
echo "<p>PHP Version: " . phpversion() . "</p>";

// Test PHPMailer
$phpmailerPath = __DIR__ . '/include/PHPMailer/';
echo "<p>Checking: $phpmailerPath</p>";

if (is_dir($phpmailerPath)) {
    echo "<p style='color:green'>✓ PHPMailer folder exists</p>";
    
    // Check for v5.2 files
    $files = ['class.phpmailer.php', 'class.smtp.php'];
    $allExist = true;
    foreach ($files as $file) {
        if (file_exists($phpmailerPath . $file)) {
            echo "<p style='color:green'>✓ $file found</p>";
        } else {
            echo "<p style='color:red'>✗ $file missing</p>";
            $allExist = false;
        }
    }
    
    if ($allExist) {
        // Try to load
        require_once $phpmailerPath . 'class.phpmailer.php';
        require_once $phpmailerPath . 'class.smtp.php';
        
        if (class_exists('PHPMailer')) {
            echo "<p style='color:green; font-weight:bold;'>✓ PHPMailer v5.2 loaded successfully!</p>";
        } else {
            echo "<p style='color:red'>✗ PHPMailer class not found</p>";
        }
    }
} else {
    echo "<p style='color:red'>✗ PHPMailer folder not found</p>";
}

// Test email function
echo "<h3>Testing Email Function</h3>";
require_once __DIR__ . '/include/smtp_config.php';
require_once __DIR__ . '/include/smtp_mail.php';

if (function_exists('sendSMTPMail')) {
    echo "<p style='color:green'>✓ sendSMTPMail() function exists</p>";
    
    // Quick test
    echo "<p><a href='pages/leave/test_gmail.php'>Go to Email Test Page</a></p>";
} else {
    echo "<p style='color:red'>✗ sendSMTPMail() function not found</p>";
}
?>