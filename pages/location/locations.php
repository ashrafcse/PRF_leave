<?php
/******************************
 * Locations - Full CRUD (mobile-first UI + auto search/filter)
 * Table: dbo.Locations
 ******************************/
ini_set('display_errors', 1);
error_reporting(E_ALL);

/* 1) Boot */
require_once __DIR__ . '/../../init.php';
require_login(); // block unauthenticated

/* 2) Helpers */
if (!function_exists('h')) {
  function h($s){
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
  }
}
function self_name(){
  return strtok(basename($_SERVER['SCRIPT_NAME']), "?");
}
$self = self_name();

/* simple normalizer: spaces + lowercase (for dup checks when using name+district) */
function normalize_str($s){
  $s = trim(preg_replace('/\s+/', ' ', (string)$s));
  return mb_strtolower($s, 'UTF-8');
}

function is_duplicate_pdo(PDOException $e){
  $code = $e->getCode();
  $msg  = $e->getMessage();
  return ($code === '23000')
      && (stripos($msg, 'unique') !== false
       || stripos($msg, 'duplicate') !== false
       || stripos($msg, 'uq_') !== false);
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

function check_csrf(){
  if (!isset($_POST['csrf']) || $_POST['csrf'] !== $_SESSION['csrf']) {
    die('Invalid CSRF token');
  }
}

/* 4) Actions (NO OUTPUT before these) */
$msg = '';
$msg_type = 'success'; // 'success' | 'danger'

if (isset($_GET['ok']) && $_GET['ok'] === '1') {
  $msg = 'Location created.';
  $msg_type = 'success';
}

$editRow = null;
$edit_id = isset($_GET['edit']) ? (int)$_GET['edit'] : 0;

/* CREATE */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['act']) && $_POST['act'] === 'create') {
  check_csrf();

  $LocationCode = isset($_POST['LocationCode']) ? trim($_POST['LocationCode']) : '';
  $LocationType = isset($_POST['LocationType']) ? (int)$_POST['LocationType'] : null; // 0/1
  $LocationName = isset($_POST['LocationName']) ? trim($_POST['LocationName']) : '';
  $Address      = isset($_POST['Address']) ? trim($_POST['Address']) : '';
  $Phone        = isset($_POST['Phone']) ? trim($_POST['Phone']) : '';
  $District     = isset($_POST['District']) ? trim($_POST['District']) : '';
  $IsActive     = isset($_POST['IsActive']) ? 1 : 0;

  if ($LocationName !== '' && $District !== '' && ($LocationType === 0 || $LocationType === 1)) {
    try {
      // duplicate protection
      $dup = false;
      if ($LocationCode !== '') {
        $chk = $conn->prepare("SELECT 1 FROM dbo.Locations WHERE LocationCode = :code");
        $chk->execute(array(':code'=>$LocationCode));
        $dup = (bool)$chk->fetchColumn();
      } else {
        $normName = normalize_str($LocationName);
        $normDist = normalize_str($District);
        $chk = $conn->prepare("
          SELECT 1
            FROM dbo.Locations
           WHERE LOWER(LTRIM(RTRIM(LocationName))) = :n
             AND LOWER(LTRIM(RTRIM(District)))     = :d
        ");
        $chk->execute(array(':n'=>$normName, ':d'=>$normDist));
        $dup = (bool)$chk->fetchColumn();
      }

      if ($dup) {
        $msg = "Create failed: Duplicate location.";
        $msg_type = 'danger';
      } else {
        $createdBy = isset($_SESSION['auth_user']['UserID']) ? (int)$_SESSION['auth_user']['UserID'] : null;
        $stmt = $conn->prepare("
          INSERT INTO dbo.Locations
            (LocationCode, LocationType, LocationName, [Address], Phone, District, IsActive, CreatedAt, CreatedBy)
          VALUES
            (:code, :type, :name, :addr, :phone, :dist, :active, GETDATE(), :by)
        ");
        $stmt->bindValue(':code',  ($LocationCode === '') ? null : $LocationCode, ($LocationCode === '' ? PDO::PARAM_NULL : PDO::PARAM_STR));
        $stmt->bindValue(':type',  $LocationType, PDO::PARAM_INT);
        $stmt->bindValue(':name',  $LocationName, PDO::PARAM_STR);
        $stmt->bindValue(':addr',  ($Address === '') ? null : $Address, ($Address === '' ? PDO::PARAM_NULL : PDO::PARAM_STR));
        $stmt->bindValue(':phone', ($Phone === '') ? null : $Phone, ($Phone === '' ? PDO::PARAM_NULL : PDO::PARAM_STR));
        $stmt->bindValue(':dist',  $District, PDO::PARAM_STR);
        $stmt->bindValue(':active',$IsActive, PDO::PARAM_INT);
        if ($createdBy === null) {
          $stmt->bindValue(':by', null, PDO::PARAM_NULL);
        } else {
          $stmt->bindValue(':by', $createdBy, PDO::PARAM_INT);
        }
        $stmt->execute();

        header('Location: ' . $self . '?ok=1');
        exit;
      }
    } catch (PDOException $e) {
      if (is_duplicate_pdo($e)) {
        $msg = "Create failed: Duplicate location.";
      } else {
        $msg = "Create failed: ".h($e->getMessage());
      }
      $msg_type = 'danger';
    }
  } else {
    $msg = "Required: Location Type, Location Name, District.";
    $msg_type = 'danger';
  }
}

/* PREPARE EDIT */
if ($edit_id > 0) {
  try {
    $st = $conn->prepare("
      SELECT L.LocationID, L.LocationCode, L.LocationType, L.LocationName,
             L.[Address], L.Phone, L.District, L.IsActive, L.CreatedAt, L.CreatedBy,
             U.Username AS CreatedByUsername
        FROM dbo.Locations L
        LEFT JOIN dbo.Users U ON U.UserID = L.CreatedBy
       WHERE L.LocationID = :id
    ");
    $st->execute(array(':id'=>$edit_id));
    $editRow = $st->fetch();
    if (!$editRow) {
      $msg = "Row not found for edit.";
      $msg_type='danger';
      $edit_id = 0;
    }
  } catch (PDOException $e) {
    $msg = "Load edit row failed: ".h($e->getMessage());
    $msg_type='danger';
  }
}

/* UPDATE */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['act']) && $_POST['act'] === 'update') {
  check_csrf();

  $LocationID   = isset($_POST['LocationID']) ? (int)$_POST['LocationID'] : 0;
  $LocationCode = isset($_POST['LocationCode']) ? trim($_POST['LocationCode']) : '';
  $LocationType = isset($_POST['LocationType']) ? (int)$_POST['LocationType'] : null;
  $LocationName = isset($_POST['LocationName']) ? trim($_POST['LocationName']) : '';
  $Address      = isset($_POST['Address']) ? trim($_POST['Address']) : '';
  $Phone        = isset($_POST['Phone']) ? trim($_POST['Phone']) : '';
  $District     = isset($_POST['District']) ? trim($_POST['District']) : '';
  $IsActive     = isset($_POST['IsActive']) ? 1 : 0;

  if ($LocationID > 0 && $LocationName !== '' && $District !== '' && ($LocationType === 0 || $LocationType === 1)) {
    try {
      // duplicate checks (ignore self)
      $dup = false;
      if ($LocationCode !== '') {
        $chk = $conn->prepare("SELECT 1 FROM dbo.Locations WHERE LocationCode = :code AND LocationID <> :id");
        $chk->execute(array(':code'=>$LocationCode, ':id'=>$LocationID));
        $dup = (bool)$chk->fetchColumn();
      } else {
        $normName = normalize_str($LocationName);
        $normDist = normalize_str($District);
        $chk = $conn->prepare("
          SELECT 1
            FROM dbo.Locations
           WHERE LOWER(LTRIM(RTRIM(LocationName))) = :n
             AND LOWER(LTRIM(RTRIM(District)))     = :d
             AND LocationID <> :id
        ");
        $chk->execute(array(':n'=>$normName, ':d'=>$normDist, ':id'=>$LocationID));
        $dup = (bool)$chk->fetchColumn();
      }

      if ($dup) {
        $msg = "Update failed: Duplicate location.";
        $msg_type = 'danger';
      } else {
        $stmt = $conn->prepare("
          UPDATE dbo.Locations
             SET LocationCode = :code,
                 LocationType = :type,
                 LocationName = :name,
                 [Address]    = :addr,
                 Phone        = :phone,
                 District     = :dist,
                 IsActive     = :active
           WHERE LocationID   = :id
        ");
        $stmt->bindValue(':code',  ($LocationCode === '') ? null : $LocationCode, ($LocationCode === '' ? PDO::PARAM_NULL : PDO::PARAM_STR));
        $stmt->bindValue(':type',  $LocationType, PDO::PARAM_INT);
        $stmt->bindValue(':name',  $LocationName, PDO::PARAM_STR);
        $stmt->bindValue(':addr',  ($Address === '') ? null : $Address, ($Address === '' ? PDO::PARAM_NULL : PDO::PARAM_STR));
        $stmt->bindValue(':phone', ($Phone === '') ? null : $Phone, ($Phone === '' ? PDO::PARAM_NULL : PDO::PARAM_STR));
        $stmt->bindValue(':dist',  $District, PDO::PARAM_STR);
        $stmt->bindValue(':active',$IsActive, PDO::PARAM_INT);
        $stmt->bindValue(':id',    $LocationID, PDO::PARAM_INT);
        $stmt->execute();

        header('Location: ' . $self);
        exit;
      }
    } catch (PDOException $e) {
      if (is_duplicate_pdo($e)) {
        $msg = "Update failed: Duplicate location.";
      } else {
        $msg = "Update failed: ".h($e->getMessage());
      }
      $msg_type = 'danger';
    }
  } else {
    $msg = "Invalid data.";
    $msg_type = 'danger';
  }
}

/* TOGGLE ACTIVE */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['act']) && $_POST['act'] === 'toggle') {
  check_csrf();
  $id = isset($_POST['LocationID']) ? (int)$_POST['LocationID'] : 0;
  $to = isset($_POST['to']) ? (int)$_POST['to'] : 0;
  if ($id > 0) {
    try {
      $stmt = $conn->prepare("UPDATE dbo.Locations SET IsActive = :a WHERE LocationID = :id");
      $stmt->execute(array(':a'=>$to, ':id'=>$id));
      $msg = $to ? "Activated." : "Deactivated.";
      $msg_type = 'success';
    } catch (PDOException $e) {
      $msg = "Toggle failed: ".h($e->getMessage());
      $msg_type='danger';
    }
  }
}

/* DELETE (hard) */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['act']) && $_POST['act'] === 'delete') {
  check_csrf();
  $id = isset($_POST['LocationID']) ? (int)$_POST['LocationID'] : 0;
  if ($id > 0) {
    try {
      $stmt = $conn->prepare("DELETE FROM dbo.Locations WHERE LocationID = :id");
      $stmt->execute(array(':id'=>$id));
      $msg = "Location deleted.";
      $msg_type = 'success';
    } catch (PDOException $e) {
      $msg = "Delete failed: ".h($e->getMessage());
      $msg_type='danger';
    }
  }
}

