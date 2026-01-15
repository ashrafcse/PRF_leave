<?php
/***********************
 * Leave Apply (Self) - PHP 5.6
 * Auto map Users -> Employees using:
 * 1) dbo.Users.EmployeeID
 * 2) dbo.Users.Email => dbo.Employees.Email_Office OR Email_Personal
 * 3) dbo.Users.Username => dbo.Employees.EmployeeCode
 *
 * If not mapped: user can link Employee from this page (no new table)
 ***********************/
ini_set('display_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/../../init.php';
require_once __DIR__ . '/../../include/smtp_mail.php';
require_login();

if (!function_exists('h')) { function h($s){ return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); } }
function self_name(){ return strtok(basename($_SERVER['SCRIPT_NAME']), "?"); }
$self = self_name();

/* CSRF */
if (!isset($_SESSION['csrf'])) {
  $_SESSION['csrf'] = function_exists('openssl_random_pseudo_bytes')
    ? bin2hex(openssl_random_pseudo_bytes(16))
    : substr(str_shuffle(md5(uniqid(mt_rand(), true))), 0, 32);
}
$CSRF = $_SESSION['csrf'];
function check_csrf(){
  if (!isset($_POST['csrf']) || $_POST['csrf'] !== $_SESSION['csrf']) die('Invalid CSRF token');
}

/* Helpers */
function normalize_text($s){
  $s = trim((string)$s);
  $s = trim(preg_replace('/\s+/', ' ', $s));
  return $s;
}
function parse_decimal_1($s){
  $s = trim((string)$s);
  $s = str_replace(',', '', $s);
  if ($s === '') return null;
  if (!preg_match('/^\d+(\.\d)?$/', $s)) return null;
  return $s;
}
function parse_date_any($s){
  $s = trim((string)$s);
  if ($s === '') return null;
  // HTML date input => YYYY-MM-DD
  if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $s)) return $s;

  // MM/DD/YYYY
  if (preg_match('/^(\d{1,2})\/(\d{1,2})\/(\d{4})$/', $s, $m)) {
    $mm=(int)$m[1]; $dd=(int)$m[2]; $yy=(int)$m[3];
    if ($mm>=1 && $mm<=12 && $dd>=1 && $dd<=31) return sprintf('%04d-%02d-%02d', $yy,$mm,$dd);
  }
  return null;
}

function employee_display_expr(){
  return "LTRIM(RTRIM(COALESCE(e.FirstName,'') + ' ' + COALESCE(e.LastName,'')))";
}

