<?php
// pages/leave/leave_approval_supervisor.php
require_once $_SERVER['DOCUMENT_ROOT'] . '/PRF_Leave/init.php';
require_once __DIR__ . '/../../include/header.php';

// Get logged-in employee ID from session
$employeeId = (int)(isset($_SESSION['auth_user']['EmployeeID']) ? $_SESSION['auth_user']['EmployeeID'] : 0);
$username   = isset($_SESSION['auth_user']['username']) ? $_SESSION['auth_user']['username'] : '';

// If no employee ID in session, redirect to login
if ($employeeId === 0) {
    header('Location: ' . BASE_URL . '/login.php');
    exit;
}

// ==============================================
// Check if logged-in user is a supervisor
// ==============================================

// 1. Get logged-in employee information
$sqlEmployee = "
    SELECT EmployeeID, FirstName, LastName, EmployeeCode
    FROM [dbPRFAssetMgt].[dbo].[Employees]
    WHERE EmployeeID = :employee_id
";
$stmtEmployee = $conn->prepare($sqlEmployee);
$stmtEmployee->bindParam(':employee_id', $employeeId, PDO::PARAM_INT);
$stmtEmployee->execute();
$loggedInEmployee = $stmtEmployee->fetch(PDO::FETCH_ASSOC);

if (!$loggedInEmployee) {
    showError("Employee not found in database.");
    exit;
}

$loggedInEmployeeID = $loggedInEmployee['EmployeeID'];
$loggedInEmployeeCode = $loggedInEmployee['EmployeeCode'];

// 2. Check if this employee is a supervisor (appears in any supervisor column)
$isSupervisor = false;

// Check if employee is admin supervisor
$sqlAdmin = "SELECT COUNT(*) as cnt FROM [dbPRFAssetMgt].[dbo].[Employees] WHERE SupervisorID_admin = :emp_id";
$stmtAdmin = $conn->prepare($sqlAdmin);
$stmtAdmin->bindParam(':emp_id', $loggedInEmployeeID, PDO::PARAM_INT);
$stmtAdmin->execute();
$resultAdmin = $stmtAdmin->fetch(PDO::FETCH_ASSOC);
if ($resultAdmin['cnt'] > 0) {
    $isSupervisor = true;
}

// Check if employee is technical supervisor
if (!$isSupervisor) {
    $sqlTech = "SELECT COUNT(*) as cnt FROM [dbPRFAssetMgt].[dbo].[Employees] WHERE SupervisorID_technical = :emp_id";
    $stmtTech = $conn->prepare($sqlTech);
    $stmtTech->bindParam(':emp_id', $loggedInEmployeeID, PDO::PARAM_INT);
    $stmtTech->execute();
    $resultTech = $stmtTech->fetch(PDO::FETCH_ASSOC);
    if ($resultTech['cnt'] > 0) {
        $isSupervisor = true;
    }
}

// Check if employee is 2nd level supervisor
if (!$isSupervisor) {
    $sqlSecond = "SELECT COUNT(*) as cnt FROM [dbPRFAssetMgt].[dbo].[Employees] WHERE SupervisorID_2ndLevel = :emp_id";
    $stmtSecond = $conn->prepare($sqlSecond);
    $stmtSecond->bindParam(':emp_id', $loggedInEmployeeID, PDO::PARAM_INT);
    $stmtSecond->execute();
    $resultSecond = $stmtSecond->fetch(PDO::FETCH_ASSOC);
    if ($resultSecond['cnt'] > 0) {
        $isSupervisor = true;
    }
}

// ==============================================
// If user is NOT a supervisor, show access denied
// ==============================================
if (!$isSupervisor) {
    showAccessDeniedPage($employeeId, $username, $loggedInEmployee);
    exit;
}

// Use logged-in supervisor's ID
$supervisorID = $employeeId;

// ==============================================
// Get selected leave ID for approval (if any)
// ==============================================
$selectedLeaveId = isset($_GET['leave_id']) && is_numeric($_GET['leave_id']) ? (int)$_GET['leave_id'] : 0;
$showApprovalForm = false;
$approvalFormData = null;

// If leave_id is provided and we're showing on same page
if ($selectedLeaveId > 0) {
    $showApprovalForm = true;
    $approvalFormData = getApprovalFormData($conn, $supervisorID, $selectedLeaveId, $loggedInEmployeeID);
}

// Always show the pending leaves list
showPendingLeavesList($conn, $supervisorID, $employeeId, $username, $loggedInEmployee, $showApprovalForm, $approvalFormData);

// ==============================================
// Function to show access denied page
// ==============================================
function showAccessDeniedPage($employeeId, $username, $employeeData) {
    echo "<script>alert('Access Denied: You are not a supervisor.');</script>";
    echo "<script>window.location.href = '" . BASE_URL . "/dashboard.php';</script>";
    exit;
}

