<?php
// pages/leave/leave_approval_supervisor.php
require_once $_SERVER['DOCUMENT_ROOT'] . '/PRF_Leave/init.php';

// Get logged-in employee ID from session using centralized function
$employeeId = get_current_employee_id($conn, false);
$username = isset($_SESSION['auth_user']['username']) ? $_SESSION['auth_user']['username'] : '';

// If no employee ID, redirect to login
if (!$employeeId) {
    header('Location: ' . BASE_URL . '/login.php');
    exit;
}

// ==============================================
// VALIDATE AND SANITIZE INPUT PARAMETERS
// ==============================================

// Function to sanitize numeric input
function sanitizeNumericParam($param) {
    if (!isset($param)) {
        return null;
    }
    
    // Remove any whitespace, including spaces, tabs, newlines
    $cleaned = trim($param);
    
    // Remove all non-numeric characters except minus sign for negative numbers
    $cleaned = preg_replace('/[^0-9\-]/', '', $cleaned);
    
    // If empty after cleaning, return null
    if ($cleaned === '' || $cleaned === '-') {
        return null;
    }
    
    // Convert to integer
    return (int) $cleaned;
}

// Sanitize GET parameters
$leaveApplicationID = isset($_GET['leave_id']) ? sanitizeNumericParam($_GET['leave_id']) : null;
$supervisorID = isset($_GET['supervisor_id']) ? sanitizeNumericParam($_GET['supervisor_id']) : null;
$manualEmployeeId = isset($_GET['manual_id']) ? sanitizeNumericParam($_GET['manual_id']) : null;

// ==============================================
// AUTOMATICALLY DETECT SUPERVISORS
// Check if logged-in user is a supervisor in the database
// ==============================================

// First, get the logged-in user's details including supervisor roles
$sqlEmployee = "
    SELECT 
        e.EmployeeID,
        e.FirstName,
        e.LastName,
        e.EmployeeCode,
        e.SupervisorID_admin,
        e.SupervisorID_technical,
        e.SupervisorID_2ndLevel,
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

// Check if user is a supervisor (has supervisor roles assigned)
$isL1Supervisor = !empty($loggedInEmployee['SupervisorID_admin']);
$isL2Supervisor = !empty($loggedInEmployee['SupervisorID_technical']);
$is2ndLevelSupervisor = !empty($loggedInEmployee['SupervisorID_2ndLevel']);

// User is a supervisor if they have any supervisor role
$isSupervisor = ($isL1Supervisor || $isL2Supervisor || $is2ndLevelSupervisor);

// ==============================================
// Manual override for testing (optional)
// ==============================================
$manualSupervisorMode = false;

if ($manualEmployeeId !== null) {
    $manualSupervisorMode = true;
    
    // Fetch employee by ID
    $sqlManual = "
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
        WHERE EmployeeID = :employee_id
    ";
    
    $stmtManual = $conn->prepare($sqlManual);
    $stmtManual->bindParam(':employee_id', $manualEmployeeId, PDO::PARAM_INT);
    $stmtManual->execute();
    $manualEmployee = $stmtManual->fetch(PDO::FETCH_ASSOC);
    
    if ($manualEmployee) {
        // Check if manual employee is a supervisor
        $manualIsL1Supervisor = !empty($manualEmployee['SupervisorID_admin']);
        $manualIsL2Supervisor = !empty($manualEmployee['SupervisorID_technical']);
        $manualIs2ndLevelSupervisor = !empty($manualEmployee['SupervisorID_2ndLevel']);
        $manualIsSupervisor = ($manualIsL1Supervisor || $manualIsL2Supervisor || $manualIs2ndLevelSupervisor);
        
        if ($manualIsSupervisor) {
            $loggedInEmployee = $manualEmployee;
            $employeeId = $manualEmployee['EmployeeID'];
            $isSupervisor = true;
            $isL1Supervisor = $manualIsL1Supervisor;
            $isL2Supervisor = $manualIsL2Supervisor;
            $is2ndLevelSupervisor = $manualIs2ndLevelSupervisor;
        } else {
            showError("Employee ID '$manualEmployeeId' is not a supervisor (no supervisor roles assigned).");
        }
    } else {
        showError("Employee with ID '$manualEmployeeId' not found in database.");
    }
}

// ==============================================
// If user is NOT a supervisor, show access denied
// ==============================================
if (!$isSupervisor && !$manualSupervisorMode) {
    showAccessDeniedPage($employeeId, $username, $loggedInEmployee);
    exit;
}

