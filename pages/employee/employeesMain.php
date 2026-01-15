<?php
/***********************
 * Employees - Full CRUD (SQL Server, PDO)
 * - Auto-detects EmployeeID IDENTITY (omit on insert if identity)
 * - Supervisor dropdowns by RoleName:
 *     "Administrative Supervisor" → SupervisorID_admin
 *     "Technical Supervisor"      → SupervisorID_technical
 * - Strong input sanitation to avoid SQLSTATE 22001 (truncation)
 ***********************/
ini_set('display_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/../../init.php';
require_login();

/* ---------- Tiny helpers ---------- */
if (!function_exists('h')) { function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); } }
function self_name(){ return strtok(basename($_SERVER['SCRIPT_NAME']), "?"); }
$self = self_name();
function as_null($v){ $v = trim((string)$v); return ($v===''? null : $v); }
function as_date_or_null($v){ $v = trim((string)$v); return ($v===''? null : $v); }
function errfmt(PDOException $e){
  $s=$e->getCode(); $m=$e->getMessage();
  $c = (preg_match('/constraint\s+\'?([^\']+)/i',$m,$mm)? " | Constraint: ".$mm[1] : "");
  return "SQLSTATE {$s}{$c} | {$m}";
}

/* ---------- CSRF ---------- */
if (!isset($_SESSION['csrf'])) {
  $_SESSION['csrf'] = function_exists('openssl_random_pseudo_bytes')
    ? bin2hex(openssl_random_pseudo_bytes(16))
    : substr(str_shuffle(md5(uniqid(mt_rand(), true))), 0, 32);
}
$CSRF = $_SESSION['csrf'];
function check_csrf(){ if (!isset($_POST['csrf']) || $_POST['csrf'] !== $_SESSION['csrf']) die('Invalid CSRF token'); }

/* ---------- Alerts ---------- */
$msg=''; $msg_type='success'; $notes=[];

/* ---------- Column limits (from your screenshot) ---------- */
const COL_LIMITS = [
  'EmployeeCode'          => 50,
  'FirstName'             => 100,
  'LastName'              => 100,
  'NationalID'            => 100,
  'Email_Office'          => 200,   // NVARCHAR(200)
  'Email_Personal'        => 50,    // VARCHAR(50)
  'Phone1'                => 11,    // CHAR(11)
  'Phone2'                => 11,    // CHAR(11)
  'Blood_group'           => 2,     // CHAR(2)
  'Salary_increment_month'=> 1,     // CHAR(1)  << IMPORTANT!
  'Job_Type'              => 50,
  'Salary_level'          => 10,
  'Status'                => 50
];

/* ---------- Sanitizer (prevents 22001) ---------- */
function sanitize_payload(array $p, array &$notes): array {
  // Emails → lowercase
  foreach (['Email_Office','Email_Personal'] as $k) {
    if (isset($p[$k])) $p[$k] = trim(mb_strtolower((string)$p[$k]));
  }

  // Hard length clamp with user-visible notes
  foreach (COL_LIMITS as $k=>$limit) {
    if (!array_key_exists($k,$p)) continue;
    $v = (string)$p[$k];
    if ($v === '') { $p[$k] = ''; continue; }
    if (mb_strlen($v) > $limit) {
      $notes[] = "$k was too long (".mb_strlen($v)." > {$limit}) and has been truncated.";
      $p[$k] = mb_substr($v, 0, $limit);
    }
  }

  // Special rule: Salary_increment_month = CHAR(1)
  // If user typed "January" or "10/11/12", we'll null it and add a note (DB cannot store it).
  if (isset($p['Salary_increment_month'])) {
    $raw = trim((string)$p['Salary_increment_month']);
    if ($raw === '') {
      $p['Salary_increment_month'] = null;
    } else {
      // Accept exactly one character; anything else becomes NULL with a note.
      if (mb_strlen($raw) !== 1) {
        $notes[] = "Salary_increment_month is CHAR(1). Value '{$raw}' cannot fit and was saved as NULL. ".
                   "Either enter a single character (e.g. '1'..'9' or a code) or widen the column to CHAR(2)/TINYINT.";
        $p['Salary_increment_month'] = null;
      }
    }
  }

  // Fixed-length char columns: Phone1/Phone2 may be shorter; that's okay in SQL Server.
  // Blood_group must be <=2; already enforced above.

  return $p;
}

/* ---------- Identity detection ---------- */
function emp_is_identity(PDO $conn): bool {
  $stmt = $conn->query("SELECT COLUMNPROPERTY(OBJECT_ID('dbo.Employees'),'EmployeeID','IsIdentity')");
  return ((int)$stmt->fetchColumn()) === 1;
}