// ==============================================
// Function to get approval form data - REVISED
// ==============================================
function getApprovalFormData($conn, $supervisorID, $leaveApplicationID, $loggedInEmployeeID) {
    // Check if supervisor exists and get their information
    $sqlCheck = "
        SELECT 
            EmployeeID,
            FirstName,
            LastName,
            SupervisorID_admin,
            SupervisorID_technical,
            SupervisorID_2ndLevel,
            EmployeeCode
        FROM [dbPRFAssetMgt].[dbo].[Employees]
        WHERE EmployeeID = :empid
    ";

    $stmtCheck = $conn->prepare($sqlCheck);
    $stmtCheck->bindParam(':empid', $supervisorID, PDO::PARAM_INT);
    $stmtCheck->execute();
    $supervisor = $stmtCheck->fetch(PDO::FETCH_ASSOC);

    if (!$supervisor) {
        return ['error' => "Supervisor not found for EmployeeID: " . $supervisorID];
    }

    // Create FullName in PHP
    $supervisor['FullName'] = $supervisor['FirstName'] . ' ' . $supervisor['LastName'];

    // Check if supervisor appears in any supervisor column
    $isAuthorizedSupervisor = false;
    
    // Check each supervisor column separately
    $sqlAdmin = "SELECT COUNT(*) as cnt FROM [dbPRFAssetMgt].[dbo].[Employees] WHERE SupervisorID_admin = :emp_id";
    $stmtAdmin = $conn->prepare($sqlAdmin);
    $stmtAdmin->bindParam(':emp_id', $supervisorID, PDO::PARAM_INT);
    $stmtAdmin->execute();
    $resultAdmin = $stmtAdmin->fetch(PDO::FETCH_ASSOC);
    if ($resultAdmin['cnt'] > 0) {
        $isAuthorizedSupervisor = true;
    }
    
    if (!$isAuthorizedSupervisor) {
        $sqlTech = "SELECT COUNT(*) as cnt FROM [dbPRFAssetMgt].[dbo].[Employees] WHERE SupervisorID_technical = :emp_id";
        $stmtTech = $conn->prepare($sqlTech);
        $stmtTech->bindParam(':emp_id', $supervisorID, PDO::PARAM_INT);
        $stmtTech->execute();
        $resultTech = $stmtTech->fetch(PDO::FETCH_ASSOC);
        if ($resultTech['cnt'] > 0) {
            $isAuthorizedSupervisor = true;
        }
    }
    
    if (!$isAuthorizedSupervisor) {
        $sqlSecond = "SELECT COUNT(*) as cnt FROM [dbPRFAssetMgt].[dbo].[Employees] WHERE SupervisorID_2ndLevel = :emp_id";
        $stmtSecond = $conn->prepare($sqlSecond);
        $stmtSecond->bindParam(':emp_id', $supervisorID, PDO::PARAM_INT);
        $stmtSecond->execute();
        $resultSecond = $stmtSecond->fetch(PDO::FETCH_ASSOC);
        if ($resultSecond['cnt'] > 0) {
            $isAuthorizedSupervisor = true;
        }
    }

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
        return ['error' => "Leave application not found for ID: " . $leaveApplicationID];
    }

    // Store the applicant's EmployeeID
    $applicantEmployeeID = $leave['ApplicantEmployeeID'];
    
    // Check if this supervisor is authorized to approve this specific leave
    $isAuthorizedForThisLeave = false;
    $supervisorType = ''; // Track which type of supervisor this is for the applicant
    
    if ($isAuthorizedSupervisor) {
        // Check DIRECT supervisor relationships ONLY
        $directCount = 0;
        
        // Check admin supervisor
        $sqlAdminDirect = "SELECT COUNT(*) as cnt FROM [dbPRFAssetMgt].[dbo].[Employees] WHERE EmployeeID = :applicant_id AND SupervisorID_admin = :supervisor_id";
        $stmtAdminDirect = $conn->prepare($sqlAdminDirect);
        $stmtAdminDirect->bindParam(':applicant_id', $applicantEmployeeID, PDO::PARAM_INT);
        $stmtAdminDirect->bindParam(':supervisor_id', $supervisorID, PDO::PARAM_INT);
        $stmtAdminDirect->execute();
        $resultAdminDirect = $stmtAdminDirect->fetch(PDO::FETCH_ASSOC);
        if ($resultAdminDirect['cnt'] > 0) {
            $directCount += $resultAdminDirect['cnt'];
            $supervisorType = 'admin'; // This supervisor is the admin supervisor for this applicant
        }
        
        // Check technical supervisor
        $sqlTechDirect = "SELECT COUNT(*) as cnt FROM [dbPRFAssetMgt].[dbo].[Employees] WHERE EmployeeID = :applicant_id AND SupervisorID_technical = :supervisor_id";
        $stmtTechDirect = $conn->prepare($sqlTechDirect);
        $stmtTechDirect->bindParam(':applicant_id', $applicantEmployeeID, PDO::PARAM_INT);
        $stmtTechDirect->bindParam(':supervisor_id', $supervisorID, PDO::PARAM_INT);
        $stmtTechDirect->execute();
        $resultTechDirect = $stmtTechDirect->fetch(PDO::FETCH_ASSOC);
        if ($resultTechDirect['cnt'] > 0) {
            $directCount += $resultTechDirect['cnt'];
            $supervisorType = 'technical'; // This supervisor is the technical supervisor for this applicant
        }
        
        // Check 2nd level supervisor
        $sqlSecondDirect = "SELECT COUNT(*) as cnt FROM [dbPRFAssetMgt].[dbo].[Employees] WHERE EmployeeID = :applicant_id AND SupervisorID_2ndLevel = :supervisor_id";
        $stmtSecondDirect = $conn->prepare($sqlSecondDirect);
        $stmtSecondDirect->bindParam(':applicant_id', $applicantEmployeeID, PDO::PARAM_INT);
        $stmtSecondDirect->bindParam(':supervisor_id', $supervisorID, PDO::PARAM_INT);
        $stmtSecondDirect->execute();
        $resultSecondDirect = $stmtSecondDirect->fetch(PDO::FETCH_ASSOC);
        if ($resultSecondDirect['cnt'] > 0) {
            $directCount += $resultSecondDirect['cnt'];
            $supervisorType = 'second_level'; // This supervisor is the 2nd level supervisor for this applicant
        }
        
        if ($directCount > 0) {
            $isAuthorizedForThisLeave = true;
        }
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

    // Get the variables from the database
    $leaveReason = isset($leave['Reason']) ? $leave['Reason'] : '';
    $rejectionReason = isset($approval['RejectionReason']) ? $approval['RejectionReason'] : '';
    $l1SupervisorComments = isset($approval['Comments']) ? $approval['Comments'] : '';

    // Check if already approved/rejected - REVISED LOGIC
    $canApproveL1 = false;
    $canApproveL2 = false;
    $canReject = false;
    $currentApprovalStatus = 'pending'; // pending, l1_approved, l2_approved, rejected

    // Determine current approval status
    if ($approval) {
        if ($approval['RejectedBy'] !== null) {
            $currentApprovalStatus = 'rejected';
        } elseif ($approval['L2ApprovedBy'] !== null) {
            $currentApprovalStatus = 'l2_approved';
        } elseif ($approval['L1ApprovedBy'] !== null) {
            $currentApprovalStatus = 'l1_approved';
        }
    }

    // Check if this supervisor can approve based on their type
    if ($isAuthorizedSupervisor && $isAuthorizedForThisLeave && $currentApprovalStatus !== 'rejected') {
        switch ($supervisorType) {
            case 'admin':
                // Admin supervisor can approve as L1
                $canApproveL1 = true;
                $canReject = true;
                break;
                
            case 'technical':
            case 'second_level':
                // Technical or 2nd level supervisor can approve as L2
                $canApproveL2 = true;
                $canReject = true;
                break;
        }
        
        // If already approved by this supervisor, disable approval button
        if ($approval) {
            if ($supervisorType === 'admin' && $approval['L1ApprovedBy'] == $supervisorID) {
                $canApproveL1 = false;
            }
            if (($supervisorType === 'technical' || $supervisorType === 'second_level') && $approval['L2ApprovedBy'] == $supervisorID) {
                $canApproveL2 = false;
            }
        }
    }

    // Check supervisor types (for display purposes)
    $isL1Supervisor = ($supervisorType === 'admin');
    $isL2Supervisor = ($supervisorType === 'technical' || $supervisorType === 'second_level');

    return [
        'supervisor' => $supervisor,
        'leave' => $leave,
        'approval' => $approval,
        'isL1Supervisor' => $isL1Supervisor,
        'isL2Supervisor' => $isL2Supervisor,
        'is2ndLevelSupervisor' => ($supervisorType === 'second_level'),
        'isAuthorizedSupervisor' => $isAuthorizedSupervisor,
        'isAuthorizedForThisLeave' => $isAuthorizedForThisLeave,
        'applicantEmployeeID' => $applicantEmployeeID,
        'leaveReason' => $leaveReason,
        'rejectionReason' => $rejectionReason,
        'l1SupervisorComments' => $l1SupervisorComments,
        'canApproveL1' => $canApproveL1,
        'canApproveL2' => $canApproveL2,
        'canReject' => $canReject,
        'supervisorID' => $supervisorID,
        'leaveApplicationID' => $leaveApplicationID,
        'supervisorType' => $supervisorType,
        'currentApprovalStatus' => $currentApprovalStatus
    ];
}

