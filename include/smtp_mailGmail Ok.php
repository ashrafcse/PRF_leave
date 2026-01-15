<?php
/***********************
 * SMTP Mail Function for PRF Leave System
 * PHP 5.6 Compatible Version
 ***********************/

// Load config once
if (!defined('SMTP_HOST')) {
    require_once dirname(__FILE__) . '/smtp_config.php';
}

// Load PHPMailer v5.2 for PHP 5.6
$phpmailerPath = dirname(__FILE__) . '/PHPMailer/';
$phpmailerAvailable = false;

if (file_exists($phpmailerPath . 'class.phpmailer.php')) {
    require_once $phpmailerPath . 'class.phpmailer.php';
    require_once $phpmailerPath . 'class.smtp.php';
    $phpmailerAvailable = true;
    error_log("PHPMailer v5.2 loaded successfully");
} else {
    error_log("PHPMailer not found in: $phpmailerPath");
    $phpmailerAvailable = false;
}

/**
 * Main email sending function
 */
function sendSMTPMail($to, $subject, $body, $altBody = '') {
    global $phpmailerAvailable;
    
    // Validate email
    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
        error_log("Invalid email address: $to");
        return false;
    }
    
    error_log("Sending email to: $to | Subject: $subject");
    
    if ($phpmailerAvailable) {
        return sendWithPHPMailerV5($to, $subject, $body, $altBody);
    } else {
        return sendWithMailFunction($to, $subject, $body);
    }
}

/**
 * Send email using PHPMailer v5.2
 */
function sendWithPHPMailerV5($to, $subject, $body, $altBody = '') {
    try {
        // Create instance (PHPMailer v5 doesn't use namespaces)
        $mail = new PHPMailer(true);
        
        // Server settings for Gmail
        $mail->SMTPDebug = defined('SMTP_DEBUG') ? SMTP_DEBUG : 0;
        $mail->isSMTP();
        $mail->Host       = SMTP_HOST;
        $mail->Port       = SMTP_PORT;
        $mail->SMTPSecure = SMTP_SECURE; // 'tls' or 'ssl'
        $mail->SMTPAuth   = true;
        $mail->Username   = SMTP_USERNAME;
        $mail->Password   = SMTP_PASSWORD;
        
        // Important: For XAMPP/Gmail on Windows with PHP 5.6
        // In PHPMailer v5, the property name is different
        if (property_exists($mail, 'SMTPOptions')) {
            $mail->SMTPOptions = array(
                'ssl' => array(
                    'verify_peer' => false,
                    'verify_peer_name' => false,
                    'allow_self_signed' => true
                )
            );
        }
        
        // For older PHPMailer v5 versions
        $mail->SMTPAutoTLS = false;
        
        if (defined('SMTP_CHARSET')) {
            $mail->CharSet = SMTP_CHARSET;
        } else {
            $mail->CharSet = 'UTF-8';
        }
        
        $mail->Timeout = 30;
        
        // Sender
        $mail->setFrom(SMTP_FROM_EMAIL, SMTP_FROM_NAME);
        $mail->addAddress($to);
        $mail->addReplyTo(SMTP_FROM_EMAIL, SMTP_FROM_NAME);
        
        // Content
        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body    = $body;
        
        // Plain text alternative
        if (!empty($altBody)) {
            $mail->AltBody = $altBody;
        } else {
            $mail->AltBody = strip_tags(str_replace(array('<br>', '<br/>', '<br />'), "\n", $body));
        }
        
        // Send email
        if ($mail->send()) {
            error_log("Email successfully sent to: $to");
            return true;
        } else {
            error_log("Failed to send to $to: " . $mail->ErrorInfo);
            return false;
        }
        
    } catch (Exception $e) {
        error_log("Exception sending to $to: " . $e->getMessage());
        return false;
    }
}

/**
 * Fallback using PHP mail() function
 */
function sendWithMailFunction($to, $subject, $body) {
    $headers = "MIME-Version: 1.0\r\n";
    $headers .= "Content-Type: text/html; charset=UTF-8\r\n";
    $headers .= "From: " . SMTP_FROM_NAME . " <" . SMTP_FROM_EMAIL . ">\r\n";
    $headers .= "Reply-To: " . SMTP_FROM_EMAIL . "\r\n";
    $headers .= "X-Mailer: PHP/" . phpversion();
    
    $result = @mail($to, $subject, $body, $headers);
    
    if ($result) {
        error_log("mail() function: Email sent to $to");
        return true;
    } else {
        error_log("mail() function failed for $to");
        return false;
    }
}

