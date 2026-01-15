<?php
/***********************
 * Gmail SMTP Test Page
 * Location: /PRF_Leave/pages/leave/test_gmail.php
 ***********************/

// Enable full error reporting
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Start session and check login
session_start();
if (!isset($_SESSION['auth_user']['UserID'])) {
    die("
        <div style='padding: 20px; font-family: Arial;'>
            <h2 style='color: red;'>Access Denied</h2>
            <p>Please <a href='../../login.php'>login</a> first.</p>
        </div>
    ");
}

// Include necessary files
require_once __DIR__ . '/../../init.php';
require_once __DIR__ . '/../../include/smtp_config.php';
require_once __DIR__ . '/../../include/smtp_mail.php';

// Load PHPMailer classes globally
require_once __DIR__ . '/../../include/PHPMailer/src/PHPMailer.php';
require_once __DIR__ . '/../../include/PHPMailer/src/SMTP.php';
require_once __DIR__ . '/../../include/PHPMailer/src/Exception.php';

$test_result = '';
$config_details = '';
$debug_info = '';

// Debug: Show what's loaded
$debug_info .= "<div style='background: #f5f5f5; padding: 15px; border-radius: 5px; margin-bottom: 20px;'>
                <h4 style='margin-top: 0;'>Debug Info</h4>";

// Check PHP version
$debug_info .= "<p><strong>PHP Version:</strong> " . PHP_VERSION . "</p>";

// Check PHPMailer path
$phpmailerPath = __DIR__ . '/../../include/PHPMailer/';
$debug_info .= "<p><strong>PHPMailer Path:</strong> $phpmailerPath</p>";

// Check if src folder exists
$srcPath = $phpmailerPath . 'src/';
if (is_dir($srcPath)) {
    $debug_info .= "<p style='color: green;'>✓ Found src/ folder</p>";
    
    // List files in src folder
    $files = scandir($srcPath);
    $debug_info .= "<p><strong>Files in src folder:</strong> ";
    $file_list = [];
    foreach ($files as $file) {
        if ($file !== '.' && $file !== '..') {
            $file_list[] = $file;
        }
    }
    $debug_info .= implode(', ', $file_list) . "</p>";
    
    // Check for required files
    $required_files = ['PHPMailer.php', 'SMTP.php', 'Exception.php'];
    $missing_files = [];
    foreach ($required_files as $file) {
        if (!file_exists($srcPath . $file)) {
            $missing_files[] = $file;
        }
    }
    
    if (empty($missing_files)) {
        $debug_info .= "<p style='color: green;'>✓ All required PHPMailer files present</p>";
    } else {
        $debug_info .= "<p style='color: red;'>✗ Missing files: " . implode(', ', $missing_files) . "</p>";
    }
} else {
    $debug_info .= "<p style='color: red;'>✗ src/ folder not found!</p>";
}

// Check config constants
$debug_info .= "<p><strong>Configuration Check:</strong></p>";
$constants = ['SMTP_HOST', 'SMTP_PORT', 'SMTP_SECURE', 'SMTP_USERNAME', 'SMTP_FROM_EMAIL', 'SMTP_FROM_NAME'];
foreach ($constants as $const) {
    if (defined($const)) {
        $value = constant($const);
        if ($const === 'SMTP_PASSWORD') {
            $value = str_repeat('*', strlen($value));
        }
        $debug_info .= "<span style='color: green;'>✓ $const = $value</span><br>";
    } else {
        $debug_info .= "<span style='color: red;'>✗ $const NOT defined</span><br>";
    }
}

$debug_info .= "</div>";

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['test_email'])) {
    $test_email = trim($_POST['test_email']);
    $use_direct_method = isset($_POST['direct_method']);
    
    // Validate email
    if (!filter_var($test_email, FILTER_VALIDATE_EMAIL)) {
        $test_result = '<div class="alert alert-danger">
            <i class="fas fa-exclamation-triangle"></i> Please enter a valid email address
        </div>';
    } else {
        if ($use_direct_method) {
            // Use direct method for testing
            $direct_result = testDirectPHPMailer($test_email);
            $test_result = $direct_result['message'];
            $config_details = $direct_result['details'];
        } else {
            // Use the function from smtp_mail.php
            if (function_exists('testGmailSMTP')) {
                $results = testGmailSMTP($test_email);
                
                if ($results['success']) {
                    $test_result = '<div class="alert alert-success alert-dismissible fade show">
                        <i class="fas fa-check-circle"></i> <strong>Success!</strong> ' . $results['message'] . '
                        <br><small>' . $results['details'] . '</small>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>';
                } else {
                    $test_result = '<div class="alert alert-danger alert-dismissible fade show">
                        <i class="fas fa-exclamation-triangle"></i> <strong>Failed!</strong> ' . $results['message'] . '
                        <br><small>' . $results['details'] . '</small>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>';
                }
                
                // Show configuration
                $config_details = '<h5 class="mt-4"><i class="fas fa-cog"></i> Current Configuration:</h5>';
                $config_details .= '<div class="table-responsive"><table class="table table-bordered table-sm">';
                foreach ($results['config'] as $key => $value) {
                    $config_details .= '<tr><th>' . htmlspecialchars($key) . '</th><td>' . htmlspecialchars($value) . '</td></tr>';
                }
                $config_details .= '</table></div>';
            } else {
                $test_result = '<div class="alert alert-warning">
                    <i class="fas fa-exclamation-circle"></i> Function testGmailSMTP() not found.
                    <br>Using direct test method instead.
                </div>';
                $direct_result = testDirectPHPMailer($test_email);
                $test_result .= $direct_result['message'];
                $config_details = $direct_result['details'];
            }
        }
    }
}

