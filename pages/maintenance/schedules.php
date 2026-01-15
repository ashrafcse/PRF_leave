<?php
/**********************************************
 * Maintenance Schedules - Full CRUD (raw PHP)
 * Table: dbo.MaintenanceSchedules
 **********************************************/
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
  try {
    $dt = new DateTime($s);
    return $dt->format('Y-m-d H:i:s');
  }
  catch (Exception $e) { // PHP 5.6-friendly
    return null;
  }
}

/* 3) CSRF */
if (!isset($_SESSION['csrf'])) {
  if (function_exists('openssl_random_pseudo_bytes')) {
    $_SESSION['csrf'] = bin2hex(openssl_random_pseudo_bytes(16));
  } else {
    $_SESSION['csrf'] = substr(str_shuffle(md5(uniqid(mt_rand(), true))), 0, 32);
  }
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
} catch (PDOException $e) { $assets=array(); }

/* 5) Actions */
$msg=''; 
$msg_type='success';
if (isset($_GET['ok']) && $_GET['ok']==='1'){ $msg='Schedule created.'; }

$editRow = null;
$edit_id = isset($_GET['edit']) ? (int)$_GET['edit'] : 0;


/* CREATE */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (isset($_POST['act']) && $_POST['act'] === 'create')) {
    check_csrf();

    $AssetID         = isset($_POST['AssetID']) ? (int)$_POST['AssetID'] : 0;
    $MaintenanceType = isset($_POST['MaintenanceType']) ? trim($_POST['MaintenanceType']) : '';
    $NextDueDate     = isset($_POST['NextDueDate']) ? to_sql_datetime($_POST['NextDueDate']) : null;
    $FrequencyDays   = (isset($_POST['FrequencyDays']) && $_POST['FrequencyDays'] !== '') ? (int)$_POST['FrequencyDays'] : null;
    $IsActive        = isset($_POST['IsActive']) ? 1 : 0;
    $LastPerformed   = isset($_POST['LastPerformedDate']) ? to_sql_datetime($_POST['LastPerformedDate']) : null;
    $Status          = isset($_POST['Status']) ? trim($_POST['Status']) : '';
    $CreatedBy       = isset($_SESSION['auth_user']['UserID']) ? (int)$_SESSION['auth_user']['UserID'] : null;

    $errors=array();
    if ($AssetID<=0) $errors[]='Asset is required.';
    if ($MaintenanceType==='') $errors[]='Maintenance type is required.';
    if ($FrequencyDays !== null && $FrequencyDays < 0) $errors[]='Frequency must be >= 0.';

    if (empty($errors)){
      try {
        $st = $conn->prepare("
          INSERT INTO dbo.MaintenanceSchedules
            (AssetID, MaintenanceType, NextDueDate, FrequencyDays,
             CreatedAt, CreatedBy, IsActive, LastPerformedDate, Status)
          VALUES
            (:asset, :mtype, :nextdue, :freq,
             GETDATE(), :by_created, :active, :lastperf, :status)
        ");
        $st->bindValue(':asset', $AssetID, PDO::PARAM_INT);
        $st->bindValue(':mtype', $MaintenanceType, PDO::PARAM_STR);
        $st->bindValue(':nextdue', $NextDueDate, $NextDueDate===null?PDO::PARAM_NULL:PDO::PARAM_STR);
        if ($FrequencyDays===null) $st->bindValue(':freq', null, PDO::PARAM_NULL); else $st->bindValue(':freq', $FrequencyDays, PDO::PARAM_INT);
        if ($CreatedBy===null) $st->bindValue(':by_created', null, PDO::PARAM_NULL); else $st->bindValue(':by_created', $CreatedBy, PDO::PARAM_INT);
        $st->bindValue(':active', (int)$IsActive, PDO::PARAM_INT);
        $st->bindValue(':lastperf', $LastPerformed, $LastPerformed===null?PDO::PARAM_NULL:PDO::PARAM_STR);
        $st->bindValue(':status', $Status!==''?$Status:'Pending', PDO::PARAM_STR);
        $st->execute();

        header('Location: '.$self.'?ok=1'); exit;
      } catch (PDOException $e) {
        $msg = "Create failed: ".h($e->getMessage()); 
        $msg_type='danger';
      }
    } else { 
      $msg = implode(' ', $errors); 
      $msg_type='danger'; 
    }
}

/* LOAD EDIT ROW */
if ($edit_id>0){
  try{
    $st = $conn->prepare("
      SELECT ms.*, a.AssetTag, a.AssetName, u.Username AS CreatedByName
        FROM dbo.MaintenanceSchedules ms
        JOIN dbo.Assets a ON a.AssetID = ms.AssetID
        LEFT JOIN dbo.Users u ON u.UserID = ms.CreatedBy
       WHERE ms.ScheduleID = :id
    ");
    $st->execute(array(':id'=>$edit_id));
    $editRow = $st->fetch();
    if (!$editRow){ 
      $msg = "Row not found for edit."; 
      $msg_type='danger'; 
      $edit_id=0; 
    }
  }catch (PDOException $e){ 
    $msg="Load edit row failed: ".h($e->getMessage()); 
    $msg_type='danger'; 
  }
}

/* UPDATE */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (isset($_POST['act']) && $_POST['act'] === 'update')) {
    check_csrf();

    $ScheduleID      = isset($_POST['ScheduleID']) ? (int)$_POST['ScheduleID'] : 0;
    $AssetID         = isset($_POST['AssetID']) ? (int)$_POST['AssetID'] : 0;
    $MaintenanceType = isset($_POST['MaintenanceType']) ? trim($_POST['MaintenanceType']) : '';
    $NextDueDate     = isset($_POST['NextDueDate']) ? to_sql_datetime($_POST['NextDueDate']) : null;
    $FrequencyDays   = (isset($_POST['FrequencyDays']) && $_POST['FrequencyDays'] !== '') ? (int)$_POST['FrequencyDays'] : null;
    $IsActive        = isset($_POST['IsActive']) ? 1 : 0;
    $LastPerformed   = isset($_POST['LastPerformedDate']) ? to_sql_datetime($_POST['LastPerformedDate']) : null;
    $Status          = isset($_POST['Status']) ? trim($_POST['Status']) : '';

    $errors=array();
    if ($ScheduleID<=0) $errors[]='Invalid row.';
    if ($AssetID<=0) $errors[]='Asset is required.';
    if ($MaintenanceType==='') $errors[]='Maintenance type is required.';
    if ($FrequencyDays !== null && $FrequencyDays < 0) $errors[]='Frequency must be >= 0.';

    if (empty($errors)){
      try{
        $st = $conn->prepare("
          UPDATE dbo.MaintenanceSchedules
             SET AssetID = :asset,
                 MaintenanceType = :mtype,
                 NextDueDate = :nextdue,
                 FrequencyDays = :freq,
                 IsActive = :active,
                 LastPerformedDate = :lastperf,
                 Status = :status
           WHERE ScheduleID = :id
        ");
        $st->bindValue(':asset', $AssetID, PDO::PARAM_INT);
        $st->bindValue(':mtype', $MaintenanceType, PDO::PARAM_STR);
        $st->bindValue(':nextdue', $NextDueDate, $NextDueDate===null?PDO::PARAM_NULL:PDO::PARAM_STR);
        if ($FrequencyDays===null) $st->bindValue(':freq', null, PDO::PARAM_NULL); else $st->bindValue(':freq', $FrequencyDays, PDO::PARAM_INT);
        $st->bindValue(':active', (int)$IsActive, PDO::PARAM_INT);
        $st->bindValue(':lastperf', $LastPerformed, $LastPerformed===null?PDO::PARAM_NULL:PDO::PARAM_STR);
        $st->bindValue(':status', $Status!==''?$Status:'Pending', PDO::PARAM_STR);
        $st->bindValue(':id', $ScheduleID, PDO::PARAM_INT);
        $st->execute();

        header('Location: '.$self); exit;
      }catch(PDOException $e){
        $msg="Update failed: ".h($e->getMessage()); 
        $msg_type='danger';
      }
    } else { 
      $msg=implode(' ', $errors); 
      $msg_type='danger'; 
    }
}

/* DELETE */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['act']) && $_POST['act'] === 'delete') {
    check_csrf();
    $id = isset($_POST['ScheduleID']) ? (int)$_POST['ScheduleID'] : 0;
    if ($id>0){
      try{
        $stmt = $conn->prepare("DELETE FROM dbo.MaintenanceSchedules WHERE ScheduleID=:id");
        $stmt->execute(array(':id'=>$id));
        $msg="Schedule deleted."; 
        $msg_type='success';
      }catch(PDOException $e){ 
        $msg="Delete failed: ".h($e->getMessage()); 
        $msg_type='danger'; 
      }
    }
}