// ============================================
// KEEP ALL YOUR EXISTING FUNCTIONS BELOW
// (They should work with PHP 5.6)
// ============================================

/**
 * Send leave notification to supervisors with Gmail
 */
function sendLeaveNotificationToSupervisors($conn, $leaveApplicationID) {
    $results = array(
        'success_count' => 0,
        'total_supervisors' => 0,
        'errors' => array(),
        'sent_to' => array()
    );
    
    try {
        // Get leave application details
        $sqlLeave = "
            SELECT la.*, lt.LeaveTypeName, 
                   e.FirstName, e.LastName, e.EmployeeCode, 
                   e.Email_Office as EmployeeEmail,
                   e.SupervisorID_admin, e.SupervisorID_technical, e.SupervisorID_2ndLevel
            FROM dbo.LeaveApplications la
            LEFT JOIN dbo.LeaveTypes lt ON la.LeaveTypeID = lt.LeaveTypeID
            LEFT JOIN dbo.Employees e ON la.EmployeeID = e.EmployeeID
            WHERE la.LeaveApplicationID = ?
        ";
        
        $stmtLeave = $conn->prepare($sqlLeave);
        $stmtLeave->execute(array($leaveApplicationID));
        $leaveData = $stmtLeave->fetch(PDO::FETCH_ASSOC);
        
        if (!$leaveData) {
            $results['errors'][] = "Leave application #$leaveApplicationID not found";
            return $results;
        }
        
        error_log("Processing leave application #$leaveApplicationID for employee: " . 
                 $leaveData['FirstName'] . " " . $leaveData['LastName']);
        
        // Get supervisor emails
        $supervisorEmails = getSupervisorEmailsForGmail($conn, $leaveData['EmployeeID']);
        $results['total_supervisors'] = count($supervisorEmails);
        
        if (empty($supervisorEmails)) {
            $results['errors'][] = "No supervisor emails found for employee ID: " . $leaveData['EmployeeID'];
            return $results;
        }
        
        error_log("Found " . count($supervisorEmails) . " supervisor(s) to notify");
        
        // Generate email content
        $emailContent = generateGmailLeaveEmailHTML($leaveData, array(
            'FirstName' => $leaveData['FirstName'],
            'LastName' => $leaveData['LastName'],
            'EmployeeCode' => $leaveData['EmployeeCode'],
            'Email' => $leaveData['EmployeeEmail']
        ));
        
        // Send to each supervisor
        foreach ($supervisorEmails as $index => $supervisorEmail) {
            if (filter_var($supervisorEmail, FILTER_VALIDATE_EMAIL)) {
                error_log("Sending email to supervisor #" . ($index+1) . ": $supervisorEmail");
                
                $sent = sendSMTPMail(
                    $supervisorEmail,
                    $emailContent['subject'],
                    $emailContent['body'],
                    $emailContent['altBody']
                );
                
                if ($sent) {
                    $results['success_count']++;
                    $results['sent_to'][] = $supervisorEmail;
                    error_log("Email sent successfully to: $supervisorEmail");
                } else {
                    $results['errors'][] = "Failed to send to: $supervisorEmail";
                    error_log("Failed to send email to: $supervisorEmail");
                }
            } else {
                $results['errors'][] = "Invalid email format: $supervisorEmail";
                error_log("Invalid email address: $supervisorEmail");
            }
        }
        
        // Also send confirmation to employee
        if (!empty($leaveData['EmployeeEmail']) && filter_var($leaveData['EmployeeEmail'], FILTER_VALIDATE_EMAIL)) {
            $employeeEmailContent = generateEmployeeConfirmationEmail($leaveData);
            sendSMTPMail(
                $leaveData['EmployeeEmail'],
                $employeeEmailContent['subject'],
                $employeeEmailContent['body'],
                $employeeEmailContent['altBody']
            );
            error_log("Confirmation email sent to employee: " . $leaveData['EmployeeEmail']);
        }
        
    } catch (Exception $e) {
        $results['errors'][] = "System error: " . $e->getMessage();
        error_log("Exception in sendLeaveNotificationToSupervisors: " . $e->getMessage());
    }
    
    return $results;
}