/* ---------- Supervisor lists (by RoleName) ---------- */
function supervisors_by_role(PDO $conn, string $roleName): array {
  $sql = "
    WITH RU AS (
      SELECT DISTINCT u.UserID, u.Username, u.Email
      FROM dbo.Users u
      JOIN dbo.UserRoles ur ON ur.UserID = u.UserID
      JOIN dbo.Roles r      ON r.RoleID   = ur.RoleID
      WHERE r.RoleName = :rname
    )
    SELECT
      e.EmployeeID AS id,
      LTRIM(RTRIM(e.FirstName + ' ' + ISNULL(e.LastName,''))) AS name,
      e.Status,
      ru.Username,
      ru.Email
    FROM RU ru
    LEFT JOIN dbo.Employees e
         ON (e.Email_Office = ru.Email OR e.EmployeeID = ru.UserID)
    WHERE e.EmployeeID IS NOT NULL
    ORDER BY CASE WHEN e.Status='Active' THEN 0 ELSE 1 END,
             e.FirstName, e.LastName;
  ";
  $st=$conn->prepare($sql); $st->execute([':rname'=>$roleName]);
  return $st->fetchAll(PDO::FETCH_ASSOC);
}

function load_fk_options(PDO $conn, ?array $current=null){
  $opts = ['titles'=>[], 'depts'=>[], 'locs'=>[], 'supers_admin'=>[], 'supers_tech'=>[]];

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

  $opts['supers_admin']=supervisors_by_role($conn, 'Administrative Supervisor');
  $opts['supers_tech'] =supervisors_by_role($conn, 'Technical Supervisor');

  // Keep current selected supervisors even if they lost role
  if ($current) {
    foreach (['SupervisorID_admin'=>'supers_admin','SupervisorID_technical'=>'supers_tech'] as $field=>$key){
      $cur = isset($current[$field]) ? (int)$current[$field] : 0;
      if ($cur>0){
        $exists=false; foreach($opts[$key] as $r){ if((int)$r['id']===$cur){ $exists=true; break; } }
        if(!$exists){
          $st=$conn->prepare("SELECT EmployeeID AS id, LTRIM(RTRIM(FirstName + ' ' + ISNULL(LastName,''))) AS name, Status FROM dbo.Employees WHERE EmployeeID=:id");
          $st->execute([':id'=>$cur]); if($tmp=$st->fetch(PDO::FETCH_ASSOC)){ $tmp['name'].=' (not in role)'; array_unshift($opts[$key],$tmp); }
        }
      }
    }
  }
  return $opts;
}