/* Email Notification Functions */
function sendEmailNotification($to, $subject, $body, $from = null) {
    try {
        // If no from address provided, use system default
        if ($from === null) {
            $from = 'noreply@prfbd.org'; // Change to your company email
        }
        
        // Headers
        $headers = "MIME-Version: 1.0" . "\r\n";
        $headers .= "Content-type:text/html;charset=UTF-8" . "\r\n";
        $headers .= "From: PRF Leave System <$from>" . "\r\n";
        $headers .= "Reply-To: noreply@prfbd.org" . "\r\n";
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

/* DEBUGGING: Test email configuration */
function testEmailConfiguration($conn, $employeeId) {
    // Get your own email from database
    $sql = "SELECT Email_Office FROM dbo.Employees WHERE EmployeeID = ?";
    $stmt = $conn->prepare($sql);
    $stmt->execute([$employeeId]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($result && !empty($result['Email_Office'])) {
        $testEmail = $result['Email_Office'];
        $testSubject = "Test Email from Leave System";
        $testBody = "This is a test email to verify mail configuration on " . date('Y-m-d H:i:s');
        $testFrom = "noreply@prfbd.org";
        
        // Test headers
        $headers = "MIME-Version: 1.0" . "\r\n";
        $headers .= "Content-type:text/plain;charset=UTF-8" . "\r\n";
        $headers .= "From: PRF Leave System <$testFrom>" . "\r\n";
        $headers .= "X-Mailer: PHP/" . phpversion();
        
        $sent = mail($testEmail, $testSubject, $testBody, $headers);
        error_log("DEBUG: Test email sent to " . $testEmail . ": " . ($sent ? "SUCCESS" : "FAILED"));
        return $sent;
    } else {
        error_log("DEBUG: No Email_Office found for employee ID: " . $employeeId);
        return false;
    }
}

function getSupervisorEmails($conn, $employeeId) {
    $supervisorEmails = [];
    
    try {
        // Get employee details to find supervisors - USING ONLY Email_Office
        $sql = "
            SELECT 
                e.EmployeeID,
                e.FirstName,
                e.LastName,
                e.Email_Office as EmployeeEmail,
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
        $stmt->execute([$employeeId]);
        $employee = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($employee) {
            // DEBUGGING: Log what we found
            error_log("DEBUG: Employee found - ID: " . $employeeId . ", Name: " . $employee['FirstName'] . " " . $employee['LastName']);
            error_log("DEBUG: Admin Supervisor Email: " . ($employee['AdminSupervisorEmail'] ?: 'EMPTY'));
            error_log("DEBUG: Technical Supervisor Email: " . ($employee['TechnicalSupervisorEmail'] ?: 'EMPTY'));
            error_log("DEBUG: 2nd Level Supervisor Email: " . ($employee['SecondLevelSupervisorEmail'] ?: 'EMPTY'));
            
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
            
            // DEBUGGING: Log final supervisor emails
            error_log("DEBUG: Final supervisor emails array: " . print_r($supervisorEmails, true));
            error_log("DEBUG: Number of supervisor emails found: " . count($supervisorEmails));
        } else {
            error_log("DEBUG: No employee found with ID: " . $employeeId);
        }
        
    } catch (Exception $e) {
        error_log("Error getting supervisor emails: " . $e->getMessage());
    }
    
    return $supervisorEmails;
}

function generateLeaveEmailHTML($leaveData, $employeeData) {
    // Using ternary operators instead of null coalescing for PHP 5.6 compatibility
    $leaveId = isset($leaveData['LeaveApplicationID']) ? $leaveData['LeaveApplicationID'] : 'N/A';
    $employeeName = (isset($employeeData['FirstName']) ? $employeeData['FirstName'] : '') . ' ' . 
                   (isset($employeeData['LastName']) ? $employeeData['LastName'] : '');
    $employeeCode = isset($employeeData['EmployeeCode']) ? $employeeData['EmployeeCode'] : '';
    $leaveType = isset($leaveData['LeaveTypeName']) ? $leaveData['LeaveTypeName'] : 'N/A';
    $startDate = isset($leaveData['StartDate']) ? date('d M Y', strtotime($leaveData['StartDate'])) : 'N/A';
    $endDate = isset($leaveData['EndDate']) ? date('d M Y', strtotime($leaveData['EndDate'])) : 'N/A';
    $totalDays = isset($leaveData['TotalDays']) ? $leaveData['TotalDays'] : 0;
    $reason = nl2br(htmlspecialchars(isset($leaveData['Reason']) ? $leaveData['Reason'] : 'No reason provided'));
    $appliedDate = date('d M Y, h:i A', strtotime(isset($leaveData['AppliedDate']) ? $leaveData['AppliedDate'] : 'now'));
    
    // Application URL (modify according to your system)
    $baseUrl = isset($_SERVER['HTTPS']) ? 'https://' : 'http://';
    $baseUrl .= $_SERVER['HTTP_HOST'];
    $applicationUrl = $baseUrl . "/PRF_Leave/pages/leave/leave_approval_supervisor.php?leave_id=" . $leaveId;
    
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

/* Resolve EmployeeID for current user */
function resolve_employee_id(PDO $conn, $userId, $autoSet){
  if (!$userId) return array(null, 'UserID missing.');

  // dbo.Users has Email nullable, EmployeeID nullable
  $st = $conn->prepare("SELECT UserID, Username, Email, EmployeeID FROM dbo.Users WHERE UserID=:uid");
  $st->execute(array(':uid'=>(int)$userId));
  $u = $st->fetch(PDO::FETCH_ASSOC);
  if (!$u) return array(null, 'User not found.');

  if (!empty($u['EmployeeID']) && (int)$u['EmployeeID'] > 0) {
    return array((int)$u['EmployeeID'], null);
  }

  $email = isset($u['Email']) ? trim((string)$u['Email']) : '';
  $uname = isset($u['Username']) ? trim((string)$u['Username']) : '';

  // Email match: Employees.Email_Office OR Email_Personal
  // IMPORTANT FIX: do NOT reuse same named parameter twice in SQL Server ODBC
  if ($email !== '') {
    $st = $conn->prepare("
      SELECT EmployeeID
        FROM dbo.Employees
       WHERE LOWER(Email_Office) = LOWER(:em1)
          OR LOWER(Email_Personal) = LOWER(:em2)
    ");
    $st->execute(array(':em1'=>$email, ':em2'=>$email));
    $ids = $st->fetchAll(PDO::FETCH_COLUMN);

    if (count($ids) === 1) {
      $eid = (int)$ids[0];
      if ($autoSet) {
        $up = $conn->prepare("UPDATE dbo.Users SET EmployeeID=:eid WHERE UserID=:uid AND (EmployeeID IS NULL OR EmployeeID=0)");
        $up->execute(array(':eid'=>$eid, ':uid'=>(int)$userId));
      }
      return array($eid, null);
    }
  }

  // Username match: Employees.EmployeeCode
  if ($uname !== '') {
    $st = $conn->prepare("SELECT EmployeeID FROM dbo.Employees WHERE EmployeeCode = :c");
    $st->execute(array(':c'=>$uname));
    $ids = $st->fetchAll(PDO::FETCH_COLUMN);

    if (count($ids) === 1) {
      $eid = (int)$ids[0];
      if ($autoSet) {
        $up = $conn->prepare("UPDATE dbo.Users SET EmployeeID=:eid WHERE UserID=:uid AND (EmployeeID IS NULL OR EmployeeID=0)");
        $up->execute(array(':eid'=>$eid, ':uid'=>(int)$userId));
      }
      return array($eid, null);
    }
  }

  return array(null, "আপনার account এ EmployeeID map করা নেই (dbo.Users.EmployeeID NULL)।");
}

/* Status map */
$STATUS = array(
  0 => array('text'=>'Pending',     'class'=>'status-pill-warning'),
  1 => array('text'=>'L1 Approved', 'class'=>'status-pill-active'),
  2 => array('text'=>'L2 Approved', 'class'=>'status-pill-active'),
  3 => array('text'=>'Rejected',    'class'=>'status-pill-inactive'),
  4 => array('text'=>'Cancelled',   'class'=>'status-pill-muted')
);
function status_text($STATUS, $s){ return isset($STATUS[$s]) ? $STATUS[$s]['text'] : ('Status '.$s); }
function status_class($STATUS, $s){ return isset($STATUS[$s]) ? $STATUS[$s]['class'] : 'status-pill-muted'; }

/* Config */
$AUTO_SET_USERS_EMPLOYEE_ID = true;

$currentUserId = isset($_SESSION['auth_user']['UserID']) ? (int)$_SESSION['auth_user']['UserID'] : 0;

/* Messages */
$msg=''; $msg_type='success';

/* Load employees list (for self-linking if needed) */
$employees = array();
try {
  $expr = employee_display_expr();
  $employees = $conn->query("
    SELECT e.EmployeeID, {$expr} AS EmpName, e.EmployeeCode
      FROM dbo.Employees e
     ORDER BY EmpName
  ")->fetchAll(PDO::FETCH_ASSOC);
} catch(PDOException $e){
  $employees = array();
}

/* Self link action (no new table) */
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['act']) && $_POST['act']==='link_employee') {
  check_csrf();
  $eid = isset($_POST['EmployeeID']) ? (int)$_POST['EmployeeID'] : 0;
  if ($currentUserId>0 && $eid>0) {
    try {
      $st = $conn->prepare("UPDATE dbo.Users SET EmployeeID=:eid WHERE UserID=:uid");
      $st->execute(array(':eid'=>$eid, ':uid'=>$currentUserId));
      header("Location: ".$self); exit;
    } catch(PDOException $e){
      $msg_type='danger';
      $msg='Map failed: '.h($e->getMessage());
    }
  } else {
    $msg_type='danger';
    $msg='Please select employee.';
  }
}

/* Resolve employee */
list($employeeId, $note) = resolve_employee_id($conn, $currentUserId, $AUTO_SET_USERS_EMPLOYEE_ID);
$canApply = ($employeeId !== null);

if (!$canApply && $note) { $msg_type='danger'; $msg=$note; }
if (isset($_GET['ok']) && $_GET['ok']==='1') { $msg_type='success'; $msg='Leave applied successfully.'; }

/* Leave Types */
$leaveTypes = array();
try {
  $leaveTypes = $conn->query("
    SELECT LeaveTypeID, LeaveTypeName
      FROM dbo.LeaveTypes
     WHERE (IsActive=1 OR IsActive IS NULL)
     ORDER BY LeaveTypeName
  ")->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
  $msg_type='danger';
  $msg='Leave types load failed: '.h($e->getMessage());
}

/* Edit (own only) */
$editRow=null;
$edit_id = isset($_GET['edit']) ? (int)$_GET['edit'] : 0;
if ($edit_id>0 && $canApply) {
  $st = $conn->prepare("SELECT * FROM dbo.LeaveApplications WHERE LeaveApplicationID=:id AND EmployeeID=:emp");
  $st->execute(array(':id'=>$edit_id, ':emp'=>$employeeId));
  $editRow = $st->fetch(PDO::FETCH_ASSOC);
}

/* CREATE */
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['act']) && $_POST['act']==='create') {
  check_csrf();

  list($employeeId, $note) = resolve_employee_id($conn, $currentUserId, $AUTO_SET_USERS_EMPLOYEE_ID);
  $canApply = ($employeeId !== null);

  if (!$canApply) { $msg_type='danger'; $msg=$note; }
  else {
    $LeaveTypeID = isset($_POST['LeaveTypeID']) ? (int)$_POST['LeaveTypeID'] : 0;
    $StartDate = parse_date_any(isset($_POST['StartDate']) ? $_POST['StartDate'] : '');
    $EndDate   = parse_date_any(isset($_POST['EndDate']) ? $_POST['EndDate'] : '');
    $TotalDays = parse_decimal_1(isset($_POST['TotalDays']) ? $_POST['TotalDays'] : '');
    $Reason    = normalize_text(isset($_POST['Reason']) ? $_POST['Reason'] : '');

    if ($LeaveTypeID<=0 || !$StartDate || !$EndDate) { $msg_type='danger'; $msg="Type/Start/End required."; }
    elseif (strtotime($EndDate) < strtotime($StartDate)) { $msg_type='danger'; $msg="End date start এর আগে হতে পারবে না।"; }
    elseif ($TotalDays===null) { $msg_type='danger'; $msg="Total days invalid (e.g. 1, 1.5, 2.0)."; }
    else {
      try {
        $conn->beginTransaction();
        
        $stmt = $conn->prepare("
          INSERT INTO dbo.LeaveApplications
            (EmployeeID, LeaveTypeID, StartDate, EndDate, TotalDays, Reason, Status, AppliedDate)
          VALUES
            (:emp,:lt,:sd,:ed,:td,:rsn,0,GETDATE())
        ");
        $stmt->execute(array(
          ':emp'=>$employeeId, ':lt'=>$LeaveTypeID, ':sd'=>$StartDate, ':ed'=>$EndDate,
          ':td'=>$TotalDays, ':rsn'=>$Reason
        ));
        
        $leaveApplicationID = $conn->lastInsertId();
        
        // DEBUGGING: Log that we're creating leave application
        error_log("DEBUG: Creating leave application #" . $leaveApplicationID . " for employee ID: " . $employeeId);
        
        // Get the inserted leave application details
        $sqlGetLeave = "
            SELECT la.*, lt.LeaveTypeName 
            FROM dbo.LeaveApplications la
            LEFT JOIN dbo.LeaveTypes lt ON la.LeaveTypeID = lt.LeaveTypeID
            WHERE la.LeaveApplicationID = ?
        ";
        $stmtGetLeave = $conn->prepare($sqlGetLeave);
        $stmtGetLeave->execute([$leaveApplicationID]);
        $leaveData = $stmtGetLeave->fetch(PDO::FETCH_ASSOC);
        
        // Get employee details - USING ONLY Email_Office
        $sqlEmployee = "
            SELECT EmployeeID, FirstName, LastName, EmployeeCode, 
                   Email_Office as Email
            FROM dbo.Employees
            WHERE EmployeeID = ?
        ";
        $stmtEmployee = $conn->prepare($sqlEmployee);
        $stmtEmployee->execute([$employeeId]);
        $employeeData = $stmtEmployee->fetch(PDO::FETCH_ASSOC);
        
        // DEBUGGING: Test email configuration first
        error_log("DEBUG: Testing email configuration for employee ID: " . $employeeId);
        $testResult = testEmailConfiguration($conn, $employeeId);
        error_log("DEBUG: Test email result: " . ($testResult ? "SUCCESS" : "FAILED"));
        
        // Get supervisor emails
        $supervisorEmails = getSupervisorEmails($conn, $employeeId);
        
        // Send email notifications to supervisors
$emailSentCount = 0;
if (!empty($supervisorEmails) && !empty($leaveData) && !empty($employeeData)) {
    // Generate email content
    $emailContent = generateLeaveEmailHTML($leaveData, $employeeData);
    
    // DEBUGGING: Log email details
    error_log("DEBUG: Attempting to send emails to " . count($supervisorEmails) . " supervisor(s)");
    
    // Send email to each supervisor
    foreach ($supervisorEmails as $index => $supervisorEmail) {
        error_log("DEBUG: Processing supervisor email #" . ($index+1) . ": " . $supervisorEmail);
        
        if (filter_var($supervisorEmail, FILTER_VALIDATE_EMAIL)) {
            $sent = sendSMTPMail(
                $supervisorEmail,  // This should pass supervisor's email
                $emailContent['subject'],
                $emailContent['body']
            );
            
            if ($sent) {
                $emailSentCount++;
                error_log("SUCCESS: Leave notification sent to: " . $supervisorEmail . " for application #" . $leaveApplicationID);
            } else {
                error_log("FAILED: Could not send email to: " . $supervisorEmail);
            }
        } else {
            error_log("ERROR: Invalid email address format for supervisor: " . $supervisorEmail);
        }
    }
}else {
            error_log("DEBUG: Cannot send emails - missing data:");
            error_log("DEBUG: - Supervisor emails: " . (empty($supervisorEmails) ? "EMPTY" : "OK"));
            error_log("DEBUG: - Leave data: " . (empty($leaveData) ? "EMPTY" : "OK"));
            error_log("DEBUG: - Employee data: " . (empty($employeeData) ? "EMPTY" : "OK"));
            
            // Log what we do have
            if ($employeeData) {
                error_log("DEBUG: Employee data: " . print_r($employeeData, true));
            }
        }
        
        $conn->commit();
        
        // Set success message with email notification info
        $successMsg = "Leave application submitted successfully!";
        if ($emailSentCount > 0) {
            $successMsg .= " Notification sent to supervisor(s).";
        } else {
            $successMsg .= " (Note: No email notifications sent - check supervisor email configuration)";
        }
        
        $_SESSION['success'] = $successMsg;
        header('Location: ' . $self . '?ok=1'); 
        exit;
        
      } catch(PDOException $e){
        $conn->rollBack();
        $msg_type='danger'; 
        $msg="Create failed: ".h($e->getMessage());
        error_log("ERROR in CREATE: " . $e->getMessage());
      }
    }
  }
}

/* UPDATE (only pending) */
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['act']) && $_POST['act']==='update') {
  check_csrf();

  list($employeeId, $note) = resolve_employee_id($conn, $currentUserId, $AUTO_SET_USERS_EMPLOYEE_ID);
  $canApply = ($employeeId !== null);

  if (!$canApply) { $msg_type='danger'; $msg=$note; }
  else {
    $id = isset($_POST['LeaveApplicationID']) ? (int)$_POST['LeaveApplicationID'] : 0;
    $LeaveTypeID = isset($_POST['LeaveTypeID']) ? (int)$_POST['LeaveTypeID'] : 0;
    $StartDate = parse_date_any(isset($_POST['StartDate']) ? $_POST['StartDate'] : '');
    $EndDate   = parse_date_any(isset($_POST['EndDate']) ? $_POST['EndDate'] : '');
    $TotalDays = parse_decimal_1(isset($_POST['TotalDays']) ? $_POST['TotalDays'] : '');
    $Reason    = normalize_text(isset($_POST['Reason']) ? $_POST['Reason'] : '');

    if ($id<=0 || $LeaveTypeID<=0 || !$StartDate || !$EndDate || $TotalDays===null) { $msg_type='danger'; $msg="Invalid data."; }
    else {
      try {
        $st = $conn->prepare("
          UPDATE dbo.LeaveApplications
             SET LeaveTypeID=:lt, StartDate=:sd, EndDate=:ed, TotalDays=:td, Reason=:rsn
           WHERE LeaveApplicationID=:id AND EmployeeID=:emp AND Status=0
        ");
        $st->execute(array(':lt'=>$LeaveTypeID, ':sd'=>$StartDate, ':ed'=>$EndDate, ':td'=>$TotalDays, ':rsn'=>$Reason, ':id'=>$id, ':emp'=>$employeeId));
        header('Location: '.$self); exit;
      } catch(PDOException $e){
        $msg_type='danger'; $msg="Update failed: ".h($e->getMessage());
      }
    }
  }
}

/* CANCEL (only pending) */
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['act']) && $_POST['act']==='cancel') {
  check_csrf();

  list($employeeId, $note) = resolve_employee_id($conn, $currentUserId, $AUTO_SET_USERS_EMPLOYEE_ID);
  $canApply = ($employeeId !== null);

  if (!$canApply) { $msg_type='danger'; $msg=$note; }
  else {
    $id = isset($_POST['LeaveApplicationID']) ? (int)$_POST['LeaveApplicationID'] : 0;
    if ($id>0) {
      try {
        $st = $conn->prepare("
          UPDATE dbo.LeaveApplications
             SET Status=4, CancelledDate=GETDATE()
           WHERE LeaveApplicationID=:id AND EmployeeID=:emp AND Status=0
        ");
        $st->execute(array(':id'=>$id, ':emp'=>$employeeId));
        $msg_type='success'; $msg='Cancelled.';
      } catch(PDOException $e){
        $msg_type='danger'; $msg='Cancel failed: '.h($e->getMessage());
      }
    }
  }
}

/* List own */
$rows = array();
if ($canApply) {
  $st = $conn->prepare("
    SELECT la.LeaveApplicationID, la.StartDate, la.EndDate, la.TotalDays, la.Reason,
           la.Status, la.AppliedDate, lt.LeaveTypeName
      FROM dbo.LeaveApplications la
      LEFT JOIN dbo.LeaveTypes lt ON lt.LeaveTypeID=la.LeaveTypeID
     WHERE la.EmployeeID=:emp
     ORDER BY la.LeaveApplicationID DESC
  ");
  $st->execute(array(':emp'=>$employeeId));
  $rows = $st->fetchAll(PDO::FETCH_ASSOC);
}

require_once __DIR__ . '/../../include/header.php';
?>

<style>
  .page-wrap { max-width: 100%; margin: 28px auto; padding: 0 16px 32px; }
  .page-title { font-weight:700; color:#0f172a; display:flex; align-items:center; gap:8px; }
  .page-title i{ font-size:22px; color:#4f46e5; }
  .page-subtitle{ font-size:13px; color:#6b7280; }

  .card-elevated { border-radius:16px; border:1px solid #e5e7eb; box-shadow:0 18px 45px rgba(15,23,42,.12); overflow:hidden; }
  .card-elevated .card-body{ background: radial-gradient(circle at top left, #eff6ff 0, #ffffff 45%, #f9fafb 100%); }

  .badge-soft { border-radius:999px; padding:5px 12px; font-size:12px; font-weight:500; color:#0f172a; background:#e0f2fe; border:1px solid #bae6fd; display:inline-flex; align-items:center; gap:6px; }
  .badge-soft i{ font-size:.85rem; color:#0284c7; }

  .btn-brand { background:linear-gradient(135deg,#6366f1,#2563eb); color:#fff!important; border:none; padding:.55rem 1.4rem; font-weight:600; border-radius:999px; display:inline-flex; align-items:center; gap:8px; box-shadow:0 12px 25px rgba(37,99,235,.35); }
  .btn-brand:hover { background:linear-gradient(135deg,#4f46e5,#1d4ed8); }

  .btn-muted { background:#e5e7eb; color:#111827!important; border:none; border-radius:999px; padding:.45rem 1.1rem; font-weight:500; display:inline-flex; align-items:center; gap:6px; }
  .btn-danger-soft{ background:#fee2e2; color:#b91c1c!important; border:1px solid #fecaca; border-radius:999px; padding:.45rem 1.1rem; font-weight:500; display:inline-flex; align-items:center; gap:6px; }

  .form-label{ font-weight:600; color:#374151; font-size:13px; }
  .form-control, .form-select{ border-radius:10px; border-color:#cbd5e1; font-size:14px; }

  .section-title{ font-weight:600; color:#111827; display:flex; align-items:center; gap:8px; }
  .section-title i{ color:#4f46e5; font-size:1rem; }

  .status-pill{ display:inline-flex; align-items:center; gap:6px; padding:3px 10px; border-radius:999px; font-size:11px; font-weight:500; }
  .status-pill .status-dot{ width:8px; height:8px; border-radius:999px; }
  .status-pill-active{ background:#ecfdf3; color:#166534; } .status-pill-active .status-dot{ background:#22c55e; }
  .status-pill-inactive{ background:#fef2f2; color:#b91c1c; } .status-pill-inactive .status-dot{ background:#ef4444; }
  .status-pill-warning{ background:#fffbeb; color:#92400e; } .status-pill-warning .status-dot{ background:#f59e0b; }
  .status-pill-muted{ background:#f3f4f6; color:#374151; } .status-pill-muted .status-dot{ background:#9ca3af; }
</style>

<div class="page-wrap">
  <div class="d-flex flex-wrap align-items-center justify-content-between mb-3 gap-2">
    <div>
      <h1 class="page-title mb-1"><i class="fas fa-paper-plane"></i> Leave Application</h1>
      <!-- <div class="page-subtitle">আপনি আপনার নিজের জন্য leave apply / edit / cancel করতে পারবেন।</div> -->
    </div>
    <span class="badge-soft"><i class="fas fa-layer-group"></i> Total: <?php echo count($rows); ?></span>
  </div>

  <?php if ($msg): ?>
    <div class="alert alert-<?php echo ($msg_type==='danger'?'danger':'success'); ?> alert-dismissible fade show shadow-sm" role="alert">
      <?php echo h($msg); ?>
      <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
  <?php endif; ?>

  <?php if (!$canApply): ?>
    <div class="card card-elevated mb-4">
      <div class="card-body">
        <div class="section-title mb-2"><i class="fas fa-link"></i><span>Link your account with Employee</span></div>
        <form method="post" class="row g-3" accept-charset="UTF-8">
          <input type="hidden" name="csrf" value="<?php echo h($CSRF); ?>">
          <input type="hidden" name="act" value="link_employee">
          <div class="col-12 col-md-6">
            <label class="form-label">Select Employee</label>
            <select name="EmployeeID" class="form-select" required>
              <option value="">-- Select --</option>
              <?php foreach($employees as $e): ?>
                <option value="<?php echo (int)$e['EmployeeID']; ?>">
                  <?php echo h($e['EmpName']); ?> (<?php echo h($e['EmployeeCode']); ?>)
                </option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-12 col-md-3 d-flex align-items-end">
            <button class="btn btn-brand" type="submit"><i class="fas fa-save"></i> Save Mapping</button>
          </div>
        </form>
        <div class="text-muted small mt-2">একবার map করলে পরে আর দেখাবে না।</div>
      </div>
    </div>
  <?php endif; ?>

  <div class="card card-elevated mb-4">
    <div class="card-body">
      <?php if (!empty($editRow) && (int)$editRow['Status']===0): ?>
        <div class="section-title mb-3"><i class="fas fa-edit"></i><span>Edit (Pending)</span></div>
        <form method="post" class="row g-3" accept-charset="UTF-8">
          <input type="hidden" name="csrf" value="<?php echo h($CSRF); ?>">
          <input type="hidden" name="act" value="update">
          <input type="hidden" name="LeaveApplicationID" value="<?php echo (int)$editRow['LeaveApplicationID']; ?>">

          <div class="col-12 col-md-4">
            <label class="form-label">Leave Type</label>
            <select name="LeaveTypeID" class="form-select" required>
              <option value="">-- Select --</option>
              <?php foreach($leaveTypes as $lt): ?>
                <option value="<?php echo (int)$lt['LeaveTypeID']; ?>" <?php echo ((int)$editRow['LeaveTypeID']===(int)$lt['LeaveTypeID']?'selected':''); ?>>
                  <?php echo h($lt['LeaveTypeName']); ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="col-6 col-md-2">
            <label class="form-label">Start</label>
            <input type="date" name="StartDate" class="form-control" required value="<?php echo h(substr((string)$editRow['StartDate'],0,10)); ?>">
          </div>

          <div class="col-6 col-md-2">
            <label class="form-label">End</label>
            <input type="date" name="EndDate" class="form-control" required value="<?php echo h(substr((string)$editRow['EndDate'],0,10)); ?>">
          </div>

          <div class="col-12 col-md-2">
            <label class="form-label">Total Days</label>
            <input type="number" step="0.1" min="0" name="TotalDays" class="form-control" required value="<?php echo h($editRow['TotalDays']); ?>">
          </div>

          <div class="col-12">
            <label class="form-label">Details</label>
            <textarea name="Reason" class="form-control" rows="2"><?php echo h($editRow['Reason']); ?></textarea>
          </div>

          <div class="col-12 d-grid d-md-inline">
            <button class="btn btn-brand"><i class="fas fa-save"></i> Update</button>
            <a class="btn btn-muted" href="<?php echo h($self); ?>">Cancel</a>
          </div>
        </form>
      <?php else: ?>
        <div class="section-title mb-3"></div>
        <form method="post" class="row g-3" accept-charset="UTF-8">
          <input type="hidden" name="csrf" value="<?php echo h($CSRF); ?>">
          <input type="hidden" name="act" value="create">

          <div class="col-12 col-md-4">
            <label class="form-label">Leave Type</label>
            <select name="LeaveTypeID" class="form-select" required <?php echo $canApply?'':'disabled'; ?>>
              <option value="">-- Select --</option>
              <?php foreach($leaveTypes as $lt): ?>
                <option value="<?php echo (int)$lt['LeaveTypeID']; ?>"><?php echo h($lt['LeaveTypeName']); ?></option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="col-6 col-md-2">
            <label class="form-label">Start</label>
            <input type="date" name="StartDate" class="form-control" required <?php echo $canApply?'':'disabled'; ?>>
          </div>

          <div class="col-6 col-md-2">
            <label class="form-label">End</label>
            <input type="date" name="EndDate" class="form-control" required <?php echo $canApply?'':'disabled'; ?>>
          </div>

          <div class="col-12 col-md-2">
            <label class="form-label">Total Days</label>
            <input type="number" step="0.1" min="0" name="TotalDays" class="form-control" required placeholder="e.g. 2.0" <?php echo $canApply?'':'disabled'; ?>>
          </div>

          <div class="col-12">
            <label class="form-label">Details</label>
            <textarea
              name="Reason"
              class="form-control"
              rows="2"
              placeholder="Reason..."
              oninput="allowPlainText(this)"
              <?php echo $canApply ? '' : 'disabled'; ?>
            ></textarea>
          </div>

          <script>
          function allowPlainText(el) {
            // Allow letters, numbers, space, full stop and comma
            el.value = el.value.replace(/[^a-zA-Z0-9 .,]/g, '');
          }
          </script>

          <div class="col-12 d-grid d-md-inline">
            <button class="btn btn-brand" <?php echo $canApply?'':'disabled'; ?>><i class="fas fa-save"></i> Submit</button>
          </div>
        </form>
      <?php endif; ?>
    </div>
  </div>

  <div class="card card-elevated">
    <div class="card-body">
      <div class="section-title mb-2"><i class="fas fa-list-ul"></i><span>My Applications</span></div>
      <div class="table-responsive">
        <table class="table table-hover align-middle">
          <thead>
            <tr>
              <th>#</th><th>Type</th><th>Dates</th><th>Days</th><th>Status</th><th>Applied</th><th class="text-end">Actions</th>
            </tr>
          </thead>
          <tbody>
          <?php foreach($rows as $r): ?>
            <?php $st=(int)$r['Status']; ?>
            <tr>
              <td><?php echo (int)$r['LeaveApplicationID']; ?></td>
              <td><?php echo h($r['LeaveTypeName']); ?></td>
              <td><?php echo h(substr((string)$r['StartDate'],0,10)); ?> → <?php echo h(substr((string)$r['EndDate'],0,10)); ?></td>
              <td><?php echo h($r['TotalDays']); ?></td>
              <td><span class="status-pill <?php echo status_class($STATUS,$st); ?>"><span class="status-dot"></span><?php echo h(status_text($STATUS,$st)); ?></span></td>
              <td><?php echo h($r['AppliedDate']); ?></td>
              <td class="text-end">
                <?php if ((int)$r['Status']===0): ?>
                  <a class="btn btn-muted btn-sm" href="<?php echo h($self); ?>?edit=<?php echo (int)$r['LeaveApplicationID']; ?>"><i class="fas fa-pencil-alt"></i> Edit</a>
                  <form method="post" class="d-inline" accept-charset="UTF-8" onsubmit="return confirm('Cancel?');">
                    <input type="hidden" name="csrf" value="<?php echo h($CSRF); ?>">
                    <input type="hidden" name="act" value="cancel">
                    <input type="hidden" name="LeaveApplicationID" value="<?php echo (int)$r['LeaveApplicationID']; ?>">
                    <button class="btn btn-danger-soft btn-sm" type="submit"><i class="fas fa-ban"></i> Cancel</button>
                  </form>
                <?php else: ?>
                  <span class="text-muted">—</span>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>

          <?php if (empty($rows)): ?>
            <tr><td colspan="7" class="text-center text-muted py-4">No data</td></tr>
          <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>

<?php require_once __DIR__ . '/../../include/footer.php'; ?>