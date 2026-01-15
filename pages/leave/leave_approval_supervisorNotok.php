<?php
// pages/leave/leave_approval_supervisor.php
require_once $_SERVER['DOCUMENT_ROOT'] . '/PRF_Leave/init.php';

// Get logged-in employee ID from session
$employeeId = (int)($_SESSION['auth_user']['EmployeeID'] ?? 0);
$username = $_SESSION['auth_user']['username'] ?? '';

// If no employee ID in session, redirect to login
if ($employeeId === 0) {
    header('Location: ' . BASE_URL . '/login.php');
    exit;
}

// ==============================================
// MANUALLY DEFINED SUPERVISOR EMPLOYEE CODES
// Add employee codes of supervisors here
// ==============================================
$supervisorEmployeeCodes = [
    '0102',  // Example supervisor 1
    '0105',  // Example supervisor 2  
    '0017',  // Example supervisor 3
    'EMP101',  // Example supervisor 4
    'EMP102',  // Example supervisor 5
    // Add more supervisor employee codes as needed
];

// ==============================================
// Check if logged-in user is in supervisor list
// ==============================================

// First, get the logged-in user's employee code
$sqlEmployee = "
    SELECT 
        e.EmployeeID,
        e.FirstName,
        e.LastName,
        e.EmployeeCode,
        e.FirstName + ' ' + e.LastName AS FullName
    FROM [dbPRFAssetMgt].[dbo].[Employees] e
    WHERE e.EmployeeID = :employee_id
";

$stmtEmployee = $conn->prepare($sqlEmployee);
$stmtEmployee->bindParam(':employee_id', $employeeId, PDO::PARAM_INT);
$stmtEmployee->execute();
$loggedInEmployee = $stmtEmployee->fetch(PDO::FETCH_ASSOC);

if (!$loggedInEmployee) {
    showError("Employee not found in database.");
    exit;
}

$userEmployeeCode = $loggedInEmployee['EmployeeCode'];
$isSupervisor = in_array($userEmployeeCode, $supervisorEmployeeCodes);

// ==============================================
// Manual override for testing (optional)
// ==============================================
$manualSupervisorMode = false;
$manualEmployeeCode = '';

if (isset($_GET['manual_code']) && !empty($_GET['manual_code'])) {
    $manualEmployeeCode = trim($_GET['manual_code']);
    $manualSupervisorMode = true;
    
    // Check if manual code is in supervisor list
    if (in_array($manualEmployeeCode, $supervisorEmployeeCodes)) {
        // Fetch employee by employee code
        $sqlManual = "
            SELECT 
                EmployeeID,
                FirstName,
                LastName,
                EmployeeCode,
                FirstName + ' ' + LastName AS FullName
            FROM [dbPRFAssetMgt].[dbo].[Employees]
            WHERE EmployeeCode = :employee_code
        ";
        
        $stmtManual = $conn->prepare($sqlManual);
        $stmtManual->bindParam(':employee_code', $manualEmployeeCode);
        $stmtManual->execute();
        $manualEmployee = $stmtManual->fetch(PDO::FETCH_ASSOC);
        
        if ($manualEmployee) {
            $loggedInEmployee = $manualEmployee;
            $employeeId = $manualEmployee['EmployeeID'];
            $isSupervisor = true;
        } else {
            showError("Employee with code '$manualEmployeeCode' not found in database.");
        }
    } else {
        showError("Employee code '$manualEmployeeCode' is not authorized as a supervisor.");
    }
}

// ==============================================
// If user is NOT a supervisor, show access denied
// ==============================================
if (!$isSupervisor && !$manualSupervisorMode) {
    showAccessDeniedPage($employeeId, $username, $loggedInEmployee, $supervisorEmployeeCodes);
    exit;
}

// Use logged-in supervisor's ID
$supervisorID = $employeeId;

// If supervisor_id is provided in GET, use it (for testing different supervisors)
if (isset($_GET['supervisor_id']) && is_numeric($_GET['supervisor_id'])) {
    $supervisorID = (int) $_GET['supervisor_id'];
}

// If leave_id is provided, show approval page
if (isset($_GET['leave_id']) && is_numeric($_GET['leave_id'])) {
    $leaveApplicationID = (int) $_GET['leave_id'];
    showApprovalPage($conn, $supervisorID, $leaveApplicationID, $employeeId, $username, $loggedInEmployee, $manualSupervisorMode, $manualEmployeeCode, $supervisorEmployeeCodes);
} else {
    // Show list of pending leaves
    showPendingLeavesList($conn, $supervisorID, $employeeId, $username, $loggedInEmployee, $manualSupervisorMode, $manualEmployeeCode, $supervisorEmployeeCodes);
}