/**
 * Get supervisor emails
 */
function getSupervisorEmailsForGmail($conn, $employeeId) {
    $supervisorEmails = array();
    
    try {
        $sql = "
            SELECT 
                e.EmployeeID,
                e.FirstName,
                e.LastName,
                e.Email_Office,
                e.SupervisorID_admin,
                e.SupervisorID_technical,
                e.SupervisorID_2ndLevel,
                s1.Email_Office as AdminSupervisorEmail,
                s2.Email_Office as TechnicalSupervisorEmail,
                s3.Email_Office as SecondLevelSupervisorEmail
            FROM [dbPRFAssetMgt].[dbo].[Employees] e
            LEFT JOIN [dbPRFAssetMgt].[dbo].[Employees] s1 ON e.SupervisorID_admin = s1.EmployeeID
            LEFT JOIN [dbPRFAssetMgt].[dbo].[Employees] s2 ON e.SupervisorID_technical = s2.EmployeeID
            LEFT JOIN [dbPRFAssetMgt].[dbo].[Employees] s3 ON e.SupervisorID_2ndLevel = s3.EmployeeID
            WHERE e.EmployeeID = ?
        ";
        
        $stmt = $conn->prepare($sql);
        $stmt->execute(array($employeeId));
        $employee = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($employee) {
            // Add non-empty supervisor emails
            $emailsToCheck = array(
                'Admin' => $employee['AdminSupervisorEmail'],
                'Technical' => $employee['TechnicalSupervisorEmail'],
                'Second Level' => $employee['SecondLevelSupervisorEmail']
            );
            
            foreach ($emailsToCheck as $type => $email) {
                if (!empty($email) && filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    $supervisorEmails[] = $email;
                    error_log("Found $type supervisor email: $email");
                }
            }
            
            // Remove duplicates
            $supervisorEmails = array_unique($supervisorEmails);
        }
        
    } catch (Exception $e) {
        error_log("Error getting supervisor emails: " . $e->getMessage());
    }
    
    return $supervisorEmails;
}

/**
 * Generate email HTML (PHP 5.6 compatible)
 */
