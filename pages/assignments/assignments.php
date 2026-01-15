<?php
/************************************
 * Assignments - Full CRUD (raw PHP)
 * Table: dbo.Assignments
 ************************************/
ini_set('display_errors', 1);
error_reporting(E_ALL);

/* 1) Boot */
require_once __DIR__ . '/../../init.php';
require_login();

/* 2) Helpers */
if (!function_exists('h')) {
  function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
}
function self_name(){ return strtok(basename($_SERVER['SCRIPT_NAME']), "?"); }
$self = self_name();

/* Convert HTML datetime-local -> SQL Server friendly */
function to_sql_datetime($s){
  $s = trim((string)$s);
  if ($s === '') return null;
  $s = str_replace('T', ' ', $s);
  try { $dt = new DateTime($s); return $dt->format('Y-m-d H:i:s'); }
  catch (Throwable $e) { return null; }
}

/* 3) CSRF */
if (!isset($_SESSION['csrf'])) {
  $_SESSION['csrf'] = function_exists('openssl_random_pseudo_bytes')
    ? bin2hex(openssl_random_pseudo_bytes(16))
    : substr(str_shuffle(md5(uniqid(mt_rand(), true))), 0, 32);
}
$CSRF = $_SESSION['csrf'];
function check_csrf(){ if (!isset($_POST['csrf']) || $_POST['csrf'] !== $_SESSION['csrf']) die('Invalid CSRF token'); }