// ==============================================
// Function to show access denied page
// ==============================================
function showAccessDeniedPage($employeeId, $username, $employeeData, $supervisorList) {
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Access Denied - Supervisor Approval</title>
        <style>
            * {
                margin: 0;
                padding: 0;
                box-sizing: border-box;
            }
            body {
                font-family: Arial, sans-serif;
                background: #f0f2f5;
                padding: 20px;
            }
            .container {
                max-width: 800px;
                margin: 0 auto;
            }
            .header {
                background: white;
                padding: 20px;
                border-radius: 10px;
                box-shadow: 0 2px 10px rgba(0,0,0,0.1);
                margin-bottom: 20px;
            }
            h1 {
                color: #333;
                margin-bottom: 10px;
            }
            .alert {
                padding: 12px 15px;
                border-radius: 5px;
                margin-bottom: 20px;
                font-weight: bold;
            }
            .alert-danger {
                background-color: #f8d7da;
                color: #721c24;
                border: 1px solid #f5c6cb;
            }
            .alert-info {
                background-color: #d1ecf1;
                color: #0c5460;
                border: 1px solid #bee5eb;
            }
            .content {
                background: white;
                padding: 30px;
                border-radius: 10px;
                box-shadow: 0 2px 10px rgba(0,0,0,0.1);
                text-align: center;
            }
            .info-box {
                background: #f8f9fa;
                padding: 20px;
                border-radius: 8px;
                margin: 20px 0;
                text-align: left;
            }
            .info-item {
                display: flex;
                justify-content: space-between;
                padding: 8px 0;
                border-bottom: 1px solid #eee;
            }
            .info-label {
                font-weight: bold;
                color: #666;
            }
            .info-value {
                color: #333;
            }
            .supervisor-list-box {
                background: #e8f4fd;
                padding: 20px;
                border-radius: 8px;
                margin: 20px 0;
                border-left: 4px solid #007bff;
            }
            .supervisor-list-items {
                display: flex;
                flex-wrap: wrap;
                gap: 10px;
                margin-top: 10px;
            }
            .supervisor-badge {
                background: #007bff;
                color: white;
                padding: 5px 10px;
                border-radius: 15px;
                font-size: 12px;
                font-weight: bold;
            }
            .manual-form {
                margin-top: 30px;
                padding: 20px;
                background: #fff3cd;
                border-radius: 8px;
                border-left: 4px solid #ffc107;
            }
            .form-group {
                margin-bottom: 15px;
                text-align: left;
            }
            label {
                display: block;
                margin-bottom: 5px;
                font-weight: bold;
                color: #495057;
            }
            input[type="text"] {
                width: 100%;
                padding: 10px;
                border: 1px solid #ced4da;
                border-radius: 4px;
                font-family: Arial, sans-serif;
            }
            .btn {
                padding: 10px 20px;
                border: none;
                border-radius: 5px;
                cursor: pointer;
                font-weight: bold;
                font-size: 16px;
                transition: all 0.3s;
                text-decoration: none;
                display: inline-block;
            }
            .btn-primary {
                background: #007bff;
                color: white;
            }
            .btn-primary:hover {
                background: #0056b3;
            }
            .btn-secondary {
                background: #6c757d;
                color: white;
            }
            .btn-secondary:hover {
                background: #5a6268;
            }
            .btn-warning {
                background: #ffc107;
                color: #212529;
            }
            .btn-warning:hover {
                background: #e0a800;
            }
        </style>
    </head>
    <body>
        <div class="container">
            <div class="header">
                <h1>Leave Approval System</h1>
                <p>Logged in as: <?php echo htmlspecialchars($username); ?></p>
            </div>
            
            <div class="content">
                <div class="alert alert-danger">
                    <strong>Access Denied:</strong> You are not authorized to access supervisor approval functions.
                </div>
                
                <div class="info-box">
                    <h3>Your Information:</h3>
                    <div class="info-item">
                        <span class="info-label">Employee ID:</span>
                        <span class="info-value"><?php echo $employeeId; ?></span>
                    </div>
                    <div class="info-item">
                        <span class="info-label">Name:</span>
                        <span class="info-value"><?php echo htmlspecialchars($employeeData['FullName']); ?></span>
                    </div>
                    <div class="info-item">
                        <span class="info-label">Employee Code:</span>
                        <span class="info-value"><?php echo htmlspecialchars($employeeData['EmployeeCode']); ?></span>
                    </div>
                    <div class="info-item">
                        <span class="info-label">Supervisor Status:</span>
                        <span class="info-value" style="color: #dc3545; font-weight: bold;">Not Authorized</span>
                    </div>
                </div>
                
                <div class="supervisor-list-box">
                    <h3>Authorized Supervisors:</h3>
                    <p>Only the following employee codes can access this system:</p>
                    <div class="supervisor-list-items">
                        <?php foreach ($supervisorList as $code): ?>
                            <span class="supervisor-badge"><?php echo htmlspecialchars($code); ?></span>
                        <?php endforeach; ?>
                    </div>
                    <p style="margin-top: 10px; font-size: 12px; color: #666;">
                        <em>Total: <?php echo count($supervisorList); ?> authorized supervisor(s)</em>
                    </p>
                </div>
                
                <?php if (can('admin.access')): ?>
                <div class="manual-form">
                    <h3>Admin Testing Mode</h3>
                    <p>As an admin, you can test supervisor functions by entering an authorized supervisor's employee code:</p>
                    
                    <form method="get" action="">
                        <div class="form-group">
                            <label for="manual_code">Supervisor Employee Code:</label>
                            <input type="text" id="manual_code" name="manual_code" 
                                   placeholder="Enter authorized supervisor code" required>
                        </div>
                        
                        <button type="submit" class="btn btn-warning">Test as Supervisor</button>
                        <a href="<?php echo BASE_URL; ?>/dashboard.php" class="btn btn-secondary">Return to Dashboard</a>
                    </form>
                    
                    <p style="margin-top: 15px; color: #856404; font-size: 12px;">
                        <strong>Note:</strong> This manual testing is only available to admin users.
                    </p>
                </div>
                <?php else: ?>
                <div style="margin-top: 30px;">
                    <a href="<?php echo BASE_URL; ?>/dashboard.php" class="btn btn-primary">Return to Dashboard</a>
                    <a href="javascript:history.back()" class="btn btn-secondary">Go Back</a>
                </div>
                <?php endif; ?>
                
                <p style="margin-top: 20px; color: #666; font-size: 14px;">
                    <em>If you believe you should have supervisor access, please contact your system administrator.</em>
                </p>
            </div>
        </div>
    </body>
    </html>
    <?php
    exit;
}