/* ---------- Create / Update DB functions ---------- */
function create_employee(PDO $conn, array $p): void {
  $usesIdentity = emp_is_identity($conn);

  // Non-identity হলে নিরাপদ MAX+1 + টেবিল লক
  if (!$usesIdentity) {
    $conn->exec("SET TRANSACTION ISOLATION LEVEL SERIALIZABLE");
    $conn->beginTransaction();
  }

  try {
    $params = [
      ':EmployeeCode'            => $p['EmployeeCode'],
      ':FirstName'               => $p['FirstName'],
      ':LastName'                => as_null($p['LastName'] ?? ''),
      ':NationalID'              => as_null($p['NationalID'] ?? ''),
      ':Email_Office'            => as_null($p['Email_Office'] ?? ''),
      ':Email_Personal'          => as_null($p['Email_Personal'] ?? ''),
      ':Phone1'                  => as_null($p['Phone1'] ?? ''),
      ':Phone2'                  => as_null($p['Phone2'] ?? ''),
      ':JobTitleID'              => (int)$p['JobTitleID'],
      ':DepartmentID'            => (int)$p['DepartmentID'],
      ':LocationID'              => (int)$p['LocationID'],
      ':SupervisorID_admin'      => ($p['SupervisorID_admin']!==''?(int)$p['SupervisorID_admin']:null),
      ':SupervisorID_technical'  => ($p['SupervisorID_technical']!==''?(int)$p['SupervisorID_technical']:null),
      ':HireDate'                => as_date_or_null($p['HireDate'] ?? ''),
      ':EndDate'                 => as_date_or_null($p['EndDate'] ?? ''),
      ':Blood_group'             => as_null($p['Blood_group'] ?? ''),
      ':Salary_increment_month'  => as_null($p['Salary_increment_month'] ?? ''),
      ':DOB'                     => as_date_or_null($p['DOB'] ?? ''),
      ':Job_Type'                => as_null($p['Job_Type'] ?? ''),
      ':Salary_level'            => as_null($p['Salary_level'] ?? ''),
      ':Salary_Steps'            => as_null($p['Salary_Steps'] ?? ''),
      ':Status'                  => $p['Status'] ?: 'Active',
      ':CreatedBy'               => isset($_SESSION['auth_user']['UserID'])?(int)$_SESSION['auth_user']['UserID']:null
    ];

    if ($usesIdentity) {
      // EmployeeID কলাম বাদ (IDENTITY)
      $sql = "
        INSERT INTO dbo.Employees
        (EmployeeCode, FirstName, LastName, NationalID,
         Email_Office, Email_Personal, Phone1, Phone2,
         JobTitleID, DepartmentID, LocationID,
         SupervisorID_admin, SupervisorID_technical,
         HireDate, EndDate, Blood_group, Salary_increment_month,
         DOB, Job_Type, Salary_level, Salary_Steps, Status, CreatedAt, CreatedBy)
        VALUES
        (:EmployeeCode, :FirstName, :LastName, :NationalID,
         :Email_Office, :Email_Personal, :Phone1, :Phone2,
         :JobTitleID, :DepartmentID, :LocationID,
         :SupervisorID_admin, :SupervisorID_technical,
         :HireDate, :EndDate, :Blood_group, :Salary_increment_month,
         :DOB, :Job_Type, :Salary_level, :Salary_Steps, :Status, GETDATE(), :CreatedBy)
      ";
    } else {
      // EmployeeID ম্যানুয়ালি সেট (NON-IDENTITY)
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
        (:EmployeeID, :EmployeeCode, :FirstName, :LastName, :NationalID,
         :Email_Office, :Email_Personal, :Phone1, :Phone2,
         :JobTitleID, :DepartmentID, :LocationID,
         :SupervisorID_admin, :SupervisorID_technical,
         :HireDate, :EndDate, :Blood_group, :Salary_increment_month,
         :DOB, :Job_Type, :Salary_level, :Salary_Steps, :Status, GETDATE(), :CreatedBy)
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


function update_employee(PDO $conn, int $id, array $p): void {
  $sql="
    UPDATE dbo.Employees
       SET EmployeeCode=:EmployeeCode, FirstName=:FirstName, LastName=:LastName, NationalID=:NationalID,
           Email_Office=:Email_Office, Email_Personal=:Email_Personal, Phone1=:Phone1, Phone2=:Phone2,
           JobTitleID=:JobTitleID, DepartmentID=:DepartmentID, LocationID=:LocationID,
           SupervisorID_admin=:SupervisorID_admin, SupervisorID_technical=:SupervisorID_technical,
           HireDate=:HireDate, EndDate=:EndDate, Blood_group=:Blood_group, Salary_increment_month=:Salary_increment_month,
           DOB=:DOB, Job_Type=:Job_Type, Salary_level=:Salary_level, Salary_Steps=:Salary_Steps, Status=:Status
     WHERE EmployeeID=:EmployeeID";
  $stmt=$conn->prepare($sql);
  $stmt->execute([
    ':EmployeeCode'=>$p['EmployeeCode'], ':FirstName'=>$p['FirstName'], ':LastName'=>as_null($p['LastName']??''),
    ':NationalID'=>as_null($p['NationalID']??''), ':Email_Office'=>as_null($p['Email_Office']??''),
    ':Email_Personal'=>as_null($p['Email_Personal']??''), ':Phone1'=>as_null($p['Phone1']??''),
    ':Phone2'=>as_null($p['Phone2']??''), ':JobTitleID'=>(int)$p['JobTitleID'], ':DepartmentID'=>(int)$p['DepartmentID'],
    ':LocationID'=>(int)$p['LocationID'],
    ':SupervisorID_admin'=>($p['SupervisorID_admin']!==''?(int)$p['SupervisorID_admin']:null),
    ':SupervisorID_technical'=>($p['SupervisorID_technical']!==''?(int)$p['SupervisorID_technical']:null),
    ':HireDate'=>as_date_or_null($p['HireDate']??''), ':EndDate'=>as_date_or_null($p['EndDate']??''),
    ':Blood_group'=>as_null($p['Blood_group']??''), ':Salary_increment_month'=>as_null($p['Salary_increment_month']??''),
    ':DOB'=>as_date_or_null($p['DOB']??''), ':Job_Type'=>as_null($p['Job_Type']??''),
    ':Salary_level'=>as_null($p['Salary_level']??''), ':Salary_Steps'=>as_null($p['Salary_Steps']??''),
    ':Status'=>$p['Status']?:'Active', ':EmployeeID'=>$id
  ]);
}

/* ---------- Load one ---------- */
function load_employee(PDO $conn, int $id){
  $st=$conn->prepare("
    SELECT e.*, jt.JobTitleName, d.DepartmentName, l.LocationName
    FROM dbo.Employees e
    LEFT JOIN dbo.Designation jt ON jt.JobTitleID=e.JobTitleID
    LEFT JOIN dbo.Departments d  ON d.DepartmentID=e.DepartmentID
    LEFT JOIN dbo.Locations l    ON l.LocationID=e.LocationID
    WHERE e.EmployeeID=:id
  "); $st->execute([':id'=>$id]); return $st->fetch(PDO::FETCH_ASSOC);
}

/* ---------- Controller ---------- */
$editRow=null;
$edit_id = isset($_GET['edit'])?(int)$_GET['edit']:0;
$filter  = isset($_GET['q'])?trim($_GET['q']):'';

if ($edit_id>0){
  try{ $editRow=load_employee($conn,$edit_id); }
  catch(PDOException $e){ $msg="Load edit row failed: ".h(errfmt($e)); $msg_type='danger'; }
}

$opts = load_fk_options($conn, $editRow);

if (isset($_GET['ok']) && $_GET['ok']==='1'){ $msg='Employee created.'; $msg_type='success'; }

/* ---- CREATE ---- */
if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['act']??'')==='create'){
  check_csrf();
  $payload = [
    'EmployeeCode'=>trim($_POST['EmployeeCode']??''),
    'FirstName'=>trim($_POST['FirstName']??''),
    'LastName'=>$_POST['LastName']??'',
    'NationalID'=>$_POST['NationalID']??'',
    'Email_Office'=>$_POST['Email_Office']??'',
    'Email_Personal'=>$_POST['Email_Personal']??'',
    'Phone1'=>$_POST['Phone1']??'',
    'Phone2'=>$_POST['Phone2']??'',
    'JobTitleID'=>(int)($_POST['JobTitleID']??0),
    'DepartmentID'=>(int)($_POST['DepartmentID']??0),
    'LocationID'=>(int)($_POST['LocationID']??0),
    'SupervisorID_admin'=>$_POST['SupervisorID_admin']??'',
    'SupervisorID_technical'=>$_POST['SupervisorID_technical']??'',
    'HireDate'=>$_POST['HireDate']??'',
    'EndDate'=>$_POST['EndDate']??'',
    'Blood_group'=>$_POST['Blood_group']??'',
    'Salary_increment_month'=>$_POST['Salary_increment_month']??'',
    'DOB'=>$_POST['DOB']??'',
    'Job_Type'=>$_POST['Job_Type']??'',
    'Salary_level'=>$_POST['Salary_level']??'',
    'Salary_Steps'=>$_POST['Salary_Steps']??'',
    'Status'=>$_POST['Status']??'Active'
  ];

  // Requireds
  $required=['EmployeeCode','FirstName','JobTitleID','DepartmentID','LocationID','Status'];
  $missing=[]; foreach($required as $k){ if(($payload[$k]??'')==='' || (is_int($payload[$k]) && $payload[$k]===0)) $missing[]=$k; }
  if ($missing){ $msg="Missing required: ".implode(', ',$missing); $msg_type='danger'; }
  else{
    // Sanitize → prevents 22001
    $payload = sanitize_payload($payload, $notes);
    try{
      create_employee($conn,$payload);
      $extra = $notes ? ' (Note: '.implode(' ', array_map('h',$notes)).')' : '';
      header('Location: '.$self.'?ok=1'); exit;
    }catch(PDOException $e){
      $msg="Create failed: ".h(errfmt($e)); $msg_type='danger';
      if ($notes) $msg .= '<br><small>'.h(implode(' ', $notes)).'</small>';
    }
  }
}

/* ---- UPDATE ---- */
if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['act']??'')==='update'){
  check_csrf();
  $id=(int)($_POST['EmployeeID']??0);
  if ($id<=0){ $msg="Invalid EmployeeID."; $msg_type='danger'; }
  else{
    $payload = [
      'EmployeeCode'=>trim($_POST['EmployeeCode']??''),
      'FirstName'=>trim($_POST['FirstName']??''),
      'LastName'=>$_POST['LastName']??'',
      'NationalID'=>$_POST['NationalID']??'',
      'Email_Office'=>$_POST['Email_Office']??'',
      'Email_Personal'=>$_POST['Email_Personal']??'',
      'Phone1'=>$_POST['Phone1']??'',
      'Phone2'=>$_POST['Phone2']??'',
      'JobTitleID'=>(int)($_POST['JobTitleID']??0),
      'DepartmentID'=>(int)($_POST['DepartmentID']??0),
      'LocationID'=>(int)($_POST['LocationID']??0),
      'SupervisorID_admin'=>$_POST['SupervisorID_admin']??'',
      'SupervisorID_technical'=>$_POST['SupervisorID_technical']??'',
      'HireDate'=>$_POST['HireDate']??'',
      'EndDate'=>$_POST['EndDate']??'',
      'Blood_group'=>$_POST['Blood_group']??'',
      'Salary_increment_month'=>$_POST['Salary_increment_month']??'',
      'DOB'=>$_POST['DOB']??'',
      'Job_Type'=>$_POST['Job_Type']??'',
      'Salary_level'=>$_POST['Salary_level']??'',
      'Salary_Steps'=>$_POST['Salary_Steps']??'',
      'Status'=>$_POST['Status']??'Active'
    ];

    $missing=[]; foreach(['EmployeeCode','FirstName','JobTitleID','DepartmentID','LocationID','Status'] as $k){ if(($payload[$k]??'')==='' || (is_int($payload[$k]) && $payload[$k]===0)) $missing[]=$k; }
    if ($missing){ $msg="Missing required: ".implode(', ',$missing); $msg_type='danger'; }
    else{
      $payload = sanitize_payload($payload, $notes);
      try{
        update_employee($conn,$id,$payload);
        header('Location: '.$self); exit;
      }catch(PDOException $e){
        $msg="Update failed: ".h(errfmt($e)); $msg_type='danger';
        if ($notes) $msg .= '<br><small>'.h(implode(' ', $notes)).'</small>';
      }
    }
  }
}