/* 6) List + Search + Active filter */
$search      = isset($_GET['q']) ? trim($_GET['q']) : '';
$activeFilter = isset($_GET['active']) ? trim($_GET['active']) : '';

if ($activeFilter !== '1' && $activeFilter !== '0') {
  $activeFilter = '';
}

try{
  $sql = "
      SELECT ms.ScheduleID, ms.AssetID, a.AssetTag, a.AssetName,
             ms.MaintenanceType, ms.NextDueDate, ms.FrequencyDays,
             ms.IsActive, ms.LastPerformedDate, ms.Status, ms.CreatedAt
        FROM dbo.MaintenanceSchedules ms
        JOIN dbo.Assets a ON a.AssetID = ms.AssetID
       WHERE 1=1
  ";

  $params = array();

  if ($search!==''){
    $sql .= " AND (
        a.AssetTag LIKE :q1
        OR a.AssetName LIKE :q2
        OR ms.MaintenanceType LIKE :q3
        OR ms.Status LIKE :q4
    )";
    $like = '%'.$search.'%';
    $params[':q1'] = $like;
    $params[':q2'] = $like;
    $params[':q3'] = $like;
    $params[':q4'] = $like;
  }

  if ($activeFilter !== '') {
    $sql .= " AND ms.IsActive = :active";
    $params[':active'] = (int)$activeFilter;
  }

  $sql .= " ORDER BY ms.NextDueDate ASC, a.AssetTag";

  $st = $conn->prepare($sql);
  $st->execute($params);
  $rows = $st->fetchAll();

}catch(PDOException $e){ 
  $rows=array(); 
  $msg="Load list failed: ".h($e->getMessage()); 
  $msg_type='danger'; 
}

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
  .page-title i{ font-size:1.4rem; color:#4f46e5; }

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
    background:linear-gradient(135deg,#6366f1,#4f46e5);
    color:#fff!important;
    border:none;
  }
  .btn-brand:hover{ filter:brightness(1.08); }
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

  .status-pill{
    font-size:.75rem;
    border-radius:999px;
    padding:3px 9px;
    display:inline-block;
  }
  .status-pill-pending{
    background:#fef3c7;
    color:#92400e;
    border:1px solid #fde68a;
  }
  .status-pill-completed{
    background:#ecfdf5;
    color:#166534;
    border:1px solid #bbf7d0;
  }
  .status-pill-overdue{
    background:#fee2e2;
    color:#b91c1c;
    border:1px solid #fecaca;
  }

  @media (max-width:575.98px){
    .page-wrap{ margin-top:16px; }
  }
</style>

<div class="page-wrap">

  <!-- Header -->
  <div class="d-flex flex-wrap align-items-center justify-content-between mb-3 gap-2">
    <h1 class="page-title mb-0">
      <i class="bi bi-tools"></i>
      <span>Maintenance Schedules</span>
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
            <i class="bi bi-pencil-square me-1 text-primary"></i>Edit Schedule
          </h5>
          <a class="btn btn-muted btn-sm" href="<?php echo h($self); ?>">
            <i class="bi bi-x-circle me-1"></i>Cancel
          </a>
        </div>

        <form method="post" accept-charset="UTF-8">
          <input type="hidden" name="csrf" value="<?php echo h($CSRF); ?>">
          <input type="hidden" name="act" value="update">
          <input type="hidden" name="ScheduleID" value="<?php echo (int)$editRow['ScheduleID']; ?>">

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
                <i class="bi bi-wrench"></i> Type
              </label>
              <input type="text" name="MaintenanceType" class="form-control" maxlength="50" required
                     value="<?php echo h($editRow['MaintenanceType']); ?>" placeholder="e.g. Battery Charging">
            </div>

            <div class="col-12 col-md-4">
              <label class="form-label">
                <i class="bi bi-calendar-event"></i> Next Due
              </label>
              <input type="datetime-local" name="NextDueDate" class="form-control"
                     value="<?php echo ($editRow['NextDueDate']?date('Y-m-d\TH:i', strtotime($editRow['NextDueDate'])):''); ?>">
            </div>

            <div class="col-12 col-md-4">
              <label class="form-label">
                <i class="bi bi-arrow-repeat"></i> Frequency (days)
              </label>
              <input type="number" name="FrequencyDays" class="form-control"
                     value="<?php echo h($editRow['FrequencyDays']); ?>" min="0" placeholder="e.g. 30">
            </div>

            <div class="col-12 col-md-4">
              <label class="form-label">
                <i class="bi bi-calendar-check"></i> Last Performed
              </label>
              <input type="datetime-local" name="LastPerformedDate" class="form-control"
                     value="<?php echo ($editRow['LastPerformedDate']?date('Y-m-d\TH:i', strtotime($editRow['LastPerformedDate'])):''); ?>">
            </div>

            <div class="col-12 col-md-4 d-flex align-items-center">
              <div class="form-check me-3">
                <input class="form-check-input" type="checkbox" name="IsActive" id="isActiveEdit"
                       <?php echo ((int)$editRow['IsActive']===1?'checked':''); ?>>
                <label class="form-check-label" for="isActiveEdit">Active</label>
              </div>
              <input type="text" name="Status" class="form-control" maxlength="50"
                     value="<?php echo h($editRow['Status']); ?>" placeholder="e.g. Pending">
            </div>

            <div class="col-12">
              <div class="text-muted small">
                <i class="bi bi-clock-history me-1"></i>
                Created:
                <span class="badge-soft"><?php echo h($editRow['CreatedAt']); ?></span>
                <span class="badge-soft">
                  <?php echo h(isset($editRow['CreatedByName']) ? $editRow['CreatedByName'] : ''); ?>
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
            <i class="bi bi-plus-circle me-1 text-success"></i>Add Schedule
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
              <i class="bi bi-wrench"></i> Type
            </label>
            <input type="text" name="MaintenanceType" class="form-control" maxlength="50" required placeholder="Battery Charging">
          </div>

          <div class="col-12 col-md-4">
            <label class="form-label">
              <i class="bi bi-calendar-event"></i> Next Due
            </label>
            <input type="datetime-local" name="NextDueDate" class="form-control" placeholder="optional">
          </div>

          <div class="col-12 col-md-4">
            <label class="form-label">
              <i class="bi bi-arrow-repeat"></i> Frequency (days)
            </label>
            <input type="number" name="FrequencyDays" class="form-control" min="0" placeholder="e.g. 30">
          </div>

          <div class="col-12 col-md-4">
            <label class="form-label">
              <i class="bi bi-calendar-check"></i> Last Performed
            </label>
            <input type="datetime-local" name="LastPerformedDate" class="form-control" placeholder="optional">
          </div>

          <div class="col-12 col-md-4 d-flex align-items-center">
            <div class="form-check me-3">
              <input class="form-check-input" type="checkbox" name="IsActive" id="isActiveCreate" checked>
              <label class="form-check-label" for="isActiveCreate">Active</label>
            </div>
            <input type="text" name="Status" class="form-control" maxlength="50" placeholder="Pending">
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
          <i class="bi bi-list-ul me-1"></i>All Schedules
        </h5>
        <span class="text-muted small">
          <i class="bi bi-collection me-1"></i>Total: <?php echo count($rows); ?>
        </span>
      </div>

      <!-- Search + Active filter -->
      <form method="get" class="mb-3" accept-charset="UTF-8" id="filterForm">
        <div class="row g-2 align-items-end">
          <div class="col-12 col-md-5">
            <label class="form-label">
              <i class="bi bi-search"></i> Search
            </label>
            <div class="input-group">
              <span class="input-group-text">
                <i class="bi bi-search"></i>
              </span>
              <input type="text"
                     name="q"
                     id="searchBox"
                     class="form-control"
                     placeholder="Search asset/tag, type, status..."
                     value="<?php echo h($search); ?>">
            </div>
          </div>

          <div class="col-6 col-md-3">
            <label class="form-label">
              <i class="bi bi-toggle-on"></i> Active
            </label>
            <select name="active" class="form-select" onchange="this.form.submit()">
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
              <th>Type</th>
              <th>Next Due</th>
              <th>Freq (days)</th>
              <th>Last Done</th>
              <th>Status</th>
              <th>Active</th>
              <th>Created</th>
              <th class="text-end">Actions</th>
            </tr>
          </thead>
          <tbody>
          <?php foreach($rows as $r): ?>
            <tr>
              <td><?php echo (int)$r['ScheduleID']; ?></td>
              <td>
                <div class="fw-semibold"><?php echo h($r['AssetTag']); ?></div>
                <div class="text-muted small"><?php echo h($r['AssetName']); ?></div>
              </td>
              <td><?php echo h($r['MaintenanceType']); ?></td>
              <td><?php echo h($r['NextDueDate']); ?></td>
              <td><?php echo h($r['FrequencyDays']); ?></td>
              <td><?php echo h($r['LastPerformedDate']); ?></td>
              <td>
                <?php
                  $statusText = trim($r['Status']);
                  $statusClass = 'status-pill';
                  $lc = strtolower($statusText);
                  if ($lc === 'completed' || $lc === 'done') {
                    $statusClass .= ' status-pill-completed';
                  } elseif ($lc === 'overdue') {
                    $statusClass .= ' status-pill-overdue';
                  } else {
                    $statusClass .= ' status-pill-pending';
                  }
                ?>
                <span class="<?php echo $statusClass; ?>">
                  <?php echo h($statusText===''?'Pending':$statusText); ?>
                </span>
              </td>
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
              <td><?php echo h($r['CreatedAt']); ?></td>
              <td class="text-end">
                <div class="action-stack">
                  <a class="btn btn-muted btn-sm w-100 w-md-auto"
                     href="<?php echo h($self); ?>?edit=<?php echo (int)$r['ScheduleID']; ?>"
                     title="Edit schedule">
                    <i class="bi bi-pencil"></i>
                  </a>

                  <form method="post" class="d-inline"
                        onsubmit="return confirm('Delete this schedule permanently?');"
                        accept-charset="UTF-8">
                    <input type="hidden" name="csrf" value="<?php echo h($CSRF); ?>">
                    <input type="hidden" name="act" value="delete">
                    <input type="hidden" name="ScheduleID" value="<?php echo (int)$r['ScheduleID']; ?>">
                    <button class="btn btn-danger-soft btn-sm w-100 w-md-auto" type="submit"
                            title="Delete schedule">
                      <i class="bi bi-trash"></i>
                    </button>
                  </form>
                </div>
              </td>
            </tr>
          <?php endforeach; ?>
          <?php if (empty($rows)): ?>
            <tr><td colspan="10" class="text-center text-muted py-4">No data</td></tr>
          <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>

</div><!-- /.page-wrap -->

<script type="text/javascript">
// auto search submit on typing
(function(){
  var input = document.getElementById('searchBox');
  if (!input) return;
  var form = document.getElementById('filterForm');
  if (!form) return;
  var timer = null;
  input.onkeyup = function(){
    if (timer) { clearTimeout(timer); }
    timer = setTimeout(function(){
      form.submit();
    }, 600); // 0.6 second delay
  };
})();
</script>

<?php require_once __DIR__ . '/../../include/footer.php'; ?>
