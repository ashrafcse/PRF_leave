<?php 
/******************************
 * Roles - Full CRUD (Designation page-এর স্টাইল/UX)
 * Table: dbo.Roles
 * Required cols: RoleID (PK), RoleName, Description (nullable)
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
        return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8', false);
    }
}
function self_name(){
    return strtok(basename($_SERVER['SCRIPT_NAME']), "?");
}
$self = self_name();

/* feature-detect columns (IsActive / CreatedAt / CreatedBy) */
function table_has_col(PDO $conn, $table, $column){
    static $cache = array();
    $key = strtolower($table.'|'.$column);
    if (array_key_exists($key, $cache)) return $cache[$key];
    $st = $conn->prepare("
        SELECT 1
          FROM INFORMATION_SCHEMA.COLUMNS
         WHERE TABLE_SCHEMA='dbo' AND TABLE_NAME=:t AND COLUMN_NAME=:c
    ");
    $st->execute(array(':t'=>$table, ':c'=>$column));
    $has = (bool)$st->fetchColumn();
    $cache[$key] = $has;
    return $has;
}
$HAS_ACTIVE      = table_has_col($conn, 'Roles', 'IsActive');
$HAS_CREATED_AT  = table_has_col($conn, 'Roles', 'CreatedAt');
$HAS_CREATED_BY  = table_has_col($conn, 'Roles', 'CreatedBy');

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
    $msg = 'Role created.';
    $msg_type = 'success';
}

$editRow = null;
$edit_id = isset($_GET['edit']) ? (int)$_GET['edit'] : 0;

