<?php
/***********************
 * Leave Types - Full CRUD (same design + auto search/filter)
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

/* 4) Actions */
$msg = '';
$msg_type = 'success';

if (isset($_GET['ok']) && $_GET['ok'] === '1') {
  $msg = 'Leave type created.';
  $msg_type = 'success';
}

$editRow = null;
$edit_id = isset($_GET['edit']) ? (int)$_GET['edit'] : 0;

function normalize_name($s){
  $s = trim((string)$s);
  $s = trim(preg_replace('/\s+/', ' ', $s));
  return $s;
}
function parse_decimal($s){
  // allow "10", "10.5", "10.50"
  $s = trim((string)$s);
  $s = str_replace(',', '', $s);
  if ($s === '') return null;
  if (!preg_match('/^\d+(\.\d{1,2})?$/', $s)) return null;
  return $s;
}

/* CREATE */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['act']) && $_POST['act'] === 'create') {
  check_csrf();

  $name = normalize_name(isset($_POST['LeaveTypeName']) ? $_POST['LeaveTypeName'] : '');
  $max  = parse_decimal(isset($_POST['MaxDaysPerYear']) ? $_POST['MaxDaysPerYear'] : '');
  $isActive = isset($_POST['IsActive']) ? 1 : 0;

  if ($name === '') {
    $msg_type = 'danger';
    $msg = "Leave type name is required.";
  } elseif ($max === null) {
    $msg_type = 'danger';
    $msg = "Max days per year must be a valid number (e.g. 12 or 12.50).";
  } else {
    try {
      $stmt = $conn->prepare("
        INSERT INTO dbo.LeaveTypes (LeaveTypeName, MaxDaysPerYear, IsActive)
        VALUES (:n, :m, :a)
      ");
      $stmt->bindValue(':n', $name, PDO::PARAM_STR);
      $stmt->bindValue(':m', $max,  PDO::PARAM_STR); // decimal -> string safe
      $stmt->bindValue(':a', (int)$isActive, PDO::PARAM_INT);
      $stmt->execute();

      header('Location: ' . $self . '?ok=1'); exit;
    } catch (PDOException $e) {
      $msg_type = 'danger';
      $msg = ($e->getCode() == '23000')
        ? "Create failed: Duplicate leave type name."
        : "Create failed: " . h($e->getMessage());
    }
  }
}

/* PREPARE EDIT */
if ($edit_id > 0) {
  try {
    $st = $conn->prepare("
      SELECT LeaveTypeID, LeaveTypeName, MaxDaysPerYear, IsActive
        FROM dbo.LeaveTypes
       WHERE LeaveTypeID = :id
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

/* UPDATE */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['act']) && $_POST['act'] === 'update') {
  check_csrf();

  $id   = isset($_POST['LeaveTypeID']) ? (int)$_POST['LeaveTypeID'] : 0;
  $name = normalize_name(isset($_POST['LeaveTypeName']) ? $_POST['LeaveTypeName'] : '');
  $max  = parse_decimal(isset($_POST['MaxDaysPerYear']) ? $_POST['MaxDaysPerYear'] : '');
  $isActive = isset($_POST['IsActive']) ? 1 : 0;

  if ($id <= 0 || $name === '' || $max === null) {
    $msg_type = 'danger';
    $msg = "Invalid data.";
  } else {
    try {
      $stmt = $conn->prepare("
        UPDATE dbo.LeaveTypes
           SET LeaveTypeName = :n,
               MaxDaysPerYear = :m,
               IsActive = :a
         WHERE LeaveTypeID = :id
      ");
      $stmt->execute(array(':n'=>$name, ':m'=>$max, ':a'=>$isActive, ':id'=>$id));
      header('Location: ' . $self); exit;
    } catch (PDOException $e) {
      $msg_type = 'danger';
      $msg = ($e->getCode() == '23000')
        ? "Update failed: Duplicate leave type name."
        : "Update failed: ".h($e->getMessage());
    }
  }
}

/* TOGGLE */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['act']) && $_POST['act'] === 'toggle') {
  check_csrf();

  $id = isset($_POST['LeaveTypeID']) ? (int)$_POST['LeaveTypeID'] : 0;
  $to = isset($_POST['to']) ? (int)$_POST['to'] : 0;

  if ($id > 0) {
    try {
      $stmt = $conn->prepare("UPDATE dbo.LeaveTypes SET IsActive = :a WHERE LeaveTypeID = :id");
      $stmt->execute(array(':a'=>$to, ':id'=>$id));
      $msg_type = 'success';
      $msg = $to ? "Activated." : "Deactivated.";
    } catch (PDOException $e) {
      $msg_type = 'danger';
      $msg = "Toggle failed: ".h($e->getMessage());
    }
  }
}

/* DELETE */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['act']) && $_POST['act'] === 'delete') {
  check_csrf();

  $id = isset($_POST['LeaveTypeID']) ? (int)$_POST['LeaveTypeID'] : 0;
  if ($id > 0) {
    try {
      $stmt = $conn->prepare("DELETE FROM dbo.LeaveTypes WHERE LeaveTypeID = :id");
      $stmt->execute(array(':id'=>$id));
      $msg_type = 'success';
      $msg = "Leave type deleted.";
    } catch (PDOException $e) {
      $msg_type = 'danger';
      $msg = "Delete failed: ".h($e->getMessage());
    }
  }
}