/* ---- TOGGLE / DELETE ---- */
if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['act']??'')==='toggle'){
  check_csrf();
  $id=(int)($_POST['EmployeeID']??0); $to=$_POST['to']??'Active';
  if($id>0){
    try{ $conn->prepare("UPDATE dbo.Employees SET Status=:s WHERE EmployeeID=:id")->execute([':s'=>$to,':id'=>$id]);
         $msg=($to==='Active'?'Activated.':'Deactivated.'); $msg_type='success';
    }catch(PDOException $e){ $msg="Toggle failed: ".h(errfmt($e)); $msg_type='danger'; }
  }
}
if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['act']??'')==='delete'){
  check_csrf(); $id=(int)($_POST['EmployeeID']??0);
  if($id>0){
    try{ $conn->prepare("DELETE FROM dbo.Employees WHERE EmployeeID=:id")->execute([':id'=>$id]);
         $msg="Employee deleted."; $msg_type='success';
    }catch(PDOException $e){ $msg="Delete failed: ".h(errfmt($e)); $msg_type='danger'; }
  }
}

/* ---- List ---- */
try{
  if($filter!==''){
    $st=$conn->prepare("
      SELECT e.EmployeeID, e.EmployeeCode, e.FirstName, e.LastName, e.Status,
             jt.JobTitleName, d.DepartmentName, l.LocationName, e.CreatedAt
      FROM dbo.Employees e
      LEFT JOIN dbo.Designation jt ON jt.JobTitleID = e.JobTitleID
      LEFT JOIN dbo.Departments d ON d.DepartmentID = e.DepartmentID
      LEFT JOIN dbo.Locations l ON l.LocationID = e.LocationID
      WHERE e.EmployeeCode LIKE :q OR e.FirstName LIKE :q OR e.LastName LIKE :q
         OR jt.JobTitleName LIKE :q OR d.DepartmentName LIKE :q OR l.LocationName LIKE :q
      ORDER BY e.FirstName, e.LastName");
    $st->execute([':q'=>'%'.$filter.'%']); $rows=$st->fetchAll(PDO::FETCH_ASSOC);
  }else{
    $rows=$conn->query("
      SELECT e.EmployeeID, e.EmployeeCode, e.FirstName, e.LastName, e.Status,
             jt.JobTitleName, d.DepartmentName, l.LocationName, e.CreatedAt
      FROM dbo.Employees e
      LEFT JOIN dbo.Designation jt ON jt.JobTitleID = e.JobTitleID
      LEFT JOIN dbo.Departments d ON d.DepartmentID = e.DepartmentID
      LEFT JOIN dbo.Locations l ON l.LocationID = e.LocationID
      ORDER BY e.FirstName, e.LastName")->fetchAll(PDO::FETCH_ASSOC);
  }
}catch(PDOException $e){ $rows=[]; $msg="Load list failed: ".h(errfmt($e)); $msg_type='danger'; }

/* ---------- View ---------- */
require_once __DIR__ . '/../../include/header.php';
?>
<style>
  .page-wrap { margin:28px auto; padding:0 12px; }
  .page-title { font-weight:700; letter-spacing:.2px; }
  .card-elevated { border:1px solid #e5e7eb; border-radius:12px; box-shadow:0 8px 24px rgba(2,6,23,.06); }
  .badge-soft { border:1px solid #e2e8f0; background:#f8fafc; border-radius:999px; padding:4px 10px; font-size:12px; }
  .btn-brand { background:#2563eb; color:#fff!important; border:none; }
  .btn-brand:hover{ background:#1d4ed8; }
  .btn-muted { background:#e5e7eb; color:#111827!important; border:none; }
  .btn-muted:hover{ background:#d1d5db; }
  .btn-danger-soft{ background:#fee2e2; color:#b91c1c!important; border:1px solid #fecaca; }
  .btn-danger-soft:hover{ background:#fecaca; }
  .form-label{ font-weight:600; color:#374151; }
  .form-control, .form-select{ border-radius:10px; border-color:#cbd5e1; }
  .action-stack > *{ margin:4px; }
  @media (min-width:768px){ .action-stack{ display:inline-flex; gap:6px; } }
  .table thead th{ background:#f8fafc; color:#334155; border-bottom:1px solid #e5e7eb; }
  .table tbody td{ vertical-align:middle; }
</style>

<div class="page-wrap">
  <div class="d-flex flex-wrap align-items-center justify-content-between mb-3 gap-2">
    <h1 class="page-title mb-0">Employees</h1>
    <form method="get" class="w-100 w-md-auto" accept-charset="UTF-8">
      <div class="input-group">
        <input type="text" name="q" class="form-control" placeholder="Search code/name/department/..." value="<?php echo h($filter); ?>">
        <button class="btn btn-brand" type="submit">Search</button>
        <a class="btn btn-muted" href="<?php echo h($self); ?>">Reset</a>
      </div>
    </form>
  </div>

  <?php if ($msg): ?>
    <div class="alert alert-<?php echo ($msg_type==='danger'?'danger':'success'); ?> alert-dismissible fade show" role="alert">
      <?php echo $msg; ?>
      <?php if ($notes): ?><div class="mt-1 small text-muted"><?php echo h(implode(' ', $notes)); ?></div><?php endif; ?>
      <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
  <?php endif; ?>

  <div class="card card-elevated mb-4">
    <div class="card-body">
      <?php if (!empty($editRow)): ?>
        <div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-3">
          <h5 class="mb-0">Edit Employee</h5>
          <a class="btn btn-muted btn-sm" href="<?php echo h($self); ?>">Cancel</a>
        </div>

        <!-- EDIT FORM -->
        <form method="post" accept-charset="UTF-8">
          <input type="hidden" name="csrf" value="<?php echo h($CSRF); ?>">
          <input type="hidden" name="act" value="update">
          <input type="hidden" name="EmployeeID" value="<?php echo (int)$editRow['EmployeeID']; ?>">

          <div class="row g-3">
            <div class="col-md-3">
              <label class="form-label">Employee Code *</label>
              <input type="text" name="EmployeeCode" class="form-control" required value="<?php echo h($editRow['EmployeeCode']); ?>">
            </div>
            <div class="col-md-3">
              <label class="form-label">First Name *</label>
              <input type="text" name="FirstName" class="form-control" required value="<?php echo h($editRow['FirstName']); ?>">
            </div>
            <div class="col-md-3">
              <label class="form-label">Last Name</label>
              <input type="text" name="LastName" class="form-control" value="<?php echo h($editRow['LastName']); ?>">
            </div>
            <div class="col-md-3">
              <label class="form-label">National ID</label>
              <input type="text" name="NationalID" class="form-control" value="<?php echo h($editRow['NationalID']); ?>">
            </div>

            <div class="col-md-3">
              <label class="form-label">Email (Office)</label>
              <input type="email" name="Email_Office" class="form-control" value="<?php echo h($editRow['Email_Office']); ?>">
            </div>
            <div class="col-md-3">
              <label class="form-label">Email (Personal) (max 50)</label>
              <input type="email" name="Email_Personal" class="form-control" value="<?php echo h($editRow['Email_Personal']); ?>">
            </div>
            <div class="col-md-3">
              <label class="form-label">Phone 1 (<=11)</label>
              <input type="text" name="Phone1" class="form-control" value="<?php echo h($editRow['Phone1']); ?>">
            </div>
            <div class="col-md-3">
              <label class="form-label">Phone 2 (<=11)</label>
              <input type="text" name="Phone2" class="form-control" value="<?php echo h($editRow['Phone2']); ?>">
            </div>

            <div class="col-md-3">
              <label class="form-label">Designation *</label>
              <select name="JobTitleID" class="form-select" required>
                <option value="">-- Select --</option>
                <?php foreach ($opts['titles'] as $o): ?>
                  <option value="<?php echo (int)$o['id']; ?>" <?php echo ((int)$editRow['JobTitleID']===(int)$o['id']?'selected':''); ?>>
                    <?php echo h($o['name']); ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-3">
              <label class="form-label">Department *</label>
              <select name="DepartmentID" class="form-select" required>
                <option value="">-- Select --</option>
                <?php foreach ($opts['depts'] as $o): ?>
                  <option value="<?php echo (int)$o['id']; ?>" <?php echo ((int)$editRow['DepartmentID']===(int)$o['id']?'selected':''); ?>>
                    <?php echo h($o['name']); ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-3">
              <label class="form-label">Location *</label>
              <select name="LocationID" class="form-select" required>
                <option value="">-- Select --</option>
                <?php foreach ($opts['locs'] as $o): ?>
                  <option value="<?php echo (int)$o['id']; ?>" <?php echo ((int)$editRow['LocationID']===(int)$o['id']?'selected':''); ?>>
                    <?php echo h($o['name']); ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-3">
              <label class="form-label">Status *</label>
              <select name="Status" class="form-select" required>
                <?php foreach (['Active','Inactive','On Leave','Terminated'] as $s): ?>
                  <option <?php echo ($editRow['Status']===$s?'selected':''); ?>><?php echo h($s); ?></option>
                <?php endforeach; ?>
              </select>
            </div>

            <div class="col-md-3">
              <label class="form-label">Supervisor (Admin)</label>
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
              <label class="form-label">Hire Date</label>
              <input type="date" name="HireDate" class="form-control" value="<?php echo h(substr((string)$editRow['HireDate'],0,10)); ?>">
            </div>
            <div class="col-md-3">
              <label class="form-label">End Date</label>
              <input type="date" name="EndDate" class="form-control" value="<?php echo h(substr((string)$editRow['EndDate'],0,10)); ?>">
            </div>
            <div class="col-md-3">
              <label class="form-label">DOB</label>
              <input type="date" name="DOB" class="form-control" value="<?php echo h(substr((string)$editRow['DOB'],0,10)); ?>">
            </div>
            <div class="col-md-3">
              <label class="form-label">Blood Group (2 chars)</label>
              <input type="text" name="Blood_group" class="form-control" value="<?php echo h($editRow['Blood_group']); ?>">
            </div>

            <div class="col-md-3">
              <label class="form-label">Increment Month (CHAR(1))</label>
              <input type="text" name="Salary_increment_month" class="form-control" maxlength="1" placeholder="e.g. 1" value="<?php echo h($editRow['Salary_increment_month']); ?>">
              <small class="text-muted">DB column is CHAR(1). Use a single character code or widen the column.</small>
            </div>
            <div class="col-md-3">
              <label class="form-label">Job Type</label>
              <input type="text" name="Job_Type" class="form-control" value="<?php echo h($editRow['Job_Type']); ?>">
            </div>
            <div class="col-md-3">
              <label class="form-label">Salary Level</label>
              <input type="text" name="Salary_level" class="form-control" value="<?php echo h($editRow['Salary_level']); ?>">
            </div>
            <div class="col-md-3">
              <label class="form-label">Salary Steps</label>
              <input type="text" name="Salary_Steps" class="form-control" value="<?php echo h($editRow['Salary_Steps']); ?>">
            </div>

            <div class="col-12 d-grid d-md-inline">
              <button class="btn btn-brand w-100 w-md-auto">Update</button>
            </div>
          </div>
        </form>

      <?php else: ?>
        <h5 class="mb-3">Add Employee</h5>
        <!-- CREATE FORM -->
        <form method="post" class="row g-3" accept-charset="UTF-8">
          <input type="hidden" name="csrf" value="<?php echo h($CSRF); ?>">
          <input type="hidden" name="act" value="create">

          <div class="col-md-3">
            <label class="form-label">Employee Code *</label>
            <input type="text" name="EmployeeCode" class="form-control" required maxlength="50">
          </div>
          <div class="col-md-3">
            <label class="form-label">First Name *</label>
            <input type="text" name="FirstName" class="form-control" required maxlength="100">
          </div>
          <div class="col-md-3">
            <label class="form-label">Last Name</label>
            <input type="text" name="LastName" class="form-control" maxlength="100">
          </div>
          <div class="col-md-3">
            <label class="form-label">National ID</label>
            <input type="text" name="NationalID" class="form-control" maxlength="100">
          </div>

          <div class="col-md-3">
            <label class="form-label">Email (Office)</label>
            <input type="email" name="Email_Office" class="form-control" maxlength="200">
          </div>
          <div class="col-md-3">
            <label class="form-label">Email (Personal) (max 50)</label>
            <input type="email" name="Email_Personal" class="form-control" maxlength="50">
          </div>
          <div class="col-md-3">
            <label class="form-label">Phone 1 (<=11)</label>
            <input type="text" name="Phone1" class="form-control" maxlength="11">
          </div>
          <div class="col-md-3">
            <label class="form-label">Phone 2 (<=11)</label>
            <input type="text" name="Phone2" class="form-control" maxlength="11">
          </div>

          <div class="col-md-3">
            <label class="form-label">Designation *</label>
            <select name="JobTitleID" class="form-select" required>
              <option value="">-- Select --</option>
              <?php foreach ($opts['titles'] as $o): ?>
                <option value="<?php echo (int)$o['id']; ?>"><?php echo h($o['name']); ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-md-3">
            <label class="form-label">Department *</label>
            <select name="DepartmentID" class="form-select" required>
              <option value="">-- Select --</option>
              <?php foreach ($opts['depts'] as $o): ?>
                <option value="<?php echo (int)$o['id']; ?>"><?php echo h($o['name']); ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-md-3">
            <label class="form-label">Location *</label>
            <select name="LocationID" class="form-select" required>
              <option value="">-- Select --</option>
              <?php foreach ($opts['locs'] as $o): ?>
                <option value="<?php echo (int)$o['id']; ?>"><?php echo h($o['name']); ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-md-3">
            <label class="form-label">Status *</label>
            <select name="Status" class="form-select" required>
              <?php foreach (['Active','Inactive','On Leave','Terminated'] as $s): ?>
                <option><?php echo h($s); ?></option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="col-md-3">
            <label class="form-label">Supervisor (Admin)</label>
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
            <label class="form-label">Hire Date</label>
            <input type="date" name="HireDate" class="form-control">
          </div>
          <div class="col-md-3">
            <label class="form-label">End Date</label>
            <input type="date" name="EndDate" class="form-control">
          </div>
          <div class="col-md-3">
            <label class="form-label">DOB</label>
            <input type="date" name="DOB" class="form-control">
          </div>
          <div class="col-md-3">
            <label class="form-label">Blood Group (2 chars)</label>
            <input type="text" name="Blood_group" class="form-control" maxlength="2" placeholder="O+, AB, ...">
          </div>

          <div class="col-md-3">
            <label class="form-label">Increment Month (CHAR(1))</label>
            <input type="text" name="Salary_increment_month" class="form-control" maxlength="1" placeholder="e.g. 1">
            <small class="text-muted">DB column is CHAR(1). Enter a single character or widen the column if you need 10–12 / names.</small>
          </div>
          <div class="col-md-3">
            <label class="form-label">Job Type</label>
            <input type="text" name="Job_Type" class="form-control" maxlength="50">
          </div>
          <div class="col-md-3">
            <label class="form-label">Salary Level</label>
            <input type="text" name="Salary_level" class="form-control" maxlength="10">
          </div>
          <div class="col-md-3">
            <label class="form-label">Salary Steps</label>
            <input type="text" name="Salary_Steps" class="form-control">
          </div>

          <div class="col-12 d-grid d-md-inline">
            <button class="btn btn-brand w-100 w-md-auto">Create</button>
          </div>
        </form>
      <?php endif; ?>
    </div>
  </div>

  <!-- List -->
  <div class="card card-elevated">
    <div class="card-body">
      <div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-2">
        <h5 class="mb-0">All Employees</h5>
        <span class="text-muted small">Total: <?php echo count($rows); ?></span>
      </div>

      <div class="table-responsive">
        <table class="table table-hover align-middle">
          <thead>
            <tr>
              <th>#</th><th>Code</th><th>Name</th><th>Designation</th>
              <th>Department</th><th>Location</th><th>Status</th><th>Created</th><th class="text-end">Actions</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($rows as $r): ?>
              <tr>
                <td><?php echo (int)$r['EmployeeID']; ?></td>
                <td><?php echo h($r['EmployeeCode']); ?></td>
                <td><?php echo h(trim($r['FirstName'].' '.($r['LastName']??''))); ?></td>
                <td><?php echo h($r['JobTitleName']); ?></td>
                <td><?php echo h($r['DepartmentName']); ?></td>
                <td><?php echo h($r['LocationName']); ?></td>
                <td><?php echo ($r['Status']==='Active'
                      ? '<span class="badge-soft text-success">Active</span>'
                      : '<span class="badge-soft text-secondary">'.h($r['Status']).'</span>'); ?></td>
                <td><?php echo h($r['CreatedAt']); ?></td>
                <td class="text-end">
                  <div class="action-stack">
                    <a class="btn btn-muted btn-sm w-100 w-md-auto" href="<?php echo h($self); ?>?edit=<?php echo (int)$r['EmployeeID']; ?>">Edit</a>
                    <form method="post" class="d-inline" onsubmit="return confirm('Toggle status?');" accept-charset="UTF-8">
                      <input type="hidden" name="csrf" value="<?php echo h($CSRF); ?>">
                      <input type="hidden" name="act" value="toggle">
                      <input type="hidden" name="EmployeeID" value="<?php echo (int)$r['EmployeeID']; ?>">
                      <input type="hidden" name="to" value="<?php echo ($r['Status']==='Active'?'Inactive':'Active'); ?>">
                      <button class="btn btn-muted btn-sm w-100 w-md-auto" type="submit">
                        <?php echo ($r['Status']==='Active'?'Deactivate':'Activate'); ?>
                      </button>
                    </form>
                    <form method="post" class="d-inline" onsubmit="return confirm('Delete this employee permanently?');" accept-charset="UTF-8">
                      <input type="hidden" name="csrf" value="<?php echo h($CSRF); ?>">
                      <input type="hidden" name="act" value="delete">
                      <input type="hidden" name="EmployeeID" value="<?php echo (int)$r['EmployeeID']; ?>">
                      <button class="btn btn-danger-soft btn-sm w-100 w-md-auto" type="submit">Delete</button>
                    </form>
                  </div>
                </td>
              </tr>
            <?php endforeach; ?>
            <?php if (empty($rows)): ?>
              <tr><td colspan="9" class="text-center text-muted py-4">No data</td></tr>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>

<?php require_once __DIR__ . '/../../include/footer.php'; ?>
