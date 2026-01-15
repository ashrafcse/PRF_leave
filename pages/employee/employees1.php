<?php
/***********************
 * Employees - Full CRUD (SQL Server, PDO)  [PHP 5.6 compatible]
 * - Table is shown first
 * - Pagination added
 * - Supervisor dropdowns show ALL employees (Active first)
 * - Added: SupervisorID_2ndLevel, Contract_end_date
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

/* ---------- ALL employees list for supervisor dropdowns ---------- */
function all_employees_for_supervisor(PDO $conn) {
    $sql = "
      SELECT
        e.EmployeeID AS id,
        LTRIM(RTRIM(e.FirstName + ' ' + ISNULL(e.LastName,''))) AS name,
        e.Status
      FROM dbo.Employees e
      ORDER BY CASE WHEN e.Status='Active' THEN 0 ELSE 1 END,
               e.FirstName, e.LastName
    ";
    return $conn->query($sql)->fetchAll(PDO::FETCH_ASSOC);
}

/* ---------- FK options (titles/dep/loc + supervisors) ---------- */
function load_fk_options(PDO $conn, $current=null){
    $opts = array(
        'titles'=>array(),
        'depts'=>array(),
        'locs'=>array(),
        'supers_admin'=>array(),
        'supers_tech'=>array(),
        'supers_2nd'=>array()
    );

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

    // All 3 supervisor dropdowns use ALL employees (Active first)
    $all = all_employees_for_supervisor($conn);
    $opts['supers_admin'] = $all;
    $opts['supers_tech']  = $all;
    $opts['supers_2nd']   = $all;

    // Keep current selected supervisors even if not present (edge)
    if ($current) {
        foreach (array(
            'SupervisorID_admin'     => 'supers_admin',
            'SupervisorID_technical' => 'supers_tech',
            'SupervisorID_2ndLevel'  => 'supers_2nd'
        ) as $field=>$key){
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

/* ---------- Create / Update DB functions ---------- */
function create_employee(PDO $conn, array $p) {
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
            ':SupervisorID_2ndLevel'  => ($p['SupervisorID_2ndLevel']!==''?(int)$p['SupervisorID_2ndLevel']:null),
            ':HireDate'               => as_date_or_null(isset($p['HireDate']) ? $p['HireDate'] : ''),
            ':Contract_end_date'      => as_date_or_null(isset($p['Contract_end_date']) ? $p['Contract_end_date'] : ''),
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
                 SupervisorID_admin, SupervisorID_technical, SupervisorID_2ndLevel,
                 HireDate, Contract_end_date, EndDate, Blood_group, Salary_increment_month,
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
                 :SupervisorID_admin, :SupervisorID_technical, :SupervisorID_2ndLevel,
                 :HireDate, :Contract_end_date, :EndDate,
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
                 SupervisorID_admin, SupervisorID_technical, SupervisorID_2ndLevel,
                 HireDate, Contract_end_date, EndDate, Blood_group, Salary_increment_month,
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
                 :SupervisorID_admin, :SupervisorID_technical, :SupervisorID_2ndLevel,
                 :HireDate, :Contract_end_date, :EndDate,
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

        if ($conn->inTransaction()) $conn->commit();
    } catch (PDOException $e) {
        if ($conn->inTransaction()) $conn->rollBack();
        throw $e;
    }
}

function update_employee(PDO $conn, $id, array $p) {
    $id = (int)$id;
    $dummyNotes = array();
    $p = sanitize_payload($p, $dummyNotes);

    $sql = "
        UPDATE dbo.Employees
           SET EmployeeCode           = LEFT(:EmployeeCode, 50),
               FirstName              = LEFT(:FirstName, 100),
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
               SupervisorID_2ndLevel  = :SupervisorID_2ndLevel,
               HireDate               = :HireDate,
               Contract_end_date      = :Contract_end_date,
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
        ':EmployeeCode'           => trim((string)$p['EmployeeCode']),
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
        ':SupervisorID_2ndLevel'  => ($p['SupervisorID_2ndLevel']!==''?(int)$p['SupervisorID_2ndLevel']:null),
        ':HireDate'               => as_date_or_null(isset($p['HireDate'])?$p['HireDate']:''), 
        ':Contract_end_date'      => as_date_or_null(isset($p['Contract_end_date'])?$p['Contract_end_date']:''), 
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
}

/* ---------- Load one ---------- */
function load_employee(PDO $conn, $id){
    $id = (int)$id;
    $st=$conn->prepare("
        SELECT e.*, jt.JobTitleName, d.DepartmentName, l.LocationName,
               sa.FirstName + ' ' + ISNULL(sa.LastName,'') AS AdminSupervisorName,
               s2.FirstName + ' ' + ISNULL(s2.LastName,'') AS SecondLevelSupervisorName
        FROM dbo.Employees e
        LEFT JOIN dbo.Designation jt ON jt.JobTitleID=e.JobTitleID
        LEFT JOIN dbo.Departments d  ON d.DepartmentID=e.DepartmentID
        LEFT JOIN dbo.Locations l    ON l.LocationID=e.LocationID
        LEFT JOIN dbo.Employees sa   ON sa.EmployeeID = e.SupervisorID_admin
        LEFT JOIN dbo.Employees s2   ON s2.EmployeeID = e.SupervisorID_2ndLevel
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
        'SupervisorID_2ndLevel'=>isset($_POST['SupervisorID_2ndLevel'])?$_POST['SupervisorID_2ndLevel']:'', 
        'HireDate'=>isset($_POST['HireDate'])?$_POST['HireDate']:'', 
        'Contract_end_date'=>isset($_POST['Contract_end_date'])?$_POST['Contract_end_date']:'', 
        'EndDate'=>isset($_POST['EndDate'])?$_POST['EndDate']:'', 
        'Blood_group'=>isset($_POST['Blood_group'])?$_POST['Blood_group']:'', 
        'Salary_increment_month'=>isset($_POST['Salary_increment_month'])?$_POST['Salary_increment_month']:'', 
        'DOB'=>isset($_POST['DOB'])?$_POST['DOB']:'', 
        'Job_Type'=>isset($_POST['Job_Type'])?$_POST['Job_Type']:'', 
        'Salary_level'=>isset($_POST['Salary_level'])?$_POST['Salary_level']:'', 
        'Salary_Steps'=>isset($_POST['Salary_Steps'])?$_POST['Salary_Steps']:'', 
        'Status'=>isset($_POST['Status'])?$_POST['Status']:'Active'
    );

    $required=array('EmployeeCode','FirstName','JobTitleID','DepartmentID','LocationID','Status');
    $missing=array();
    foreach($required as $k){
        $v = isset($payload[$k]) ? $payload[$k] : '';
        if($v==='' || (is_int($v) && $v===0)) $missing[]=$k;
    }
    if (!empty($missing)){
        $msg="Missing required: ".implode(', ',$missing);
        $msg_type='danger';
    } else {
        $payload = sanitize_payload($payload, $notes);
        try{
            create_employee($conn,$payload);
            header('Location: '.$self.'?ok=1'); exit;
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
            'SupervisorID_2ndLevel'=>isset($_POST['SupervisorID_2ndLevel'])?$_POST['SupervisorID_2ndLevel']:'', 
            'HireDate'=>isset($_POST['HireDate'])?$_POST['HireDate']:'', 
            'Contract_end_date'=>isset($_POST['Contract_end_date'])?$_POST['Contract_end_date']:'', 
            'EndDate'=>isset($_POST['EndDate'])?$_POST['EndDate']:'', 
            'Blood_group'=>isset($_POST['Blood_group'])?$_POST['Blood_group']:'', 
            'Salary_increment_month'=>isset($_POST['Salary_increment_month'])?$_POST['Salary_increment_month']:'', 
            'DOB'=>isset($_POST['DOB'])?$_POST['DOB']:'', 
            'Job_Type'=>isset($_POST['Job_Type'])?$_POST['Job_Type']:'', 
            'Salary_level'=>isset($_POST['Salary_level'])?$_POST['Salary_level']:'', 
            'Salary_Steps'=>isset($_POST['Salary_Steps'])?$_POST['Salary_Steps']:'', 
            'Status'=>isset($_POST['Status'])?$_POST['Status']:'Active'
        );

        $missing=array();
        foreach(array('EmployeeCode','FirstName','JobTitleID','DepartmentID','LocationID','Status') as $k){
            $v = isset($payload[$k]) ? $payload[$k] : '';
            if($v==='' || (is_int($v) && $v===0)) $missing[]=$k;
        }
        if (!empty($missing)){
            $msg="Missing required: ".implode(', ',$missing);
            $msg_type='danger';
        } else {
            $payload = sanitize_payload($payload, $notes);
            try{
                update_employee($conn,$id,$payload);
                header('Location: '.$self); exit;
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
            $conn->prepare("DELETE FROM dbo.Employees WHERE EmployeeID=:id")->execute(array(':id'=>$id));
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
        LEFT JOIN dbo.Employees s2   ON s2.EmployeeID = e.SupervisorID_2ndLevel
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
          e.Email_Office, e.Email_Personal, e.HireDate,
          e.Contract_end_date,
          e.Salary_level, e.Salary_Steps,
          e.Blood_group, e.Salary_increment_month, e.Job_Type,
          sa.FirstName + ' ' + ISNULL(sa.LastName,'') AS AdminSupervisorName,
          s2.FirstName + ' ' + ISNULL(s2.LastName,'') AS SecondLevelSupervisorName,
          e.CreatedAt
        FROM dbo.Employees e
        LEFT JOIN dbo.Designation jt ON jt.JobTitleID = e.JobTitleID
        LEFT JOIN dbo.Departments d  ON d.DepartmentID = e.DepartmentID
        LEFT JOIN dbo.Locations l    ON l.LocationID = e.LocationID
        LEFT JOIN dbo.Employees sa   ON sa.EmployeeID = e.SupervisorID_admin
        LEFT JOIN dbo.Employees s2   ON s2.EmployeeID = e.SupervisorID_2ndLevel
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
</style>

<div class="page-wrap">
  <div class="d-flex flex-wrap align-items-center justify-content-between mb-3 gap-2">
    <div>
      <h1 class="page-title mb-1"><i class="fas fa-address-card"></i> Manage Employees</h1>
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
              <th>Email (PRF)</th><th>Email (Personal)</th><th>Joining Date</th>
              <th>Immediate Supervisor</th><th>2nd Level Supervisor</th>
              <th>Contract End</th>
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
                <td><?php echo h($r['SecondLevelSupervisorName']); ?></td>
                <td><?php echo h(substr((string)$r['Contract_end_date'],0,10)); ?></td>
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
              <tr><td colspan="16" class="text-center text-muted py-4"><i class="fas fa-user-slash me-1"></i> No data</td></tr>
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

          <div class="row g-3">
            <div class="col-md-3"><label class="form-label">PRF ID (Employee Code) *</label>
              <input type="text" name="EmployeeCode" class="form-control" required value="<?php echo h($editRow['EmployeeCode']); ?>"></div>
            <div class="col-md-3"><label class="form-label">First Name *</label>
              <input type="text" name="FirstName" class="form-control" required value="<?php echo h($editRow['FirstName']); ?>"></div>
            <div class="col-md-3"><label class="form-label">Last Name</label>
              <input type="text" name="LastName" class="form-control" value="<?php echo h($editRow['LastName']); ?>"></div>
            <div class="col-md-3"><label class="form-label">National ID</label>
              <input type="text" name="NationalID" class="form-control" value="<?php echo h($editRow['NationalID']); ?>"></div>

            <div class="col-md-3"><label class="form-label">Email (PRF / Office)</label>
              <input type="email" name="Email_Office" class="form-control" value="<?php echo h($editRow['Email_Office']); ?>"></div>
            <div class="col-md-3"><label class="form-label">Email (Personal)</label>
              <input type="email" name="Email_Personal" class="form-control" value="<?php echo h($editRow['Email_Personal']); ?>"></div>
            <div class="col-md-3"><label class="form-label">Phone 1</label>
              <input type="text" name="Phone1" class="form-control" value="<?php echo h($editRow['Phone1']); ?>"></div>
            <div class="col-md-3"><label class="form-label">Phone 2</label>
              <input type="text" name="Phone2" class="form-control" value="<?php echo h($editRow['Phone2']); ?>"></div>

            <div class="col-md-3"><label class="form-label">Designation *</label>
              <select name="JobTitleID" class="form-select" required>
                <option value="">-- Select --</option>
                <?php foreach ($opts['titles'] as $o): ?>
                  <option value="<?php echo (int)$o['id']; ?>" <?php echo ((int)$editRow['JobTitleID']===(int)$o['id']?'selected':''); ?>><?php echo h($o['name']); ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-3"><label class="form-label">Department *</label>
              <select name="DepartmentID" class="form-select" required>
                <option value="">-- Select --</option>
                <?php foreach ($opts['depts'] as $o): ?>
                  <option value="<?php echo (int)$o['id']; ?>" <?php echo ((int)$editRow['DepartmentID']===(int)$o['id']?'selected':''); ?>><?php echo h($o['name']); ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-3"><label class="form-label">Location *</label>
              <select name="LocationID" class="form-select" required>
                <option value="">-- Select --</option>
                <?php foreach ($opts['locs'] as $o): ?>
                  <option value="<?php echo (int)$o['id']; ?>" <?php echo ((int)$editRow['LocationID']===(int)$o['id']?'selected':''); ?>><?php echo h($o['name']); ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-3"><label class="form-label">Status *</label>
              <select name="Status" class="form-select" required>
                <?php foreach (array('Active','Inactive','On Leave','Terminated') as $s): ?>
                  <option <?php echo ($editRow['Status']===$s?'selected':''); ?>><?php echo h($s); ?></option>
                <?php endforeach; ?>
              </select>
            </div>

            <!-- Supervisor dropdowns -->
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
              <label class="form-label">Supervisor (2nd Level)</label>
              <select name="SupervisorID_2ndLevel" class="form-select">
                <option value="">-- None --</option>
                <?php foreach ($opts['supers_2nd'] as $o): ?>
                  <option value="<?php echo (int)$o['id']; ?>" <?php echo ((int)$editRow['SupervisorID_2ndLevel']===(int)$o['id']?'selected':''); ?>>
                    <?php echo h($o['name']).($o['Status']==='Active'?'':' ('.$o['Status'].')'); ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>

            <div class="col-md-3"><label class="form-label">Joining Date</label>
              <input type="date" name="HireDate" class="form-control" value="<?php echo h(substr((string)$editRow['HireDate'],0,10)); ?>"></div>

            <div class="col-md-3"><label class="form-label">Contract End Date</label>
              <input type="date" name="Contract_end_date" class="form-control" value="<?php echo h(substr((string)$editRow['Contract_end_date'],0,10)); ?>"></div>

            <div class="col-md-3"><label class="form-label">End Date</label>
              <input type="date" name="EndDate" class="form-control" value="<?php echo h(substr((string)$editRow['EndDate'],0,10)); ?>"></div>

            <div class="col-md-3"><label class="form-label">DOB</label>
              <input type="date" name="DOB" class="form-control" value="<?php echo h(substr((string)$editRow['DOB'],0,10)); ?>"></div>

            <div class="col-md-3"><label class="form-label">Blood Group</label>
              <select name="Blood_group" class="form-select">
                <option value="">-- Select --</option>
                <?php foreach ($BLOOD_GROUPS as $bg): ?>
                  <option value="<?php echo h($bg); ?>" <?php echo ($editRow['Blood_group']===$bg?'selected':''); ?>><?php echo h($bg); ?></option>
                <?php endforeach; ?>
              </select>
            </div>

            <div class="col-md-3"><label class="form-label">Increment Month</label>
              <select name="Salary_increment_month" class="form-select">
                <option value="">-- Select --</option>
                <?php foreach ($INCREMENT_MONTHS as $code=>$label): ?>
                  <option value="<?php echo h($code); ?>" <?php echo ($editRow['Salary_increment_month']===$code?'selected':''); ?>><?php echo h($label); ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-3"><label class="form-label">Job Type</label>
              <select name="Job_Type" class="form-select">
                <option value="">-- Select --</option>
                <?php foreach ($JOB_TYPES as $jt): ?>
                  <option value="<?php echo h($jt); ?>" <?php echo ($editRow['Job_Type']===$jt?'selected':''); ?>><?php echo h($jt); ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-3"><label class="form-label">Salary Level</label>
              <select name="Salary_level" class="form-select">
                <option value="">-- Select --</option>
                <?php foreach ($SALARY_LEVELS as $lvl): ?>
                  <option value="<?php echo h($lvl); ?>" <?php echo ($editRow['Salary_level']===$lvl?'selected':''); ?>><?php echo h($lvl); ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-3"><label class="form-label">Salary Step</label>
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

          <div class="col-md-3"><label class="form-label">PRF ID (Employee Code) *</label>
            <input type="text" name="EmployeeCode" class="form-control" required maxlength="50"></div>
          <div class="col-md-3"><label class="form-label">First Name *</label>
            <input type="text" name="FirstName" class="form-control" required maxlength="100"></div>
          <div class="col-md-3"><label class="form-label">Last Name</label>
            <input type="text" name="LastName" class="form-control" maxlength="100"></div>
          <div class="col-md-3"><label class="form-label">National ID</label>
            <input type="text" name="NationalID" class="form-control" maxlength="100"></div>

          <div class="col-md-3"><label class="form-label">Email (PRF / Office)</label>
            <input type="email" name="Email_Office" class="form-control" maxlength="200"></div>
          <div class="col-md-3"><label class="form-label">Email (Personal)</label>
            <input type="email" name="Email_Personal" class="form-control" maxlength="50"></div>
          <div class="col-md-3"><label class="form-label">Phone 1</label>
            <input type="text" name="Phone1" class="form-control" maxlength="15"></div>
          <div class="col-md-3"><label class="form-label">Phone 2</label>
            <input type="text" name="Phone2" class="form-control" maxlength="15"></div>

          <div class="col-md-3"><label class="form-label">Designation *</label>
            <select name="JobTitleID" class="form-select" required>
              <option value="">-- Select --</option>
              <?php foreach ($opts['titles'] as $o): ?>
                <option value="<?php echo (int)$o['id']; ?>"><?php echo h($o['name']); ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-md-3"><label class="form-label">Department *</label>
            <select name="DepartmentID" class="form-select" required>
              <option value="">-- Select --</option>
              <?php foreach ($opts['depts'] as $o): ?>
                <option value="<?php echo (int)$o['id']; ?>"><?php echo h($o['name']); ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-md-3"><label class="form-label">Location *</label>
            <select name="LocationID" class="form-select" required>
              <option value="">-- Select --</option>
              <?php foreach ($opts['locs'] as $o): ?>
                <option value="<?php echo (int)$o['id']; ?>"><?php echo h($o['name']); ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-md-3"><label class="form-label">Status *</label>
            <select name="Status" class="form-select" required>
              <?php foreach (array('Active','Inactive','On Leave','Terminated') as $s): ?>
                <option><?php echo h($s); ?></option>
              <?php endforeach; ?>
            </select>
          </div>

          <!-- Supervisor dropdowns -->
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
            <label class="form-label">Supervisor (2nd Level)</label>
            <select name="SupervisorID_2ndLevel" class="form-select">
              <option value="">-- None --</option>
              <?php foreach ($opts['supers_2nd'] as $o): ?>
                <option value="<?php echo (int)$o['id']; ?>"><?php echo h($o['name']).($o['Status']==='Active'?'':' ('.$o['Status'].')'); ?></option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="col-md-3"><label class="form-label">Joining Date</label><input type="date" name="HireDate" class="form-control"></div>
          <div class="col-md-3"><label class="form-label">Contract End Date</label><input type="date" name="Contract_end_date" class="form-control"></div>
          <div class="col-md-3"><label class="form-label">End Date</label><input type="date" name="EndDate" class="form-control"></div>
          <div class="col-md-3"><label class="form-label">DOB</label><input type="date" name="DOB" class="form-control"></div>

          <div class="col-md-3"><label class="form-label">Blood Group</label>
            <select name="Blood_group" class="form-select">
              <option value="">-- Select --</option>
              <?php foreach ($BLOOD_GROUPS as $bg): ?>
                <option value="<?php echo h($bg); ?>"><?php echo h($bg); ?></option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="col-md-3"><label class="form-label">Increment Month</label>
            <select name="Salary_increment_month" class="form-select">
              <option value="">-- Select --</option>
              <?php foreach ($INCREMENT_MONTHS as $code=>$label): ?>
                <option value="<?php echo h($code); ?>"><?php echo h($label); ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-md-3"><label class="form-label">Job Type</label>
            <select name="Job_Type" class="form-select">
              <option value="">-- Select --</option>
              <?php foreach ($JOB_TYPES as $jt): ?>
                <option value="<?php echo h($jt); ?>"><?php echo h($jt); ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-md-3"><label class="form-label">Salary Level</label>
            <select name="Salary_level" class="form-select">
              <option value="">-- Select --</option>
              <?php foreach ($SALARY_LEVELS as $lvl): ?>
                <option value="<?php echo h($lvl); ?>"><?php echo h($lvl); ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-md-3"><label class="form-label">Salary Step</label>
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
  var LIMITS = { EmployeeCode:50, FirstName:100, LastName:100, NationalID:100, Email_Office:200, Email_Personal:50, Phone1:15, Phone2:15, Blood_group:3, Salary_increment_month:10, Job_Type:50, Salary_level:10 };
  var REQUIRED = ["EmployeeCode","FirstName","JobTitleID","DepartmentID","LocationID","Status"];
  function $(sel, root){ return (root||document).querySelector(sel); }
  function $all(sel, root){ return Array.prototype.slice.call((root||document).querySelectorAll(sel)); }
  function ensureFeedbackEl(input){ var next = input.nextElementSibling; if(!(next && next.classList && next.classList.contains('invalid-feedback'))){ var div = document.createElement('div'); div.className = 'invalid-feedback'; input.parentNode.insertBefore(div, input.nextSibling); } return input.nextElementSibling; }
  function setInvalid(input, msg){ input.classList.add('is-invalid'); input.classList.remove('is-valid'); ensureFeedbackEl(input).textContent = msg || 'Invalid value'; }
  function setValid(input){ input.classList.remove('is-invalid'); input.classList.add('is-valid'); var fb = input.nextElementSibling; if(fb && fb.classList.contains('invalid-feedback')) fb.textContent = ''; }
  function isEmail(v){ if(!v) return true; return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(v); }
  function digitsOnly(v){ return (v||'').replace(/\D+/g,''); }
  function validateOne(input, form){
    var name = input.getAttribute('name');
    var val = (input.value||'').trim();
    var isSelect = input.tagName === 'SELECT';
    if(REQUIRED.indexOf(name) !== -1){
      if((!isSelect && val === '') || (isSelect && (val === '' || val === null))){ setInvalid(input,'This field is required'); return false; }
    }
    if(LIMITS[name] && val.length > LIMITS[name]){ setInvalid(input,'Max '+LIMITS[name]+' characters'); return false; }
    switch(name){
      case 'Email_Office':
      case 'Email_Personal':
        if(val && !isEmail(val)){ setInvalid(input,'Enter a valid email'); return false; }
        break;
      case 'Phone1':
      case 'Phone2':
        if(val){ var d=digitsOnly(val); if(d.length > LIMITS[name]){ setInvalid(input,'Max '+LIMITS[name]+' digits'); return false; } if(d && !/^\d+$/.test(d)){ setInvalid(input,'Digits only'); return false; } if(val !== d){ input.value = d; } }
        break;
    }
    setValid(input); return true;
  }
  function validateForm(form){
    var ok = true;
    ['Email_Office','Email_Personal'].forEach(function(n){ var el=$('input[name="'+n+'"]',form); if(el && el.value){ el.value = el.value.trim().toLowerCase(); } });
    $all('input, select, textarea', form).forEach(function(inp){ if(inp.getAttribute('name')){ if(!validateOne(inp, form)){ ok=false; } } });
    return ok;
  }
  function attach(form){
    $all('input, select, textarea', form).forEach(function(inp){
      if(!inp.getAttribute('name')) return;
      inp.addEventListener('input', function(){ validateOne(inp, form); });
      inp.addEventListener('change', function(){ validateOne(inp, form); });
      if(inp.name === 'Email_Office' || inp.name === 'Email_Personal'){
        inp.addEventListener('blur', function(){ inp.value = (inp.value||'').trim().toLowerCase(); validateOne(inp, form); });
      }
    });
    form.addEventListener('submit', function(e){ if(!validateForm(form)){ e.preventDefault(); e.stopPropagation(); var firstInvalid=form.querySelector('.is-invalid'); if(firstInvalid) firstInvalid.focus(); } });
  }
  $all('form').forEach(function(f){ if(f.querySelector('input[name="act"][value="create"], input[name="act"][value="update"]')){ attach(f); } });
})();
</script>

<style>
.invalid-feedback{ display:block; font-size:12px; color:#dc2626; margin-top:4px; }
.is-invalid{ border-color:#dc2626!important; }
.is-valid{ border-color:#16a34a!important; }
</style>

<?php require_once __DIR__ . '/../../include/footer.php'; ?>