/* 4) Reference lists */
try {
  $assets = $conn->query("
    SELECT AssetID, AssetTag, AssetName
    FROM dbo.Assets
    ORDER BY AssetTag
  ")->fetchAll();
} catch (PDOException $e) { $assets=[]; }

try {
  $employees = $conn->query("
    SELECT EmployeeID, FirstName + ' ' + ISNULL(LastName,'') AS EmpName
    FROM dbo.Employees
    ORDER BY FirstName, LastName
  ")->fetchAll();
} catch (PDOException $e) { $employees=[]; }

try {
  $locations = $conn->query("
    SELECT LocationID, LocationName
    FROM dbo.Locations
    ORDER BY LocationName
  ")->fetchAll();
} catch (PDOException $e) { $locations=[]; }

/* 5) Actions */
$msg=''; $msg_type='success';
if (isset($_GET['ok']) && $_GET['ok']==='1'){ $msg='Assignment created.'; }

$editRow = null;
$edit_id = isset($_GET['edit']) ? (int)$_GET['edit'] : 0;


/* CREATE */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (isset($_POST['act']) ? $_POST['act'] : '') === 'create') {
    check_csrf();

    $AssetID = isset($_POST['AssetID']) ? (int)$_POST['AssetID'] : 0;
    $AssignedToEmployeeID = (isset($_POST['AssignedToEmployeeID']) && $_POST['AssignedToEmployeeID'] !== '') ? (int)$_POST['AssignedToEmployeeID'] : null;
    $AssignedToLocationID = isset($_POST['AssignedToLocationID']) ? (int)$_POST['AssignedToLocationID'] : 0;
    $AssignedByUserID = isset($_SESSION['auth_user']['UserID']) ? (int)$_SESSION['auth_user']['UserID'] : null;
    $AssignedAt = isset($_POST['AssignedAt']) ? to_sql_datetime($_POST['AssignedAt']) : null;
    $ExpectedReturnDate = isset($_POST['ExpectedReturnDate']) ? to_sql_datetime($_POST['ExpectedReturnDate']) : null;
    $ConditionAtAssign = isset($_POST['ConditionAtAssign']) ? trim($_POST['ConditionAtAssign']) : '';
    $IsActive = isset($_POST['IsActive']) ? 1 : 0;
    $Notes = isset($_POST['Notes']) ? trim($_POST['Notes']) : '';

    $errors=[];
    if ($AssetID<=0) $errors[]='Asset is required.';
    if ($AssignedToLocationID<=0) $errors[]='Assigned location is required.';

    if (empty($errors)){
      try {
        // Only one active assignment per asset
        if ($IsActive === 1) {
          $chk = $conn->prepare("SELECT 1 FROM dbo.Assignments WHERE AssetID=:a AND IsActive=1");
          $chk->execute([':a'=>$AssetID]);
          if ($chk->fetchColumn()) {
            $msg = "Create failed: This asset already has an active assignment.";
            $msg_type='danger';
          }
        }

        if ($msg_type !== 'danger') {
          $st = $conn->prepare("
            INSERT INTO dbo.Assignments
              (AssetID, AssignedToEmployeeID, AssignedToLocationID, AssignedByUserID,
               AssignedAt, ExpectedReturnDate, ConditionAtAssign, IsActive, Notes,
               CreatedAt, CreatedBy)
            VALUES
              (:asset, :emp, :loc, :by_assign,
               :at, :ret, :cond, :active, :notes,
               GETDATE(), :by_created)
          ");

          $st->bindValue(':asset', $AssetID, PDO::PARAM_INT);
          if ($AssignedToEmployeeID===null) $st->bindValue(':emp', null, PDO::PARAM_NULL); else $st->bindValue(':emp', $AssignedToEmployeeID, PDO::PARAM_INT);
          $st->bindValue(':loc', $AssignedToLocationID, PDO::PARAM_INT);

          if ($AssignedByUserID===null) {
            $st->bindValue(':by_assign',  null, PDO::PARAM_NULL);
            $st->bindValue(':by_created', null, PDO::PARAM_NULL);
          } else {
            $st->bindValue(':by_assign',  $AssignedByUserID, PDO::PARAM_INT);
            $st->bindValue(':by_created', $AssignedByUserID, PDO::PARAM_INT);
          }

          $st->bindValue(':at',  $AssignedAt,         $AssignedAt===null?PDO::PARAM_NULL:PDO::PARAM_STR);
          $st->bindValue(':ret', $ExpectedReturnDate, $ExpectedReturnDate===null?PDO::PARAM_NULL:PDO::PARAM_STR);
          $st->bindValue(':cond', $ConditionAtAssign!==''?$ConditionAtAssign:null, $ConditionAtAssign!==''?PDO::PARAM_STR:PDO::PARAM_NULL);
          $st->bindValue(':active', (int)$IsActive, PDO::PARAM_INT);
          $st->bindValue(':notes', $Notes!==''?$Notes:null, $Notes!==''?PDO::PARAM_STR:PDO::PARAM_NULL);

          $st->execute();
          header('Location: '.$self.'?ok=1'); exit;
        }
      } catch (PDOException $e) {
        $msg = "Create failed: ".h($e->getMessage()); $msg_type='danger';
      }
    } else { $msg = implode(' ', $errors); $msg_type='danger'; }
}

/* LOAD EDIT ROW */
if ($edit_id>0){
  try{
    $st = $conn->prepare("
      SELECT a.*,
             asst.AssetTag, asst.AssetName,
             loc.LocationName,
             emp.FirstName + ' ' + ISNULL(emp.LastName,'') AS EmpName,
             u.Username AS CreatedByUsername
        FROM dbo.Assignments a
        JOIN dbo.Assets asst ON asst.AssetID = a.AssetID
        JOIN dbo.Locations loc ON loc.LocationID = a.AssignedToLocationID
        LEFT JOIN dbo.Employees emp ON emp.EmployeeID = a.AssignedToEmployeeID
        LEFT JOIN dbo.Users u ON u.UserID = a.CreatedBy
       WHERE a.AssignmentID = :id
    ");
    $st->execute([':id'=>$edit_id]);
    $editRow = $st->fetch();
    if (!$editRow){ $msg = "Row not found for edit."; $msg_type='danger'; $edit_id=0; }
  }catch (PDOException $e){ $msg="Load edit row failed: ".h($e->getMessage()); $msg_type='danger'; }
}

/* UPDATE */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (isset($_POST['act']) ? $_POST['act'] : '') === 'update') {
    check_csrf();

    $AssignmentID = isset($_POST['AssignmentID']) ? (int)$_POST['AssignmentID'] : 0;
    $AssetID = isset($_POST['AssetID']) ? (int)$_POST['AssetID'] : 0;
    $AssignedToEmployeeID = (isset($_POST['AssignedToEmployeeID']) && $_POST['AssignedToEmployeeID'] !== '') ? (int)$_POST['AssignedToEmployeeID'] : null;
    $AssignedToLocationID = isset($_POST['AssignedToLocationID']) ? (int)$_POST['AssignedToLocationID'] : 0;
    $AssignedAt = isset($_POST['AssignedAt']) ? to_sql_datetime($_POST['AssignedAt']) : null;
    $ExpectedReturnDate = isset($_POST['ExpectedReturnDate']) ? to_sql_datetime($_POST['ExpectedReturnDate']) : null;
    $ConditionAtAssign = isset($_POST['ConditionAtAssign']) ? trim($_POST['ConditionAtAssign']) : '';
    $IsActive = isset($_POST['IsActive']) ? 1 : 0;
    $Notes = isset($_POST['Notes']) ? trim($_POST['Notes']) : '';

    $errors=[];
    if ($AssignmentID<=0) $errors[]='Invalid assignment.';
    if ($AssetID<=0) $errors[]='Asset is required.';
    if ($AssignedToLocationID<=0) $errors[]='Assigned location is required.';

    if (empty($errors)){
      try{
        if ($IsActive === 1) {
          $chk = $conn->prepare("SELECT 1 FROM dbo.Assignments WHERE AssetID=:a AND IsActive=1 AND AssignmentID<>:id");
          $chk->execute([':a'=>$AssetID, ':id'=>$AssignmentID]);
          if ($chk->fetchColumn()){
            $msg = "Update failed: This asset already has an active assignment."; $msg_type='danger';
          }
        }

        if ($msg_type !== 'danger') {
          $st = $conn->prepare("
            UPDATE dbo.Assignments
               SET AssetID = :asset,
                   AssignedToEmployeeID = :emp,
                   AssignedToLocationID = :loc,
                   AssignedAt = :at,
                   ExpectedReturnDate = :ret,
                   ConditionAtAssign = :cond,
                   IsActive = :active,
                   Notes = :notes
             WHERE AssignmentID = :id
          ");
          $st->bindValue(':asset', $AssetID, PDO::PARAM_INT);
          if ($AssignedToEmployeeID===null) $st->bindValue(':emp', null, PDO::PARAM_NULL); else $st->bindValue(':emp', $AssignedToEmployeeID, PDO::PARAM_INT);
          $st->bindValue(':loc', $AssignedToLocationID, PDO::PARAM_INT);
          $st->bindValue(':at', $AssignedAt, $AssignedAt===null?PDO::PARAM_NULL:PDO::PARAM_STR);
          $st->bindValue(':ret', $ExpectedReturnDate, $ExpectedReturnDate===null?PDO::PARAM_NULL:PDO::PARAM_STR);
          $st->bindValue(':cond', $ConditionAtAssign!==''?$ConditionAtAssign:null, $ConditionAtAssign!==''?PDO::PARAM_STR:PDO::PARAM_NULL);
          $st->bindValue(':active', (int)$IsActive, PDO::PARAM_INT);
          $st->bindValue(':notes', $Notes!==''?$Notes:null, $Notes!==''?PDO::PARAM_STR:PDO::PARAM_NULL);
          $st->bindValue(':id', $AssignmentID, PDO::PARAM_INT);
          $st->execute();
          header('Location: '.$self); exit;
        }
      }catch(PDOException $e){
        $msg="Update failed: ".h($e->getMessage()); $msg_type='danger';
      }
    } else { $msg=implode(' ', $errors); $msg_type='danger'; }
}

/* DELETE */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (isset($_POST['act']) ? $_POST['act'] : '') === 'delete') {
    check_csrf();
    $id = isset($_POST['AssignmentID']) ? (int)$_POST['AssignmentID'] : 0;
  if ($id>0){
    try{
      $conn->prepare("DELETE FROM dbo.Assignments WHERE AssignmentID=:id")->execute([':id'=>$id]);
      $msg="Assignment deleted."; $msg_type='success';
    }catch(PDOException $e){ $msg="Delete failed: ".h($e->getMessage()); $msg_type='danger'; }
  }
}

/* 6) List + Search + Active filter */
$search       = isset($_GET['q']) ? trim($_GET['q']) : '';
$activeFilter = isset($_GET['active']) ? trim($_GET['active']) : '';

if ($activeFilter !== '1' && $activeFilter !== '0') {
  $activeFilter = '';
}

try{
  $sql = "
    SELECT a.AssignmentID, a.AssetID, asst.AssetTag, asst.AssetName,
           a.AssignedToEmployeeID,
           emp.FirstName + ' ' + ISNULL(emp.LastName,'') AS EmpName,
           a.AssignedToLocationID, loc.LocationName,
           a.AssignedAt, a.ExpectedReturnDate, a.IsActive,
           a.ConditionAtAssign, a.Notes,
           a.CreatedAt, u.Username AS CreatedByUsername
      FROM dbo.Assignments a
      JOIN dbo.Assets asst ON asst.AssetID = a.AssetID
      JOIN dbo.Locations loc ON loc.LocationID = a.AssignedToLocationID
      LEFT JOIN dbo.Employees emp ON emp.EmployeeID = a.AssignedToEmployeeID
      LEFT JOIN dbo.Users u ON u.UserID = a.CreatedBy
  ";

  $where  = "1=1";
  $params = array();

  if ($search !== '') {
    $where .= " AND (
          asst.AssetTag   LIKE :q
       OR asst.AssetName  LIKE :q
       OR loc.LocationName LIKE :q
       OR emp.FirstName   LIKE :q
       OR emp.LastName    LIKE :q
    )";
    $params[':q'] = '%'.$search.'%';
  }

  if ($activeFilter !== '') {
    $where .= " AND a.IsActive = :active";
    $params[':active'] = (int)$activeFilter;
  }

  $sql .= " WHERE ".$where." ORDER BY a.AssignedAt DESC, asst.AssetTag";

  $st = $conn->prepare($sql);
  $st->execute($params);
  $rows = $st->fetchAll();
}catch(PDOException $e){ $rows=[]; $msg="Load list failed: ".h($e->getMessage()); $msg_type='danger'; }

/* 7) Render */
require_once __DIR__ . '/../../include/header.php';
?>
<!-- Bootstrap Icons -->
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">

<style>
  .page-wrap{ margin:24px auto; padding:0 12px; }
  .page-title{
    font-weight:700;
    letter-spacing:.2px;
    display:flex;
    align-items:center;
    gap:.45rem;
  }
  .page-title i{ font-size:1.4rem; color:#2563eb; }

  .card-elevated{
    border:1px solid #e5e7eb;
    border-radius:14px;
    box-shadow:0 8px 22px rgba(15,23,42,.06);
  }

  .badge-soft{
    border:1px solid #e2e8f0;
    background:#f8fafc;
    border-radius:999px;
    padding:4px 10px;
    font-size:12px;
  }

  .btn-brand{
    background:linear-gradient(135deg,#2563eb,#1d4ed8);
    color:#fff!important;
    border:none;
  }
  .btn-brand:hover{ filter:brightness(1.05); }
  .btn-muted{
    background:#e5e7eb;
    color:#111827!important;
    border:none;
  }
  .btn-muted:hover{ background:#d1d5db; }
  .btn-danger-soft{
    background:#fee2e2;
    color:#b91c1c!important;
    border:1px solid #fecaca;
  }
  .btn-danger-soft:hover{ background:#fecaca; }

  .form-label{
    font-weight:600;
    color:#374151;
    display:flex;
    align-items:center;
    gap:.35rem;
    font-size:.9rem;
  }
  .form-label i{ color:#64748b; font-size:.95rem; }

  .form-control, .form-select{
    border-radius:10px;
    border-color:#cbd5e1;
  }

  .action-stack>*{ margin:4px; }
  @media(min-width:768px){
    .action-stack{ display:inline-flex; gap:6px; }
  }

  .table thead th{
    background:#f8fafc;
    color:#334155;
    border-bottom:1px solid #e5e7eb;
    font-size:.8rem;
    white-space:nowrap;
  }
  .table tbody td{
    vertical-align:middle;
    font-size:.85rem;
  }

  .status-badge{
    font-size:.75rem;
    border-radius:999px;
    padding:3px 9px;
    display:inline-flex;
    align-items:center;
    gap:.25rem;
  }
  .status-active{
    background:#ecfdf5;
    color:#15803d;
    border:1px solid #bbf7d0;
  }
  .status-inactive{
    background:#f9fafb;
    color:#6b7280;
    border:1px solid #e5e7eb;
  }

  @media (max-width:575.98px){
    .page-wrap{ margin-top:16px; }
  }
</style>

<div class="page-wrap">

  <!-- Header -->
  <div class="d-flex flex-wrap align-items-center justify-content-between mb-3 gap-2">
    <h1 class="page-title mb-0">
      <i class="bi bi-arrow-left-right"></i>
      <span>Assignments</span>
    </h1>
  </div>

  <!-- Alerts -->
  <?php if ($msg): ?>
    <div class="alert alert-<?php echo ($msg_type==='danger'?'danger':'success'); ?> alert-dismissible fade show" role="alert">
      <?php echo $msg; ?>
      <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close" style="box-shadow:none;outline:none;"></button>
    </div>
  <?php endif; ?>

  <!-- Create / Edit Card -->
  <div class="card card-elevated mb-4">
    <div class="card-body">
      <?php if (!empty($editRow)): ?>
        <div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-3">
          <h5 class="mb-0">
            <i class="bi bi-pencil-square me-1 text-primary"></i>Edit Assignment
          </h5>
          <a class="btn btn-muted btn-sm" href="<?php echo h($self); ?>">
            <i class="bi bi-x-circle me-1"></i>Cancel
          </a>
        </div>

        <form method="post" accept-charset="UTF-8">
          <input type="hidden" name="csrf" value="<?php echo h($CSRF); ?>">
          <input type="hidden" name="act" value="update">
          <input type="hidden" name="AssignmentID" value="<?php echo (int)$editRow['AssignmentID']; ?>">

          <div class="row g-3">
            <div class="col-12 col-md-4">
              <label class="form-label">
                <i class="bi bi-box-seam"></i> Asset
              </label>
              <select name="AssetID" class="form-select" required>
                <option value="">-- Select --</option>
                <?php foreach($assets as $a): ?>
                  <option value="<?php echo (int)$a['AssetID']; ?>"
                    <?php echo ((int)$editRow['AssetID']===(int)$a['AssetID']?'selected':''); ?>>
                    <?php echo h($a['AssetTag'].' — '.$a['AssetName']); ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>

            <div class="col-12 col-md-4">
              <label class="form-label">
                <i class="bi bi-person-badge"></i> Employee (optional)
              </label>
              <select name="AssignedToEmployeeID" class="form-select">
                <option value="">-- None --</option>
                <?php foreach($employees as $e): ?>
                  <option value="<?php echo (int)$e['EmployeeID']; ?>"
                    <?php echo ((int)(isset($editRow['AssignedToEmployeeID']) ? $editRow['AssignedToEmployeeID'] : 0) === (int)$e['EmployeeID'] ? 'selected' : ''); ?>>
                    <?php echo h($e['EmpName']); ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>

            <div class="col-12 col-md-4">
              <label class="form-label">
                <i class="bi bi-geo-alt"></i> Location
              </label>
              <select name="AssignedToLocationID" class="form-select" required>
                <option value="">-- Select --</option>
                <?php foreach($locations as $l): ?>
                  <option value="<?php echo (int)$l['LocationID']; ?>"
                    <?php echo ((int)$editRow['AssignedToLocationID']===(int)$l['LocationID']?'selected':''); ?>>
                    <?php echo h($l['LocationName']); ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>

            <div class="col-12 col-md-4">
              <label class="form-label">
                <i class="bi bi-calendar-check"></i> Assigned At
              </label>
              <input type="datetime-local" name="AssignedAt" class="form-control"
                     value="<?php echo ($editRow['AssignedAt']?date('Y-m-d\TH:i', strtotime($editRow['AssignedAt'])):''); ?>">
            </div>

            <div class="col-12 col-md-4">
              <label class="form-label">
                <i class="bi bi-calendar-event"></i> Expected Return
              </label>
              <input type="datetime-local" name="ExpectedReturnDate" class="form-control"
                     value="<?php echo ($editRow['ExpectedReturnDate']?date('Y-m-d\TH:i', strtotime($editRow['ExpectedReturnDate'])):''); ?>">
            </div>

            <div class="col-12 col-md-4 d-flex align-items-center">
              <div class="form-check">
                <input class="form-check-input" type="checkbox" name="IsActive" id="isActiveEdit"
                       <?php echo ((int)$editRow['IsActive']===1?'checked':''); ?>>
                <label class="form-check-label" for="isActiveEdit">Active</label>
              </div>
            </div>

            <div class="col-12">
              <label class="form-label">
                <i class="bi bi-clipboard-check"></i> Condition at Assignment
              </label>
              <input type="text" name="ConditionAtAssign" class="form-control" maxlength="250"
                     value="<?php echo h($editRow['ConditionAtAssign']); ?>" placeholder="Condition at assignment">
            </div>

            <div class="col-12">
              <label class="form-label">
                <i class="bi bi-journal-text"></i> Notes (optional)
              </label>
              <input type="text" name="Notes" class="form-control" maxlength="1000"
                     value="<?php echo h($editRow['Notes']); ?>">
            </div>

            <div class="col-12">
              <div class="text-muted small">
                <i class="bi bi-clock-history me-1"></i>
                Created:
                <span class="badge-soft"><?php echo h($editRow['CreatedAt']); ?></span>
                <span class="badge-soft">
                  <?php echo h(isset($editRow['CreatedByUsername']) ? $editRow['CreatedByUsername'] : ''); ?>
                </span>
              </div>
            </div>

            <div class="col-12 d-grid d-md-inline">
              <button class="btn btn-brand w-100 w-md-auto">
                <i class="bi bi-save2 me-1"></i>Update
              </button>
            </div>
          </div>
        </form>

      <?php else: ?>
        <div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-3">
          <h5 class="mb-0">
            <i class="bi bi-plus-circle me-1 text-success"></i>Add Assignment
          </h5>
        </div>

        <form method="post" class="row g-3" accept-charset="UTF-8">
          <input type="hidden" name="csrf" value="<?php echo h($CSRF); ?>">
          <input type="hidden" name="act" value="create">

          <div class="col-12 col-md-4">
            <label class="form-label">
              <i class="bi bi-box-seam"></i> Asset
            </label>
            <select name="AssetID" class="form-select" required>
              <option value="">-- Select --</option>
              <?php foreach($assets as $a): ?>
                <option value="<?php echo (int)$a['AssetID']; ?>">
                  <?php echo h($a['AssetTag'].' — '.$a['AssetName']); ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="col-12 col-md-4">
            <label class="form-label">
              <i class="bi bi-person-badge"></i> Employee (optional)
            </label>
            <select name="AssignedToEmployeeID" class="form-select">
              <option value="">-- None --</option>
              <?php foreach($employees as $e): ?>
                <option value="<?php echo (int)$e['EmployeeID']; ?>"><?php echo h($e['EmpName']); ?></option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="col-12 col-md-4">
            <label class="form-label">
              <i class="bi bi-geo-alt"></i> Location
            </label>
            <select name="AssignedToLocationID" class="form-select" required>
              <option value="">-- Select --</option>
              <?php foreach($locations as $l): ?>
                <option value="<?php echo (int)$l['LocationID']; ?>"><?php echo h($l['LocationName']); ?></option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="col-12 col-md-4">
            <label class="form-label">
              <i class="bi bi-calendar-check"></i> Assigned At
            </label>
            <input type="datetime-local" name="AssignedAt" class="form-control" placeholder="optional">
          </div>

          <div class="col-12 col-md-4">
            <label class="form-label">
              <i class="bi bi-calendar-event"></i> Expected Return
            </label>
            <input type="datetime-local" name="ExpectedReturnDate" class="form-control" placeholder="optional">
          </div>

          <div class="col-12 col-md-4 d-flex align-items-center">
            <div class="form-check">
              <input class="form-check-input" type="checkbox" name="IsActive" id="isActiveCreate" checked>
              <label class="form-check-label" for="isActiveCreate">Active</label>
            </div>
          </div>

          <div class="col-12">
            <label class="form-label">
              <i class="bi bi-clipboard-check"></i> Condition at Assignment
            </label>
            <input type="text" name="ConditionAtAssign" class="form-control" maxlength="250" placeholder="Condition at assignment">
          </div>

          <div class="col-12">
            <label class="form-label">
              <i class="bi bi-journal-text"></i> Notes (optional)
            </label>
            <input type="text" name="Notes" class="form-control" maxlength="1000">
          </div>

          <div class="col-12 d-grid d-md-inline">
            <button class="btn btn-brand w-100 w-md-auto">
              <i class="bi bi-plus-circle me-1"></i>Create
            </button>
          </div>
        </form>
      <?php endif; ?>
    </div>
  </div>

  <!-- List -->
  <div class="card card-elevated">
    <div class="card-body">
      <div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-2">
        <h5 class="mb-0">
          <i class="bi bi-list-ul me-1"></i>All Assignments
        </h5>
        <span class="text-muted small">
          <i class="bi bi-collection me-1"></i>Total: <?php echo count($rows); ?>
        </span>
      </div>

      <!-- Search + Active filter -->
      <form method="get" class="mb-3" accept-charset="UTF-8">
        <div class="row g-2 align-items-end">

          <div class="col-12 col-md-6">
            <label class="form-label">
              <i class="bi bi-search"></i> Search
            </label>
            <div class="input-group">
              <span class="input-group-text">
                <i class="bi bi-search"></i>
              </span>
              <input type="text"
                     name="q"
                     class="form-control"
                     placeholder="Search asset tag/name, employee, location..."
                     value="<?php echo h($search); ?>">
            </div>
          </div>

          <div class="col-12 col-md-3">
            <label class="form-label">
              <i class="bi bi-toggle-on"></i> Status
            </label>
            <select name="active" class="form-select">
              <option value="">All</option>
              <option value="1" <?php echo ($activeFilter==='1'?'selected':''); ?>>Active</option>
              <option value="0" <?php echo ($activeFilter==='0'?'selected':''); ?>>Inactive</option>
            </select>
          </div>

          <div class="col-12 col-md-auto">
            <label class="form-label d-none d-md-block">&nbsp;</label>
            <div>
              <button class="btn btn-brand me-1" type="submit">
                <i class="bi bi-funnel me-1"></i>Apply
              </button>
              <a class="btn btn-muted" href="<?php echo h($self); ?>">
                <i class="bi bi-arrow-clockwise"></i>
              </a>
            </div>
          </div>

        </div>
      </form>

      <div class="table-responsive">
        <table class="table table-hover align-middle">
          <thead>
            <tr>
              <th>#</th>
              <th>Asset</th>
              <th>Employee</th>
              <th>Location</th>
              <th>Assigned</th>
              <th>Return</th>
              <th>Status</th>
              <th>Condition</th>
              <th>Notes</th>
              <th>Created</th>
              <th>By</th>
              <th class="text-end">Actions</th>
            </tr>
          </thead>
          <tbody>
          <?php foreach($rows as $r): ?>
            <tr>
              <td><?php echo (int)$r['AssignmentID']; ?></td>
              <td>
                <div class="fw-semibold"><?php echo h($r['AssetTag']); ?></div>
                <div class="text-muted small"><?php echo h($r['AssetName']); ?></div>
              </td>
              <td><?php echo h($r['EmpName']); ?></td>
              <td><?php echo h($r['LocationName']); ?></td>
              <td><?php echo h($r['AssignedAt']); ?></td>
              <td><?php echo h($r['ExpectedReturnDate']); ?></td>
              <td>
                <?php if ((int)$r['IsActive']===1): ?>
                  <span class="status-badge status-active">
                    <i class="bi bi-check-circle-fill"></i>Active
                  </span>
                <?php else: ?>
                  <span class="status-badge status-inactive">
                    <i class="bi bi-x-circle-fill"></i>Inactive
                  </span>
                <?php endif; ?>
              </td>
              <td><?php echo h($r['ConditionAtAssign']); ?></td>
              <td><?php echo h($r['Notes']); ?></td>
              <td><?php echo h($r['CreatedAt']); ?></td>
              <td><?php echo h(isset($r['CreatedByUsername']) ? $r['CreatedByUsername'] : ''); ?></td>

              <td class="text-end">
                <div class="action-stack">
                  <a class="btn btn-muted btn-sm w-100 w-md-auto"
                     href="<?php echo h($self); ?>?edit=<?php echo (int)$r['AssignmentID']; ?>"
                     title="Edit assignment">
                    <i class="bi bi-pencil"></i>
                  </a>

                  <form method="post" class="d-inline"
                        onsubmit="return confirm('Delete this assignment permanently?');"
                        accept-charset="UTF-8">
                    <input type="hidden" name="csrf" value="<?php echo h($CSRF); ?>">
                    <input type="hidden" name="act" value="delete">
                    <input type="hidden" name="AssignmentID" value="<?php echo (int)$r['AssignmentID']; ?>">
                    <button class="btn btn-danger-soft btn-sm w-100 w-md-auto" type="submit"
                            title="Delete assignment">
                      <i class="bi bi-trash"></i>
                    </button>
                  </form>
                </div>
              </td>
            </tr>
          <?php endforeach; ?>
          <?php if (empty($rows)): ?>
            <tr><td colspan="12" class="text-center text-muted py-4">No data</td></tr>
          <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>

</div><!-- /.page-wrap -->

<?php require_once __DIR__ . '/../../include/footer.php'; ?>