/**
 * Direct PHPMailer test function
 */
function testDirectPHPMailer($testEmail) {
    $result = [
        'message' => '',
        'details' => ''
    ];
    
    try {
        // Create PHPMailer instance using fully qualified class name
        $mail = new PHPMailer\PHPMailer\PHPMailer(true);
        
        // Configure for Gmail
        $mail->isSMTP();
        $mail->Host = defined('SMTP_HOST') ? SMTP_HOST : 'smtp.gmail.com';
        $mail->Port = defined('SMTP_PORT') ? SMTP_PORT : 587;
        $mail->SMTPSecure = defined('SMTP_SECURE') ? SMTP_SECURE : 'tls';
        $mail->SMTPAuth = true;
        $mail->Username = defined('SMTP_USERNAME') ? SMTP_USERNAME : '';
        $mail->Password = defined('SMTP_PASSWORD') ? SMTP_PASSWORD : '';
        
        // For XAMPP on Windows
        $mail->SMTPOptions = [
            'ssl' => [
                'verify_peer' => false,
                'verify_peer_name' => false,
                'allow_self_signed' => true
            ]
        ];
        
        $mail->CharSet = 'UTF-8';
        $mail->Timeout = 30;
        
        // Set sender and recipient
        $fromEmail = defined('SMTP_FROM_EMAIL') ? SMTP_FROM_EMAIL : 'ashraf.nrl@gmail.com';
        $fromName = defined('SMTP_FROM_NAME') ? SMTP_FROM_NAME : 'PRF Leave System';
        
        $mail->setFrom($fromEmail, $fromName);
        $mail->addAddress($testEmail);
        $mail->addReplyTo($fromEmail, $fromName);
        
        // Email content
        $mail->isHTML(true);
        $mail->Subject = "Direct PHPMailer Test - PRF Leave System";
        $mail->Body = '
        <h2>Direct PHPMailer Test Successful!</h2>
        <p>This email was sent using direct PHPMailer configuration.</p>
        <p><strong>Details:</strong></p>
        <ul>
            <li>Date: ' . date('Y-m-d H:i:s') . '</li>
            <li>SMTP Server: ' . $mail->Host . '</li>
            <li>Port: ' . $mail->Port . ' (' . $mail->SMTPSecure . ')</li>
            <li>PHP Version: ' . PHP_VERSION . '</li>
        </ul>
        <p style="color: green; font-weight: bold;">✅ Your email configuration is working correctly!</p>';
        
        $mail->AltBody = "Direct PHPMailer Test\nSent on: " . date('Y-m-d H:i:s') . "\nSMTP: " . $mail->Host . ":" . $mail->Port;
        
        // Send the email
        if ($mail->send()) {
            $result['message'] = '<div class="alert alert-success">
                <i class="fas fa-check-circle"></i> <strong>Direct Test Success!</strong> Email sent to ' . $testEmail . '
                <p class="mb-0">Please check your inbox and spam folder.</p>
            </div>';
            
            $result['details'] = '
            <div class="mt-3">
                <h5><i class="fas fa-info-circle"></i> Direct Test Details:</h5>
                <div class="alert alert-info">
                    <p><strong>Method Used:</strong> Direct PHPMailer instantiation</p>
                    <p><strong>PHPMailer Version:</strong> v6+ (Namespaced)</p>
                    <p><strong>Load Path:</strong> include/PHPMailer/src/</p>
                    <p><strong>Status:</strong> <span class="badge bg-success">Working Perfectly</span></p>
                </div>
            </div>';
        } else {
            $result['message'] = '<div class="alert alert-danger">
                <i class="fas fa-exclamation-triangle"></i> <strong>Direct Test Failed!</strong>
                <p class="mb-0">Error: ' . $mail->ErrorInfo . '</p>
            </div>';
        }
        
    } catch (Exception $e) {
        $result['message'] = '<div class="alert alert-danger">
            <i class="fas fa-bug"></i> <strong>Exception in Direct Test!</strong>
            <p class="mb-0">' . $e->getMessage() . '</p>
        </div>';
    }
    
    return $result;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gmail SMTP Test - PRF Leave System</title>
    
    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    
    <style>
        body {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            padding-top: 20px;
            padding-bottom: 50px;
        }
        
        .card {
            border-radius: 15px;
            border: none;
            box-shadow: 0 10px 40px rgba(0,0,0,0.1);
            margin-bottom: 20px;
        }
        
        .card-header {
            border-radius: 15px 15px 0 0 !important;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
        }
        
        .btn-primary {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            border: none;
            padding: 10px 30px;
            font-weight: 600;
        }
        
        .btn-primary:hover {
            background: linear-gradient(135deg, #5a6fd8 0%, #6a4190 100%);
        }
        
        .btn-outline-primary {
            border-color: #667eea;
            color: #667eea;
        }
        
        .btn-outline-primary:hover {
            background: #667eea;
            color: white;
        }
        
        .alert {
            border-radius: 10px;
            border: none;
        }
        
        .accordion-button:not(.collapsed) {
            background-color: rgba(102, 126, 234, 0.1);
            color: #667eea;
        }
        
        .form-check-input:checked {
            background-color: #667eea;
            border-color: #667eea;
        }
        
        .debug-panel {
            background: #f8f9fa;
            border-left: 4px solid #667eea;
            padding: 15px;
            margin: 20px 0;
            border-radius: 5px;
        }
        
        .status-badge {
            display: inline-block;
            padding: 5px 10px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: bold;
        }
        
        .status-success {
            background: #d4edda;
            color: #155724;
        }
        
        .status-failed {
            background: #f8d7da;
            color: #721c24;
        }
        
        pre {
            background: #f5f5f5;
            padding: 15px;
            border-radius: 5px;
            font-size: 14px;
            overflow-x: auto;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="row justify-content-center">
            <div class="col-md-10 col-lg-8">
                <div class="card">
                    <div class="card-header">
                        <h3 class="mb-0"><i class="fas fa-envelope me-2"></i> Gmail SMTP Configuration Test</h3>
                        <p class="mb-0 opacity-75">Test email functionality for PRF Leave System</p>
                    </div>
                    
                    <div class="card-body">
                        <!-- Debug Information -->
                        <?php if (isset($_GET['debug']) || !empty($debug_info)): ?>
                            <div class="debug-panel">
                                <h5><i class="fas fa-bug me-2"></i>Debug Information</h5>
                                <?php echo $debug_info; ?>
                            </div>
                        <?php endif; ?>
                        
                        <!-- Test Result -->
                        <?php echo $test_result; ?>
                        
                        <!-- Test Form -->
                        <div class="mb-4">
                            <p>Use this page to test your Gmail SMTP configuration. Enter an email address to receive a test message.</p>
                            
                            <form method="post" class="mb-4">
                                <div class="row g-3">
                                    <div class="col-md-8">
                                        <label for="test_email" class="form-label">Test Email Address:</label>
                                        <div class="input-group">
                                            <span class="input-group-text"><i class="fas fa-at"></i></span>
                                            <input type="email" class="form-control" id="test_email" name="test_email" 
                                                   value="ashraf.nrl@gmail.com" placeholder="Enter email to test" required>
                                        </div>
                                        <div class="form-text">Enter the email address where you want to receive the test message.</div>
                                    </div>
                                    
                                    <div class="col-md-4 d-flex align-items-end">
                                        <button type="submit" class="btn btn-primary w-100">
                                            <i class="fas fa-paper-plane me-2"></i> Send Test Email
                                        </button>
                                    </div>
                                    
                                    <div class="col-12">
                                        <div class="form-check">
                                            <input class="form-check-input" type="checkbox" id="direct_method" name="direct_method" value="1" checked>
                                            <label class="form-check-label" for="direct_method">
                                                <strong>Use Direct Test Method</strong> (recommended for first test)
                                            </label>
                                            <div class="form-text">Tests PHPMailer directly without wrapper functions.</div>
                                        </div>
                                    </div>
                                </div>
                            </form>
                        </div>
                        
                        <!-- Configuration Details -->
                        <?php echo $config_details; ?>
                        
                        <!-- Configuration File Check -->
                        <div class="mt-4">
                            <h5><i class="fas fa-file-code me-2"></i>Configuration File Check</h5>
                            <div class="alert alert-info">
                                <p><strong>Important:</strong> Make sure your <code>smtp_config.php</code> has the correct App Password:</p>
                                <pre>// Gmail SMTP Configuration
define('SMTP_HOST', 'smtp.gmail.com');
define('SMTP_PORT', 587);
define('SMTP_SECURE', 'tls');
define('SMTP_USERNAME', 'ashraf.nrl@gmail.com');
define('SMTP_PASSWORD', 'your-16-character-app-password'); // ← PUT YOUR APP PASSWORD HERE
define('SMTP_FROM_EMAIL', 'ashraf.nrl@gmail.com');
define('SMTP_FROM_NAME', 'PRF Leave System');</pre>
                                <p class="mb-0"><strong>File Location:</strong> <code>C:\xampp\htdocs\PRF_Leave\include\smtp_config.php</code></p>
                            </div>
                        </div>
                        
                        <!-- Quick Troubleshooting -->
                        <div class="mt-4">
                            <h5><i class="fas fa-wrench me-2"></i>Quick Troubleshooting</h5>
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="card border-warning">
                                        <div class="card-body">
                                            <h6><i class="fas fa-key me-2"></i>Authentication Issues</h6>
                                            <ul class="mb-0">
                                                <li>Enable 2-Step Verification in Google</li>
                                                <li>Generate 16-character App Password</li>
                                                <li>Use App Password, not regular password</li>
                                            </ul>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="card border-danger">
                                        <div class="card-body">
                                            <h6><i class="fas fa-plug me-2"></i>Connection Issues</h6>
                                            <ul class="mb-0">
                                                <li>Check Windows Firewall allows port 587</li>
                                                <li>Try disabling antivirus temporarily</li>
                                                <li>Check XAMPP error logs</li>
                                            </ul>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Action Buttons -->
                        <div class="mt-4 d-flex justify-content-between flex-wrap gap-2">
                            <a href="../leave_apply.php" class="btn btn-outline-primary">
                                <i class="fas fa-arrow-left me-2"></i> Back to Leave Application
                            </a>
                            
                            <a href="test_gmail.php?debug=1" class="btn btn-outline-secondary">
                                <i class="fas fa-bug me-2"></i> Show Debug Info
                            </a>
                            
                            <button onclick="location.reload()" class="btn btn-outline-info">
                                <i class="fas fa-redo me-2"></i> Refresh Page
                            </button>
                        </div>
                        
                        <!-- System Info Footer -->
                        <div class="mt-4 pt-3 border-top text-center text-muted small">
                            <p class="mb-1"><strong>System Information</strong></p>
                            <p class="mb-1">PHP Version: <?php echo PHP_VERSION; ?> | Server: <?php echo $_SERVER['SERVER_SOFTWARE'] ?? 'XAMPP'; ?></p>
                            <p class="mb-0">PHPMailer Loaded: <?php echo class_exists('PHPMailer\PHPMailer\PHPMailer') ? 'Yes ✓' : 'No ✗'; ?></p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    
    <script>
        // Auto-dismiss alerts after 10 seconds
        document.addEventListener('DOMContentLoaded', function() {
            const alerts = document.querySelectorAll('.alert-dismissible');
            alerts.forEach(alert => {
                setTimeout(() => {
                    if (alert.parentNode) {
                        const bsAlert = bootstrap.Alert.getOrCreateInstance(alert);
                        bsAlert.close();
                    }
                }, 10000);
            });
        });
    </script>
</body>
</html>