// Set supervisor ID if not provided in GET
if ($supervisorID === null) {
    $supervisorID = $employeeId;
}

// Validate supervisor ID if provided in GET
if ($supervisorID !== null && $supervisorID != $employeeId) {
    // Verify this ID is a supervisor
    $sqlCheck = "SELECT SupervisorID_admin, SupervisorID_technical, SupervisorID_2ndLevel FROM dbo.Employees WHERE EmployeeID = :id";
    $stmtCheck = $conn->prepare($sqlCheck);
    $stmtCheck->execute([':id' => $supervisorID]);
    $checkSupervisor = $stmtCheck->fetch();
    
    if (!$checkSupervisor || (empty($checkSupervisor['SupervisorID_admin']) && empty($checkSupervisor['SupervisorID_technical']) && empty($checkSupervisor['SupervisorID_2ndLevel']))) {
        showError("Employee ID $supervisorID is not a supervisor.");
        exit;
    }
}

// If leave_id is provided and valid, show approval page
if ($leaveApplicationID !== null && $leaveApplicationID > 0) {
    showApprovalPage($conn, $supervisorID, $leaveApplicationID, $employeeId, $username, $loggedInEmployee, $manualSupervisorMode, $manualEmployeeId);
} else {
    // Show list of pending leaves for employees assigned to this supervisor
    showPendingLeavesList($conn, $supervisorID, $employeeId, $username, $loggedInEmployee, $manualSupervisorMode, $manualEmployeeId);
}