// ==============================================
// Function to show approval page
// ==============================================
function showApprovalPage($conn, $supervisorID, $leaveApplicationID, $loggedInEmployeeID, $username, $loggedInEmployee, $manualMode = false, $manualCode = '', $supervisorList = []) {
    // Check if supervisor exists
    $sqlCheck = "
        SELECT 
            EmployeeID,
            FirstName,
            LastName,
            SupervisorID_admin,
            SupervisorID_technical,
            SupervisorID_2ndLevel,
            EmployeeCode,
            FirstName + ' ' + LastName AS FullName
        FROM [dbPRFAssetMgt].[dbo].[Employees]
        WHERE EmployeeID = :empid
    ";

    $stmtCheck = $conn->prepare($sqlCheck);
    $stmtCheck->bindParam(':empid', $supervisorID, PDO::PARAM_INT);
    $stmtCheck->execute();
    $supervisor = $stmtCheck->fetch(PDO::FETCH_ASSOC);

    if (!$supervisor) {
        showError("Supervisor not found for EmployeeID: " . $supervisorID);
        return;
    }

    // Check if supervisor is either L1, L2 or 2nd Level supervisor
    $isL1Supervisor = ($supervisor['SupervisorID_admin'] !== null);
    $isL2Supervisor = ($supervisor['SupervisorID_technical'] !== null);
    $is2ndLevelSupervisor = ($supervisor['SupervisorID_2ndLevel'] !== null);
    
    // Also check if employee code is in authorized list
    $isAuthorizedSupervisor = in_array($supervisor['EmployeeCode'], $supervisorList);

    // Fetch leave application details with EmployeeID
    $sqlLeave = "
        SELECT 
            la.*,
            e.FirstName,
            e.LastName,
            e.EmployeeCode,
            e.EmployeeID as ApplicantEmployeeID,
            lt.LeaveTypeName
        FROM [dbPRFAssetMgt].[dbo].[LeaveApplications] la
        LEFT JOIN [dbPRFAssetMgt].[dbo].[Employees] e ON la.EmployeeID = e.EmployeeID
        LEFT JOIN [dbPRFAssetMgt].[dbo].[LeaveTypes] lt ON la.LeaveTypeID = lt.LeaveTypeID
        WHERE la.LeaveApplicationID = :leave_id
    ";

    $stmtLeave = $conn->prepare($sqlLeave);
    $stmtLeave->bindParam(':leave_id', $leaveApplicationID, PDO::PARAM_INT);
    $stmtLeave->execute();
    $leave = $stmtLeave->fetch(PDO::FETCH_ASSOC);

    if (!$leave) {
        showError("Leave application not found for ID: " . $leaveApplicationID);
        return;
    }

    // Store the applicant's EmployeeID
    $applicantEmployeeID = $leave['ApplicantEmployeeID'];

    // Check current approval status
    $sqlApproval = "
        SELECT 
            ApprovalID,
            L1ApprovedBy,
            L1ApprovedDate,
            L2ApprovedBy,
            L2ApprovedDate,
            RejectedBy,
            RejectedDate,
            RejectionReason,
            Comments,
            EmployeeID
        FROM [dbPRFAssetMgt].[dbo].[LeaveApprovals]
        WHERE LeaveApplicationID = :leave_id
    ";

    $stmtApproval = $conn->prepare($sqlApproval);
    $stmtApproval->bindParam(':leave_id', $leaveApplicationID, PDO::PARAM_INT);
    $stmtApproval->execute();
    $approval = $stmtApproval->fetch(PDO::FETCH_ASSOC);

    // Check if already approved/rejected
    $canApproveL1 = false;
    $canApproveL2 = false;
    $canReject = false;

    // Check L1 approval status
    if ($isL1Supervisor && $isAuthorizedSupervisor && $approval) {
        if ($approval['L1ApprovedBy'] === null && $approval['RejectedBy'] === null) {
            $canApproveL1 = true;
            $canReject = true;
        }
    }

    // Check L2 approval status
    if ($isL2Supervisor && $isAuthorizedSupervisor && $approval) {
        if ($approval['L1ApprovedBy'] !== null && $approval['L2ApprovedBy'] === null && $approval['RejectedBy'] === null) {
            $canApproveL2 = true;
            $canReject = true;
        }
    }

    // If no approval record exists, create one for L1 approval
    if (!$approval && $isL1Supervisor && $isAuthorizedSupervisor) {
        $canApproveL1 = true;
        $canReject = true;
    }

    // Form submit handling
    $successMsg = '';
    $errorMsg = '';

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $action = $_POST['action'];
        $comments = trim($_POST['comments']);
        $rejectReason = isset($_POST['rejection_reason']) ? trim($_POST['rejection_reason']) : null;
        
        // Validate inputs
        if (empty($comments)) {
            $errorMsg = 'Comments are required';
        } else if ($action === 'reject' && empty($rejectReason)) {
            $errorMsg = 'Rejection reason is required when rejecting';
        } else if (!$isAuthorizedSupervisor) {
            $errorMsg = 'You are not authorized to approve/reject leaves';
        } else {
            try {
                $conn->beginTransaction();
                
                if ($action === 'approve') {
                    if ($canApproveL1) {
                        // L1 Approval
                        if ($approval) {
                            // Update existing approval record
                            if (empty($approval['EmployeeID'])) {
                                // If EmployeeID is missing, update it
                                $sql = "
                                    UPDATE [dbPRFAssetMgt].[dbo].[LeaveApprovals]
                                    SET 
                                        L1ApprovedBy = :supervisor_id,
                                        L1ApprovedDate = GETDATE(),
                                        Comments = :comments,
                                        EmployeeID = :employee_id
                                    WHERE LeaveApplicationID = :leave_id
                                ";
                            } else {
                                $sql = "
                                    UPDATE [dbPRFAssetMgt].[dbo].[LeaveApprovals]
                                    SET 
                                        L1ApprovedBy = :supervisor_id,
                                        L1ApprovedDate = GETDATE(),
                                        Comments = :comments
                                    WHERE LeaveApplicationID = :leave_id
                                ";
                            }
                            $stmt = $conn->prepare($sql);
                            $stmt->bindParam(':supervisor_id', $supervisorID, PDO::PARAM_INT);
                            $stmt->bindParam(':leave_id', $leaveApplicationID, PDO::PARAM_INT);
                            $stmt->bindParam(':comments', $comments);
                            if (empty($approval['EmployeeID'])) {
                                $stmt->bindParam(':employee_id', $applicantEmployeeID, PDO::PARAM_INT);
                            }
                            $stmt->execute();
                        } else {
                            // Create new approval record for L1 with EmployeeID
                            $sql = "
                                INSERT INTO [dbPRFAssetMgt].[dbo].[LeaveApprovals]
                                (
                                    LeaveApplicationID,
                                    L1ApprovedBy,
                                    L1ApprovedDate,
                                    Comments,
                                    EmployeeID
                                )
                                VALUES
                                (
                                    :leave_id,
                                    :supervisor_id,
                                    GETDATE(),
                                    :comments,
                                    :employee_id
                                )
                            ";
                            $stmt = $conn->prepare($sql);
                            $stmt->bindParam(':leave_id', $leaveApplicationID, PDO::PARAM_INT);
                            $stmt->bindParam(':supervisor_id', $supervisorID, PDO::PARAM_INT);
                            $stmt->bindParam(':comments', $comments);
                            $stmt->bindParam(':employee_id', $applicantEmployeeID, PDO::PARAM_INT);
                            $stmt->execute();
                        }
                        
                        // Update LeaveApplications status
                        $sqlUpdate = "
                            UPDATE [dbPRFAssetMgt].[dbo].[LeaveApplications]
                            SET Status = 1
                            WHERE LeaveApplicationID = :leave_id
                        ";
                        $stmtUpdate = $conn->prepare($sqlUpdate);
                        $stmtUpdate->bindParam(':leave_id', $leaveApplicationID, PDO::PARAM_INT);
                        $stmtUpdate->execute();
                        
                        $successMsg = "Leave application L1 approved successfully.";
                    } 
                    else if ($canApproveL2) {
                        // L2 Approval - Update existing record
                        $sql = "
                            UPDATE [dbPRFAssetMgt].[dbo].[LeaveApprovals]
                            SET 
                                L2ApprovedBy = :supervisor_id,
                                L2ApprovedDate = GETDATE(),
                                Comments = :comments
                            WHERE LeaveApplicationID = :leave_id
                        ";
                        $stmt = $conn->prepare($sql);
                        $stmt->bindParam(':supervisor_id', $supervisorID, PDO::PARAM_INT);
                        $stmt->bindParam(':leave_id', $leaveApplicationID, PDO::PARAM_INT);
                        $stmt->bindParam(':comments', $comments);
                        $stmt->execute();
                        
                        // Update LeaveApplications status
                        $sqlUpdate = "
                            UPDATE [dbPRFAssetMgt].[dbo].[LeaveApplications]
                            SET Status = 2
                            WHERE LeaveApplicationID = :leave_id
                        ";
                        $stmtUpdate = $conn->prepare($sqlUpdate);
                        $stmtUpdate->bindParam(':leave_id', $leaveApplicationID, PDO::PARAM_INT);
                        $stmtUpdate->execute();
                        
                        $successMsg = "Leave application L2 approved successfully.";
                    }
                    
                } 
                else if ($action === 'reject' && $canReject) {
                    // Rejection
                    if ($approval) {
                        // Update existing approval record for rejection
                        $sql = "
                            UPDATE [dbPRFAssetMgt].[dbo].[LeaveApprovals]
                            SET 
                                RejectedBy = :supervisor_id,
                                RejectedDate = GETDATE(),
                                RejectionReason = :reason,
                                Comments = :comments
                            WHERE LeaveApplicationID = :leave_id
                        ";
                        $stmt = $conn->prepare($sql);
                        $stmt->bindParam(':supervisor_id', $supervisorID, PDO::PARAM_INT);
                        $stmt->bindParam(':leave_id', $leaveApplicationID, PDO::PARAM_INT);
                        $stmt->bindParam(':reason', $rejectReason);
                        $stmt->bindParam(':comments', $comments);
                        $stmt->execute();
                    } else {
                        // Create new approval record for rejection with EmployeeID
                        $sql = "
                            INSERT INTO [dbPRFAssetMgt].[dbo].[LeaveApprovals]
                            (
                                LeaveApplicationID,
                                RejectedBy,
                                RejectedDate,
                                RejectionReason,
                                Comments,
                                EmployeeID
                            )
                            VALUES
                            (
                                :leave_id,
                                :supervisor_id,
                                GETDATE(),
                                :reason,
                                :comments,
                                :employee_id
                            )
                        ";
                        $stmt = $conn->prepare($sql);
                        $stmt->bindParam(':supervisor_id', $supervisorID, PDO::PARAM_INT);
                        $stmt->bindParam(':leave_id', $leaveApplicationID, PDO::PARAM_INT);
                        $stmt->bindParam(':reason', $rejectReason);
                        $stmt->bindParam(':comments', $comments);
                        $stmt->bindParam(':employee_id', $applicantEmployeeID, PDO::PARAM_INT);
                        $stmt->execute();
                    }
                    
                    // Update LeaveApplications status
                    $sqlUpdate = "
                        UPDATE [dbPRFAssetMgt].[dbo].[LeaveApplications]
                        SET Status = 3
                        WHERE LeaveApplicationID = :leave_id
                    ";
                    $stmtUpdate = $conn->prepare($sqlUpdate);
                    $stmtUpdate->bindParam(':leave_id', $leaveApplicationID, PDO::PARAM_INT);
                    $stmtUpdate->execute();
                    
                    $successMsg = "Leave application rejected successfully.";
                }
                
                $conn->commit();
                
                // Refresh approval data
                $stmtApproval->execute();
                $approval = $stmtApproval->fetch(PDO::FETCH_ASSOC);
                
                // Update permission flags after action
                $canApproveL1 = false;
                $canApproveL2 = false;
                $canReject = false;
                
            } catch (Exception $e) {
                $conn->rollBack();
                $errorMsg = "Error processing request: " . $e->getMessage();
            }
        }
    }

    // Fetch list of authorized supervisors for display
    $authorizedSupervisors = [];
    if (!empty($supervisorList)) {
        $placeholders = implode(',', array_fill(0, count($supervisorList), '?'));
        $sqlAuthSupervisors = "
            SELECT 
                EmployeeID,
                FirstName,
                LastName,
                EmployeeCode,
                SupervisorID_admin,
                SupervisorID_technical,
                SupervisorID_2ndLevel,
                FirstName + ' ' + LastName AS FullName
            FROM [dbPRFAssetMgt].[dbo].[Employees]
            WHERE EmployeeCode IN ($placeholders)
            ORDER BY FirstName, LastName
        ";
        
        $stmtAuthSupervisors = $conn->prepare($sqlAuthSupervisors);
        foreach ($supervisorList as $key => $code) {
            $stmtAuthSupervisors->bindValue(($key+1), $code);
        }
        $stmtAuthSupervisors->execute();
        $authorizedSupervisors = $stmtAuthSupervisors->fetchAll(PDO::FETCH_ASSOC);
    }
    ?>
    
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Leave Approval System</title>
        <style>
            * {
                margin: 0;
                padding: 0;
                box-sizing: border-box;
            }
            body {
                font-family: Arial, sans-serif;
                background: #f0f2f5;
                padding: 20px;
            }
            .container {
                max-width: 1200px;
                margin: 0 auto;
            }
            .header {
                background: white;
                padding: 20px;
                border-radius: 10px;
                box-shadow: 0 2px 10px rgba(0,0,0,0.1);
                margin-bottom: 20px;
            }
            h1 {
                color: #333;
                margin-bottom: 10px;
            }
            .subtitle {
                color: #666;
                margin-bottom: 15px;
            }
            .alert {
                padding: 12px 15px;
                border-radius: 5px;
                margin-bottom: 20px;
                font-weight: bold;
            }
            .alert-success {
                background-color: #d4edda;
                color: #155724;
                border: 1px solid #c3e6cb;
            }
            .alert-error {
                background-color: #f8d7da;
                color: #721c24;
                border: 1px solid #f5c6cb;
            }
            .alert-info {
                background-color: #d1ecf1;
                color: #0c5460;
                border: 1px solid #bee5eb;
            }
            .alert-warning {
                background-color: #fff3cd;
                color: #856404;
                border: 1px solid #ffeaa7;
            }
            .content {
                display: grid;
                grid-template-columns: 1fr 300px;
                gap: 20px;
            }
            .main-content {
                background: white;
                padding: 20px;
                border-radius: 10px;
                box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            }
            .sidebar {
                background: white;
                padding: 20px;
                border-radius: 10px;
                box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            }
            .section {
                margin-bottom: 25px;
            }
            .section h2 {
                color: #495057;
                margin-bottom: 15px;
                padding-bottom: 8px;
                border-bottom: 2px solid #dee2e6;
            }
            .info-grid {
                display: grid;
                grid-template-columns: repeat(2, 1fr);
                gap: 10px;
                margin-bottom: 10px;
            }
            .info-item {
                display: flex;
                justify-content: space-between;
                padding: 8px 0;
                border-bottom: 1px solid #eee;
            }
            .info-label {
                font-weight: bold;
                color: #666;
            }
            .info-value {
                color: #333;
            }
            .status {
                display: inline-block;
                padding: 4px 10px;
                border-radius: 20px;
                font-size: 12px;
                font-weight: bold;
            }
            .status-pending { background: #ffc107; color: #333; }
            .status-l1 { background: #17a2b8; color: white; }
            .status-l2 { background: #28a745; color: white; }
            .status-rejected { background: #dc3545; color: white; }
            .status-cancelled { background: #6c757d; color: white; }
            
            form {
                margin-top: 20px;
            }
            .form-group {
                margin-bottom: 15px;
            }
            label {
                display: block;
                margin-bottom: 5px;
                font-weight: bold;
                color: #495057;
            }
            textarea, select {
                width: 100%;
                padding: 10px;
                border: 1px solid #ced4da;
                border-radius: 4px;
                font-family: Arial, sans-serif;
            }
            textarea {
                resize: vertical;
            }
            .button-group {
                display: flex;
                gap: 10px;
                justify-content: center;
                margin-top: 20px;
            }
            button {
                padding: 12px 30px;
                border: none;
                border-radius: 5px;
                cursor: pointer;
                font-weight: bold;
                font-size: 16px;
                transition: all 0.3s;
            }
            .btn-approve {
                background: #28a745;
                color: white;
            }
            .btn-approve:hover {
                background: #218838;
            }
            .btn-reject {
                background: #dc3545;
                color: white;
            }
            .btn-reject:hover {
                background: #c82333;
            }
            .btn-back {
                background: #6c757d;
                color: white;
                text-decoration: none;
                padding: 10px 20px;
                border-radius: 5px;
                display: inline-block;
                margin-top: 20px;
            }
            .btn-back:hover {
                background: #5a6268;
            }
            .supervisor-list {
                max-height: 300px;
                overflow-y: auto;
                border: 1px solid #dee2e6;
                border-radius: 5px;
                padding: 10px;
            }
            .supervisor-item {
                padding: 8px;
                border-bottom: 1px solid #eee;
                cursor: pointer;
            }
            .supervisor-item:hover {
                background: #f8f9fa;
            }
            .supervisor-item.active {
                background: #e7f5ff;
                border-left: 4px solid #007bff;
            }
            .supervisor-badges {
                display: flex;
                gap: 5px;
                margin-top: 3px;
                flex-wrap: wrap;
            }
            .badge {
                padding: 2px 6px;
                border-radius: 10px;
                font-size: 10px;
                font-weight: bold;
            }
            .badge-l1 { background: #17a2b8; color: white; }
            .badge-l2 { background: #28a745; color: white; }
            .badge-2nd { background: #6f42c1; color: white; }
            .badge-authorized { background: #28a745; color: white; }
            .badge-unauthorized { background: #dc3545; color: white; }
            .authorized-badge {
                background: #28a745;
                color: white;
                padding: 2px 8px;
                border-radius: 10px;
                font-size: 10px;
                font-weight: bold;
                margin-left: 5px;
            }
            .debug-info {
                margin-top: 30px;
                padding: 15px;
                background: #f8f9fa;
                border: 1px dashed #ccc;
                border-radius: 5px;
                font-size: 12px;
                color: #666;
            }
            .login-info {
                background: #e8f4fd;
                padding: 10px;
                border-radius: 5px;
                margin-bottom: 15px;
                border-left: 4px solid #007bff;
            }
            .manual-mode-banner {
                background: #fff3cd;
                border: 1px solid #ffeaa7;
                color: #856404;
                padding: 10px 15px;
                border-radius: 5px;
                margin-bottom: 15px;
                display: flex;
                justify-content: space-between;
                align-items: center;
            }
            .manual-mode-banner a {
                color: #856404;
                text-decoration: underline;
                font-weight: bold;
            }
            @media (max-width: 768px) {
                .content {
                    grid-template-columns: 1fr;
                }
                .info-grid {
                    grid-template-columns: 1fr;
                }
                .button-group {
                    flex-direction: column;
                }
                button {
                    width: 100%;
                }
            }
        </style>
    </head>
    <body>
        <div class="container">
            <div class="header">
                <h1>Leave Approval System</h1>
                <p class="subtitle">Logged in as: <?php echo htmlspecialchars($username); ?> (Employee ID: <?php echo $loggedInEmployeeID; ?>)</p>
                
                <?php if ($manualMode && !empty($manualCode)): ?>
                <div class="manual-mode-banner">
                    <div>
                        <strong>Manual Testing Mode Active:</strong> Testing as supervisor with Employee Code: <?php echo htmlspecialchars($manualCode); ?>
                    </div>
                    <div>
                        <a href="?supervisor_id=<?php echo $loggedInEmployeeID; ?>">(Switch back to my account)</a>
                    </div>
                </div>
                <?php endif; ?>
                
                <?php if ($loggedInEmployee): ?>
                <div class="login-info">
                    <strong>Current Supervisor:</strong> <?php echo htmlspecialchars($loggedInEmployee['FullName']); ?> 
                    (<?php echo htmlspecialchars($loggedInEmployee['EmployeeCode']); ?>)
                    <?php if ($isAuthorizedSupervisor): ?>
                        <span class="authorized-badge">AUTHORIZED</span>
                    <?php else: ?>
                        <span class="authorized-badge" style="background: #dc3545;">UNAUTHORIZED</span>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
            </div>
            
            <div class="content">
                <div class="main-content">
                    <?php if ($successMsg): ?>
                        <div class="alert alert-success"><?php echo htmlspecialchars($successMsg); ?></div>
                    <?php endif; ?>
                    
                    <?php if ($errorMsg): ?>
                        <div class="alert alert-error"><?php echo htmlspecialchars($errorMsg); ?></div>
                    <?php endif; ?>
                    
                    <?php if (!$isAuthorizedSupervisor): ?>
                    <div class="alert alert-warning">
                        <strong>Warning:</strong> You are viewing this page but are not authorized to approve/reject leaves.
                        Only authorized supervisors (employee codes in the list) can take actions.
                    </div>
                    <?php endif; ?>
                    
                    <!-- Supervisor Selection -->
                    <div class="section">
                        <h2>Supervisor Information</h2>
                        <div class="info-grid">
                            <div class="info-item">
                                <span class="info-label">Supervisor ID:</span>
                                <span class="info-value"><?php echo htmlspecialchars($supervisorID); ?></span>
                            </div>
                            <div class="info-item">
                                <span class="info-label">Supervisor Name:</span>
                                <span class="info-value"><?php echo htmlspecialchars($supervisor['FirstName'] . ' ' . $supervisor['LastName']); ?></span>
                            </div>
                            <div class="info-item">
                                <span class="info-label">Employee Code:</span>
                                <span class="info-value">
                                    <?php echo htmlspecialchars($supervisor['EmployeeCode']); ?>
                                    <?php if ($isAuthorizedSupervisor): ?>
                                        <span class="badge badge-authorized">Authorized</span>
                                    <?php else: ?>
                                        <span class="badge badge-unauthorized">Not Authorized</span>
                                    <?php endif; ?>
                                </span>
                            </div>
                            <div class="info-item">
                                <span class="info-label">L1 Supervisor:</span>
                                <span class="info-value"><?php echo $isL1Supervisor ? 'Yes' : 'No'; ?></span>
                            </div>
                            <div class="info-item">
                                <span class="info-label">L2 Supervisor:</span>
                                <span class="info-value"><?php echo $isL2Supervisor ? 'Yes' : 'No'; ?></span>
                            </div>
                            <div class="info-item">
                                <span class="info-label">2nd Level Supervisor:</span>
                                <span class="info-value"><?php echo $is2ndLevelSupervisor ? 'Yes' : 'No'; ?></span>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Leave Application Details -->
                    <div class="section">
                        <h2>Leave Application Details</h2>
                        <div class="info-grid">
                            <div class="info-item">
                                <span class="info-label">Application ID:</span>
                                <span class="info-value"><?php echo htmlspecialchars($leave['LeaveApplicationID']); ?></span>
                            </div>
                            <div class="info-item">
                                <span class="info-label">Employee:</span>
                                <span class="info-value"><?php echo htmlspecialchars($leave['FirstName'] . ' ' . $leave['LastName'] . ' (' . $leave['EmployeeCode'] . ')'); ?></span>
                            </div>
                            <div class="info-item">
                                <span class="info-label">Employee ID:</span>
                                <span class="info-value"><?php echo htmlspecialchars($applicantEmployeeID); ?></span>
                            </div>
                            <div class="info-item">
                                <span class="info-label">Leave Type:</span>
                                <span class="info-value"><?php echo htmlspecialchars($leave['LeaveTypeName']); ?></span>
                            </div>
                            <div class="info-item">
                                <span class="info-label">Start Date:</span>
                                <span class="info-value"><?php echo htmlspecialchars(date('d-m-Y', strtotime($leave['StartDate']))); ?></span>
                            </div>
                            <div class="info-item">
                                <span class="info-label">End Date:</span>
                                <span class="info-value"><?php echo htmlspecialchars(date('d-m-Y', strtotime($leave['EndDate']))); ?></span>
                            </div>
                            <div class="info-item">
                                <span class="info-label">Total Days:</span>
                                <span class="info-value"><?php echo htmlspecialchars($leave['TotalDays']); ?></span>
                            </div>
                            <div class="info-item">
                                <span class="info-label">Applied Date:</span>
                                <span class="info-value"><?php echo htmlspecialchars(date('d-m-Y H:i', strtotime($leave['AppliedDate']))); ?></span>
                            </div>
                            <div class="info-item">
                                <span class="info-label">Status:</span>
                                <span class="info-value">
                                    <?php 
                                    $statusClass = 'status-pending';
                                    $statusText = 'Pending';
                                    if ($leave['Status'] == 1) {
                                        $statusClass = 'status-l1';
                                        $statusText = 'L1 Approved';
                                    } else if ($leave['Status'] == 2) {
                                        $statusClass = 'status-l2';
                                        $statusText = 'L2 Approved';
                                    } else if ($leave['Status'] == 3) {
                                        $statusClass = 'status-rejected';
                                        $statusText = 'Rejected';
                                    } else if ($leave['Status'] == 4) {
                                        $statusClass = 'status-cancelled';
                                        $statusText = 'Cancelled';
                                    }
                                    ?>
                                    <span class="status <?php echo $statusClass; ?>"><?php echo $statusText; ?></span>
                                </span>
                            </div>
                        </div>
                        <div class="info-item">
                            <span class="info-label">Reason:</span>
                            <span class="info-value"><?php echo htmlspecialchars($leave['Reason']); ?></span>
                        </div>
                    </div>
                    
                    <!-- Approval Status -->
                    <div class="section">
                        <h2>Approval Status</h2>
                        <div class="info-grid">
                            <div class="info-item">
                                <span class="info-label">Employee ID in Approval:</span>
                                <span class="info-value">
                                    <?php echo $approval && $approval['EmployeeID'] ? htmlspecialchars($approval['EmployeeID']) : 'Not saved'; ?>
                                </span>
                            </div>
                            <div class="info-item">
                                <span class="info-label">L1 Approved By:</span>
                                <span class="info-value">
                                    <?php echo $approval && $approval['L1ApprovedBy'] ? htmlspecialchars($approval['L1ApprovedBy']) : 'Pending'; ?>
                                    <?php if ($approval && $approval['L1ApprovedDate']): ?>
                                        <br><small><?php echo htmlspecialchars(date('d-m-Y H:i', strtotime($approval['L1ApprovedDate']))); ?></small>
                                    <?php endif; ?>
                                </span>
                            </div>
                            <div class="info-item">
                                <span class="info-label">L2 Approved By:</span>
                                <span class="info-value">
                                    <?php echo $approval && $approval['L2ApprovedBy'] ? htmlspecialchars($approval['L2ApprovedBy']) : 'Pending'; ?>
                                    <?php if ($approval && $approval['L2ApprovedDate']): ?>
                                        <br><small><?php echo htmlspecialchars(date('d-m-Y H:i', strtotime($approval['L2ApprovedDate']))); ?></small>
                                    <?php endif; ?>
                                </span>
                            </div>
                            <?php if ($approval && $approval['RejectedBy']): ?>
                            <div class="info-item">
                                <span class="info-label">Rejected By:</span>
                                <span class="info-value">
                                    <?php echo htmlspecialchars($approval['RejectedBy']); ?>
                                    <br><small><?php echo htmlspecialchars(date('d-m-Y H:i', strtotime($approval['RejectedDate']))); ?></small>
                                </span>
                            </div>
                            <div class="info-item">
                                <span class="info-label">Rejection Reason:</span>
                                <span class="info-value"><?php echo htmlspecialchars($approval['RejectionReason']); ?></span>
                            </div>
                            <?php endif; ?>
                            <?php if ($approval && $approval['Comments']): ?>
                            <div class="info-item">
                                <span class="info-label">Comments:</span>
                                <span class="info-value"><?php echo htmlspecialchars($approval['Comments']); ?></span>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                    
                    <!-- Approval Form -->
                    <?php if (($canApproveL1 || $canApproveL2 || $canReject) && $isAuthorizedSupervisor): ?>
                    <div class="section">
                        <h2>Take Action</h2>
                        <form method="post" action="">
                            <div class="form-group">
                                <label for="comments">Comments (required):</label>
                                <textarea id="comments" name="comments" rows="3" required placeholder="Enter your comments about this leave application..."></textarea>
                            </div>
                            
                            <div class="form-group">
                                <label for="rejection_reason">Rejection Reason (required if rejecting):</label>
                                <textarea id="rejection_reason" name="rejection_reason" rows="2" placeholder="Please provide reason for rejection if applicable..."></textarea>
                            </div>
                            
                            <div class="button-group">
                                <?php if ($canApproveL1 || $canApproveL2): ?>
                                <button type="submit" name="action" value="approve" class="btn-approve">
                                    <?php echo $canApproveL1 ? 'Approve (L1)' : 'Approve (L2)'; ?>
                                </button>
                                <?php endif; ?>
                                
                                <?php if ($canReject): ?>
                                <button type="submit" name="action" value="reject" class="btn-reject">Reject</button>
                                <?php endif; ?>
                            </div>
                        </form>
                    </div>
                    <?php elseif (!$isAuthorizedSupervisor): ?>
                    <div class="section">
                        <h2>Action Status</h2>
                        <p style="text-align: center; color: #666; padding: 20px;">
                            <strong>No actions available:</strong> You are not authorized to approve/reject leaves. 
                            Only authorized supervisors can take actions.
                        </p>
                    </div>
                    <?php else: ?>
                    <div class="section">
                        <h2>Action Status</h2>
                        <p style="text-align: center; color: #666; padding: 20px;">
                            <strong>No actions available:</strong> This leave application has already been processed or you are not authorized for the next step.
                        </p>
                    </div>
                    <?php endif; ?>
                </div>
                
                <div class="sidebar">
                    <!-- Login Info -->
                    <div class="section">
                        <h2>Your Account</h2>
                        <div class="info-item">
                            <span class="info-label">Username:</span>
                            <span class="info-value"><?php echo htmlspecialchars($username); ?></span>
                        </div>
                        <div class="info-item">
                            <span class="info-label">Employee ID:</span>
                            <span class="info-value"><?php echo $loggedInEmployeeID; ?></span>
                        </div>
                        <?php if ($loggedInEmployee): ?>
                        <div class="info-item">
                            <span class="info-label">Your Name:</span>
                            <span class="info-value"><?php echo htmlspecialchars($loggedInEmployee['FullName']); ?></span>
                        </div>
                        <div class="info-item">
                            <span class="info-label">Employee Code:</span>
                            <span class="info-value">
                                <?php echo htmlspecialchars($loggedInEmployee['EmployeeCode']); ?>
                                <?php if (in_array($loggedInEmployee['EmployeeCode'], $supervisorList)): ?>
                                    <span class="badge badge-authorized">Supervisor</span>
                                <?php endif; ?>
                            </span>
                        </div>
                        <?php endif; ?>
                    </div>
                    
                    <!-- Authorized Supervisors -->
                    <div class="section">
                        <h2>Authorized Supervisors</h2>
                        <div class="supervisor-list">
                            <?php foreach ($authorizedSupervisors as $sup): ?>
                                <?php 
                                $badges = [];
                                if ($sup['SupervisorID_admin'] !== null) $badges[] = '<span class="badge badge-l1">L1</span>';
                                if ($sup['SupervisorID_technical'] !== null) $badges[] = '<span class="badge badge-l2">L2</span>';
                                if ($sup['SupervisorID_2ndLevel'] !== null) $badges[] = '<span class="badge badge-2nd">2nd</span>';
                                ?>
                                <div class="supervisor-item <?php echo $sup['EmployeeID'] == $supervisorID ? 'active' : ''; ?>" 
                                     onclick="window.location.href='?leave_id=<?php echo $leaveApplicationID; ?>&supervisor_id=<?php echo $sup['EmployeeID']; ?><?php echo $manualMode ? '&manual_code=' . urlencode($manualCode) : ''; ?>'">
                                    <strong><?php echo htmlspecialchars($sup['FullName']); ?></strong><br>
                                    <small>Code: <?php echo htmlspecialchars($sup['EmployeeCode']); ?></small>
                                    <?php if (!empty($badges)): ?>
                                        <div class="supervisor-badges">
                                            <?php echo implode(' ', $badges); ?>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        <p style="margin-top: 10px; font-size: 12px; color: #666; text-align: center;">
                            Total: <?php echo count($authorizedSupervisors); ?> authorized supervisor(s)
                        </p>
                    </div>
                    
                    <!-- Quick Links -->
                    <div class="section">
                        <h2>Quick Links</h2>
                        <p><a href="?supervisor_id=<?php echo $loggedInEmployeeID; ?>" class="btn-back" style="width:100%; text-align:center; background:#17a2b8;">View Pending Leaves</a></p>
                        <p><a href="<?php echo BASE_URL; ?>/dashboard.php" class="btn-back" style="width:100%; text-align:center;">← Return to Dashboard</a></p>
                    </div>
                    
                    <?php if (can('admin.access')): ?>
                    <!-- Manual Testing (Admin only) -->
                    <div class="section">
                        <h2>Admin Testing</h2>
                        <form method="get" action="">
                            <input type="hidden" name="leave_id" value="<?php echo $leaveApplicationID; ?>">
                            <div class="form-group">
                                <label for="manual_code_test">Test Supervisor Code:</label>
                                <input type="text" id="manual_code_test" name="manual_code" 
                                       placeholder="Enter supervisor code" style="width: 100%; padding: 8px;">
                            </div>
                            <button type="submit" class="btn-back" style="width:100%; text-align:center; background:#ffc107; color: #212529;">Test as Supervisor</button>
                        </form>
                    </div>
                    <?php endif; ?>
                    
                    <!-- Debug Info -->
                    <div class="debug-info">
                        <strong>Debug Information:</strong><br>
                        Logged-in Employee ID: <?php echo $loggedInEmployeeID; ?><br>
                        Current Supervisor ID: <?php echo $supervisorID; ?><br>
                        Employee Code: <?php echo htmlspecialchars($supervisor['EmployeeCode']); ?><br>
                        Is Authorized: <?php echo $isAuthorizedSupervisor ? 'Yes' : 'No'; ?><br>
                        Leave ID: <?php echo $leaveApplicationID; ?><br>
                        Manual Mode: <?php echo $manualMode ? 'Yes' : 'No'; ?><br>
                        <?php if ($manualMode): ?>
                        Manual Code: <?php echo htmlspecialchars($manualCode); ?><br>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </body>
    </html>
    <?php
}

// ==============================================
// Function to show pending leaves list
// ==============================================
function showPendingLeavesList($conn, $supervisorID, $loggedInEmployeeID, $username, $loggedInEmployee, $manualMode = false, $manualCode = '', $supervisorList = []) {
    // Fetch pending leaves
    $sqlPending = "
        SELECT 
            la.LeaveApplicationID,
            la.StartDate,
            la.EndDate,
            la.TotalDays,
            la.Reason,
            la.AppliedDate,
            la.Status,
            e.FirstName,
            e.LastName,
            e.EmployeeCode,
            e.EmployeeID,
            lt.LeaveTypeName
        FROM [dbPRFAssetMgt].[dbo].[LeaveApplications] la
        LEFT JOIN [dbPRFAssetMgt].[dbo].[Employees] e ON la.EmployeeID = e.EmployeeID
        LEFT JOIN [dbPRFAssetMgt].[dbo].[LeaveTypes] lt ON la.LeaveTypeID = lt.LeaveTypeID
        WHERE la.Status IN (0, 1)  -- Pending or L1 Approved
        ORDER BY la.AppliedDate DESC
    ";
    
    $stmtPending = $conn->prepare($sqlPending);
    $stmtPending->execute();
    $pendingLeaves = $stmtPending->fetchAll(PDO::FETCH_ASSOC);

    // Fetch authorized supervisors for display
    $authorizedSupervisors = [];
    if (!empty($supervisorList)) {
        $placeholders = implode(',', array_fill(0, count($supervisorList), '?'));
        $sqlAuthSupervisors = "
            SELECT 
                EmployeeID,
                FirstName,
                LastName,
                EmployeeCode,
                SupervisorID_admin,
                SupervisorID_technical,
                SupervisorID_2ndLevel,
                FirstName + ' ' + LastName AS FullName
            FROM [dbPRFAssetMgt].[dbo].[Employees]
            WHERE EmployeeCode IN ($placeholders)
            ORDER BY FirstName, LastName
        ";
        
        $stmtAuthSupervisors = $conn->prepare($sqlAuthSupervisors);
        foreach ($supervisorList as $key => $code) {
            $stmtAuthSupervisors->bindValue(($key+1), $code);
        }
        $stmtAuthSupervisors->execute();
        $authorizedSupervisors = $stmtAuthSupervisors->fetchAll(PDO::FETCH_ASSOC);
    }
    ?>
    
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Pending Leave Applications</title>
        <style>
            * {
                margin: 0;
                padding: 0;
                box-sizing: border-box;
            }
            body {
                font-family: Arial, sans-serif;
                background: #f0f2f5;
                padding: 20px;
            }
            .container {
                max-width: 1200px;
                margin: 0 auto;
            }
            .header {
                background: white;
                padding: 20px;
                border-radius: 10px;
                box-shadow: 0 2px 10px rgba(0,0,0,0.1);
                margin-bottom: 20px;
            }
            h1 {
                color: #333;
                margin-bottom: 10px;
            }
            .subtitle {
                color: #666;
                margin-bottom: 15px;
            }
            .alert-info {
                background-color: #d1ecf1;
                color: #0c5460;
                border: 1px solid #bee5eb;
                padding: 12px 15px;
                border-radius: 5px;
                margin-bottom: 20px;
                font-weight: bold;
            }
            .alert-warning {
                background-color: #fff3cd;
                color: #856404;
                border: 1px solid #ffeaa7;
                padding: 12px 15px;
                border-radius: 5px;
                margin-bottom: 20px;
            }
            .content {
                display: grid;
                grid-template-columns: 1fr 300px;
                gap: 20px;
            }
            .main-content {
                background: white;
                padding: 20px;
                border-radius: 10px;
                box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            }
            .sidebar {
                background: white;
                padding: 20px;
                border-radius: 10px;
                box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            }
            .section {
                margin-bottom: 25px;
            }
            .section h2 {
                color: #495057;
                margin-bottom: 15px;
                padding-bottom: 8px;
                border-bottom: 2px solid #dee2e6;
            }
            .leaves-table {
                width: 100%;
                border-collapse: collapse;
            }
            .leaves-table th, .leaves-table td {
                padding: 12px;
                text-align: left;
                border-bottom: 1px solid #dee2e6;
            }
            .leaves-table th {
                background-color: #f8f9fa;
                font-weight: bold;
                color: #495057;
            }
            .leaves-table tr:hover {
                background-color: #f8f9fa;
            }
            .btn-action {
                background: #007bff;
                color: white;
                border: none;
                padding: 6px 12px;
                border-radius: 4px;
                cursor: pointer;
                text-decoration: none;
                display: inline-block;
                font-size: 14px;
            }
            .btn-action:hover {
                background: #0056b3;
            }
            .status {
                display: inline-block;
                padding: 4px 10px;
                border-radius: 20px;
                font-size: 12px;
                font-weight: bold;
            }
            .status-pending { background: #ffc107; color: #333; }
            .status-l1 { background: #17a2b8; color: white; }
            .supervisor-list {
                max-height: 300px;
                overflow-y: auto;
                border: 1px solid #dee2e6;
                border-radius: 5px;
                padding: 10px;
            }
            .supervisor-item {
                padding: 8px;
                border-bottom: 1px solid #eee;
                cursor: pointer;
            }
            .supervisor-item:hover {
                background: #f8f9fa;
            }
            .supervisor-item.active {
                background: #e7f5ff;
                border-left: 4px solid #007bff;
            }
            .supervisor-badges {
                display: flex;
                gap: 5px;
                margin-top: 3px;
                flex-wrap: wrap;
            }
            .badge {
                padding: 2px 6px;
                border-radius: 10px;
                font-size: 10px;
                font-weight: bold;
            }
            .badge-l1 { background: #17a2b8; color: white; }
            .badge-l2 { background: #28a745; color: white; }
            .badge-2nd { background: #6f42c1; color: white; }
            .badge-authorized { background: #28a745; color: white; }
            .btn-back {
                background: #6c757d;
                color: white;
                text-decoration: none;
                padding: 10px 20px;
                border-radius: 5px;
                display: inline-block;
                margin-top: 20px;
            }
            .btn-back:hover {
                background: #5a6268;
            }
            .info-item {
                display: flex;
                justify-content: space-between;
                padding: 8px 0;
                border-bottom: 1px solid #eee;
            }
            .info-label {
                font-weight: bold;
                color: #666;
            }
            .info-value {
                color: #333;
            }
            .login-info {
                background: #e8f4fd;
                padding: 10px;
                border-radius: 5px;
                margin-bottom: 15px;
                border-left: 4px solid #007bff;
            }
            .manual-mode-banner {
                background: #fff3cd;
                border: 1px solid #ffeaa7;
                color: #856404;
                padding: 10px 15px;
                border-radius: 5px;
                margin-bottom: 15px;
                display: flex;
                justify-content: space-between;
                align-items: center;
            }
            .manual-mode-banner a {
                color: #856404;
                text-decoration: underline;
                font-weight: bold;
            }
        </style>
    </head>
    <body>
        <div class="container">
            <div class="header">
                <h1>Pending Leave Applications</h1>
                <p class="subtitle">Logged in as: <?php echo htmlspecialchars($username); ?> (Employee ID: <?php echo $loggedInEmployeeID; ?>)</p>
                
                <?php if ($manualMode && !empty($manualCode)): ?>
                <div class="manual-mode-banner">
                    <div>
                        <strong>Manual Testing Mode Active:</strong> Testing as supervisor with Employee Code: <?php echo htmlspecialchars($manualCode); ?>
                    </div>
                    <div>
                        <a href="?supervisor_id=<?php echo $loggedInEmployeeID; ?>">(Switch back to my account)</a>
                    </div>
                </div>
                <?php endif; ?>
                
                <?php if ($loggedInEmployee): ?>
                <div class="login-info">
                    <strong>Current Supervisor:</strong> <?php echo htmlspecialchars($loggedInEmployee['FullName']); ?> 
                    (<?php echo htmlspecialchars($loggedInEmployee['EmployeeCode']); ?>)
                    <?php if (in_array($loggedInEmployee['EmployeeCode'], $supervisorList)): ?>
                        <span class="badge badge-authorized">AUTHORIZED SUPERVISOR</span>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
            </div>
            
            <div class="content">
                <div class="main-content">
                    <div class="section">
                        <h2>Pending Leave Applications (<?php echo count($pendingLeaves); ?>)</h2>
                        <?php if (count($pendingLeaves) > 0): ?>
                            <table class="leaves-table">
                                <thead>
                                    <tr>
                                        <th>ID</th>
                                        <th>Employee</th>
                                        <th>Emp ID</th>
                                        <th>Leave Type</th>
                                        <th>Start Date</th>
                                        <th>End Date</th>
                                        <th>Total Days</th>
                                        <th>Applied Date</th>
                                        <th>Status</th>
                                        <th>Action</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($pendingLeaves as $leave): ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars($leave['LeaveApplicationID']); ?></td>
                                            <td><?php echo htmlspecialchars($leave['FirstName'] . ' ' . $leave['LastName'] . ' (' . $leave['EmployeeCode'] . ')'); ?></td>
                                            <td><?php echo htmlspecialchars($leave['EmployeeID']); ?></td>
                                            <td><?php echo htmlspecialchars($leave['LeaveTypeName']); ?></td>
                                            <td><?php echo htmlspecialchars(date('d-m-Y', strtotime($leave['StartDate']))); ?></td>
                                            <td><?php echo htmlspecialchars(date('d-m-Y', strtotime($leave['EndDate']))); ?></td>
                                            <td><?php echo htmlspecialchars($leave['TotalDays']); ?></td>
                                            <td><?php echo htmlspecialchars(date('d-m-Y H:i', strtotime($leave['AppliedDate']))); ?></td>
                                            <td>
                                                <?php 
                                                $statusClass = 'status-pending';
                                                $statusText = 'Pending';
                                                if ($leave['Status'] == 1) {
                                                    $statusClass = 'status-l1';
                                                    $statusText = 'L1 Approved';
                                                }
                                                ?>
                                                <span class="status <?php echo $statusClass; ?>"><?php echo $statusText; ?></span>
                                            </td>
                                            <td>
                                                <a href="?leave_id=<?php echo $leave['LeaveApplicationID']; ?>&supervisor_id=<?php echo $supervisorID; ?><?php echo $manualMode ? '&manual_code=' . urlencode($manualCode) : ''; ?>" class="btn-action">Approve/Reject</a>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        <?php else: ?>
                            <p style="text-align: center; color: #666; padding: 20px;">
                                No pending leave applications found.
                            </p>
                        <?php endif; ?>
                    </div>
                </div>
                
                <div class="sidebar">
                    <!-- Your Account Info -->
                    <div class="section">
                        <h2>Your Account</h2>
                        <div class="info-item">
                            <span class="info-label">Username:</span>
                            <span class="info-value"><?php echo htmlspecialchars($username); ?></span>
                        </div>
                        <div class="info-item">
                            <span class="info-label">Employee ID:</span>
                            <span class="info-value"><?php echo $loggedInEmployeeID; ?></span>
                        </div>
                        <?php if ($loggedInEmployee): ?>
                        <div class="info-item">
                            <span class="info-label">Name:</span>
                            <span class="info-value"><?php echo htmlspecialchars($loggedInEmployee['FullName']); ?></span>
                        </div>
                        <div class="info-item">
                            <span class="info-label">Employee Code:</span>
                            <span class="info-value">
                                <?php echo htmlspecialchars($loggedInEmployee['EmployeeCode']); ?>
                                <?php if (in_array($loggedInEmployee['EmployeeCode'], $supervisorList)): ?>
                                    <span class="badge badge-authorized">Supervisor</span>
                                <?php endif; ?>
                            </span>
                        </div>
                        <?php endif; ?>
                    </div>
                    
                    <!-- Authorized Supervisors -->
                    <div class="section">
                        <h2>Authorized Supervisors</h2>
                        <div class="supervisor-list">
                            <?php foreach ($authorizedSupervisors as $sup): ?>
                                <?php 
                                $badges = [];
                                if ($sup['SupervisorID_admin'] !== null) $badges[] = '<span class="badge badge-l1">L1</span>';
                                if ($sup['SupervisorID_technical'] !== null) $badges[] = '<span class="badge badge-l2">L2</span>';
                                if ($sup['SupervisorID_2ndLevel'] !== null) $badges[] = '<span class="badge badge-2nd">2nd</span>';
                                ?>
                                <div class="supervisor-item <?php echo $sup['EmployeeID'] == $supervisorID ? 'active' : ''; ?>" 
                                     onclick="window.location.href='?supervisor_id=<?php echo $sup['EmployeeID']; ?><?php echo $manualMode ? '&manual_code=' . urlencode($manualCode) : ''; ?>'">
                                    <strong><?php echo htmlspecialchars($sup['FullName']); ?></strong><br>
                                    <small>Code: <?php echo htmlspecialchars($sup['EmployeeCode']); ?></small>
                                    <?php if (!empty($badges)): ?>
                                        <div class="supervisor-badges">
                                            <?php echo implode(' ', $badges); ?>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        <p style="margin-top: 10px; font-size: 12px; color: #666; text-align: center;">
                            Total: <?php echo count($authorizedSupervisors); ?> authorized supervisor(s)
                        </p>
                    </div>
                    
                    <?php if (can('admin.access')): ?>
                    <!-- Manual Testing (Admin only) -->
                    <div class="section">
                        <h2>Admin Testing</h2>
                        <form method="get" action="">
                            <div class="form-group">
                                <label for="manual_code_test">Test Supervisor Code:</label>
                                <input type="text" id="manual_code_test" name="manual_code" 
                                       placeholder="Enter supervisor code" style="width: 100%; padding: 8px;">
                            </div>
                            <button type="submit" class="btn-back" style="width:100%; text-align:center; background:#ffc107; color: #212529;">Test as Supervisor</button>
                        </form>
                    </div>
                    <?php endif; ?>
                    
                    <!-- Quick Links -->
                    <div class="section">
                        <h2>Quick Links</h2>
                        <p><a href="<?php echo BASE_URL; ?>/dashboard.php" class="btn-back" style="width:100%; text-align:center;">← Return to Dashboard</a></p>
                    </div>
                </div>
            </div>
        </div>
    </body>
    </html>
    <?php
}

// ==============================================
// Function to show error
// ==============================================
if (!function_exists('showError')) {
    function showError($message) {
        ?>
        <!DOCTYPE html>
        <html lang="en">
        <head>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width, initial-scale=1.0">
            <title>Error</title>
            <style>
                body {
                    font-family: Arial, sans-serif;
                    background: #f0f2f5;
                    display: flex;
                    justify-content: center;
                    align-items: center;
                    height: 100vh;
                    margin: 0;
                }
                .error-container {
                    background: white;
                    padding: 40px;
                    border-radius: 10px;
                    box-shadow: 0 10px 40px rgba(0,0,0,0.1);
                    max-width: 600px;
                    text-align: center;
                }
                h1 {
                    color: #dc3545;
                    margin-bottom: 20px;
                }
                p {
                    color: #666;
                    margin-bottom: 20px;
                    line-height: 1.6;
                }
                .btn {
                    display: inline-block;
                    background: #007bff;
                    color: white;
                    text-decoration: none;
                    padding: 10px 20px;
                    border-radius: 5px;
                    margin: 5px;
                }
                .btn:hover {
                    background: #0056b3;
                }
                .btn-back {
                    background: #6c757d;
                }
                .btn-back:hover {
                    background: #5a6268;
                }
            </style>
        </head>
        <body>
            <div class="error-container">
                <h1>Error</h1>
                <p><?php echo htmlspecialchars($message); ?></p>
                <div>
                    <a href="<?php echo BASE_URL; ?>/dashboard.php" class="btn">Go to Dashboard</a>
                    <a href="javascript:history.back()" class="btn btn-back">Go Back</a>
                </div>
            </div>
        </body>
        </html>
        <?php
        exit;
    }
}
?>