<?php
/***********************
 * Leave Apply (Self) - PHP 5.6
 * Uses centralized employee mapping from init.php
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
  
  // D-M-YYYY or DD-MM-YYYY format from Flatpickr
  if (preg_match('/^(\d{1,2})-(\d{1,2})-(\d{4})$/', $s, $m)) {
    $dd=(int)$m[1]; $mm=(int)$m[2]; $yy=(int)$m[3];
    if ($mm>=1 && $mm<=12 && $dd>=1 && $dd<=31) return sprintf('%04d-%02d-%02d', $yy,$mm,$dd);
  }
  
  // Also accept D/M/YYYY or DD/MM/YYYY for backward compatibility
  if (preg_match('/^(\d{1,2})\/(\d{1,2})\/(\d{4})$/', $s, $m)) {
    $dd=(int)$m[1]; $mm=(int)$m[2]; $yy=(int)$m[3];
    if ($mm>=1 && $mm<=12 && $dd>=1 && $dd<=31) return sprintf('%04d-%02d-%02d', $yy,$mm,$dd);
  }
  
  return null;
}

// Function to format date for display (from YYYY-MM-DD to D-M-Y)
function format_date_dmy($date_str) {
    if (empty($date_str) || $date_str == '0000-00-00') return '';
    $date = DateTime::createFromFormat('Y-m-d', substr($date_str, 0, 10));
    if ($date) {
        return $date->format('d-m-Y');
    }
    return $date_str;
}

// Function to format datetime for display
function format_datetime_dmy($datetime_str) {
    if (empty($datetime_str) || $datetime_str == '0000-00-00 00:00:00') return '';
    $datetime = DateTime::createFromFormat('Y-m-d H:i:s', $datetime_str);
    if ($datetime) {
        return $datetime->format('d-m-Y H:i');
    }
    return $datetime_str;
}

function employee_display_expr(){
  return "LTRIM(RTRIM(COALESCE(e.FirstName,'') + ' ' + COALESCE(e.LastName,'')))";
}

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

/* Config */
$AUTO_SET_USERS_EMPLOYEE_ID = true;

/* Messages */
$msg=''; $msg_type='success';

/* Get current employee ID using centralized function */
$employeeId = get_current_employee_id($conn, $AUTO_SET_USERS_EMPLOYEE_ID);
$canApply = ($employeeId !== null);

if (!$canApply) { 
    $msg_type='danger'; 
    $msg='Employee mapping not found. Please link your account with an employee.'; 
}

if (isset($_GET['ok']) && $_GET['ok']==='1') { 
    $msg_type='success'; 
    $msg='Leave applied successfully.'; 
}

/* Load employees list (for self-linking if needed) */
$employees = array();
if (!$canApply) {
    try {
        $expr = employee_display_expr();
        $employees = $conn->query("
            SELECT e.EmployeeID, {$expr} AS EmpName, e.EmployeeCode
            FROM dbo.Employees e
            ORDER BY EmpName
        ")->fetchAll(PDO::FETCH_ASSOC);
    } catch(PDOException $e){
        $employees = array();
    }
}

/* Self link action (no new table) */
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['act']) && $_POST['act']==='link_employee') {
    check_csrf();
    $eid = isset($_POST['EmployeeID']) ? (int)$_POST['EmployeeID'] : 0;
    $currentUserId = isset($_SESSION['auth_user']['UserID']) ? (int)$_SESSION['auth_user']['UserID'] : 0;
    
    if ($currentUserId>0 && $eid>0) {
        try {
            $st = $conn->prepare("UPDATE dbo.Users SET EmployeeID=:eid WHERE UserID=:uid");
            $st->execute(array(':eid'=>$eid, ':uid'=>$currentUserId));
            
            // Update session
            $_SESSION['auth_user']['EmployeeID'] = $eid;
            $employeeId = $eid;
            $canApply = true;
            
            header("Location: ".$self."?ok=linked"); 
            exit;
        } catch(PDOException $e){
            $msg_type='danger';
            $msg='Map failed: '.h($e->getMessage());
        }
    } else {
        $msg_type='danger';
        $msg='Please select employee.';
    }
}

