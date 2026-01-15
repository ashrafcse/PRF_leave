<?php 
/******************************
 * Permissions - Full CRUD (Designation page-এর স্টাইল/UX)
 * Table: dbo.Permissions
 * Required cols: PermissionID (PK), Code, Description (nullable)
 * Optional cols: IsActive (bit), CreatedAt (datetime), CreatedBy (int FK dbo.Users.UserID)
 ******************************/
ini_set('display_errors', 1);
error_reporting(E_ALL);

/* 1) Boot */
require_once __DIR__ . '/../../init.php';
require_login(); // block unauthenticated

/* 2) Helpers */
if (!function_exists('h')) {
    function h($s){
        return htmlspecialchars((string)$s, ENT_QUOTES|ENT_SUBSTITUTE, 'UTF-8', false);
    }
}
function self_name(){
    return strtok(basename($_SERVER['SCRIPT_NAME']), "?");
}
$self = self_name();

/* feature-detect columns (IsActive / CreatedAt / CreatedBy) */
function table_has_col(PDO $conn, $table, $column){
    static $cache = [];
    $key = strtolower($table.'|'.$column);
    if (array_key_exists($key, $cache)) return $cache[$key];

    $st = $conn->prepare("
        SELECT 1
          FROM INFORMATION_SCHEMA.COLUMNS
         WHERE TABLE_SCHEMA = 'dbo' AND TABLE_NAME = :t AND COLUMN_NAME = :c
    ");
    $st->execute([':t'=>$table, ':c'=>$column]);
    $has = (bool)$st->fetchColumn();
    $cache[$key] = $has;
    return $has;
}
$HAS_ACTIVE      = table_has_col($conn, 'Permissions', 'IsActive');
$HAS_CREATED     = table_has_col($conn, 'Permissions', 'CreatedAt');
$HAS_CREATED_BY  = table_has_col($conn, 'Permissions', 'CreatedBy');

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
    $msg = 'Permission created.';
    $msg_type = 'success';
}

$editRow = null;
$edit_id = isset($_GET['edit']) ? (int)$_GET['edit'] : 0;

/* CREATE */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['act']) && $_POST['act'] === 'create') {
    check_csrf();

    $Code        = isset($_POST['Code']) ? trim($_POST['Code']) : '';
    $Description = isset($_POST['Description']) ? trim($_POST['Description']) : '';
    $IsActive    = isset($_POST['IsActive']) ? 1 : 0;

    // CreatedBy guard if column exists
    $createdBy = null;
    if ($HAS_CREATED_BY) {
        $auth = isset($_SESSION['auth_user']) ? $_SESSION['auth_user'] : [];

        if (isset($auth['UserID'])) {
            $createdBy = (int)$auth['UserID'];
        } elseif (isset($auth['id'])) {
            $createdBy = (int)$auth['id'];
        } else {
            $createdBy = 0;
        }

        if ($createdBy <= 0) {
            // force re-login if CreatedBy is required
            logout();
            header('Location: ' . BASE_URL . 'login.php?next=' . rawurlencode($_SERVER['REQUEST_URI']));
            exit;
        }
    }

    if ($Code !== '') {
        try {
            // duplicate check (by Code)
            $chk = $conn->prepare("SELECT 1 FROM dbo.Permissions WHERE [Code] = :c");
            $chk->execute([':c'=>$Code]);
            if ($chk->fetchColumn()) {
                $msg = "Create failed: Duplicate code.";
                $msg_type = 'danger';
            } else {
                // build INSERT dynamically based on columns
                $cols = ['[Code]', '[Description]'];
                $vals = [':c', ':d'];
                if ($HAS_ACTIVE)     { $cols[] = 'IsActive';  $vals[]=':a'; }
                if ($HAS_CREATED)    { $cols[] = 'CreatedAt'; $vals[]='GETDATE()'; }
                if ($HAS_CREATED_BY) { $cols[] = 'CreatedBy'; $vals[]=':by'; }

                $sql = "INSERT INTO dbo.Permissions (".implode(',', $cols).") VALUES (".implode(',', $vals).")";
                $st  = $conn->prepare($sql);
                $st->bindValue(':c', $Code, PDO::PARAM_STR);
                if ($Description === '') {
                    $st->bindValue(':d', null, PDO::PARAM_NULL);
                } else {
                    $st->bindValue(':d', $Description, PDO::PARAM_STR);
                }
                if ($HAS_ACTIVE)     { $st->bindValue(':a', $IsActive, PDO::PARAM_INT); }
                if ($HAS_CREATED_BY) { $st->bindValue(':by', $createdBy, PDO::PARAM_INT); }
                $st->execute();

                header('Location: ' . $self . '?ok=1');
                exit;
            }
        } catch(PDOException $e) {
            $code   = $e->getCode();
            $msgErr = $e->getMessage();
            if ($code === '23000' && (stripos($msgErr,'unique')!==false || stripos($msgErr,'duplicate')!==false || stripos($msgErr,'uq_')!==false)) {
                $msg = "Create failed: Duplicate code.";
            } else {
                $msg = "Create failed: ".h($msgErr);
            }
            $msg_type = 'danger';
        }
    } else {
        $msg = "Permission code is required.";
        $msg_type = 'danger';
    }
}