/* CREATE */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['act']) && $_POST['act'] === 'create') {
    check_csrf();

    $RoleName    = trim(isset($_POST['RoleName']) ? $_POST['RoleName'] : '');
    $Description = trim(isset($_POST['Description']) ? $_POST['Description'] : '');
    $IsActive    = isset($_POST['IsActive']) ? 1 : 0;

    // CreatedBy guard if column exists
    $createdBy = null;
    if ($HAS_CREATED_BY) {
        $auth = isset($_SESSION['auth_user']) ? $_SESSION['auth_user'] : array();
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

    if ($RoleName !== '') {
        try {
            // duplicate check (by RoleName)
            $chk = $conn->prepare("SELECT 1 FROM dbo.Roles WHERE RoleName = :n");
            $chk->execute(array(':n'=>$RoleName));
            if ($chk->fetchColumn()) {
                $msg = "Create failed: Duplicate role name.";
                $msg_type = 'danger';
            } else {
                // dynamic insert by available cols
                $cols = array('RoleName','[Description]');
                $vals = array(':n',':d');
                if ($HAS_ACTIVE)      { $cols[]='IsActive';  $vals[]=':a'; }
                if ($HAS_CREATED_AT)  { $cols[]='CreatedAt'; $vals[]='GETDATE()'; }
                if ($HAS_CREATED_BY)  { $cols[]='CreatedBy'; $vals[]=':by'; }

                $sql = "INSERT INTO dbo.Roles (".implode(',', $cols).") VALUES (".implode(',', $vals).")";
                $st  = $conn->prepare($sql);
                $st->bindValue(':n', $RoleName, PDO::PARAM_STR);
                if ($Description === '') $st->bindValue(':d', null, PDO::PARAM_NULL);
                else $st->bindValue(':d', $Description, PDO::PARAM_STR);
                if ($HAS_ACTIVE)     $st->bindValue(':a', $IsActive, PDO::PARAM_INT);
                if ($HAS_CREATED_BY) $st->bindValue(':by', $createdBy, PDO::PARAM_INT);
                $st->execute();

                header('Location: ' . $self . '?ok=1');
                exit;
            }
        } catch(PDOException $e) {
            $code   = $e->getCode();
            $msgErr = $e->getMessage();
            if ($code === '23000' && (stripos($msgErr,'unique')!==false || stripos($msgErr,'duplicate')!==false || stripos($msgErr,'uq_')!==false)) {
                $msg = "Create failed: Duplicate role name.";
            } else {
                $msg = "Create failed: ".h($msgErr);
            }
            $msg_type = 'danger';
        }
    } else {
        $msg = "Role name is required.";
        $msg_type = 'danger';
    }
}

/* PREPARE EDIT */
if ($edit_id > 0) {
    try {
        $select = "r.RoleID, r.RoleName, r.[Description]";
        if ($HAS_ACTIVE)     $select .= ", r.IsActive";
        if ($HAS_CREATED_AT) $select .= ", r.CreatedAt";
        if ($HAS_CREATED_BY) $select .= ", r.CreatedBy, u.Username AS CreatedByUsername";

        $sql = "SELECT $select
                  FROM dbo.Roles r
                  ".($HAS_CREATED_BY ? "LEFT JOIN dbo.Users u ON u.UserID = r.CreatedBy" : "")."
                 WHERE r.RoleID = :id";

        $st = $conn->prepare($sql);
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

    $RoleID      = isset($_POST['RoleID']) ? (int)$_POST['RoleID'] : 0;
    $RoleName    = isset($_POST['RoleName']) ? trim($_POST['RoleName']) : '';
    $Description = isset($_POST['Description']) ? trim($_POST['Description']) : '';
    $IsActive    = isset($_POST['IsActive']) ? 1 : 0;

    if ($RoleID > 0 && $RoleName !== '') {
        try {
            // dup check by RoleName excluding self
            $chk = $conn->prepare("SELECT 1 FROM dbo.Roles WHERE RoleName = :n AND RoleID <> :id");
            $chk->execute(array(':n'=>$RoleName, ':id'=>$RoleID));
            if ($chk->fetchColumn()) {
                $msg = "Update failed: Duplicate role name.";
                $msg_type = 'danger';
            } else {
                $sets = array("RoleName = :n", "[Description] = :d");
                if ($HAS_ACTIVE) $sets[] = "IsActive = :a";

                $sql = "UPDATE dbo.Roles SET ".implode(', ', $sets)." WHERE RoleID = :id";
                $st  = $conn->prepare($sql);
                $st->bindValue(':n', $RoleName, PDO::PARAM_STR);
                if ($Description === '') $st->bindValue(':d', null, PDO::PARAM_NULL);
                else $st->bindValue(':d', $Description, PDO::PARAM_STR);
                if ($HAS_ACTIVE) $st->bindValue(':a', $IsActive, PDO::PARAM_INT);
                $st->bindValue(':id', $RoleID, PDO::PARAM_INT);
                $st->execute();

                header('Location: ' . $self);
                exit;
            }
        } catch (PDOException $e) {
            $code   = $e->getCode();
            $msgErr = $e->getMessage();
            if ($code === '23000' && (stripos($msgErr,'unique')!==false || stripos($msgErr,'duplicate')!==false || stripos($msgErr,'uq_')!==false)) {
                $msg = "Update failed: Duplicate role name.";
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
    $id = isset($_POST['RoleID']) ? (int)$_POST['RoleID'] : 0;
    $to = isset($_POST['to']) ? (int)$_POST['to'] : 0;
    if ($id > 0) {
        try {
            $stmt = $conn->prepare("UPDATE dbo.Roles SET IsActive = :a WHERE RoleID = :id");
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
    $id = isset($_POST['RoleID']) ? (int)$_POST['RoleID'] : 0;
    if ($id > 0) {
        try {
            $stmt = $conn->prepare("DELETE FROM dbo.Roles WHERE RoleID = :id");
            $stmt->execute(array(':id'=>$id));
            $msg = "Role deleted.";
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
    $select = "r.RoleID, r.RoleName, r.[Description]";
    if ($HAS_ACTIVE)     $select .= ", r.IsActive";
    if ($HAS_CREATED_AT) $select .= ", r.CreatedAt";
    if ($HAS_CREATED_BY) $select .= ", r.CreatedBy, u.Username AS CreatedByUsername";

    $base = "FROM dbo.Roles r ".($HAS_CREATED_BY ? "LEFT JOIN dbo.Users u ON u.UserID = r.CreatedBy " : "");
    if ($search !== '') {
        $st = $conn->prepare("
          SELECT $select
          $base
         WHERE (r.RoleName LIKE :q OR r.[Description] LIKE :q)
         ORDER BY r.RoleName
        ");
        $st->execute(array(':q'=>'%'.$search.'%'));
        $rows = $st->fetchAll();
    } else {
        $rows = $conn->query("
          SELECT $select
          $base
          ORDER BY r.RoleName
        ")->fetchAll();
    }
} catch (PDOException $e) {
    $rows = array();
    $msg = "Load list failed: ".h($e->getMessage());
    $msg_type='danger';
}

/* 6) Render */
require_once __DIR__ . '/../../include/header.php';
?>

<!-- Bootstrap Icons CDN (common icons so that সব icon কাজ করবে) -->
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
    padding:6px 12px;
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

  .btn-icon{
    padding:4px 7px;
    border-radius:999px;
    display:inline-flex;
    align-items:center;
    justify-content:center;
  }
  .btn-icon i{
    font-size:14px;
  }

  .badge-soft{
    border:1px solid #e2e8f0;
    background:#f8fafc;
    border-radius:999px;
    padding:3px 8px;
    font-size:11px;
    color:#475569;
  }

  .table-roles{
    border-radius:14px;
    overflow:hidden;
  }
  .table-roles thead th{
    background:#f8fafc;
    color:#334155;
    border-bottom:1px solid #e5e7eb;
    font-size:12px;
    text-transform:uppercase;
    letter-spacing:.06em;
  }
  .table-roles tbody td{
    vertical-align:middle;
    font-size:13px;
    border-top:1px solid #e5e7eb;
  }
  .table-roles tbody tr:nth-child(even){
    background:#f9fafb;
  }
  .table-roles tbody tr:hover{
    background-color:#eef2ff;
  }

  .col-id{
    width:70px;
    text-align:center;
    white-space:nowrap;
  }
  .col-id .id-pill{
    display:inline-flex;
    align-items:center;
    justify-content:center;
    padding:2px 8px;
    border-radius:999px;
    background:#e0f2fe;
    color:#0f172a;
    font-size:11px;
    border:1px solid #bae6fd;
  }

  .col-actions{
    white-space:nowrap;
  }

  .actions-stack{
    display:flex;
    align-items:center;
    justify-content:flex-end;
    gap:6px;
    flex-wrap:nowrap;
  }
  .actions-stack form,
  .actions-stack a{
    display:inline-block;
    margin:0;
  }

  .status-badge-active{
    background:#ecfdf3;
    color:#15803d;
    border-color:#bbf7d0;
  }
  .status-badge-inactive{
    background:#f9fafb;
    color:#6b7280;
    border-color:#e5e7eb;
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
    .actions-stack{
      justify-content:flex-start;
    }
  }
</style>

<div class="page-wrap">

  <!-- Header -->
  <div class="mb-3">
    <h1 class="page-title mb-1">
      <span class="page-title-badge">
        <i class="bi bi-people-fill"></i>
      </span>
      <span>Roles</span>
    </h1>
    <div class="page-subtitle">
      System roles manage করুন, description, status ও history সহ।
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
            <span>Edit Role</span>
          </div>
          <a class="btn btn-muted btn-sm" href="<?php echo h($self); ?>">
            <i class="bi bi-x-circle"></i>&nbsp;Cancel
          </a>
        </div>

        <form method="post" accept-charset="UTF-8">
          <input type="hidden" name="csrf" value="<?php echo h($CSRF); ?>">
          <input type="hidden" name="act" value="update">
          <input type="hidden" name="RoleID" value="<?php echo (int)$editRow['RoleID']; ?>">

          <div class="row g-3">
            <div class="col-12 col-md-6">
              <label class="form-label">
                <i class="bi bi-tag"></i>&nbsp;Role Name
              </label>
              <input type="text" name="RoleName" class="form-control" required maxlength="200"
                     value="<?php echo h($editRow['RoleName']); ?>">
            </div>
            <div class="col-12 col-md-6">
              <label class="form-label">
                <i class="bi bi-text-left"></i>&nbsp;Description
              </label>
              <input type="text" name="Description" class="form-control" maxlength="500"
                     value="<?php echo h(isset($editRow['Description']) ? $editRow['Description'] : ''); ?>">
            </div>

            <?php if ($HAS_ACTIVE): ?>
            <div class="col-12 col-md-4 d-flex align-items-end">
              <div class="form-check">
                <input class="form-check-input" type="checkbox" name="IsActive"
                       id="isActiveEdit" <?php echo ((isset($editRow['IsActive']) && (int)$editRow['IsActive'] === 1) ? 'checked' : ''); ?>>
                <label class="form-check-label" for="isActiveEdit">Active</label>
              </div>
            </div>
            <?php endif; ?>

            <?php if ($HAS_CREATED_AT || $HAS_CREATED_BY): ?>
            <div class="col-12">
              <div class="text-muted small">
                <?php if ($HAS_CREATED_AT): ?>
                  Created:
                  <span class="badge-soft">
                    <i class="bi bi-clock"></i>&nbsp;<?php echo h($editRow['CreatedAt']); ?>
                  </span>
                <?php endif; ?>
                <?php if ($HAS_CREATED_BY): ?>
                  &nbsp;by
                  <span class="badge-soft">
                    <i class="bi bi-person"></i>&nbsp;<?php echo isset($editRow['CreatedByUsername']) ? h($editRow['CreatedByUsername']) : ''; ?>
                  </span>
                <?php endif; ?>
              </div>
            </div>
            <?php endif; ?>

            <div class="col-12 d-grid d-md-inline">
              <button class="btn btn-brand w-100 w-md-auto">
                <i class="bi bi-save"></i>&nbsp;Update Role
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
            <span>Add Role</span>
          </div>
        </div>

        <form method="post" class="row g-3" accept-charset="UTF-8">
          <input type="hidden" name="csrf" value="<?php echo h($CSRF); ?>">
          <input type="hidden" name="act" value="create">

          <div class="col-12 col-md-6">
            <label class="form-label">
              <i class="bi bi-tag"></i>&nbsp;Role Name
            </label>
            <input type="text" name="RoleName" class="form-control" required maxlength="200" placeholder="e.g. Admin">
          </div>
          <div class="col-12 col-md-6">
            <label class="form-label">
              <i class="bi bi-text-left"></i>&nbsp;Description
            </label>
            <input type="text" name="Description" class="form-control" maxlength="500" placeholder="Optional">
          </div>

          <?php if ($HAS_ACTIVE): ?>
          <div class="col-12 col-md-4 d-flex align-items-end">
            <div class="form-check">
              <input class="form-check-input" type="checkbox" name="IsActive" id="isActiveCreate" checked>
              <label class="form-check-label" for="isActiveCreate">Active</label>
            </div>
          </div>
          <?php endif; ?>

          <div class="col-12 d-grid d-md-inline">
            <button class="btn btn-brand w-100 w-md-auto">
              <i class="bi bi-plus-circle"></i>&nbsp;Create Role
            </button>
          </div>
        </form>
      <?php endif; ?>
    </div>
  </div>

  <!-- List Card -->
  <div class="card card-elevated">
    <div class="card-body">
      <div class="section-header">
        <div class="section-title">
          <span class="section-title-icon" style="background:rgba(59,130,246,.10);color:#1d4ed8;">
            <i class="bi bi-list-ul"></i>
          </span>
          <span>All Roles</span>
        </div>
        <span class="badge-soft">
          <i class="bi bi-collection"></i>&nbsp;Total: <?php echo count($rows); ?>
        </span>
      </div>

      <!-- Filter just above table -->
      <form method="get" class="row g-2 align-items-end mb-3" accept-charset="UTF-8">
        <div class="col-sm-8">
          <label class="form-label mb-1">
            <i class="bi bi-search"></i>&nbsp;Search roles
          </label>
          <div class="search-input-wrapper">
            <span class="search-input-icon">
              <i class="bi bi-search"></i>
            </span>
            <input type="text"
                   name="q"
                   class="form-control"
                   placeholder="Type role name or description..."
                   value="<?php echo h($search); ?>">
          </div>
        </div>
        <div class="col-sm-4 text-sm-end">
          <div class="d-flex justify-content-sm-end gap-2 mt-3 mt-sm-0">
            <button class="btn btn-brand" type="submit">
              <i class="bi bi-search"></i>&nbsp;Filter
            </button>
            <a class="btn btn-muted" href="<?php echo h($self); ?>">
              <i class="bi bi-x-circle"></i>&nbsp;Reset
            </a>
          </div>
        </div>
      </form>

      <div class="table-responsive">
        <table class="table table-hover table-roles align-middle">
          <thead>
            <tr>
              <th class="col-id">ID</th>
              <th>Name</th>
              <th>Description</th>
              <?php if ($HAS_CREATED_AT): ?><th>Created</th><?php endif; ?>
              <?php if ($HAS_CREATED_BY): ?><th>By</th><?php endif; ?>
              <?php if ($HAS_ACTIVE): ?><th>Status</th><?php endif; ?>
              <th class="text-end col-actions">Actions</th>
            </tr>
          </thead>
          <tbody>
          <?php foreach($rows as $r): ?>
            <tr>
              <td class="col-id">
                <span class="id-pill">
                  #<?php echo (int)$r['RoleID']; ?>
                </span>
              </td>
              <td><?php echo h($r['RoleName']); ?></td>
              <td><?php echo isset($r['Description']) ? h($r['Description']) : ''; ?></td>

              <?php if ($HAS_CREATED_AT): ?>
                <td><?php echo h($r['CreatedAt']); ?></td>
              <?php endif; ?>
              <?php if ($HAS_CREATED_BY): ?>
                <td><?php echo isset($r['CreatedByUsername']) ? h($r['CreatedByUsername']) : ''; ?></td>
              <?php endif; ?>

              <?php if ($HAS_ACTIVE): ?>
              <td>
                <?php if ((int)(isset($r['IsActive']) ? $r['IsActive'] : 0) === 1): ?>
                  <span class="badge-soft status-badge-active">
                    <i class="bi bi-check-circle"></i>&nbsp;Active
                  </span>
                <?php else: ?>
                  <span class="badge-soft status-badge-inactive">
                    <i class="bi bi-dash-circle"></i>&nbsp;Inactive
                  </span>
                <?php endif; ?>
              </td>
              <?php endif; ?>

              <td class="text-end col-actions">
                <div class="actions-stack">

                  <!-- Edit -->
                  <a class="btn btn-muted btn-icon btn-sm"
                     href="<?php echo h($self); ?>?edit=<?php echo (int)$r['RoleID']; ?>"
                     title="Edit role">
                    <i class="bi bi-pencil"></i>
                  </a>

                  <?php if ($HAS_ACTIVE): ?>
                  <!-- Toggle -->
                  <form method="post"
                        onsubmit="return confirm('Toggle active status?');"
                        accept-charset="UTF-8">
                    <input type="hidden" name="csrf" value="<?php echo h($CSRF); ?>">
                    <input type="hidden" name="act" value="toggle">
                    <input type="hidden" name="RoleID" value="<?php echo (int)$r['RoleID']; ?>">
                    <input type="hidden" name="to" value="<?php echo ((int)(isset($r['IsActive']) ? $r['IsActive'] : 0) === 1 ? 0 : 1); ?>">

                    <button class="btn btn-muted btn-icon btn-sm" type="submit"
                            title="<?php echo ((int)(isset($r['IsActive']) ? $r['IsActive'] : 0) === 1 ? 'Deactivate' : 'Activate'); ?>">
                      <?php if ((int)(isset($r['IsActive']) ? $r['IsActive'] : 0) === 1): ?>
                        <i class="bi bi-pause"></i>
                      <?php else: ?>
                        <i class="bi bi-play-fill"></i>
                      <?php endif; ?>
                    </button>
                  </form>
                  <?php endif; ?>

                  <!-- Delete -->
                  <form method="post"
                        onsubmit="return confirm('Delete this role permanently?');"
                        accept-charset="UTF-8">
                    <input type="hidden" name="csrf" value="<?php echo h($CSRF); ?>">
                    <input type="hidden" name="act" value="delete">
                    <input type="hidden" name="RoleID" value="<?php echo (int)$r['RoleID']; ?>">
                    <button class="btn btn-danger-soft btn-icon btn-sm" type="submit" title="Delete role">
                      <i class="bi bi-trash"></i>
                    </button>
                  </form>

                </div>
              </td>
            </tr>
          <?php endforeach; ?>

          <?php if (empty($rows)): ?>
            <tr>
              <td colspan="<?php echo 4 + (int)$HAS_ACTIVE + (int)$HAS_CREATED_AT + (int)$HAS_CREATED_BY; ?>"
                  class="text-center text-muted py-4">
                No roles found.
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
