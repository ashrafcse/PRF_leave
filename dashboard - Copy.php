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
    echo '<a href="leave.php" class="btn btn-primary mt-2">Go to Leave Page to Link Employee</a>';
    echo '</div>';
    echo '</div>';
    require_once __DIR__ . '/include/footer.php';
    exit;
}

/* ==========================
   FETCH BASIC PROFILE DETAILS
========================== */
$profile = get_current_employee_details($conn);

/* ==========================
   FETCH RELATED DATA SEPARATELY
========================== */
$departmentName = '';
$locationName = '';
$designationName = '';
$adminSupervisorName = '';
$techSupervisorName = '';
$secondSupervisorName = '';

if (!empty($profile)) {
    try {
        // Get Department Name
        if (!empty($profile['DepartmentID'])) {
            try {
                $stmt = $conn->prepare(
                    "SELECT DepartmentName 
                     FROM [dbPRFAssetMgt].[dbo].[Departments] 
                     WHERE DepartmentID = :deptId"
                );
                $stmt->bindParam(':deptId', $profile['DepartmentID'], PDO::PARAM_INT);
                $stmt->execute();
                $dept = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($dept && isset($dept['DepartmentName'])) {
                    $departmentName = $dept['DepartmentName'];
                }
            } catch (Exception $e) {
                $departmentName = 'Dept ID: ' . $profile['DepartmentID'];
            }
        }
        
        // Get Location Name
        if (!empty($profile['LocationID'])) {
            try {
                $stmt = $conn->prepare(
                    "SELECT LocationName 
                     FROM [dbPRFAssetMgt].[dbo].[Locations] 
                     WHERE LocationID = :locId"
                );
                $stmt->bindParam(':locId', $profile['LocationID'], PDO::PARAM_INT);
                $stmt->execute();
                $loc = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($loc && isset($loc['LocationName'])) {
                    $locationName = $loc['LocationName'];
                }
            } catch (Exception $e) {
                $locationName = 'Loc ID: ' . $profile['LocationID'];
            }
        }
        
        // Get Designation Name from Designation table
        if (!empty($profile['JobTitleID'])) {
            try {
                $stmt = $conn->prepare("
                    SELECT JobTitleName
                    FROM [dbPRFAssetMgt].[dbo].[Designation]
                    WHERE JobTitleID = :jobId AND IsActive = 1
                ");
                $stmt->bindParam(':jobId', $profile['JobTitleID'], PDO::PARAM_INT);
                $stmt->execute();
                $job = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($job && isset($job['JobTitleName'])) {
                    $designationName = $job['JobTitleName'];
                }
            } catch (Exception $e) {
                error_log('Designation fetch error: ' . $e->getMessage());
                $designationName = 'Designation ID: ' . $profile['JobTitleID'];
            }
        }
        
        // Get Admin Supervisor Name
        if (!empty($profile['SupervisorID_admin'])) {
            try {
                $stmt = $conn->prepare("
                    SELECT FirstName, LastName, EmployeeCode
                    FROM [dbPRFAssetMgt].[dbo].[Employees]
                    WHERE EmployeeID = :supId
                ");
                $stmt->bindParam(':supId', $profile['SupervisorID_admin'], PDO::PARAM_INT);
                $stmt->execute();
                $sup = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($sup) {
                    $firstName = isset($sup['FirstName']) ? $sup['FirstName'] : '';
                    $lastName  = isset($sup['LastName'])  ? $sup['LastName']  : '';
                    $adminSupervisorName = trim($firstName . ' ' . $lastName);
                    if (isset($sup['EmployeeCode']) && $sup['EmployeeCode'] !== '') {
                        $adminSupervisorName .= ' (' . $sup['EmployeeCode'] . ')';
                    }
                }
            } catch (Exception $e) {
                $adminSupervisorName = 'Sup ID: ' . $profile['SupervisorID_admin'];
            }
        }
        
        // Get Technical Supervisor Name
        if (!empty($profile['SupervisorID_technical'])) {
            try {
                $stmt = $conn->prepare("
                    SELECT FirstName, LastName, EmployeeCode
                    FROM [dbPRFAssetMgt].[dbo].[Employees]
                    WHERE EmployeeID = :supId
                ");
                $stmt->bindParam(':supId', $profile['SupervisorID_technical'], PDO::PARAM_INT);
                $stmt->execute();
                $sup = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($sup) {
                    $firstName = isset($sup['FirstName']) ? $sup['FirstName'] : '';
                    $lastName  = isset($sup['LastName'])  ? $sup['LastName']  : '';
                    $techSupervisorName = trim($firstName . ' ' . $lastName);
                    if (isset($sup['EmployeeCode']) && $sup['EmployeeCode'] !== '') {
                        $techSupervisorName .= ' (' . $sup['EmployeeCode'] . ')';
                    }
                }
            } catch (Exception $e) {
                $techSupervisorName = 'Sup ID: ' . $profile['SupervisorID_technical'];
            }
        }
        
        // Get 2nd Level Supervisor Name
        if (!empty($profile['SupervisorID_2ndLevel'])) {
            try {
                $stmt = $conn->prepare("
                    SELECT FirstName, LastName, EmployeeCode
                    FROM [dbPRFAssetMgt].[dbo].[Employees]
                    WHERE EmployeeID = :supId
                ");
                $stmt->bindParam(':supId', $profile['SupervisorID_2ndLevel'], PDO::PARAM_INT);
                $stmt->execute();
                $sup = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($sup) {
                    $firstName = isset($sup['FirstName']) ? $sup['FirstName'] : '';
                    $lastName  = isset($sup['LastName'])  ? $sup['LastName']  : '';
                    $secondSupervisorName = trim($firstName . ' ' . $lastName);
                    if (isset($sup['EmployeeCode']) && $sup['EmployeeCode'] !== '') {
                        $secondSupervisorName .= ' (' . $sup['EmployeeCode'] . ')';
                    }
                }
            } catch (Exception $e) {
                $secondSupervisorName = 'Sup ID: ' . $profile['SupervisorID_2ndLevel'];
            }
        }
        
    } catch(Exception $e){
        error_log("Related data fetch error: " . $e->getMessage());
    }
}


$pendingCount = 0;
$supervisorId = $employeeId; // ✅ USE mapped employee ID

if ($supervisorId) {

    $sql = "
    SELECT COUNT(*) 
    FROM [dbPRFAssetMgt].[dbo].[LeaveApplications] LA
    INNER JOIN [dbPRFAssetMgt].[dbo].[Employees] E
        ON LA.EmployeeID = E.EmployeeID
    WHERE 
        LA.Status = 0
        AND (
            E.SupervisorID_admin = :sup1
            OR E.SupervisorID_technical = :sup2
            OR E.SupervisorID_2ndLevel = :sup3
        )
";


    $stmt = $conn->prepare($sql);
    $stmt->bindValue(':sup1', $supervisorId, PDO::PARAM_INT);
    $stmt->bindValue(':sup2', $supervisorId, PDO::PARAM_INT);
    $stmt->bindValue(':sup3', $supervisorId, PDO::PARAM_INT);
    $stmt->execute();

    $pendingCount = (int) $stmt->fetchColumn();
}


// replace with actual DB count


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
   FETCH LEAVE APPLICATIONS - IMPROVED VERSION
========================== */
$leaveApps = [];
try {
    // First, let's check what tables exist
    $testQuery = "SELECT COUNT(*) as total FROM [dbPRFAssetMgt].[dbo].[LeaveApplications]";
    $testStmt = $conn->prepare($testQuery);
    $testStmt->execute();
    $tableCheck = $testStmt->fetch(PDO::FETCH_ASSOC);
    
    // Check if table has data for this employee
    $checkQuery = "SELECT COUNT(*) as emp_count FROM [dbPRFAssetMgt].[dbo].[LeaveApplications] WHERE EmployeeID = :eid";
    $checkStmt = $conn->prepare($checkQuery);
    $checkStmt->execute([':eid' => $employeeId]);
    $empCount = $checkStmt->fetch(PDO::FETCH_ASSOC);
    
    // Debug output
    // echo "<!-- DEBUG: Table exists with " . $tableCheck['total'] . " total records -->";
    // echo "<!-- DEBUG: This employee has " . $empCount['emp_count'] . " applications -->";
    
    if ($empCount['emp_count'] > 0) {
        // Try to get columns from the table
        $colQuery = "
            SELECT COLUMN_NAME 
            FROM INFORMATION_SCHEMA.COLUMNS 
            WHERE TABLE_NAME = 'LeaveApplications' 
            AND TABLE_SCHEMA = 'dbo'
            AND TABLE_CATALOG = 'dbPRFAssetMgt'
            ORDER BY ORDINAL_POSITION
        ";
        
        $colStmt = $conn->prepare($colQuery);
        $colStmt->execute();
        $columns = $colStmt->fetchAll(PDO::FETCH_COLUMN);
        
        // echo "<!-- DEBUG: Columns found: " . implode(', ', $columns) . " -->";
        
        // Build query based on available columns
        $selectColumns = [];
        $baseColumns = [
            'LeaveApplicationID', 'LeaveTypeID', 'StartDate', 'EndDate', 
            'TotalDays', 'Reason', 'Status', 'AppliedDate'
        ];
        
        // Check which columns actually exist
        foreach ($baseColumns as $col) {
            if (in_array($col, $columns)) {
                $selectColumns[] = $col;
            }
        }
        
        if (empty($selectColumns)) {
            // Fallback to simple select
            $stmt = $conn->prepare("
                SELECT TOP 10 *
                FROM [dbPRFAssetMgt].[dbo].[LeaveApplications]
                WHERE EmployeeID = :eid
                ORDER BY AppliedDate DESC
            ");
        } else {
            // Use verified columns
            $columnList = implode(', ', $selectColumns);
            $stmt = $conn->prepare("
                SELECT TOP 10 {$columnList}
                FROM [dbPRFAssetMgt].[dbo].[LeaveApplications]
                WHERE EmployeeID = :eid
                ORDER BY AppliedDate DESC
            ");
        }
        
        $stmt->execute([':eid' => $employeeId]);
        $leaveApps = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Debug the results
        // echo "<!-- DEBUG: Retrieved " . count($leaveApps) . " applications -->";
        // if (count($leaveApps) > 0) {
        //     echo "<!-- DEBUG: First app: " . print_r($leaveApps[0], true) . " -->";
        // }
        
        // Get supervisor names for each application if approval columns exist
        if (in_array('L1ApprovedBy', $columns) || in_array('L2ApprovedBy', $columns) || in_array('RejectedBy', $columns)) {
            foreach ($leaveApps as &$app) {
                $app['Approvals'] = [];
                
                // L1 Approval
                if (!empty($app['L1ApprovedBy'])) {
                    try {
                        $stmt = $conn->prepare("
                            SELECT FirstName, LastName, EmployeeCode
                            FROM [dbPRFAssetMgt].[dbo].[Employees]
                            WHERE EmployeeID = :supId
                        ");
                        $stmt->execute([':supId' => $app['L1ApprovedBy']]);
                        $sup = $stmt->fetch(PDO::FETCH_ASSOC);
                        if ($sup) {
                            $app['Approvals']['L1'] = [
                                'name' => trim((isset($sup['FirstName']) ? $sup['FirstName'] : '') . ' ' . (isset($sup['LastName']) ? $sup['LastName'] : '')),
                                'code' => isset($sup['EmployeeCode']) ? $sup['EmployeeCode'] : '',
                                'date' => isset($app['L1ApprovedDate']) ? $app['L1ApprovedDate'] : '',
                                'comments' => isset($app['L1SupervisorComments']) ? $app['L1SupervisorComments'] : ''
                            ];
                        }
                    } catch(Exception $e) {}
                }
                
                // L2 Approval
                if (!empty($app['L2ApprovedBy'])) {
                    try {
                        $stmt = $conn->prepare("
                            SELECT FirstName, LastName, EmployeeCode
                            FROM [dbPRFAssetMgt].[dbo].[Employees]
                            WHERE EmployeeID = :supId
                        ");
                        $stmt->execute([':supId' => $app['L2ApprovedBy']]);
                        $sup = $stmt->fetch(PDO::FETCH_ASSOC);
                        if ($sup) {
                            $app['Approvals']['L2'] = [
                                'name' => trim((isset($sup['FirstName']) ? $sup['FirstName'] : '') . ' ' . (isset($sup['LastName']) ? $sup['LastName'] : '')),
                                'code' => isset($sup['EmployeeCode']) ? $sup['EmployeeCode'] : '',
                                'date' => isset($app['L2ApprovedDate']) ? $app['L2ApprovedDate'] : '',
                                'comments' => isset($app['L2SupervisorComments']) ? $app['L2SupervisorComments'] : ''
                            ];
                        }
                    } catch(Exception $e) {}
                }
                
                // Rejection
                if (!empty($app['RejectedBy'])) {
                    try {
                        $stmt = $conn->prepare("
                            SELECT FirstName, LastName, EmployeeCode
                            FROM [dbPRFAssetMgt].[dbo].[Employees]
                            WHERE EmployeeID = :supId
                        ");
                        $stmt->execute([':supId' => $app['RejectedBy']]);
                        $sup = $stmt->fetch(PDO::FETCH_ASSOC);
                        if ($sup) {
                            $app['Approvals']['Rejected'] = [
                                'name' => trim((isset($sup['FirstName']) ? $sup['FirstName'] : '') . ' ' . (isset($sup['LastName']) ? $sup['LastName'] : '')),
                                'code' => isset($sup['EmployeeCode']) ? $sup['EmployeeCode'] : '',
                                'date' => isset($app['RejectedDate']) ? $app['RejectedDate'] : '',
                                'comments' => isset($app['RejectionReason']) ? $app['RejectionReason'] : ''
                            ];
                        }
                    } catch(Exception $e) {}
                }
            }
        }
    }
    
} catch(Exception $e){
    error_log("Leave applications fetch error: " . $e->getMessage());
    // Alternative table name check
    try {
        // Try with different table name
        $stmt = $conn->prepare("
            SELECT TOP 10 *
            FROM [dbPRFAssetMgt].[dbo].[leave_applications]
            WHERE EmployeeID = :eid
            ORDER BY AppliedDate DESC
        ");
        $stmt->execute([':eid' => $employeeId]);
        $leaveApps = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        if (count($leaveApps) > 0) {
            error_log("Note: Found data in 'leave_applications' table (lowercase)");
        }
    } catch(Exception $e2) {
        error_log("Alternative table also failed: " . $e2->getMessage());
    }
}

/* ==========================
   FETCH PENDING LEAVE APPLICATIONS
   (Where Status = 0 - Pending)
========================== */
$pendingLeaveApps = [];
try {
    $stmt = $conn->prepare("
        SELECT 
            LeaveApplicationID,
            LeaveTypeID,
            StartDate,
            EndDate,
            TotalDays,
            Reason,
            Status,
            AppliedDate
        FROM [dbPRFAssetMgt].[dbo].[LeaveApplications]
        WHERE EmployeeID = :eid 
        AND Status = 0
        ORDER BY AppliedDate DESC
    ");
    $stmt->execute([':eid' => $employeeId]);
    $pendingLeaveApps = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch(Exception $e){
    error_log("Pending leave applications fetch error: " . $e->getMessage());
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
    .total-leave {
        background: linear-gradient(135deg, #3b82f6 0%, #1d4ed8 100%);
        color: white;
        padding: 15px;
        border-radius: 10px;
        text-align: center;
        margin-top: 20px;
    }
    .total-leave-value {
        font-size: 28px;
        font-weight: 700;
        margin: 5px 0;
    }
    .total-leave-label {
        opacity: 0.9;
        font-size: 14px;
    }
    .supervisor-info {
        font-size: 12px;
        color: #6b7280;
    }
    .status-details {
        font-size: 12px;
        color: #6b7280;
        margin-top: 2px;
    }
    .employee-code-badge {
        background: rgba(255,255,255,0.2);
        padding: 2px 8px;
        border-radius: 12px;
        font-size: 12px;
        margin-left: 10px;
    }
    
    /* Pending applications highlight */
    .pending-badge {
        background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%);
        color: white;
        padding: 3px 8px;
        border-radius: 12px;
        font-size: 11px;
        font-weight: 600;
    }
    
    /* Debug styles */
    .debug-info {
        background: #f8f9fa;
        border: 1px solid #dee2e6;
        border-radius: 5px;
        padding: 15px;
        margin-bottom: 20px;
        font-family: monospace;
        font-size: 12px;
    }
</style>

<div class="container my-4">

    <!-- Debug Information (remove in production) -->
    <!--
    <div class="debug-info">
        <strong>Debug Information:</strong><br>
        Employee ID: <?php echo $employeeId; ?><br>
        Employee Code: <?php echo isset($profile['EmployeeCode']) ? $profile['EmployeeCode'] : 'N/A'; ?><br>
        Total Applications Found: <?php echo count($leaveApps); ?><br>
        Pending Applications Found: <?php echo count($pendingLeaveApps); ?><br>
        <?php if (count($pendingLeaveApps) > 0): ?>
            First Pending Application:<br>
            <pre style="font-size: 10px;"><?php print_r($pendingLeaveApps[0]); ?></pre>
        <?php endif; ?>
    </div>
    -->

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

                    <!-- <div class="info-item">
                        <span class="info-label">Designation</span>
                        <span class="info-value">
                            <?php echo h(!empty($designationName) ? $designationName : 'N/A'); ?>
                        </span>
                    </div> -->

                    <div class="info-item">
                        <span class="info-label">Location</span>
                        <span class="info-value">
                            <?php echo h(!empty($locationName) ? $locationName : 'N/A'); ?>
                        </span>
                    </div>

                    <div class="info-item">
                        <span class="info-label">Admin Supervisor</span>
                        <span class="info-value">
                            <?php echo h(!empty($adminSupervisorName) ? $adminSupervisorName : 'Not Assigned'); ?>
                        </span>
                    </div>

                    <div class="info-item">
                        <span class="info-label">Technical Supervisor</span>
                        <span class="info-value">
                            <?php echo h(!empty($techSupervisorName) ? $techSupervisorName : 'Not Assigned'); ?>
                        </span>
                    </div>

                    <div class="info-item">
                        <span class="info-label">2nd Level Supervisor</span>
                        <span class="info-value">
                            <?php echo h(!empty($secondSupervisorName) ? $secondSupervisorName : 'Not Assigned'); ?>
                        </span>
                    </div>
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
                            foreach($leaveBalances as $lb): 
                                $remaining = (float)(isset($lb['RemainingDays']) ? $lb['RemainingDays'] : 0);
                                $totalRemaining += $remaining;
                                
                                $leaveTypeId = isset($lb['LeaveTypeID']) ? $lb['LeaveTypeID'] : '';
                                $leaveTypeName = isset($leaveTypes[$leaveTypeId]) ? $leaveTypes[$leaveTypeId] : $leaveTypeId;
                                
                                $openingBalance = isset($lb['OpeningBalance']) ? $lb['OpeningBalance'] : 0;
                                $usedDays = isset($lb['UsedDays']) ? $lb['UsedDays'] : 0;
                                $leaveYear = isset($lb['LeaveYear']) ? $lb['LeaveYear'] : '';
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
       <style>
    .pending-app-wrapper {
        display: flex;
        justify-content: center;
        padding-right: -120px;   /* moves slightly right */
        margin-top: 20px;
    }

    .pending-app-btn {
        display: flex;
        align-items: center;
        gap: 10px;
        padding: 12px 22px;
        background: linear-gradient(135deg, #f6b73c, #f57c00);
        color: #fff !important;
        font-weight: 600;
        border-radius: 30px;
        text-decoration: none;
        box-shadow: 0 8px 20px rgba(0, 0, 0, 0.15);
        transition: all 0.3s ease;
    }

    .pending-app-btn i {
        font-size: 18px;
    }

    .pending-app-btn:hover {
        transform: translateY(-3px);
        box-shadow: 0 12px 25px rgba(0, 0, 0, 0.25);
        background: linear-gradient(135deg, #f57c00, #ef6c00);
        color: #fff;
        text-decoration: none;
    }
    <style>
.pending-app-wrapper {
    border: 1px solid #e6e9ef;
    padding: 15px 20px;
    border-radius: 12px;
    background-color: #f9f9f9;
}

/* Pending Applications Link */
.pending-app-btn {
    text-decoration: none;
    color: #333;
    font-weight: 500;
    display: flex;
    align-items: center;
    gap: 8px;
}

.pending-app-btn:hover {
    color: #0d6efd;
}

.pending-app-wrapper .btn {
    border-radius: 25px; /* more rounded */
    padding: 10px 20px; /* bigger size */
    font-size: 1rem; /* bigger text */
    font-weight: 600;
    transition: all 0.3s ease;
}

/* Hover effect for Apply Leave button */
.pending-app-wrapper .btn:hover {
    background-color: #0b5ed7; /* darker blue */
    transform: scale(1.05);
}

/* Pending count styling */
.pending-count {
    background-color: #dc3545;
    color: #fff;
    border-radius: 50%;
    padding: 2px 8px;
    font-size: 0.85rem;
    margin-left: 6px;
}

.pending-app-wrapper li {
    list-style: none;
}

</style>

<div class="pending-app-wrapper d-flex justify-content-between align-items-center">
    
    <!-- Pending Applications Button -->
    <li>
        <a class="pending-app-btn"
           href="<?php echo htmlspecialchars(url_to('/pages/leave/leave_approval_supervisor.php')); ?>">
            <i class="fa-solid fa-user-check"></i>
            <span>Pending Applications For Approval</span>

            <?php if ($pendingCount > 0): ?>
                <span class="pending-count">
                    <strong><?php echo (int)$pendingCount; ?></strong>
                </span>
            <?php endif; ?>
        </a>
    </li>

    <!-- Apply Leave Button (Right Side) -->
    <div>
        <a href="/PRF_Leave/pages/leave/leave_apply.php" class="btn btn-primary btn-lg">
            <i class="bi bi-plus-circle"></i> Apply for Leave
        </a>
    </div>

</div>




    </div>

    <!-- PENDING LEAVE APPLICATIONS CARD (NEW SECTION) -->
    <!-- <div class="card-box mb-4">
        <h5 class="card-title">
            <i class="bi bi-clock me-2"></i>Your Pending Leave Applications
            <?php if(count($pendingLeaveApps) > 0): ?>
                <span class="pending-badge ms-2"><?php echo count($pendingLeaveApps); ?> Pending</span>
            <?php endif; ?>
        </h5>

        <?php if(!empty($pendingLeaveApps)): ?>
            <div class="table-responsive">
                <table class="table table-hover">
                    <thead class="table-light">
                        <tr>
                            <th>Application #</th>
                            <th>Leave Type</th>
                            <th>Period</th>
                            <th class="text-center">Days</th>
                            <th>Status</th>
                            <th>Reason</th>
                            <th>Applied On</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php 
                        foreach($pendingLeaveApps as $la): 
                            $leaveTypeId = isset($la['LeaveTypeID']) ? $la['LeaveTypeID'] : '';
                            $leaveTypeName = isset($leaveTypes[$leaveTypeId]) ? $leaveTypes[$leaveTypeId] : 'N/A';
                            
                            $startDate = !empty($la['StartDate']) ? date('d M Y', strtotime($la['StartDate'])) : 'N/A';
                            $endDate = !empty($la['EndDate']) ? date('d M Y', strtotime($la['EndDate'])) : 'N/A';
                            $appliedDate = !empty($la['AppliedDate'])
    ? date('d M Y', strtotime($la['AppliedDate']))
    : 'N/A';

                            $totalDays = isset($la['TotalDays']) ? $la['TotalDays'] : 0;
                            $reason = isset($la['Reason']) ? $la['Reason'] : '';
                        ?>
                        <tr>
                            <td>
                                <strong>#<?php echo h(isset($la['LeaveApplicationID']) ? $la['LeaveApplicationID'] : 'N/A'); ?></strong>
                            </td>
                            <td><?php echo h($leaveTypeName); ?></td>
                            <td>
                                <div><?php echo h($startDate); ?></div>
                                <small class="text-muted">to <?php echo h($endDate); ?></small>
                            </td>
                            <td class="text-center">
                                <span class="badge bg-warning text-dark border">
                                    <?php echo h($totalDays); ?> day(s)
                                </span>
                            </td>
                            <td>
                                <span class="badge-status badge-pending">
                                    <i class="bi bi-clock me-1"></i>Pending
                                </span>
                            </td>
                            <td>
                                <?php if(!empty($reason)): ?>
                                    <div class="text-truncate" style="max-width: 200px;" title="<?php echo h($reason); ?>">
                                        <?php echo h(substr($reason, 0, 50)); ?>
                                        <?php if(strlen($reason) > 50): ?>...<?php endif; ?>
                                    </div>
                                <?php else: ?>
                                    <span class="text-muted">No reason provided</span>
                                <?php endif; ?>
                            </td>
                            <td><small class="text-muted"><?php echo h($appliedDate); ?></small></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <!-- <div class="text-center mt-3">
                <a href="/PRF_Leave/pages/leave/leave.php" class="btn btn-outline-warning btn-sm">
                    <i class="bi bi-list-check"></i> View All Pending Applications
                </a>
            </div> -->
        <?php else: ?>
            <div class="text-center py-4">
                <i class="bi bi-check-circle text-success" style="font-size: 48px;"></i>
                <p class="text-muted mt-3">No pending leave applications</p>
                <p class="text-muted small mb-4">All your leave applications have been processed.</p>
                <div class="text-center">
                    <a href="/PRF_Leave/pages/leave/leave_apply.php" class="btn btn-primary btn-sm">
                        <i class="bi bi-plus-circle"></i> Apply for New Leave
                    </a>
                </div>
            </div>
        <?php endif; ?>
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
                            <th>Status</th>
                            <th>Applied On</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php 
                        foreach($leaveApps as $la): 
                            $leaveTypeId = isset($la['LeaveTypeID']) ? $la['LeaveTypeID'] : '';
                            $leaveTypeName = isset($leaveTypes[$leaveTypeId]) ? $leaveTypes[$leaveTypeId] : 'N/A';
                            
                            $status = isset($la['Status']) ? (int)$la['Status'] : 0;
                            switch($status) {
                                case 0: $statusText = 'Pending'; $badgeClass = 'badge-pending'; break;
                                case 1: $statusText = 'L1 Approved'; $badgeClass = 'badge-approved'; break;
                                case 2: $statusText = 'L2 Approved'; $badgeClass = 'badge-approved'; break;
                                case 3: $statusText = 'Rejected'; $badgeClass = 'badge-rejected'; break;
                                case 4: $statusText = 'Cancelled'; $badgeClass = 'badge-cancelled'; break;
                                default: $statusText = 'Pending'; $badgeClass = 'badge-pending'; break;
                            }
                            
                            $startDate = !empty($la['StartDate']) ? date('d M Y', strtotime($la['StartDate'])) : 'N/A';
                            $endDate = !empty($la['EndDate']) ? date('d M Y', strtotime($la['EndDate'])) : 'N/A';
                            $appliedDate = !empty($la['AppliedDate'])
    ? date('d M Y', strtotime($la['AppliedDate']))
    : 'N/A';

                            $totalDays = isset($la['TotalDays']) ? $la['TotalDays'] : 0;
                        ?>
                        <tr>
                            <td>#<?php echo h(isset($la['LeaveApplicationID']) ? $la['LeaveApplicationID'] : 'N/A'); ?></td>
                            <td><?php echo h($leaveTypeName); ?></td>
                            <td>
                                <div><?php echo h($startDate); ?></div>
                                <small class="text-muted">to <?php echo h($endDate); ?></small>
                            </td>
                            <td class="text-center">
                                <span class="badge bg-light text-dark border">
                                    <?php echo h($totalDays); ?> day(s)
                                </span>
                            </td>
                            <td>
                                <span class="badge-status <?php echo $badgeClass; ?>">
                                    <?php echo h($statusText); ?>
                                </span>
                            </td>
                            <td><small class="text-muted"><?php echo h($appliedDate); ?></small></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <!-- <div class="text-center mt-3">
                <!-- <a href="/PRF_Leave/pages/leave/leave.php" class="btn btn-outline-primary btn-sm">View All Applications</a> -->
                <!-- <a href="/PRF_Leave/pages/leave/leave_apply.php" class="btn btn-primary btn-sm ms-2">
                    <i class="bi bi-plus-circle"></i> Apply for Leave
                </a> -->
            </div> -->
        <?php else: ?>
            <div class="text-center py-4">
                <i class="bi bi-inbox text-muted" style="font-size: 48px;"></i>
                <p class="text-muted mt-3">No leave applications found.</p>
                <p class="text-muted small mb-4">You haven't applied for any leave yet.</p>
                <!-- <div class="text-center">
                    <a href="/PRF_Leave/pages/leave/leave_apply.php" class="btn btn-primary">
                        <i class="bi bi-plus-circle"></i> Apply for Leave
                    </a>
                </div> -->
            </div>
        <?php endif; ?>
    </div>
</div>

<?php require_once __DIR__ . '/include/footer.php'; ?>