/* Leave Types */
$leaveTypes = array();
try {
    $leaveTypes = $conn->query("
        SELECT LeaveTypeID, LeaveTypeName
        FROM dbo.LeaveTypes
        WHERE (IsActive=1 OR IsActive IS NULL)
        ORDER BY LeaveTypeName
    ")->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $msg_type='danger';
    $msg='Leave types load failed: '.h($e->getMessage());
}

/* Edit (own only) */
$editRow=null;
$edit_id = isset($_GET['edit']) ? (int)$_GET['edit'] : 0;
if ($edit_id>0 && $canApply) {
    $st = $conn->prepare("SELECT * FROM dbo.LeaveApplications WHERE LeaveApplicationID=:id AND EmployeeID=:emp");
    $st->execute(array(':id'=>$edit_id, ':emp'=>$employeeId));
    $editRow = $st->fetch(PDO::FETCH_ASSOC);
}

/* CREATE */
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['act']) && $_POST['act']==='create') {
    check_csrf();

    if (!$canApply) { 
        $msg_type='danger'; 
        $msg='Please link your account with an employee first.'; 
    } else {
        $LeaveTypeID = isset($_POST['LeaveTypeID']) ? (int)$_POST['LeaveTypeID'] : 0;
        $StartDate = parse_date_any(isset($_POST['StartDate']) ? $_POST['StartDate'] : '');
        $EndDate   = parse_date_any(isset($_POST['EndDate']) ? $_POST['EndDate'] : '');
        $TotalDays = parse_decimal_1(isset($_POST['TotalDays']) ? $_POST['TotalDays'] : '');
        $Reason    = normalize_text(isset($_POST['Reason']) ? $_POST['Reason'] : '');

        if ($LeaveTypeID<=0 || !$StartDate || !$EndDate) { 
            $msg_type='danger'; 
            $msg="Type/Start/End required."; 
        } elseif (strtotime($EndDate) < strtotime($StartDate)) { 
            $msg_type='danger'; 
            $msg="End date start এর আগে হতে পারবে না।"; 
        } elseif ($TotalDays===null) { 
            $msg_type='danger'; 
            $msg="Total days invalid (e.g. 1, 1.5, 2.0)."; 
        } else {
            try {
                $stmt = $conn->prepare("
                    INSERT INTO dbo.LeaveApplications
                    (EmployeeID, LeaveTypeID, StartDate, EndDate, TotalDays, Reason, Status, AppliedDate)
                    VALUES
                    (:emp,:lt,:sd,:ed,:td,:rsn,0,GETDATE())
                ");
                $stmt->execute(array(
                    ':emp'=>$employeeId, ':lt'=>$LeaveTypeID, ':sd'=>$StartDate, ':ed'=>$EndDate,
                    ':td'=>$TotalDays, ':rsn'=>$Reason
                ));
                header('Location: '.$self.'?ok=1'); 
                exit;
            } catch(PDOException $e){
                $msg_type='danger'; 
                $msg="Create failed: ".h($e->getMessage());
            }
        }
    }
}

/* UPDATE (only pending) */
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['act']) && $_POST['act']==='update') {
    check_csrf();

    if (!$canApply) { 
        $msg_type='danger'; 
        $msg='Please link your account with an employee first.'; 
    } else {
        $id = isset($_POST['LeaveApplicationID']) ? (int)$_POST['LeaveApplicationID'] : 0;
        $LeaveTypeID = isset($_POST['LeaveTypeID']) ? (int)$_POST['LeaveTypeID'] : 0;
        $StartDate = parse_date_any(isset($_POST['StartDate']) ? $_POST['StartDate'] : '');
        $EndDate   = parse_date_any(isset($_POST['EndDate']) ? $_POST['EndDate'] : '');
        $TotalDays = parse_decimal_1(isset($_POST['TotalDays']) ? $_POST['TotalDays'] : '');
        $Reason    = normalize_text(isset($_POST['Reason']) ? $_POST['Reason'] : '');

        if ($id<=0 || $LeaveTypeID<=0 || !$StartDate || !$EndDate || $TotalDays===null) { 
            $msg_type='danger'; 
            $msg="Invalid data."; 
        } else {
            try {
                $st = $conn->prepare("
                    UPDATE dbo.LeaveApplications
                    SET LeaveTypeID=:lt, StartDate=:sd, EndDate=:ed, TotalDays=:td, Reason=:rsn
                    WHERE LeaveApplicationID=:id AND EmployeeID=:emp AND Status=0
                ");
                $st->execute(array(
                    ':lt'=>$LeaveTypeID, ':sd'=>$StartDate, ':ed'=>$EndDate, 
                    ':td'=>$TotalDays, ':rsn'=>$Reason, ':id'=>$id, ':emp'=>$employeeId
                ));
                header('Location: '.$self); 
                exit;
            } catch(PDOException $e){
                $msg_type='danger'; 
                $msg="Update failed: ".h($e->getMessage());
            }
        }
    }
}

/* CANCEL (only pending) */
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['act']) && $_POST['act']==='cancel') {
    check_csrf();

    if (!$canApply) { 
        $msg_type='danger'; 
        $msg='Please link your account with an employee first.'; 
    } else {
        $id = isset($_POST['LeaveApplicationID']) ? (int)$_POST['LeaveApplicationID'] : 0;
        if ($id>0) {
            try {
                $st = $conn->prepare("
                    UPDATE dbo.LeaveApplications
                    SET Status=4, CancelledDate=GETDATE()
                    WHERE LeaveApplicationID=:id AND EmployeeID=:emp AND Status=0
                ");
                $st->execute(array(':id'=>$id, ':emp'=>$employeeId));
                $msg_type='success'; 
                $msg='Cancelled.';
            } catch(PDOException $e){
                $msg_type='danger'; 
                $msg='Cancel failed: '.h($e->getMessage());
            }
        }
    }
}

/* List own */
$rows = array();
if ($canApply) {
    try {
        $st = $conn->prepare("
            SELECT la.LeaveApplicationID, la.StartDate, la.EndDate, la.TotalDays, la.Reason,
                   la.Status, la.AppliedDate, lt.LeaveTypeName
            FROM dbo.LeaveApplications la
            LEFT JOIN dbo.LeaveTypes lt ON lt.LeaveTypeID=la.LeaveTypeID
            WHERE la.EmployeeID=:emp
            ORDER BY la.LeaveApplicationID DESC
        ");
        $st->execute(array(':emp'=>$employeeId));
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    } catch(Exception $e) {
        $msg_type='danger';
        $msg='Failed to load leave applications: '.h($e->getMessage());
    }
}

require_once __DIR__ . '/../../include/header.php';
?>

<style>
    .page-wrap { max-width: 100%; margin: 28px auto; padding: 0 16px 32px; }
    .page-title { font-weight:700; color:#0f172a; display:flex; align-items:center; gap:8px; }
    .page-title i{ font-size:22px; color:#4f46e5; }
    .page-subtitle{ font-size:13px; color:#6b7280; }

    .card-elevated { border-radius:16px; border:1px solid #e5e7eb; box-shadow:0 18px 45px rgba(15,23,42,.12); overflow:hidden; }
    .card-elevated .card-body{ background: radial-gradient(circle at top left, #eff6ff 0, #ffffff 45%, #f9fafb 100%); }

    .badge-soft { border-radius:999px; padding:5px 12px; font-size:12px; font-weight:500; color:#0f172a; background:#e0f2fe; border:1px solid #bae6fd; display:inline-flex; align-items:center; gap:6px; }
    .badge-soft i{ font-size:.85rem; color:#0284c7; }

    .btn-brand { background:linear-gradient(135deg,#6366f1,#2563eb); color:#fff!important; border:none; padding:.55rem 1.4rem; font-weight:600; border-radius:999px; display:inline-flex; align-items:center; gap:8px; box-shadow:0 12px 25px rgba(37,99,235,.35); }
    .btn-brand:hover { background:linear-gradient(135deg,#4f46e5,#1d4ed8); }

    .btn-muted { background:#e5e7eb; color:#111827!important; border:none; border-radius:999px; padding:.45rem 1.1rem; font-weight:500; display:inline-flex; align-items:center; gap:6px; }
    .btn-danger-soft{ background:#fee2e2; color:#b91c1c!important; border:1px solid #fecaca; border-radius:999px; padding:.45rem 1.1rem; font-weight:500; display:inline-flex; align-items:center; gap:6px; }

    .form-label{ font-weight:600; color:#374151; font-size:13px; }
    .form-control, .form-select{ border-radius:10px; border-color:#cbd5e1; font-size:14px; }

    .section-title{ font-weight:600; color:#111827; display:flex; align-items:center; gap:8px; }
    .section-title i{ color:#4f46e5; font-size:1rem; }

    .status-pill{ display:inline-flex; align-items:center; gap:6px; padding:3px 10px; border-radius:999px; font-size:11px; font-weight:500; }
    .status-pill .status-dot{ width:8px; height:8px; border-radius:999px; }
    .status-pill-active{ background:#ecfdf3; color:#166534; } .status-pill-active .status-dot{ background:#22c55e; }
    .status-pill-inactive{ background:#fef2f2; color:#b91c1c; } .status-pill-inactive .status-dot{ background:#ef4444; }
    .status-pill-warning{ background:#fffbeb; color:#92400e; } .status-pill-warning .status-dot{ background:#f59e0b; }
    .status-pill-muted{ background:#f3f4f6; color:#374151; } .status-pill-muted .status-dot{ background:#9ca3af; }
</style>

<!-- Flatpickr CDN -->
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
<script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>

<div class="page-wrap">
    <div class="d-flex flex-wrap align-items-center justify-content-between mb-3 gap-2">
        <div>
            <h1 class="page-title mb-1"><i class="fas fa-paper-plane"></i> Leave Application</h1>
            <div class="page-subtitle">
                <?php if ($canApply): ?>
                    Employee: <?php echo h(isset($_SESSION['auth_user']['FullName']) ? $_SESSION['auth_user']['FullName'] : 'ID: ' . $employeeId); ?>
                <?php endif; ?>
            </div>
        </div>
        <span class="badge-soft"><i class="fas fa-layer-group"></i> Total: <?php echo count($rows); ?></span>
    </div>

    <?php if ($msg): ?>
        <div class="alert alert-<?php echo ($msg_type==='danger'?'danger':'success'); ?> alert-dismissible fade show shadow-sm" role="alert">
            <?php echo h($msg); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>

    <?php if (!$canApply): ?>
        <div class="card card-elevated mb-4">
            <div class="card-body">
                <div class="section-title mb-2"><i class="fas fa-link"></i><span>Link your account with Employee</span></div>
                <p class="text-muted mb-3">Your account (Username: <strong><?php echo h(isset($_SESSION['auth_user']['username']) ? $_SESSION['auth_user']['username'] : ''); ?></strong>) needs to be linked with an employee record.</p>
                <form method="post" class="row g-3" accept-charset="UTF-8">
                    <input type="hidden" name="csrf" value="<?php echo h($CSRF); ?>">
                    <input type="hidden" name="act" value="link_employee">
                    <div class="col-12 col-md-6">
                        <label class="form-label">Select Employee</label>
                        <select name="EmployeeID" class="form-select" required>
                            <option value="">-- Select --</option>
                            <?php foreach($employees as $e): ?>
                                <option value="<?php echo (int)$e['EmployeeID']; ?>">
                                    <?php echo h($e['EmpName']); ?> (<?php echo h($e['EmployeeCode']); ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-12 col-md-3 d-flex align-items-end">
                        <button class="btn btn-brand" type="submit"><i class="fas fa-save"></i> Save Mapping</button>
                    </div>
                </form>
                <div class="text-muted small mt-2">একবার map করলে পরে আর দেখাবে না।</div>
            </div>
        </div>
    <?php endif; ?>

    <?php if ($canApply): ?>
        <div class="card card-elevated mb-4">
            <div class="card-body">
                <?php if (!empty($editRow) && (int)$editRow['Status']===0): ?>
                    <div class="section-title mb-3"><i class="fas fa-edit"></i><span>Edit (Pending)</span></div>
                    <form method="post" class="row g-3" accept-charset="UTF-8">
                        <input type="hidden" name="csrf" value="<?php echo h($CSRF); ?>">
                        <input type="hidden" name="act" value="update">
                        <input type="hidden" name="LeaveApplicationID" value="<?php echo (int)$editRow['LeaveApplicationID']; ?>">

                        <div class="col-12 col-md-4">
                            <label class="form-label">Leave Type</label>
                            <select name="LeaveTypeID" class="form-select" required>
                                <option value="">-- Select --</option>
                                <?php foreach($leaveTypes as $lt): ?>
                                    <option value="<?php echo (int)$lt['LeaveTypeID']; ?>" <?php echo ((int)$editRow['LeaveTypeID']===(int)$lt['LeaveTypeID']?'selected':''); ?>>
                                        <?php echo h($lt['LeaveTypeName']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="col-6 col-md-2">
                            <label class="form-label">Start Date</label>
                            <input type="text" name="StartDate" class="form-control flatpickr-date" required 
                                   placeholder="DD-MM-YYYY" 
                                   value="<?php echo !empty($editRow['StartDate']) ? format_date_dmy($editRow['StartDate']) : ''; ?>">
                        </div>

                        <div class="col-6 col-md-2">
                            <label class="form-label">End Date</label>
                            <input type="text" name="EndDate" class="form-control flatpickr-date" required 
                                   placeholder="DD-MM-YYYY" 
                                   value="<?php echo !empty($editRow['EndDate']) ? format_date_dmy($editRow['EndDate']) : ''; ?>">
                        </div>

                        <div class="col-12 col-md-2">
                            <label class="form-label">Total Days</label>
                            <input type="number" step="0.1" min="0" name="TotalDays" class="form-control" required value="<?php echo h($editRow['TotalDays']); ?>">
                        </div>

                        <div class="col-12">
                            <label class="form-label">Details</label>
                            <textarea name="Reason" class="form-control" rows="2"><?php echo h($editRow['Reason']); ?></textarea>
                        </div>

                        <div class="col-12 d-grid d-md-inline">
                            <button class="btn btn-brand"><i class="fas fa-save"></i> Update</button>
                            <a class="btn btn-muted" href="<?php echo h($self); ?>">Cancel</a>
                        </div>
                    </form>
                <?php else: ?>
                    <div class="section-title mb-3"><i class="fas fa-plus-circle"></i><span>Apply New Leave</span></div>
                    <form method="post" class="row g-3" accept-charset="UTF-8">
                        <input type="hidden" name="csrf" value="<?php echo h($CSRF); ?>">
                        <input type="hidden" name="act" value="create">

                        <div class="col-12 col-md-4">
                            <label class="form-label">Leave Type</label>
                            <select name="LeaveTypeID" class="form-select" required>
                                <option value="">-- Select --</option>
                                <?php foreach($leaveTypes as $lt): ?>
                                    <option value="<?php echo (int)$lt['LeaveTypeID']; ?>"><?php echo h($lt['LeaveTypeName']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="col-6 col-md-2">
                            <label class="form-label">Start Date</label>
                            <input type="text" name="StartDate" class="form-control flatpickr-date" required placeholder="DD-MM-YYYY">
                        </div>

                        <div class="col-6 col-md-2">
                            <label class="form-label">End Date</label>
                            <input type="text" name="EndDate" class="form-control flatpickr-date" required placeholder="DD-MM-YYYY">
                        </div>

                        <div class="col-12 col-md-2">
                            <label class="form-label">Total Days</label>
                            <input type="number" step="0.1" min="0" name="TotalDays" class="form-control" required placeholder="e.g. 2.0">
                        </div>

                        <div class="col-12">
                            <label class="form-label">Details</label>
                            <textarea
                                name="Reason"
                                class="form-control"
                                rows="2"
                                placeholder="Reason..."
                                oninput="allowPlainText(this)"
                            ></textarea>
                        </div>

                        <div class="col-12 d-grid d-md-inline">
                            <button class="btn btn-brand"><i class="fas fa-save"></i> Submit</button>
                        </div>
                    </form>
                <?php endif; ?>
            </div>
        </div>

        <div class="card card-elevated">
            <div class="card-body">
                <div class="section-title mb-2"><i class="fas fa-list-ul"></i><span>My Applications</span></div>
                <div class="table-responsive">
                    <table class="table table-hover align-middle">
                        <thead>
                            <tr>
                                <th>#</th><th>Type</th><th>Dates</th><th>Days</th><th>Status</th><th>Applied</th><th class="text-end">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach($rows as $r): ?>
                            <?php $st=(int)$r['Status']; ?>
                            <tr>
                                <td><?php echo (int)$r['LeaveApplicationID']; ?></td>
                                <td><?php echo h($r['LeaveTypeName']); ?></td>
                                <td><?php echo format_date_dmy($r['StartDate']); ?> → <?php echo format_date_dmy($r['EndDate']); ?></td>
                                <td><?php echo h($r['TotalDays']); ?></td>
                                <td><span class="status-pill <?php echo status_class($STATUS,$st); ?>"><span class="status-dot"></span><?php echo h(status_text($STATUS,$st)); ?></span></td>
                                <td><?php echo format_datetime_dmy($r['AppliedDate']); ?></td>
                                <td class="text-end">
                                    <?php if ((int)$r['Status']===0): ?>
                                        <a class="btn btn-muted btn-sm" href="<?php echo h($self); ?>?edit=<?php echo (int)$r['LeaveApplicationID']; ?>"><i class="fas fa-pencil-alt"></i> Edit</a>
                                        <form method="post" class="d-inline" accept-charset="UTF-8" onsubmit="return confirm('Cancel?');">
                                            <input type="hidden" name="csrf" value="<?php echo h($CSRF); ?>">
                                            <input type="hidden" name="act" value="cancel">
                                            <input type="hidden" name="LeaveApplicationID" value="<?php echo (int)$r['LeaveApplicationID']; ?>">
                                            <button class="btn btn-danger-soft btn-sm" type="submit"><i class="fas fa-ban"></i> Cancel</button>
                                        </form>
                                    <?php else: ?>
                                        <span class="text-muted">—</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>

                        <?php if (empty($rows)): ?>
                            <tr><td colspan="7" class="text-center text-muted py-4">No leave applications found</td></tr>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    <?php endif; ?>
</div>

<script>
    function allowPlainText(el) {
        // Allow letters, numbers, space, full stop and comma
        el.value = el.value.replace(/[^a-zA-Z0-9 .,]/g, '');
    }
    
    // Initialize Flatpickr date picker
    document.addEventListener('DOMContentLoaded', function() {
        // Configure Flatpickr for D-M-Y format
        flatpickr(".flatpickr-date", {
            dateFormat: "d-m-Y",          // D-M-Y format (e.g., 24-12-2024)
            allowInput: true,             // Allows manual typing
            disableMobile: false,         // Keep mobile behavior
            // Optional: Set min date to today to prevent past dates
            // minDate: "today",
            
            // Optional: Set locale if needed (default is English)
            // locale: "bn" // For Bengali/Bangla language
            
            // Optional: Add month/year navigation
            monthSelectorType: "static"
        });
        
        // Optional: Add date validation when form is submitted
        const forms = document.querySelectorAll('form');
        forms.forEach(function(form) {
            form.addEventListener('submit', function(e) {
                const dateInputs = form.querySelectorAll('.flatpickr-date');
                let isValid = true;
                
                dateInputs.forEach(function(input) {
                    const value = input.value.trim();
                    if (value === '') {
                        isValid = false;
                        alert('Please select a date for ' + input.previousElementSibling.textContent);
                        input.focus();
                        return;
                    }
                    
                    // Validate D-M-Y format
                    const pattern = /^(\d{1,2})-(\d{1,2})-(\d{4})$/;
                    if (!pattern.test(value)) {
                        isValid = false;
                        alert('Please use DD-MM-YYYY format for ' + input.previousElementSibling.textContent);
                        input.focus();
                        return;
                    }
                    
                    const parts = value.split('-');
                    const day = parseInt(parts[0], 10);
                    const month = parseInt(parts[1], 10);
                    const year = parseInt(parts[2], 10);
                    
                    // Basic validation
                    if (month < 1 || month > 12) {
                        isValid = false;
                        alert('Month must be between 01 and 12');
                        input.focus();
                        return;
                    }
                    
                    if (day < 1 || day > 31) {
                        isValid = false;
                        alert('Day must be between 01 and 31');
                        input.focus();
                        return;
                    }
                    
                    // Check for valid date
                    const date = new Date(year, month - 1, day);
                    if (date.getFullYear() !== year || date.getMonth() !== month - 1 || date.getDate() !== day) {
                        isValid = false;
                        alert('Invalid date. Please check the day and month values.');
                        input.focus();
                    }
                });
                
                if (!isValid) {
                    e.preventDefault();
                }
            });
        });
    });
</script>

<?php require_once __DIR__ . '/../../include/footer.php'; ?>