/* 5) Query list */
try {
  $rows = $conn->query("
    SELECT LeaveTypeID, LeaveTypeName, MaxDaysPerYear, IsActive
      FROM dbo.LeaveTypes
     ORDER BY LeaveTypeName
  ")->fetchAll();
} catch (PDOException $e) {
  $rows = array();
  $msg_type = 'danger';
  $msg = "Load list failed: ".h($e->getMessage());
}

/* 6) Render */
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

  .action-stack > *{ margin:4px; }
  @media (min-width:768px){ .action-stack{ display:inline-flex; gap:6px; } }

  .filters-helper{ font-size:12px; color:#6b7280; }
</style>

<div class="page-wrap">

  <div class="d-flex flex-wrap align-items-center justify-content-between mb-3 gap-2">
    <div>
      <h1 class="page-title mb-1">
        <i class="fas fa-calendar-check"></i>
        Leave Types
      </h1>
      <div class="page-subtitle">
        Leave type তথ্য manage করুন – create, update, status toggle & delete.
      </div>
    </div>
    <span class="badge-soft">
      <i class="fas fa-layer-group"></i>
      Total Leave Types: <?php echo count($rows); ?>
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
            <span>Edit Leave Type</span>
          </div>
          <a class="btn btn-muted btn-sm" href="<?php echo h($self); ?>">
            <i class="fas fa-times-circle"></i>
            Cancel
          </a>
        </div>

        <form method="post" accept-charset="UTF-8">
          <input type="hidden" name="csrf" value="<?php echo h($CSRF); ?>">
          <input type="hidden" name="act" value="update">
          <input type="hidden" name="LeaveTypeID" value="<?php echo (int)$editRow['LeaveTypeID']; ?>">

          <div class="row g-3">
            <div class="col-12 col-md-6">
              <label class="form-label">Leave Type Name</label>
              <input type="text" name="LeaveTypeName" class="form-control" required
                     value="<?php echo h($editRow['LeaveTypeName']); ?>">
            </div>

            <div class="col-12 col-md-3">
              <label class="form-label">Max Days / Year</label>
              <input type="number" step="0.01" min="0" name="MaxDaysPerYear" class="form-control" required
                     value="<?php echo h($editRow['MaxDaysPerYear']); ?>">
            </div>

            <div class="col-12 col-md-3 d-flex align-items-end">
              <?php
                $ea = isset($editRow['IsActive']) ? (int)$editRow['IsActive'] : 0;
              ?>
              <div class="form-check">
                <input class="form-check-input" type="checkbox" name="IsActive" id="isActiveEdit"
                       <?php echo ($ea===1?'checked':''); ?>>
                <label class="form-check-label" for="isActiveEdit">Active</label>
              </div>
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
          <span>Add Leave Type</span>
        </div>

        <form method="post" class="row g-3" accept-charset="UTF-8">
          <input type="hidden" name="csrf" value="<?php echo h($CSRF); ?>">
          <input type="hidden" name="act" value="create">

          <div class="col-12 col-md-6">
            <label class="form-label">Leave Type Name</label>
            <input type="text" name="LeaveTypeName" class="form-control" required placeholder="e.g. Casual / Sick">
          </div>

          <div class="col-12 col-md-3">
            <label class="form-label">Max Days / Year</label>
            <input type="number" step="0.01" min="0" name="MaxDaysPerYear" class="form-control" required placeholder="e.g. 12">
          </div>

          <div class="col-12 col-md-3 d-flex align-items-end">
            <div class="form-check">
              <input class="form-check-input" type="checkbox" name="IsActive" id="isActiveCreate" checked>
              <label class="form-check-label" for="isActiveCreate">Active</label>
            </div>
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
          <span>All Leave Types</span>
        </div>
        <span class="filters-helper">
          <i class="fas fa-filter me-1"></i>
          নিচের search & status filter দিয়ে table auto-filter হবে (reload ছাড়াই)।
        </span>
      </div>

      <div class="row g-2 mb-3">
        <div class="col-12 col-md-6">
          <label class="form-label small mb-1">Search (Name)</label>
          <input type="text" id="ltSearch" class="form-control" placeholder="Type to search...">
        </div>
        <div class="col-6 col-md-3">
          <label class="form-label small mb-1">Status</label>
          <select id="ltStatusFilter" class="form-select">
            <option value="">All</option>
            <option value="1">Active</option>
            <option value="0">Inactive</option>
          </select>
        </div>
      </div>

      <div class="table-responsive">
        <table class="table table-hover align-middle" id="ltTable">
          <thead>
            <tr>
              <th>#</th>
              <th>Leave Type</th>
              <th>Max Days/Year</th>
              <th>Status</th>
              <th class="text-end">Actions</th>
            </tr>
          </thead>
          <tbody>
          <?php foreach($rows as $r): ?>
            <?php
              // IsActive nullable -> treat NULL as 0
              $isActive = isset($r['IsActive']) ? (int)$r['IsActive'] : 0;
              $statusClass = $isActive ? 'status-pill-active' : 'status-pill-inactive';
              $statusText  = $isActive ? 'Active' : 'Inactive';

              $searchIndex = strtolower(
                (string)$r['LeaveTypeName'].' '.(string)$r['MaxDaysPerYear']
              );
            ?>
            <tr data-status="<?php echo $isActive; ?>"
                data-search="<?php echo h($searchIndex); ?>">
              <td><?php echo (int)$r['LeaveTypeID']; ?></td>
              <td><?php echo h($r['LeaveTypeName']); ?></td>
              <td><?php echo h($r['MaxDaysPerYear']); ?></td>
              <td>
                <span class="status-pill <?php echo $statusClass; ?>">
                  <span class="status-dot"></span>
                  <?php echo h($statusText); ?>
                </span>
              </td>
              <td class="text-end">
                <div class="action-stack">
                  <a class="btn btn-muted btn-sm w-100 w-md-auto"
                     href="<?php echo h($self); ?>?edit=<?php echo (int)$r['LeaveTypeID']; ?>">
                    <i class="fas fa-pencil-alt"></i>
                    Edit
                  </a>

                  <form method="post" class="d-inline"
                        onsubmit="return confirm('Toggle active status?');"
                        accept-charset="UTF-8">
                    <input type="hidden" name="csrf" value="<?php echo h($CSRF); ?>">
                    <input type="hidden" name="act" value="toggle">
                    <input type="hidden" name="LeaveTypeID" value="<?php echo (int)$r['LeaveTypeID']; ?>">
                    <input type="hidden" name="to" value="<?php echo $isActive?0:1; ?>">
                    <button class="btn btn-muted btn-sm w-100 w-md-auto" type="submit">
                      <i class="fas <?php echo $isActive ? 'fa-pause-circle' : 'fa-play-circle'; ?>"></i>
                      <?php echo $isActive?'Deactivate':'Activate'; ?>
                    </button>
                  </form>

                  <form method="post" class="d-inline"
                        onsubmit="return confirm('Delete this leave type permanently?');"
                        accept-charset="UTF-8">
                    <input type="hidden" name="csrf" value="<?php echo h($CSRF); ?>">
                    <input type="hidden" name="act" value="delete">
                    <input type="hidden" name="LeaveTypeID" value="<?php echo (int)$r['LeaveTypeID']; ?>">
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
            <tr><td colspan="5" class="text-center text-muted py-4">
              <i class="fas fa-folder-open me-1"></i> No data
            </td></tr>
          <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>

</div><!-- /.page-wrap -->

<script>
(function(){
  var searchInput  = document.getElementById('ltSearch');
  var statusFilter = document.getElementById('ltStatusFilter');
  var table        = document.getElementById('ltTable');
  if (!table) return;

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
})();
</script>

<?php require_once __DIR__ . '/../../include/footer.php'; ?>
