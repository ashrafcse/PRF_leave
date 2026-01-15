<?php 
/***********************
 * Designations - Full CRUD (same design + auto search/filter)
 * Table: dbo.Designation (JobTitleID PK [NOT IDENTITY], JobTitleName, IsActive, CreatedAt, CreatedBy)
 ***********************/
ini_set('display_errors', 1);
error_reporting(E_ALL);

/* 1) Boot */
require_once __DIR__ . '/../../init.php';
require_login(); // block unauthenticated

/* 2) Helpers */
if (!function_exists('h')) {
  function h($s){ return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }
}
function self_name(){ return strtok(basename($_SERVER['SCRIPT_NAME']), "?"); }
$self = self_name();

function normalize_name($s){
  $s = trim(preg_replace('/\s+/', ' ', (string)$s));
  return mb_strtolower($s, 'UTF-8');
}
function is_duplicate_pdo(PDOException $e){
  $code = $e->getCode();
  $msg  = $e->getMessage();
  return ($code === '23000') && (stripos($msg, 'unique') !== false || stripos($msg, 'duplicate') !== false || stripos($msg, 'uq_') !== false);
}

/**
 * Get next JobTitleID safely and insert a row inside the same transaction.
 * Returns void; throws on failure.
 */
function create_designation(PDO $conn, $name, $createdBy, $isActive){
  // Set strict isolation for consistent next-id calc
  $conn->exec("SET TRANSACTION ISOLATION LEVEL SERIALIZABLE");
  $conn->beginTransaction();
  try {
    // Duplicate check within txn (case-insensitive + space-normalized best effort on DB side)
    $dbNormalizeExpr = "LOWER(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(LTRIM(RTRIM(JobTitleName)),'  ',' '),'  ',' '),'  ',' '),'  ',' '),'  ',' '))";
    $norm = normalize_name($name);

    $chk = $conn->prepare("SELECT 1 FROM dbo.Designation WITH (HOLDLOCK) WHERE $dbNormalizeExpr = :n");
    $chk->execute(array(':n' => $norm));
    if ($chk->fetchColumn()) {
      throw new RuntimeException("DUPLICATE_NAME");
    }

    // Compute next id with an exclusive table lock
    $nextId = (int)$conn->query("
      SELECT ISNULL(MAX(JobTitleID), 0) + 1
      FROM dbo.Designation WITH (TABLOCKX, HOLDLOCK)
    ")->fetchColumn();

    // Insert with explicit PK
    $stmt = $conn->prepare("
      INSERT INTO dbo.Designation (JobTitleID, JobTitleName, CreatedAt, CreatedBy, IsActive)
      VALUES (:id, :n_display, GETDATE(), :by, :a)
    ");
    $stmt->bindValue(':id', $nextId, PDO::PARAM_INT);
    $stmt->bindValue(':n_display', $name, PDO::PARAM_STR);
    if ($createdBy === null) $stmt->bindValue(':by', null, PDO::PARAM_NULL);
    else $stmt->bindValue(':by', $createdBy, PDO::PARAM_INT);
    $stmt->bindValue(':a', (int)$isActive, PDO::PARAM_INT);
    $stmt->execute();

    $conn->commit();
  } catch (Throwable $e) {
    if ($conn->inTransaction()) { $conn->rollBack(); }
    if ($e instanceof RuntimeException && $e->getMessage() === 'DUPLICATE_NAME') {
      // Signal to caller
      throw new RuntimeException('DUPLICATE_NAME');
    }
    throw $e;
  }
}

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

/* 4) Actions (NO OUTPUT before these) */
$msg = '';
$msg_type = 'success'; // 'success' | 'danger'

if (isset($_GET['ok']) && $_GET['ok'] === '1') { 
  $msg = 'Designation created.'; 
  $msg_type = 'success';
}

$editRow = null;
$edit_id = isset($_GET['edit']) ? (int)$_GET['edit'] : 0;

/* DB-side normalize expr used elsewhere (for update duplicate check) */
$dbNormalizeExpr = "LOWER(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(LTRIM(RTRIM(JobTitleName)),'  ',' '),'  ',' '),'  ',' '),'  ',' '),'  ',' '))";

/* CREATE */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['act']) && $_POST['act'] === 'create') {
    check_csrf();

    $raw = isset($_POST['JobTitleName']) ? $_POST['JobTitleName'] : '';
    $name = trim(preg_replace('/\s+/', ' ', $raw));
    $isActive = isset($_POST['IsActive']) ? 1 : 0;

  if ($name !== '') {
    try {
      $createdBy = isset($_SESSION['auth_user']['UserID']) ? (int)$_SESSION['auth_user']['UserID'] : null;
      create_designation($conn, $name, $createdBy, $isActive);
      header('Location: ' . $self . '?ok=1'); exit;
    } catch (RuntimeException $e) {
      if ($e->getMessage() === 'DUPLICATE_NAME') {
        $msg = "Create failed: Designation already exists.";
        $msg_type = 'danger';
      } else {
        $msg = "Create failed.";
        $msg_type = 'danger';
      }
    } catch (PDOException $e) {
      // If UNIQUE constraint exists at DB level, cover race as well
      if (is_duplicate_pdo($e)) {
        $msg = "Create failed: Designation already exists.";
        $msg_type = 'danger';
      } else {
        $msg = "Create failed: " . h($e->getMessage());
        $msg_type = 'danger';
      }
    }
  } else { 
    $msg = "Designation name is required."; 
    $msg_type = 'danger';
  }
}