/* PREPARE EDIT */
if ($edit_id > 0) {
    try {
        $select = "p.PermissionID, p.[Code], p.[Description]";
        if ($HAS_ACTIVE)     $select .= ", p.IsActive";
        if ($HAS_CREATED)    $select .= ", p.CreatedAt";
        if ($HAS_CREATED_BY) $select .= ", p.CreatedBy, u.Username AS CreatedByUsername";

        $sql = "SELECT $select
                  FROM dbo.Permissions p
                  ".($HAS_CREATED_BY ? "LEFT JOIN dbo.Users u ON u.UserID = p.CreatedBy" : "")."
                 WHERE p.PermissionID = :id";

        $st = $conn->prepare($sql);
        $st->execute([':id'=>$edit_id]);
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

    $PermissionID = isset($_POST['PermissionID']) ? (int)$_POST['PermissionID'] : 0;
    $Code         = isset($_POST['Code']) ? trim($_POST['Code']) : '';
    $Description  = isset($_POST['Description']) ? trim($_POST['Description']) : '';
    $IsActive     = isset($_POST['IsActive']) ? 1 : 0;

    if ($PermissionID > 0 && $Code !== '') {
        try {
            // dup check by Code excluding self
            $chk = $conn->prepare("SELECT 1 FROM dbo.Permissions WHERE [Code] = :c AND PermissionID <> :id");
            $chk->execute([':c'=>$Code, ':id'=>$PermissionID]);
            if ($chk->fetchColumn()) {
                $msg = "Update failed: Duplicate code.";
                $msg_type = 'danger';
            } else {
                $sets = ["[Code] = :c", "[Description] = :d"];
                if ($HAS_ACTIVE) $sets[] = "IsActive = :a";

                $sql = "UPDATE dbo.Permissions SET ".implode(', ', $sets)." WHERE PermissionID = :id";
                $st  = $conn->prepare($sql);
                $st->bindValue(':c', $Code, PDO::PARAM_STR);
                if ($Description === '') {
                    $st->bindValue(':d', null, PDO::PARAM_NULL);
                } else {
                    $st->bindValue(':d', $Description, PDO::PARAM_STR);
                }
                if ($HAS_ACTIVE) $st->bindValue(':a', $IsActive, PDO::PARAM_INT);
                $st->bindValue(':id', $PermissionID, PDO::PARAM_INT);
                $st->execute();

                header('Location: ' . $self);
                exit;
            }
        } catch (PDOException $e) {
            $code   = $e->getCode();
            $msgErr = $e->getMessage();
            if ($code === '23000' && (stripos($msgErr,'unique')!==false || stripos($msgErr,'duplicate')!==false || stripos($msgErr,'uq_')!==false)) {
                $msg = "Update failed: Duplicate code.";
            } else {
                $msg = "Update failed: ".h($msgErr);
            }
            $msg_type = 'danger';
        }
    } else {
        $msg = "Invalid data.";
        $msg_type = 'danger';
    }
}

/* TOGGLE ACTIVE (only if column exists) */
if ($HAS_ACTIVE && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['act']) && $_POST['act'] === 'toggle') {
    check_csrf();
    $id = isset($_POST['PermissionID']) ? (int)$_POST['PermissionID'] : 0;
    $to = isset($_POST['to']) ? (int)$_POST['to'] : 0;
    if ($id > 0) {
        try {
            $stmt = $conn->prepare("UPDATE dbo.Permissions SET IsActive = :a WHERE PermissionID = :id");
            $stmt->execute([':a'=>$to, ':id'=>$id]);
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
    $id = isset($_POST['PermissionID']) ? (int)$_POST['PermissionID'] : 0;
    if ($id > 0) {
        try {
            $stmt = $conn->prepare("DELETE FROM dbo.Permissions WHERE PermissionID = :id");
            $stmt->execute([':id'=>$id]);
            $msg = "Permission deleted.";
            $msg_type = 'success';
        } catch (PDOException $e) {
            $msg = "Delete failed: ".h($e->getMessage());
            $msg_type='danger';
        }
    }
}

/* 5) Query list + search */
$search = isset($_GET['q']) ? trim($_GET['q']) : '';
try {
    $select = "p.PermissionID, p.[Code], p.[Description]";
    if ($HAS_ACTIVE)     $select .= ", p.IsActive";
    if ($HAS_CREATED)    $select .= ", p.CreatedAt";
    if ($HAS_CREATED_BY) $select .= ", p.CreatedBy, u.Username AS CreatedByUsername";

    $base = "FROM dbo.Permissions p ".($HAS_CREATED_BY ? "LEFT JOIN dbo.Users u ON u.UserID = p.CreatedBy " : "");
    if ($search !== '') {
        $st = $conn->prepare("
          SELECT $select
          $base
         WHERE (p.[Code] LIKE :q OR p.[Description] LIKE :q)
         ORDER BY p.[Code]
        ");
        $st->execute([':q'=>'%'.$search.'%']);
        $rows = $st->fetchAll();
    } else {
        $rows = $conn->query("
          SELECT $select
          $base
          ORDER BY p.[Code]
        ")->fetchAll();
    }
} catch (PDOException $e) {
    $rows = [];
    $msg = "Load list failed: ".h($e->getMessage());
    $msg_type='danger';
}

/* 6) Render */
require_once __DIR__ . '/../../include/header.php';
?>

<!-- Bootstrap Icons CDN (design + icons) -->
<link rel="stylesheet"
      href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">

<style>
  .page-wrap{
    margin:28px auto;
    padding:0 12px 40px;
    background:
      radial-gradient(circle at top left, #eff6ff, transparent 55%),
      radial-gradient(circle at bottom right, #ecfeff, transparent 55%);
  }

  .page-title{
    font-weight:700;
    letter-spacing:.2px;
    font-size:24px;
    color:#0f172a;
    display:flex;
    align-items:center;
    gap:10px;
  }
  .page-title-badge{
    width:38px;
    height:38px;
    border-radius:999px;
    display:inline-flex;
    align-items:center;
    justify-content:center;
    background:linear-gradient(135deg,#4f46e5,#0ea5e9);
    color:#fff;
    box-shadow:0 8px 18px rgba(79,70,229,.35);
    font-size:18px;
  }
  .page-subtitle{
    font-size:13px;
    color:#64748b;
  }

  .card-elevated{
    border-radius:18px;
    border:1px solid #e5e7eb;
    box-shadow:0 14px 40px rgba(15,23,42,.10);
    background-color:#ffffff;
    position:relative;
    overflow:hidden;
  }
  .card-elevated::before{
    content:"";
    position:absolute;
    inset:-40%;
    opacity:0.22;
    background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='160' height='160' viewBox='0 0 160 160'%3E%3Cg fill='none' stroke='%23e5e7eb' stroke-width='1'%3E%3Ccircle cx='80' cy='80' r='34'/%3E%3Ccircle cx='0' cy='0' r='18'/%3E%3Ccircle cx='0' cy='160' r='18'/%3E%3Ccircle cx='160' cy='0' r='18'/%3E%3Ccircle cx='160' cy='160' r='18'/%3E%3C/g%3E%3C/svg%3E");
    background-repeat:repeat;
    pointer-events:none;
  }
  .card-elevated > .card-body{
    position:relative;
    z-index:1;
  }

  .section-header{
    display:flex;
    align-items:center;
    justify-content:space-between;
    margin-bottom:16px;
    gap:12px;
  }
  .section-title{
    display:flex;
    align-items:center;
    gap:10px;
    font-size:17px;
    font-weight:600;
    color:#0f172a;
  }
  .section-title-icon{
    width:30px;
    height:30px;
    border-radius:999px;
    display:inline-flex;
    align-items:center;
    justify-content:center;
    background:rgba(37,99,235,.08);
    color:#2563eb;
    font-size:16px;
  }
  .section-sub{
    font-size:12px;
    color:#6b7280;
  }

  .form-label{
    font-weight:600;
    color:#374151;
    font-size:13px;
  }
  .form-control,
  .form-select{
    border-radius:10px;
    border-color:#cbd5e1;
    font-size:13px;
  }

  .btn-brand{
    background:#2563eb;
    color:#fff !important;
    border:none;
    border-radius:999px;
    font-size:13px;
    padding:6px 16px;
  }
  .btn-brand:hover{
    background:#1d4ed8;
  }

  .btn-muted{
    background:#e5e7eb;
    color:#111827 !important;
    border:none;
    border-radius:999px;
    font-size:13px;
    padding:6px 14px;
  }
  .btn-muted:hover{
    background:#d1d5db;
  }

  .btn-danger-soft{
    background:#fee2e2;
    color:#b91c1c !important;
    border:1px solid #fecaca;
    border-radius:999px;
    font-size:12px;
    padding:4px 10px;
  }
  .btn-danger-soft:hover{
    background:#fecaca;
  }

  .btn-toggle{
    background:#e0f2fe;
    color:#0369a1 !important;
    border:1px solid #bae6fd;
    border-radius:999px;
    font-size:12px;
    padding:4px 10px;
  }
  .btn-toggle:hover{
    background:#bae6fd;
  }

  .table thead th{
    background:#f8fafc;
    color:#334155;
    border-bottom:1px solid #e5e7eb;
    font-size:12px;
    text-transform:uppercase;
    letter-spacing:.06em;
  }
  .table tbody td{
    vertical-align:middle;
    font-size:13px;
  }
  .table-hover tbody tr:hover{
    background-color:#f1f5f9;
  }

  .badge-soft{
    border:1px solid #e2e8f0;
    background:#f8fafc;
    border-radius:999px;
    padding:3px 8px;
    font-size:11px;
    color:#475569;
  }

  .status-badge-active{
    background:#dcfce7;
    border:1px solid #bbf7d0;
    color:#15803d;
  }
  .status-badge-inactive{
    background:#e5e7eb;
    border:1px solid #d4d4d8;
    color:#4b5563;
  }

  .search-input-icon{
    position:absolute;
    left:10px;
    top:50%;
    transform:translateY(-50%);
    font-size:14px;
    color:#9ca3af;
  }
  .search-input-wrapper{
    position:relative;
    width:100%;
  }
  .search-input-wrapper input{
    padding-left:30px;
  }

  @media (max-width:576px){
    .page-title{
      font-size:20px;
    }
  }
</style>

<div class="page-wrap">

  <!-- Header (শুধু title + subtitle) -->
  <div class="mb-3">
    <h1 class="page-title mb-1">
      <span class="page-title-badge">
        <i class="bi bi-shield-check"></i>
      </span>
      <span>Permissions</span>
    </h1>
    <div class="page-subtitle">
      System permissions manage করুন – add / edit / activate / deactivate।
    </div>
  </div>

  <!-- Alerts -->
  <?php if ($msg): ?>
    <div class="alert alert-<?php echo ($msg_type === 'danger' ? 'danger' : 'success'); ?> alert-dismissible fade show" role="alert">
      <i class="bi <?php echo ($msg_type === 'danger' ? 'bi-exclamation-triangle-fill' : 'bi-check-circle-fill'); ?>"></i>
      &nbsp;<?php echo $msg; ?>
      <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"
              style="box-shadow:none; outline:none;"></button>
    </div>
  <?php endif; ?>

  <!-- Create / Edit Card -->
  <div class="card card-elevated mb-4">
    <div class="card-body">
      <?php if (!empty($editRow)): ?>
        <div class="section-header">
          <div class="section-title">
            <span class="section-title-icon">
              <i class="bi bi-pencil-square"></i>
            </span>
            <span>Edit Permission</span>
          </div>
          <a class="btn btn-muted btn-sm" href="<?php echo h($self); ?>">
            <i class="bi bi-arrow-counterclockwise me-1"></i> Cancel
          </a>
        </div>

        <form method="post" accept-charset="UTF-8">
          <input type="hidden" name="csrf" value="<?php echo h($CSRF); ?>">
          <input type="hidden" name="act" value="update">
          <input type="hidden" name="PermissionID" value="<?php echo (int)$editRow['PermissionID']; ?>">

          <div class="row g-3">
            <div class="col-12 col-md-6">
              <label class="form-label">
                <i class="bi bi-code me-1"></i> Code
              </label>
              <input type="text"
                     name="Code"
                     class="form-control"
                     required
                     maxlength="200"
                     value="<?php echo h($editRow['Code']); ?>">
            </div>
            <div class="col-12 col-md-6">
              <label class="form-label">
                <i class="bi bi-card-text me-1"></i> Description
              </label>
              <input type="text"
                     name="Description"
                     class="form-control"
                     maxlength="500"
                     value="<?php echo h(isset($editRow['Description']) ? $editRow['Description'] : ''); ?>">
            </div>

            <?php if ($HAS_ACTIVE): ?>
            <div class="col-12 col-md-4 d-flex align-items-end">
              <div class="form-check">
                <input class="form-check-input"
                       type="checkbox"
                       name="IsActive"
                       id="isActiveEdit"
                       <?php echo ((int)(isset($editRow['IsActive']) ? $editRow['IsActive'] : 0) === 1 ? 'checked' : ''); ?>>
                <label class="form-check-label" for="isActiveEdit">Active</label>
              </div>
            </div>
            <?php endif; ?>

            <?php if ($HAS_CREATED || $HAS_CREATED_BY): ?>
            <div class="col-12">
              <div class="text-muted small">
                <?php if ($HAS_CREATED): ?>
                  Created:
                  <span class="badge-soft">
                    <i class="bi bi-calendar-event me-1"></i>
                    <?php echo h($editRow['CreatedAt']); ?>
                  </span>
                <?php endif; ?>
                <?php if ($HAS_CREATED_BY): ?>
                  &nbsp;by
                  <span class="badge-soft">
                    <i class="bi bi-person-circle me-1"></i>
                    <?php echo h(isset($editRow['CreatedByUsername']) ? $editRow['CreatedByUsername'] : ''); ?>
                  </span>
                <?php endif; ?>
              </div>
            </div>
            <?php endif; ?>

            <div class="col-12">
              <button class="btn btn-brand" type="submit">
                <i class="bi bi-save2 me-1"></i> Update Permission
              </button>
            </div>
          </div>
        </form>

      <?php else: ?>
        <div class="section-header">
          <div class="section-title">
            <span class="section-title-icon">
              <i class="bi bi-plus-circle"></i>
            </span>
            <span>Add Permission</span>
          </div>
        </div>

        <form method="post" class="row g-3" accept-charset="UTF-8">
          <input type="hidden" name="csrf" value="<?php echo h($CSRF); ?>">
          <input type="hidden" name="act" value="create">

          <div class="col-12 col-md-6">
            <label class="form-label">
              <i class="bi bi-code me-1"></i> Code
            </label>
            <input type="text"
                   name="Code"
                   class="form-control"
                   required
                   maxlength="200"
                   placeholder="e.g. manage.users">
          </div>
          <div class="col-12 col-md-6">
            <label class="form-label">
              <i class="bi bi-card-text me-1"></i> Description
            </label>
            <input type="text"
                   name="Description"
                   class="form-control"
                   maxlength="500"
                   placeholder="Optional description">
          </div>

          <?php if ($HAS_ACTIVE): ?>
          <div class="col-12 col-md-4 d-flex align-items-end">
            <div class="form-check">
              <input class="form-check-input"
                     type="checkbox"
                     name="IsActive"
                     id="isActiveCreate"
                     checked>
              <label class="form-check-label" for="isActiveCreate">Active</label>
            </div>
          </div>
          <?php endif; ?>

          <div class="col-12">
            <button class="btn btn-brand" type="submit">
              <i class="bi bi-plus-circle me-1"></i> Create Permission
            </button>
          </div>
        </form>
      <?php endif; ?>
    </div>
  </div>

  <!-- List Card -->
  <div class="card card-elevated">
    <div class="card-body">
      <?php
        $totalPermissions = count($rows);
        $activeCount   = 0;
        $inactiveCount = 0;
        if ($HAS_ACTIVE) {
            foreach ($rows as $r) {
                $flag = isset($r['IsActive']) ? (int)$r['IsActive'] : 0;
                if ($flag === 1) $activeCount++;
                else $inactiveCount++;
            }
        }
      ?>

      <div class="section-header">
        <div class="section-title">
          <span class="section-title-icon" style="background:rgba(59,130,246,.10);color:#1d4ed8;">
            <i class="bi bi-list-check"></i>
          </span>
          <span>All Permissions</span>
        </div>
        <div class="d-flex flex-wrap gap-2 align-items-center">
          <span class="badge-soft">
            <i class="bi bi-hash me-1"></i>
            Total: <?php echo $totalPermissions; ?>
          </span>
          <?php if ($HAS_ACTIVE): ?>
            <span class="badge-soft status-badge-active">
              <i class="bi bi-check-circle me-1"></i>
              Active: <?php echo $activeCount; ?>
            </span>
            <span class="badge-soft status-badge-inactive">
              <i class="bi bi-pause-circle me-1"></i>
              Inactive: <?php echo $inactiveCount; ?>
            </span>
          <?php endif; ?>
        </div>
      </div>

      <!-- Search/filter table-এর ঠিক উপরে -->
      <form method="get" class="row g-2 align-items-end mb-3" accept-charset="UTF-8">
        <div class="col-sm-8 col-md-6">
          <label class="form-label mb-1">
            <i class="bi bi-search me-1"></i> Search
          </label>
          <div class="search-input-wrapper">
            <span class="search-input-icon">
              <i class="bi bi-search"></i>
            </span>
            <input type="text"
                   name="q"
                   class="form-control"
                   placeholder="Search by code or description..."
                   value="<?php echo h($search); ?>">
          </div>
        </div>
        <div class="col-sm-4 col-md-6 text-sm-end">
          <div class="d-flex justify-content-sm-end gap-2 mt-3 mt-sm-0">
            <button class="btn btn-brand" type="submit">
              <i class="bi bi-filter me-1"></i> Filter
            </button>
            <a class="btn btn-muted" href="<?php echo h($self); ?>">
              <i class="bi bi-x-circle me-1"></i> Reset
            </a>
          </div>
        </div>
      </form>

      <div class="table-responsive">
        <table class="table table-hover align-middle">
          <thead>
            <tr>
              <th>#</th>
              <th>Code</th>
              <th>Description</th>
              <?php if ($HAS_CREATED): ?><th>Created</th><?php endif; ?>
              <?php if ($HAS_CREATED_BY): ?><th>By</th><?php endif; ?>
              <?php if ($HAS_ACTIVE): ?><th>Status</th><?php endif; ?>
              <th class="text-end">Actions</th>
            </tr>
          </thead>
          <tbody>
          <?php foreach($rows as $r): ?>
            <tr>
              <td><?php echo (int)$r['PermissionID']; ?></td>
              <td>
                <i class="bi bi-code-slash me-1 text-muted"></i>
                <?php echo h($r['Code']); ?>
              </td>
              <td><?php echo h(isset($r['Description']) ? $r['Description'] : ''); ?></td>

              <?php if ($HAS_CREATED): ?>
                <td>
                  <span class="badge-soft">
                    <i class="bi bi-calendar-event me-1"></i>
                    <?php echo h($r['CreatedAt']); ?>
                  </span>
                </td>
              <?php endif; ?>
              <?php if ($HAS_CREATED_BY): ?>
                <td>
                  <span class="badge-soft">
                    <i class="bi bi-person-circle me-1"></i>
                    <?php echo h(isset($r['CreatedByUsername']) ? $r['CreatedByUsername'] : ''); ?>
                  </span>
                </td>
              <?php endif; ?>

              <?php if ($HAS_ACTIVE): ?>
              <td>
                <?php if ((int)(isset($r['IsActive']) ? $r['IsActive'] : 0) === 1): ?>
                  <span class="badge-soft status-badge-active">
                    <i class="bi bi-check-circle-fill me-1"></i> Active
                  </span>
                <?php else: ?>
                  <span class="badge-soft status-badge-inactive">
                    <i class="bi bi-slash-circle me-1"></i> Inactive
                  </span>
                <?php endif; ?>
              </td>
              <?php endif; ?>

              <td class="text-end">
                <div class="d-inline-flex flex-wrap gap-1">
                  <a class="btn btn-muted btn-sm"
                     href="<?php echo h($self); ?>?edit=<?php echo (int)$r['PermissionID']; ?>">
                    <i class="bi bi-pencil me-1"></i> Edit
                  </a>

                  <?php if ($HAS_ACTIVE): ?>
                  <!-- Toggle -->
                  <form method="post"
                        class="d-inline"
                        onsubmit="return confirm('Toggle active status for this permission?');"
                        accept-charset="UTF-8">
                    <input type="hidden" name="csrf" value="<?php echo h($CSRF); ?>">
                    <input type="hidden" name="act" value="toggle">
                    <input type="hidden" name="PermissionID" value="<?php echo (int)$r['PermissionID']; ?>">
                    <input type="hidden" name="to"
                           value="<?php echo ((int)(isset($r['IsActive']) ? $r['IsActive'] : 0) === 1 ? 0 : 1); ?>">

                    <button class="btn btn-toggle btn-sm" type="submit">
                      <?php if ((int)(isset($r['IsActive']) ? $r['IsActive'] : 0) === 1): ?>
                        <i class="bi bi-pause-circle me-1"></i> Deactivate
                      <?php else: ?>
                        <i class="bi bi-play-circle me-1"></i> Activate
                      <?php endif; ?>
                    </button>
                  </form>
                  <?php endif; ?>

                  <!-- Delete -->
                  <form method="post"
                        class="d-inline"
                        onsubmit="return confirm('Delete this permission permanently?');"
                        accept-charset="UTF-8">
                    <input type="hidden" name="csrf" value="<?php echo h($CSRF); ?>">
                    <input type="hidden" name="act" value="delete">
                    <input type="hidden" name="PermissionID" value="<?php echo (int)$r['PermissionID']; ?>">
                    <button class="btn btn-danger-soft btn-sm" type="submit">
                      <i class="bi bi-trash me-1"></i> Delete
                    </button>
                  </form>
                </div>
              </td>
            </tr>
          <?php endforeach; ?>

          <?php if (empty($rows)): ?>
            <tr>
              <td colspan="<?php echo 4 + (int)$HAS_ACTIVE + (int)$HAS_CREATED + (int)$HAS_CREATED_BY; ?>"
                  class="text-center text-muted py-4">
                No permissions found.
              </td>
            </tr>
          <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>

</div><!-- /.page-wrap -->

<?php require_once __DIR__ . '/../../include/footer.php'; ?>