/* 5) Query list (full; front-end auto filter/search) */
try {
  $rows = $conn->query("
    SELECT L.LocationID, L.LocationCode, L.LocationType, L.LocationName,
           L.[Address], L.Phone, L.District, L.IsActive, L.CreatedAt, L.CreatedBy,
           U.Username AS CreatedByUsername
      FROM dbo.Locations L
      LEFT JOIN dbo.Users U ON U.UserID = L.CreatedBy
     ORDER BY L.LocationName
  ")->fetchAll();
} catch (PDOException $e) {
  $rows = array();
  $msg = "Load list failed: ".h($e->getMessage());
  $msg_type='danger';
}

/* 6) Render */
require_once __DIR__ . '/../../include/header.php';
?>

<style>
  .page-wrap {
      max-width: 100%;
      margin: 28px auto;
      padding: 0 16px 32px;
  }

  .page-title {
      font-weight: 700;
      letter-spacing: .2px;
      color: #0f172a;
      display: flex;
      align-items: center;
      gap: 8px;
  }
  .page-title i{
      font-size: 22px;
      color: #4f46e5;
  }

  .page-subtitle{
      font-size: 13px;
      color: #6b7280;
  }

  .card-elevated {
      border-radius: 16px;
      border: 1px solid #e5e7eb;
      box-shadow: 0 18px 45px rgba(15, 23, 42, 0.12);
      overflow: hidden;
  }
  .card-elevated .card-body{
      background: radial-gradient(circle at top left, #eff6ff 0, #ffffff 45%, #f9fafb 100%);
  }

  .badge-soft {
      border-radius: 999px;
      padding: 5px 12px;
      font-size: 12px;
      font-weight: 500;
      color: #0f172a;
      background: #e0f2fe;
      border: 1px solid #bae6fd;
      display: inline-flex;
      align-items: center;
      gap: 6px;
  }
  .badge-soft i{
      font-size: 0.85rem;
      color: #0284c7;
  }

  .btn-brand {
      background: linear-gradient(135deg, #6366f1, #2563eb);
      color: #fff !important;
      border: none;
      padding: 0.55rem 1.4rem;
      font-weight: 600;
      border-radius: 999px;
      display: inline-flex;
      align-items: center;
      gap: 8px;
      box-shadow: 0 12px 25px rgba(37, 99, 235, 0.35);
      transition: all .15s ease-in-out;
  }
  .btn-brand i{
      font-size: 0.95rem;
  }
  .btn-brand:hover {
      background: linear-gradient(135deg, #4f46e5, #1d4ed8);
      transform: translateY(-1px);
      box-shadow: 0 16px 32px rgba(30, 64, 175, 0.45);
  }

  .btn-muted {
      background:#e5e7eb;
      color:#111827!important;
      border:none;
      border-radius: 999px;
      padding: 0.45rem 1.1rem;
      font-weight: 500;
      display: inline-flex;
      align-items: center;
      gap: 6px;
  }
  .btn-muted i{
      font-size: 0.9rem;
  }
  .btn-muted:hover{
      background:#d1d5db;
  }

  .btn-danger-soft{
      background:#fee2e2;
      color:#b91c1c!important;
      border:1px solid #fecaca;
      border-radius: 999px;
      padding: 0.45rem 1.1rem;
      font-weight: 500;
      display: inline-flex;
      align-items: center;
      gap: 6px;
  }
  .btn-danger-soft i{
      font-size: 0.9rem;
  }
  .btn-danger-soft:hover{
      background:#fecaca;
  }

  .form-label{
      font-weight:600;
      color:#374151;
      font-size: 13px;
  }
  .form-control, .form-select{
      border-radius:10px;
      border-color:#cbd5e1;
      font-size: 14px;
  }

  .section-title{
      font-weight:600;
      color:#111827;
      display:flex;
      align-items:center;
      gap:8px;
  }
  .section-title i{
      color:#4f46e5;
      font-size: 1rem;
  }

  .table thead th{
      background:#f9fafb;
      color:#4b5563;
      border-bottom:1px solid #e5e7eb;
      font-size:12px;
      text-transform:uppercase;
      letter-spacing:.04em;
      white-space: nowrap;
  }
  .table tbody td{
      vertical-align:middle;
      font-size:13px;
      color:#111827;
  }
  .table-hover tbody tr:hover{
      background-color:#eff6ff;
  }

  .status-pill{
      display: inline-flex;
      align-items: center;
      gap: 6px;
      padding: 3px 10px;
      border-radius: 999px;
      font-size: 11px;
      font-weight: 500;
  }
  .status-pill .status-dot{
      width: 8px;
      height: 8px;
      border-radius: 999px;
  }
  .status-pill-active{
      background: #ecfdf3;
      color: #166534;
  }
  .status-pill-active .status-dot{
      background:#22c55e;
  }
  .status-pill-inactive{
      background: #fef2f2;
      color: #b91c1c;
  }
  .status-pill-inactive .status-dot{
      background:#ef4444;
  }

  .action-stack > *{ margin:4px; }
  @media (min-width:768px){
    .action-stack{
        display:inline-flex;
        gap:6px;
    }
  }

  .filters-helper{
      font-size:12px;
      color:#6b7280;
  }
</style>

<div class="page-wrap">

  <div class="d-flex flex-wrap align-items-center justify-content-between mb-3 gap-2">
    <div>
      <h1 class="page-title mb-1">
        <i class="fas fa-map-marker-alt"></i>
        Locations
      </h1>
      <div class="page-subtitle">
        PRF locations manage করুন – create, update, status toggle & delete (hospital / office / branch)।
      </div>
    </div>
    <span class="badge-soft">
      <i class="fas fa-map"></i>
      Total Locations: <?php echo count($rows); ?>
    </span>
  </div>

  <!-- Alerts -->
  <?php if ($msg): ?>
    <div class="alert alert-<?php echo ($msg_type === 'danger' ? 'danger' : 'success'); ?> alert-dismissible fade show shadow-sm" role="alert">
      <?php echo $msg; ?>
      <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
  <?php endif; ?>

  <!-- Create / Edit Card -->
  <div class="card card-elevated mb-4">
    <div class="card-body">
      <?php if (!empty($editRow)): ?>
        <div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-3">
          <div class="section-title mb-0">
            <i class="fas fa-edit"></i>
            <span>Edit Location</span>
          </div>
          <a class="btn btn-muted btn-sm" href="<?php echo h($self); ?>">
            <i class="fas fa-times-circle"></i>
            Cancel
          </a>
        </div>

        <form method="post" accept-charset="UTF-8">
          <input type="hidden" name="csrf" value="<?php echo h($CSRF); ?>">
          <input type="hidden" name="act" value="update">
          <input type="hidden" name="LocationID" value="<?php echo (int)$editRow['LocationID']; ?>">

          <div class="row g-3">
            <div class="col-12 col-md-4">
              <label class="form-label">Location Code</label>
              <input type="text" name="LocationCode" class="form-control"
                     value="<?php echo h($editRow['LocationCode']); ?>" maxlength="50">
            </div>

            <div class="col-12 col-md-4">
              <label class="form-label">Location Type</label>
              <select name="LocationType" class="form-select" required>
                <option value="1" <?php echo ((int)$editRow['LocationType']===1?'selected':''); ?>>
                  1 - Hospital
                </option>
                <option value="0" <?php echo ((int)$editRow['LocationType']===0?'selected':''); ?>>
                  0 - Office/Branch
                </option>
              </select>
            </div>

            <div class="col-12 col-md-4 d-flex align-items-end">
              <div class="form-check">
                <input class="form-check-input" type="checkbox" name="IsActive"
                       id="isActiveEdit" <?php echo ((int)$editRow['IsActive']===1?'checked':''); ?>>
                <label class="form-check-label" for="isActiveEdit">Active</label>
              </div>
            </div>

            <div class="col-12 col-md-6">
              <label class="form-label">Location Name</label>
              <input type="text" name="LocationName" class="form-control" required maxlength="200"
                     value="<?php echo h($editRow['LocationName']); ?>">
            </div>

            <div class="col-12 col-md-6">
              <label class="form-label">District</label>
              <input type="text" name="District" class="form-control" required maxlength="100"
                     value="<?php echo h($editRow['District']); ?>">
            </div>

            <div class="col-12 col-md-6">
              <label class="form-label">Address</label>
              <input type="text" name="Address" class="form-control" maxlength="500"
                     value="<?php echo h($editRow['Address']); ?>">
            </div>

            <div class="col-12 col-md-6">
              <label class="form-label">Phone</label>
              <input type="text" name="Phone" class="form-control" maxlength="50"
                     value="<?php echo h($editRow['Phone']); ?>">
            </div>

            <div class="col-12">
              <div class="text-muted small">
                Created:
                <span class="badge-soft"><?php echo h($editRow['CreatedAt']); ?></span>
                by
                <span class="badge-soft"><?php echo h(isset($editRow['CreatedByUsername']) ? $editRow['CreatedByUsername'] : ''); ?></span>
              </div>
            </div>

            <div class="col-12 d-grid d-md-inline">
              <button class="btn btn-brand w-100 w-md-auto" style="display: inline;">
                <i class="fas fa-save"></i>
                Update
              </button>
            </div>
          </div>
        </form>

      <?php else: ?>
        <div class="section-title mb-3">
          <i class="fas fa-plus-circle"></i>
          <span>Add Location</span>
        </div>

        <form method="post" class="row g-3" accept-charset="UTF-8">
          <input type="hidden" name="csrf" value="<?php echo h($CSRF); ?>">
          <input type="hidden" name="act" value="create">

          <div class="col-12 col-md-4">
            <label class="form-label">Location Code</label>
            <input type="text" name="LocationCode" class="form-control" maxlength="50" placeholder="Optional">
          </div>

          <div class="col-12 col-md-4">
            <label class="form-label">Location Type</label>
            <select name="LocationType" class="form-select" required>
              <option value="1">1 - Hospital</option>
              <option value="0">0 - Office/Branch</option>
            </select>
          </div>

          <div class="col-12 col-md-4 d-flex align-items-end">
            <div class="form-check">
              <input class="form-check-input" type="checkbox" name="IsActive" id="isActiveCreate" checked>
              <label class="form-check-label" for="isActiveCreate">Active</label>
            </div>
          </div>

          <div class="col-12 col-md-6">
            <label class="form-label">Location Name</label>
            <input type="text" name="LocationName" class="form-control" required maxlength="200" placeholder="e.g. Dhaka Central Hospital">
          </div>

          <div class="col-12 col-md-6">
            <label class="form-label">District</label>
            <input type="text" name="District" class="form-control" required maxlength="100" placeholder="e.g. Dhaka">
          </div>

          <div class="col-12 col-md-6">
            <label class="form-label">Address</label>
            <input type="text" name="Address" class="form-control" maxlength="500" placeholder="Street, area, etc.">
          </div>

          <div class="col-12 col-md-6">
            <label class="form-label">Phone</label>
            <input type="text" name="Phone" class="form-control" maxlength="50" placeholder="e.g. 017XXXXXXXX">
          </div>

          <div class="col-12 d-grid d-md-inline">
            <button class="btn btn-brand w-100 w-md-auto" style="display: inline;">
              <i class="fas fa-save"></i>
              Create
            </button>
          </div>
        </form>
      <?php endif; ?>
    </div>
  </div>

  <!-- List Card -->
  <div class="card card-elevated">
    <div class="card-body">
      <div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-2">
        <div class="section-title mb-0">
          <i class="fas fa-list-ul"></i>
          <span>All Locations</span>
        </div>
        <span class="filters-helper">
          <i class="fas fa-filter me-1"></i>
          উপরের search + type/status filter দিয়ে table auto-filter হবে (reload ছাড়া)।
        </span>
      </div>

      <!-- Search + filters above table -->
      <div class="row g-2 mb-3">
        <div class="col-12 col-md-6">
          <label class="form-label small mb-1">Search (code / name / district / phone)</label>
          <input type="text" id="locSearch" class="form-control" placeholder="Type to search...">
        </div>
        <div class="col-6 col-md-3">
          <label class="form-label small mb-1">Location Type</label>
          <select id="locTypeFilter" class="form-select">
            <option value="">All</option>
            <option value="1">Hospital</option>
            <option value="0">Office/Branch</option>
          </select>
        </div>
        <div class="col-6 col-md-3">
          <label class="form-label small mb-1">Status</label>
          <select id="statusFilter" class="form-select">
            <option value="">All</option>
            <option value="1">Active</option>
            <option value="0">Inactive</option>
          </select>
        </div>
      </div>

      <div class="table-responsive">
        <table class="table table-hover align-middle" id="locTable">
          <thead>
            <tr>
              <th>#</th>
              <th>Code</th>
              <th>Name</th>
              <th>Type</th>
              <th>District</th>
              <th>Phone</th>
              <th>Status</th>
              <th>Created</th>
              <th>By</th>
              <th class="text-end">Actions</th>
            </tr>
          </thead>
          <tbody>
          <?php foreach($rows as $r): ?>
            <?php
              $isActive = (int)$r['IsActive'];
              $statusClass = $isActive ? 'status-pill-active' : 'status-pill-inactive';
              $statusText  = $isActive ? 'Active' : 'Inactive';
              $typeInt     = (int)$r['LocationType'];
              $typeLabel   = $typeInt === 1 ? '1 - Hospital' : '0 - Office/Branch';
              $searchIndex = strtolower(
                (string)$r['LocationCode'].' '.
                $r['LocationName'].' '.
                $r['District'].' '.
                $r['Phone'].' '.
                $typeLabel
              );
            ?>
            <tr data-status="<?php echo $isActive; ?>"
                data-type="<?php echo $typeInt; ?>"
                data-search="<?php echo h($searchIndex); ?>">
              <td><?php echo (int)$r['LocationID']; ?></td>
              <td><?php echo h($r['LocationCode']); ?></td>
              <td><?php echo h($r['LocationName']); ?></td>
              <td><?php echo h($typeLabel); ?></td>
              <td><?php echo h($r['District']); ?></td>
              <td><?php echo h($r['Phone']); ?></td>
              <td>
                <span class="status-pill <?php echo $statusClass; ?>">
                  <span class="status-dot"></span>
                  <?php echo h($statusText); ?>
                </span>
              </td>
              <td><?php echo h($r['CreatedAt']); ?></td>
              <td><?php echo h(isset($r['CreatedByUsername']) ? $r['CreatedByUsername'] : ''); ?></td>

              <td class="text-end">
                <div class="action-stack">
                  <a class="btn btn-muted btn-sm w-100 w-md-auto"
                     href="<?php echo h($self); ?>?edit=<?php echo (int)$r['LocationID']; ?>">
                    <i class="fas fa-pencil-alt"></i>
                    Edit
                  </a>

                  <!-- Toggle -->
                  <form method="post" class="d-inline"
                        onsubmit="return confirm('Toggle active status?');"
                        accept-charset="UTF-8">
                    <input type="hidden" name="csrf" value="<?php echo h($CSRF); ?>">
                    <input type="hidden" name="act" value="toggle">
                    <input type="hidden" name="LocationID" value="<?php echo (int)$r['LocationID']; ?>">
                    <input type="hidden" name="to" value="<?php echo $isActive?0:1; ?>">
                    <button class="btn btn-muted btn-sm w-100 w-md-auto" type="submit">
                      <i class="fas <?php echo $isActive ? 'fa-pause-circle' : 'fa-play-circle'; ?>"></i>
                      <?php echo $isActive ? 'Deactivate' : 'Activate'; ?>
                    </button>
                  </form>

                  <!-- Delete -->
                  <form method="post" class="d-inline"
                        onsubmit="return confirm('Delete this location permanently?');"
                        accept-charset="UTF-8">
                    <input type="hidden" name="csrf" value="<?php echo h($CSRF); ?>">
                    <input type="hidden" name="act" value="delete">
                    <input type="hidden" name="LocationID" value="<?php echo (int)$r['LocationID']; ?>">
                    <button class="btn btn-danger-soft btn-sm w-100 w-md-auto" type="submit">
                      <i class="fas fa-trash-alt"></i>
                      Delete
                    </button>
                  </form>
                </div>
              </td>
            </tr>
          <?php endforeach; ?>
          <?php if (empty($rows)): ?>
            <tr>
              <td colspan="10" class="text-center text-muted py-4">
                <i class="fas fa-folder-open me-1"></i> No data
              </td>
            </tr>
          <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>

</div><!-- /.page-wrap -->

<script>
// table uporer search + type/status filter (auto, page reload ছাড়া)
(function(){
  var searchInput   = document.getElementById('locSearch');
  var typeFilter    = document.getElementById('locTypeFilter');
  var statusFilter  = document.getElementById('statusFilter');
  var table         = document.getElementById('locTable');
  if (!table) return;

  var rows = table.querySelectorAll('tbody tr');

  function applyFilters(){
    var q   = (searchInput && searchInput.value ? searchInput.value : '').toLowerCase();
    var st  = statusFilter ? statusFilter.value : '';
    var typ = typeFilter ? typeFilter.value : '';

    Array.prototype.forEach.call(rows, function(tr){
      var rowStatus = tr.getAttribute('data-status') || '';
      var rowType   = tr.getAttribute('data-type') || '';
      var sIndex    = (tr.getAttribute('data-search') || '').toLowerCase();

      var matchSearch = !q   || sIndex.indexOf(q) !== -1;
      var matchStatus = !st  || rowStatus === st;
      var matchType   = !typ || rowType === typ;

      tr.style.display = (matchSearch && matchStatus && matchType) ? '' : 'none';
    });
  }

  if (searchInput)  searchInput.addEventListener('input', applyFilters);
  if (statusFilter) statusFilter.addEventListener('change', applyFilters);
  if (typeFilter)   typeFilter.addEventListener('change', applyFilters);

  applyFilters();
})();
</script>

<?php require_once __DIR__ . '/../../include/footer.php'; ?>