/* PREPARE EDIT */
if ($edit_id > 0) {
  try {
    $st = $conn->prepare("
      SELECT d.JobTitleID, d.JobTitleName, d.CreatedAt, d.CreatedBy, d.IsActive,
             u.Username AS CreatedByUsername
        FROM dbo.Designation d
        LEFT JOIN dbo.Users u ON u.UserID = d.CreatedBy
       WHERE d.JobTitleID = :id
    ");
    $st->execute(array(':id'=>$edit_id));
    $editRow = $st->fetch();
    if (!$editRow) { $msg = "Row not found for edit."; $msg_type='danger'; $edit_id = 0; }
  } catch (PDOException $e) { $msg = "Load edit row failed: ".h($e->getMessage()); $msg_type='danger'; }
}

/* UPDATE */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['act']) && $_POST['act'] === 'update') {
    check_csrf();

    $id = isset($_POST['JobTitleID']) ? (int)$_POST['JobTitleID'] : 0;
    $raw = isset($_POST['JobTitleName']) ? $_POST['JobTitleName'] : '';
    $name = trim(preg_replace('/\s+/', ' ', $raw));
    $norm = normalize_name($name);
    $isActive = isset($_POST['IsActive']) ? 1 : 0;

  if ($id > 0 && $name !== '') {
    try {
      // Duplicate check on rename
      $chk = $conn->prepare("
        SELECT 1 
          FROM dbo.Designation 
         WHERE $dbNormalizeExpr = :n
           AND JobTitleID <> :id
      ");
      $chk->execute(array(':n'=>$norm, ':id'=>$id));
      if ($chk->fetchColumn()) {
        $msg = "Update failed: Designation already exists.";
        $msg_type = 'danger';
      } else {
        $stmt = $conn->prepare("
          UPDATE dbo.Designation
             SET JobTitleName = :n_display,
                 IsActive = :a
           WHERE JobTitleID = :id
        ");
        $stmt->execute(array(':n_display'=>$name, ':a'=>$isActive, ':id'=>$id));
        header('Location: ' . $self); exit;
      }
    } catch (PDOException $e) {
      if (is_duplicate_pdo($e)) {
        $msg = "Update failed: Designation already exists.";
        $msg_type = 'danger';
      } else {
        $msg = "Update failed: ".h($e->getMessage());
        $msg_type = 'danger';
      }
    }
  } else { 
    $msg = "Invalid data."; 
    $msg_type = 'danger';
  }
}

/* TOGGLE */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['act']) && $_POST['act'] === 'toggle') {
  check_csrf();
  $id = isset($_POST['JobTitleID']) ? (int)$_POST['JobTitleID'] : 0;
  $to = isset($_POST['to']) ? (int)$_POST['to'] : 0;
  if ($id > 0) {
    try {
      $stmt = $conn->prepare("UPDATE dbo.Designation SET IsActive = :a WHERE JobTitleID = :id");
      $stmt->execute(array(':a'=>$to, ':id'=>$id));
      $msg = $to ? "Activated." : "Deactivated.";
      $msg_type = 'success';
    } catch (PDOException $e) { $msg = "Toggle failed: ".h($e->getMessage()); $msg_type='danger'; }
  }
}

