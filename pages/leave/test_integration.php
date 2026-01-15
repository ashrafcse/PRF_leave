<?php
/***********************
 * Email System Integration Test
 * Location: /PRF_Leave/pages/leave/test_integration.php
 ***********************/

// Enable full error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Fix the path - init.php is in the root, not in leave folder
require_once __DIR__ . '/../../init.php';

// Check if user is logged in
if (!isset($_SESSION['auth_user']['UserID'])) {
    die("
        <div style='padding: 20px; font-family: Arial;'>
            <h2 style='color: red;'>Access Denied</h2>
            <p>Please <a href='../../login.php'>login</a> first.</p>
        </div>
    ");
}

// Include email functions
require_once __DIR__ . '/../../include/smtp_mail.php';

// HTML header
echo '<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Email Integration Test - PRF Leave System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        body { background: #f8f9fa; padding: 20px; }
        .card { margin-bottom: 20px; border-radius: 10px; }
        .success { color: #198754; }
        .error { color: #dc3545; }
        .warning { color: #ffc107; }
        .test-result { padding: 10px; border-radius: 5px; margin: 10px 0; }
    </style>
</head>
<body>
    <div class="container">
        <div class="card">
            <div class="card-header bg-primary text-white">
                <h3 class="mb-0"><i class="fas fa-envelope me-2"></i> Email System Integration Test</h3>
            </div>
            <div class="card-body">';

// Test 1: Check if functions exist
echo "<h4>1. Function Availability Check</h4>";
$functions = [
    'sendSMTPMail' => 'Main email sending function',
    'sendLeaveNotificationToSupervisors' => 'Supervisor notification function',
    'generateGmailLeaveEmailHTML' => 'Email template generator',
    'testGmailSMTP' => 'Test function'
];

foreach ($functions as $func => $description) {
    if (function_exists($func)) {
        echo "<div class='alert alert-success'>
                <i class='fas fa-check-circle'></i> <strong>$func()</strong> - $description
              </div>";
    } else {
        echo "<div class='alert alert-danger'>
                <i class='fas fa-times-circle'></i> <strong>$func()</strong> - NOT FOUND - $description
              </div>";
    }
}

// Test 2: Check configuration constants
echo "<h4 class='mt-4'>2. Configuration Check</h4>";
$constants = [
    'SMTP_HOST' => 'SMTP Server',
    'SMTP_PORT' => 'SMTP Port',
    'SMTP_SECURE' => 'SMTP Security',
    'SMTP_USERNAME' => 'SMTP Username',
    'SMTP_FROM_EMAIL' => 'From Email',
    'SMTP_FROM_NAME' => 'From Name'
];

foreach ($constants as $const => $description) {
    if (defined($const)) {
        $value = constant($const);
        // Hide password for security
        if ($const === 'SMTP_PASSWORD') {
            $value = '••••••••••••••••';
        }
        echo "<div class='alert alert-success'>
                <i class='fas fa-check-circle'></i> <strong>$const</strong> = $value - $description
              </div>";
    } else {
        echo "<div class='alert alert-warning'>
                <i class='fas fa-exclamation-triangle'></i> <strong>$const</strong> - NOT DEFINED - $description
              </div>";
    }
}

// Test 3: Test direct email sending
echo "<h4 class='mt-4'>3. Direct Email Test</h4>";

// Get user's email or use default
$userEmail = $_SESSION['auth_user']['Email'] ?? 'ashraf.nrl@gmail.com';
echo "<p>Testing with email: <strong>$userEmail</strong></p>";

// Simple test email
$testSubject = "PRF Leave System - Integration Test";
$testBody = "<h3>Integration Test Successful!</h3>
             <p>This email confirms that your PRF Leave System email integration is working correctly.</p>
             <p><strong>Test Details:</strong></p>
             <ul>
                <li>Date: " . date('Y-m-d H:i:s') . "</li>
                <li>PHP Version: " . PHP_VERSION . "</li>
                <li>Test Type: Direct Integration Test</li>
             </ul>
             <p style='color: green; font-weight: bold;'>✓ Email system is working!</p>";

if (function_exists('sendSMTPMail')) {
    $testResult = sendSMTPMail($userEmail, $testSubject, $testBody);
    
    if ($testResult) {
        echo "<div class='alert alert-success'>
                <i class='fas fa-check-circle'></i> <strong>Direct Email Test PASSED</strong>
                <p class='mb-0'>Test email sent successfully to $userEmail. Please check your inbox and spam folder.</p>
              </div>";
    } else {
        echo "<div class='alert alert-danger'>
                <i class='fas fa-times-circle'></i> <strong>Direct Email Test FAILED</strong>
                <p class='mb-0'>Failed to send test email. Check PHP error logs for details.</p>
              </div>";
    }
} else {
    echo "<div class='alert alert-danger'>
            <i class='fas fa-times-circle'></i> <strong>sendSMTPMail() function not available</strong>
            <p class='mb-0'>Check if smtp_mail.php is properly included.</p>
          </div>";
}

// Test 4: Check PHPMailer installation
echo "<h4 class='mt-4'>4. PHPMailer Installation Check</h4>";
$phpmailerPath = __DIR__ . '/../../include/PHPMailer/src/';

if (is_dir($phpmailerPath)) {
    $requiredFiles = ['PHPMailer.php', 'SMTP.php', 'Exception.php'];
    $missingFiles = [];
    
    foreach ($requiredFiles as $file) {
        if (!file_exists($phpmailerPath . $file)) {
            $missingFiles[] = $file;
        }
    }
    
    if (empty($missingFiles)) {
        echo "<div class='alert alert-success'>
                <i class='fas fa-check-circle'></i> <strong>PHPMailer v6+ Installed Correctly</strong>
                <p class='mb-0'>All required files found in: $phpmailerPath</p>
              </div>";
    } else {
        echo "<div class='alert alert-danger'>
                <i class='fas fa-times-circle'></i> <strong>PHPMailer Files Missing</strong>
                <p class='mb-0'>Missing files: " . implode(', ', $missingFiles) . "</p>
                <p class='mb-0'>Expected in: $phpmailerPath</p>
              </div>";
    }
} else {
    echo "<div class='alert alert-danger'>
            <i class='fas fa-times-circle'></i> <strong>PHPMailer Not Found</strong>
            <p class='mb-0'>Directory not found: $phpmailerPath</p>
          </div>";
}

// Test 5: Generate sample email template
echo "<h4 class='mt-4'>5. Email Template Test</h4>";

if (function_exists('generateGmailLeaveEmailHTML')) {
    $sampleLeaveData = [
        'LeaveApplicationID' => 999,
        'LeaveTypeName' => 'Annual Leave',
        'StartDate' => date('Y-m-d'),
        'EndDate' => date('Y-m-d', strtotime('+2 days')),
        'TotalDays' => 3,
        'Reason' => 'This is a test reason for the integration test.',
        'AppliedDate' => date('Y-m-d H:i:s')
    ];
    
    $sampleEmployeeData = [
        'FirstName' => 'Test',
        'LastName' => 'User',
        'EmployeeCode' => 'EMP001',
        'Email' => $userEmail
    ];
    
    $emailContent = generateGmailLeaveEmailHTML($sampleLeaveData, $sampleEmployeeData);
    
    echo "<div class='alert alert-success'>
            <i class='fas fa-check-circle'></i> <strong>Email Template Generation PASSED</strong>
            <p class='mb-0'><strong>Subject:</strong> " . htmlspecialchars($emailContent['subject']) . "</p>
            <p class='mb-0'><strong>Body Length:</strong> " . strlen($emailContent['body']) . " characters</p>
          </div>";
    
    // Show a preview (optional)
    echo '<button class="btn btn-sm btn-info" type="button" data-bs-toggle="collapse" data-bs-target="#emailPreview">
            <i class="fas fa-eye"></i> Preview Email Subject
          </button>
          <div class="collapse mt-2" id="emailPreview">
            <div class="card card-body">
              <strong>Subject Preview:</strong><br>
              ' . htmlspecialchars($emailContent['subject']) . '
            </div>
          </div>';
} else {
    echo "<div class='alert alert-danger'>
            <i class='fas fa-times-circle'></i> <strong>Email Template Generation FAILED</strong>
            <p class='mb-0'>generateGmailLeaveEmailHTML() function not found.</p>
          </div>";
}

// Test 6: Database connection check (if you want to test supervisor emails)
echo "<h4 class='mt-4'>6. System Environment</h4>";
echo "<div class='alert alert-info'>
        <i class='fas fa-info-circle'></i> <strong>System Information</strong>
        <ul class='mb-0'>
            <li><strong>PHP Version:</strong> " . PHP_VERSION . "</li>
            <li><strong>Server:</strong> " . ($_SERVER['SERVER_SOFTWARE'] ?? 'Unknown') . "</li>
            <li><strong>Current User:</strong> " . ($_SESSION['auth_user']['Username'] ?? 'Not logged in') . "</li>
            <li><strong>User ID:</strong> " . ($_SESSION['auth_user']['UserID'] ?? 'N/A') . "</li>
            <li><strong>Session Status:</strong> " . (session_status() === PHP_SESSION_ACTIVE ? 'Active' : 'Inactive') . "</li>
        </ul>
      </div>";

// Action buttons
echo "<h4 class='mt-4'>7. Next Steps</h4>";
echo "<div class='d-flex gap-2 flex-wrap'>
        <a href='leave_apply.php' class='btn btn-primary'>
            <i class='fas fa-paper-plane me-2'></i> Go to Leave Application
        </a>
        <a href='test_gmail.php' class='btn btn-secondary'>
            <i class='fas fa-envelope me-2'></i> Test Email Configuration
        </a>
        <a href='../../include/smtp_config.php' target='_blank' class='btn btn-info'>
            <i class='fas fa-cog me-2'></i> View Email Config
        </a>
        <button onclick='location.reload()' class='btn btn-warning'>
            <i class='fas fa-redo me-2'></i> Run Tests Again
        </button>
      </div>";

// Close HTML
echo '            </div>
        </div>
        
        <!-- Test Results Summary -->
        <div class="card mt-3">
            <div class="card-header bg-dark text-white">
                <h5 class="mb-0"><i class="fas fa-clipboard-check me-2"></i> Test Results Summary</h5>
            </div>
            <div class="card-body">
                <p><strong>Expected Outcome:</strong> All tests should pass for the email system to work properly.</p>
                <p><strong>If tests fail:</strong></p>
                <ol>
                    <li>Check that PHPMailer files are in: <code>include/PHPMailer/src/</code></li>
                    <li>Verify <code>smtp_config.php</code> has correct Gmail App Password</li>
                    <li>Check XAMPP error logs: <code>C:\xampp\php\logs\php_error_log</code></li>
                    <li>Restart XAMPP Apache service</li>
                </ol>
                <p class="mb-0"><strong>Note:</strong> After successful tests, you can submit a real leave application to test supervisor notifications.</p>
            </div>
        </div>
    </div>
    
    <!-- Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    
    <script>
        // Auto-refresh page if any critical tests failed
        setTimeout(function() {
            const criticalErrors = document.querySelectorAll(\'.alert-danger\');
            if (criticalErrors.length > 0) {
                console.log(\'Critical errors detected. Consider refreshing to re-run tests.\');
            }
        }, 5000);
    </script>
</body>
</html>';