// ==============================================
// Function to get L1 and L2 approval status
// ==============================================
function getL1L2Status($conn, $leaveApplicationID, $employeeID) {
    $status = [
        'l1_status' => 'pending',
        'l2_status' => 'pending',
        'l1_approver' => null,
        'l2_approver' => null,
        'l1_date' => null,
        'l2_date' => null
    ];
    
    try {
        $sql = "
            SELECT 
                L1ApprovedBy,
                L1ApprovedDate,
                L2ApprovedBy,
                L2ApprovedDate,
                RejectedBy,
                RejectedDate
            FROM [dbPRFAssetMgt].[dbo].[LeaveApprovals]
            WHERE LeaveApplicationID = :leave_id
        ";
        
        $stmt = $conn->prepare($sql);
        $stmt->bindParam(':leave_id', $leaveApplicationID, PDO::PARAM_INT);
        $stmt->execute();
        $approval = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($approval) {
            // Check if rejected
            if ($approval['RejectedBy'] !== null) {
                $status['l1_status'] = 'rejected';
                $status['l2_status'] = 'rejected';
            } else {
                // L1 Status
                if ($approval['L1ApprovedBy'] !== null) {
                    $status['l1_status'] = 'approved';
                    $status['l1_approver'] = $approval['L1ApprovedBy'];
                    $status['l1_date'] = $approval['L1ApprovedDate'];
                    
                    // Check if L1 approver is the current supervisor
                    if ($approval['L1ApprovedBy'] == $employeeID) {
                        $status['l1_status'] = 'approved_by_me';
                    }
                }
                
                // L2 Status
                if ($approval['L2ApprovedBy'] !== null) {
                    $status['l2_status'] = 'approved';
                    $status['l2_approver'] = $approval['L2ApprovedBy'];
                    $status['l2_date'] = $approval['L2ApprovedDate'];
                    
                    // Check if L2 approver is the current supervisor
                    if ($approval['L2ApprovedBy'] == $employeeID) {
                        $status['l2_status'] = 'approved_by_me';
                    }
                }
            }
        }
    } catch (PDOException $e) {
        error_log("Error getting L1/L2 status: " . $e->getMessage());
    }
    
    return $status;
}

// ==============================================
// Function to get DIRECT REPORTS only
// ==============================================
function getDirectReports($conn, $supervisorID) {
    $directReports = [];
    
    try {
        // Get direct reports from ALL supervisor columns
        // Admin supervisor reports
        $sqlAdminReports = "SELECT EmployeeID FROM [dbPRFAssetMgt].[dbo].[Employees] WHERE SupervisorID_admin = ?";
        $stmtAdminReports = $conn->prepare($sqlAdminReports);
        $stmtAdminReports->execute([$supervisorID]);
        $adminReports = $stmtAdminReports->fetchAll(PDO::FETCH_COLUMN, 0);
        $directReports = array_merge($directReports, $adminReports);
        
        // Technical supervisor reports
        $sqlTechReports = "SELECT EmployeeID FROM [dbPRFAssetMgt].[dbo].[Employees] WHERE SupervisorID_technical = ?";
        $stmtTechReports = $conn->prepare($sqlTechReports);
        $stmtTechReports->execute([$supervisorID]);
        $techReports = $stmtTechReports->fetchAll(PDO::FETCH_COLUMN, 0);
        $directReports = array_merge($directReports, $techReports);
        
        // 2nd level supervisor reports
        $sqlSecondReports = "SELECT EmployeeID FROM [dbPRFAssetMgt].[dbo].[Employees] WHERE SupervisorID_2ndLevel = ?";
        $stmtSecondReports = $conn->prepare($sqlSecondReports);
        $stmtSecondReports->execute([$supervisorID]);
        $secondReports = $stmtSecondReports->fetchAll(PDO::FETCH_COLUMN, 0);
        $directReports = array_merge($directReports, $secondReports);
        
    } catch (PDOException $e) {
        error_log("Error in getDirectReports: " . $e->getMessage());
    }
    
    return array_unique($directReports);
}

