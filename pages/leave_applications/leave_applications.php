<?php
/***********************
 * Leave Applications - Full CRUD (same design + auto search/filter)
 * + Quick status actions (L1/L2/Reject/Cancel)
 * PHP 5.6 compatible
 ***********************/
ini_set('display_errors', 1);
error_reporting(E_ALL);

/* 1) Boot (no output yet) */
require_once __DIR__ . '/../../init.php';
require_login();

/* 2) Helpers */
if (!function_exists('h')) {
  function h($s){ return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }
}
function self_name(){ return strtok(basename($_SERVER['SCRIPT_NAME']), "?"); }
$self = self_name();

/* 3) CSRF */
if (!isset($_SESSION['csrf'])) {
  $_SESSION['csrf'] = function_exists('openssl_random_pseudo_bytes')
    ? bin2hex(openssl_random_pseudo_bytes(16))
    : substr(str_shuffle(md5(uniqid(mt_rand(), true))), 0, 32);
}
$CSRF = $_SESSION['csrf'];

function check_csrf(){
  if (!isset($_POST['csrf']) || $_POST['csrf'] !== $_SESSION['csrf']) {
    die('Invalid CSRF token');
  }
}

function normalize_text($s){
  $s = trim((string)$s);
  $s = trim(preg_replace('/\s+/', ' ', $s));
  return $s;
}

/* decimal(4,1) -> allow 1 decimal (e.g. 1, 1.5, 10.0) */
function parse_decimal_1($s){
  $s = trim((string)$s);
  $s = str_replace(',', '', $s);
  if ($s === '') return null;
  if (!preg_match('/^\d+(\.\d)?$/', $s)) return null;
  return $s;
}

function parse_date($s){
  $s = trim((string)$s);
  if ($s === '') return null;
  // expect YYYY-MM-DD (HTML date input)
  if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $s)) return null;
  return $s;
}

/* Status map */
$STATUS = array(
  0 => array('text'=>'Pending',     'class'=>'status-pill-warning'),
  1 => array('text'=>'L1 Approved', 'class'=>'status-pill-active'),
  2 => array('text'=>'L2 Approved', 'class'=>'status-pill-active'),
  3 => array('text'=>'Rejected',    'class'=>'status-pill-inactive'),
  4 => array('text'=>'Cancelled',   'class'=>'status-pill-muted')
);

function status_text($STATUS, $s){
  return isset($STATUS[$s]) ? $STATUS[$s]['text'] : ('Status '.$s);
}
function status_class($STATUS, $s){
  return isset($STATUS[$s]) ? $STATUS[$s]['class'] : 'status-pill-muted';
}

