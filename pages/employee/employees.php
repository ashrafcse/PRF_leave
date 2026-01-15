<?php 
/***********************
 * Employees - Full CRUD (SQL Server, PDO)  [PHP 5.6 compatible]
 * - Table is shown first
 * - Pagination added
 * - Two supervisor dropdowns show ALL employees (Active first)
 * - Also saves to Users table with correct schema
 ***********************/
ini_set('display_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/../../init.php';
require_login();

/* ---------- Tiny helpers ---------- */
if (!function_exists('h')) {
    function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
}
function self_name(){ return strtok(basename($_SERVER['SCRIPT_NAME']), "?"); }
$self = self_name();
function as_null($v){ $v = trim((string)$v); return ($v===''? null : $v); }
function as_date_or_null($v){ $v = trim((string)$v); return ($v===''? null : $v); }
function errfmt(PDOException $e){
    $s=$e->getCode(); $m=$e->getMessage();
    $c = (preg_match('/constraint\s+\'?([^\']+)/i',$m,$mm)? " | Constraint: ".$mm[1] : "");
    return "SQLSTATE {$s}{$c} | {$m}";
}

/* ---------- Static option arrays ---------- */
$BLOOD_GROUPS = array('A+','A-','B+','B-','O+','O-','AB+','AB-');

$INCREMENT_MONTHS = array(
    '1' => 'January',
    '7' => 'July'
);

$JOB_TYPES = array('Fixed term','Contractual');

$SALARY_LEVELS = array(
    'GS-1','GS-2','GS-3','GS-4','GS-5','GS-6','GS-7',
    'NO-A','NO-B','NO-C','NO-D','NO-E'
);

/* ---------- CSRF ---------- */
if (!isset($_SESSION['csrf'])) {
    $_SESSION['csrf'] = function_exists('openssl_random_pseudo_bytes')
        ? bin2hex(openssl_random_pseudo_bytes(16))
        : substr(str_shuffle(md5(uniqid(mt_rand(), true))), 0, 32);
}
$CSRF = $_SESSION['csrf'];
function check_csrf(){
    if (!isset($_POST['csrf']) || $_POST['csrf'] !== $_SESSION['csrf']) die('Invalid CSRF token');
}

/* ---------- Alerts ---------- */
$msg=''; $msg_type='success'; $notes=array();

/* ---------- Column limits (match schema) ---------- */
/* PHP 5.6: use a normal variable (const arrays are not supported) */
$COL_LIMITS = array(
    'EmployeeCode'           => 50,
    'FirstName'              => 100,
    'LastName'               => 100,
    'NationalID'             => 100,
    'Email_Office'           => 200,
    'Email_Personal'         => 50,
    'Phone1'                 => 15,
    'Phone2'                 => 15,
    'Blood_group'            => 3,
    'Salary_increment_month' => 10,
    'Job_Type'               => 50,
    'Salary_level'           => 10,
    'Status'                 => 50
);

/* ---------- Sanitizer ---------- */
function sanitize_payload(array $p, array &$notes) {
    global $COL_LIMITS;

    // Emails → lowercase & trimmed
    foreach (array('Email_Office','Email_Personal') as $k) {
        if (isset($p[$k])) $p[$k] = trim(mb_strtolower((string)$p[$k]));
    }
    
    // PRF ID: Only digits allowed
    if (isset($p['EmployeeCode'])) {
        $empCode = trim((string)$p['EmployeeCode']);
        // Remove any non-digit characters
        $empCode = preg_replace('/[^0-9]/', '', $empCode);
        if ($empCode !== '') {
            $p['EmployeeCode'] = $empCode;
        } else {
            // If empty after removing non-digits, keep original for error message
            $p['EmployeeCode'] = trim((string)$p['EmployeeCode']);
        }
    }
    
    // Phone: Only digits allowed
    foreach (array('Phone1', 'Phone2') as $phoneField) {
        if (isset($p[$phoneField])) {
            $phone = trim((string)$p[$phoneField]);
            if ($phone !== '') {
                // Remove any non-digit characters except plus sign for international
                $phone = preg_replace('/[^0-9\+]/', '', $phone);
                $p[$phoneField] = $phone;
            }
        }
    }

    // Clamp to column limits
    foreach ($COL_LIMITS as $k=>$limit) {
        if (!array_key_exists($k,$p)) continue;
        $v = (string)$p[$k];
        if ($v === '') { $p[$k] = ''; continue; }
        if (mb_strlen($v) > $limit) {
            $notes[] = "$k was too long (".mb_strlen($v)." > {$limit}) and has been truncated.";
            $p[$k] = mb_substr($v, 0, $limit);
        }
    }

    return $p;
}

/* ---------- Identity detection ---------- */
function emp_is_identity(PDO $conn) {
    $stmt = $conn->query("SELECT COLUMNPROPERTY(OBJECT_ID('dbo.Employees'),'EmployeeID','IsIdentity')");
    return ((int)$stmt->fetchColumn()) === 1;
}

/* ---------- ALL employees list for both supervisor dropdowns ---------- */
function all_employees_for_supervisor(PDO $conn) {
    $sql = "
      SELECT
        e.EmployeeID AS id,
        LTRIM(RTRIM(e.FirstName + ' ' + ISNULL(e.LastName,''))) AS name,
        e.Status,
        e.EmployeeCode
      FROM dbo.Employees e
      ORDER BY CASE WHEN e.Status='Active' THEN 0 ELSE 1 END,
               e.FirstName, e.LastName
    ";
    return $conn->query($sql)->fetchAll(PDO::FETCH_ASSOC);
}

/* ---------- FK options (titles/dep/loc + supervisors) ---------- */
function load_fk_options(PDO $conn, $current=null){
    $opts = array('titles'=>array(), 'depts'=>array(), 'locs'=>array(), 'supers_admin'=>array(), 'supers_tech'=>array());

    $opts['titles']=$conn->query("
        SELECT JobTitleID AS id, JobTitleName AS name
        FROM dbo.Designation WHERE IsActive=1 ORDER BY JobTitleName
    ")->fetchAll(PDO::FETCH_ASSOC);

    $opts['depts']=$conn->query("
        SELECT DepartmentID AS id, DepartmentName AS name
        FROM dbo.Departments ORDER BY DepartmentName
    ")->fetchAll(PDO::FETCH_ASSOC);

    $opts['locs']=$conn->query("
        SELECT LocationID AS id, LocationName AS name
        FROM dbo.Locations ORDER BY LocationName
    ")->fetchAll(PDO::FETCH_ASSOC);

    // Both dropdowns use ALL employees (Active first)
    $all = all_employees_for_supervisor($conn);
    $opts['supers_admin'] = $all;
    $opts['supers_tech']  = $all;

    // Keep current selected supervisors even if not present (edge)
    if ($current) {
        foreach (array('SupervisorID_admin'=>'supers_admin','SupervisorID_technical'=>'supers_tech') as $field=>$key){
            $cur = isset($current[$field]) ? (int)$current[$field] : 0;
            if ($cur>0){
                $exists=false; foreach($opts[$key] as $r){ if((int)$r['id']===$cur){ $exists=true; break; } }
                if(!$exists){
                    $st=$conn->prepare("SELECT EmployeeID AS id, LTRIM(RTRIM(FirstName + ' ' + ISNULL(LastName,''))) AS name, Status FROM dbo.Employees WHERE EmployeeID=:id");
                    $st->execute(array(':id'=>$cur));
                    if($tmp=$st->fetch(PDO::FETCH_ASSOC)){
                        $tmp['name'].=' (not found in list)';
                        array_unshift($opts[$key],$tmp);
                    }
                }
            }
        }
    }
    return $opts;
}

/* ---------- Function to save/update user in Users table ---------- */
function sync_user_account(PDO $conn, array $employeeData, $isUpdate = false) {
    global $msg, $msg_type;
    
    try {
        // Default password: 1234567
        $defaultPassword = '1234567';
        $passwordHash = password_hash($defaultPassword, PASSWORD_DEFAULT);
        
        // Get the current user ID from session
        $createdBy = isset($_SESSION['auth_user']['UserID']) ? (int)$_SESSION['auth_user']['UserID'] : null;
        
        // Generate username from EmployeeCode (or use EmployeeCode itself)
        $username = $employeeData['EmployeeCode'];
        
        // Check if user already exists
        $checkStmt = $conn->prepare("SELECT UserID FROM [dbPRFAssetMgt].[dbo].[Users] WHERE EmployeeID = :EmployeeID");
        $checkStmt->execute(array(':EmployeeID' => $employeeData['EmployeeID']));
        $existingUser = $checkStmt->fetch(PDO::FETCH_ASSOC);
        
        if ($existingUser) {
            // Update existing user - match your actual schema
            $sql = "
                UPDATE [dbPRFAssetMgt].[dbo].[Users]
                SET 
                    Username = :Username,
                    PasswordHash = :PasswordHash,
                    Email = :Email,
                    EmployeeCode = :EmployeeCode,
                    SupervisorID_admin = :SupervisorID_admin,
                    SupervisorID_2ndLevel = :SupervisorID_2ndLevel,
                    UpdatedAt = GETDATE()
                WHERE EmployeeID = :EmployeeID
            ";
            
            $stmt = $conn->prepare($sql);
            $params = array(
                ':Username' => $username,
                ':PasswordHash' => $passwordHash,
                ':Email' => $employeeData['Email_Office'], // Use office email
                ':EmployeeCode' => $employeeData['EmployeeCode'],
                ':SupervisorID_admin' => $employeeData['SupervisorID_admin'],
                ':SupervisorID_2ndLevel' => $employeeData['SupervisorID_2ndLevel'],
                ':EmployeeID' => $employeeData['EmployeeID']
            );
            
            $stmt->execute($params);
            
            $msg .= " User account updated.";
            return true;
        } else {
            // Insert new user - match your actual schema exactly
            $sql = "
                INSERT INTO [dbPRFAssetMgt].[dbo].[Users]
                (Username, PasswordHash, Email, IsActive, CreatedAt, CreatedBy,
                 EmployeeID, EmployeeCode, SupervisorID_admin, SupervisorID_2ndLevel)
                VALUES
                (:Username, :PasswordHash, :Email, 1, GETDATE(), :CreatedBy,
                 :EmployeeID, :EmployeeCode, :SupervisorID_admin, :SupervisorID_2ndLevel)
            ";
            
            $stmt = $conn->prepare($sql);
            $params = array(
                ':Username' => $username,
                ':PasswordHash' => $passwordHash,
                ':Email' => $employeeData['Email_Office'], // Use office email
                ':CreatedBy' => $createdBy,
                ':EmployeeID' => $employeeData['EmployeeID'],
                ':EmployeeCode' => $employeeData['EmployeeCode'],
                ':SupervisorID_admin' => $employeeData['SupervisorID_admin'],
                ':SupervisorID_2ndLevel' => $employeeData['SupervisorID_2ndLevel']
            );
            
            $stmt->execute($params);
            
            $msg .= " User account created with password: 1234567";
            return true;
        }
    } catch (PDOException $e) {
        // Log error and add to message
        $errorMsg = "Failed to sync user account for EmployeeID {$employeeData['EmployeeID']}: " . $e->getMessage();
        error_log($errorMsg);
        
        // Try alternative - maybe we need to specify all columns
        try {
            // Try with all columns including optional ones
            $altSql = "
                INSERT INTO [dbPRFAssetMgt].[dbo].[Users]
                (Username, PasswordHash, Email, IsActive, CreatedAt, CreatedBy,
                 LastLoginAt, Avatar, EmployeeID, EmployeeCode, SupervisorID_admin, SupervisorID_2ndLevel)
                VALUES
                (:Username, :PasswordHash, :Email, 1, GETDATE(), :CreatedBy,
                 NULL, NULL, :EmployeeID, :EmployeeCode, :SupervisorID_admin, :SupervisorID_2ndLevel)
            ";
            
            $altStmt = $conn->prepare($altSql);
            $altStmt->execute(array(
                ':Username' => $username,
                ':PasswordHash' => password_hash('1234567', PASSWORD_DEFAULT),
                ':Email' => $employeeData['Email_Office'],
                ':CreatedBy' => $createdBy,
                ':EmployeeID' => $employeeData['EmployeeID'],
                ':EmployeeCode' => $employeeData['EmployeeCode'],
                ':SupervisorID_admin' => $employeeData['SupervisorID_admin'],
                ':SupervisorID_2ndLevel' => $employeeData['SupervisorID_2ndLevel']
            ));
            
            $msg .= " User account created (with all columns).";
            return true;
        } catch (PDOException $e2) {
            $errorMsg2 = "Alternate method also failed: " . $e2->getMessage();
            error_log($errorMsg2);
            
            // Let user know but don't fail the main operation
            $msg .= " Note: Could not create user account. Error: " . h($e->getMessage());
            return false;
        }
    }
}

/* ---------- Validate PRF ID (only digits) ---------- */
function validate_prf_id($prfId) {
    $prfId = trim((string)$prfId);
    if ($prfId === '') {
        return array(false, "PRF ID is required");
    }
    if (!preg_match('/^[0-9]+$/', $prfId)) {
        return array(false, "PRF ID must contain only digits (0-9)");
    }
    if (strlen($prfId) < 3) {
        return array(false, "PRF ID must be at least 3 digits");
    }
    if (strlen($prfId) > 20) {
        return array(false, "PRF ID must not exceed 20 digits");
    }
    return array(true, "");
}

/* ---------- Check if PRF ID already exists ---------- */
function check_prf_id_exists(PDO $conn, $prfId, $excludeId = 0) {
    $sql = "SELECT COUNT(*) FROM dbo.Employees WHERE EmployeeCode = :code";
    $params = array(':code' => $prfId);
    
    if ($excludeId > 0) {
        $sql .= " AND EmployeeID != :id";
        $params[':id'] = $excludeId;
    }
    
    $stmt = $conn->prepare($sql);
    $stmt->execute($params);
    return (int)$stmt->fetchColumn() > 0;
}

/* ---------- Validate mandatory fields ---------- */
function validate_mandatory_fields($payload) {
    $errors = array();
    
    // PRF ID validation
    if (!isset($payload['EmployeeCode']) || trim($payload['EmployeeCode']) === '') {
        $errors[] = "PRF ID (Employee Code) is required";
    }
    
    // First Name validation
    if (!isset($payload['FirstName']) || trim($payload['FirstName']) === '') {
        $errors[] = "First Name is required";
    }
    
    // National ID validation
    if (!isset($payload['NationalID']) || trim($payload['NationalID']) === '') {
        $errors[] = "National ID is required";
    } elseif (strlen(trim($payload['NationalID'])) < 10) {
        $errors[] = "National ID must be at least 10 characters";
    }
    
    // Phone 1 validation
    if (!isset($payload['Phone1']) || trim($payload['Phone1']) === '') {
        $errors[] = "Phone 1 is required";
    } elseif (!preg_match('/^[0-9\+][0-9]{9,14}$/', preg_replace('/[^0-9\+]/', '', $payload['Phone1']))) {
        $errors[] = "Phone 1 must be a valid phone number (10-15 digits, can start with +)";
    }
    
    // Joining Date validation
    if (!isset($payload['HireDate']) || trim($payload['HireDate']) === '') {
        $errors[] = "Joining Date is required";
    } else {
        $hireDate = strtotime($payload['HireDate']);
        if ($hireDate === false) {
            $errors[] = "Joining Date must be a valid date";
        }
    }
    
    // DOB validation
    if (!isset($payload['DOB']) || trim($payload['DOB']) === '') {
        $errors[] = "Date of Birth (DOB) is required";
    } else {
        $dob = strtotime($payload['DOB']);
        if ($dob === false) {
            $errors[] = "Date of Birth (DOB) must be a valid date";
        } elseif (isset($payload['HireDate']) && $payload['HireDate'] !== '') {
            // Check if DOB is before HireDate
            $hireDate = strtotime($payload['HireDate']);
            $dob = strtotime($payload['DOB']);
            if ($dob >= $hireDate) {
                $errors[] = "Date of Birth must be before Joining Date";
            }
        }
    }
    
    // Job Type validation
    if (!isset($payload['Job_Type']) || trim($payload['Job_Type']) === '') {
        $errors[] = "Job Type is required";
    }
    
    // Designation validation
    if (!isset($payload['JobTitleID']) || (int)$payload['JobTitleID'] === 0) {
        $errors[] = "Designation is required";
    }
    
    // Department validation
    if (!isset($payload['DepartmentID']) || (int)$payload['DepartmentID'] === 0) {
        $errors[] = "Department is required";
    }
    
    // Location validation
    if (!isset($payload['LocationID']) || (int)$payload['LocationID'] === 0) {
        $errors[] = "Location is required";
    }
    
    // Status validation
    if (!isset($payload['Status']) || trim($payload['Status']) === '') {
        $errors[] = "Status is required";
    }
    
    return $errors;
}

/* ---------- Create / Update DB functions ---------- */
function create_employee(PDO $conn, array $p) {
    global $msg, $msg_type;
    
    $dummyNotes = array();
    $p = sanitize_payload($p, $dummyNotes);

    $usesIdentity = emp_is_identity($conn);

    if (!$usesIdentity) {
        $conn->exec("SET TRANSACTION ISOLATION LEVEL SERIALIZABLE");
        $conn->beginTransaction();
    }

    try {
        $params = array(
            ':EmployeeCode'           => trim((string)$p['EmployeeCode']),
            ':FirstName'              => trim((string)$p['FirstName']),
            ':LastName'               => as_null(isset($p['LastName']) ? $p['LastName'] : ''),
            ':NationalID'             => as_null(isset($p['NationalID']) ? $p['NationalID'] : ''),
            ':Email_Office'           => as_null(isset($p['Email_Office']) ? $p['Email_Office'] : ''),
            ':Email_Personal'         => as_null(isset($p['Email_Personal']) ? $p['Email_Personal'] : ''),
            ':Phone1'                 => as_null(isset($p['Phone1']) ? $p['Phone1'] : ''),
            ':Phone2'                 => as_null(isset($p['Phone2']) ? $p['Phone2'] : ''),
            ':JobTitleID'             => (int)$p['JobTitleID'],
            ':DepartmentID'           => (int)$p['DepartmentID'],
            ':LocationID'             => (int)$p['LocationID'],
            ':SupervisorID_admin'     => ($p['SupervisorID_admin']!==''?(int)$p['SupervisorID_admin']:null),
            ':SupervisorID_technical' => ($p['SupervisorID_technical']!==''?(int)$p['SupervisorID_technical']:null),
            ':HireDate'               => as_date_or_null(isset($p['HireDate']) ? $p['HireDate'] : ''),
            ':EndDate'                => as_date_or_null(isset($p['EndDate']) ? $p['EndDate'] : ''),
            ':Blood_group'            => as_null(isset($p['Blood_group']) ? $p['Blood_group'] : ''),
            ':Salary_increment_month' => as_null(isset($p['Salary_increment_month']) ? $p['Salary_increment_month'] : ''),
            ':DOB'                    => as_date_or_null(isset($p['DOB']) ? $p['DOB'] : ''),
            ':Job_Type'               => as_null(isset($p['Job_Type']) ? $p['Job_Type'] : ''),
            ':Salary_level'           => as_null(isset($p['Salary_level']) ? $p['Salary_level'] : ''),
            ':Salary_Steps'           => ($p['Salary_Steps']!=='' ? (int)$p['Salary_Steps'] : null),
            ':Status'                 => !empty($p['Status']) ? $p['Status'] : 'Active',
            ':CreatedBy'              => (isset($_SESSION['auth_user']['UserID']) ? (int)$_SESSION['auth_user']['UserID'] : null)
        );

        if ($usesIdentity) {
            $sql = "
                INSERT INTO dbo.Employees
                (EmployeeCode, FirstName, LastName, NationalID,
                 Email_Office, Email_Personal, Phone1, Phone2,
                 JobTitleID, DepartmentID, LocationID,
                 SupervisorID_admin, SupervisorID_technical,
                 HireDate, EndDate, Blood_group, Salary_increment_month,
                 DOB, Job_Type, Salary_level, Salary_Steps, Status, CreatedAt, CreatedBy)
                VALUES
                (LEFT(:EmployeeCode, 50),
                 LEFT(:FirstName, 100),
                 LEFT(:LastName, 100),
                 LEFT(:NationalID, 100),
                 LEFT(:Email_Office, 200),
                 LEFT(:Email_Personal, 50),
                 LEFT(:Phone1, 15),
                 LEFT(:Phone2, 15),
                 :JobTitleID, :DepartmentID, :LocationID,
                 :SupervisorID_admin, :SupervisorID_technical,
                 :HireDate, :EndDate,
                 LEFT(:Blood_group, 3),
                 LEFT(:Salary_increment_month, 10),
                 :DOB,
                 LEFT(:Job_Type, 50),
                 LEFT(:Salary_level, 10),
                 :Salary_Steps,
                 LEFT(:Status, 50),
                 GETDATE(), :CreatedBy)
            ";
        } else {
            $nextId = (int)$conn->query("
                SELECT ISNULL(MAX(EmployeeID),0)+1
                FROM dbo.Employees WITH (TABLOCKX, HOLDLOCK)
            ")->fetchColumn();
            $params[':EmployeeID'] = $nextId;

            $sql = "
                INSERT INTO dbo.Employees
                (EmployeeID, EmployeeCode, FirstName, LastName, NationalID,
                 Email_Office, Email_Personal, Phone1, Phone2,
                 JobTitleID, DepartmentID, LocationID,
                 SupervisorID_admin, SupervisorID_technical,
                 HireDate, EndDate, Blood_group, Salary_increment_month,
                 DOB, Job_Type, Salary_level, Salary_Steps, Status, CreatedAt, CreatedBy)
                VALUES
                (:EmployeeID,
                 LEFT(:EmployeeCode, 50),
                 LEFT(:FirstName, 100),
                 LEFT(:LastName, 100),
                 LEFT(:NationalID, 100),
                 LEFT(:Email_Office, 200),
                 LEFT(:Email_Personal, 50),
                 LEFT(:Phone1, 15),
                 LEFT(:Phone2, 15),
                 :JobTitleID, :DepartmentID, :LocationID,
                 :SupervisorID_admin, :SupervisorID_technical,
                 :HireDate, :EndDate,
                 LEFT(:Blood_group, 3),
                 LEFT(:Salary_increment_month, 10),
                 :DOB,
                 LEFT(:Job_Type, 50),
                 LEFT(:Salary_level, 10),
                 :Salary_Steps,
                 LEFT(:Status, 50),
                 GETDATE(), :CreatedBy)
            ";
        }

        $stmt = $conn->prepare($sql);
        $stmt->execute($params);
        // Run leave balance generation procedure
$stmt = $conn->prepare("
    EXEC [dbPRFAssetMgt].[dbo].[usp_GenLeaveBalance]
");
$stmt->execute();

        // Get the EmployeeID (for identity columns)
        if ($usesIdentity) {
            $employeeId = (int)$conn->lastInsertId();
        } else {
            $employeeId = $params[':EmployeeID'];
        }
        
        // Also save to Users table
        $userData = array(
            'EmployeeID' => $employeeId,
            'EmployeeCode' => trim((string)$p['EmployeeCode']),
            'Email_Office' => as_null(isset($p['Email_Office']) ? $p['Email_Office'] : ''),
            'SupervisorID_admin' => ($p['SupervisorID_admin']!==''?(int)$p['SupervisorID_admin']:null),
            'SupervisorID_2ndLevel' => ($p['SupervisorID_technical']!==''?(int)$p['SupervisorID_technical']:null) // Using technical supervisor as 2nd level
        );
        
        // Call sync function
        sync_user_account($conn, $userData, false);

        if ($conn->inTransaction()) $conn->commit();
        
        return $employeeId;
    } catch (PDOException $e) {
        if ($conn->inTransaction()) $conn->rollBack();
        throw $e;
    }
}

function update_employee(PDO $conn, $id, array $p) {
    global $msg, $msg_type;
    
    $id = (int)$id;
    $dummyNotes = array();
    $p = sanitize_payload($p, $dummyNotes);

    $sql = "
        UPDATE dbo.Employees
           SET FirstName              = LEFT(:FirstName, 100),
               LastName               = LEFT(:LastName, 100),
               NationalID             = LEFT(:NationalID, 100),
               Email_Office           = LEFT(:Email_Office, 200),
               Email_Personal         = LEFT(:Email_Personal, 50),
               Phone1                 = LEFT(:Phone1, 15),
               Phone2                 = LEFT(:Phone2, 15),
               JobTitleID             = :JobTitleID,
               DepartmentID           = :DepartmentID,
               LocationID             = :LocationID,
               SupervisorID_admin     = :SupervisorID_admin,
               SupervisorID_technical = :SupervisorID_technical,
               HireDate               = :HireDate,
               EndDate                = :EndDate,
               Blood_group            = LEFT(:Blood_group, 3),
               Salary_increment_month = LEFT(:Salary_increment_month, 10),
               DOB                    = :DOB,
               Job_Type               = LEFT(:Job_Type, 50),
               Salary_level           = LEFT(:Salary_level, 10),
               Salary_Steps           = :Salary_Steps,
               Status                 = LEFT(:Status, 50)
         WHERE EmployeeID = :EmployeeID
    ";

    $stmt = $conn->prepare($sql);
    $stmt->execute(array(
        ':FirstName'              => trim((string)$p['FirstName']),
        ':LastName'               => as_null(isset($p['LastName'])?$p['LastName']:''), 
        ':NationalID'             => as_null(isset($p['NationalID'])?$p['NationalID']:''), 
        ':Email_Office'           => as_null(isset($p['Email_Office'])?$p['Email_Office']:''), 
        ':Email_Personal'         => as_null(isset($p['Email_Personal'])?$p['Email_Personal']:''), 
        ':Phone1'                 => as_null(isset($p['Phone1'])?$p['Phone1']:''), 
        ':Phone2'                 => as_null(isset($p['Phone2'])?$p['Phone2']:''), 
        ':JobTitleID'             => (int)$p['JobTitleID'],
        ':DepartmentID'           => (int)$p['DepartmentID'],
        ':LocationID'             => (int)$p['LocationID'],
        ':SupervisorID_admin'     => ($p['SupervisorID_admin']!==''?(int)$p['SupervisorID_admin']:null),
        ':SupervisorID_technical' => ($p['SupervisorID_technical']!==''?(int)$p['SupervisorID_technical']:null),
        ':HireDate'               => as_date_or_null(isset($p['HireDate'])?$p['HireDate']:''), 
        ':EndDate'                => as_date_or_null(isset($p['EndDate'])?$p['EndDate']:''), 
        ':Blood_group'            => as_null(isset($p['Blood_group'])?$p['Blood_group']:''), 
        ':Salary_increment_month' => as_null(isset($p['Salary_increment_month'])?$p['Salary_increment_month']:''), 
        ':DOB'                    => as_date_or_null(isset($p['DOB'])?$p['DOB']:''), 
        ':Job_Type'               => as_null(isset($p['Job_Type'])?$p['Job_Type']:''), 
        ':Salary_level'           => as_null(isset($p['Salary_level'])?$p['Salary_level']:''), 
        ':Salary_Steps'           => ($p['Salary_Steps']!=='' ? (int)$p['Salary_Steps'] : null),
        ':Status'                 => !empty($p['Status'])?$p['Status']:'Active',
        ':EmployeeID'             => $id
    ));
    
    // Also update Users table
    $userData = array(
        'EmployeeID' => $id,
        'EmployeeCode' => trim((string)$p['EmployeeCode']),
        'Email_Office' => as_null(isset($p['Email_Office'])?$p['Email_Office']:''),
        'SupervisorID_admin' => ($p['SupervisorID_admin']!==''?(int)$p['SupervisorID_admin']:null),
        'SupervisorID_2ndLevel' => ($p['SupervisorID_technical']!==''?(int)$p['SupervisorID_technical']:null) // Using technical supervisor as 2nd level
    );
    
    sync_user_account($conn, $userData, true);
}

/* ---------- Load one ---------- */
function load_employee(PDO $conn, $id){
    $id = (int)$id;
    $st=$conn->prepare("
        SELECT e.*, jt.JobTitleName, d.DepartmentName, l.LocationName,
               sa.FirstName + ' ' + ISNULL(sa.LastName,'') AS AdminSupervisorName
        FROM dbo.Employees e
        LEFT JOIN dbo.Designation jt ON jt.JobTitleID=e.JobTitleID
        LEFT JOIN dbo.Departments d  ON d.DepartmentID=e.DepartmentID
        LEFT JOIN dbo.Locations l    ON l.LocationID=e.LocationID
        LEFT JOIN dbo.Employees sa   ON sa.EmployeeID = e.SupervisorID_admin
        WHERE e.EmployeeID=:id
    ");
    $st->execute(array(':id'=>$id));
    return $st->fetch(PDO::FETCH_ASSOC);
}

/* ---------- Controller ---------- */
$editRow=null;
$edit_id = isset($_GET['edit']) ? (int)$_GET['edit'] : 0;

/* Filters */
$filter_q      = isset($_GET['q'])      ? trim($_GET['q'])      : '';
$filter_blood  = isset($_GET['blood'])  ? trim($_GET['blood'])  : '';
$filter_inc    = isset($_GET['inc'])    ? trim($_GET['inc'])    : '';
$filter_job    = isset($_GET['job'])    ? trim($_GET['job'])    : '';
$filter_level  = isset($_GET['level'])  ? trim($_GET['level'])  : '';
$filter_step   = isset($_GET['step'])   ? trim($_GET['step'])   : '';

/* Pagination */
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$per_page = isset($_GET['pp']) ? max(10, min(100, (int)$_GET['pp'])) : 20; // 10-100
$start = ($page - 1) * $per_page + 1;
$end   = $page * $per_page;

/* Edit row */
if ($edit_id>0){
    try{ $editRow=load_employee($conn,$edit_id); }
    catch(PDOException $e){ $msg="Load edit row failed: ".h(errfmt($e)); $msg_type='danger'; $edit_id=0; }
}

$opts = load_fk_options($conn, $editRow);

if (isset($_GET['ok']) && $_GET['ok']==='1'){
    $msg='Employee created.'; $msg_type='success';
}

/* ---- CREATE ---- */
if ($_SERVER['REQUEST_METHOD']==='POST' && (isset($_POST['act']) ? $_POST['act'] : '')==='create'){
    check_csrf();
    $payload = array(
        'EmployeeCode'=>trim(isset($_POST['EmployeeCode'])?$_POST['EmployeeCode']:''), 
        'FirstName'=>trim(isset($_POST['FirstName'])?$_POST['FirstName']:''), 
        'LastName'=>isset($_POST['LastName'])?$_POST['LastName']:'', 
        'NationalID'=>isset($_POST['NationalID'])?$_POST['NationalID']:'', 
        'Email_Office'=>isset($_POST['Email_Office'])?$_POST['Email_Office']:'', 
        'Email_Personal'=>isset($_POST['Email_Personal'])?$_POST['Email_Personal']:'', 
        'Phone1'=>isset($_POST['Phone1'])?$_POST['Phone1']:'', 
        'Phone2'=>isset($_POST['Phone2'])?$_POST['Phone2']:'', 
        'JobTitleID'=>(int)(isset($_POST['JobTitleID'])?$_POST['JobTitleID']:0), 
        'DepartmentID'=>(int)(isset($_POST['DepartmentID'])?$_POST['DepartmentID']:0), 
        'LocationID'=>(int)(isset($_POST['LocationID'])?$_POST['LocationID']:0), 
        'SupervisorID_admin'=>isset($_POST['SupervisorID_admin'])?$_POST['SupervisorID_admin']:'', 
        'SupervisorID_technical'=>isset($_POST['SupervisorID_technical'])?$_POST['SupervisorID_technical']:'', 
        'HireDate'=>isset($_POST['HireDate'])?$_POST['HireDate']:'', 
        'EndDate'=>isset($_POST['EndDate'])?$_POST['EndDate']:'', 
        'Blood_group'=>isset($_POST['Blood_group'])?$_POST['Blood_group']:'', 
        'Salary_increment_month'=>isset($_POST['Salary_increment_month'])?$_POST['Salary_increment_month']:'', 
        'DOB'=>isset($_POST['DOB'])?$_POST['DOB']:'', 
        'Job_Type'=>isset($_POST['Job_Type'])?$_POST['Job_Type']:'', 
        'Salary_level'=>isset($_POST['Salary_level'])?$_POST['Salary_level']:'', 
        'Salary_Steps'=>isset($_POST['Salary_Steps'])?$_POST['Salary_Steps']:'', 
        'Status'=>isset($_POST['Status'])?$_POST['Status']:'Active'
    );

    // Validate mandatory fields
    $validationErrors = validate_mandatory_fields($payload);
    
    // Validate PRF ID
    list($prfValid, $prfError) = validate_prf_id($payload['EmployeeCode']);
    if (!$prfValid) {
        $validationErrors[] = $prfError;
    } else {
        // Check if PRF ID already exists
        if (check_prf_id_exists($conn, $payload['EmployeeCode'])) {
            $validationErrors[] = "PRF ID already exists. Please use a different PRF ID.";
        }
    }
    
    if (!empty($validationErrors)){
        $msg="Validation failed:<br>• " . implode("<br>• ", $validationErrors);
        $msg_type='danger';
    } else {
        $payload = sanitize_payload($payload, $notes);
        try{
            $employeeId = create_employee($conn,$payload);
            // Don't redirect - show message on same page
            $msg = "Employee created successfully.";
            if (strpos($msg, 'User account') !== false) {
                // Message already contains user account info
            } else {
                $msg .= " User account created with password: 1234567";
            }
            $msg_type='success';
        }catch(PDOException $e){
            $msg="Create failed: ".h(errfmt($e));
            $msg_type='danger';
            if (!empty($notes)) $msg .= '<br><small>'.h(implode(' ', $notes)).'</small>';
        }
    }
}

/* ---- UPDATE ---- */
if ($_SERVER['REQUEST_METHOD']==='POST' && (isset($_POST['act']) ? $_POST['act'] : '')==='update'){
    check_csrf();
    $id=(int)(isset($_POST['EmployeeID'])?$_POST['EmployeeID']:0);
    if ($id<=0){
        $msg="Invalid EmployeeID."; $msg_type='danger';
    } else {
        $payload = array(
            'EmployeeCode'=>trim(isset($_POST['EmployeeCode'])?$_POST['EmployeeCode']:''), 
            'FirstName'=>trim(isset($_POST['FirstName'])?$_POST['FirstName']:''), 
            'LastName'=>isset($_POST['LastName'])?$_POST['LastName']:'', 
            'NationalID'=>isset($_POST['NationalID'])?$_POST['NationalID']:'', 
            'Email_Office'=>isset($_POST['Email_Office'])?$_POST['Email_Office']:'', 
            'Email_Personal'=>isset($_POST['Email_Personal'])?$_POST['Email_Personal']:'', 
            'Phone1'=>isset($_POST['Phone1'])?$_POST['Phone1']:'', 
            'Phone2'=>isset($_POST['Phone2'])?$_POST['Phone2']:'', 
            'JobTitleID'=>(int)(isset($_POST['JobTitleID'])?$_POST['JobTitleID']:0), 
            'DepartmentID'=>(int)(isset($_POST['DepartmentID'])?$_POST['DepartmentID']:0), 
            'LocationID'=>(int)(isset($_POST['LocationID'])?$_POST['LocationID']:0), 
            'SupervisorID_admin'=>isset($_POST['SupervisorID_admin'])?$_POST['SupervisorID_admin']:'', 
            'SupervisorID_technical'=>isset($_POST['SupervisorID_technical'])?$_POST['SupervisorID_technical']:'', 
            'HireDate'=>isset($_POST['HireDate'])?$_POST['HireDate']:'', 
            'EndDate'=>isset($_POST['EndDate'])?$_POST['EndDate']:'', 
            'Blood_group'=>isset($_POST['Blood_group'])?$_POST['Blood_group']:'', 
            'Salary_increment_month'=>isset($_POST['Salary_increment_month'])?$_POST['Salary_increment_month']:'', 
            'DOB'=>isset($_POST['DOB'])?$_POST['DOB']:'', 
            'Job_Type'=>isset($_POST['Job_Type'])?$_POST['Job_Type']:'', 
            'Salary_level'=>isset($_POST['Salary_level'])?$_POST['Salary_level']:'', 
            'Salary_Steps'=>isset($_POST['Salary_Steps'])?$_POST['Salary_Steps']:'', 
            'Status'=>isset($_POST['Status'])?$_POST['Status']:'Active'
        );

        // Validate mandatory fields (PRF ID is not validated for update since it's read-only)
        $validationErrors = validate_mandatory_fields($payload);
        
        if (!empty($validationErrors)){
            $msg="Validation failed:<br>• " . implode("<br>• ", $validationErrors);
            $msg_type='danger';
        } else {
            $payload = sanitize_payload($payload, $notes);
            try{
                update_employee($conn,$id,$payload);
                // Don't redirect - show message on same page
                $msg = "Employee updated successfully.";
                if (strpos($msg, 'User account') !== false) {
                    // Message already contains user account info
                } else {
                    $msg .= " User account also updated.";
                }
                $msg_type='success';
            }catch(PDOException $e){
                $msg="Update failed: ".h(errfmt($e));
                $msg_type='danger';
                if (!empty($notes)) $msg .= '<br><small>'.h(implode(' ', $notes)).'</small>';
            }
        }
    }
}

/* ---- TOGGLE / DELETE ---- */
if ($_SERVER['REQUEST_METHOD']==='POST' && (isset($_POST['act']) ? $_POST['act'] : '')==='toggle'){
    check_csrf();
    $id=(int)(isset($_POST['EmployeeID'])?$_POST['EmployeeID']:0);
    $to=isset($_POST['to'])?$_POST['to']:'Active';
    if($id>0){
        try{
            $conn->prepare("UPDATE dbo.Employees SET Status=:s WHERE EmployeeID=:id")
                 ->execute(array(':s'=>$to,':id'=>$id));
            $msg=($to==='Active'?'Activated.':'Deactivated.');
            $msg_type='success';
        }catch(PDOException $e){
            $msg="Toggle failed: ".h(errfmt($e)); $msg_type='danger';
        }
    }
}
if ($_SERVER['REQUEST_METHOD']==='POST' && (isset($_POST['act']) ? $_POST['act'] : '')==='delete'){
    check_csrf();
    $id=(int)(isset($_POST['EmployeeID'])?$_POST['EmployeeID']:0);
    if($id>0){
        try{
            // First delete from Users table (try multiple approaches)
            try {
                $conn->prepare("DELETE FROM [dbPRFAssetMgt].[dbo].[Users] WHERE EmployeeID=:id")
                     ->execute(array(':id'=>$id));
            } catch (Exception $e) {
                // Try without database prefix
                $conn->prepare("DELETE FROM Users WHERE EmployeeID=:id")
                     ->execute(array(':id'=>$id));
            }
            // Then delete from Employees table
            $conn->prepare("DELETE FROM dbo.Employees WHERE EmployeeID=:id")
                 ->execute(array(':id'=>$id));
            $msg="Employee deleted."; $msg_type='success';
        }catch(PDOException $e){
            $msg="Delete failed: ".h(errfmt($e)); $msg_type='danger';
        }
    }
}

/* ---- Build WHERE / Params once (reuse for count + page) ---- */
$where  = array();
$params = array();

if ($filter_q !== '') {
    $where[] = "(e.EmployeeCode LIKE :q
             OR e.FirstName LIKE :q
             OR e.LastName  LIKE :q
             OR e.Email_Office LIKE :q
             OR e.Email_Personal LIKE :q)";
    $params[':q'] = '%'.$filter_q.'%';
}
if ($filter_blood !== '') { $where[] = "e.Blood_group = :bg"; $params[':bg'] = $filter_blood; }
if ($filter_inc   !== '') { $where[] = "e.Salary_increment_month = :inc"; $params[':inc'] = $filter_inc; }
if ($filter_job   !== '') { $where[] = "e.Job_Type = :job"; $params[':job'] = $filter_job; }
if ($filter_level !== '') { $where[] = "e.Salary_level = :lvl"; $params[':lvl'] = $filter_level; }
if ($filter_step  !== '') { $where[] = "e.Salary_Steps = :stp"; $params[':stp'] = (int)$filter_step; }

$whereSql = '';
if (!empty($where)) $whereSql = " WHERE ".implode(" AND ", $where);

/* ---- Count total rows ---- */
try {
    $countSql = "
      SELECT COUNT(*) FROM (
        SELECT e.EmployeeID
        FROM dbo.Employees e
        LEFT JOIN dbo.Designation jt ON jt.JobTitleID = e.JobTitleID
        LEFT JOIN dbo.Departments d  ON d.DepartmentID = e.DepartmentID
        LEFT JOIN dbo.Locations l    ON l.LocationID = e.LocationID
        LEFT JOIN dbo.Employees sa   ON sa.EmployeeID = e.SupervisorID_admin
        $whereSql
      ) X
    ";
    if (!empty($params)) { $stc=$conn->prepare($countSql); $stc->execute($params); $total_rows=(int)$stc->fetchColumn(); }
    else { $total_rows=(int)$conn->query($countSql)->fetchColumn(); }
} catch(PDOException $e){
    $total_rows = 0;
    $msg = "Count failed: ".h(errfmt($e)); $msg_type='danger';
}

/* ---- Paged list (ROW_NUMBER based; works everywhere) ---- */
try{
    $pagedSql = "
      WITH Q AS (
        SELECT
          ROW_NUMBER() OVER (ORDER BY e.CreatedAt DESC, e.EmployeeID DESC) AS rn,
          e.EmployeeID, e.EmployeeCode, e.FirstName, e.LastName, e.Status,
          jt.JobTitleName, d.DepartmentName, l.LocationName,
          e.Email_Office, e.Email_Personal, e.HireDate, e.Salary_level, e.Salary_Steps,
          e.Blood_group, e.Salary_increment_month, e.Job_Type,
          sa.FirstName + ' ' + ISNULL(sa.LastName,'') AS AdminSupervisorName,
          e.CreatedAt
        FROM dbo.Employees e
        LEFT JOIN dbo.Designation jt ON jt.JobTitleID = e.JobTitleID
        LEFT JOIN dbo.Departments d  ON d.DepartmentID = e.DepartmentID
        LEFT JOIN dbo.Locations l    ON l.LocationID = e.LocationID
        LEFT JOIN dbo.Employees sa   ON sa.EmployeeID = e.SupervisorID_admin
        $whereSql
      )
      SELECT *
      FROM Q
      WHERE rn BETWEEN :start AND :end
      ORDER BY rn
    ";

    $rows = array();
    if (!empty($params)) {
        $params[':start'] = $start;
        $params[':end']   = $end;
        $st = $conn->prepare($pagedSql); 
        $st->execute($params);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    } else {
        $st = $conn->prepare($pagedSql);
        $st->execute(array(':start'=>$start, ':end'=>$end));
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    }
}catch(PDOException $e){
    $rows=array(); $msg="Load list failed: ".h(errfmt($e)); $msg_type='danger';
}

/* ---------- View ---------- */
require_once __DIR__ . '/../../include/header.php';
?>
<style>
  .page-wrap { max-width:100%; margin:28px auto; padding:0 16px 32px; }
  .page-title { font-weight:700; letter-spacing:.2px; color:#0f172a; display:flex; align-items:center; gap:8px; }
  .page-title i{ font-size:22px; color:#4f46e5; }
  .page-subtitle{ font-size:13px; color:#6b7280; }
  .card-elevated { border-radius:16px; border:1px solid #e5e7eb; box-shadow:0 18px 45px rgba(15,23,42,.12); overflow:hidden; }
  .card-elevated .card-body{ background:radial-gradient(circle at top left,#eff6ff 0,#ffffff 45%,#f9fafb 100%); }
  .badge-soft { border-radius:999px; padding:5px 12px; font-size:12px; font-weight:500; color:#0f172a; background:#e0f2fe; border:1px solid #bae6fd; display:inline-flex; align-items:center; gap:6px; }
  .badge-soft i{ font-size:.85rem; color:#0284c7; }
  .btn-brand { background:linear-gradient(135deg,#6366f1,#2563eb); color:#fff!important; border:none; padding:.55rem 1.4rem; font-weight:600; border-radius:999px; display:inline-flex; align-items:center; gap:8px; box-shadow:0 12px 25px rgba(37,99,235,.35); transition:all .15s; }
  .btn-brand i{ font-size:.95rem; } .btn-brand:hover{ background:linear-gradient(135deg,#4f46e5,#1d4ed8); transform:translateY(-1px); box-shadow:0 16px 32px rgba(30,64,175,.45); }
  .btn-muted{ background:#e5e7eb; color:#111827!important; border:none; border-radius:999px; padding:.45rem 1.1rem; font-weight:500; display:inline-flex; align-items:center; gap:6px; }
  .btn-danger-soft{ background:#fee2e2; color:#b91c1c!important; border:1px solid #fecaca; border-radius:999px; padding:.45rem 1.1rem; font-weight:500; display:inline-flex; align-items:center; gap:6px; }
  .form-label{ font-weight:600; color:#374151; font-size:13px; }
  .form-control,.form-select{ border-radius:10px; border-color:#cbd5e1; font-size:14px; }
  .section-title{ font-weight:600; color:#111827; display:flex; align-items:center; gap:8px; }
  .section-title i{ color:#4f46e5; font-size:1rem; }
  .table thead th{ background:#f9fafb; color:#4b5563; border-bottom:1px solid #e5e7eb; font-size:12px; text-transform:uppercase; letter-spacing:.04em; white-space:nowrap; }
  .table tbody td{ vertical-align:middle; font-size:13px; color:#111827; }
  .table-hover tbody tr:hover{ background-color:#eff6ff; }
  .status-pill{ display:inline-flex; align-items:center; gap:6px; padding:3px 10px; border-radius:999px; font-size:11px; font-weight:500; }
  .status-pill .status-dot{ width:8px; height:8px; border-radius:999px; background:#22c55e; }
  .status-pill-active{ background:#ecfdf3; color:#166534; }
  .status-pill-inactive{ background:#fef2f2; color:#b91c1c; } .status-pill-inactive .status-dot{ background:#ef4444; }
  .status-pill-other{ background:#eff6ff; color:#1d4ed8; } .status-pill-other .status-dot{ background:#3b82f6; }
  .action-stack > *{ margin:4px; }
  @media (min-width:768px){ .action-stack{ display:inline-flex; justify-content:flex-end; gap:6px; } }
  .filters-helper{ font-size:12px; color:#6b7280; }
  .pagination { display:flex; gap:6px; flex-wrap:wrap; }
  .page-link, .page-info { padding:.35rem .7rem; border:1px solid #e5e7eb; border-radius:8px; background:#fff; font-size:13px; }
  .page-link.active{ background:#2563eb; color:#fff; border-color:#2563eb; }
  
  /* New styles for mandatory fields */
  .form-label.required:after {
    content: " *";
    color: #dc2626;
    font-weight: bold;
  }
  .readonly-field {
    background-color: #f9fafb;
    border-color: #d1d5db;
    cursor: not-allowed;
  }
  .validation-note {
    font-size: 12px;
    color: #6b7280;
    margin-top: 4px;
    font-style: italic;
  }
</style>

<div class="page-wrap">
  <div class="d-flex flex-wrap align-items-center justify-content-between mb-3 gap-2">
    <div>
      <h1 class="page-title mb-1"><i class="fas fa-address-card"></i> Manage Employees</h1>
      <div class="page-subtitle">Also creates/updates user accounts with default password: <code>1234567</code></div>
      <div class="validation-note mt-1"><i class="fas fa-info-circle me-1"></i>Fields marked with <span class="text-danger">*</span> are mandatory</div>
    </div>
    <span class="badge-soft"><i class="fas fa-users"></i> Total Employees: <?php echo (int)$total_rows; ?></span>
  </div>

  <?php if ($msg): ?>
    <div class="alert alert-<?php echo ($msg_type==='danger'?'danger':'success'); ?> alert-dismissible fade show shadow-sm" role="alert">
      <?php echo $msg; ?>
      <?php if ($notes): ?><div class="mt-1 small text-muted"><?php echo h(implode(' ', $notes)); ?></div><?php endif; ?>
      <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
  <?php endif; ?>

  <!-- ======================= LIST (TOP) ======================= -->
  <div class="card card-elevated mb-4">
    <div class="card-body">
      <div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-2">
        <div class="section-title mb-0"><i class="fas fa-list-ul"></i><span>Current list of employees</span></div>
        <span class="filters-helper"><i class="fas fa-filter me-1"></i>Filters ব্যবহার করে দ্রুত narrow down করুন</span>
      </div>

      <!-- Filters -->
      <form method="get" class="row g-2 mb-3" accept-charset="UTF-8">
        <div class="col-12 col-md-3">
          <label class="form-label small mb-1">Search (PRF ID / Name / Email)</label>
          <input type="text" name="q" class="form-control" value="<?php echo h($filter_q); ?>" placeholder="Type to search...">
        </div>
        <div class="col-6 col-md-2">
          <label class="form-label small mb-1">Blood Group</label>
          <select name="blood" class="form-select">
            <option value="">All</option>
            <?php foreach ($BLOOD_GROUPS as $bg): ?>
              <option value="<?php echo h($bg); ?>" <?php echo ($filter_blood===$bg?'selected':''); ?>><?php echo h($bg); ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-6 col-md-2">
          <label class="form-label small mb-1">Increment Month</label>
          <select name="inc" class="form-select">
            <option value="">All</option>
            <?php foreach ($INCREMENT_MONTHS as $code=>$label): ?>
              <option value="<?php echo h($code); ?>" <?php echo ($filter_inc===$code?'selected':''); ?>><?php echo h($label); ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-6 col-md-2">
          <label class="form-label small mb-1">Job Type</label>
          <select name="job" class="form-select">
            <option value="">All</option>
            <?php foreach ($JOB_TYPES as $jt): ?>
              <option value="<?php echo h($jt); ?>" <?php echo ($filter_job===$jt?'selected':''); ?>><?php echo h($jt); ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-6 col-md-2">
          <label class="form-label small mb-1">Salary Level</label>
          <select name="level" class="form-select">
            <option value="">All</option>
            <?php foreach ($SALARY_LEVELS as $lvl): ?>
              <option value="<?php echo h($lvl); ?>" <?php echo ($filter_level===$lvl?'selected':''); ?>><?php echo h($lvl); ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-6 col-md-1">
          <label class="form-label small mb-1">Step</label>
          <select name="step" class="form-select">
            <option value="">All</option>
            <?php for ($i=1; $i<=15; $i++): ?>
              <option value="<?php echo $i; ?>" <?php echo ((string)$filter_step===(string)$i?'selected':''); ?>>Step-<?php echo $i; ?></option>
            <?php endfor; ?>
          </select>
        </div>

        <div class="col-12 d-flex gap-2 mt-1 align-items-end">
          <input type="hidden" name="page" value="1">
          <button class="btn btn-brand"><i class="fas fa-search"></i> Apply Filters</button>
          <a class="btn btn-muted" href="<?php echo h($self); ?>"><i class="fas fa-undo"></i> Reset</a>
        </div>
      </form>

      <!-- Table -->
      <div class="table-responsive">
        <table class="table table-hover align-middle">
          <thead>
            <tr>
              <th>#</th><th>PRF ID</th><th>Name</th><th>Designation</th><th>Department</th><th>Location</th>
              <th>Email (PRF)</th><th>Email (Personal)</th><th>Joining Date</th><th>Immediate Supervisor</th>
              <th>Salary Level</th><th>Salary Step</th><th>Status</th><th class="text-end">Actions</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($rows as $r): ?>
              <?php
                $status = (string)$r['Status'];
                $pillClass = ($status==='Active' ? 'status-pill-active' : ($status==='Inactive'?'status-pill-inactive':'status-pill-other'));
              ?>
              <tr>
                <td><?php echo (int)$r['EmployeeID']; ?></td>
                <td><?php echo h($r['EmployeeCode']); ?></td>
                <td><?php echo h(trim($r['FirstName'].' '.(isset($r['LastName']) ? $r['LastName'] : ''))); ?></td>
                <td><?php echo h($r['JobTitleName']); ?></td>
                <td><?php echo h($r['DepartmentName']); ?></td>
                <td><?php echo h($r['LocationName']); ?></td>
                <td><?php echo h($r['Email_Office']); ?></td>
                <td><?php echo h($r['Email_Personal']); ?></td>
                <td><?php echo h(substr((string)$r['HireDate'],0,10)); ?></td>
                <td><?php echo h($r['AdminSupervisorName']); ?></td>
                <td><?php echo h($r['Salary_level']); ?></td>
                <td><?php echo ($r['Salary_Steps']!==null && $r['Salary_Steps']!=='' ? 'Step-'.h($r['Salary_Steps']) : ''); ?></td>
                <td><span class="status-pill <?php echo $pillClass; ?>"><span class="status-dot"></span><?php echo h($status); ?></span></td>
                <td class="text-end">
                  <div class="action-stack">
                    <a class="btn btn-muted btn-sm w-100 w-md-auto" href="<?php echo h($self); ?>?edit=<?php echo (int)$r['EmployeeID']; ?>"><i class="fas fa-pencil-alt"></i> Edit</a>
                    <form method="post" class="d-inline" onsubmit="return confirm('Toggle status?');" accept-charset="UTF-8">
                      <input type="hidden" name="csrf" value="<?php echo h($CSRF); ?>">
                      <input type="hidden" name="act" value="toggle">
                      <input type="hidden" name="EmployeeID" value="<?php echo (int)$r['EmployeeID']; ?>">
                      <input type="hidden" name="to" value="<?php echo ($r['Status']==='Active'?'Inactive':'Active'); ?>">
                      <button class="btn btn-muted btn-sm w-100 w-md-auto" type="submit">
                        <i class="fas <?php echo ($r['Status']==='Active'?'fa-pause-circle':'fa-play-circle'); ?>"></i>
                        <?php echo ($r['Status']==='Active'?'Deactivate':'Activate'); ?>
                      </button>
                    </form>
                    <form method="post" class="d-inline" onsubmit="return confirm('Delete this employee permanently?');" accept-charset="UTF-8">
                      <input type="hidden" name="csrf" value="<?php echo h($CSRF); ?>">
                      <input type="hidden" name="act" value="delete">
                      <input type="hidden" name="EmployeeID" value="<?php echo (int)$r['EmployeeID']; ?>">
                      <button class="btn btn-danger-soft btn-sm w-100 w-md-auto" type="submit"><i class="fas fa-trash-alt"></i> Delete</button>
                    </form>
                  </div>
                </td>
              </tr>
            <?php endforeach; ?>
            <?php if (empty($rows)): ?>
              <tr><td colspan="14" class="text-center text-muted py-4"><i class="fas fa-user-slash me-1"></i> No data</td></tr>
            <?php endif; ?>
          </tbody>
        </table>
      </div>

      <!-- Pagination -->
      <?php
        $total_pages = ($per_page>0) ? (int)ceil($total_rows / $per_page) : 1;
        if ($total_pages < 1) $total_pages = 1;
        $qs = $_GET; unset($qs['page']); $base_qs = http_build_query($qs);
        function page_link($p,$base_qs){ return '?'.$base_qs.($base_qs!==''?'&':'').'page='.$p; }
      ?>
      <div class="d-flex align-items-center justify-content-between mt-2">
        <div class="page-info">
          Page <?php echo $page; ?> of <?php echo $total_pages; ?> &nbsp;|&nbsp; Showing
          <?php echo ($total_rows ? min($per_page, $total_rows - ($page-1)*$per_page) : 0); ?> of <?php echo (int)$total_rows; ?>
        </div>
        <div class="pagination">
          <?php if ($page>1): ?>
            <a class="page-link" href="<?php echo h(page_link(1,$base_qs)); ?>">&laquo; First</a>
            <a class="page-link" href="<?php echo h(page_link($page-1,$base_qs)); ?>">&lsaquo; Prev</a>
          <?php endif; ?>
          <?php
            $start_p = max(1, $page-2);
            $end_p   = min($total_pages, $page+2);
            for ($p=$start_p; $p<=$end_p; $p++):
          ?>
            <a class="page-link <?php echo ($p==$page?'active':''); ?>" href="<?php echo h(page_link($p,$base_qs)); ?>"><?php echo $p; ?></a>
          <?php endfor; ?>
          <?php if ($page<$total_pages): ?>
            <a class="page-link" href="<?php echo h(page_link($page+1,$base_qs)); ?>">Next &rsaquo;</a>
            <a class="page-link" href="<?php echo h(page_link($total_pages,$base_qs)); ?>">Last &raquo;</a>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </div>
  <!-- ======================= /LIST (TOP) ======================= -->

  <!-- ======================= FORM (BOTTOM) ======================= -->
  <div class="card card-elevated">
    <div class="card-body">
      <?php if (!empty($editRow)): ?>
        <div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-3">
          <div class="section-title mb-0"><i class="fas fa-user-edit"></i><span>Edit Employee</span></div>
          <a class="btn btn-muted btn-sm" href="<?php echo h($self); ?>"><i class="fas fa-times-circle"></i> Cancel</a>
        </div>

        <!-- EDIT FORM -->
        <form method="post" accept-charset="UTF-8">
          <input type="hidden" name="csrf" value="<?php echo h($CSRF); ?>">
          <input type="hidden" name="act" value="update">
          <input type="hidden" name="EmployeeID" value="<?php echo (int)$editRow['EmployeeID']; ?>">
          <input type="hidden" name="EmployeeCode" value="<?php echo h($editRow['EmployeeCode']); ?>">

          <div class="row g-3">
            <div class="col-md-3">
              <label class="form-label required">PRF ID (Employee Code)</label>
              <input type="text" class="form-control readonly-field" 
                     value="<?php echo h($editRow['EmployeeCode']); ?>" 
                     readonly
                     title="PRF ID cannot be edited">
              <div class="validation-note">PRF ID cannot be changed after creation</div>
            </div>
            <div class="col-md-3">
              <label class="form-label required">First Name</label>
              <input type="text" name="FirstName" class="form-control" required 
                     value="<?php echo h($editRow['FirstName']); ?>">
            </div>
            <div class="col-md-3">
              <label class="form-label">Last Name</label>
              <input type="text" name="LastName" class="form-control" 
                     value="<?php echo h($editRow['LastName']); ?>">
            </div>
            <div class="col-md-3">
              <label class="form-label required">National ID</label>
              <input type="text" name="NationalID" class="form-control" required 
                     value="<?php echo h($editRow['NationalID']); ?>" 
                     minlength="10" maxlength="100">
              <div class="validation-note">At least 10 characters</div>
            </div>

            <div class="col-md-3">
              <label class="form-label">Email (PRF / Office)</label>
              <input type="email" name="Email_Office" class="form-control" 
                     value="<?php echo h($editRow['Email_Office']); ?>">
            </div>
            <div class="col-md-3">
              <label class="form-label">Email (Personal)</label>
              <input type="email" name="Email_Personal" class="form-control" 
                     value="<?php echo h($editRow['Email_Personal']); ?>">
            </div>
            <div class="col-md-3">
              <label class="form-label required">Phone 1</label>
              <input type="tel" name="Phone1" class="form-control" required 
                     value="<?php echo h($editRow['Phone1']); ?>" 
                     pattern="^[0-9\+][0-9]{9,14}$"
                     title="Phone number (10-15 digits, can start with +)">
            </div>
            <div class="col-md-3">
              <label class="form-label">Phone 2</label>
              <input type="tel" name="Phone2" class="form-control" 
                     value="<?php echo h($editRow['Phone2']); ?>">
            </div>

            <div class="col-md-3">
              <label class="form-label required">Designation</label>
              <select name="JobTitleID" class="form-select" required>
                <option value="">-- Select --</option>
                <?php foreach ($opts['titles'] as $o): ?>
                  <option value="<?php echo (int)$o['id']; ?>" <?php echo ((int)$editRow['JobTitleID']===(int)$o['id']?'selected':''); ?>><?php echo h($o['name']); ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-3">
              <label class="form-label required">Department</label>
              <select name="DepartmentID" class="form-select" required>
                <option value="">-- Select --</option>
                <?php foreach ($opts['depts'] as $o): ?>
                  <option value="<?php echo (int)$o['id']; ?>" <?php echo ((int)$editRow['DepartmentID']===(int)$o['id']?'selected':''); ?>><?php echo h($o['name']); ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-3">
              <label class="form-label required">Location</label>
              <select name="LocationID" class="form-select" required>
                <option value="">-- Select --</option>
                <?php foreach ($opts['locs'] as $o): ?>
                  <option value="<?php echo (int)$o['id']; ?>" <?php echo ((int)$editRow['LocationID']===(int)$o['id']?'selected':''); ?>><?php echo h($o['name']); ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-3">
              <label class="form-label required">Status</label>
              <select name="Status" class="form-select" required>
                <?php foreach (array('Active','Inactive','On Leave','Terminated') as $s): ?>
                  <option <?php echo ($editRow['Status']===$s?'selected':''); ?>><?php echo h($s); ?></option>
                <?php endforeach; ?>
              </select>
            </div>

            <!-- BOTH dropdowns now show ALL employees -->
            <div class="col-md-3">
              <label class="form-label">Immediate Supervisor (Admin)</label>
              <select name="SupervisorID_admin" class="form-select">
                <option value="">-- None --</option>
                <?php foreach ($opts['supers_admin'] as $o): ?>
                  <option value="<?php echo (int)$o['id']; ?>" <?php echo ((int)$editRow['SupervisorID_admin']===(int)$o['id']?'selected':''); ?>>
                    <?php echo h($o['name']).($o['Status']==='Active'?'':' ('.$o['Status'].')'); ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-3">
              <label class="form-label">Supervisor (Technical)</label>
              <select name="SupervisorID_technical" class="form-select">
                <option value="">-- None --</option>
                <?php foreach ($opts['supers_tech'] as $o): ?>
                  <option value="<?php echo (int)$o['id']; ?>" <?php echo ((int)$editRow['SupervisorID_technical']===(int)$o['id']?'selected':''); ?>>
                    <?php echo h($o['name']).($o['Status']==='Active'?'':' ('.$o['Status'].')'); ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>

            <div class="col-md-3">
              <label class="form-label required">Joining Date</label>
              <input type="date" name="HireDate" class="form-control" required 
                     value="<?php echo h(substr((string)$editRow['HireDate'],0,10)); ?>">
            </div>
            <div class="col-md-3">
              <label class="form-label">End Date</label>
              <input type="date" name="EndDate" class="form-control" 
                     value="<?php echo h(substr((string)$editRow['EndDate'],0,10)); ?>">
            </div>
            <div class="col-md-3">
              <label class="form-label required">Date of Birth (DOB)</label>
              <input type="date" name="DOB" class="form-control" required 
                     value="<?php echo h(substr((string)$editRow['DOB'],0,10)); ?>">
              <div class="validation-note">Must be before Joining Date</div>
            </div>
            <div class="col-md-3">
              <label class="form-label">Blood Group</label>
              <select name="Blood_group" class="form-select">
                <option value="">-- Select --</option>
                <?php foreach ($BLOOD_GROUPS as $bg): ?>
                  <option value="<?php echo h($bg); ?>" <?php echo ($editRow['Blood_group']===$bg?'selected':''); ?>><?php echo h($bg); ?></option>
                <?php endforeach; ?>
              </select>
            </div>

            <div class="col-md-3">
              <label class="form-label required">Job Type</label>
              <select name="Job_Type" class="form-select" required>
                <option value="">-- Select --</option>
                <?php foreach ($JOB_TYPES as $jt): ?>
                  <option value="<?php echo h($jt); ?>" <?php echo ($editRow['Job_Type']===$jt?'selected':''); ?>><?php echo h($jt); ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-3">
              <label class="form-label">Increment Month</label>
              <select name="Salary_increment_month" class="form-select">
                <option value="">-- Select --</option>
                <?php foreach ($INCREMENT_MONTHS as $code=>$label): ?>
                  <option value="<?php echo h($code); ?>" <?php echo ($editRow['Salary_increment_month']===$code?'selected':''); ?>><?php echo h($label); ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-3">
              <label class="form-label">Salary Level</label>
              <select name="Salary_level" class="form-select">
                <option value="">-- Select --</option>
                <?php foreach ($SALARY_LEVELS as $lvl): ?>
                  <option value="<?php echo h($lvl); ?>" <?php echo ($editRow['Salary_level']===$lvl?'selected':''); ?>><?php echo h($lvl); ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-3">
              <label class="form-label">Salary Step</label>
              <select name="Salary_Steps" class="form-select">
                <option value="">-- Select --</option>
                <?php $currentStep = isset($editRow['Salary_Steps']) ? (int)$editRow['Salary_Steps'] : 0;
                  for ($i=1; $i<=15; $i++): $sel = ($currentStep === $i) ? ' selected' : ''; ?>
                  <option value="<?php echo $i; ?>"<?php echo $sel; ?>>Step-<?php echo $i; ?></option>
                <?php endfor; ?>
              </select>
            </div>

            <div class="col-12 d-grid d-md-inline">
              <button class="btn btn-brand w-100 w-md-auto" style="display: inline;"><i class="fas fa-save"></i> Update Employee</button>
            </div>
          </div>
        </form>

      <?php else: ?>
        <div class="section-title mb-3"><i class="fas fa-user-plus"></i><span>Add Employee</span></div>
        <!-- CREATE FORM -->
        <form method="post" class="row g-3" accept-charset="UTF-8">
          <input type="hidden" name="csrf" value="<?php echo h($CSRF); ?>">
          <input type="hidden" name="act" value="create">

          <div class="col-md-3">
            <label class="form-label required">PRF ID (Employee Code)</label>
            <input type="text" name="EmployeeCode" class="form-control" required 
                   maxlength="20"
                   pattern="^[0-9]+$"
                   title="Only digits (0-9) allowed"
                   placeholder="e.g., 123456">
            <div class="validation-note">Only digits allowed (3-20 characters)</div>
          </div>
          <div class="col-md-3">
            <label class="form-label required">First Name</label>
            <input type="text" name="FirstName" class="form-control" required maxlength="100">
          </div>
          <div class="col-md-3">
            <label class="form-label">Last Name</label>
            <input type="text" name="LastName" class="form-control" maxlength="100">
          </div>
          <div class="col-md-3">
            <label class="form-label required">National ID</label>
            <input type="text" name="NationalID" class="form-control" required 
                   maxlength="100" minlength="10">
            <div class="validation-note">At least 10 characters</div>
          </div>

          <div class="col-md-3">
            <label class="form-label">Email (PRF / Office)</label>
            <input type="email" name="Email_Office" class="form-control" maxlength="200">
          </div>
          <div class="col-md-3">
            <label class="form-label">Email (Personal)</label>
            <input type="email" name="Email_Personal" class="form-control" maxlength="50">
          </div>
          <div class="col-md-3">
            <label class="form-label required">Phone 1</label>
            <input type="tel" name="Phone1" class="form-control" required 
                   maxlength="15"
                   pattern="^[0-9\+][0-9]{9,14}$"
                   title="Phone number (10-15 digits, can start with +)"
                   placeholder="e.g., +8801712345678">
          </div>
          <div class="col-md-3">
            <label class="form-label">Phone 2</label>
            <input type="tel" name="Phone2" class="form-control" maxlength="15">
          </div>

          <div class="col-md-3">
            <label class="form-label required">Designation</label>
            <select name="JobTitleID" class="form-select" required>
              <option value="">-- Select --</option>
              <?php foreach ($opts['titles'] as $o): ?>
                <option value="<?php echo (int)$o['id']; ?>"><?php echo h($o['name']); ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-md-3">
            <label class="form-label required">Department</label>
            <select name="DepartmentID" class="form-select" required>
              <option value="">-- Select --</option>
              <?php foreach ($opts['depts'] as $o): ?>
                <option value="<?php echo (int)$o['id']; ?>"><?php echo h($o['name']); ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-md-3">
            <label class="form-label required">Location</label>
            <select name="LocationID" class="form-select" required>
              <option value="">-- Select --</option>
              <?php foreach ($opts['locs'] as $o): ?>
                <option value="<?php echo (int)$o['id']; ?>"><?php echo h($o['name']); ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-md-3">
            <label class="form-label required">Status</label>
            <select name="Status" class="form-select" required>
              <?php foreach (array('Active','Inactive','On Leave','Terminated') as $s): ?>
                <option><?php echo h($s); ?></option>
              <?php endforeach; ?>
            </select>
          </div>

          <!-- BOTH dropdowns now show ALL employees -->
          <div class="col-md-3">
            <label class="form-label">Immediate Supervisor (Admin)</label>
            <select name="SupervisorID_admin" class="form-select">
              <option value="">-- None --</option>
              <?php foreach ($opts['supers_admin'] as $o): ?>
                <option value="<?php echo (int)$o['id']; ?>"><?php echo h($o['name']).($o['Status']==='Active'?'':' ('.$o['Status'].')'); ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-md-3">
            <label class="form-label">Supervisor (Technical)</label>
            <select name="SupervisorID_technical" class="form-select">
              <option value="">-- None --</option>
              <?php foreach ($opts['supers_tech'] as $o): ?>
                <option value="<?php echo (int)$o['id']; ?>"><?php echo h($o['name']).($o['Status']==='Active'?'':' ('.$o['Status'].')'); ?></option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="col-md-3">
            <label class="form-label required">Joining Date</label>
            <input type="date" name="HireDate" class="form-control" required>
          </div>
          <div class="col-md-3">
            <label class="form-label">End Date</label>
            <input type="date" name="EndDate" class="form-control">
          </div>
          <div class="col-md-3">
            <label class="form-label required">Date of Birth (DOB)</label>
            <input type="date" name="DOB" class="form-control" required>
            <div class="validation-note">Must be before Joining Date</div>
          </div>
          <div class="col-md-3">
            <label class="form-label">Blood Group</label>
            <select name="Blood_group" class="form-select">
              <option value="">-- Select --</option>
              <?php foreach ($BLOOD_GROUPS as $bg): ?>
                <option value="<?php echo h($bg); ?>"><?php echo h($bg); ?></option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="col-md-3">
            <label class="form-label required">Job Type</label>
            <select name="Job_Type" class="form-select" required>
              <option value="">-- Select --</option>
              <?php foreach ($JOB_TYPES as $jt): ?>
                <option value="<?php echo h($jt); ?>"><?php echo h($jt); ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-md-3">
            <label class="form-label">Increment Month</label>
            <select name="Salary_increment_month" class="form-select">
              <option value="">-- Select --</option>
              <?php foreach ($INCREMENT_MONTHS as $code=>$label): ?>
                <option value="<?php echo h($code); ?>"><?php echo h($label); ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-md-3">
            <label class="form-label">Salary Level</label>
            <select name="Salary_level" class="form-select">
              <option value="">-- Select --</option>
              <?php foreach ($SALARY_LEVELS as $lvl): ?>
                <option value="<?php echo h($lvl); ?>"><?php echo h($lvl); ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-md-3">
            <label class="form-label">Salary Step</label>
            <select name="Salary_Steps" class="form-select">
              <option value="">-- Select --</option>
              <?php for ($i=1; $i<=15; $i++): ?>
                <option value="<?php echo $i; ?>">Step-<?php echo $i; ?></option>
              <?php endfor; ?>
            </select>
          </div>

          <div class="col-12 d-grid d-md-inline">
            <button class="btn btn-brand w-100 w-md-auto" style="display: inline;"><i class="fas fa-save"></i> Create Employee</button>
          </div>
        </form>
        
      <?php endif; ?>
    </div>
  </div>
  <!-- ======================= /FORM (BOTTOM) ======================= -->
</div>

<script>
(function(){
  var LIMITS = { EmployeeCode:20, FirstName:100, LastName:100, NationalID:100, Email_Office:200, Email_Personal:50, Phone1:15, Phone2:15, Blood_group:3, Salary_increment_month:10, Job_Type:50, Salary_level:10 };
  var REQUIRED = ["EmployeeCode","FirstName","NationalID","Phone1","HireDate","DOB","Job_Type","JobTitleID","DepartmentID","LocationID","Status"];
  
  function $(sel, root){ return (root||document).querySelector(sel); }
  function $all(sel, root){ return Array.prototype.slice.call((root||document).querySelectorAll(sel)); }
  
  function ensureFeedbackEl(input){ 
    var next = input.nextElementSibling; 
    if(!(next && next.classList && next.classList.contains('invalid-feedback'))){ 
      var div = document.createElement('div'); 
      div.className = 'invalid-feedback'; 
      input.parentNode.insertBefore(div, input.nextSibling); 
    } 
    return input.nextElementSibling; 
  }
  
  function setInvalid(input, msg){ 
    input.classList.add('is-invalid'); 
    input.classList.remove('is-valid'); 
    ensureFeedbackEl(input).textContent = msg || 'Invalid value'; 
  }
  
  function setValid(input){ 
    input.classList.remove('is-invalid'); 
    input.classList.add('is-valid'); 
    var fb = input.nextElementSibling; 
    if(fb && fb.classList.contains('invalid-feedback')) fb.textContent = ''; 
  }
  
  function isEmail(v){ 
    if(!v) return true; 
    return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(v); 
  }
  
  function validatePRFId(value) {
    if (!value) return "PRF ID is required";
    if (!/^[0-9]+$/.test(value)) return "PRF ID must contain only digits (0-9)";
    if (value.length < 3) return "PRF ID must be at least 3 digits";
    if (value.length > 20) return "PRF ID must not exceed 20 digits";
    return "";
  }
  
  function validatePhone(value) {
    if (!value) return "Phone is required";
    var cleaned = value.replace(/[^0-9\+]/g, '');
    if (!/^[0-9\+][0-9]{9,14}$/.test(cleaned)) {
      return "Phone must be 10-15 digits (can start with +)";
    }
    return "";
  }
  
  function validateNationalId(value) {
    if (!value) return "National ID is required";
    if (value.length < 10) return "National ID must be at least 10 characters";
    return "";
  }
  
  function validateDate(value, fieldName) {
    if (!value) return fieldName + " is required";
    var date = new Date(value);
    if (isNaN(date.getTime())) return fieldName + " must be a valid date";
    return "";
  }
  
  function validateOne(input, form){
    var name = input.getAttribute('name');
    var val = (input.value||'').trim();
    var isSelect = input.tagName === 'SELECT';
    
    // Check if field is required
    if(REQUIRED.indexOf(name) !== -1){
      if((!isSelect && val === '') || (isSelect && (val === '' || val === null || val === '0'))){
        setInvalid(input,'This field is required'); 
        return false; 
      }
    }
    
    // Check field limits
    if(LIMITS[name] && val.length > LIMITS[name]){
      setInvalid(input,'Max '+LIMITS[name]+' characters'); 
      return false; 
    }
    
    // Field-specific validation
    switch(name){
      case 'EmployeeCode':
        var prfError = validatePRFId(val);
        if (prfError) { setInvalid(input, prfError); return false; }
        break;
      case 'Phone1':
        var phoneError = validatePhone(val);
        if (phoneError) { setInvalid(input, phoneError); return false; }
        // Clean up phone number
        var cleaned = val.replace(/[^0-9\+]/g, '');
        if (cleaned !== val) {
          input.value = cleaned;
        }
        break;
      case 'Phone2':
        if (val) {
          var cleaned = val.replace(/[^0-9\+]/g, '');
          if (cleaned !== val) {
            input.value = cleaned;
          }
        }
        break;
      case 'NationalID':
        var nidError = validateNationalId(val);
        if (nidError) { setInvalid(input, nidError); return false; }
        break;
      case 'HireDate':
        var hireError = validateDate(val, 'Joining Date');
        if (hireError) { setInvalid(input, hireError); return false; }
        
        // Check if HireDate is after DOB
        var dobInput = form.querySelector('input[name="DOB"]');
        if (dobInput && dobInput.value) {
          var hireDate = new Date(val);
          var dobDate = new Date(dobInput.value);
          if (hireDate <= dobDate) {
            setInvalid(input, 'Joining Date must be after Date of Birth');
            return false;
          }
        }
        break;
      case 'DOB':
        var dobError = validateDate(val, 'Date of Birth');
        if (dobError) { setInvalid(input, dobError); return false; }
        
        // Check if DOB is before HireDate
        var hireInput = form.querySelector('input[name="HireDate"]');
        if (hireInput && hireInput.value) {
          var dobDate = new Date(val);
          var hireDate = new Date(hireInput.value);
          if (dobDate >= hireDate) {
            setInvalid(input, 'Date of Birth must be before Joining Date');
            return false;
          }
        }
        break;
      case 'Email_Office':
      case 'Email_Personal':
        if(val && !isEmail(val)){
          setInvalid(input,'Enter a valid email'); 
          return false; 
        }
        // Auto-lowercase emails
        if (val) {
          input.value = val.toLowerCase();
        }
        break;
    }
    
    setValid(input); 
    return true;
  }
  
  function validateForm(form){
    var ok = true;
    
    // Auto-lowercase emails
    ['Email_Office','Email_Personal'].forEach(function(n){ 
      var el=$('input[name="'+n+'"]',form); 
      if(el && el.value){ 
        el.value = el.value.trim().toLowerCase(); 
      } 
    });
    
    // Validate all fields
    $all('input, select, textarea', form).forEach(function(inp){ 
      if(inp.getAttribute('name')){ 
        if(!validateOne(inp, form)){ 
          ok=false; 
        } 
      } 
    });
    
    return ok;
  }
  
  function attach(form){
    // Attach validation to all inputs
    $all('input, select, textarea', form).forEach(function(inp){
      if(!inp.getAttribute('name')) return;
      
      inp.addEventListener('input', function(){ validateOne(inp, form); });
      inp.addEventListener('change', function(){ validateOne(inp, form); });
      
      // Special handling for date validation
      if (inp.name === 'HireDate' || inp.name === 'DOB') {
        inp.addEventListener('change', function() {
          // Trigger validation on both date fields when one changes
          var otherField = form.querySelector(inp.name === 'HireDate' ? 'input[name="DOB"]' : 'input[name="HireDate"]');
          if (otherField) {
            validateOne(inp, form);
            validateOne(otherField, form);
          }
        });
      }
    });
    
    // Form submission validation
    form.addEventListener('submit', function(e){ 
      if(!validateForm(form)){ 
        e.preventDefault(); 
        e.stopPropagation(); 
        var firstInvalid=form.querySelector('.is-invalid'); 
        if(firstInvalid) firstInvalid.focus(); 
      } 
    });
  }
  
  // Attach validation to all forms
  $all('form').forEach(function(f){ 
    if(f.querySelector('input[name="act"][value="create"], input[name="act"][value="update"]')){ 
      attach(f); 
    } 
  });
  
  // Real-time PRF ID validation - only digits
  document.addEventListener('input', function(e) {
    if (e.target.name === 'EmployeeCode' && e.target.form && !e.target.form.querySelector('input[name="act"][value="update"]')) {
      // Only for create form
      var value = e.target.value;
      // Remove non-digit characters
      var digitsOnly = value.replace(/[^0-9]/g, '');
      if (digitsOnly !== value) {
        e.target.value = digitsOnly;
      }
    }
  });
  
  // Real-time phone number formatting
  document.addEventListener('input', function(e) {
    if (e.target.name === 'Phone1' || e.target.name === 'Phone2') {
      var value = e.target.value;
      // Remove non-digit and non-plus characters
      var cleaned = value.replace(/[^0-9\+]/g, '');
      if (cleaned !== value) {
        e.target.value = cleaned;
      }
    }
  });
  
})();
</script>

<style>
.invalid-feedback{ display:block; font-size:12px; color:#dc2626; margin-top:4px; }
.is-invalid{ border-color:#dc2626!important; }
.is-valid{ border-color:#16a34a!important; }
</style>

<?php require_once __DIR__ . '/../../include/footer.php'; ?>