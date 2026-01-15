<?php
// utils/send_email.php or include/email_functions.php

/**
 * Send email notification
 * @param string $to Recipient email
 * @param string $subject Email subject
 * @param string $body Email body (HTML supported)
 * @param string $from Sender email (optional)
 * @return bool Success status
 */
function sendEmailNotification($to, $subject, $body, $from = null) {
    try {
        // If no from address provided, use system default
        if ($from === null) {
            $from = 'noreply@yourcompany.com'; // Change to your company email
        }
        
        // Headers
        $headers = "MIME-Version: 1.0" . "\r\n";
        $headers .= "Content-type:text/html;charset=UTF-8" . "\r\n";
        $headers .= "From: PRF Leave System <$from>" . "\r\n";
        $headers .= "Reply-To: noreply@yourcompany.com" . "\r\n";
        $headers .= "X-Mailer: PHP/" . phpversion();
        
        // Send email
        $result = mail($to, $subject, $body, $headers);
        
        // Log email sending
        error_log("Email sent to: $to | Subject: $subject | Result: " . ($result ? "Success" : "Failed"));
        
        return $result;
        
    } catch (Exception $e) {
        error_log("Email sending error: " . $e->getMessage());
        return false;
    }
}

/**
 * Get supervisor emails for an employee
 * @param object $conn Database connection
 * @param int $employeeId Employee ID
 * @return array Array of supervisor emails
 */
function getSupervisorEmails($conn, $employeeId) {
    $supervisorEmails = [];
    
    try {
        // Get employee details to find supervisors
        $sql = "
            SELECT 
                e.EmployeeID,
                e.FirstName,
                e.LastName,
                e.Email,
                e.SupervisorID_admin,
                e.SupervisorID_technical,
                e.SupervisorID_2ndLevel,
                s1.Email as AdminSupervisorEmail,
                s2.Email as TechnicalSupervisorEmail,
                s3.Email as SecondLevelSupervisorEmail
            FROM [dbPRFAssetMgt].[dbo].[Employees] e
            LEFT JOIN [dbPRFAssetMgt].[dbo].[Employees] s1 ON e.SupervisorID_admin = s1.EmployeeID
            LEFT JOIN [dbPRFAssetMgt].[dbo].[Employees] s2 ON e.SupervisorID_technical = s2.EmployeeID
            LEFT JOIN [dbPRFAssetMgt].[dbo].[Employees] s3 ON e.SupervisorID_2ndLevel = s3.EmployeeID
            WHERE e.EmployeeID = ?
        ";
        
        $stmt = $conn->prepare($sql);
        $stmt->execute([$employeeId]);
        $employee = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($employee) {
            // Add non-empty supervisor emails to array
            if (!empty($employee['AdminSupervisorEmail'])) {
                $supervisorEmails[] = $employee['AdminSupervisorEmail'];
            }
            if (!empty($employee['TechnicalSupervisorEmail'])) {
                $supervisorEmails[] = $employee['TechnicalSupervisorEmail'];
            }
            if (!empty($employee['SecondLevelSupervisorEmail'])) {
                $supervisorEmails[] = $employee['SecondLevelSupervisorEmail'];
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
 * Generate leave application email HTML
 * @param array $leaveData Leave application details
 * @param array $employeeData Employee details
 * @param string $type Type of email ('application' or 'status_update')
 * @return string HTML email content
 */
function generateLeaveEmailHTML($leaveData, $employeeData, $type = 'application') {
    $leaveId = $leaveData['LeaveApplicationID'] ?? 'N/A';
    $employeeName = $employeeData['FirstName'] . ' ' . $employeeData['LastName'];
    $employeeCode = $employeeData['EmployeeCode'] ?? '';
    $leaveType = $leaveData['LeaveTypeName'] ?? 'N/A';
    $startDate = date('d M Y', strtotime($leaveData['StartDate']));
    $endDate = date('d M Y', strtotime($leaveData['EndDate']));
    $totalDays = $leaveData['TotalDays'] ?? 0;
    $reason = nl2br(htmlspecialchars($leaveData['Reason'] ?? 'No reason provided'));
    $appliedDate = date('d M Y, h:i A', strtotime($leaveData['AppliedDate'] ?? 'now'));
    
    // Application URL (modify according to your system)
    $applicationUrl = BASE_URL . "/pages/leave/leave_approval_supervisor.php?leave_id=" . $leaveId;
    
    if ($type === 'application') {
        $subject = "New Leave Application - " . $employeeName . " (" . $employeeCode . ")";
        
        $html = '
        <!DOCTYPE html>
        <html>
        <head>
            <style>
                body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
                .container { max-width: 600px; margin: 0 auto; padding: 20px; }
                .header { background: #007bff; color: white; padding: 15px; border-radius: 5px 5px 0 0; }
                .content { background: #f8f9fa; padding: 20px; border: 1px solid #dee2e6; }
                .footer { background: #e9ecef; padding: 15px; text-align: center; font-size: 12px; color: #6c757d; }
                .btn { display: inline-block; background: #28a745; color: white; padding: 10px 20px; text-decoration: none; border-radius: 5px; margin: 10px 0; }
                .details-table { width: 100%; border-collapse: collapse; margin: 15px 0; }
                .details-table th, .details-table td { padding: 8px; border: 1px solid #dee2e6; text-align: left; }
                .details-table th { background: #e9ecef; }
                .status-badge { display: inline-block; padding: 3px 8px; border-radius: 12px; font-size: 12px; font-weight: bold; }
                .status-pending { background: #ffc107; color: #000; }
            </style>
        </head>
        <body>
            <div class="container">
                <div class="header">
                    <h2>New Leave Application</h2>
                </div>
                
                <div class="content">
                    <p>Dear Supervisor,</p>
                    
                    <p>A new leave application has been submitted by <strong>' . $employeeName . ' (' . $employeeCode . ')</strong> 
                    that requires your attention.</p>
                    
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
                        <tr>
                            <th>Status</th>
                            <td><span class="status-badge status-pending">Pending Approval</span></td>
                        </tr>
                        <tr>
                            <th>Reason</th>
                            <td>' . $reason . '</td>
                        </tr>
                    </table>
                    
                    <p>To review and take action on this leave application, please click the button below:</p>
                    
                    <p>
                        <a href="' . $applicationUrl . '" class="btn">Review Leave Application</a>
                    </p>
                    
                    <p>Alternatively, you can copy and paste this link in your browser:<br>
                    <small>' . $applicationUrl . '</small></p>
                    
                    <p>Thank you,<br>
                    PRF Leave Management System</p>
                </div>
                
                <div class="footer">
                    <p>This is an automated notification. Please do not reply to this email.</p>
                    <p>© ' . date('Y') . ' PRF Leave Management System. All rights reserved.</p>
                </div>
            </div>
        </body>
        </html>';
        
        return ['subject' => $subject, 'body' => $html];
    }
    
    // For status update emails (optional - you can add this later)
    return ['subject' => '', 'body' => ''];
}
?>