<?php
/***********************
 * SMTP Configuration for PRF Leave System
 * Using Gmail: zamanitc@gmail.com
 ***********************/

// Remove any previously defined constants to avoid duplicate warnings
$constants_to_undefine = [
    'SMTP_HOST', 'SMTP_PORT', 'SMTP_SECURE', 'SMTP_USERNAME',
    'SMTP_PASSWORD', 'SMTP_FROM_EMAIL', 'SMTP_FROM_NAME',
    'SMTP_DEBUG', 'SMTP_CHARSET'
];

foreach ($constants_to_undefine as $constant) {
    if (defined($constant)) {
        // We can't undefine constants in PHP, but we can avoid redefining them
        // by using conditional definition below
    }
}

// ===== GMAIL SMTP CONFIGURATION =====
// Replace 'your-app-password' with your actual 16-digit Gmail App Password
define('SMTP_HOST', 'smtp.gmail.com');          // Gmail SMTP server
define('SMTP_PORT', 587);                       // Port for TLS
define('SMTP_SECURE', 'tls');                   // Use TLS encryption
define('SMTP_USERNAME', 'ashraf.nrl@gmail.com');  // Your Gmail address
define('SMTP_PASSWORD', 'jrmijiroqulcevbs');   // <-- PUT YOUR APP PASSWORD HERE
define('SMTP_FROM_EMAIL', 'ashraf.nrl@gmail.com'); // From email address
define('SMTP_FROM_NAME', 'PRF Leave System');   // From name
define('SMTP_DEBUG', 0);                        // 0=off, 1=client, 2=client+server
define('SMTP_CHARSET', 'UTF-8');                // Email charset

// Email validation function
function isValidEmail($email) {
    return filter_var($email, FILTER_VALIDATE_EMAIL) && preg_match('/@.+\./', $email);
}

// ===== HOW TO GET GMAIL APP PASSWORD =====
// 1. Go to https://myaccount.google.com
// 2. Click "Security" on the left
// 3. Enable "2-Step Verification"
// 4. Go back to Security → "App passwords"
// 5. Select "Mail" → "Other" → Name it "PRF Leave System"
// 6. Copy the 16-character password (without spaces)
// 7. Paste it above replacing 'your-app-password'
?>