/* detect employee display column safely */
function employee_display_expr(PDO $conn){
  try {
    $cols = $conn->query("
      SELECT COLUMN_NAME
        FROM INFORMATION_SCHEMA.COLUMNS
       WHERE TABLE_SCHEMA='dbo' AND TABLE_NAME='Employees'
    ")->fetchAll(PDO::FETCH_COLUMN);

    $has = array();
    foreach($cols as $c){ $has[strtolower($c)] = true; }

    // preference order
    if (isset($has['employeename'])) return "e.EmployeeName";
    if (isset($has['fullname']))     return "e.FullName";
    if (isset($has['name']))         return "e.Name";
    if (isset($has['empname']))      return "e.EmpName";

    if (isset($has['firstname']) && isset($has['lastname'])) {
      return "LTRIM(RTRIM(COALESCE(e.FirstName,'') + ' ' + COALESCE(e.LastName,'')))";
    }
    if (isset($has['firstname'])) return "e.FirstName";
    if (isset($has['lastname']))  return "e.LastName";
  } catch(Exception $e){
    // ignore
  }
  return "CAST(e.EmployeeID AS varchar(20))";
}

/* 4) Messages */
$msg = '';
$msg_type = 'success';

if (isset($_GET['ok']) && $_GET['ok'] === '1') {
  $msg = 'Leave application created.';
  $msg_type = 'success';
}

/* Current user */
$currentUserId = isset($_SESSION['auth_user']['UserID']) ? (int)$_SESSION['auth_user']['UserID'] : null;

/* 5) Load dropdown data */
$empExpr = employee_display_expr($conn);

try {
  $employees = $conn->query("
    SELECT e.EmployeeID, {$empExpr} AS EmpDisplay
      FROM dbo.Employees e
     ORDER BY EmpDisplay
  ")->fetchAll();
} catch (PDOException $e) {
  $employees = array();
  $msg_type = 'danger';
  $msg = "Load employees failed: ".h($e->getMessage());
}

try {
  $leaveTypes = $conn->query("
    SELECT LeaveTypeID, LeaveTypeName
      FROM dbo.LeaveTypes
     WHERE (IsActive = 1 OR IsActive IS NULL)
     ORDER BY LeaveTypeName
  ")->fetchAll();
} catch (PDOException $e) {
  $leaveTypes = array();
  $msg_type = 'danger';
  $msg = "Load leave types failed: ".h($e->getMessage());
}

/* 6) Edit row load */
$editRow = null;
$edit_id = isset($_GET['edit']) ? (int)$_GET['edit'] : 0;

if ($edit_id > 0) {
  try {
    $st = $conn->prepare("
      SELECT *
        FROM dbo.LeaveApplications
       WHERE LeaveApplicationID = :id
    ");
    $st->execute(array(':id'=>$edit_id));
    $editRow = $st->fetch();
    if (!$editRow) {
      $msg_type = 'danger';
      $msg = "Row not found for edit.";
      $edit_id = 0;
    }
  } catch (PDOException $e) {
    $msg_type = 'danger';
    $msg = "Load edit row failed: ".h($e->getMessage());
  }
}

/* 7) CREATE */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['act']) && $_POST['act'] === 'create') {
  check_csrf();

  $EmployeeID = isset($_POST['EmployeeID']) ? (int)$_POST['EmployeeID'] : 0;
  $LeaveTypeID = isset($_POST['LeaveTypeID']) ? (int)$_POST['LeaveTypeID'] : 0;
  $StartDate = parse_date(isset($_POST['StartDate']) ? $_POST['StartDate'] : '');
  $EndDate   = parse_date(isset($_POST['EndDate']) ? $_POST['EndDate'] : '');
  $TotalDays = parse_decimal_1(isset($_POST['TotalDays']) ? $_POST['TotalDays'] : '');
  $Reason    = normalize_text(isset($_POST['Reason']) ? $_POST['Reason'] : '');

  if ($EmployeeID <= 0 || $LeaveTypeID <= 0 || !$StartDate || !$EndDate) {
    $msg_type = 'danger';
    $msg = "Employee, Leave type, Start date, End date are required.";
  } elseif (strtotime($EndDate) < strtotime($StartDate)) {
    $msg_type = 'danger';
    $msg = "End date cannot be earlier than start date.";
  } elseif ($TotalDays === null) {
    $msg_type = 'danger';
    $msg = "Total days must be valid (e.g. 1, 1.5, 10.0).";
  } else {
    try {
      $stmt = $conn->prepare("
        INSERT INTO dbo.LeaveApplications
          (EmployeeID, LeaveTypeID, StartDate, EndDate, TotalDays, Reason, Status, AppliedDate)
        VALUES
          (:emp, :lt, :sd, :ed, :td, :rsn, :st, GETDATE())
      ");
      $stmt->execute(array(
        ':emp'=>$EmployeeID,
        ':lt'=>$LeaveTypeID,
        ':sd'=>$StartDate,
        ':ed'=>$EndDate,
        ':td'=>$TotalDays,
        ':rsn'=>$Reason,
        ':st'=>0 // Pending
      ));
      header('Location: ' . $self . '?ok=1'); exit;
    } catch (PDOException $e) {
      $msg_type = 'danger';
      $msg = "Create failed: ".h($e->getMessage());
    }
  }
}

/* 8) UPDATE (base fields + optionally rejection reason) */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['act']) && $_POST['act'] === 'update') {
  check_csrf();

  $id = isset($_POST['LeaveApplicationID']) ? (int)$_POST['LeaveApplicationID'] : 0;
  $EmployeeID = isset($_POST['EmployeeID']) ? (int)$_POST['EmployeeID'] : 0;
  $LeaveTypeID = isset($_POST['LeaveTypeID']) ? (int)$_POST['LeaveTypeID'] : 0;
  $StartDate = parse_date(isset($_POST['StartDate']) ? $_POST['StartDate'] : '');
  $EndDate   = parse_date(isset($_POST['EndDate']) ? $_POST['EndDate'] : '');
  $TotalDays = parse_decimal_1(isset($_POST['TotalDays']) ? $_POST['TotalDays'] : '');
  $Reason    = normalize_text(isset($_POST['Reason']) ? $_POST['Reason'] : '');
  $RejectionReason = normalize_text(isset($_POST['RejectionReason']) ? $_POST['RejectionReason'] : '');

  if ($id <= 0 || $EmployeeID<=0 || $LeaveTypeID<=0 || !$StartDate || !$EndDate || $TotalDays===null) {
    $msg_type = 'danger';
    $msg = "Invalid data.";
  } elseif (strtotime($EndDate) < strtotime($StartDate)) {
    $msg_type = 'danger';
    $msg = "End date cannot be earlier than start date.";
  } else {
    try {
      $stmt = $conn->prepare("
        UPDATE dbo.LeaveApplications
           SET EmployeeID = :emp,
               LeaveTypeID = :lt,
               StartDate = :sd,
               EndDate = :ed,
               TotalDays = :td,
               Reason = :rsn,
               RejectionReason = :rej
         WHERE LeaveApplicationID = :id
      ");
      $stmt->execute(array(
        ':emp'=>$EmployeeID,
        ':lt'=>$LeaveTypeID,
        ':sd'=>$StartDate,
        ':ed'=>$EndDate,
        ':td'=>$TotalDays,
        ':rsn'=>$Reason,
        ':rej'=>$RejectionReason,
        ':id'=>$id
      ));
      header('Location: ' . $self); exit;
    } catch (PDOException $e) {
      $msg_type = 'danger';
      $msg = "Update failed: ".h($e->getMessage());
    }
  }
}

/* 9) QUICK STATUS UPDATE */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['act']) && $_POST['act'] === 'set_status') {
  check_csrf();

  $id = isset($_POST['LeaveApplicationID']) ? (int)$_POST['LeaveApplicationID'] : 0;
  $to = isset($_POST['to']) ? (int)$_POST['to'] : -1;
  $rej = normalize_text(isset($_POST['RejectionReason']) ? $_POST['RejectionReason'] : '');

  if ($id <= 0 || $to < 0) {
    $msg_type = 'danger';
    $msg = "Invalid action.";
  } else {
    try {
      if ($to === 1) {
        $stmt = $conn->prepare("
          UPDATE dbo.LeaveApplications
             SET Status=1,
                 L1ApprovedBy=:by,
                 L1ApprovedDate=GETDATE(),
                 RejectedBy=NULL, RejectedDate=NULL, RejectionReason=NULL,
                 CancelledDate=NULL
           WHERE LeaveApplicationID=:id
        ");
        $stmt->bindValue(':id',$id,PDO::PARAM_INT);
        if ($currentUserId===null) $stmt->bindValue(':by', null, PDO::PARAM_NULL);
        else $stmt->bindValue(':by', $currentUserId, PDO::PARAM_INT);
        $stmt->execute();
        $msg_type='success'; $msg='L1 Approved.';
      } elseif ($to === 2) {
        $stmt = $conn->prepare("
          UPDATE dbo.LeaveApplications
             SET Status=2,
                 L2ApprovedBy=:by,
                 L2ApprovedDate=GETDATE(),
                 RejectedBy=NULL, RejectedDate=NULL, RejectionReason=NULL,
                 CancelledDate=NULL
           WHERE LeaveApplicationID=:id
        ");
        $stmt->bindValue(':id',$id,PDO::PARAM_INT);
        if ($currentUserId===null) $stmt->bindValue(':by', null, PDO::PARAM_NULL);
        else $stmt->bindValue(':by', $currentUserId, PDO::PARAM_INT);
        $stmt->execute();
        $msg_type='success'; $msg='L2 Approved.';
      } elseif ($to === 3) {
        if ($rej === '') {
          $msg_type='danger'; $msg='Rejection reason required.';
        } else {
          $stmt = $conn->prepare("
            UPDATE dbo.LeaveApplications
               SET Status=3,
                   RejectedBy=:by,
                   RejectedDate=GETDATE(),
                   RejectionReason=:rej,
                   CancelledDate=NULL
             WHERE LeaveApplicationID=:id
          ");
          $stmt->bindValue(':id',$id,PDO::PARAM_INT);
          $stmt->bindValue(':rej',$rej,PDO::PARAM_STR);
          if ($currentUserId===null) $stmt->bindValue(':by', null, PDO::PARAM_NULL);
          else $stmt->bindValue(':by', $currentUserId, PDO::PARAM_INT);
          $stmt->execute();
          $msg_type='success'; $msg='Rejected.';
        }
      } elseif ($to === 4) {
        $stmt = $conn->prepare("
          UPDATE dbo.LeaveApplications
             SET Status=4,
                 CancelledDate=GETDATE()
           WHERE LeaveApplicationID=:id
        ");
        $stmt->execute(array(':id'=>$id));
        $msg_type='success'; $msg='Cancelled.';
      } elseif ($to === 0) {
        $stmt = $conn->prepare("
          UPDATE dbo.LeaveApplications
             SET Status=0,
                 L1ApprovedBy=NULL, L1ApprovedDate=NULL,
                 L2ApprovedBy=NULL, L2ApprovedDate=NULL,
                 RejectedBy=NULL, RejectedDate=NULL, RejectionReason=NULL,
                 CancelledDate=NULL
           WHERE LeaveApplicationID=:id
        ");
        $stmt->execute(array(':id'=>$id));
        $msg_type='success'; $msg='Reset to Pending.';
      } else {
        $msg_type='danger'; $msg='Unknown status.';
      }
    } catch (PDOException $e) {
      $msg_type='danger';
      $msg="Status update failed: ".h($e->getMessage());
    }
  }
}

/* 10) DELETE */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['act']) && $_POST['act'] === 'delete') {
  check_csrf();
  $id = isset($_POST['LeaveApplicationID']) ? (int)$_POST['LeaveApplicationID'] : 0;
  if ($id > 0) {
    try {
      $stmt = $conn->prepare("DELETE FROM dbo.LeaveApplications WHERE LeaveApplicationID = :id");
      $stmt->execute(array(':id'=>$id));
      $msg_type = 'success';
      $msg = "Leave application deleted.";
    } catch (PDOException $e) {
      $msg_type = 'danger';
      $msg = "Delete failed: ".h($e->getMessage());
    }
  }
}

/* 11) List */
try {
  $rows = $conn->query("
    SELECT la.LeaveApplicationID, la.EmployeeID, la.LeaveTypeID,
           la.StartDate, la.EndDate, la.TotalDays, la.Reason,
           la.Status, la.AppliedDate,
           la.L1ApprovedBy, la.L1ApprovedDate,
           la.L2ApprovedBy, la.L2ApprovedDate,
           la.RejectedBy, la.RejectedDate, la.RejectionReason,
           la.CancelledDate,
           {$empExpr} AS EmployeeName,
           lt.LeaveTypeName
      FROM dbo.LeaveApplications la
      LEFT JOIN dbo.Employees e ON e.EmployeeID = la.EmployeeID
      LEFT JOIN dbo.LeaveTypes lt ON lt.LeaveTypeID = la.LeaveTypeID
     ORDER BY la.LeaveApplicationID DESC
  ")->fetchAll();
} catch (PDOException $e) {
  $rows = array();
  $msg_type = 'danger';
  $msg = "Load list failed: ".h($e->getMessage());
}

/* 12) Render */
require_once __DIR__ . '/../../include/header.php';
?>

<style>
  .page-wrap { max-width: 100%; margin: 28px auto; padding: 0 16px 32px; }
  .page-title { font-weight:700; letter-spacing:.2px; color:#0f172a; display:flex; align-items:center; gap:8px; }
  .page-title i{ font-size:22px; color:#4f46e5; }
  .page-subtitle{ font-size:13px; color:#6b7280; }

  .card-elevated { border-radius:16px; border:1px solid #e5e7eb; box-shadow:0 18px 45px rgba(15, 23, 42, 0.12); overflow:hidden; }
  .card-elevated .card-body{ background: radial-gradient(circle at top left, #eff6ff 0, #ffffff 45%, #f9fafb 100%); }

  .badge-soft { border-radius:999px; padding:5px 12px; font-size:12px; font-weight:500; color:#0f172a; background:#e0f2fe; border:1px solid #bae6fd; display:inline-flex; align-items:center; gap:6px; }
  .badge-soft i{ font-size:.85rem; color:#0284c7; }

  .btn-brand { background:linear-gradient(135deg, #6366f1, #2563eb); color:#fff!important; border:none; padding:.55rem 1.4rem; font-weight:600; border-radius:999px; display:inline-flex; align-items:center; gap:8px; box-shadow:0 12px 25px rgba(37, 99, 235, 0.35); transition:all .15s ease-in-out; }
  .btn-brand i{ font-size:.95rem; }
  .btn-brand:hover { background:linear-gradient(135deg, #4f46e5, #1d4ed8); transform:translateY(-1px); box-shadow:0 16px 32px rgba(30, 64, 175, 0.45); }

  .btn-muted { background:#e5e7eb; color:#111827!important; border:none; border-radius:999px; padding:.45rem 1.1rem; font-weight:500; display:inline-flex; align-items:center; gap:6px; }
  .btn-muted i{ font-size:.9rem; }
  .btn-muted:hover{ background:#d1d5db; }

  .btn-danger-soft{ background:#fee2e2; color:#b91c1c!important; border:1px solid #fecaca; border-radius:999px; padding:.45rem 1.1rem; font-weight:500; display:inline-flex; align-items:center; gap:6px; }
  .btn-danger-soft i{ font-size:.9rem; }
  .btn-danger-soft:hover{ background:#fecaca; }

  .form-label{ font-weight:600; color:#374151; font-size:13px; }
  .form-control, .form-select{ border-radius:10px; border-color:#cbd5e1; font-size:14px; }

  .section-title{ font-weight:600; color:#111827; display:flex; align-items:center; gap:8px; }
  .section-title i{ color:#4f46e5; font-size:1rem; }

  .table thead th{ background:#f9fafb; color:#4b5563; border-bottom:1px solid #e5e7eb; font-size:12px; text-transform:uppercase; letter-spacing:.04em; white-space:nowrap; }
  .table tbody td{ vertical-align:middle; font-size:13px; color:#111827; }
  .table-hover tbody tr:hover{ background-color:#eff6ff; }

  .status-pill{ display:inline-flex; align-items:center; gap:6px; padding:3px 10px; border-radius:999px; font-size:11px; font-weight:500; }
  .status-pill .status-dot{ width:8px; height:8px; border-radius:999px; }

  .status-pill-active{ background:#ecfdf3; color:#166534; }
  .status-pill-active .status-dot{ background:#22c55e; }

  .status-pill-inactive{ background:#fef2f2; color:#b91c1c; }
  .status-pill-inactive .status-dot{ background:#ef4444; }

  .status-pill-warning{ background:#fffbeb; color:#92400e; }
  .status-pill-warning .status-dot{ background:#f59e0b; }

  .status-pill-muted{ background:#f3f4f6; color:#374151; }
  .status-pill-muted .status-dot{ background:#9ca3af; }

  .action-stack > *{ margin:4px; }
  @media (min-width:768px){ .action-stack{ display:inline-flex; gap:6px; flex-wrap:wrap; } }

  .filters-helper{ font-size:12px; color:#6b7280; }
</style>

<div class="page-wrap">

  <div class="d-flex flex-wrap align-items-center justify-content-between mb-3 gap-2">
    <div>
      <h1 class="page-title mb-1">
        <i class="fas fa-file-signature"></i>
        Leave Applications
      </h1>
       
    </div>
    <span class="badge-soft">
      <i class="fas fa-layer-group"></i>
      Total Applications: <?php echo count($rows); ?>
    </span>
  </div>

  <?php if ($msg): ?>
    <div class="alert alert-<?php echo ($msg_type==='danger'?'danger':'success'); ?> alert-dismissible fade show shadow-sm" role="alert">
      <?php echo $msg; ?>
      <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
  <?php endif; ?>

  <!-- Add / Edit Card -->
  <div class="card card-elevated mb-4">
    <div class="card-body">

      <?php if (!empty($editRow)): ?>
        <div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-3">
          <div class="section-title mb-0">
            <i class="fas fa-edit"></i>
            <span>Edit Leave Application</span>
          </div>
          <a class="btn btn-muted btn-sm" href="<?php echo h($self); ?>">
            <i class="fas fa-times-circle"></i>
            Cancel
          </a>
        </div>

        <form method="post" accept-charset="UTF-8" id="editForm">
          <input type="hidden" name="csrf" value="<?php echo h($CSRF); ?>">
          <input type="hidden" name="act" value="update">
          <input type="hidden" name="LeaveApplicationID" value="<?php echo (int)$editRow['LeaveApplicationID']; ?>">

          <div class="row g-3">
            <div class="col-12 col-md-4">
              <label class="form-label">Employee</label>
              <select name="EmployeeID" class="form-select" required>
                <option value="">-- Select --</option>
                <?php foreach($employees as $e): ?>
                  <option value="<?php echo (int)$e['EmployeeID']; ?>"
                    <?php echo ((int)$editRow['EmployeeID']===(int)$e['EmployeeID']?'selected':''); ?>>
                    <?php echo h($e['EmpDisplay']); ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>

            <div class="col-12 col-md-4">
              <label class="form-label">Leave Type</label>
              <select name="LeaveTypeID" class="form-select" required>
                <option value="">-- Select --</option>
                <?php foreach($leaveTypes as $lt): ?>
                  <option value="<?php echo (int)$lt['LeaveTypeID']; ?>"
                    <?php echo ((int)$editRow['LeaveTypeID']===(int)$lt['LeaveTypeID']?'selected':''); ?>>
                    <?php echo h($lt['LeaveTypeName']); ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>

            <div class="col-6 col-md-2">
              <label class="form-label">Start</label>
              <input type="date" name="StartDate" class="form-control" required
                     value="<?php echo h(substr((string)$editRow['StartDate'],0,10)); ?>" id="editStart">
            </div>

            <div class="col-6 col-md-2">
              <label class="form-label">End</label>
              <input type="date" name="EndDate" class="form-control" required
                     value="<?php echo h(substr((string)$editRow['EndDate'],0,10)); ?>" id="editEnd">
            </div>

            <div class="col-12 col-md-2">
              <label class="form-label">Total Days</label>
              <input type="number" step="0.1" min="0" name="TotalDays" class="form-control" required
                     value="<?php echo h($editRow['TotalDays']); ?>" id="editTotalDays">
            </div>

            <div class="col-12 col-md-10">
              <label class="form-label">Reason</label>
              <textarea name="Reason" class="form-control" rows="2" placeholder="Reason..."><?php echo h($editRow['Reason']); ?></textarea>
            </div>

            <div class="col-12 col-md-12">
              <label class="form-label">Rejection Reason (optional)</label>
              <input type="text" name="RejectionReason" class="form-control"
                     value="<?php echo h(isset($editRow['RejectionReason']) ? $editRow['RejectionReason'] : ''); ?>">
            </div>

            <div class="col-12 d-grid d-md-inline">
              <button class="btn btn-brand w-100 w-md-auto" style="display:inline;">
                <i class="fas fa-save"></i>
                Update
              </button>
            </div>
          </div>
        </form>

      <?php else: ?>
        <div class="section-title mb-3">
          <i class="fas fa-plus-circle"></i>
          <span>Add Leave Application</span>
        </div>

        <form method="post" class="row g-3" accept-charset="UTF-8" id="createForm">
          <input type="hidden" name="csrf" value="<?php echo h($CSRF); ?>">
          <input type="hidden" name="act" value="create">

          <div class="col-12 col-md-4">
            <label class="form-label">Employee</label>
            <select name="EmployeeID" class="form-select" required>
              <option value="">-- Select --</option>
              <?php foreach($employees as $e): ?>
                <option value="<?php echo (int)$e['EmployeeID']; ?>">
                  <?php echo h($e['EmpDisplay']); ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="col-12 col-md-4">
            <label class="form-label">Leave Type</label>
            <select name="LeaveTypeID" class="form-select" required>
              <option value="">-- Select --</option>
              <?php foreach($leaveTypes as $lt): ?>
                <option value="<?php echo (int)$lt['LeaveTypeID']; ?>">
                  <?php echo h($lt['LeaveTypeName']); ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="col-6 col-md-2">
            <label class="form-label">Start</label>
            <input type="date" name="StartDate" class="form-control" required id="createStart">
          </div>

          <div class="col-6 col-md-2">
            <label class="form-label">End</label>
            <input type="date" name="EndDate" class="form-control" required id="createEnd">
          </div>

          <div class="col-12 col-md-2">
            <label class="form-label">Total Days</label>
            <input type="number" step="0.1" min="0" name="TotalDays" class="form-control" required
                   placeholder="e.g. 2.0" id="createTotalDays">
          </div>

          <div class="col-12 col-md-10">
            <label class="form-label">Reason</label>
            <textarea name="Reason" class="form-control" rows="2" placeholder="Reason..."></textarea>
          </div>

          <div class="col-12 d-grid d-md-inline">
            <button class="btn btn-brand w-100 w-md-auto" style="display:inline;">
              <i class="fas fa-save"></i>
              Create
            </button>
          </div>
        </form>
      <?php endif; ?>

    </div>
  </div>

  <!-- List + Filters -->
  <div class="card card-elevated">
    <div class="card-body">
      <div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-2">
        <div class="section-title mb-0">
          <i class="fas fa-list-ul"></i>
          <span>All Leave Applications</span>
        </div>
         
      </div>

      <div class="row g-2 mb-3">
        <div class="col-12 col-md-6">
          <label class="form-label small mb-1">Search (Employee / Type / Reason)</label>
          <input type="text" id="laSearch" class="form-control" placeholder="Type to search...">
        </div>
        <div class="col-6 col-md-3">
          <label class="form-label small mb-1">Status</label>
          <select id="laStatusFilter" class="form-select">
            <option value="">All</option>
            <option value="0">Pending</option>
            <option value="1">L1 Approved</option>
            <option value="2">L2 Approved</option>
            <option value="3">Rejected</option>
            <option value="4">Cancelled</option>
          </select>
        </div>
      </div>

      <div class="table-responsive">
        <table class="table table-hover align-middle" id="laTable">
          <thead>
            <tr>
              <th>#</th>
              <th>Employee</th>
              <th>Type</th>
              <th>Dates</th>
              <th>Days</th>
              <th>Status</th>
              <th>Applied</th>
              <th class="text-end">Actions</th>
            </tr>
          </thead>
          <tbody>
          <?php foreach($rows as $r): ?>
            <?php
              $st = (int)$r['Status'];
              $sClass = status_class($STATUS, $st);
              $sText  = status_text($STATUS, $st);

              $empName = isset($r['EmployeeName']) && $r['EmployeeName']!=='' ? $r['EmployeeName'] : ('#'.$r['EmployeeID']);
              $typeName = isset($r['LeaveTypeName']) ? $r['LeaveTypeName'] : '';

              $sd = (string)$r['StartDate'];
              $ed = (string)$r['EndDate'];

              $searchIndex = strtolower(
                $empName.' '.$typeName.' '.$sd.' '.$ed.' '.(string)$r['TotalDays'].' '.(string)$r['Reason']
              );
            ?>
            <tr data-status="<?php echo $st; ?>" data-search="<?php echo h($searchIndex); ?>">
              <td><?php echo (int)$r['LeaveApplicationID']; ?></td>
              <td><?php echo h($empName); ?></td>
              <td><?php echo h($typeName); ?></td>
              <td>
                <?php echo h(substr($sd,0,10)); ?> → <?php echo h(substr($ed,0,10)); ?>
              </td>
              <td><?php echo h($r['TotalDays']); ?></td>
              <td>
                <span class="status-pill <?php echo $sClass; ?>">
                  <span class="status-dot"></span>
                  <?php echo h($sText); ?>
                </span>
              </td>
              <td><?php echo h($r['AppliedDate']); ?></td>

              <td class="text-end">
                <div class="action-stack">
                  <a class="btn btn-muted btn-sm w-100 w-md-auto"
                     href="<?php echo h($self); ?>?edit=<?php echo (int)$r['LeaveApplicationID']; ?>">
                    <i class="fas fa-pencil-alt"></i> Edit
                  </a>

                  <!-- Quick: L1 Approve -->
                  <form method="post" class="d-inline" accept-charset="UTF-8"
                        onsubmit="return confirm('Approve Level-1?');">
                    <input type="hidden" name="csrf" value="<?php echo h($CSRF); ?>">
                    <input type="hidden" name="act" value="set_status">
                    <input type="hidden" name="LeaveApplicationID" value="<?php echo (int)$r['LeaveApplicationID']; ?>">
                    <input type="hidden" name="to" value="1">
                    <button class="btn btn-muted btn-sm w-100 w-md-auto" type="submit">
                      <i class="fas fa-check-circle"></i> L1
                    </button>
                  </form>

                  <!-- Quick: L2 Approve -->
                  <form method="post" class="d-inline" accept-charset="UTF-8"
                        onsubmit="return confirm('Approve Level-2?');">
                    <input type="hidden" name="csrf" value="<?php echo h($CSRF); ?>">
                    <input type="hidden" name="act" value="set_status">
                    <input type="hidden" name="LeaveApplicationID" value="<?php echo (int)$r['LeaveApplicationID']; ?>">
                    <input type="hidden" name="to" value="2">
                    <button class="btn btn-muted btn-sm w-100 w-md-auto" type="submit">
                      <i class="fas fa-check-double"></i> L2
                    </button>
                  </form>

                  <!-- Quick: Reject (prompt reason) -->
                  <form method="post" class="d-inline laRejectForm" accept-charset="UTF-8"
                        data-id="<?php echo (int)$r['LeaveApplicationID']; ?>">
                    <input type="hidden" name="csrf" value="<?php echo h($CSRF); ?>">
                    <input type="hidden" name="act" value="set_status">
                    <input type="hidden" name="LeaveApplicationID" value="<?php echo (int)$r['LeaveApplicationID']; ?>">
                    <input type="hidden" name="to" value="3">
                    <input type="hidden" name="RejectionReason" value="">
                    <button class="btn btn-danger-soft btn-sm w-100 w-md-auto" type="submit">
                      <i class="fas fa-times-circle"></i> Reject
                    </button>
                  </form>

                  <!-- Quick: Cancel -->
                  <form method="post" class="d-inline" accept-charset="UTF-8"
                        onsubmit="return confirm('Cancel this application?');">
                    <input type="hidden" name="csrf" value="<?php echo h($CSRF); ?>">
                    <input type="hidden" name="act" value="set_status">
                    <input type="hidden" name="LeaveApplicationID" value="<?php echo (int)$r['LeaveApplicationID']; ?>">
                    <input type="hidden" name="to" value="4">
                    <button class="btn btn-muted btn-sm w-100 w-md-auto" type="submit">
                      <i class="fas fa-ban"></i> Cancel
                    </button>
                  </form>

                  <!-- Delete -->
                  <form method="post" class="d-inline" accept-charset="UTF-8"
                        onsubmit="return confirm('Delete this leave application permanently?');">
                    <input type="hidden" name="csrf" value="<?php echo h($CSRF); ?>">
                    <input type="hidden" name="act" value="delete">
                    <input type="hidden" name="LeaveApplicationID" value="<?php echo (int)$r['LeaveApplicationID']; ?>">
                    <button class="btn btn-danger-soft btn-sm w-100 w-md-auto" type="submit">
                      <i class="fas fa-trash-alt"></i> Delete
                    </button>
                  </form>

                </div>
              </td>
            </tr>
          <?php endforeach; ?>

          <?php if (empty($rows)): ?>
            <tr><td colspan="8" class="text-center text-muted py-4">
              <i class="fas fa-folder-open me-1"></i> No data
            </td></tr>
          <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>

</div>

<script>
// Auto total days (inclusive) for create/edit
function calcDaysInclusive(startStr, endStr){
  if(!startStr || !endStr) return '';
  var s = new Date(startStr + 'T00:00:00');
  var e = new Date(endStr + 'T00:00:00');
  if (isNaN(s.getTime()) || isNaN(e.getTime())) return '';
  var diff = Math.round((e - s) / (1000*60*60*24));
  if (diff < 0) return '';
  return (diff + 1).toFixed(1); // fits decimal(4,1)
}

(function(){
  // filters
  var searchInput  = document.getElementById('laSearch');
  var statusFilter = document.getElementById('laStatusFilter');
  var table        = document.getElementById('laTable');
  if (table) {
    var rows = table.querySelectorAll('tbody tr');
    function applyFilters(){
      var q  = (searchInput && searchInput.value ? searchInput.value : '').toLowerCase();
      var st = statusFilter ? statusFilter.value : '';
      Array.prototype.forEach.call(rows, function(tr){
        var rowStatus = tr.getAttribute('data-status') || '';
        var searchStr = (tr.getAttribute('data-search') || '').toLowerCase();
        var matchSearch = !q || searchStr.indexOf(q) !== -1;
        var matchStatus = !st || rowStatus === st;
        tr.style.display = (matchSearch && matchStatus) ? '' : 'none';
      });
    }
    if (searchInput)  searchInput.addEventListener('input', applyFilters);
    if (statusFilter) statusFilter.addEventListener('change', applyFilters);
    applyFilters();
  }

  // create form days
  var cs = document.getElementById('createStart');
  var ce = document.getElementById('createEnd');
  var ctd = document.getElementById('createTotalDays');
  function updCreateDays(){
    if (!ctd) return;
    var v = calcDaysInclusive(cs ? cs.value : '', ce ? ce.value : '');
    if (v !== '') ctd.value = v;
  }
  if (cs) cs.addEventListener('change', updCreateDays);
  if (ce) ce.addEventListener('change', updCreateDays);

  // edit form days
  var es = document.getElementById('editStart');
  var ee = document.getElementById('editEnd');
  var etd = document.getElementById('editTotalDays');
  function updEditDays(){
    if (!etd) return;
    var v = calcDaysInclusive(es ? es.value : '', ee ? ee.value : '');
    if (v !== '') etd.value = v;
  }
  if (es) es.addEventListener('change', updEditDays);
  if (ee) ee.addEventListener('change', updEditDays);

  // reject prompt
  var rejects = document.querySelectorAll('.laRejectForm');
  Array.prototype.forEach.call(rejects, function(f){
    f.addEventListener('submit', function(ev){
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
