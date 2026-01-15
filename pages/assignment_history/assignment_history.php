<?php
/**********************************************
 * Assignment History - Full CRUD (raw PHP)
 * Table: dbo.AssignmentHistory
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
function to_sql_datetime($s) {
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
  $assets = $conn->query("SELECT AssetID, AssetTag, AssetName FROM dbo.Assets ORDER BY AssetTag")->fetchAll();
} catch (PDOException $e) { $assets=[]; }

try {
  $employees = $conn->query("
    SELECT EmployeeID, FirstName + ' ' + ISNULL(LastName,'') AS EmpName
    FROM dbo.Employees
    ORDER BY FirstName, LastName
  ")->fetchAll();
} catch (PDOException $e) { $employees=[]; }

try {
  $locations = $conn->query("SELECT LocationID, LocationName FROM dbo.Locations ORDER BY LocationName")->fetchAll();
} catch (PDOException $e) { $locations=[]; }

/* 5) Actions */
$msg=''; $msg_type='success';
if (isset($_GET['ok']) && $_GET['ok']==='1'){ $msg='History record created.'; }

$editRow = null;
$edit_id = isset($_GET['edit']) ? (int)$_GET['edit'] : 0;


/* CREATE */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (isset($_POST['act']) && $_POST['act'] === 'create')) {
    check_csrf();

    $AssetID           = isset($_POST['AssetID']) ? (int)$_POST['AssetID'] : 0;
    $EventType         = isset($_POST['EventType']) ? trim($_POST['EventType']) : '';
    $FromEmployeeID    = (isset($_POST['FromEmployeeID']) && $_POST['FromEmployeeID'] !== '') ? (int)$_POST['FromEmployeeID'] : null;
    $ToEmployeeID      = (isset($_POST['ToEmployeeID']) && $_POST['ToEmployeeID'] !== '') ? (int)$_POST['ToEmployeeID'] : null;
    $FromLocationID    = (isset($_POST['FromLocationID']) && $_POST['FromLocationID'] !== '') ? (int)$_POST['FromLocationID'] : null;
    $ToLocationID      = (isset($_POST['ToLocationID']) && $_POST['ToLocationID'] !== '') ? (int)$_POST['ToLocationID'] : null;
    $EventAt           = isset($_POST['EventAt']) ? to_sql_datetime($_POST['EventAt']) : null;
    $PerformedByUserID = isset($_SESSION['auth_user']['UserID']) ? (int)$_SESSION['auth_user']['UserID'] : null;
    $Notes             = isset($_POST['Notes']) ? trim($_POST['Notes']) : '';

  $errors=[];
  if ($AssetID<=0) $errors[]='Asset is required.';
  if ($EventType==='') $errors[]='Event type is required.';

  if (empty($errors)){
    try {
      $st = $conn->prepare("
        INSERT INTO dbo.AssignmentHistory
          (AssetID, EventType, FromEmployeeID, ToEmployeeID, FromLocationID, ToLocationID,
           EventAt, PerformedByUserID, Notes)
        VALUES
          (:asset, :etype, :fe, :te, :fl, :tl, :evt, :by_user, :notes)
      ");
      $st->bindValue(':asset', $AssetID, PDO::PARAM_INT);
      $st->bindValue(':etype', $EventType, PDO::PARAM_STR);

      if ($FromEmployeeID===null) $st->bindValue(':fe', null, PDO::PARAM_NULL); else $st->bindValue(':fe', $FromEmployeeID, PDO::PARAM_INT);
      if ($ToEmployeeID===null)   $st->bindValue(':te', null, PDO::PARAM_NULL); else $st->bindValue(':te', $ToEmployeeID, PDO::PARAM_INT);
      if ($FromLocationID===null) $st->bindValue(':fl', null, PDO::PARAM_NULL); else $st->bindValue(':fl', $FromLocationID, PDO::PARAM_INT);
      if ($ToLocationID===null)   $st->bindValue(':tl', null, PDO::PARAM_NULL); else $st->bindValue(':tl', $ToLocationID, PDO::PARAM_INT);

      $st->bindValue(':evt', $EventAt, $EventAt===null?PDO::PARAM_NULL:PDO::PARAM_STR);
      if ($PerformedByUserID===null) $st->bindValue(':by_user', null, PDO::PARAM_NULL); else $st->bindValue(':by_user', $PerformedByUserID, PDO::PARAM_INT);
      $st->bindValue(':notes', $Notes!==''?$Notes:null, $Notes!==''?PDO::PARAM_STR:PDO::PARAM_NULL);

      $st->execute();
      header('Location: '.$self.'?ok=1'); exit;
    } catch (PDOException $e) {
      $msg = "Create failed: ".h($e->getMessage()); $msg_type='danger';
    }
  } else { $msg = implode(' ', $errors); $msg_type='danger'; }
}

/* LOAD EDIT ROW */
if ($edit_id>0){
  try{
    $st = $conn->prepare("
      SELECT h.*,
             a.AssetTag, a.AssetName,
             fe.FirstName + ' ' + ISNULL(fe.LastName,'') AS FromEmpName,
             te.FirstName + ' ' + ISNULL(te.LastName,'') AS ToEmpName,
             fl.LocationName AS FromLocName,
             tl.LocationName AS ToLocName,
             u.Username AS PerformedByName
        FROM dbo.AssignmentHistory h
        JOIN dbo.Assets a ON a.AssetID = h.AssetID
        LEFT JOIN dbo.Employees fe ON fe.EmployeeID = h.FromEmployeeID
        LEFT JOIN dbo.Employees te ON te.EmployeeID = h.ToEmployeeID
        LEFT JOIN dbo.Locations fl ON fl.LocationID = h.FromLocationID
        LEFT JOIN dbo.Locations tl ON tl.LocationID = h.ToLocationID
        LEFT JOIN dbo.Users u ON u.UserID = h.PerformedByUserID
       WHERE h.HistoryID = :id
    ");
    $st->execute([':id'=>$edit_id]);
    $editRow = $st->fetch();
    if (!$editRow){ $msg="Row not found for edit."; $msg_type='danger'; $edit_id=0; }
  } catch (PDOException $e){ $msg="Load edit row failed: ".h($e->getMessage()); $msg_type='danger'; }
}

/* UPDATE */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (isset($_POST['act']) && $_POST['act'] === 'update')) {
    check_csrf();

    $HistoryID       = isset($_POST['HistoryID']) ? (int)$_POST['HistoryID'] : 0;
    $AssetID         = isset($_POST['AssetID']) ? (int)$_POST['AssetID'] : 0;
    $EventType       = isset($_POST['EventType']) ? trim($_POST['EventType']) : '';
    $FromEmployeeID  = (isset($_POST['FromEmployeeID']) && $_POST['FromEmployeeID'] !== '') ? (int)$_POST['FromEmployeeID'] : null;
    $ToEmployeeID    = (isset($_POST['ToEmployeeID']) && $_POST['ToEmployeeID'] !== '') ? (int)$_POST['ToEmployeeID'] : null;
    $FromLocationID  = (isset($_POST['FromLocationID']) && $_POST['FromLocationID'] !== '') ? (int)$_POST['FromLocationID'] : null;
    $ToLocationID    = (isset($_POST['ToLocationID']) && $_POST['ToLocationID'] !== '') ? (int)$_POST['ToLocationID'] : null;
    $EventAt         = isset($_POST['EventAt']) ? to_sql_datetime($_POST['EventAt']) : null;
    $Notes           = isset($_POST['Notes']) ? trim($_POST['Notes']) : '';

  $errors=[];
  if ($HistoryID<=0) $errors[]='Invalid row.';
  if ($AssetID<=0)  $errors[]='Asset is required.';
  if ($EventType==='') $errors[]='Event type is required.';

  if (empty($errors)){
    try{
      $st = $conn->prepare("
        UPDATE dbo.AssignmentHistory
           SET AssetID = :asset,
               EventType = :etype,
               FromEmployeeID = :fe,
               ToEmployeeID = :te,
               FromLocationID = :fl,
               ToLocationID = :tl,
               EventAt = :evt,
               Notes = :notes
         WHERE HistoryID = :id
      ");
      $st->bindValue(':asset', $AssetID, PDO::PARAM_INT);
      $st->bindValue(':etype', $EventType, PDO::PARAM_STR);
      if ($FromEmployeeID===null) $st->bindValue(':fe', null, PDO::PARAM_NULL); else $st->bindValue(':fe', $FromEmployeeID, PDO::PARAM_INT);
      if ($ToEmployeeID===null)   $st->bindValue(':te', null, PDO::PARAM_NULL); else $st->bindValue(':te', $ToEmployeeID, PDO::PARAM_INT);
      if ($FromLocationID===null) $st->bindValue(':fl', null, PDO::PARAM_NULL); else $st->bindValue(':fl', $FromLocationID, PDO::PARAM_INT);
      if ($ToLocationID===null)   $st->bindValue(':tl', null, PDO::PARAM_NULL); else $st->bindValue(':tl', $ToLocationID, PDO::PARAM_INT);
      $st->bindValue(':evt', $EventAt, $EventAt===null?PDO::PARAM_NULL:PDO::PARAM_STR);
      $st->bindValue(':notes', $Notes!==''?$Notes:null, $Notes!==''?PDO::PARAM_STR:PDO::PARAM_NULL);
      $st->bindValue(':id', $HistoryID, PDO::PARAM_INT);
      $st->execute();
      header('Location: '.$self); exit;
    } catch (PDOException $e){
      $msg="Update failed: ".h($e->getMessage()); $msg_type='danger';
    }
  } else { $msg=implode(' ', $errors); $msg_type='danger'; }
}

/* DELETE */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (isset($_POST['act']) && $_POST['act'] === 'delete')) {
    check_csrf();
    $id = isset($_POST['HistoryID']) ? (int)$_POST['HistoryID'] : 0;

    if ($id > 0) {
        try {
            $stmt = $conn->prepare("DELETE FROM dbo.AssignmentHistory WHERE HistoryID = :id");
            $stmt->execute([':id' => $id]);
            $msg = "History row deleted.";
            $msg_type = 'success';
        } catch (PDOException $e) {
            $msg = "Delete failed: " . h($e->getMessage());
            $msg_type = 'danger';
        }
    }
}


/* 6) List + Search */
$search = isset($_GET['q']) ? trim($_GET['q']) : '';

try{
  if ($search!==''){
    $st = $conn->prepare("
      SELECT h.HistoryID, h.AssetID, a.AssetTag, a.AssetName, h.EventType,
             h.FromEmployeeID, fe.FirstName + ' ' + ISNULL(fe.LastName,'') AS FromEmpName,
             h.ToEmployeeID,   te.FirstName + ' ' + ISNULL(te.LastName,'') AS ToEmpName,
             h.FromLocationID, fl.LocationName AS FromLocName,
             h.ToLocationID,   tl.LocationName AS ToLocName,
             h.EventAt, h.PerformedByUserID, u.Username AS PerformedByName,
             h.Notes
        FROM dbo.AssignmentHistory h
        JOIN dbo.Assets a ON a.AssetID = h.AssetID
        LEFT JOIN dbo.Employees fe ON fe.EmployeeID = h.FromEmployeeID
        LEFT JOIN dbo.Employees te ON te.EmployeeID = h.ToEmployeeID
        LEFT JOIN dbo.Locations fl ON fl.LocationID = h.FromLocationID
        LEFT JOIN dbo.Locations tl ON tl.LocationID = h.ToLocationID
        LEFT JOIN dbo.Users u ON u.UserID = h.PerformedByUserID
       WHERE a.AssetTag LIKE :q
          OR a.AssetName LIKE :q
          OR h.EventType LIKE :q
          OR fl.LocationName LIKE :q
          OR tl.LocationName LIKE :q
          OR fe.FirstName LIKE :q OR fe.LastName LIKE :q
          OR te.FirstName LIKE :q OR te.LastName LIKE :q
       ORDER BY h.EventAt DESC, a.AssetTag
    ");
    $st->execute([':q'=>'%'.$search.'%']);
    $rows = $st->fetchAll();
  } else {
    $rows = $conn->query("
      SELECT h.HistoryID, h.AssetID, a.AssetTag, a.AssetName, h.EventType,
             h.FromEmployeeID, fe.FirstName + ' ' + ISNULL(fe.LastName,'') AS FromEmpName,
             h.ToEmployeeID,   te.FirstName + ' ' + ISNULL(te.LastName,'') AS ToEmpName,
             h.FromLocationID, fl.LocationName AS FromLocName,
             h.ToLocationID,   tl.LocationName AS ToLocName,
             h.EventAt, h.PerformedByUserID, u.Username AS PerformedByName,
             h.Notes
        FROM dbo.AssignmentHistory h
        JOIN dbo.Assets a ON a.AssetID = h.AssetID
        LEFT JOIN dbo.Employees fe ON fe.EmployeeID = h.FromEmployeeID
        LEFT JOIN dbo.Employees te ON te.EmployeeID = h.ToEmployeeID
        LEFT JOIN dbo.Locations fl ON fl.LocationID = h.FromLocationID
        LEFT JOIN dbo.Locations tl ON tl.LocationID = h.ToLocationID
        LEFT JOIN dbo.Users u ON u.UserID = h.PerformedByUserID
       ORDER BY h.EventAt DESC, a.AssetTag
    ")->fetchAll();
  }
}catch(PDOException $e){ $rows=[]; $msg="Load list failed: ".h($e->getMessage()); $msg_type='danger'; }

/* 7) Render */
require_once __DIR__ . '/../../include/header.php';
?>
<style>
  .page-wrap{ margin:28px auto; padding:0 12px; }
  .page-title{ font-weight:700; letter-spacing:.2px; }
  .card-elevated{ border:1px solid #e5e7eb; border-radius:12px; box-shadow:0 8px 24px rgba(2,6,23,.06); }
  .badge-soft{ border:1px solid #e2e8f0; background:#f8fafc; border-radius:999px; padding:4px 10px; font-size:12px; }
  .btn-brand{ background:#2563eb; color:#fff!important; border:none; }
  .btn-brand:hover{ background:#1d4ed8; }
  .btn-muted{ background:#e5e7eb; color:#111827!important; border:none; }
  .btn-danger-soft{ background:#fee2e2; color:#b91c1c!important; border:1px solid #fecaca; }
  .form-label{ font-weight:600; color:#374151; }
  .form-control, .form-select{ border-radius:10px; border-color:#cbd5e1; }
  .action-stack>*{ margin:4px; }
  @media(min-width:768px){ .action-stack{ display:inline-flex; gap:6px; } }
  .table thead th{ background:#f8fafc; color:#334155; border-bottom:1px solid #e5e7eb; }
  .table tbody td{ vertical-align:middle; }
</style>

<div class="page-wrap">

  <!-- Header / Search -->
  <div class="d-flex flex-wrap align-items-center justify-content-between mb-3 gap-2">
    <h1 class="page-title mb-0">Assignment History</h1>

    <form method="get" class="w-100 w-md-auto" accept-charset="UTF-8">
      <div class="input-group">
        <input type="text" name="q" class="form-control" placeholder="Search asset/event/employee/location..."
               value="<?php echo h($search); ?>">
        <button class="btn btn-brand" type="submit">Search</button>
        <a class="btn btn-muted" href="<?php echo h($self); ?>">Reset</a>
      </div>
    </form>
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
          <h5 class="mb-0">Edit History</h5>
          <a class="btn btn-muted btn-sm" href="<?php echo h($self); ?>">Cancel</a>
        </div>

        <form method="post" accept-charset="UTF-8">
          <input type="hidden" name="csrf" value="<?php echo h($CSRF); ?>">
          <input type="hidden" name="act" value="update">
          <input type="hidden" name="HistoryID" value="<?php echo (int)$editRow['HistoryID']; ?>">

          <div class="row g-3">
            <div class="col-12 col-md-4">
              <label class="form-label">Asset</label>
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
              <label class="form-label">Event Type</label>
              <input type="text" name="EventType" class="form-control" maxlength="50" required
                     value="<?php echo h($editRow['EventType']); ?>" placeholder="e.g. Assigned/Returned/Transfer">
            </div>

            <div class="col-12 col-md-4">
              <label class="form-label">Event At</label>
              <input type="datetime-local" name="EventAt" class="form-control"
                     value="<?php echo ($editRow['EventAt']?date('Y-m-d\TH:i', strtotime($editRow['EventAt'])):''); ?>">
            </div>

            <div class="col-12 col-md-6">
              <label class="form-label">From Employee (optional)</label>
              <select name="FromEmployeeID" class="form-select">
                <option value="">-- None --</option>
                <?php foreach($employees as $e): ?>
                  <option value="<?php echo (int)$e['EmployeeID']; ?>"
                    <?php echo ((int)(isset($editRow['FromEmployeeID']) ? $editRow['FromEmployeeID'] : 0) === (int)$e['EmployeeID'] ? 'selected' : ''); ?>

                    <?php echo h($e['EmpName']); ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>

            <div class="col-12 col-md-6">
              <label class="form-label">To Employee (optional)</label>
              <select name="ToEmployeeID" class="form-select">
                <option value="">-- None --</option>
                <?php foreach($employees as $e): ?>
                  <option value="<?php echo (int)$e['EmployeeID']; ?>"
                    <?php echo ((int)(isset($editRow['ToEmployeeID']) ? $editRow['ToEmployeeID'] : 0) === (int)$e['EmployeeID'] ? 'selected' : ''); ?>

                    <?php echo h($e['EmpName']); ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>

            <div class="col-12 col-md-6">
              <label class="form-label">From Location (optional)</label>
              <select name="FromLocationID" class="form-select">
                <option value="">-- None --</option>
                <?php foreach($locations as $l): ?>
                  <option value="<?php echo (int)$l['LocationID']; ?>"
                    <?php echo ((int)(isset($editRow['FromLocationID']) ? $editRow['FromLocationID'] : 0) === (int)$l['LocationID'] ? 'selected' : ''); ?>

                    <?php echo h($l['LocationName']); ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>

            <div class="col-12 col-md-6">
              <label class="form-label">To Location (optional)</label>
              <select name="ToLocationID" class="form-select">
                <option value="">-- None --</option>
                <?php foreach($locations as $l): ?>
                  <option value="<?php echo (int)$l['LocationID']; ?>"
                   <?php echo ((int)(isset($editRow['ToLocationID']) ? $editRow['ToLocationID'] : 0) === (int)$l['LocationID'] ? 'selected' : ''); ?>

                    <?php echo h($l['LocationName']); ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>

            <div class="col-12">
              <label class="form-label">Notes (optional)</label>
              <input type="text" name="Notes" class="form-control" maxlength="1000"
                     value="<?php echo h($editRow['Notes']); ?>">
            </div>

            <div class="col-12 d-grid d-md-inline">
              <button class="btn btn-brand w-100 w-md-auto">Update</button>
            </div>
          </div>
        </form>

      <?php else: ?>
        <h5 class="mb-3">Add History</h5>
        <form method="post" class="row g-3" accept-charset="UTF-8">
          <input type="hidden" name="csrf" value="<?php echo h($CSRF); ?>">
          <input type="hidden" name="act" value="create">

          <div class="col-12 col-md-4">
            <label class="form-label">Asset</label>
            <select name="AssetID" class="form-select" required>
              <option value="">-- Select --</option>
              <?php foreach($assets as $a): ?>
                <option value="<?php echo (int)$a['AssetID']; ?>"><?php echo h($a['AssetTag'].' — '.$a['AssetName']); ?></option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="col-12 col-md-4">
            <label class="form-label">Event Type</label>
            <input type="text" name="EventType" class="form-control" maxlength="50" required placeholder="Assigned/Returned/Transfer">
          </div>

          <div class="col-12 col-md-4">
            <label class="form-label">Event At</label>
            <input type="datetime-local" name="EventAt" class="form-control" placeholder="optional">
          </div>

          <div class="col-12 col-md-6">
            <label class="form-label">From Employee (optional)</label>
            <select name="FromEmployeeID" class="form-select">
              <option value="">-- None --</option>
              <?php foreach($employees as $e): ?>
                <option value="<?php echo (int)$e['EmployeeID']; ?>"><?php echo h($e['EmpName']); ?></option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="col-12 col-md-6">
            <label class="form-label">To Employee (optional)</label>
            <select name="ToEmployeeID" class="form-select">
              <option value="">-- None --</option>
              <?php foreach($employees as $e): ?>
                <option value="<?php echo (int)$e['EmployeeID']; ?>"><?php echo h($e['EmpName']); ?></option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="col-12 col-md-6">
            <label class="form-label">From Location (optional)</label>
            <select name="FromLocationID" class="form-select">
              <option value="">-- None --</option>
              <?php foreach($locations as $l): ?>
                <option value="<?php echo (int)$l['LocationID']; ?>"><?php echo h($l['LocationName']); ?></option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="col-12 col-md-6">
            <label class="form-label">To Location (optional)</label>
            <select name="ToLocationID" class="form-select">
              <option value="">-- None --</option>
              <?php foreach($locations as $l): ?>
                <option value="<?php echo (int)$l['LocationID']; ?>"><?php echo h($l['LocationName']); ?></option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="col-12">
            <label class="form-label">Notes (optional)</label>
            <input type="text" name="Notes" class="form-control" maxlength="1000">
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
        <h5 class="mb-0">All History</h5>
        <span class="text-muted small">Total: <?php echo count($rows); ?></span>
      </div>

      <div class="table-responsive">
        <table class="table table-hover align-middle">
          <thead>
            <tr>
              <th>#</th>
              <th>Asset</th>
              <th>Event</th>
              <th>From</th>
              <th>To</th>
              <th>Event At</th>
              <th>By</th>
              <th>Notes</th>
              <th class="text-end">Actions</th>
            </tr>
          </thead>
          <tbody>
          <?php foreach($rows as $r): ?>
            <tr>
              <td><?php echo (int)$r['HistoryID']; ?></td>
              <td><?php echo h($r['AssetTag']); ?></td>
              <td><?php echo h($r['EventType']); ?></td>
              <td>
                <?php
                  $from = trim(($r['FromEmpName'] ? $r['FromEmpName'] : '').($r['FromLocName'] ? ' @ '.$r['FromLocName'] : ''));
                  echo h($from);
                ?>
              </td>
              <td>
                <?php
                  $to = trim(($r['ToEmpName'] ? $r['ToEmpName'] : '').($r['ToLocName'] ? ' @ '.$r['ToLocName'] : ''));
                  echo h($to);
                ?>
              </td>
              <td><?php echo h($r['EventAt']); ?></td>
              <td><?php echo h($r['PerformedByName']); ?></td>
              <td><?php echo h($r['Notes']); ?></td>
              <td class="text-end">
                <div class="action-stack">
                  <a class="btn btn-muted btn-sm w-100 w-md-auto"
                     href="<?php echo h($self); ?>?edit=<?php echo (int)$r['HistoryID']; ?>">Edit</a>

                  <form method="post" class="d-inline" onsubmit="return confirm('Delete this history row permanently?');" accept-charset="UTF-8">
                    <input type="hidden" name="csrf" value="<?php echo h($CSRF); ?>">
                    <input type="hidden" name="act" value="delete">
                    <input type="hidden" name="HistoryID" value="<?php echo (int)$r['HistoryID']; ?>">
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

</div><!-- /.page-wrap -->

<?php require_once __DIR__ . '/../../include/footer.php'; ?>