/* DELETE */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['act']) && $_POST['act'] === 'delete') {
  check_csrf();
  $id = isset($_POST['JobTitleID']) ? (int)$_POST['JobTitleID'] : 0;
  if ($id > 0) {
    try {
      $stmt = $conn->prepare("DELETE FROM dbo.Designation WHERE JobTitleID = :id");
      $stmt->execute(array(':id'=>$id));
      $msg = "Designation deleted.";
      $msg_type = 'success';
    } catch (PDOException $e) { $msg = "Delete failed: ".h($e->getMessage()); $msg_type='danger'; }
  }
}

/* 5) Query list (always full; auto filter via JS) */
try {
  $rows = $conn->query("
    SELECT d.JobTitleID, d.JobTitleName, d.CreatedAt, d.CreatedBy, d.IsActive,
           u.Username AS CreatedByUsername
      FROM dbo.Designation d
      LEFT JOIN dbo.Users u ON u.UserID = d.CreatedBy
     ORDER BY d.JobTitleName
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
        <i class="fas fa-id-badge"></i>
        Designations
      </h1>
      <div class="page-subtitle">
        PRF designation / job title manage করুন – create, update, status toggle & delete.
      </div>
    </div>
    <span class="badge-soft">
      <i class="fas fa-layer-group"></i>
      Total Designations: <?php echo count($rows); ?>
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
            <span>Edit Designation</span>
          </div>
          <a class="btn btn-muted btn-sm" href="<?php echo h($self); ?>">
            <i class="fas fa-times-circle"></i>
            Cancel
          </a>
        </div>

        <form method="post" accept-charset="UTF-8">
          <input type="hidden" name="csrf" value="<?php echo h($CSRF); ?>">
          <input type="hidden" name="act" value="update">
          <input type="hidden" name="JobTitleID" value="<?php echo (int)$editRow['JobTitleID']; ?>">

          <div class="row g-3">
            <div class="col-12 col-md-8">
              <label class="form-label">Designation Name</label>
              <input type="text" name="JobTitleName" class="form-control" required
                     value="<?php echo h($editRow['JobTitleName']); ?>">
            </div>
            <div class="col-12 col-md-4 d-flex align-items-end">
              <div class="form-check">
                <input class="form-check-input" type="checkbox" name="IsActive"
                       id="isActiveEdit" <?php echo ((int)$editRow['IsActive']===1?'checked':''); ?>>
                <label class="form-check-label" for="isActiveEdit">Active</label>
              </div>
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
          <span>Add Designation</span>
        </div>

        <form method="post" class="row g-3" accept-charset="UTF-8">
          <input type="hidden" name="csrf" value="<?php echo h($CSRF); ?>">
          <input type="hidden" name="act" value="create">

          <div class="col-12 col-md-8">
            <label class="form-label">Designation Name</label>
            <input type="text" name="JobTitleName" class="form-control" required placeholder="e.g. Software Engineer">
          </div>
          <div class="col-12 col-md-4 d-flex align-items-end">
            <div class="form-check">
              <input class="form-check-input" type="checkbox" name="IsActive" id="isActiveCreate" checked>
              <label class="form-check-label" for="isActiveCreate">Active</label>
            </div>
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
          <span>All Designations</span>
        </div>
        <span class="filters-helper">
          <i class="fas fa-filter me-1"></i>
          উপরের search & status filter দিয়ে table auto-filter হবে (reload ছাড়াই)।
        </span>
      </div>

      <!-- Search + Filter above table -->
      <div class="row g-2 mb-3">
        <div class="col-12 col-md-6">
          <label class="form-label small mb-1">Search (Name / Created by)</label>
          <input type="text" id="desigSearch" class="form-control" placeholder="Type to search...">
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
        <table class="table table-hover align-middle" id="desigTable">
          <thead>
            <tr>
              <th>#</th>
              <th>Name</th>
              <th>Created</th>
              <th>By</th>
              <th>Status</th>
              <th class="text-end">Actions</th>
            </tr>
          </thead>
          <tbody>
          <?php foreach($rows as $r): ?>
            <?php
              $isActive = (int)$r['IsActive'];
              $statusClass = $isActive ? 'status-pill-active' : 'status-pill-inactive';
              $statusText  = $isActive ? 'Active' : 'Inactive';
              $searchIndex = strtolower(
                (string)$r['JobTitleName'].' '.
                (isset($r['CreatedByUsername']) ? $r['CreatedByUsername'] : '')
              );
            ?>
            <tr data-status="<?php echo $isActive; ?>"
                data-search="<?php echo h($searchIndex); ?>">
              <td><?php echo (int)$r['JobTitleID']; ?></td>
              <td><?php echo h($r['JobTitleName']); ?></td>
              <td><?php echo h($r['CreatedAt']); ?></td>
              <td><?php echo h(isset($r['CreatedByUsername']) ? $r['CreatedByUsername'] : ''); ?></td>
              <td>
                <span class="status-pill <?php echo $statusClass; ?>">
                  <span class="status-dot"></span>
                  <?php echo h($statusText); ?>
                </span>
              </td>
              <td class="text-end">
                <div class="action-stack">
                  <a class="btn btn-muted btn-sm w-100 w-md-auto"
                     href="<?php echo h($self); ?>?edit=<?php echo (int)$r['JobTitleID']; ?>">
                    <i class="fas fa-pencil-alt"></i>
                    Edit
                  </a>

                  <!-- Toggle -->
                  <form method="post" class="d-inline"
                        onsubmit="return confirm('Toggle active status?');"
                        accept-charset="UTF-8">
                    <input type="hidden" name="csrf" value="<?php echo h($CSRF); ?>">
                    <input type="hidden" name="act" value="toggle">
                    <input type="hidden" name="JobTitleID" value="<?php echo (int)$r['JobTitleID']; ?>">
                    <input type="hidden" name="to" value="<?php echo $isActive?0:1; ?>">
                    <button class="btn btn-muted btn-sm w-100 w-md-auto" type="submit">
                      <i class="fas <?php echo $isActive ? 'fa-pause-circle' : 'fa-play-circle'; ?>"></i>
                      <?php echo $isActive ? 'Deactivate' : 'Activate'; ?>
                    </button>
                  </form>

                  <!-- Delete -->
                  <form method="post" class="d-inline"
                        onsubmit="return confirm('Delete this designation permanently?');"
                        accept-charset="UTF-8">
                    <input type="hidden" name="csrf" value="<?php echo h($CSRF); ?>">
                    <input type="hidden" name="act" value="delete">
                    <input type="hidden" name="JobTitleID" value="<?php echo (int)$r['JobTitleID']; ?>">
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
              <td colspan="6" class="text-center text-muted py-4">
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
// table uporer search + status filter (auto, page reload ছাড়া)
(function(){
  var searchInput  = document.getElementById('desigSearch');
  var statusFilter = document.getElementById('statusFilter');
  var table        = document.getElementById('desigTable');
  if (!table) return;

  var rows = table.querySelectorAll('tbody tr');

  function applyFilters(){
    var q  = (searchInput && searchInput.value ? searchInput.value : '').toLowerCase();
    var st = statusFilter ? statusFilter.value : '';

    Array.prototype.forEach.call(rows, function(tr){
      var rowStatus = tr.getAttribute('data-status') || '';
      var sIndex    = (tr.getAttribute('data-search') || '').toLowerCase();

      var matchSearch = !q || sIndex.indexOf(q) !== -1;
      var matchStatus = !st || rowStatus === st;

      tr.style.display = (matchSearch && matchStatus) ? '' : 'none';
    });
  }

  if (searchInput) {
    searchInput.addEventListener('input', applyFilters);
  }
  if (statusFilter) {
    statusFilter.addEventListener('change', applyFilters);
  }

  applyFilters();
})();
</script>

<?php require_once __DIR__ . '/../../include/footer.php'; ?>