function generateGmailLeaveEmailHTML($leaveData, $employeeData) {
    $leaveId = isset($leaveData['LeaveApplicationID']) ? $leaveData['LeaveApplicationID'] : 'N/A';
    $employeeName = (isset($employeeData['FirstName']) ? $employeeData['FirstName'] : '') . ' ' . 
                   (isset($employeeData['LastName']) ? $employeeData['LastName'] : '');
    $employeeCode = isset($employeeData['EmployeeCode']) ? $employeeData['EmployeeCode'] : '';
    $leaveType = isset($leaveData['LeaveTypeName']) ? $leaveData['LeaveTypeName'] : 'N/A';
    
    // Format dates
    $startDate = isset($leaveData['StartDate']) ? date('d M Y', strtotime($leaveData['StartDate'])) : 'N/A';
    $endDate = isset($leaveData['EndDate']) ? date('d M Y', strtotime($leaveData['EndDate'])) : 'N/A';
    $totalDays = isset($leaveData['TotalDays']) ? $leaveData['TotalDays'] : 0;
    $reason = isset($leaveData['Reason']) ? nl2br(htmlspecialchars($leaveData['Reason'])) : 'No reason provided';
    $appliedDate = date('d M Y, h:i A', strtotime(isset($leaveData['AppliedDate']) ? $leaveData['AppliedDate'] : 'now'));
    
    // Application URL - use your server IP
    $baseUrl = "http://27.147.225.171:8080/PRF_Leave";
    $applicationUrl = $baseUrl . "/pages/leave/leave_approval_supervisor.php?leave_id=" . $leaveId;
    
    $subject = "New Leave Application - " . $employeeName . " (" . $employeeCode . ")";
    
    // Plain text version
    $altBody = "NEW LEAVE APPLICATION\n";
    $altBody .= "====================\n\n";
    $altBody .= "Application ID: #" . $leaveId . "\n";
    $altBody .= "Employee: " . $employeeName . " (" . $employeeCode . ")\n";
    $altBody .= "Leave Type: " . $leaveType . "\n";
    $altBody .= "Leave Period: " . $startDate . " to " . $endDate . " (" . $totalDays . " day(s))\n";
    $altBody .= "Applied On: " . $appliedDate . "\n";
    $altBody .= "Status: Pending Approval\n";
    $altBody .= "Reason: " . strip_tags($reason) . "\n\n";
    $altBody .= "To review and take action on this leave application, please visit:\n";
    $altBody .= $applicationUrl . "\n\n";
    $altBody .= "This is an automated notification from PRF Leave Management System.";
    
    // HTML version
    $html = '<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>' . htmlspecialchars($subject) . '</title>
    <style>
        body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
        .container { max-width: 600px; margin: 0 auto; padding: 20px; }
        .header { background: #2563eb; color: white; padding: 15px; border-radius: 5px 5px 0 0; }
        .content { background: #f8f9fa; padding: 20px; border: 1px solid #dee2e6; }
        .footer { background: #e9ecef; padding: 10px; text-align: center; font-size: 12px; color: #6c757d; }
        .btn { background: #28a745; color: white; padding: 10px 15px; text-decoration: none; border-radius: 5px; display: inline-block; }
        .details-table { width: 100%; border-collapse: collapse; margin: 15px 0; }
        .details-table th, .details-table td { padding: 8px; border: 1px solid #dee2e6; text-align: left; }
        .details-table th { background: #e9ecef; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h2 style="margin: 0;">New Leave Application</h2>
        </div>
        
        <div class="content">
            <p>Dear Supervisor,</p>
            
            <p>A new leave application has been submitted by <strong>' . $employeeName . ' (' . $employeeCode . ')</strong></p>
            
            <table class="details-table">
                <tr>
                    <th>Application ID</th>
                    <td>#' . $leaveId . '</td>
                </tr>
                <tr>
                    <th>Employee</th>
                    <td>' . $employeeName . ' (' . $employeeCode . ')</td>
                </tr>
                <tr>
                    <th>Leave Type</th>
                    <td>' . $leaveType . '</td>
                </tr>
                <tr>
                    <th>Leave Period</th>
                    <td>' . $startDate . ' to ' . $endDate . ' (' . $totalDays . ' day(s))</td>
                </tr>
                <tr>
                    <th>Applied On</th>
                    <td>' . $appliedDate . '</td>
                </tr>
            </table>
            
            <p><strong>Reason:</strong><br>' . $reason . '</p>
            
            <p>To review this leave application, please click the link below:</p>
            
            <p>
                <a href="' . $applicationUrl . '" class="btn">Review Leave Application</a>
            </p>
            
            <p>If the button doesn\'t work, copy and paste this link:<br>
            <small>' . $applicationUrl . '</small></p>
            
            <p>Thank you,<br>
            PRF Leave Management System</p>
        </div>
        
        <div class="footer">
            <p>This is an automated notification. Please do not reply to this email.</p>
        </div>
    </div>
</body>
</html>';
    
    return array('subject' => $subject, 'body' => $html, 'altBody' => $altBody);
}

/**
 * Generate employee confirmation email
 */
function generateEmployeeConfirmationEmail($leaveData) {
    $employeeName = (isset($leaveData['FirstName']) ? $leaveData['FirstName'] : '') . ' ' . 
                   (isset($leaveData['LastName']) ? $leaveData['LastName'] : '');
    $leaveId = isset($leaveData['LeaveApplicationID']) ? $leaveData['LeaveApplicationID'] : 'N/A';
    $leaveType = isset($leaveData['LeaveTypeName']) ? $leaveData['LeaveTypeName'] : 'N/A';
    $startDate = isset($leaveData['StartDate']) ? date('d M Y', strtotime($leaveData['StartDate'])) : 'N/A';
    $endDate = isset($leaveData['EndDate']) ? date('d M Y', strtotime($leaveData['EndDate'])) : 'N/A';
    $totalDays = isset($leaveData['TotalDays']) ? $leaveData['TotalDays'] : 0;
    
    $subject = "Leave Application Submitted - #" . $leaveId;
    
    $altBody = "LEAVE APPLICATION CONFIRMATION\n";
    $altBody .= "=============================\n\n";
    $altBody .= "Dear " . $employeeName . ",\n\n";
    $altBody .= "Your leave application has been successfully submitted.\n\n";
    $altBody .= "Application ID: #" . $leaveId . "\n";
    $altBody .= "Leave Type: " . $leaveType . "\n";
    $altBody .= "Leave Period: " . $startDate . " to " . $endDate . "\n";
    $altBody .= "Total Days: " . $totalDays . "\n";
    $altBody .= "Status: Pending Supervisor Approval\n\n";
    $altBody .= "Your supervisor(s) have been notified.\n\n";
    $altBody .= "Thank you,\n";
    $altBody .= "PRF Leave Management System";
    
    $html = '<!DOCTYPE html>
<html>
<head>
    <style>
        body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
        .container { max-width: 600px; margin: 0 auto; padding: 20px; }
        .header { background: #10b981; color: white; padding: 15px; }
        .content { background: #f0fdf4; padding: 20px; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h2>Leave Application Submitted</h2>
        </div>
        <div class="content">
            <p>Dear <strong>' . $employeeName . '</strong>,</p>
            <p>Your leave application has been successfully submitted.</p>
            <p><strong>Application ID:</strong> #' . $leaveId . '</p>
            <p><strong>Leave Type:</strong> ' . $leaveType . '</p>
            <p><strong>Leave Period:</strong> ' . $startDate . ' to ' . $endDate . '</p>
            <p><strong>Total Days:</strong> ' . $totalDays . '</p>
            <p><strong>Status:</strong> Pending Approval</p>
            <p>Your supervisor(s) have been notified.</p>
            <p>Thank you,<br>
            <strong>PRF Leave Management System</strong></p>
        </div>
    </div>
</body>
</html>';
    
    return array('subject' => $subject, 'body' => $html, 'altBody' => $altBody);
}

/**
 * Test Gmail SMTP configuration (PHP 5.6 compatible)
 */
function testGmailSMTP($testEmail = 'ashraf.nrl@gmail.com') {
    $results = array(
        'success' => false,
        'message' => '',
        'config' => array(),
        'details' => ''
    );
    
    try {
        // Store configuration
        $results['config'] = array(
            'SMTP Host' => defined('SMTP_HOST') ? SMTP_HOST : 'Not defined',
            'SMTP Port' => defined('SMTP_PORT') ? SMTP_PORT : 'Not defined',
            'SMTP Secure' => defined('SMTP_SECURE') ? SMTP_SECURE : 'Not defined',
            'SMTP Username' => defined('SMTP_USERNAME') ? SMTP_USERNAME : 'Not defined',
            'From Email' => defined('SMTP_FROM_EMAIL') ? SMTP_FROM_EMAIL : 'Not defined',
            'From Name' => defined('SMTP_FROM_NAME') ? SMTP_FROM_NAME : 'Not defined'
        );
        
        // Test connection
        $testSubject = "Gmail SMTP Test - PRF Leave System";
        $testBody = '<h3>Gmail SMTP Configuration Test</h3>
        <p>This test email was sent on: ' . date('Y-m-d H:i:s') . '</p>
        <p>If you receive this email, your Gmail SMTP configuration is working correctly!</p>
        <p><strong>Configuration Details:</strong></p>
        <ul>
            <li>SMTP Server: ' . SMTP_HOST . '</li>
            <li>Port: ' . SMTP_PORT . ' (' . SMTP_SECURE . ')</li>
            <li>From: ' . SMTP_FROM_NAME . ' &lt;' . SMTP_FROM_EMAIL . '&gt;</li>
        </ul>
        <p style="color: green; font-weight: bold;">SMTP Test Successful!</p>';
        
        $sent = sendSMTPMail($testEmail, $testSubject, $testBody);
        
        if ($sent) {
            $results['success'] = true;
            $results['message'] = "Test email sent successfully to $testEmail";
            $results['details'] = "Please check your inbox (and spam folder) for the test email.";
        } else {
            $results['message'] = "Failed to send test email. Check PHP error logs for details.";
            $results['details'] = "Common issues:
1. Incorrect Gmail App Password
2. 2-Step Verification not enabled
3. Less secure app access blocked
4. Firewall blocking port " . SMTP_PORT;
        }
        
    } catch (Exception $e) {
        $results['message'] = "Test error: " . $e->getMessage();
    }
    
    return $results;
}

error_log("=== SMTP Mail System Ready (PHP 5.6) ===");
?>