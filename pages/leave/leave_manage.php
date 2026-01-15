<?php
/***********************
 * Leave Manage - PHP 5.6 (Admin/Approver)
 * Auto-resolve approver EmployeeID without new table (same logic as apply)
 * Status actions save EMPLOYEE ID to avoid FK errors.
 ***********************/
ini_set('display_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/../../init.php';
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

/* Config */
$AUTO_SET_USERS_EMPLOYEE_ID = true;

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
  if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $s)) return $s;
  if (preg_match('/^(\d{1,2})\/(\d{1,2})\/(\d{4})$/', $s, $m)) {
    $mm=(int)$m[1]; $dd=(int)$m[2]; $yy=(int)$m[3];
    if ($mm>=1 && $mm<=12 && $dd>=1 && $dd<=31) return sprintf('%04d-%02d-%02d', $yy,$mm,$dd);
  }
  return null;
}
function col_exists(PDO $conn, $table, $col){
  $st = $conn->prepare("
    SELECT COUNT(*) 
      FROM INFORMATION_SCHEMA.COLUMNS
     WHERE TABLE_SCHEMA='dbo' AND TABLE_NAME=:t AND COLUMN_NAME=:c
  ");
  $st->execute(array(':t'=>$table, ':c'=>$col));
  return ((int)$st->fetchColumn() > 0);
}
function resolve_employee_id(PDO $conn, $userId, $autoSet){
  if (!$userId) return array(null, "UserID missing.");
  $st = $conn->prepare("SELECT UserID, Username, Email, EmployeeID FROM dbo.Users WHERE UserID=:uid");
  $st->execute(array(':uid'=>(int)$userId));
  $user = $st->fetch(PDO::FETCH_ASSOC);
  if (!$user) return array(null, "User not found.");

  if (!empty($user['EmployeeID']) && (int)$user['EmployeeID']>0) return array((int)$user['EmployeeID'], null);

  $email = isset($user['Email']) ? trim((string)$user['Email']) : '';
  $uname = isset($user['Username']) ? trim((string)$user['Username']) : '';

  $emailCols = array('Email','OfficialEmail','WorkEmail','OfficeEmail');
  $empEmailCol=null;
  foreach($emailCols as $c){ if (col_exists($conn,'Employees',$c)) { $empEmailCol=$c; break; } }
  if ($email!=='' && $empEmailCol) {
    $st = $conn->prepare("SELECT EmployeeID FROM dbo.Employees WHERE LOWER($empEmailCol)=LOWER(:em)");
    $st->execute(array(':em'=>$email));
    $ids = $st->fetchAll(PDO::FETCH_COLUMN);
    if (count($ids)===1) {
      $eid=(int)$ids[0];
      if ($autoSet) {
        $up = $conn->prepare("UPDATE dbo.Users SET EmployeeID=:eid WHERE UserID=:uid AND (EmployeeID IS NULL OR EmployeeID=0)");
        $up->execute(array(':eid'=>$eid, ':uid'=>(int)$userId));
      }
      return array($eid, null);
    }
  }

  $userCols = array('EmployeeCode','EmpCode','EmpID','CardNo','Username','UserName');
  $empUserCol=null;
  foreach($userCols as $c){ if (col_exists($conn,'Employees',$c)) { $empUserCol=$c; break; } }
  if ($uname!=='' && $empUserCol) {
    $st = $conn->prepare("SELECT EmployeeID FROM dbo.Employees WHERE $empUserCol=:u");
    $st->execute(array(':u'=>$uname));
    $ids = $st->fetchAll(PDO::FETCH_COLUMN);
    if (count($ids)===1) {
      $eid=(int)$ids[0];
      if ($autoSet) {
        $up = $conn->prepare("UPDATE dbo.Users SET EmployeeID=:eid WHERE UserID=:uid AND (EmployeeID IS NULL OR EmployeeID=0)");
        $up->execute(array(':eid'=>$eid, ':uid'=>(int)$userId));
      }
      return array($eid, null);
    }
  }

  return array(null, "Approver Employee auto-match failed.");
}

/* Detect employee name expression safely */
function detect_employee_display_mode(PDO $conn){
  $cols = $conn->query("
    SELECT COLUMN_NAME
      FROM INFORMATION_SCHEMA.COLUMNS
     WHERE TABLE_SCHEMA='dbo' AND TABLE_NAME='Employees'
  ")->fetchAll(PDO::FETCH_COLUMN);

  $has=array();
  foreach($cols as $c){ $has[strtolower($c)] = true; }

  if (isset($has['employeename'])) return array('mode'=>'col','col'=>'EmployeeName');
  if (isset($has['fullname']))     return array('mode'=>'col','col'=>'FullName');
  if (isset($has['name']))         return array('mode'=>'col','col'=>'Name');
  if (isset($has['empname']))      return array('mode'=>'col','col'=>'EmpName');
  if (isset($has['firstname']) && isset($has['lastname'])) return array('mode'=>'first_last');
  if (isset($has['firstname'])) return array('mode'=>'col','col'=>'FirstName');
  if (isset($has['lastname']))  return array('mode'=>'col','col'=>'LastName');
  return array('mode'=>'id');
}
function emp_expr($alias, $modeInfo){
  if ($modeInfo['mode']==='col') return $alias.'.'.$modeInfo['col'];
  if ($modeInfo['mode']==='first_last') return "LTRIM(RTRIM(COALESCE($alias.FirstName,'') + ' ' + COALESCE($alias.LastName,'')))";
  return "CAST($alias.EmployeeID AS varchar(20))";
}
$modeInfo = detect_employee_display_mode($conn);

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

/* Resolve approver employee id */
$currentUserId = isset($_SESSION['auth_user']['UserID']) ? (int)$_SESSION['auth_user']['UserID'] : null;
$msg=''; $msg_type='success';
list($approverEmployeeId, $note) = resolve_employee_id($conn, $currentUserId, $AUTO_SET_USERS_EMPLOYEE_ID);
if (!$approverEmployeeId) { $msg_type='danger'; $msg=$note; }

/* dropdowns */
$employees = $conn->query("
  SELECT e.EmployeeID, ".emp_expr('e',$modeInfo)." AS EmpDisplay
    FROM dbo.Employees e
   ORDER BY EmpDisplay
")->fetchAll(PDO::FETCH_ASSOC);

$leaveTypes = $conn->query("
  SELECT LeaveTypeID, LeaveTypeName
    FROM dbo.LeaveTypes
   WHERE (IsActive=1 OR IsActive IS NULL)
   ORDER BY LeaveTypeName
")->fetchAll(PDO::FETCH_ASSOC);

/* Edit load */
$editRow=null;
$edit_id = isset($_GET['edit']) ? (int)$_GET['edit'] : 0;
if ($edit_id>0) {
  $st = $conn->prepare("SELECT * FROM dbo.LeaveApplications WHERE LeaveApplicationID=:id");
  $st->execute(array(':id'=>$edit_id));
  $editRow = $st->fetch(PDO::FETCH_ASSOC);
}

/* CREATE */
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['act']) && $_POST['act']==='create') {
  check_csrf();
  $EmployeeID = isset($_POST['EmployeeID']) ? (int)$_POST['EmployeeID'] : 0;
  $LeaveTypeID = isset($_POST['LeaveTypeID']) ? (int)$_POST['LeaveTypeID'] : 0;
  $StartDate = parse_date_any(isset($_POST['StartDate']) ? $_POST['StartDate'] : '');
  $EndDate   = parse_date_any(isset($_POST['EndDate']) ? $_POST['EndDate'] : '');
  $TotalDays = parse_decimal_1(isset($_POST['TotalDays']) ? $_POST['TotalDays'] : '');
  $Reason    = normalize_text(isset($_POST['Reason']) ? $_POST['Reason'] : '');

  if ($EmployeeID<=0 || $LeaveTypeID<=0 || !$StartDate || !$EndDate) { $msg_type='danger'; $msg="Employee/Type/Start/End required."; }
  elseif (strtotime($EndDate) < strtotime($StartDate)) { $msg_type='danger'; $msg="End date start এর আগে হতে পারবে না।"; }
  elseif ($TotalDays===null) { $msg_type='danger'; $msg="Total days invalid."; }
  else {
    $stmt = $conn->prepare("
      INSERT INTO dbo.LeaveApplications
        (EmployeeID, LeaveTypeID, StartDate, EndDate, TotalDays, Reason, Status, AppliedDate)
      VALUES
        (:emp,:lt,:sd,:ed,:td,:rsn,0,GETDATE())
    ");
    $stmt->execute(array(':emp'=>$EmployeeID, ':lt'=>$LeaveTypeID, ':sd'=>$StartDate, ':ed'=>$EndDate, ':td'=>$TotalDays, ':rsn'=>$Reason));
    $msg_type='success'; $msg="Created.";
  }
}

/* UPDATE */
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['act']) && $_POST['act']==='update') {
  check_csrf();
  $id = isset($_POST['LeaveApplicationID']) ? (int)$_POST['LeaveApplicationID'] : 0;
  $EmployeeID = isset($_POST['EmployeeID']) ? (int)$_POST['EmployeeID'] : 0;
  $LeaveTypeID = isset($_POST['LeaveTypeID']) ? (int)$_POST['LeaveTypeID'] : 0;
  $StartDate = parse_date_any(isset($_POST['StartDate']) ? $_POST['StartDate'] : '');
  $EndDate   = parse_date_any(isset($_POST['EndDate']) ? $_POST['EndDate'] : '');
  $TotalDays = parse_decimal_1(isset($_POST['TotalDays']) ? $_POST['TotalDays'] : '');
  $Reason    = normalize_text(isset($_POST['Reason']) ? $_POST['Reason'] : '');
  $RejectionReason = normalize_text(isset($_POST['RejectionReason']) ? $_POST['RejectionReason'] : '');

  if ($id<=0 || $EmployeeID<=0 || $LeaveTypeID<=0 || !$StartDate || !$EndDate || $TotalDays===null) { $msg_type='danger'; $msg="Invalid data."; }
  else {
    $stmt = $conn->prepare("
      UPDATE dbo.LeaveApplications
         SET EmployeeID=:emp, LeaveTypeID=:lt, StartDate=:sd, EndDate=:ed, TotalDays=:td,
             Reason=:rsn, RejectionReason=:rej
       WHERE LeaveApplicationID=:id
    ");
    $stmt->execute(array(':emp'=>$EmployeeID, ':lt'=>$LeaveTypeID, ':sd'=>$StartDate, ':ed'=>$EndDate, ':td'=>$TotalDays, ':rsn'=>$Reason, ':rej'=>$RejectionReason, ':id'=>$id));
    header('Location: '.$self); exit;
  }
}

/* STATUS (FIX: save employee id) */
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['act']) && $_POST['act']==='set_status') {
  check_csrf();
  $id = isset($_POST['LeaveApplicationID']) ? (int)$_POST['LeaveApplicationID'] : 0;
  $to = isset($_POST['to']) ? (int)$_POST['to'] : -1;
  $rej = normalize_text(isset($_POST['RejectionReason']) ? $_POST['RejectionReason'] : '');

  list($approverEmployeeId, $note) = resolve_employee_id($conn, $currentUserId, $AUTO_SET_USERS_EMPLOYEE_ID);

  if (($to===1 || $to===2 || $to===3) && !$approverEmployeeId) {
    $msg_type='danger'; $msg=$note;
  } else {
    if ($to===1) {
      $st=$conn->prepare("UPDATE dbo.LeaveApplications SET Status=1, L1ApprovedBy=:by, L1ApprovedDate=GETDATE(),
                          RejectedBy=NULL, RejectedDate=NULL, RejectionReason=NULL, CancelledDate=NULL
                          WHERE LeaveApplicationID=:id");
      $st->execute(array(':by'=>$approverEmployeeId, ':id'=>$id));
      $msg_type='success'; $msg="L1 Approved.";
    } elseif ($to===2) {
      $st=$conn->prepare("UPDATE dbo.LeaveApplications SET Status=2, L2ApprovedBy=:by, L2ApprovedDate=GETDATE(),
                          RejectedBy=NULL, RejectedDate=NULL, RejectionReason=NULL, CancelledDate=NULL
                          WHERE LeaveApplicationID=:id");
      $st->execute(array(':by'=>$approverEmployeeId, ':id'=>$id));
      $msg_type='success'; $msg="L2 Approved.";
    } elseif ($to===3) {
      if ($rej==='') { $msg_type='danger'; $msg="Rejection reason required."; }
      else {
        $st=$conn->prepare("UPDATE dbo.LeaveApplications SET Status=3, RejectedBy=:by, RejectedDate=GETDATE(),
                            RejectionReason=:rej, CancelledDate=NULL WHERE LeaveApplicationID=:id");
        $st->execute(array(':by'=>$approverEmployeeId, ':rej'=>$rej, ':id'=>$id));
        $msg_type='success'; $msg="Rejected.";
      }
    } elseif ($to===4) {
      $st=$conn->prepare("UPDATE dbo.LeaveApplications SET Status=4, CancelledDate=GETDATE() WHERE LeaveApplicationID=:id");
      $st->execute(array(':id'=>$id));
      $msg_type='success'; $msg="Cancelled.";
    }
  }
}

/* DELETE */
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['act']) && $_POST['act']==='delete') {
  check_csrf();
  $id = isset($_POST['LeaveApplicationID']) ? (int)$_POST['LeaveApplicationID'] : 0;
  if ($id>0) {
    $st=$conn->prepare("DELETE FROM dbo.LeaveApplications WHERE LeaveApplicationID=:id");
    $st->execute(array(':id'=>$id));
    $msg_type='success'; $msg="Deleted.";
  }
}

/* List */
$rows = $conn->query("
  SELECT la.*,
         ".emp_expr('e',$modeInfo)."  AS EmployeeName,
         lt.LeaveTypeName,
         ".emp_expr('a1',$modeInfo)." AS L1ByName,
         ".emp_expr('a2',$modeInfo)." AS L2ByName,
         ".emp_expr('rj',$modeInfo)." AS RejByName
    FROM dbo.LeaveApplications la
    LEFT JOIN dbo.Employees e  ON e.EmployeeID=la.EmployeeID
    LEFT JOIN dbo.LeaveTypes lt ON lt.LeaveTypeID=la.LeaveTypeID
    LEFT JOIN dbo.Employees a1 ON a1.EmployeeID=la.L1ApprovedBy
    LEFT JOIN dbo.Employees a2 ON a2.EmployeeID=la.L2ApprovedBy
    LEFT JOIN dbo.Employees rj ON rj.EmployeeID=la.RejectedBy
   ORDER BY la.LeaveApplicationID DESC
")->fetchAll(PDO::FETCH_ASSOC);

require_once __DIR__ . '/../../include/header.php';
?>

<div class="page-wrap">
  <div class="d-flex flex-wrap align-items-center justify-content-between mb-3 gap-2">
    <div>
      <h1 class="page-title mb-1"><i class="fas fa-tasks"></i> Leave Manage</h1>
      <div class="page-subtitle">সব leave application manage করুন।</div>
    </div>
    <span class="badge-soft"><i class="fas fa-layer-group"></i> Total: <?php echo count($rows); ?></span>
  </div>

  <?php if ($msg): ?>
    <div class="alert alert-<?php echo ($msg_type==='danger'?'danger':'success'); ?> alert-dismissible fade show shadow-sm" role="alert">
      <?php echo h($msg); ?>
      <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
  <?php endif; ?>

  <!-- Add/Edit (আপনার existing UI/Design থাকলে এখানে swap করতে পারেন) -->
  <!-- ... (আপনার আগের layout চাইলে 그대로 রেখে শুধু backend অংশগুলোই replace করলেই হবে) ... -->

  <div class="card card-elevated">
    <div class="card-body">
      <div class="table-responsive">
        <table class="table table-hover align-middle">
          <thead>
            <tr>
              <th>#</th><th>Employee</th><th>Type</th><th>Dates</th><th>Days</th><th>Status</th><th>Applied</th><th>By</th><th class="text-end">Actions</th>
            </tr>
          </thead>
          <tbody>
          <?php foreach($rows as $r): ?>
            <?php
              $who='';
              if (!empty($r['L2ByName'])) $who='L2: '.$r['L2ByName'];
              elseif (!empty($r['L1ByName'])) $who='L1: '.$r['L1ByName'];
              elseif (!empty($r['RejByName'])) $who='Rej: '.$r['RejByName'];
            ?>
            <tr>
              <td><?php echo (int)$r['LeaveApplicationID']; ?></td>
              <td><?php echo h($r['EmployeeName']); ?></td>
              <td><?php echo h($r['LeaveTypeName']); ?></td>
              <td><?php echo h(substr((string)$r['StartDate'],0,10)); ?> → <?php echo h(substr((string)$r['EndDate'],0,10)); ?></td>
              <td><?php echo h($r['TotalDays']); ?></td>
              <td><span class="status-pill <?php echo status_class($STATUS,(int)$r['Status']); ?>"><span class="status-dot"></span><?php echo h(status_text($STATUS,(int)$r['Status'])); ?></span></td>
              <td><?php echo h($r['AppliedDate']); ?></td>
              <td><?php echo h($who); ?></td>
              <td class="text-end">
                <div class="action-stack">
                  <form method="post" class="d-inline" accept-charset="UTF-8">
                    <input type="hidden" name="csrf" value="<?php echo h($CSRF); ?>">
                    <input type="hidden" name="act" value="set_status">
                    <input type="hidden" name="LeaveApplicationID" value="<?php echo (int)$r['LeaveApplicationID']; ?>">
                    <input type="hidden" name="to" value="1">
                    <button class="btn btn-muted btn-sm" <?php echo $approverEmployeeId?'':'disabled'; ?>>L1</button>
                  </form>

                  <form method="post" class="d-inline" accept-charset="UTF-8">
                    <input type="hidden" name="csrf" value="<?php echo h($CSRF); ?>">
                    <input type="hidden" name="act" value="set_status">
                    <input type="hidden" name="LeaveApplicationID" value="<?php echo (int)$r['LeaveApplicationID']; ?>">
                    <input type="hidden" name="to" value="2">
                    <button class="btn btn-muted btn-sm" <?php echo $approverEmployeeId?'':'disabled'; ?>>L2</button>
                  </form>

                  <form method="post" class="d-inline laRejectForm" accept-charset="UTF-8">
                    <input type="hidden" name="csrf" value="<?php echo h($CSRF); ?>">
                    <input type="hidden" name="act" value="set_status">
                    <input type="hidden" name="LeaveApplicationID" value="<?php echo (int)$r['LeaveApplicationID']; ?>">
                    <input type="hidden" name="to" value="3">
                    <input type="hidden" name="RejectionReason" value="">
                    <button class="btn btn-danger-soft btn-sm" <?php echo $approverEmployeeId?'':'disabled'; ?>>Reject</button>
                  </form>

                  <form method="post" class="d-inline" accept-charset="UTF-8">
                    <input type="hidden" name="csrf" value="<?php echo h($CSRF); ?>">
                    <input type="hidden" name="act" value="set_status">
                    <input type="hidden" name="LeaveApplicationID" value="<?php echo (int)$r['LeaveApplicationID']; ?>">
                    <input type="hidden" name="to" value="4">
                    <button class="btn btn-muted btn-sm">Cancel</button>
                  </form>

                  <form method="post" class="d-inline" accept-charset="UTF-8" onsubmit="return confirm('Delete?');">
                    <input type="hidden" name="csrf" value="<?php echo h($CSRF); ?>">
                    <input type="hidden" name="act" value="delete">
                    <input type="hidden" name="LeaveApplicationID" value="<?php echo (int)$r['LeaveApplicationID']; ?>">
                    <button class="btn btn-danger-soft btn-sm">Delete</button>
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

<script>
(function(){
  var rejects = document.querySelectorAll('.laRejectForm');
  Array.prototype.forEach.call(rejects, function(f){
    f.addEventListener('submit', function(ev){
      var btn = f.querySelector('button');
      if (btn && btn.disabled) { ev.preventDefault(); return false; }
      var reason = prompt('Rejection reason লিখুন:');
      if (!reason) { ev.preventDefault(); return false; }
      var input = f.querySelector('input[name="RejectionReason"]');
      if (input) input.value = reason;
      return true;
    });
  });
})();
</script>

<?php require_once __DIR__ . '/../../include/footer.php'; ?>
