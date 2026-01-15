<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/init.php';
require_login();

function h($s){
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

/* Get current employee details using centralized functions */
$employeeId = get_current_employee_id($conn, false);
$username = isset($_SESSION['auth_user']['username']) ? $_SESSION['auth_user']['username'] : '';

if (!$employeeId) {
    echo '<div class="container my-4">';
    echo '<div class="alert alert-danger">';
    echo '<h4><i class="bi bi-exclamation-triangle"></i> Employee Mapping Required</h4>';
    echo '<p>Your account is not linked to any employee record.</p>';
    echo '<p>Username: <strong>' . h($username) . '</strong></p>';
    echo '<a href="logout.php" class="btn btn-primary mt-2">Logout</a>';
    echo '</div>';
    echo '</div>';
    require_once __DIR__ . '/include/footer.php';
    exit;
}

/* ==========================
   CHECK IF CURRENT USER IS SUPERVISOR
========================== */
$isSupervisor = false;
if ($employeeId) {
    try {
        $sql = "SELECT CASE 
                       WHEN EXISTS (
                           SELECT 1 
                           FROM [dbPRFAssetMgt].[dbo].[Employees] 
                           WHERE SupervisorID_admin = ? 
                              OR SupervisorID_technical = ? 
                              OR SupervisorID_2ndLevel = ?
                       ) THEN 1 
                       ELSE 0 
                   END AS is_supervisor";
        
        $stmt = $conn->prepare($sql);
        $stmt->execute([$employeeId, $employeeId, $employeeId]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        $isSupervisor = ($result && $result['is_supervisor'] == 1);
        
    } catch (Exception $e) {
        error_log("Error checking supervisor status in dashboard: " . $e->getMessage());
    }
}

/* ==========================
   FETCH PENDING LEAVES COUNT FOR SUPERVISOR - REVISED
========================== */
$pendingCount = 0;
if ($isSupervisor && $employeeId) {
    $supervisorId = $employeeId;
    
    // Get all direct reports
    $directReportsSql = "
        SELECT EmployeeID 
        FROM [dbPRFAssetMgt].[dbo].[Employees]
        WHERE SupervisorID_admin = :sup1 
           OR SupervisorID_technical = :sup2 
           OR SupervisorID_2ndLevel = :sup3
    ";
    
    $stmtDirectReports = $conn->prepare($directReportsSql);
    $stmtDirectReports->bindValue(':sup1', $supervisorId, PDO::PARAM_INT);
    $stmtDirectReports->bindValue(':sup2', $supervisorId, PDO::PARAM_INT);
    $stmtDirectReports->bindValue(':sup3', $supervisorId, PDO::PARAM_INT);
    $stmtDirectReports->execute();
    $directReports = $stmtDirectReports->fetchAll(PDO::FETCH_COLUMN, 0);
    
    // Get supervisor type for each direct report
    $adminSupervisorIds = [];
    $techSupervisorIds = [];
    $secondLevelIds = [];
    
    foreach ($directReports as $employeeIdValue) {
        // Check if current supervisor is admin supervisor for this employee
        $sqlCheckAdmin = "SELECT COUNT(*) as cnt FROM [dbPRFAssetMgt].[dbo].[Employees] WHERE EmployeeID = :emp_id AND SupervisorID_admin = :sup_id";
        $stmtCheckAdmin = $conn->prepare($sqlCheckAdmin);
        $stmtCheckAdmin->bindParam(':emp_id', $employeeIdValue, PDO::PARAM_INT);
        $stmtCheckAdmin->bindParam(':sup_id', $supervisorId, PDO::PARAM_INT);
        $stmtCheckAdmin->execute();
        $resultAdmin = $stmtCheckAdmin->fetch(PDO::FETCH_ASSOC);
        
        if ($resultAdmin['cnt'] > 0) {
            $adminSupervisorIds[] = $employeeIdValue;
            continue; // Skip checking other supervisor types
        }
        
        // Check if current supervisor is technical supervisor for this employee
        $sqlCheckTech = "SELECT COUNT(*) as cnt FROM [dbPRFAssetMgt].[dbo].[Employees] WHERE EmployeeID = :emp_id AND SupervisorID_technical = :sup_id";
        $stmtCheckTech = $conn->prepare($sqlCheckTech);
        $stmtCheckTech->bindParam(':emp_id', $employeeIdValue, PDO::PARAM_INT);
        $stmtCheckTech->bindParam(':sup_id', $supervisorId, PDO::PARAM_INT);
        $stmtCheckTech->execute();
        $resultTech = $stmtCheckTech->fetch(PDO::FETCH_ASSOC);
        
        if ($resultTech['cnt'] > 0) {
            $techSupervisorIds[] = $employeeIdValue;
            continue; // Skip checking other supervisor types
        }
        
        // Check if current supervisor is 2nd level supervisor for this employee
        $sqlCheckSecond = "SELECT COUNT(*) as cnt FROM [dbPRFAssetMgt].[dbo].[Employees] WHERE EmployeeID = :emp_id AND SupervisorID_2ndLevel = :sup_id";
        $stmtCheckSecond = $conn->prepare($sqlCheckSecond);
        $stmtCheckSecond->bindParam(':emp_id', $employeeIdValue, PDO::PARAM_INT);
        $stmtCheckSecond->bindParam(':sup_id', $supervisorId, PDO::PARAM_INT);
        $stmtCheckSecond->execute();
        $resultSecond = $stmtCheckSecond->fetch(PDO::FETCH_ASSOC);
        
        if ($resultSecond['cnt'] > 0) {
            $secondLevelIds[] = $employeeIdValue;
        }
    }
    
    // Now count leaves that are pending for this supervisor
    $pendingCount = 0;
    
    // Helper function to check if a leave is pending for a supervisor
    function isLeavePendingForSupervisorDashboard($conn, $leaveApplicationID, $supervisorID, $supervisorType) {
        try {
            // Get approval status
            $sql = "
                SELECT 
                    L1ApprovedBy,
                    L2ApprovedBy,
                    RejectedBy
                FROM [dbPRFAssetMgt].[dbo].[LeaveApprovals]
                WHERE LeaveApplicationID = :leave_id
            ";
            
            $stmt = $conn->prepare($sql);
            $stmt->bindParam(':leave_id', $leaveApplicationID, PDO::PARAM_INT);
            $stmt->execute();
            $approval = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$approval) {
                // No approval record exists, so leave is pending for everyone
                return true;
            }
            
            // Check if rejected
            if ($approval['RejectedBy'] !== null) {
                return false; // Rejected leaves are not pending
            }
            
            // Check based on supervisor type
            if ($supervisorType === 'admin') {
                // For L1 supervisor, check if they haven't approved yet
                if ($approval['L1ApprovedBy'] == $supervisorID) {
                    return false; // Already approved by this L1 supervisor
                }
                return true; // L1 supervisor can still approve
            } 
            elseif ($supervisorType === 'technical' || $supervisorType === 'second_level') {
                // For L2 supervisor, check if they haven't approved yet
                if ($approval['L2ApprovedBy'] == $supervisorID) {
                    return false; // Already approved by this L2 supervisor
                }
                return true; // L2 supervisor can still approve
            }
            
            return false;
            
        } catch (PDOException $e) {
            error_log("Error checking pending status in dashboard: " . $e->getMessage());
            return false;
        }
    }
    
    // Count leaves for admin supervisors
    if (!empty($adminSupervisorIds)) {
        $placeholders = str_repeat('?,', count($adminSupervisorIds) - 1) . '?';
        
        // Get all leaves from admin direct reports
        $sqlAdminLeaves = "
            SELECT la.LeaveApplicationID
            FROM [dbPRFAssetMgt].[dbo].[LeaveApplications] la
            WHERE la.EmployeeID IN ($placeholders)
            AND la.Status IN (0, 1, 2) -- Pending, L1 Approved, or L2 Approved (but not rejected)
        ";
        
        $stmtAdminLeaves = $conn->prepare($sqlAdminLeaves);
        $stmtAdminLeaves->execute($adminSupervisorIds);
        $adminLeaves = $stmtAdminLeaves->fetchAll(PDO::FETCH_COLUMN, 0);
        
        // Check each leave if it's pending for this admin supervisor
        foreach ($adminLeaves as $leaveId) {
            if (isLeavePendingForSupervisorDashboard($conn, $leaveId, $supervisorId, 'admin')) {
                $pendingCount++;
            }
        }
    }
    
    // Count leaves for technical supervisors
    if (!empty($techSupervisorIds)) {
        $placeholders = str_repeat('?,', count($techSupervisorIds) - 1) . '?';
        
        // Get all leaves from technical direct reports
        $sqlTechLeaves = "
            SELECT la.LeaveApplicationID
            FROM [dbPRFAssetMgt].[dbo].[LeaveApplications] la
            WHERE la.EmployeeID IN ($placeholders)
            AND la.Status IN (0, 1, 2) -- Pending, L1 Approved, or L2 Approved (but not rejected)
        ";
        
        $stmtTechLeaves = $conn->prepare($sqlTechLeaves);
        $stmtTechLeaves->execute($techSupervisorIds);
        $techLeaves = $stmtTechLeaves->fetchAll(PDO::FETCH_COLUMN, 0);
        
        // Check each leave if it's pending for this technical supervisor
        foreach ($techLeaves as $leaveId) {
            if (isLeavePendingForSupervisorDashboard($conn, $leaveId, $supervisorId, 'technical')) {
                $pendingCount++;
            }
        }
    }
    
    // Count leaves for 2nd level supervisors
    if (!empty($secondLevelIds)) {
        $placeholders = str_repeat('?,', count($secondLevelIds) - 1) . '?';
        
        // Get all leaves from 2nd level direct reports
        $sqlSecondLeaves = "
            SELECT la.LeaveApplicationID
            FROM [dbPRFAssetMgt].[dbo].[LeaveApplications] la
            WHERE la.EmployeeID IN ($placeholders)
            AND la.Status IN (0, 1, 2) -- Pending, L1 Approved, or L2 Approved (but not rejected)
        ";
        
        $stmtSecondLeaves = $conn->prepare($sqlSecondLeaves);
        $stmtSecondLeaves->execute($secondLevelIds);
        $secondLeaves = $stmtSecondLeaves->fetchAll(PDO::FETCH_COLUMN, 0);
        
        // Check each leave if it's pending for this 2nd level supervisor
        foreach ($secondLeaves as $leaveId) {
            if (isLeavePendingForSupervisorDashboard($conn, $leaveId, $supervisorId, 'second_level')) {
                $pendingCount++;
            }
        }
    }
}

/* ==========================
   FETCH BASIC PROFILE DETAILS
========================== */
$profile = get_current_employee_details($conn);

// DEBUG: Check profile data (remove in production)
error_log("Profile data keys: " . implode(', ', array_keys($profile ?: [])));

/* ==========================
   FETCH RELATED DATA SEPARATELY
========================== */
$departmentName = '';
$locationName = '';
$designationName = '';
$adminSupervisorName = 'Not Assigned';
$techSupervisorName = 'Not Assigned';
$secondSupervisorName = 'Not Assigned';

if (!empty($profile)) {
    try {
        // Get Department Name
        if (!empty($profile['DepartmentID'])) {
            $stmt = $conn->prepare(
                "SELECT DepartmentName 
                 FROM [dbPRFAssetMgt].[dbo].[Departments] 
                 WHERE DepartmentID = :deptId"
            );
            $stmt->bindParam(':deptId', $profile['DepartmentID'], PDO::PARAM_INT);
            $stmt->execute();
            $dept = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($dept) {
                $departmentName = isset($dept['DepartmentName']) ? $dept['DepartmentName'] : '';
            }
        }
        
        // Get Location Name
        if (!empty($profile['LocationID'])) {
            $stmt = $conn->prepare(
                "SELECT LocationName 
                 FROM [dbPRFAssetMgt].[dbo].[Locations] 
                 WHERE LocationID = :locId"
            );
            $stmt->bindParam(':locId', $profile['LocationID'], PDO::PARAM_INT);
            $stmt->execute();
            $loc = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($loc) {
                $locationName = isset($loc['LocationName']) ? $loc['LocationName'] : '';
            }
        }
        
        // Get Designation Name
        if (!empty($profile['JobTitleID'])) {
            $stmt = $conn->prepare("
                SELECT JobTitleName
                FROM [dbPRFAssetMgt].[dbo].[Designation]
                WHERE JobTitleID = :jobId AND IsActive = 1
            ");
            $stmt->bindParam(':jobId', $profile['JobTitleID'], PDO::PARAM_INT);
            $stmt->execute();
            $job = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($job) {
                $designationName = isset($job['JobTitleName']) ? $job['JobTitleName'] : '';
            }
        }
        
        /* ==========================
           FETCH SUPERVISOR NAMES
        ========================== */
        
        // Function to fetch supervisor name by ID
        function fetchSupervisorName($conn, $supervisorId, &$outputVar) {
            if (empty($supervisorId)) {
                $outputVar = 'Not Assigned';
                return;
            }
            
            try {
                $stmt = $conn->prepare("
                    SELECT FirstName, LastName, EmployeeCode
                    FROM [dbPRFAssetMgt].[dbo].[Employees]
                    WHERE EmployeeID = :supId
                ");
                $stmt->bindParam(':supId', $supervisorId, PDO::PARAM_INT);
                $stmt->execute();
                $sup = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if ($sup && !empty($sup['FirstName'])) {
                    $firstName = isset($sup['FirstName']) ? $sup['FirstName'] : '';
                    $lastName = isset($sup['LastName']) ? $sup['LastName'] : '';
                    $name = trim($firstName . ' ' . $lastName);
                    
                    if (!empty($sup['EmployeeCode'])) {
                        $name .= ' (' . $sup['EmployeeCode'] . ')';
                    }
                    
                    $outputVar = $name;
                } else {
                    $outputVar = 'ID: ' . $supervisorId . ' (Not Found)';
                }
            } catch (Exception $e) {
                error_log("Error fetching supervisor ID {$supervisorId}: " . $e->getMessage());
                $outputVar = 'ID: ' . $supervisorId . ' (Error)';
            }
        }
        
        // Get Admin Supervisor - Check multiple possible column names
        $adminSupId = null;
        $possibleAdminKeys = [
            'SupervisorID_admin',
            'Supervisor_admin',
            'SupervisorID_admin',
            'AdminSupervisorID',
            'AdminSupervisor',
            'supervisor_id_admin',
            'Admin_Supervisor_ID'
        ];
        
        foreach ($possibleAdminKeys as $key) {
            if (isset($profile[$key]) && !empty($profile[$key]) && $profile[$key] > 0) {
                $adminSupId = (int)$profile[$key];
                error_log("Found admin supervisor ID in key '{$key}': {$adminSupId}");
                break;
            }
        }
        
        fetchSupervisorName($conn, $adminSupId, $adminSupervisorName);
        
        // Get Technical Supervisor - Check multiple possible column names
        $techSupId = null;
        $possibleTechKeys = [
            'SupervisorID_technical',
            'Supervisor_technical',
            'TechnicalSupervisorID',
            'TechnicalSupervisor',
            'supervisor_id_technical',
            'Tech_Supervisor_ID'
        ];
        
        foreach ($possibleTechKeys as $key) {
            if (isset($profile[$key]) && !empty($profile[$key]) && $profile[$key] > 0) {
                $techSupId = (int)$profile[$key];
                error_log("Found technical supervisor ID in key '{$key}': {$techSupId}");
                break;
            }
        }
        
        fetchSupervisorName($conn, $techSupId, $techSupervisorName);
        
        // Get 2nd Level Supervisor - Check multiple possible column names
        $secondSupId = null;
        $possibleSecondKeys = [
            'SupervisorID_2ndLevel',
            'Supervisor_2ndLevel',
            'SecondSupervisorID',
            'SecondSupervisor',
            'supervisor_id_2nd',
            'SupervisorID_second',
            'Second_Level_Supervisor_ID'
        ];
        
        foreach ($possibleSecondKeys as $key) {
            if (isset($profile[$key]) && !empty($profile[$key]) && $profile[$key] > 0) {
                $secondSupId = (int)$profile[$key];
                error_log("Found second level supervisor ID in key '{$key}': {$secondSupId}");
                break;
            }
        }
        
        fetchSupervisorName($conn, $secondSupId, $secondSupervisorName);
        
    } catch(Exception $e){
        error_log("Related data fetch error: " . $e->getMessage());
    }
}

/* ==========================
   FETCH LEAVE TYPES
========================== */
$leaveTypes = [];
try {
    $stmt = $conn->prepare("SELECT LeaveTypeID, LeaveTypeName FROM [dbPRFAssetMgt].[dbo].[LeaveTypes] WHERE IsActive = 1 OR IsActive IS NULL");
    $stmt->execute();
    $leaveTypesData = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($leaveTypesData as $lt) {
        $leaveTypes[$lt['LeaveTypeID']] = $lt['LeaveTypeName'];
    }
} catch(Exception $e) {
    error_log("LeaveTypes fetch error: " . $e->getMessage());
}

/* ==========================
   FETCH LEAVE BALANCES
========================== */
$leaveBalances = [];
try {
    $stmt = $conn->prepare("
        SELECT 
            LeaveTypeID,
            LeaveYear,
            OpeningBalance,
            UsedDays,
            RemainingDays
        FROM [dbPRFAssetMgt].[dbo].[LeaveBalances]
        WHERE EmployeeID = :eid
          AND LeaveYear = YEAR(GETDATE())
        ORDER BY LeaveYear DESC, LeaveTypeID
    ");
    $stmt->execute([':eid' => $employeeId]);
    $leaveBalances = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    error_log('Leave balances error: ' . $e->getMessage());
}

/* ==========================
   FETCH RECENT LEAVE APPLICATIONS
========================== */
$leaveApps = [];
try {
    $stmt = $conn->prepare("
        SELECT TOP 10 
            LeaveApplicationID,
            LeaveTypeID,
            StartDate,
            EndDate,
            TotalDays,
            Status,
            AppliedDate
        FROM [dbPRFAssetMgt].[dbo].[LeaveApplications]
        WHERE EmployeeID = :eid
        ORDER BY AppliedDate DESC
    ");
    $stmt->execute([':eid' => $employeeId]);
    $leaveApps = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch(Exception $e){
    error_log("Leave applications fetch error: " . $e->getMessage());
}

require_once __DIR__ . '/include/header.php';
?>

<style>
    .dashboard-grid{
        display:grid;
        grid-template-columns: 1fr 1fr;
        gap:20px;
    }
    @media(max-width:992px){
        .dashboard-grid{ grid-template-columns:1fr; }
    }
    .card-box{
        background:#fff;
        border-radius:12px;
        padding:20px;
        border:1px solid #e5e7eb;
        box-shadow:0 4px 6px rgba(0,0,0,.05);
    }
    .card-title{
        font-weight:600;
        margin-bottom:16px;
        color:#1e293b;
        font-size:18px;
        border-bottom:2px solid #f1f5f9;
        padding-bottom:10px;
    }
    .profile-header {
        background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        color: white;
        padding: 25px;
        border-radius: 12px;
        margin-bottom: 25px;
    }
    .info-grid {
        display: grid;
        grid-template-columns: repeat(2, 1fr);
        gap: 12px;
    }
    .info-item {
        background: #f8fafc;
        border-radius: 8px;
        padding: 12px;
        border-left: 4px solid #3b82f6;
    }
    .info-label {
        color:#64748b;
        font-size:13px;
        font-weight:500;
        margin-bottom:4px;
        display:block;
    }
    .info-value {
        color:#1e293b;
        font-size:15px;
        font-weight:600;
    }
    .supervisor-item {
        background: #f0f9ff;
        border-left-color: #0ea5e9;
    }
    .badge-status {
        padding: 4px 10px;
        border-radius: 20px;
        font-size: 12px;
        font-weight: 600;
    }
    .badge-pending { background:#fef3c7; color:#92400e; }
    .badge-approved { background:#d1fae5; color:#065f46; }
    .badge-rejected { background:#fee2e2; color:#991b1b; }
    .badge-cancelled { background:#e5e7eb; color:#374151; }
    .employee-code-badge {
        background: rgba(255,255,255,0.2);
        padding: 2px 8px;
        border-radius: 12px;
        font-size: 12px;
        margin-left: 10px;
    }
    
    /* Beautiful Pending Applications Button Styles */
    .action-buttons-container {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 30px;
        gap: 20px;
    }
    
    /* Supervisor Approval Button */
    .supervisor-approval-btn {
        display: flex;
        align-items: center;
        gap: 12px;
        padding: 16px 24px;
        background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        color: white;
        text-decoration: none;
        border-radius: 12px;
        font-weight: 600;
        font-size: 16px;
        box-shadow: 0 4px 15px rgba(102, 126, 234, 0.3);
        transition: all 0.3s ease;
        border: none;
        position: relative;
        overflow: hidden;
        flex: 1;
        max-width: 400px;
    }
    
    .supervisor-approval-btn::before {
        content: '';
        position: absolute;
        top: 0;
        left: -100%;
        width: 100%;
        height: 100%;
        background: linear-gradient(90deg, transparent, rgba(255,255,255,0.2), transparent);
        transition: 0.5s;
    }
    
    .supervisor-approval-btn:hover::before {
        left: 100%;
    }
    
    .supervisor-approval-btn:hover {
        transform: translateY(-3px);
        box-shadow: 0 8px 25px rgba(102, 126, 234, 0.4);
        color: white;
        text-decoration: none;
    }
    
    .supervisor-approval-btn i {
        font-size: 20px;
        background: rgba(255,255,255,0.2);
        padding: 10px;
        border-radius: 10px;
    }
    
    .btn-content {
        display: flex;
        flex-direction: column;
        gap: 4px;
        flex: 1;
    }
    
    .btn-title {
        font-size: 16px;
        font-weight: 600;
    }
    
    .btn-subtitle {
        font-size: 12px;
        opacity: 0.9;
        font-weight: 400;
    }
    
    .pending-count-badge {
        background: #ff6b6b;
        color: white;
        padding: 6px 12px;
        border-radius: 20px;
        font-size: 14px;
        font-weight: 700;
        min-width: 40px;
        text-align: center;
        box-shadow: 0 2px 8px rgba(255, 107, 107, 0.4);
        animation: pulse 2s infinite;
    }
    
    @keyframes pulse {
        0% { transform: scale(1); }
        50% { transform: scale(1.05); }
        100% { transform: scale(1); }
    }
    
    /* Apply Leave Button */
    .apply-leave-btn {
        display: flex;
        align-items: center;
        gap: 10px;
        padding: 16px 28px;
        background: linear-gradient(135deg, #10b981 0%, #059669 100%);
        color: white;
        text-decoration: none;
        border-radius: 12px;
        font-weight: 600;
        font-size: 16px;
        box-shadow: 0 4px 15px rgba(16, 185, 129, 0.3);
        transition: all 0.3s ease;
        border: none;
    }
    
    .apply-leave-btn:hover {
        transform: translateY(-3px);
        box-shadow: 0 8px 25px rgba(16, 185, 129, 0.4);
        color: white;
        text-decoration: none;
    }
    
    .apply-leave-btn i {
        font-size: 20px;
    }
    
    /* Supervisor badge */
    .supervisor-badge {
        background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%);
        color: white;
        padding: 6px 12px;
        border-radius: 20px;
        font-size: 12px;
        font-weight: 600;
        margin-left: 10px;
        box-shadow: 0 2px 5px rgba(245, 158, 11, 0.3);
    }
    
    .supervisor-icon {
        color: #0ea5e9;
        margin-right: 5px;
    }
    
    /* Table styles */
    .table-responsive {
        overflow-x: auto;
    }
    
    .table {
        width: 100%;
        margin-bottom: 1rem;
        color: #212529;
    }
    
    .table th {
        vertical-align: bottom;
        border-bottom: 2px solid #dee2e6;
    }
    
    .table td, .table th {
        padding: 0.75rem;
        vertical-align: top;
        border-top: 1px solid #dee2e6;
    }
    
    .table-hover tbody tr:hover {
        color: #212529;
        background-color: rgba(0, 0, 0, 0.075);
    }
    
    .table-light {
        background-color: #f8f9fa;
    }
    
    .text-end {
        text-align: right !important;
    }
    
    .text-center {
        text-align: center !important;
    }
    
    .text-success {
        color: #28a745 !important;
    }
    
    .text-danger {
        color: #dc3545 !important;
    }
    
    .text-muted {
        color: #6c757d !important;
    }
    
    .fw-bold {
        font-weight: 700 !important;
    }
    
    .border {
        border: 1px solid #dee2e6 !important;
    }
    
    .bg-light {
        background-color: #f8f9fa !important;
    }
    
    .bg-secondary {
        background-color: #6c757d !important;
        color: white;
    }
    
    .badge {
        display: inline-block;
        padding: 0.25em 0.4em;
        font-size: 75%;
        font-weight: 700;
        line-height: 1;
        text-align: center;
        white-space: nowrap;
        vertical-align: baseline;
        border-radius: 0.25rem;
    }
  
.badge-status {
    padding: 4px 8px;
    border-radius: 12px;
    font-size: 12px;
    font-weight: 500;
}

.badge-pending {
    background-color: #ffc107;
    color: #000;
}

.badge-approved {
    background-color: #28a745;
    color: #fff;
}

.badge-rejected {
    background-color: #dc3545;
    color: #fff;
}

.badge-cancelled {
    background-color: #6c757d;
    color: #fff;
}

.supervisor-comments {
    font-size: 12px;
    line-height: 1.4;
    max-height: 60px;
    overflow: hidden;
    text-overflow: ellipsis;
}

.supervisor-comments .full-content {
    max-height: none;
    overflow: visible;
}

.show-more-link {
    color: #0d6efd;
    text-decoration: none;
    cursor: pointer;
}

.show-more-link:hover {
    text-decoration: underline;
}

.table th {
    font-weight: 600;
    font-size: 13px;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.table td {
    vertical-align: middle;
    font-size: 14px;
}
.badge-status {
    padding: 4px 8px;
    border-radius: 12px;
    font-size: 12px;
    font-weight: 500;
    cursor: default;
    min-width: 70px;
    display: inline-block;
}

.badge-pending {
    background-color: #ffc107;
    color: #000;
}

.badge-approved {
    background-color: #28a745;
    color: #fff;
}

.badge-rejected {
    background-color: #dc3545;
    color: #fff;
}

.badge-cancelled {
    background-color: #6c757d;
    color: #fff;
}

.comments-container {
    font-size: 11px;
    line-height: 1.4;
}

.comments-container .text-muted {
    color: #6c757d !important;
}

.comments-container .text-danger {
    color: #dc3545 !important;
}

.comments-container strong {
    font-weight: 600;
    color: #495057;
}

.comments-container .bi {
    font-size: 10px;
    vertical-align: middle;
}

.short-comments, .full-comments {
    max-height: 80px;
    overflow: hidden;
}

.full-comments {
    max-height: none !important;
}

.show-more-link {
    color: #0d6efd;
    text-decoration: none;
    cursor: pointer;
    font-size: 10px !important;
    display: inline-block;
    margin-top: 3px;
}

.show-more-link:hover {
    text-decoration: underline;
}

.table th {
    font-weight: 600;
    font-size: 12px;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    background-color: #f8f9fa;
    white-space: nowrap;
}

.table td {
    vertical-align: middle;
    font-size: 13px;
    padding: 8px 10px;
}

.table-hover tbody tr:hover {
    background-color: rgba(0, 0, 0, 0.02);
}

.badge.bg-light {
    background-color: #f8f9fa !important;
    border: 1px solid #dee2e6;
    font-size: 11px;
}

/* Approved date column styling */
.approved-date-badge {
    font-size: 11px;
    padding: 3px 8px;
    border-radius: 10px;
}

.approved-date-badge .bi {
    font-size: 10px;
    vertical-align: middle;
}

/* Tooltip styling */
.tooltip {
    font-size: 11px;
}

/* Ensure comments don't overflow table cell */
.comments-container {
    word-wrap: break-word;
    word-break: break-word;
    overflow-wrap: break-word;
}

.comment-text, .rejection-text {
    white-space: pre-wrap;
}
</style>

<div class="container my-4">

    <!-- PROFILE HEADER -->
    <?php if(!empty($profile)): ?>
    <div class="profile-header d-flex align-items-center">
        <div style="width: 80px; height: 80px; background: rgba(255,255,255,0.2); border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 32px; font-weight: bold; margin-right: 20px;">
            <?php 
            $initials = '';
            if (!empty($profile['FirstName'])) $initials .= substr($profile['FirstName'], 0, 1);
            if (!empty($profile['LastName'])) $initials .= substr($profile['LastName'], 0, 1);
            echo h($initials ?: '?');
            ?>
        </div>
        <div class="flex-grow-1">
            <h2 class="mb-2">
                <?php echo h(isset($profile['FirstName']) ? $profile['FirstName'] : ''); ?>
                <?php echo h(isset($profile['LastName']) ? $profile['LastName'] : ''); ?>
                <span class="employee-code-badge"><?php echo h(isset($profile['EmployeeCode']) ? $profile['EmployeeCode'] : ''); ?></span>
                <!-- <?php if ($isSupervisor): ?>
                    <span class="supervisor-badge">SUPERVISOR</span>
                <?php endif; ?> -->
            </h2>
            <div class="d-flex flex-wrap gap-3">
                <div>
                    <small class="opacity-90">Designation</small>
                    <div class="fw-bold">
                        <?php echo h(!empty($designationName) ? $designationName : 'N/A'); ?>
                    </div>
                </div>
                <div>
                    <small class="opacity-90">Department</small>
                    <div class="fw-bold">
                        <?php echo h(!empty($departmentName) ? $departmentName : 'N/A'); ?>
                    </div>
                </div>
                <!-- <div>
                    <small class="opacity-90">Location</small>
                    <div class="fw-bold">
                        <?php echo h(!empty($locationName) ? $locationName : 'N/A'); ?>
                    </div>
                </div> -->
                <div class="ms-auto text-end">
                    <small class="opacity-90">Email</small>
                    <div class="fw-bold">
                        <?php echo h(isset($profile['Email_Office']) ? $profile['Email_Office'] : 'N/A'); ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- ACTION BUTTONS SECTION -->
    <div class="action-buttons-container">
        <?php if ($isSupervisor): ?>
        <!-- For Supervisors: Show beautiful approval button -->
        <a href="<?php echo htmlspecialchars(url_to('/pages/leave/leave_approval_supervisor.php')); ?>" 
           class="supervisor-approval-btn">
            <i class="fa-solid fa-user-check"></i>
            <div class="btn-content">
                <span class="btn-title">Pending Applications For Approval</span>
                <span class="btn-subtitle">Review and approve leave requests from your team</span>
            </div>
            <?php if ($pendingCount > 0): ?>
                <span class="pending-count-badge">
                    <?php echo (int)$pendingCount; ?> pending
                </span>
            <?php else: ?>
                <span style="padding: 6px 12px; font-size: 14px; opacity: 0.8;">
                    No pending
                </span>
            <?php endif; ?>
        </a>
        <?php else: ?>
        <!-- For Non-Supervisors: Placeholder or different content -->
        <div style="flex: 1;"></div>
        <?php endif; ?>

        <!-- Apply Leave Button (Beautiful) -->
        <a href="/PRF_LeaveEmailest/pages/leave/leave_apply.php" class="apply-leave-btn">
            <i class="bi bi-plus-circle"></i>
            Apply for Leave
        </a>
    </div>

    <!-- TOP SECTION -->
    <div class="dashboard-grid mb-4">

        <!-- EMPLOYEE DETAILS CARD -->
        <div class="card-box">
            <h5 class="card-title">
                <i class="bi bi-person-badge me-2"></i>Employee Information
            </h5>
            
            <?php if (!empty($profile)): ?>
                <div class="info-grid">
                    <div class="info-item">
                        <span class="info-label">Employee Code</span>
                        <span class="info-value">
                            <?php echo h(isset($profile['EmployeeCode']) ? $profile['EmployeeCode'] : 'N/A'); ?>
                        </span>
                    </div>

                    <div class="info-item">
                        <span class="info-label">Full Name</span>
                        <span class="info-value">
                            <?php echo h(isset($profile['FirstName']) ? $profile['FirstName'] : ''); ?>
                            <?php echo h(isset($profile['LastName']) ? $profile['LastName'] : ''); ?>
                        </span>
                    </div>

                    <div class="info-item">
                        <span class="info-label">Office Email</span>
                        <span class="info-value">
                            <?php echo h(isset($profile['Email_Office']) ? $profile['Email_Office'] : 'N/A'); ?>
                        </span>
                    </div>

                    <div class="info-item">
                        <span class="info-label">Designation</span>
                        <span class="info-value">
                            <?php echo h(!empty($designationName) ? $designationName : 'N/A'); ?>
                        </span>
                    </div>

                    <div class="info-item">
                        <span class="info-label">Department</span>
                        <span class="info-value">
                            <?php echo h(!empty($departmentName) ? $departmentName : 'N/A'); ?>
                        </span>
                    </div>

                    <div class="info-item">
                        <span class="info-label">Location</span>
                        <span class="info-value">
                            <?php echo h(!empty($locationName) ? $locationName : 'N/A'); ?>
                        </span>
                    </div>
                    <h1>Test</h1>
                    <!-- Supervisor Information -->
                    <div class="info-item supervisor-item">
                        <span class="info-label">
                            <i class="bi bi-person-badge supervisor-icon"></i>Admin Supervisor
                        </span>
                        <span class="info-value">
                            <?php echo h($adminSupervisorName); ?>
                        </span>
                    </div>
                    
                    <div class="info-item supervisor-item">
                        <span class="info-label">
                            <i class="bi bi-gear supervisor-icon"></i>Technical Supervisor
                        </span>
                        <span class="info-value">
                            <?php echo h($techSupervisorName); ?>
                        </span>
                    </div>
                    
                    <div class="info-item supervisor-item">
                        <span class="info-label">
                            <i class="bi bi-person-fill-up supervisor-icon"></i>Second Level Supervisor
                        </span>
                        <span class="info-value">
                            <?php echo h($secondSupervisorName); ?>
                        </span>
                    </div>
                    
                    <!-- <div class="info-item">
                        <span class="info-label">Supervisor Status</span>
                        <span class="info-value">
                            <?php if ($isSupervisor): ?>
                                <span style="color: #28a745; font-weight: bold;">
                                    <i class="fa-solid fa-user-tie"></i> Yes - You are a Supervisor
                                </span>
                            <?php else: ?>
                                <span style="color: #6c757d;">
                                    <i class="fa-solid fa-user"></i> Regular Employee
                                </span>
                            <?php endif; ?>
                        </span>
                    </div> -->
                </div>
            <?php else: ?>
                <div class="text-center py-4">
                    <i class="bi bi-exclamation-triangle text-warning" style="font-size: 48px;"></i>
                    <p class="text-muted mt-3">No profile information found</p>
                </div>
            <?php endif; ?>
        </div>

        <!-- LEAVE BALANCE CARD -->
        <div class="card-box">
            <h5 class="card-title">
                <i class="bi bi-calendar-heart me-2"></i>My Leave Balance
            </h5>
            
            <?php if(!empty($leaveBalances)): ?>
                <div class="table-responsive">
                    <table class="table table-hover table-sm">
                        <thead class="table-light">
                            <tr>
                                <th>Year</th>
                                <th>Leave Type</th>
                                <th class="text-end">Opening</th>
                                <th class="text-end">Used</th>
                                <th class="text-end">Remaining</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php 
                            $totalRemaining = 0;
                            foreach ($leaveBalances as $lb): 
                                $remaining = isset($lb['RemainingDays']) ? (float)$lb['RemainingDays'] : 0;
                                $totalRemaining += $remaining;
                                $leaveTypeId = isset($lb['LeaveTypeID']) ? $lb['LeaveTypeID'] : '';
                                $leaveTypeName = isset($leaveTypes[$leaveTypeId]) 
                                    ? $leaveTypes[$leaveTypeId] 
                                    : $leaveTypeId;
                                $openingBalance = isset($lb['OpeningBalance']) ? $lb['OpeningBalance'] : 0;
                                $usedDays       = isset($lb['UsedDays']) ? $lb['UsedDays'] : 0;
                                $leaveYear      = isset($lb['LeaveYear']) ? $lb['LeaveYear'] : '';
                            ?>
                            <tr>
                                <td><strong><?php echo h($leaveYear); ?></strong></td>
                                <td><?php echo h($leaveTypeName); ?></td>
                                <td class="text-end"><?php echo h(number_format($openingBalance, 1)); ?></td>
                                <td class="text-end"><?php echo h(number_format($usedDays, 1)); ?></td>
                                <td class="text-end">
                                    <span class="fw-bold <?php echo $remaining > 0 ? 'text-success' : 'text-danger'; ?>">
                                        <?php echo h(number_format($remaining, 1)); ?>
                                    </span>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                            <?php if(count($leaveBalances) > 1): ?>
                            <tr class="table-light">
                                <td colspan="4" class="text-end fw-bold">Total Remaining:</td>
                                <td class="text-end fw-bold <?php echo $totalRemaining > 0 ? 'text-success' : 'text-danger'; ?>">
                                    <?php echo h(number_format($totalRemaining, 1)); ?>
                                </td>
                            </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <div class="text-center py-4">
                    <i class="bi bi-calendar-x text-muted" style="font-size: 48px;"></i>
                    <p class="text-muted mt-3">No leave balance records found</p>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- RECENT LEAVE APPLICATIONS CARD -->
    <div class="card-box">
    <h5 class="card-title">
        <i class="bi bi-clock-history me-2"></i>Your Recent Leave Applications
        <span class="badge bg-secondary ms-2"><?php echo count($leaveApps); ?> total</span>
    </h5>

    <?php if(!empty($leaveApps)): ?>
        <div class="table-responsive">
            <table class="table table-hover">
                <thead class="table-light">
                    <tr>
                        <th>Application #</th>
                        <th>Leave Type</th>
                        <th>Period</th>
                        <th class="text-center">Days</th>
                        <th class="text-center">L1 Status</th>
                        <th class="text-center">L2 Status</th>
                        <th class="text-center">Approved Date</th>
                        <th>Supervisor Comments</th>
                        <th>Applied On</th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    foreach ($leaveApps as $la):
                        $leaveAppId = isset($la['LeaveApplicationID']) ? $la['LeaveApplicationID'] : 0;
                        $leaveTypeId = isset($la['LeaveTypeID']) ? $la['LeaveTypeID'] : '';
                        $leaveTypeName = isset($leaveTypes[$leaveTypeId])
                            ? $leaveTypes[$leaveTypeId]
                            : 'N/A';
                        
                        // Get overall status
                        $overallStatus = isset($la['Status']) ? (int)$la['Status'] : 0;
                        
                        // Get approval details for this leave application
                        $supervisorComments = '';
                        $rejectionReason = '';
                        $l1ApprovedDate = '';
                        $l2ApprovedDate = '';
                        $rejectedDate = '';
                        $l1ApproverName = '';
                        $l2ApproverName = '';
                        $rejectedByName = '';
                        $finalApprovalDate = ''; // For the new Approved Date column
                        
                        if ($leaveAppId > 0) {
                            try {
                                $sqlApproval = "
                                    SELECT 
                                        la.Comments,
                                        la.RejectionReason,
                                        la.L1ApprovedBy,
                                        la.L2ApprovedBy,
                                        la.RejectedBy,
                                        la.L1ApprovedDate,
                                        la.L2ApprovedDate,
                                        la.RejectedDate,
                                        e1.FirstName as L1FirstName,
                                        e1.LastName as L1LastName,
                                        e2.FirstName as L2FirstName,
                                        e2.LastName as L2LastName,
                                        e3.FirstName as RejectedFirstName,
                                        e3.LastName as RejectedLastName
                                    FROM [dbPRFAssetMgt].[dbo].[LeaveApprovals] la
                                    LEFT JOIN [dbPRFAssetMgt].[dbo].[Employees] e1 ON la.L1ApprovedBy = e1.EmployeeID
                                    LEFT JOIN [dbPRFAssetMgt].[dbo].[Employees] e2 ON la.L2ApprovedBy = e2.EmployeeID
                                    LEFT JOIN [dbPRFAssetMgt].[dbo].[Employees] e3 ON la.RejectedBy = e3.EmployeeID
                                    WHERE la.LeaveApplicationID = ?
                                ";
                                $stmtApproval = $conn->prepare($sqlApproval);
                                $stmtApproval->execute([$leaveAppId]);
                                $approvalDetails = $stmtApproval->fetch(PDO::FETCH_ASSOC);
                                
                                if ($approvalDetails) {
                                    $supervisorComments = isset($approvalDetails['Comments']) ? $approvalDetails['Comments'] : '';
                                    $rejectionReason = isset($approvalDetails['RejectionReason']) ? $approvalDetails['RejectionReason'] : '';
                                    
                                    // Format dates
                                    if (!empty($approvalDetails['L1ApprovedDate'])) {
                                        $l1ApprovedDate = date('d M Y', strtotime($approvalDetails['L1ApprovedDate']));
                                        $l1ApprovedDateTime = date('d M Y, h:i A', strtotime($approvalDetails['L1ApprovedDate']));
                                    }
                                    if (!empty($approvalDetails['L2ApprovedDate'])) {
                                        $l2ApprovedDate = date('d M Y', strtotime($approvalDetails['L2ApprovedDate']));
                                        $l2ApprovedDateTime = date('d M Y, h:i A', strtotime($approvalDetails['L2ApprovedDate']));
                                    }
                                    if (!empty($approvalDetails['RejectedDate'])) {
                                        $rejectedDate = date('d M Y', strtotime($approvalDetails['RejectedDate']));
                                        $rejectedDateTime = date('d M Y, h:i A', strtotime($approvalDetails['RejectedDate']));
                                    }
                                    
                                    // Get approver names
                                    if (!empty($approvalDetails['L1FirstName']) && !empty($approvalDetails['L1LastName'])) {
                                        $l1ApproverName = $approvalDetails['L1FirstName'] . ' ' . $approvalDetails['L1LastName'];
                                    }
                                    if (!empty($approvalDetails['L2FirstName']) && !empty($approvalDetails['L2LastName'])) {
                                        $l2ApproverName = $approvalDetails['L2FirstName'] . ' ' . $approvalDetails['L2LastName'];
                                    }
                                    if (!empty($approvalDetails['RejectedFirstName']) && !empty($approvalDetails['RejectedLastName'])) {
                                        $rejectedByName = $approvalDetails['RejectedFirstName'] . ' ' . $approvalDetails['RejectedLastName'];
                                    }
                                    
                                    // Determine final approval date for the new column
                                    if (!empty($approvalDetails['L2ApprovedDate'])) {
                                        $finalApprovalDate = $l2ApprovedDate; // L2 approval is final
                                    } elseif (!empty($approvalDetails['L1ApprovedDate'])) {
                                        $finalApprovalDate = $l1ApprovedDate; // Only L1 approved
                                    }
                                }
                            } catch (Exception $e) {
                                error_log("Error fetching approval details: " . $e->getMessage());
                            }
                        }
                        
                        // Determine L1 and L2 status based on overall status
                        $l1Status = '';
                        $l1BadgeClass = 'badge-secondary';
                        $l1Tooltip = '';
                        
                        $l2Status = '';
                        $l2BadgeClass = 'badge-secondary';
                        $l2Tooltip = '';
                        
                        // Check if we have approval details to determine exact status
                        if (isset($approvalDetails) && $approvalDetails) {
                            // L1 Status with details
                            if ($approvalDetails['L1ApprovedBy'] !== null) {
                                $l1Status = 'Approved';
                                $l1BadgeClass = 'badge-approved';
                                $l1Tooltip = 'Approved: ' . $l1ApprovedDate;
                                if (!empty($l1ApproverName)) {
                                    $l1Tooltip .= ' by ' . $l1ApproverName;
                                }
                            } elseif ($approvalDetails['RejectedBy'] !== null) {
                                $l1Status = 'Rejected';
                                $l1BadgeClass = 'badge-rejected';
                                $l1Tooltip = 'Rejected: ' . $rejectedDate;
                                if (!empty($rejectedByName)) {
                                    $l1Tooltip .= ' by ' . $rejectedByName;
                                }
                            } else {
                                $l1Status = 'Pending';
                                $l1BadgeClass = 'badge-pending';
                                $l1Tooltip = 'Awaiting L1 approval';
                            }
                            
                            // L2 Status with details
                            if ($approvalDetails['L2ApprovedBy'] !== null) {
                                $l2Status = 'Approved';
                                $l2BadgeClass = 'badge-approved';
                                $l2Tooltip = 'Approved: ' . $l2ApprovedDate;
                                if (!empty($l2ApproverName)) {
                                    $l2Tooltip .= ' by ' . $l2ApproverName;
                                }
                            } elseif ($approvalDetails['RejectedBy'] !== null) {
                                $l2Status = 'Rejected';
                                $l2BadgeClass = 'badge-rejected';
                                $l2Tooltip = 'Rejected: ' . $rejectedDate;
                                if (!empty($rejectedByName)) {
                                    $l2Tooltip .= ' by ' . $rejectedByName;
                                }
                            } else {
                                $l2Status = 'Pending';
                                $l2BadgeClass = 'badge-pending';
                                $l2Tooltip = 'Awaiting L2 approval';
                            }
                        } else {
                            // Fallback to overall status if no approval details
                            switch($overallStatus) {
                                case 0: // Pending
                                    $l1Status = 'Pending';
                                    $l1BadgeClass = 'badge-pending';
                                    $l1Tooltip = 'Awaiting L1 approval';
                                    $l2Status = 'Pending';
                                    $l2BadgeClass = 'badge-pending';
                                    $l2Tooltip = 'Awaiting L2 approval';
                                    break;
                                case 1: // L1 Approved
                                    $l1Status = 'Approved';
                                    $l1BadgeClass = 'badge-approved';
                                    $l1Tooltip = 'L1 Approved';
                                    $l2Status = 'Pending';
                                    $l2BadgeClass = 'badge-pending';
                                    $l2Tooltip = 'Awaiting L2 approval';
                                    break;
                                case 2: // L2 Approved
                                    $l1Status = 'Approved';
                                    $l1BadgeClass = 'badge-approved';
                                    $l1Tooltip = 'L1 Approved';
                                    $l2Status = 'Approved';
                                    $l2BadgeClass = 'badge-approved';
                                    $l2Tooltip = 'L2 Approved';
                                    break;
                                case 3: // Rejected
                                    $l1Status = 'Rejected';
                                    $l1BadgeClass = 'badge-rejected';
                                    $l1Tooltip = 'Rejected';
                                    $l2Status = 'Rejected';
                                    $l2BadgeClass = 'badge-rejected';
                                    $l2Tooltip = 'Rejected';
                                    break;
                                case 4: // Cancelled
                                    $l1Status = 'Cancelled';
                                    $l1BadgeClass = 'badge-cancelled';
                                    $l1Tooltip = 'Cancelled';
                                    $l2Status = 'Cancelled';
                                    $l2BadgeClass = 'badge-cancelled';
                                    $l2Tooltip = 'Cancelled';
                                    break;
                                default:
                                    $l1Status = 'Pending';
                                    $l1BadgeClass = 'badge-pending';
                                    $l1Tooltip = 'Awaiting L1 approval';
                                    $l2Status = 'Pending';
                                    $l2BadgeClass = 'badge-pending';
                                    $l2Tooltip = 'Awaiting L2 approval';
                                    break;
                            }
                        }
                        
                        $startDate = (!empty($la['StartDate']))
                            ? date('d M Y', strtotime($la['StartDate']))
                            : 'N/A';
                        
                        $endDate = (!empty($la['EndDate']))
                            ? date('d M Y', strtotime($la['EndDate']))
                            : 'N/A';
                        
                        $appliedDate = (!empty($la['AppliedDate']))
                            ? date('d M Y', strtotime($la['AppliedDate']))
                            : 'N/A';
                        
                        $totalDays = isset($la['TotalDays']) ? (int)$la['TotalDays'] : 0;
                        
                        // Format supervisor comments for display (with show more/less functionality)
                        $commentsForDisplay = '';
                        if (!empty($supervisorComments)) {
                            $commentsForDisplay .= '<div class="mb-1">';
                            $commentsForDisplay .= '<strong><i class="bi bi-chat-left-text me-1"></i>Comments:</strong> ';
                            $commentsForDisplay .= '<span class="comment-text text-muted">' . nl2br(htmlspecialchars($supervisorComments)) . '</span>';
                            $commentsForDisplay .= '</div>';
                        }
                        
                        if (!empty($rejectionReason)) {
                            $commentsForDisplay .= '<div class="mb-1">';
                            $commentsForDisplay .= '<strong><i class="bi bi-exclamation-triangle-fill text-danger me-1"></i>Rejection Reason:</strong> ';
                            $commentsForDisplay .= '<span class="rejection-text text-danger">' . nl2br(htmlspecialchars($rejectionReason)) . '</span>';
                            $commentsForDisplay .= '</div>';
                        }
                        
                        if (empty($commentsForDisplay)) {
                            $commentsForDisplay = '<span class="text-muted"><i class="bi bi-info-circle me-1"></i>No comments</span>';
                        }
                        
                        // Prepare the full HTML for the comments cell
                        $commentsCellHtml = '<div class="comments-container" style="max-width: 300px;">' . $commentsForDisplay . '</div>';
                        
                        // Format the final approval date display
                        $approvalDateDisplay = '';
                        if (!empty($finalApprovalDate)) {
                            $approvalDateDisplay = '<div class="text-center">';
                            $approvalDateDisplay .= '<span class="badge bg-success text-white" style="font-size: 11px; padding: 3px 8px;">';
                            $approvalDateDisplay .= '<i class="bi bi-calendar-check me-1"></i>' . $finalApprovalDate;
                            $approvalDateDisplay .= '</span>';
                            
                            // Add tooltip with more details
                            $approvalTooltip = '';
                            if (!empty($l2ApprovedDateTime)) {
                                $approvalTooltip = 'L2 Approved: ' . $l2ApprovedDateTime;
                                if (!empty($l2ApproverName)) {
                                    $approvalTooltip .= ' by ' . $l2ApproverName;
                                }
                            } elseif (!empty($l1ApprovedDateTime)) {
                                $approvalTooltip = 'L1 Approved: ' . $l1ApprovedDateTime;
                                if (!empty($l1ApproverName)) {
                                    $approvalTooltip .= ' by ' . $l1ApproverName;
                                }
                            }
                            
                            if (!empty($approvalTooltip)) {
                                $approvalDateDisplay = '<span data-bs-toggle="tooltip" data-bs-placement="top" title="' . htmlspecialchars($approvalTooltip) . '">' . 
                                    $approvalDateDisplay . '</span>';
                            }
                            $approvalDateDisplay .= '</div>';
                        } elseif (!empty($rejectedDate)) {
                            $approvalDateDisplay = '<div class="text-center">';
                            $approvalDateDisplay .= '<span class="badge bg-danger text-white" style="font-size: 11px; padding: 3px 8px;">';
                            $approvalDateDisplay .= '<i class="bi bi-x-circle me-1"></i>' . $rejectedDate;
                            $approvalDateDisplay .= '</span>';
                            
                            // Add tooltip with rejection details
                            if (!empty($rejectedDateTime)) {
                                $rejectionTooltip = 'Rejected: ' . $rejectedDateTime;
                                if (!empty($rejectedByName)) {
                                    $rejectionTooltip .= ' by ' . $rejectedByName;
                                }
                                $approvalDateDisplay = '<span data-bs-toggle="tooltip" data-bs-placement="top" title="' . htmlspecialchars($rejectionTooltip) . '">' . 
                                    $approvalDateDisplay . '</span>';
                            }
                            $approvalDateDisplay .= '</div>';
                        } else {
                            $approvalDateDisplay = '<div class="text-center">';
                            $approvalDateDisplay .= '<span class="text-muted" style="font-size: 11px;">';
                            $approvalDateDisplay .= '<i class="bi bi-clock me-1"></i>Pending';
                            $approvalDateDisplay .= '</span>';
                            $approvalDateDisplay .= '</div>';
                        }
                    ?>
                    <tr>
                        <td>#<?php echo htmlspecialchars(isset($la['LeaveApplicationID']) ? $la['LeaveApplicationID'] : 'N/A'); ?></td>
                        <td><?php echo htmlspecialchars($leaveTypeName); ?></td>
                        <td>
                            <div><?php echo htmlspecialchars($startDate); ?></div>
                            <small class="text-muted">to <?php echo htmlspecialchars($endDate); ?></small>
                        </td>
                        <td class="text-center">
                            <span class="badge bg-light text-dark border">
                                <?php echo htmlspecialchars($totalDays); ?> day(s)
                            </span>
                        </td>
                        <td class="text-center">
                            <span class="badge-status <?php echo $l1BadgeClass; ?>" 
                                  <?php if (!empty($l1Tooltip)): ?>
                                  data-bs-toggle="tooltip" 
                                  data-bs-placement="top" 
                                  title="<?php echo htmlspecialchars($l1Tooltip); ?>"
                                  <?php endif; ?>>
                                <?php echo htmlspecialchars($l1Status); ?>
                            </span>
                        </td>
                        <td class="text-center">
                            <span class="badge-status <?php echo $l2BadgeClass; ?>" 
                                  <?php if (!empty($l2Tooltip)): ?>
                                  data-bs-toggle="tooltip" 
                                  data-bs-placement="top" 
                                  title="<?php echo htmlspecialchars($l2Tooltip); ?>"
                                  <?php endif; ?>>
                                <?php echo htmlspecialchars($l2Status); ?>
                            </span>
                        </td>
                        <td class="text-center">
                            <?php echo $approvalDateDisplay; ?>
                        </td>
                        <td>
                            <?php echo $commentsCellHtml; ?>
                        </td>
                        <td><small class="text-muted"><?php echo htmlspecialchars($appliedDate); ?></small></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php else: ?>
        <div class="text-center py-4">
            <i class="bi bi-inbox text-muted" style="font-size: 48px;"></i>
            <p class="text-muted mt-3">No leave applications found.</p>
        </div>
    <?php endif; ?>
</div>

<?php if(!empty($leaveApps)): ?>
<script>
// Initialize Bootstrap tooltips
document.addEventListener('DOMContentLoaded', function() {
    var tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
    var tooltipList = tooltipTriggerList.map(function (tooltipTriggerEl) {
        return new bootstrap.Tooltip(tooltipTriggerEl);
    });
    
    // Add show more/less functionality to comments
    document.querySelectorAll('.comments-container').forEach(function(container) {
        var commentTexts = container.querySelectorAll('.comment-text, .rejection-text');
        var totalTextLength = 0;
        var allText = '';
        
        // Calculate total text length
        commentTexts.forEach(function(textElement) {
            totalTextLength += textElement.textContent.length;
            allText += textElement.outerHTML;
        });
        
        // If total text is too long (more than 100 characters), add show more functionality
        if (totalTextLength > 100) {
            // Create short version (first 100 characters)
            var shortVersion = '';
            var charCount = 0;
            var maxChars = 100;
            
            commentTexts.forEach(function(textElement) {
                var text = textElement.textContent;
                var html = textElement.outerHTML;
                
                if (charCount < maxChars) {
                    var remaining = maxChars - charCount;
                    if (text.length > remaining) {
                        // Truncate this element
                        shortVersion += html.replace(text, text.substring(0, remaining) + '...');
                        charCount = maxChars;
                    } else {
                        // Use full element
                        shortVersion += html;
                        charCount += text.length;
                    }
                }
            });
            
            // Replace container content with short version and show more link
            container.innerHTML = 
                '<div class="short-comments">' + shortVersion + '</div>' +
                '<div class="full-comments" style="display: none;">' + allText + '</div>' +
                '<a href="#" class="show-more-link" style="font-size: 11px; display: block; margin-top: 3px;">Show more</a>';
            
            // Add click event to show more link
            var showMoreLink = container.querySelector('.show-more-link');
            showMoreLink.addEventListener('click', function(e) {
                e.preventDefault();
                var shortComments = container.querySelector('.short-comments');
                var fullComments = container.querySelector('.full-comments');
                
                if (fullComments.style.display === 'none') {
                    shortComments.style.display = 'none';
                    fullComments.style.display = 'block';
                    this.textContent = 'Show less';
                } else {
                    shortComments.style.display = 'block';
                    fullComments.style.display = 'none';
                    this.textContent = 'Show more';
                }
            });
        }
    });
});
</script>
<?php endif; ?>
</div>

<?php if(!empty($leaveApps)): ?>
<script>
// Initialize Bootstrap tooltips
document.addEventListener('DOMContentLoaded', function() {
    var tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
    var tooltipList = tooltipTriggerList.map(function (tooltipTriggerEl) {
        return new bootstrap.Tooltip(tooltipTriggerEl);
    });
    
    // Truncate long comments and add "Show more" functionality
    document.querySelectorAll('.supervisor-comments').forEach(function(element) {
        var content = element.innerHTML;
        if (content.length > 150) {
            var shortContent = content.substring(0, 150) + '...';
            var fullContent = content;
            element.innerHTML = shortContent + 
                ' <a href="#" class="show-more-link" style="font-size: 12px;">Show more</a>' +
                '<div class="full-content" style="display: none;">' + fullContent + '</div>';
            
            element.querySelector('.show-more-link').addEventListener('click', function(e) {
                e.preventDefault();
                var fullContentDiv = this.nextElementSibling;
                if (fullContentDiv.style.display === 'none') {
                    fullContentDiv.style.display = 'block';
                    this.textContent = 'Show less';
                } else {
                    fullContentDiv.style.display = 'none';
                    this.textContent = 'Show more';
                }
            });
        }
    });
});
</script>
<?php endif; ?>
    </div>
</div>

<?php require_once __DIR__ . '/include/footer.php'; ?>