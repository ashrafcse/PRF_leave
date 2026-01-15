<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/PRF_Leave/init.php';

// Remove session check for testing
if (isset($_GET['supervisor_id']) && is_numeric($_GET['supervisor_id'])) {
    $supervisorID = (int) $_GET['supervisor_id'];
} else {
    // Default supervisor ID for testing
    $supervisorID = 1;
}

// If leave_id is provided, show approval page
if (isset($_GET['leave_id']) && is_numeric($_GET['leave_id'])) {
    $leaveApplicationID = (int) $_GET['leave_id'];
    showApprovalPage($conn, $supervisorID, $leaveApplicationID);
} else {
    // Show list of pending leaves
    showPendingLeavesList($conn, $supervisorID);
}

function showApprovalPage($conn, $supervisorID, $leaveApplicationID) {
    // Check if supervisor exists
    $sqlCheck = "
        SELECT 
            EmployeeID,
            FirstName,
            LastName,
            SupervisorID_admin,
            SupervisorID_technical
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

    // Check if supervisor is either L1 or L2 supervisor
    $isL1Supervisor = ($supervisor['SupervisorID_admin'] !== null);
    $isL2Supervisor = ($supervisor['SupervisorID_technical'] !== null);

    // Fetch leave application details
    $sqlLeave = "
        SELECT 
            la.*,
            e.FirstName,
            e.LastName,
            e.EmployeeCode,
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
            Comments
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
    if ($isL1Supervisor && $approval) {
        if ($approval['L1ApprovedBy'] === null && $approval['RejectedBy'] === null) {
            $canApproveL1 = true;
            $canReject = true;
        }
    }

    // Check L2 approval status
    if ($isL2Supervisor && $approval) {
        if ($approval['L1ApprovedBy'] !== null && $approval['L2ApprovedBy'] === null && $approval['RejectedBy'] === null) {
            $canApproveL2 = true;
            $canReject = true;
        }
    }

    // If no approval record exists, create one for L1 approval
    if (!$approval && $isL1Supervisor) {
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
        } else {
            try {
                $conn->beginTransaction();
                
                if ($action === 'approve') {
                    if ($canApproveL1) {
                        // L1 Approval
                        if ($approval) {
                            // Update existing approval record
                            $sql = "
                                UPDATE [dbPRFAssetMgt].[dbo].[LeaveApprovals]
                                SET 
                                    L1ApprovedBy = :supervisor_id,
                                    L1ApprovedDate = GETDATE(),
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
                                SET Status = 1
                                WHERE LeaveApplicationID = :leave_id
                            ";
                        } else {
                            // Create new approval record for L1
                            $sql = "
                                INSERT INTO [dbPRFAssetMgt].[dbo].[LeaveApprovals]
                                (
                                    LeaveApplicationID,
                                    L1ApprovedBy,
                                    L1ApprovedDate,
                                    Comments
                                )
                                VALUES
                                (
                                    :leave_id,
                                    :supervisor_id,
                                    GETDATE(),
                                    :comments
                                )
                            ";
                            $stmt = $conn->prepare($sql);
                            $stmt->bindParam(':leave_id', $leaveApplicationID, PDO::PARAM_INT);
                            $stmt->bindParam(':supervisor_id', $supervisorID, PDO::PARAM_INT);
                            $stmt->bindParam(':comments', $comments);
                            $stmt->execute();
                            
                            // Update LeaveApplications status
                            $sqlUpdate = "
                                UPDATE [dbPRFAssetMgt].[dbo].[LeaveApplications]
                                SET Status = 1
                                WHERE LeaveApplicationID = :leave_id
                            ";
                        }
                        $successMsg = "Leave application L1 approved successfully.";
                    } 
                    else if ($canApproveL2) {
                        // L2 Approval
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
                        
                        $successMsg = "Leave application L2 approved successfully.";
                    }
                    
                    // Update LeaveApplications status
                    if (isset($sqlUpdate)) {
                        $stmtUpdate = $conn->prepare($sqlUpdate);
                        $stmtUpdate->bindParam(':leave_id', $leaveApplicationID, PDO::PARAM_INT);
                        $stmtUpdate->execute();
                    }
                    
                } 
                else if ($action === 'reject' && $canReject) {
                    // Rejection
                    if ($approval) {
                        // Update existing approval record
                        $sql = "
                            UPDATE [dbPRFAssetMgt].[dbo].[LeaveApprovals]
                            SET 
                                RejectedBy = :supervisor_id,
                                RejectedDate = GETDATE(),
                                RejectionReason = :reason,
                                Comments = :comments
                            WHERE LeaveApplicationID = :leave_id
                        ";
                    } else {
                        // Create new approval record for rejection
                        $sql = "
                            INSERT INTO [dbPRFAssetMgt].[dbo].[LeaveApprovals]
                            (
                                LeaveApplicationID,
                                RejectedBy,
                                RejectedDate,
                                RejectionReason,
                                Comments
                            )
                            VALUES
                            (
                                :leave_id,
                                :supervisor_id,
                                GETDATE(),
                                :reason,
                                :comments
                            )
                        ";
                    }
                    
                    $stmt = $conn->prepare($sql);
                    $stmt->bindParam(':supervisor_id', $supervisorID, PDO::PARAM_INT);
                    $stmt->bindParam(':leave_id', $leaveApplicationID, PDO::PARAM_INT);
                    $stmt->bindParam(':reason', $rejectReason);
                    $stmt->bindParam(':comments', $comments);
                    $stmt->execute();
                    
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

    // Fetch list of supervisors for testing
    $sqlSupervisors = "
        SELECT 
            EmployeeID,
            FirstName + ' ' + LastName AS FullName,
            SupervisorID_admin,
            SupervisorID_technical
        FROM [dbPRFAssetMgt].[dbo].[Employees]
        WHERE SupervisorID_admin IS NOT NULL OR SupervisorID_technical IS NOT NULL
        ORDER BY FirstName, LastName
    ";

    $stmtSupervisors = $conn->prepare($sqlSupervisors);
    $stmtSupervisors->execute();
    $supervisorsList = $stmtSupervisors->fetchAll(PDO::FETCH_ASSOC);
    ?>
    
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Leave Approval (Testing Mode)</title>
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
            .debug-info {
                margin-top: 30px;
                padding: 15px;
                background: #f8f9fa;
                border: 1px dashed #ccc;
                border-radius: 5px;
                font-size: 12px;
                color: #666;
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
                <h1>Leave Approval System (Testing Mode)</h1>
                <p class="subtitle">No session authentication required for testing</p>
                <div class="alert alert-info">
                    <strong>Note:</strong> This is a testing version without session authentication. For production, enable session checking.
                </div>
            </div>
            
            <div class="content">
                <div class="main-content">
                    <?php if ($successMsg): ?>
                        <div class="alert alert-success"><?php echo htmlspecialchars($successMsg); ?></div>
                    <?php endif; ?>
                    
                    <?php if ($errorMsg): ?>
                        <div class="alert alert-error"><?php echo htmlspecialchars($errorMsg); ?></div>
                    <?php endif; ?>
                    
                    <!-- Supervisor Selection -->
                    <div class="section">
                        <h2>Select Supervisor (for testing)</h2>
                        <div class="info-grid">
                            <div class="info-item">
                                <span class="info-label">Current Supervisor ID:</span>
                                <span class="info-value"><?php echo htmlspecialchars($supervisorID); ?></span>
                            </div>
                            <div class="info-item">
                                <span class="info-label">Supervisor Name:</span>
                                <span class="info-value"><?php echo htmlspecialchars($supervisor['FirstName'] . ' ' . $supervisor['LastName']); ?></span>
                            </div>
                            <div class="info-item">
                                <span class="info-label">L1 Supervisor:</span>
                                <span class="info-value"><?php echo $isL1Supervisor ? 'Yes' : 'No'; ?></span>
                            </div>
                            <div class="info-item">
                                <span class="info-label">L2 Supervisor:</span>
                                <span class="info-value"><?php echo $isL2Supervisor ? 'Yes' : 'No'; ?></span>
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
                    <?php if ($canApproveL1 || $canApproveL2 || $canReject): ?>
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
                    <!-- Supervisor List -->
                    <div class="section">
                        <h2>Available Supervisors</h2>
                        <div class="supervisor-list">
                            <?php foreach ($supervisorsList as $sup): ?>
                                <div class="supervisor-item <?php echo $sup['EmployeeID'] == $supervisorID ? 'active' : ''; ?>" 
                                     onclick="window.location.href='?leave_id=<?php echo $leaveApplicationID; ?>&supervisor_id=<?php echo $sup['EmployeeID']; ?>'">
                                    <strong><?php echo htmlspecialchars($sup['FullName']); ?></strong><br>
                                    <small>ID: <?php echo htmlspecialchars($sup['EmployeeID']); ?></small><br>
                                    <small>L1: <?php echo $sup['SupervisorID_admin'] ? 'Yes' : 'No'; ?> | 
                                           L2: <?php echo $sup['SupervisorID_technical'] ? 'Yes' : 'No'; ?></small>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    
                    <!-- Quick Links -->
                    <div class="section">
                        <h2>Quick Links</h2>
                        <p><a href="?supervisor_id=<?php echo $supervisorID; ?>" class="btn-back" style="width:100%; text-align:center; background:#17a2b8;">View Pending Leaves</a></p>
                        <p><a href="../../dashboard.php" class="btn-back" style="width:100%; text-align:center;">← Return to Dashboard</a></p>
                    </div>
                    
                    <!-- Debug Info -->
                    <div class="debug-info">
                        <strong>Debug Information:</strong><br>
                        Supervisor ID: <?php echo $supervisorID; ?><br>
                        Leave ID: <?php echo $leaveApplicationID; ?><br>
                        Can Approve L1: <?php echo $canApproveL1 ? 'Yes' : 'No'; ?><br>
                        Can Approve L2: <?php echo $canApproveL2 ? 'Yes' : 'No'; ?><br>
                        Can Reject: <?php echo $canReject ? 'Yes' : 'No'; ?><br>
                        URL: <?php echo $_SERVER['REQUEST_URI']; ?>
                    </div>
                </div>
            </div>
        </div>
    </body>
    </html>
    <?php
}

function showPendingLeavesList($conn, $supervisorID) {
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
    
    // Fetch list of supervisors for testing
    $sqlSupervisors = "
        SELECT 
            EmployeeID,
            FirstName + ' ' + LastName AS FullName,
            SupervisorID_admin,
            SupervisorID_technical
        FROM [dbPRFAssetMgt].[dbo].[Employees]
        WHERE SupervisorID_admin IS NOT NULL OR SupervisorID_technical IS NOT NULL
        ORDER BY FirstName, LastName
    ";
    
    $stmtSupervisors = $conn->prepare($sqlSupervisors);
    $stmtSupervisors->execute();
    $supervisorsList = $stmtSupervisors->fetchAll(PDO::FETCH_ASSOC);
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
        </style>
    </head>
    <body>
        <div class="container">
            <div class="header">
                <h1>Pending Leave Applications</h1>
                <p class="subtitle">No session authentication required for testing</p>
                <div class="alert-info">
                    <strong>Note:</strong> This is a testing version without session authentication. For production, enable session checking.
                </div>
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
                                                <a href="?leave_id=<?php echo $leave['LeaveApplicationID']; ?>&supervisor_id=<?php echo $supervisorID; ?>" class="btn-action">Approve/Reject</a>
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
                    <!-- Current Supervisor Info -->
                    <div class="section">
                        <h2>Current Supervisor</h2>
                        <?php 
                        $sqlCurrent = "SELECT FirstName, LastName FROM [dbPRFAssetMgt].[dbo].[Employees] WHERE EmployeeID = :id";
                        $stmtCurrent = $conn->prepare($sqlCurrent);
                        $stmtCurrent->bindParam(':id', $supervisorID, PDO::PARAM_INT);
                        $stmtCurrent->execute();
                        $currentSupervisor = $stmtCurrent->fetch(PDO::FETCH_ASSOC);
                        ?>
                        <div class="info-item">
                            <span class="info-label">ID:</span>
                            <span class="info-value"><?php echo $supervisorID; ?></span>
                        </div>
                        <div class="info-item">
                            <span class="info-label">Name:</span>
                            <span class="info-value">
                                <?php echo $currentSupervisor ? htmlspecialchars($currentSupervisor['FirstName'] . ' ' . $currentSupervisor['LastName']) : 'Not found'; ?>
                            </span>
                        </div>
                    </div>
                    
                    <!-- Supervisor List -->
                    <div class="section">
                        <h2>Select Supervisor</h2>
                        <div class="supervisor-list">
                            <?php foreach ($supervisorsList as $sup): ?>
                                <div class="supervisor-item <?php echo $sup['EmployeeID'] == $supervisorID ? 'active' : ''; ?>" 
                                     onclick="window.location.href='?supervisor_id=<?php echo $sup['EmployeeID']; ?>'">
                                    <strong><?php echo htmlspecialchars($sup['FullName']); ?></strong><br>
                                    <small>ID: <?php echo htmlspecialchars($sup['EmployeeID']); ?></small><br>
                                    <small>L1: <?php echo $sup['SupervisorID_admin'] ? 'Yes' : 'No'; ?> | 
                                           L2: <?php echo $sup['SupervisorID_technical'] ? 'Yes' : 'No'; ?></small>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    
                    <!-- Quick Links -->
                    <div class="section">
                        <h2>Quick Links</h2>
                        <p><a href="../../dashboard.php" class="btn-back" style="width:100%; text-align:center;">← Return to Dashboard</a></p>
                    </div>
                </div>
            </div>
        </div>
    </body>
    </html>
    <?php
}

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
                <a href="?supervisor_id=1" class="btn">View Pending Leaves</a>
                <a href="../../dashboard.php" class="btn btn-back">Return to Dashboard</a>
            </div>
        </div>
    </body>
    </html>
    <?php
}
?>