// ==============================================
// Function to show access denied page
// ==============================================
function showAccessDeniedPage($employeeId, $username, $employeeData) {
    // ... (keep the existing function as is, no changes needed)
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
            .supervisor-role-box {
                background: #e8f4fd;
                padding: 20px;
                border-radius: 8px;
                margin: 20px 0;
                border-left: 4px solid #007bff;
            }
            .role-check {
                margin: 5px 0;
                padding: 5px;
            }
            .role-check.yes {
                color: #28a745;
            }
            .role-check.no {
                color: #dc3545;
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
            input[type="number"] {
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
                
                <div class="supervisor-role-box">
                    <h3>Supervisor Role Check:</h3>
                    <div class="role-check <?php echo !empty($employeeData['SupervisorID_admin']) ? 'yes' : 'no'; ?>">
                        ✓ L1 Supervisor (Admin): <?php echo !empty($employeeData['SupervisorID_admin']) ? 'YES - Assigned' : 'NO - Not assigned'; ?>
                    </div>
                    <div class="role-check <?php echo !empty($employeeData['SupervisorID_technical']) ? 'yes' : 'no'; ?>">
                        ✓ L2 Supervisor (Technical): <?php echo !empty($employeeData['SupervisorID_technical']) ? 'YES - Assigned' : 'NO - Not assigned'; ?>
                    </div>
                    <div class="role-check <?php echo !empty($employeeData['SupervisorID_2ndLevel']) ? 'yes' : 'no'; ?>">
                        ✓ 2nd Level Supervisor: <?php echo !empty($employeeData['SupervisorID_2ndLevel']) ? 'YES - Assigned' : 'NO - Not assigned'; ?>
                    </div>
                    <p style="margin-top: 10px; font-size: 12px; color: #666;">
                        <em>To be a supervisor, you need at least one supervisor role assigned in the database.</em>
                    </p>
                </div>
                
                <?php if (can('admin.access')): ?>
                <div class="manual-form">
                    <h3>Admin Testing Mode</h3>
                    <p>As an admin, you can test supervisor functions by entering a supervisor's Employee ID:</p>
                    
                    <form method="get" action="">
                        <div class="form-group">
                            <label for="manual_id">Supervisor Employee ID:</label>
                            <input type="number" id="manual_id" name="manual_id" 
                                   placeholder="Enter supervisor's Employee ID" required min="1">
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
                    <em>If you believe you should have supervisor access, please contact your system administrator to assign supervisor roles to your account.</em>
                </p>
            </div>
        </div>
    </body>
    </html>
    <?php
    exit;
}

// ==============================================
// Function to show approval page (UPDATED)
// ==============================================
function showApprovalPage($conn, $supervisorID, $leaveApplicationID, $loggedInEmployeeID, $username, $loggedInEmployee, $manualMode = false, $manualEmployeeId = null) {
    // Validate inputs
    if (!is_numeric($supervisorID) || $supervisorID <= 0) {
        showError("Invalid supervisor ID.");
        return;
    }
    
    if (!is_numeric($leaveApplicationID) || $leaveApplicationID <= 0) {
        showError("Invalid leave application ID.");
        return;
    }
    
    // Check if supervisor exists and get their supervisor types
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

    // Check supervisor roles
    $isL1Supervisor = !empty($supervisor['SupervisorID_admin']);
    $isL2Supervisor = !empty($supervisor['SupervisorID_technical']);
    $is2ndLevelSupervisor = !empty($supervisor['SupervisorID_2ndLevel']);
    
    // User is authorized as a supervisor if they have any supervisor role
    $isAuthorizedSupervisor = ($isL1Supervisor || $isL2Supervisor || $is2ndLevelSupervisor);

    // Fetch leave application details with EmployeeID
    $sqlLeave = "
        SELECT 
            la.*,
            e.FirstName,
            e.LastName,
            e.EmployeeCode,
            e.EmployeeID as ApplicantEmployeeID,
            e.SupervisorID_admin,
            e.SupervisorID_technical,
            e.SupervisorID_2ndLevel,
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
    
    // Check if this supervisor is authorized to approve this specific leave
    // Based on the applicant's supervisor assignments
    $isAuthorizedForThisLeave = false;
    $authorizedRole = '';
    
    // Make sure to check for empty strings or null values
    $applicantL1Supervisor = isset($leave['SupervisorID_admin']) ? $leave['SupervisorID_admin'] : null;
    $applicantL2Supervisor = isset($leave['SupervisorID_technical']) ? $leave['SupervisorID_technical'] : null;
    $applicant2ndLevelSupervisor = isset($leave['SupervisorID_2ndLevel']) ? $leave['SupervisorID_2ndLevel'] : null;
    
    // Convert to integers for comparison
    $applicantL1Supervisor = $applicantL1Supervisor !== null && $applicantL1Supervisor !== '' ? (int)$applicantL1Supervisor : null;
    $applicantL2Supervisor = $applicantL2Supervisor !== null && $applicantL2Supervisor !== '' ? (int)$applicantL2Supervisor : null;
    $applicant2ndLevelSupervisor = $applicant2ndLevelSupervisor !== null && $applicant2ndLevelSupervisor !== '' ? (int)$applicant2ndLevelSupervisor : null;
    
    if ($isL1Supervisor && $applicantL1Supervisor !== null && $applicantL1Supervisor == $supervisorID) {
        $isAuthorizedForThisLeave = true;
        $authorizedRole = 'L1 Supervisor';
    } elseif ($isL2Supervisor && $applicantL2Supervisor !== null && $applicantL2Supervisor == $supervisorID) {
        $isAuthorizedForThisLeave = true;
        $authorizedRole = 'L2 Supervisor';
    } elseif ($is2ndLevelSupervisor && $applicant2ndLevelSupervisor !== null && $applicant2ndLevelSupervisor == $supervisorID) {
        $isAuthorizedForThisLeave = true;
        $authorizedRole = '2nd Level Supervisor';
    }

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

    // Check L1 approval status - only if authorized for this leave and is L1 supervisor
    if ($isL1Supervisor && $isAuthorizedSupervisor && $isAuthorizedForThisLeave && $approval) {
        if ($approval['L1ApprovedBy'] === null && $approval['RejectedBy'] === null) {
            $canApproveL1 = true;
            $canReject = true;
        }
    }

    // Check L2 approval status - only if authorized for this leave and is L2 supervisor
    if ($isL2Supervisor && $isAuthorizedSupervisor && $isAuthorizedForThisLeave && $approval) {
        if ($approval['L1ApprovedBy'] !== null && $approval['L2ApprovedBy'] === null && $approval['RejectedBy'] === null) {
            $canApproveL2 = true;
            $canReject = true;
        }
    }

    // If no approval record exists, create one for L1 approval
    if (!$approval && $isL1Supervisor && $isAuthorizedSupervisor && $isAuthorizedForThisLeave) {
        $canApproveL1 = true;
        $canReject = true;
    }

    // Form submit handling
    $successMsg = '';
    $errorMsg = '';

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $action = isset($_POST['action']) ? trim($_POST['action']) : '';
        $comments = isset($_POST['comments']) ? trim($_POST['comments']) : '';
        $rejectReason = isset($_POST['rejection_reason']) ? trim($_POST['rejection_reason']) : null;
        
        // Validate inputs
        if (empty($comments)) {
            $errorMsg = 'Comments are required';
        } else if ($action === 'reject' && empty($rejectReason)) {
            $errorMsg = 'Rejection reason is required when rejecting';
        } else if (!$isAuthorizedSupervisor || !$isAuthorizedForThisLeave) {
            $errorMsg = 'You are not authorized to approve/reject this leave';
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
                grid-template-columns: 1fr;
                gap: 20px;
            }
            .main-content {
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
            .badge {
                padding: 2px 6px;
                border-radius: 10px;
                font-size: 10px;
                font-weight: bold;
            }
            .badge-authorized { background: #28a745; color: white; }
            .badge-unauthorized { background: #dc3545; color: white; }
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
            .supervisor-roles {
                margin-top: 5px;
                font-size: 12px;
                color: #666;
            }
            .role-badge {
                display: inline-block;
                background: #e9ecef;
                padding: 2px 6px;
                border-radius: 10px;
                margin-right: 5px;
                font-size: 11px;
            }
            .role-badge.active {
                background: #28a745;
                color: white;
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
                
                <?php if ($manualMode && !empty($manualEmployeeId)): ?>
                <div class="manual-mode-banner">
                    <div>
                        <strong>Manual Testing Mode Active:</strong> Testing as supervisor with Employee ID: <?php echo htmlspecialchars($manualEmployeeId); ?>
                    </div>
                    <div>
                        <a href="?supervisor_id=<?php echo $loggedInEmployeeID; ?>">(Switch back to my account)</a>
                    </div>
                </div>
                <?php endif; ?>
                
                <?php if ($supervisor): ?>
                <div class="login-info">
                    <strong>Current Supervisor:</strong> <?php echo htmlspecialchars($supervisor['FullName']); ?> 
                    (<?php echo htmlspecialchars($supervisor['EmployeeCode']); ?>)
                    <?php if ($isAuthorizedSupervisor): ?>
                        <span class="badge badge-authorized">SUPERVISOR</span>
                    <?php else: ?>
                        <span class="badge badge-unauthorized">NOT SUPERVISOR</span>
                    <?php endif; ?>
                    
                    <div class="supervisor-roles">
                        <strong>Roles:</strong>
                        <span class="role-badge <?php echo $isL1Supervisor ? 'active' : ''; ?>">L1 Supervisor</span>
                        <span class="role-badge <?php echo $isL2Supervisor ? 'active' : ''; ?>">L2 Supervisor</span>
                        <span class="role-badge <?php echo $is2ndLevelSupervisor ? 'active' : ''; ?>">2nd Level</span>
                    </div>
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
                        Only supervisors (users with supervisor roles assigned) can take actions.
                    </div>
                    <?php endif; ?>
                    
                    <?php if ($isAuthorizedSupervisor && !$isAuthorizedForThisLeave): ?>
                    <div class="alert alert-warning">
                        <strong>Warning:</strong> You are a supervisor, but this leave application is not from an employee assigned to you.
                        You can view it but cannot take any actions.
                    </div>
                    <?php endif; ?>
                    
                    <!-- Supervisor Information -->
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
                                <span class="info-value"><?php echo htmlspecialchars($supervisor['EmployeeCode']); ?></span>
                            </div>
                            <div class="info-item">
                                <span class="info-label">Authorization Status:</span>
                                <span class="info-value">
                                    <?php if ($isAuthorizedForThisLeave): ?>
                                        <span style="color: #28a745; font-weight: bold;">✓ Authorized for this leave</span>
                                        <span class="role-badge active"><?php echo $authorizedRole; ?></span>
                                    <?php else: ?>
                                        <span style="color: #dc3545; font-weight: bold;">✗ Not authorized for this leave</span>
                                    <?php endif; ?>
                                </span>
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
                    
                    <!-- Supervisor Assignment Information -->
                    <div class="section">
                        <h2>Employee Supervisor Assignment</h2>
                        <div class="info-grid">
                            <div class="info-item">
                                <span class="info-label">L1 Supervisor ID:</span>
                                <span class="info-value">
    <?php
    echo htmlspecialchars(
        isset($leave['SupervisorID_admin']) && $leave['SupervisorID_admin'] !== ''
            ? $leave['SupervisorID_admin']
            : 'Not assigned'
    );
    ?>

    <?php if (
        isset($leave['SupervisorID_admin']) &&
        $leave['SupervisorID_admin'] !== '' &&
        (int)$leave['SupervisorID_admin'] == $supervisorID &&
        $isL1Supervisor
    ): ?>
        <span class="badge badge-authorized">Your Assignment</span>
    <?php endif; ?>
</span>

                            </div>
                            <div class="info-item">
                                <span class="info-label">L2 Supervisor ID:</span>
                                <span class="info-value">
    <?php
    echo htmlspecialchars(
        isset($leave['SupervisorID_technical']) && $leave['SupervisorID_technical'] !== ''
            ? $leave['SupervisorID_technical']
            : 'Not assigned'
    );
    ?>

    <?php if (
        isset($leave['SupervisorID_technical']) &&
        $leave['SupervisorID_technical'] !== '' &&
        (int)$leave['SupervisorID_technical'] == $supervisorID &&
        $isL2Supervisor
    ): ?>
        <span class="badge badge-authorized">Your Assignment</span>
    <?php endif; ?>
</span>

                            </div>
                            <div class="info-item">
                                <span class="info-label">2nd Level Supervisor ID:</span>
                                <span class="info-value">
    <?php
    echo htmlspecialchars(
        isset($leave['SupervisorID_2ndLevel']) && $leave['SupervisorID_2ndLevel'] !== ''
            ? $leave['SupervisorID_2ndLevel']
            : 'Not assigned'
    );
    ?>

    <?php if (
        isset($leave['SupervisorID_2ndLevel']) &&
        $leave['SupervisorID_2ndLevel'] !== '' &&
        (int)$leave['SupervisorID_2ndLevel'] == $supervisorID &&
        $is2ndLevelSupervisor
    ): ?>
        <span class="badge badge-authorized">Your Assignment</span>
    <?php endif; ?>
</span>

                            </div>
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
                    <?php if (($canApproveL1 || $canApproveL2 || $canReject) && $isAuthorizedSupervisor && $isAuthorizedForThisLeave): ?>
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
                    <?php elseif (!$isAuthorizedSupervisor || !$isAuthorizedForThisLeave): ?>
                    <div class="section">
                        <h2>Action Status</h2>
                        <p style="text-align: center; color: #666; padding: 20px;">
                            <strong>No actions available:</strong> 
                            <?php if (!$isAuthorizedSupervisor): ?>
                                You are not authorized to approve/reject leaves. Only supervisors can take actions.
                            <?php else: ?>
                                This leave application is not from an employee assigned to you.
                            <?php endif; ?>
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
                    
                    <!-- Quick Links -->
                    <div class="section">
                        <h2>Quick Links</h2>
                        <p><a href="?supervisor_id=<?php echo $loggedInEmployeeID; ?>" class="btn-back" style="background:#17a2b8;">View Pending Leaves</a></p>
                        <p><a href="<?php echo BASE_URL; ?>/dashboard.php" class="btn-back">← Return to Dashboard</a></p>
                    </div>
                </div>
            </div>
        </div>
    </body>
    </html>
    <?php
}

// ==============================================
// Function to show pending leaves list (UPDATED)
// ==============================================
function showPendingLeavesList($conn, $supervisorID, $loggedInEmployeeID, $username, $loggedInEmployee, $manualMode = false, $manualEmployeeId = null) {
    // Validate supervisor ID
    if (!is_numeric($supervisorID) || $supervisorID <= 0) {
        showError("Invalid supervisor ID.");
        return;
    }
    
    // Initialize variables
    $pendingLeaves = [];
    $supervisorInfo = null;
    
    // Fetch the supervisor's own information first
    try {
        $sqlSupervisorInfo = "
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
            WHERE EmployeeID = ?
        ";
        
        $stmtSupervisor = $conn->prepare($sqlSupervisorInfo);
        $stmtSupervisor->execute([$supervisorID]);
        $supervisorInfo = $stmtSupervisor->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        // If supervisor not found, use logged in employee info
        $supervisorInfo = $loggedInEmployee;
    }
    
    // Fetch pending leaves for employees assigned to this supervisor
    try {
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
                e.SupervisorID_admin,
                e.SupervisorID_technical,
                e.SupervisorID_2ndLevel,
                lt.LeaveTypeName
            FROM [dbPRFAssetMgt].[dbo].[LeaveApplications] la
            INNER JOIN [dbPRFAssetMgt].[dbo].[Employees] e ON la.EmployeeID = e.EmployeeID
            LEFT JOIN [dbPRFAssetMgt].[dbo].[LeaveTypes] lt ON la.LeaveTypeID = lt.LeaveTypeID
            WHERE la.Status IN (0, 1)
            AND (
                e.SupervisorID_admin = ?
                OR e.SupervisorID_technical = ?
                OR e.SupervisorID_2ndLevel = ?
            )
            ORDER BY la.AppliedDate DESC
        ";
        
        $stmtPending = $conn->prepare($sqlPending);
        $stmtPending->execute([$supervisorID, $supervisorID, $supervisorID]);
        $pendingLeaves = $stmtPending->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        // If query fails, show empty list
        $pendingLeaves = [];
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
            .badge {
                padding: 2px 6px;
                border-radius: 10px;
                font-size: 10px;
                font-weight: bold;
            }
            .badge-authorized { background: #28a745; color: white; }
            .supervisor-types {
                margin-top: 10px;
                padding: 10px;
                background: #f8f9fa;
                border-radius: 5px;
            }
            .supervisor-type {
                display: inline-block;
                margin-right: 10px;
                padding: 4px 8px;
                background: #e9ecef;
                border-radius: 4px;
                font-size: 12px;
            }
            .supervisor-type.active {
                background: #28a745;
                color: white;
            }
        </style>
    </head>
    <body>
        <div class="container">
            <div class="header">
                <h1>Pending Leave Applications</h1>
                <p class="subtitle">Logged in as: <?php echo htmlspecialchars($username); ?> (Employee ID: <?php echo $loggedInEmployeeID; ?>)</p>
                
                <?php if ($manualMode && !empty($manualEmployeeId)): ?>
                <div class="manual-mode-banner">
                    <div>
                        <strong>Manual Testing Mode Active:</strong> Testing as supervisor with Employee ID: <?php echo htmlspecialchars($manualEmployeeId); ?>
                    </div>
                    <div>
                        <a href="?supervisor_id=<?php echo $loggedInEmployeeID; ?>">(Switch back to my account)</a>
                    </div>
                </div>
                <?php endif; ?>
                
                <?php if ($supervisorInfo): ?>
                <div class="login-info">
                    <strong>Current Supervisor:</strong>
                    <?php echo htmlspecialchars(
                        isset($supervisorInfo['FullName']) ? $supervisorInfo['FullName'] : ''
                    ); ?>
                    (
                    <?php echo htmlspecialchars(
                        isset($supervisorInfo['EmployeeCode']) ? $supervisorInfo['EmployeeCode'] : ''
                    ); ?>
                    )
                </div>

                    <div class="supervisor-types">
                        <strong>Supervisor Types:</strong>
                        <?php if (isset($supervisorInfo['SupervisorID_admin']) && $supervisorInfo['SupervisorID_admin'] !== null): ?>
                            <span class="supervisor-type active">L1 Supervisor</span>
                        <?php endif; ?>
                        <?php if (isset($supervisorInfo['SupervisorID_technical']) && $supervisorInfo['SupervisorID_technical'] !== null): ?>
                            <span class="supervisor-type active">L2 Supervisor</span>
                        <?php endif; ?>
                        <?php if (isset($supervisorInfo['SupervisorID_2ndLevel']) && $supervisorInfo['SupervisorID_2ndLevel'] !== null): ?>
                            <span class="supervisor-type active">2nd Level Supervisor</span>
                        <?php endif; ?>
                        <?php if (empty($supervisorInfo['SupervisorID_admin']) && empty($supervisorInfo['SupervisorID_technical']) && empty($supervisorInfo['SupervisorID_2ndLevel'])): ?>
                            <span class="supervisor-type" style="background: #dc3545; color: white;">No Supervisor Roles</span>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endif; ?>
            </div>
            
            <div class="content">
                <div class="section">
                    <h2>Pending Leave Applications from Your Suprvisee (<?php echo count($pendingLeaves); ?>)</h2>
                    
                    <?php if (isset($supervisorInfo['SupervisorID_admin']) || isset($supervisorInfo['SupervisorID_technical']) || isset($supervisorInfo['SupervisorID_2ndLevel'])): ?>
                    <!-- <div class="alert-info">
                        <strong>Supervisor View:</strong> You are viewing leave applications only from employees assigned to you.
                    </div> -->
                    <?php endif; ?>
                    
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
    <td>
        <?php echo htmlspecialchars(isset($leave['LeaveApplicationID']) ? $leave['LeaveApplicationID'] : ''); ?>
    </td>

    <td>
        <?php
        $firstName = isset($leave['FirstName']) ? $leave['FirstName'] : '';
        $lastName  = isset($leave['LastName']) ? $leave['LastName'] : '';
        $empCode   = isset($leave['EmployeeCode']) ? $leave['EmployeeCode'] : '';

        echo htmlspecialchars(trim($firstName . ' ' . $lastName . ' (' . $empCode . ')'));
        ?>
    </td>

    <td>
        <?php echo htmlspecialchars(isset($leave['EmployeeID']) ? $leave['EmployeeID'] : ''); ?>
    </td>

    <td>
        <?php echo htmlspecialchars(isset($leave['LeaveTypeName']) ? $leave['LeaveTypeName'] : ''); ?>
    </td>

    <td>
        <?php
        echo isset($leave['StartDate'])
            ? htmlspecialchars(date('d-m-Y', strtotime($leave['StartDate'])))
            : '';
        ?>
    </td>

    <td>
        <?php
        echo isset($leave['EndDate'])
            ? htmlspecialchars(date('d-m-Y', strtotime($leave['EndDate'])))
            : '';
        ?>
    </td>

    <td>
        <?php echo htmlspecialchars(isset($leave['TotalDays']) ? $leave['TotalDays'] : ''); ?>
    </td>

    <td>
        <?php
        echo isset($leave['AppliedDate'])
            ? htmlspecialchars(date('d-m-Y H:i', strtotime($leave['AppliedDate'])))
            : '';
        ?>
    </td>

    <td>

                                            <?php 
                                            $statusClass = 'status-pending';
                                            $statusText = 'Pending';
                                            if (isset($leave['Status']) && $leave['Status'] == 1) {
                                                $statusClass = 'status-l1';
                                                $statusText = 'L1 Approved';
                                            }
                                            ?>
                                            <span class="status <?php echo $statusClass; ?>"><?php echo $statusText; ?></span>
                                        </td>
                                        <td>
    <a href="?leave_id=<?php echo isset($leave['LeaveApplicationID']) ? $leave['LeaveApplicationID'] : ''; ?>
        &supervisor_id=<?php echo $supervisorID; ?>
        <?php echo $manualMode ? '&manual_id=' . urlencode($manualEmployeeId) : ''; ?>"
       class="btn-action">
        Approve/Reject
    </a>
</td>

                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php else: ?>
                        <p style="text-align: center; color: #666; padding: 20px;">
                            No pending leave applications found for employees assigned to you.
                        </p>
                    <?php endif; ?>
                </div>
                
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
        <span class="info-value">
            <?php
            echo htmlspecialchars(
                isset($loggedInEmployee['FullName']) ? $loggedInEmployee['FullName'] : ''
            );
            ?>
        </span>
    </div>

    <div class="info-item">
        <span class="info-label">Employee Code:</span>
        <span class="info-value">
            <?php
            echo htmlspecialchars(
                isset($loggedInEmployee['EmployeeCode']) ? $loggedInEmployee['EmployeeCode'] : ''
            );
            ?>
        </span>
    </div>
                    <div class="info-item">
                        <span class="info-label">Supervisor Status:</span>
                        <span class="info-value">
                            <?php if ((isset($loggedInEmployee['SupervisorID_admin']) && $loggedInEmployee['SupervisorID_admin'] !== null) || 
                                      (isset($loggedInEmployee['SupervisorID_technical']) && $loggedInEmployee['SupervisorID_technical'] !== null) || 
                                      (isset($loggedInEmployee['SupervisorID_2ndLevel']) && $loggedInEmployee['SupervisorID_2ndLevel'] !== null)): ?>
                                <span style="color: #28a745; font-weight: bold;">✓ Supervisor</span>
                            <?php else: ?>
                                <span style="color: #dc3545; font-weight: bold;">✗ Not a Supervisor</span>
                            <?php endif; ?>
                        </span>
                    </div>
                    <?php endif; ?>
                </div>
                
                <!-- Quick Links -->
                <div class="section">
                    <h2>Quick Links</h2>
                    <p><a href="<?php echo BASE_URL; ?>/dashboard.php" class="btn-back">← Return to Dashboard</a></p>
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