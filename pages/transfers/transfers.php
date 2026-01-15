<?php 
/**********************************************
 * Transfers - Full CRUD (raw PHP)
 * Table: dbo.Transfers
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
  try { 
    $dt = new DateTime($s); 
    return $dt->format('Y-m-d H:i:s'); 
  }
  catch (Exception $e) {  // PHP 5.6 friendly
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
} catch (PDOException $e) { $assets = array(); }

try {
  $locations = $conn->query("
    SELECT LocationID, LocationName
    FROM dbo.Locations
    ORDER BY LocationName
  ")->fetchAll();
} catch (PDOException $e) { $locations = array(); }

/* 5) Actions */
$msg=''; 
$msg_type='success';
if (isset($_GET['ok']) && $_GET['ok']==='1'){ $msg='Transfer created.'; }

$editRow = null;
$edit_id = isset($_GET['edit']) ? (int)$_GET['edit'] : 0;


/* CREATE */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (isset($_POST['act']) ? $_POST['act'] : '') === 'create') {
    check_csrf();

    $AssetID = isset($_POST['AssetID']) ? (int)$_POST['AssetID'] : 0;
    $FromLocationID = (isset($_POST['FromLocationID']) && $_POST['FromLocationID'] !== '') ? (int)$_POST['FromLocationID'] : null;
    $ToLocationID   = (isset($_POST['ToLocationID']) && $_POST['ToLocationID']   !== '') ? (int)$_POST['ToLocationID']   : null;
    $TransferDate   = to_sql_datetime(isset($_POST['TransferDate']) ? $_POST['TransferDate'] : '');
    $PerformedByUserID = isset($_SESSION['auth_user']['UserID']) ? (int)$_SESSION['auth_user']['UserID'] : null;
    $Notes = isset($_POST['Notes']) ? trim($_POST['Notes']) : '';

    $errors=array();
    if ($AssetID<=0) $errors[]='Asset is required.';
    if ($FromLocationID!==null && $ToLocationID!==null && $FromLocationID===$ToLocationID) $errors[]='From & To location cannot be the same.';

    if (empty($errors)){
        try {
          $st = $conn->prepare("
            INSERT INTO dbo.Transfers
              (AssetID, FromLocationID, ToLocationID, TransferDate, PerformedByUserID, Notes, CreatedAt, CreatedBy)
            VALUES
              (:asset, :from_loc, :to_loc, :tdate, :by_perf, :notes, GETDATE(), :by_created)
          ");
          $st->bindValue(':asset', $AssetID, PDO::PARAM_INT);

          if ($FromLocationID===null) $st->bindValue(':from_loc', null, PDO::PARAM_NULL); else $st->bindValue(':from_loc', $FromLocationID, PDO::PARAM_INT);
          if ($ToLocationID===null)   $st->bindValue(':to_loc',   null, PDO::PARAM_NULL); else $st->bindValue(':to_loc',   $ToLocationID,   PDO::PARAM_INT);
          $st->bindValue(':tdate', $TransferDate, $TransferDate===null?PDO::PARAM_NULL:PDO::PARAM_STR);

          if ($PerformedByUserID===null) {
            $st->bindValue(':by_perf',   null, PDO::PARAM_NULL);
            $st->bindValue(':by_created',null, PDO::PARAM_NULL);
          } else {
            $st->bindValue(':by_perf',   $PerformedByUserID, PDO::PARAM_INT);
            $st->bindValue(':by_created',$PerformedByUserID, PDO::PARAM_INT);
          }

          $st->bindValue(':notes', $Notes!==''?$Notes:null, $Notes!==''?PDO::PARAM_STR:PDO::PARAM_NULL);

          $st->execute();
          header('Location: '.$self.'?ok=1'); 
          exit;
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
      SELECT t.*,
             a.AssetTag, a.AssetName,
             fl.LocationName AS FromLocName,
             tl.LocationName AS ToLocName,
             u.Username AS PerformedByName
        FROM dbo.Transfers t
        JOIN dbo.Assets a       ON a.AssetID = t.AssetID
        LEFT JOIN dbo.Locations fl ON fl.LocationID = t.FromLocationID
        LEFT JOIN dbo.Locations tl ON tl.LocationID = t.ToLocationID
        LEFT JOIN dbo.Users u   ON u.UserID = t.PerformedByUserID
       WHERE t.TransferID = :id
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
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (isset($_POST['act']) ? $_POST['act'] : '') === 'update') {
    check_csrf();

    $TransferID     = isset($_POST['TransferID']) ? (int)$_POST['TransferID'] : 0;
    $AssetID        = isset($_POST['AssetID']) ? (int)$_POST['AssetID'] : 0;
    $FromLocationID = (isset($_POST['FromLocationID']) && $_POST['FromLocationID'] !== '') ? (int)$_POST['FromLocationID'] : null;
    $ToLocationID   = (isset($_POST['ToLocationID'])   && $_POST['ToLocationID']   !== '') ? (int)$_POST['ToLocationID']   : null;
    $TransferDate   = to_sql_datetime(isset($_POST['TransferDate']) ? $_POST['TransferDate'] : '');
    $Notes          = isset($_POST['Notes']) ? trim($_POST['Notes']) : '';

    $errors=array();
    if ($TransferID<=0) $errors[]='Invalid row.';
    if ($AssetID<=0) $errors[]='Asset is required.';
    if ($FromLocationID!==null && $ToLocationID!==null && $FromLocationID===$ToLocationID) $errors[]='From & To location cannot be the same.';

    if (empty($errors)){
      try{
        $st = $conn->prepare("
          UPDATE dbo.Transfers
             SET AssetID = :asset,
                 FromLocationID = :from_loc,
                 ToLocationID   = :to_loc,
                 TransferDate   = :tdate,
                 Notes          = :notes
           WHERE TransferID = :id
        ");
        $st->bindValue(':asset', $AssetID, PDO::PARAM_INT);
        if ($FromLocationID===null) $st->bindValue(':from_loc', null, PDO::PARAM_NULL); else $st->bindValue(':from_loc', $FromLocationID, PDO::PARAM_INT);
        if ($ToLocationID===null)   $st->bindValue(':to_loc',   null, PDO::PARAM_NULL); else $st->bindValue(':to_loc',   $ToLocationID,   PDO::PARAM_INT);
        $st->bindValue(':tdate', $TransferDate, $TransferDate===null?PDO::PARAM_NULL:PDO::PARAM_STR);
        $st->bindValue(':notes', $Notes!==''?$Notes:null, $Notes!==''?PDO::PARAM_STR:PDO::PARAM_NULL);
        $st->bindValue(':id', $TransferID, PDO::PARAM_INT);
        $st->execute();
        header('Location: '.$self); 
        exit;
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
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (isset($_POST['act']) ? $_POST['act'] : '') === 'delete') {
    check_csrf();
    $id = isset($_POST['TransferID']) ? (int)$_POST['TransferID'] : 0;

    if ($id > 0) {
        try {
            $stmt = $conn->prepare("DELETE FROM dbo.Transfers WHERE TransferID = :id");
            $stmt->execute(array(':id' => $id));
            $msg = "Transfer deleted.";
            $msg_type = 'success';
        } catch (PDOException $e) {
            $msg = "Delete failed: " . h($e->getMessage());
            $msg_type = 'danger';
        }
    }
}


/* 6) List + Search + Filters (From/To Location) */
$search      = isset($_GET['q']) ? trim($_GET['q']) : '';
$fromFilter  = isset($_GET['from_loc']) ? (int)$_GET['from_loc'] : 0;
$toFilter    = isset($_GET['to_loc'])   ? (int)$_GET['to_loc']   : 0;

try{
  $sql = "
      SELECT t.TransferID, t.AssetID, a.AssetTag, a.AssetName,
             t.FromLocationID, fl.LocationName AS FromLocName,
             t.ToLocationID,   tl.LocationName AS ToLocName,
             t.TransferDate, t.PerformedByUserID, u.Username AS PerformedByName,
             t.Notes, t.CreatedAt
        FROM dbo.Transfers t
        JOIN dbo.Assets a       ON a.AssetID = t.AssetID
        LEFT JOIN dbo.Locations fl ON fl.LocationID = t.FromLocationID
        LEFT JOIN dbo.Locations tl ON tl.LocationID = t.ToLocationID
        LEFT JOIN dbo.Users u   ON u.UserID = t.PerformedByUserID
       WHERE 1=1
  ";

  $params = array();

  if ($search !== '') {
    $sql .= " AND (
        a.AssetTag      LIKE :q1
        OR a.AssetName  LIKE :q2
        OR fl.LocationName LIKE :q3
        OR tl.LocationName LIKE :q4
    )";
    $like = '%'.$search.'%';
    $params[':q1'] = $like;
    $params[':q2'] = $like;
    $params[':q3'] = $like;
    $params[':q4'] = $like;
  }

  if ($fromFilter > 0) {
    $sql .= " AND t.FromLocationID = :from_loc";
    $params[':from_loc'] = $fromFilter;
  }

  if ($toFilter > 0) {
    $sql .= " AND t.ToLocationID = :to_loc";
    $params[':to_loc'] = $toFilter;
  }

  $sql .= " ORDER BY t.TransferDate DESC, a.AssetTag";

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

  @media (max-width:575.98px){
    .page-wrap{ margin-top:16px; }
  }
</style>

<div class="page-wrap">

  <!-- Header -->
  <div class="d-flex flex-wrap align-items-center justify-content-between mb-3 gap-2">
    <h1 class="page-title mb-0">
      <i class="bi bi-arrow-left-right"></i>
      <span>Transfers</span>
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
            <i class="bi bi-pencil-square me-1 text-primary"></i>Edit Transfer
          </h5>
          <a class="btn btn-muted btn-sm" href="<?php echo h($self); ?>">
            <i class="bi bi-x-circle me-1"></i>Cancel
          </a>
        </div>

        <form method="post" accept-charset="UTF-8">
          <input type="hidden" name="csrf" value="<?php echo h($CSRF); ?>">
          <input type="hidden" name="act" value="update">
          <input type="hidden" name="TransferID" value="<?php echo (int)$editRow['TransferID']; ?>">

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
                <i class="bi bi-geo-alt"></i> From Location (optional)
              </label>
              <select name="FromLocationID" class="form-select">
                <option value="">-- None --</option>
                <?php foreach($locations as $l): ?>
                  <option value="<?php echo (int)$l['LocationID']; ?>"
                    <?php 
                      $fromId = isset($editRow['FromLocationID']) ? (int)$editRow['FromLocationID'] : 0;
                      echo ($fromId === (int)$l['LocationID'] ? 'selected' : ''); 
                    ?>>
                    <?php echo h($l['LocationName']); ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>

            <div class="col-12 col-md-4">
              <label class="form-label">
                <i class="bi bi-geo-alt-fill"></i> To Location (optional)
              </label>
              <select name="ToLocationID" class="form-select">
                <option value="">-- None --</option>
                <?php foreach($locations as $l): ?>
                  <option value="<?php echo (int)$l['LocationID']; ?>"
                    <?php 
                      $toId = isset($editRow['ToLocationID']) ? (int)$editRow['ToLocationID'] : 0;
                      echo ($toId === (int)$l['LocationID'] ? 'selected' : ''); 
                    ?>>
                    <?php echo h($l['LocationName']); ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>

            <div class="col-12 col-md-4">
              <label class="form-label">
                <i class="bi bi-calendar-check"></i> Transfer Date
              </label>
              <input type="datetime-local" name="TransferDate" class="form-control"
                     value="<?php echo ($editRow['TransferDate']?date('Y-m-d\TH:i', strtotime($editRow['TransferDate'])):''); ?>">
            </div>

            <div class="col-12">
              <label class="form-label">
                <i class="bi bi-journal-text"></i> Notes (optional)
              </label>
              <input type="text" name="Notes" class="form-control" maxlength="500"
                     value="<?php echo h($editRow['Notes']); ?>">
            </div>

            <div class="col-12">
              <div class="text-muted small">
                <i class="bi bi-clock-history me-1"></i>
                Created:
                <span class="badge-soft"><?php echo h($editRow['CreatedAt']); ?></span>
                <span class="badge-soft">
                  <?php echo h(isset($editRow['PerformedByName']) ? $editRow['PerformedByName'] : ''); ?>
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
            <i class="bi bi-plus-circle me-1 text-success"></i>Add Transfer
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
              <i class="bi bi-geo-alt"></i> From Location (optional)
            </label>
            <select name="FromLocationID" class="form-select">
              <option value="">-- None --</option>
              <?php foreach($locations as $l): ?>
                <option value="<?php echo (int)$l['LocationID']; ?>">
                  <?php echo h($l['LocationName']); ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="col-12 col-md-4">
            <label class="form-label">
              <i class="bi bi-geo-alt-fill"></i> To Location (optional)
            </label>
            <select name="ToLocationID" class="form-select">
              <option value="">-- None --</option>
              <?php foreach($locations as $l): ?>
                <option value="<?php echo (int)$l['LocationID']; ?>">
                  <?php echo h($l['LocationName']); ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="col-12 col-md-4">
            <label class="form-label">
              <i class="bi bi-calendar-check"></i> Transfer Date
            </label>
            <input type="datetime-local" name="TransferDate" class="form-control" placeholder="optional">
          </div>

          <div class="col-12">
            <label class="form-label">
              <i class="bi bi-journal-text"></i> Notes (optional)
            </label>
            <input type="text" name="Notes" class="form-control" maxlength="500">
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
          <i class="bi bi-list-ul me-1"></i>All Transfers
        </h5>
        <span class="text-muted small">
          <i class="bi bi-collection me-1"></i>Total: <?php echo count($rows); ?>
        </span>
      </div>

      <!-- Search + Filters -->
      <form method="get" class="mb-3" accept-charset="UTF-8" id="filterForm">
        <div class="row g-2 align-items-end">
          <div class="col-12 col-md-4">
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
                     placeholder="Search asset tag/name or location..."
                     value="<?php echo h($search); ?>">
            </div>
          </div>

          <div class="col-6 col-md-3">
            <label class="form-label">
              <i class="bi bi-geo-alt"></i> From Location
            </label>
            <select name="from_loc" class="form-select" onchange="this.form.submit()">
              <option value="">All</option>
              <?php foreach($locations as $l): ?>
                <option value="<?php echo (int)$l['LocationID']; ?>"
                  <?php echo ($fromFilter === (int)$l['LocationID'] ? 'selected' : ''); ?>>
                  <?php echo h($l['LocationName']); ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="col-6 col-md-3">
            <label class="form-label">
              <i class="bi bi-geo-alt-fill"></i> To Location
            </label>
            <select name="to_loc" class="form-select" onchange="this.form.submit()">
              <option value="">All</option>
              <?php foreach($locations as $l): ?>
                <option value="<?php echo (int)$l['LocationID']; ?>"
                  <?php echo ($toFilter === (int)$l['LocationID'] ? 'selected' : ''); ?>>
                  <?php echo h($l['LocationName']); ?>
                </option>
              <?php endforeach; ?>
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
              <th>From</th>
              <th>To</th>
              <th>Transfer Date</th>
              <th>By</th>
              <th>Notes</th>
              <th>Created</th>
              <th class="text-end">Actions</th>
            </tr>
          </thead>
          <tbody>
          <?php foreach($rows as $r): ?>
            <tr>
              <td><?php echo (int)$r['TransferID']; ?></td>
              <td>
                <div class="fw-semibold"><?php echo h($r['AssetTag']); ?></div>
                <div class="text-muted small"><?php echo h($r['AssetName']); ?></div>
              </td>
              <td><?php echo h($r['FromLocName']); ?></td>
              <td><?php echo h($r['ToLocName']); ?></td>
              <td><?php echo h($r['TransferDate']); ?></td>
              <td><?php echo h($r['PerformedByName']); ?></td>
              <td><?php echo h($r['Notes']); ?></td>
              <td><?php echo h($r['CreatedAt']); ?></td>
              <td class="text-end">
                <div class="action-stack">
                  <a class="btn btn-muted btn-sm w-100 w-md-auto"
                     href="<?php echo h($self); ?>?edit=<?php echo (int)$r['TransferID']; ?>"
                     title="Edit transfer">
                    <i class="bi bi-pencil"></i>
                  </a>

                  <form method="post" class="d-inline"
                        onsubmit="return confirm('Delete this transfer permanently?');"
                        accept-charset="UTF-8">
                    <input type="hidden" name="csrf" value="<?php echo h($CSRF); ?>">
                    <input type="hidden" name="act" value="delete">
                    <input type="hidden" name="TransferID" value="<?php echo (int)$r['TransferID']; ?>">
                    <button class="btn btn-danger-soft btn-sm w-100 w-md-auto" type="submit"
                            title="Delete transfer">
                      <i class="bi bi-trash"></i>
                    </button>
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