// ==============================================
// Function to show pending leaves list with approval form below
// ==============================================
function showPendingLeavesList($conn, $supervisorID, $loggedInEmployeeID, $username, $loggedInEmployee, $showApprovalForm = false, $approvalData = null) {
    // Initialize variables
    $pendingLeaves = [];
    $approvedLeaves = [];
    $supervisorInfo = null;
    $successMsg = '';
    $errorMsg = '';
    
    // Check if form was submitted
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $showApprovalForm && $approvalData) {
        $action = $_POST['action'];
        $comments = trim($_POST['comments']);
        $rejectReason = isset($_POST['rejection_reason']) ? trim($_POST['rejection_reason']) : null;
        $leaveApplicationID = $_POST['leave_id'];
        
        // Validate inputs
        if (empty($comments)) {
            $errorMsg = 'Comments are required';
        } else if ($action === 'reject' && empty($rejectReason)) {
            $errorMsg = 'Rejection reason is required when rejecting';
        } else if (!$approvalData['isAuthorizedSupervisor'] || !$approvalData['isAuthorizedForThisLeave']) {
            $errorMsg = 'You are not authorized to approve/reject this leave';
        } else {
            try {
                $conn->beginTransaction();
                
                if ($action === 'approve') {
                    if ($approvalData['canApproveL1']) {
                        // L1 Approval (Admin Supervisor)
                        if ($approvalData['approval']) {
                            // Check if L1 already approved by someone else
                            if ($approvalData['approval']['L1ApprovedBy'] !== null && $approvalData['approval']['L1ApprovedBy'] != $supervisorID) {
                                // Another admin already approved, update the record
                                $sql = "
                                    UPDATE [dbPRFAssetMgt].[dbo].[LeaveApprovals]
                                    SET 
                                        L1ApprovedBy = :supervisor_id,
                                        L1ApprovedDate = GETDATE(),
                                        Comments = :comments
                                    WHERE LeaveApplicationID = :leave_id
                                ";
                            } else {
                                // First L1 approval or updating own approval
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
                            $stmt->execute();
                        } else {
                            // Create new approval record for L1
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
                            $stmt->bindParam(':employee_id', $approvalData['applicantEmployeeID'], PDO::PARAM_INT);
                            $stmt->execute();
                        }
                        
                        // Update LeaveApplications status to show L1 approved
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
                    else if ($approvalData['canApproveL2']) {
                        // L2 Approval (Technical or 2nd Level Supervisor)
                        if ($approvalData['approval']) {
                            // Check if L2 already approved by someone else
                            if ($approvalData['approval']['L2ApprovedBy'] !== null && $approvalData['approval']['L2ApprovedBy'] != $supervisorID) {
                                // Another technical supervisor already approved, update the record
                                $sql = "
                                    UPDATE [dbPRFAssetMgt].[dbo].[LeaveApprovals]
                                    SET 
                                        L2ApprovedBy = :supervisor_id,
                                        L2ApprovedDate = GETDATE(),
                                        Comments = :comments
                                    WHERE LeaveApplicationID = :leave_id
                                ";
                            } else {
                                // First L2 approval or updating own approval
                                $sql = "
                                    UPDATE [dbPRFAssetMgt].[dbo].[LeaveApprovals]
                                    SET 
                                        L2ApprovedBy = :supervisor_id,
                                        L2ApprovedDate = GETDATE(),
                                        Comments = :comments
                                    WHERE LeaveApplicationID = :leave_id
                                ";
                            }
                            $stmt = $conn->prepare($sql);
                            $stmt->bindParam(':supervisor_id', $supervisorID, PDO::PARAM_INT);
                            $stmt->bindParam(':leave_id', $leaveApplicationID, PDO::PARAM_INT);
                            $stmt->bindParam(':comments', $comments);
                            $stmt->execute();
                        } else {
                            // Create new approval record for L2 (when no approval record exists yet)
                            $sql = "
                                INSERT INTO [dbPRFAssetMgt].[dbo].[LeaveApprovals]
                                (
                                    LeaveApplicationID,
                                    L2ApprovedBy,
                                    L2ApprovedDate,
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
                            $stmt->bindParam(':employee_id', $approvalData['applicantEmployeeID'], PDO::PARAM_INT);
                            $stmt->execute();
                        }
                        
                        // Update LeaveApplications status to show L2 approved
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
                else if ($action === 'reject' && $approvalData['canReject']) {
                    // Rejection
                    if ($approvalData['approval']) {
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
                        $stmt->bindParam(':employee_id', $approvalData['applicantEmployeeID'], PDO::PARAM_INT);
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
                
                // Refresh the page to show updated status
                $refreshUrl = "?leave_id=" . $leaveApplicationID;
                echo "<script>window.location.href = '$refreshUrl';</script>";
                exit;
                
            } catch (Exception $e) {
                $conn->rollBack();
                $errorMsg = "Error processing request: " . $e->getMessage();
            }
        }
    }
    
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
                SupervisorID_2ndLevel
            FROM [dbPRFAssetMgt].[dbo].[Employees]
            WHERE EmployeeID = ?
        ";
        
        $stmtSupervisor = $conn->prepare($sqlSupervisorInfo);
        $stmtSupervisor->execute([$supervisorID]);
        $supervisorInfo = $stmtSupervisor->fetch(PDO::FETCH_ASSOC);
        
        if ($supervisorInfo) {
            // Create FullName in PHP
            $supervisorInfo['FullName'] = $supervisorInfo['FirstName'] . ' ' . $supervisorInfo['LastName'];
        }
    } catch (PDOException $e) {
        // If supervisor not found, use logged in employee info
        $supervisorInfo = $loggedInEmployee;
        if ($supervisorInfo) {
            $supervisorInfo['FullName'] = $supervisorInfo['FirstName'] . ' ' . $supervisorInfo['LastName'];
        }
    }
    
    // Fetch PENDING leaves for DIRECT REPORTS only
    try {
        // Get PENDING leaves only (Status = 0)
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
            WHERE la.Status = 0  -- Pending only
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
        error_log("Error fetching pending leaves: " . $e->getMessage());
    }
    
    // Fetch APPROVED leaves for DIRECT REPORTS only
    try {
        // Get APPROVED leaves (Status = 1 or 2) and also REJECTED (Status = 3)
        $sqlApproved = "
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
            WHERE la.Status IN (1, 2, 3)  -- L1 Approved, L2 Approved, or Rejected
            AND (
                e.SupervisorID_admin = ? 
                OR e.SupervisorID_technical = ? 
                OR e.SupervisorID_2ndLevel = ?
            )
            ORDER BY la.AppliedDate DESC
        ";
        
        $stmtApproved = $conn->prepare($sqlApproved);
        $stmtApproved->execute([$supervisorID, $supervisorID, $supervisorID]);
        $approvedLeaves = $stmtApproved->fetchAll(PDO::FETCH_ASSOC);
        
    } catch (PDOException $e) {
        // If query fails, show empty list
        $approvedLeaves = [];
        error_log("Error fetching approved leaves: " . $e->getMessage());
    }
    ?>
    
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Leave Applications For Approval</title>
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
                max-width: 1400px;
                margin: 0 auto;
            }
            .header {
                background: white;
                padding: 20px;
                border-radius: 10px;
                box-shadow: 0 2px 10px rgba(0,0,0,0.1);
                margin-bottom: 20px;
                position: relative;
                display: flex;
                justify-content: space-between;
                align-items: flex-start;
                flex-wrap: wrap;
            }
            
            .header-content {
                flex: 1;
                min-width: 300px;
            }
            
            .header-actions {
                margin-top: 10px;
                text-align: right;
            }
            
            .btn-dashboard {
                display: inline-block;
                background: #6c757d;
                color: white;
                text-decoration: none;
                padding: 8px 16px;
                border-radius: 5px;
                font-weight: bold;
                font-size: 14px;
                transition: all 0.3s;
                border: none;
                cursor: pointer;
            }
            
            .btn-dashboard:hover {
                background: #5a6268;
                text-decoration: none;
                color: white;
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
                margin-bottom: 30px;
            }
            .section h2 {
                color: #495057;
                margin-bottom: 15px;
                padding-bottom: 8px;
                border-bottom: 2px solid #dee2e6;
            }
            .section h3 {
                color: #6c757d;
                margin-bottom: 15px;
                font-size: 1.1rem;
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
                min-width: 200px;
            }
            .info-value {
                color: #333;
                flex: 1;
                word-break: break-word;
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
            
            /* L1/L2 Status badges */
            .l1-status { background: #17a2b8; color: white; }
            .l2-status { background: #28a745; color: white; }
            .status-approved { background: #28a745; color: white; }
            .status-approved-by-me { background: #20c997; color: white; font-weight: bold; }
            .status-pending-badge { background: #ffc107; color: #333; }
            .status-rejected-badge { background: #dc3545; color: white; }
            
            .leaves-table {
                width: 100%;
                border-collapse: collapse;
                margin-bottom: 20px;
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
                position: sticky;
                top: 0;
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
            .data-box {
                background: #f8f9fa;
                border: 1px solid #dee2e6;
                border-radius: 5px;
                padding: 15px;
                margin-top: 5px;
                font-size: 14px;
                color: #495057;
                word-break: break-word;
            }
            .data-label {
                font-weight: bold;
                color: #495057;
                margin-bottom: 5px;
                display: block;
            }
            .approval-form-container {
                margin-top: 30px;
                padding: 20px;
                background: #f8f9fa;
                border-radius: 10px;
                border: 1px solid #dee2e6;
            }
            .supervisor-type-badge {
                display: inline-block;
                padding: 3px 8px;
                border-radius: 12px;
                font-size: 11px;
                font-weight: bold;
                margin-left: 5px;
            }
            .badge-admin { background: #17a2b8; color: white; }
            .badge-technical { background: #28a745; color: white; }
            .badge-second-level { background: #6f42c1; color: white; }
            
            .table-container {
                overflow-x: auto;
                margin-bottom: 30px;
            }
            
            .count-badge {
                background: #6c757d;
                color: white;
                padding: 2px 8px;
                border-radius: 10px;
                font-size: 12px;
                margin-left: 5px;
            }
            
            .view-details {
                background: #6c757d;
                color: white;
                border: none;
                padding: 4px 8px;
                border-radius: 4px;
                cursor: pointer;
                text-decoration: none;
                display: inline-block;
                font-size: 12px;
            }
            .view-details:hover {
                background: #5a6268;
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
                .header {
                    flex-direction: column;
                }
                .header-actions {
                    text-align: left;
                    margin-top: 15px;
                }
                .leaves-table {
                    font-size: 12px;
                }
                .leaves-table th, .leaves-table td {
                    padding: 8px;
                }
            }
        </style>
        <script>
            function scrollToForm() {
                document.getElementById('approvalForm').scrollIntoView({ behavior: 'smooth' });
            }
            
            function toggleApprovedTable() {
                var table = document.getElementById('approvedLeavesTable');
                var btn = document.getElementById('toggleApprovedBtn');
                if (table.style.display === 'none') {
                    table.style.display = 'table';
                    btn.textContent = '▲ Hide Approved/Rejected Leaves';
                } else {
                    table.style.display = 'none';
                    btn.textContent = '▼ Show Approved/Rejected Leaves';
                }
            }
            
            // Hide approved table by default
            document.addEventListener('DOMContentLoaded', function() {
                document.getElementById('approvedLeavesTable').style.display = 'none';
            });
        </script>
    </head>
    <body>
        <div class="container">
            <div class="header">
                <div class="header-content">
                    <h1>Leave Applications For Approval</h1>
                    
                    <?php if ($supervisorInfo): ?>
                    <!-- <div class="login-info">
                        <strong>Current Supervisor:</strong> 
                        <?php echo htmlspecialchars(isset($supervisorInfo['FullName']) ? $supervisorInfo['FullName'] : ''); ?> 
                        (<?php echo htmlspecialchars(isset($supervisorInfo['EmployeeCode']) ? $supervisorInfo['EmployeeCode'] : ''); ?>)
                        
                        <?php 
                        // Check if this employee is a supervisor
                        $sqlCheckSupervisor = "SELECT COUNT(*) as cnt FROM [dbPRFAssetMgt].[dbo].[Employees] 
                            WHERE SupervisorID_admin = ? OR SupervisorID_technical = ? OR SupervisorID_2ndLevel = ?";
                        $stmtCheck = $conn->prepare($sqlCheckSupervisor);
                        $stmtCheck->execute([$supervisorID, $supervisorID, $supervisorID]);
                        $checkResult = $stmtCheck->fetch(PDO::FETCH_ASSOC);
                        
                        if ($checkResult['cnt'] > 0): ?>
                            <span class="badge badge-authorized">SUPERVISOR (<?php echo $checkResult['cnt']; ?> direct reports)</span>
                        <?php endif; ?>
                    </div> -->
                    <?php endif; ?>
                </div>
                
                <div class="header-actions">
                    <a href="<?php echo BASE_URL; ?>/dashboard.php" class="btn-dashboard">← Back to Dashboard</a>
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
                    
                    <!-- PENDING LEAVES SECTION -->
                    <div class="section">
                        <h2>
                            Pending Leave Applications
                            <span class="count-badge"><?php echo count($pendingLeaves); ?> applications</span>
                        </h2>

                        <!-- <div class="alert-info">
                            <strong>Note:</strong> These are leave applications from your direct reports that require your action. 
                            Both L1 (Admin) and L2 (Technical) supervisors can approve independently.
                        </div> -->

                        <?php if (count($pendingLeaves) > 0): ?>
                            <div class="table-container">
                                <table class="leaves-table">
                                    <thead>
                                        <tr>
                                            <th style="width: 5%;">ID</th>
                                            <th style="width: 15%;">Employee</th>
                                            <th style="width: 10%;">Leave Type</th>
                                            <th style="width: 10%;">Start Date</th>
                                            <th style="width: 10%;">End Date</th>
                                            <th style="width: 8%;">Days</th>
                                            <th style="width: 12%;">Applied Date</th>
                                            <th style="width: 10%;">L1 Status</th>
                                            <th style="width: 10%;">L2 Status</th>
                                            <th style="width: 10%;">Action</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($pendingLeaves as $leave): 
                                            $l1l2Status = getL1L2Status($conn, $leave['LeaveApplicationID'], $supervisorID);
                                        ?>
                                            <tr>
                                                <!-- Leave ID -->
                                                <td><?php echo htmlspecialchars(isset($leave['LeaveApplicationID']) ? $leave['LeaveApplicationID'] : ''); ?></td>

                                                <!-- Employee Info -->
                                                <td>
                                                    <?php
                                                    echo htmlspecialchars(
                                                        (isset($leave['FirstName']) ? $leave['FirstName'] : '') . ' ' .
                                                        (isset($leave['LastName']) ? $leave['LastName'] : '') . ' (' .
                                                        (isset($leave['EmployeeCode']) ? $leave['LeaveTypeName'] : '') . ')'
                                                    );
                                                    ?>
                                                </td>

                                                <td><?php echo htmlspecialchars(isset($leave['LeaveTypeName']) ? $leave['LeaveTypeName'] : ''); ?></td>

                                                <td>
                                                    <?php echo isset($leave['StartDate']) ? htmlspecialchars(date('d-m-Y', strtotime($leave['StartDate']))) : ''; ?>
                                                </td>

                                                <td>
                                                    <?php echo isset($leave['EndDate']) ? htmlspecialchars(date('d-m-Y', strtotime($leave['EndDate']))) : ''; ?>
                                                </td>

                                                <td><?php echo htmlspecialchars(isset($leave['TotalDays']) ? $leave['TotalDays'] : ''); ?></td>

                                                <td>
                                                    <?php echo isset($leave['AppliedDate']) ? htmlspecialchars(date('d-m-Y H:i', strtotime($leave['AppliedDate']))) : ''; ?>
                                                </td>

                                                <!-- L1 Status -->
                                                <td>
                                                    <?php
                                                    $l1Class = 'status-pending-badge';
                                                    $l1Text = 'Pending';
                                                    
                                                    if ($l1l2Status['l1_status'] === 'approved') {
                                                        $l1Class = 'status-approved';
                                                        $l1Text = 'Approved';
                                                    } elseif ($l1l2Status['l1_status'] === 'approved_by_me') {
                                                        $l1Class = 'status-approved-by-me';
                                                        $l1Text = 'Approved (You)';
                                                    } elseif ($l1l2Status['l1_status'] === 'rejected') {
                                                        $l1Class = 'status-rejected-badge';
                                                        $l1Text = 'Rejected';
                                                    }
                                                    ?>
                                                    <span class="status <?php echo $l1Class; ?>"><?php echo $l1Text; ?></span>
                                                </td>

                                                <!-- L2 Status -->
                                                <td>
                                                    <?php
                                                    $l2Class = 'status-pending-badge';
                                                    $l2Text = 'Pending';
                                                    
                                                    if ($l1l2Status['l2_status'] === 'approved') {
                                                        $l2Class = 'status-approved';
                                                        $l2Text = 'Approved';
                                                    } elseif ($l1l2Status['l2_status'] === 'approved_by_me') {
                                                        $l2Class = 'status-approved-by-me';
                                                        $l2Text = 'Approved (You)';
                                                    } elseif ($l1l2Status['l2_status'] === 'rejected') {
                                                        $l2Class = 'status-rejected-badge';
                                                        $l2Text = 'Rejected';
                                                    }
                                                    ?>
                                                    <span class="status <?php echo $l2Class; ?>"><?php echo $l2Text; ?></span>
                                                </td>

                                                <!-- Action -->
                                                <td>
                                                    <?php
                                                    $leaveId = isset($leave['LeaveApplicationID']) ? (int)$leave['LeaveApplicationID'] : 0;
                                                    $url = '?leave_id=' . $leaveId;
                                                    ?>
                                                    <a href="<?php echo $url; ?>" class="btn-action" onclick="setTimeout(scrollToForm, 100);">
                                                        Approve / Reject
                                                    </a>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php else: ?>
                           <div class="alert alert-warning">
                                <p><strong>No pending leave applications found from your direct reports.</strong></p>
                            </div> 
                        <?php endif; ?>
                    </div>
                    
                    <!-- APPROVED/REJECTED LEAVES SECTION -->
                    <div class="section">
                        <h2>
                            <button type="button" class="btn-action" id="toggleApprovedBtn" onclick="toggleApprovedTable()" style="background: #6c757d; margin-right: 10px;">
                                ▼ Show Approved/Rejected Leaves
                            </button>
                            Processed Leave Applications
                            <span class="count-badge"><?php echo count($approvedLeaves); ?> applications</span>
                        </h2>

                        <div id="approvedLeavesTable" style="display: none;">
                            <?php if (count($approvedLeaves) > 0): ?>
                                <div class="table-container">
                                    <table class="leaves-table">
                                        <thead>
                                            <tr>
                                                <th style="width: 5%;">ID</th>
                                                <th style="width: 15%;">Employee</th>
                                                <th style="width: 10%;">Leave Type</th>
                                                <th style="width: 10%;">Start Date</th>
                                                <th style="width: 10%;">End Date</th>
                                                <th style="width: 8%;">Days</th>
                                                <th style="width: 12%;">Applied Date</th>
                                                <th style="width: 10%;">L1 Status</th>
                                                <th style="width: 10%;">L2 Status</th>
                                                <th style="width: 10%;">Final Status</th>
                                                <th style="width: 5%;">View</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($approvedLeaves as $leave): 
                                                $l1l2Status = getL1L2Status($conn, $leave['LeaveApplicationID'], $supervisorID);
                                            ?>
                                                <tr>
                                                    <!-- Leave ID -->
                                                    <td><?php echo htmlspecialchars(isset($leave['LeaveApplicationID']) ? $leave['LeaveApplicationID'] : ''); ?></td>

                                                    <!-- Employee Info -->
                                                    <td>
                                                        <?php
                                                        echo htmlspecialchars(
                                                            (isset($leave['FirstName']) ? $leave['FirstName'] : '') . ' ' .
                                                            (isset($leave['LastName']) ? $leave['LastName'] : '') . ' (' .
                                                            (isset($leave['EmployeeCode']) ? $leave['EmployeeCode'] : '') . ')'
                                                        );
                                                        ?>
                                                    </td>

                                                    <td><?php echo htmlspecialchars(isset($leave['LeaveTypeName']) ? $leave['LeaveTypeName'] : ''); ?></td>

                                                    <td>
                                                        <?php echo isset($leave['StartDate']) ? htmlspecialchars(date('d-m-Y', strtotime($leave['StartDate']))) : ''; ?>
                                                    </td>

                                                    <td>
                                                        <?php echo isset($leave['EndDate']) ? htmlspecialchars(date('d-m-Y', strtotime($leave['EndDate']))) : ''; ?>
                                                    </td>

                                                    <td><?php echo htmlspecialchars(isset($leave['TotalDays']) ? $leave['TotalDays'] : ''); ?></td>

                                                    <td>
                                                        <?php echo isset($leave['AppliedDate']) ? htmlspecialchars(date('d-m-Y H:i', strtotime($leave['AppliedDate']))) : ''; ?>
                                                    </td>

                                                    <!-- L1 Status -->
                                                    <td>
                                                        <?php
                                                        $l1Class = 'status-pending-badge';
                                                        $l1Text = 'Pending';
                                                        
                                                        if ($l1l2Status['l1_status'] === 'approved') {
                                                            $l1Class = 'status-approved';
                                                            $l1Text = 'Approved';
                                                        } elseif ($l1l2Status['l1_status'] === 'approved_by_me') {
                                                            $l1Class = 'status-approved-by-me';
                                                            $l1Text = 'Approved (You)';
                                                        } elseif ($l1l2Status['l1_status'] === 'rejected') {
                                                            $l1Class = 'status-rejected-badge';
                                                            $l1Text = 'Rejected';
                                                        }
                                                        
                                                        // Add date if available
                                                        if ($l1l2Status['l1_date']) {
                                                            $l1Text .= '<br><small>' . date('d-m-Y', strtotime($l1l2Status['l1_date'])) . '</small>';
                                                        }
                                                        ?>
                                                        <span class="status <?php echo $l1Class; ?>"><?php echo $l1Text; ?></span>
                                                    </td>

                                                    <!-- L2 Status -->
                                                    <td>
                                                        <?php
                                                        $l2Class = 'status-pending-badge';
                                                        $l2Text = 'Pending';
                                                        
                                                        if ($l1l2Status['l2_status'] === 'approved') {
                                                            $l2Class = 'status-approved';
                                                            $l2Text = 'Approved';
                                                        } elseif ($l1l2Status['l2_status'] === 'approved_by_me') {
                                                            $l2Class = 'status-approved-by-me';
                                                            $l2Text = 'Approved (You)';
                                                        } elseif ($l1l2Status['l2_status'] === 'rejected') {
                                                            $l2Class = 'status-rejected-badge';
                                                            $l2Text = 'Rejected';
                                                        }
                                                        
                                                        // Add date if available
                                                        if ($l1l2Status['l2_date']) {
                                                            $l2Text .= '<br><small>' . date('d-m-Y', strtotime($l1l2Status['l2_date'])) . '</small>';
                                                        }
                                                        ?>
                                                        <span class="status <?php echo $l2Class; ?>"><?php echo $l2Text; ?></span>
                                                    </td>

                                                    <!-- Final Status -->
                                                    <td>
                                                        <?php
                                                        $statusClass = 'status-pending';
                                                        $statusText  = 'Pending';

                                                        if (isset($leave['Status'])) {
                                                            switch ($leave['Status']) {
                                                                case 1:
                                                                    $statusClass = 'status-l1';
                                                                    $statusText  = 'L1 Approved';
                                                                    break;
                                                                case 2:
                                                                    $statusClass = 'status-l2';
                                                                    $statusText  = 'L2 Approved';
                                                                    break;
                                                                case 3:
                                                                    $statusClass = 'status-rejected';
                                                                    $statusText  = 'Rejected';
                                                                    break;
                                                            }
                                                        }
                                                        ?>
                                                        <span class="status <?php echo $statusClass; ?>"><?php echo $statusText; ?></span>
                                                    </td>

                                                    <!-- View Details -->
                                                    <td>
                                                        <?php
                                                        $leaveId = isset($leave['LeaveApplicationID']) ? (int)$leave['LeaveApplicationID'] : 0;
                                                        $url = '?leave_id=' . $leaveId;
                                                        ?>
                                                        <a href="<?php echo $url; ?>" class="view-details" onclick="setTimeout(scrollToForm, 100);">
                                                            View
                                                        </a>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            <?php else: ?>
                               <div class="alert alert-info">
                                    <p><strong>No processed leave applications found.</strong></p>
                                </div> 
                            <?php endif; ?>
                        </div>
                    </div>
                    
                    <!-- Approval Form (shown below table) -->
                    <?php if ($showApprovalForm && $approvalData && !isset($approvalData['error'])): ?>
                    <div class="approval-form-container" id="approvalForm">
                        <div class="section">
                            <h2>Leave Approval Form</h2>
                            
                            <?php if (!$approvalData['isAuthorizedSupervisor']): ?>
                            <div class="alert alert-warning">
                                <strong>Warning:</strong> You are viewing this page but are not authorized to approve/reject leaves.
                                Only supervisors (employees who appear in supervisor columns) can take actions.
                            </div>
                            <?php endif; ?>
                            
                            <?php if ($approvalData['isAuthorizedSupervisor'] && !$approvalData['isAuthorizedForThisLeave']): ?>
                            <div class="alert alert-warning">
                                <strong>Warning:</strong> You are a supervisor, but this leave application is not from one of your direct reports.
                                You can view it but cannot take any actions.
                            </div>
                            <?php endif; ?>
                            
                            <!-- Supervisor Information -->
                            <div class="section">
                                <h3>Supervisor Information</h3>
                                <div class="info-grid">
                                    <div class="info-item">
                                        <span class="info-label">Supervisor ID:</span>
                                        <span class="info-value"><?php echo htmlspecialchars($approvalData['supervisorID']); ?></span>
                                    </div>
                                    <div class="info-item">
                                        <span class="info-label">Supervisor Name:</span>
                                        <span class="info-value">
                                            <?php echo htmlspecialchars($approvalData['supervisor']['FullName']); ?>
                                            <?php if ($approvalData['isAuthorizedSupervisor']): ?>
                                                <?php if ($approvalData['supervisorType'] === 'admin'): ?>
                                                    <span class="supervisor-type-badge badge-admin">L1 (Admin)</span>
                                                <?php elseif ($approvalData['supervisorType'] === 'technical'): ?>
                                                    <span class="supervisor-type-badge badge-technical">L2 (Technical)</span>
                                                <?php elseif ($approvalData['supervisorType'] === 'second_level'): ?>
                                                    <span class="supervisor-type-badge badge-second-level">2nd Level</span>
                                                <?php endif; ?>
                                            <?php endif; ?>
                                        </span>
                                    </div>
                                    <div class="info-item">
                                        <span class="info-label">Employee Code:</span>
                                        <span class="info-value">
                                            <?php echo htmlspecialchars($approvalData['supervisor']['EmployeeCode']); ?>
                                            <?php if ($approvalData['isAuthorizedSupervisor']): ?>
                                                <span class="badge badge-authorized">SUPERVISOR</span>
                                            <?php else: ?>
                                                <span class="badge badge-unauthorized">NOT SUPERVISOR</span>
                                            <?php endif; ?>
                                        </span>
                                    </div>
                                    <div class="info-item">
                                        <span class="info-label">Current Approval Status:</span>
                                        <span class="info-value">
                                            <?php 
                                            $statusText = ucfirst(str_replace('_', ' ', $approvalData['currentApprovalStatus']));
                                            $statusClass = 'status-pending';
                                            if ($approvalData['currentApprovalStatus'] === 'l1_approved') {
                                                $statusClass = 'status-l1';
                                            } elseif ($approvalData['currentApprovalStatus'] === 'l2_approved') {
                                                $statusClass = 'status-l2';
                                            } elseif ($approvalData['currentApprovalStatus'] === 'rejected') {
                                                $statusClass = 'status-rejected';
                                            }
                                            ?>
                                            <span class="status <?php echo $statusClass; ?>"><?php echo $statusText; ?></span>
                                        </span>
                                    </div>
                                </div>
                            </div>
                            
                            <!-- Leave Application Details -->
                            <div class="section">
                                <h3>Leave Application Details</h3>
                                <div class="info-grid">
                                    <div class="info-item">
                                        <span class="info-label">Application ID:</span>
                                        <span class="info-value"><?php echo htmlspecialchars($approvalData['leave']['LeaveApplicationID']); ?></span>
                                    </div>
                                    <div class="info-item">
                                        <span class="info-label">Employee:</span>
                                        <span class="info-value"><?php echo htmlspecialchars($approvalData['leave']['FirstName'] . ' ' . $approvalData['leave']['LastName'] . ' (' . $approvalData['leave']['EmployeeCode'] . ')'); ?></span>
                                    </div>
                                    <div class="info-item">
                                        <span class="info-label">Employee ID:</span>
                                        <span class="info-value"><?php echo htmlspecialchars($approvalData['applicantEmployeeID']); ?></span>
                                    </div>
                                    <div class="info-item">
                                        <span class="info-label">Leave Type:</span>
                                        <span class="info-value"><?php echo htmlspecialchars($approvalData['leave']['LeaveTypeName']); ?></span>
                                    </div>
                                    <div class="info-item">
                                        <span class="info-label">Start Date:</span>
                                        <span class="info-value"><?php echo htmlspecialchars(date('d-m-Y', strtotime($approvalData['leave']['StartDate']))); ?></span>
                                    </div>
                                    <div class="info-item">
                                        <span class="info-label">End Date:</span>
                                        <span class="info-value"><?php echo htmlspecialchars(date('d-m-Y', strtotime($approvalData['leave']['EndDate']))); ?></span>
                                    </div>
                                    <div class="info-item">
                                        <span class="info-label">Total Days:</span>
                                        <span class="info-value"><?php echo htmlspecialchars($approvalData['leave']['TotalDays']); ?></span>
                                    </div>
                                    <div class="info-item">
                                        <span class="info-label">Applied Date:</span>
                                        <span class="info-value"><?php echo htmlspecialchars(date('d-m-Y H:i', strtotime($approvalData['leave']['AppliedDate']))); ?></span>
                                    </div>
                                    <div class="info-item">
                                        <span class="info-label">Status:</span>
                                        <span class="info-value">
                                            <?php 
                                            $statusClass = 'status-pending';
                                            $statusText = 'Pending';
                                            if ($approvalData['leave']['Status'] == 1) {
                                                $statusClass = 'status-l1';
                                                $statusText = 'L1 Approved';
                                            } else if ($approvalData['leave']['Status'] == 2) {
                                                $statusClass = 'status-l2';
                                                $statusText = 'L2 Approved';
                                            } else if ($approvalData['leave']['Status'] == 3) {
                                                $statusClass = 'status-rejected';
                                                $statusText = 'Rejected';
                                            } else if ($approvalData['leave']['Status'] == 4) {
                                                $statusClass = 'status-cancelled';
                                                $statusText = 'Cancelled';
                                            }
                                            ?>
                                            <span class="status <?php echo $statusClass; ?>"><?php echo $statusText; ?></span>
                                        </span>
                                    </div>
                                </div>
                                
                                <!-- Leave Reason -->
                                <div class="info-item">
                                    <span class="info-label">Leave Reason (Applicant):</span>
                                    <span class="info-value">
                                        <div class="data-box">
                                            <?php echo !empty($approvalData['leaveReason']) ? nl2br(htmlspecialchars($approvalData['leaveReason'])) : '<span style="color: #6c757d; font-style: italic;">No reason provided</span>'; ?>
                                        </div>
                                    </span>
                                </div>
                                
                                <!-- Previous Approvals -->
                                <?php if ($approvalData['approval']): ?>
                                <div class="info-item">
                                    <span class="info-label">Previous Approvals:</span>
                                    <span class="info-value">
                                        <div class="data-box">
                                            <?php if ($approvalData['approval']['L1ApprovedBy']): ?>
                                                <strong>L1 Approved By:</strong> 
                                                <?php 
                                                // Fetch L1 approver name
                                                $sqlL1Name = "SELECT FirstName, LastName FROM [dbPRFAssetMgt].[dbo].[Employees] WHERE EmployeeID = ?";
                                                $stmtL1 = $conn->prepare($sqlL1Name);
                                                $stmtL1->execute([$approvalData['approval']['L1ApprovedBy']]);
                                                $l1Approver = $stmtL1->fetch(PDO::FETCH_ASSOC);
                                                echo $l1Approver ? htmlspecialchars($l1Approver['FirstName'] . ' ' . $l1Approver['LastName']) : 'Unknown';
                                                ?>
                                                on <?php echo htmlspecialchars(date('d-m-Y H:i', strtotime($approvalData['approval']['L1ApprovedDate']))); ?>
                                                <br>
                                            <?php endif; ?>
                                            
                                            <?php if ($approvalData['approval']['L2ApprovedBy']): ?>
                                                <strong>L2 Approved By:</strong> 
                                                <?php 
                                                // Fetch L2 approver name
                                                $sqlL2Name = "SELECT FirstName, LastName FROM [dbPRFAssetMgt].[dbo].[Employees] WHERE EmployeeID = ?";
                                                $stmtL2 = $conn->prepare($sqlL2Name);
                                                $stmtL2->execute([$approvalData['approval']['L2ApprovedBy']]);
                                                $l2Approver = $stmtL2->fetch(PDO::FETCH_ASSOC);
                                                echo $l2Approver ? htmlspecialchars($l2Approver['FirstName'] . ' ' . $l2Approver['LastName']) : 'Unknown';
                                                ?>
                                                on <?php echo htmlspecialchars(date('d-m-Y H:i', strtotime($approvalData['approval']['L2ApprovedDate']))); ?>
                                            <?php endif; ?>
                                            
                                            <?php if (!$approvalData['approval']['L1ApprovedBy'] && !$approvalData['approval']['L2ApprovedBy']): ?>
                                                <span style="color: #6c757d; font-style: italic;">No previous approvals</span>
                                            <?php endif; ?>
                                        </div>
                                    </span>
                                </div>
                                <?php endif; ?>
                            </div>
                            
                            <!-- Approval Form -->
                            <?php if (($approvalData['canApproveL1'] || $approvalData['canApproveL2'] || $approvalData['canReject']) && $approvalData['isAuthorizedSupervisor'] && $approvalData['isAuthorizedForThisLeave']): ?>
                            <div class="section">
                                <h3>Take Action</h3>
                                <form method="post" action="">
                                    <input type="hidden" name="leave_id" value="<?php echo $approvalData['leaveApplicationID']; ?>">
                                    
                                    <div class="form-group">
                                        <label for="comments">Your Comments (required):</label>
                                        <textarea id="comments" name="comments" rows="3" required placeholder="Enter your comments about this leave application..."><?php echo htmlspecialchars($approvalData['l1SupervisorComments']); ?></textarea>
                                    </div>
                                    
                                    <div class="form-group">
                                        <label for="rejection_reason">Rejection Reason (required if rejecting):</label>
                                        <textarea id="rejection_reason" name="rejection_reason" rows="2" placeholder="Please provide reason for rejection if applicable..."><?php echo htmlspecialchars($approvalData['rejectionReason']); ?></textarea>
                                    </div>
                                    
                                    <div class="button-group">
                                        <?php if ($approvalData['canApproveL1'] || $approvalData['canApproveL2']): ?>
                                        <button type="submit" name="action" value="approve" class="btn-approve">
                                            <?php 
                                            if ($approvalData['canApproveL1']) {
                                                echo 'Approve (L1 - Admin)';
                                            } elseif ($approvalData['canApproveL2']) {
                                                if ($approvalData['supervisorType'] === 'technical') {
                                                    echo 'Approve (L2 - Technical)';
                                                } else {
                                                    echo 'Approve (2nd Level)';
                                                }
                                            }
                                            ?>
                                        </button>
                                        <?php endif; ?>
                                        
                                        <?php if ($approvalData['canReject']): ?>
                                        <button type="submit" name="action" value="reject" class="btn-reject">Reject</button>
                                        <?php endif; ?>
                                    </div>
                                </form>
                            </div>
                            <?php elseif (!$approvalData['isAuthorizedSupervisor'] || !$approvalData['isAuthorizedForThisLeave']): ?>
                            <div class="section">
                                <h3>Action Status</h3>
                                <p style="text-align: center; color: #666; padding: 20px;">
                                    <strong>No actions available:</strong> 
                                    <?php if (!$approvalData['isAuthorizedSupervisor']): ?>
                                        You are not a supervisor. Only employees who appear in supervisor columns can take actions.
                                    <?php else: ?>
                                        This leave application is not from one of your direct reports.
                                    <?php endif; ?>
                                </p>
                            </div>
                            <?php else: ?>
                            <div class="section">
                                <h3>Action Status</h3>
                                <p style="text-align: center; color: #666; padding: 20px;">
                                    <strong>No actions available:</strong> 
                                    <?php if ($approvalData['supervisorType'] === 'admin' && $approvalData['approval'] && $approvalData['approval']['L1ApprovedBy'] == $approvalData['supervisorID']): ?>
                                        You have already approved this leave as L1 supervisor.
                                    <?php elseif (($approvalData['supervisorType'] === 'technical' || $approvalData['supervisorType'] === 'second_level') && $approvalData['approval'] && $approvalData['approval']['L2ApprovedBy'] == $approvalData['supervisorID']): ?>
                                        You have already approved this leave as L2 supervisor.
                                    <?php elseif ($approvalData['currentApprovalStatus'] === 'rejected'): ?>
                                        This leave application has been rejected.
                                    <?php else: ?>
                                        This leave application has already been processed or you are not authorized for the next step.
                                    <?php endif; ?>
                                </p>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php elseif ($showApprovalForm && isset($approvalData['error'])): ?>
                    <div class="approval-form-container">
                        <div class="alert alert-error">
                            <?php echo htmlspecialchars($approvalData['error']); ?>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        
        <?php if ($showApprovalForm && $approvalData && !isset($approvalData['error'])): ?>
        <script>
            // Scroll to form automatically when page loads with approval form
            document.addEventListener('DOMContentLoaded', function() {
                setTimeout(function() {
                    document.getElementById('approvalForm').scrollIntoView({ behavior: 'smooth' });
                }, 300);
            });
        </script>
        <?php endif; ?>
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
require_once __DIR__ . '/../../include/footer.php';
?>