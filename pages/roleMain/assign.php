<?php
/**********************************************
 * RBAC Assignments (Designation page-এর স্টাইল/UX)
 * - Assign Role to User  (dbo.UserRoles)
 * - Assign Permission to Role (dbo.RolePermissions)
 **********************************************/
ini_set('display_errors', 1);
error_reporting(E_ALL);

/* 1) Boot */
require_once __DIR__ . '/../../init.php';
require_login(); // block unauthenticated

/* 2) Helpers */
if (!function_exists('h')) {
    function h($s) {
        return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8', false);
    }
}
function self_name() {
    return strtok(basename($_SERVER['SCRIPT_NAME']), "?");
}
$self = self_name();

/* column existence + data type helpers */
function table_has_col(PDO $conn, $table, $column) {
    static $cache = array();
    $key = strtolower($table . '|' . $column . '|has');
    if (array_key_exists($key, $cache)) return $cache[$key];

    $st = $conn->prepare("
        SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
         WHERE TABLE_SCHEMA='dbo' AND TABLE_NAME=:t AND COLUMN_NAME=:c
    ");
    $st->execute(array(':t' => $table, ':c' => $column));
    $has = (bool)$st->fetchColumn();
    $cache[$key] = $has;
    return $has;
}

function column_data_type(PDO $conn, $table, $column) {
    static $cache = array();
    $key = strtolower($table . '|' . $column . '|type');
    if (array_key_exists($key, $cache)) return $cache[$key];

    $st = $conn->prepare("
        SELECT DATA_TYPE
          FROM INFORMATION_SCHEMA.COLUMNS
         WHERE TABLE_SCHEMA='dbo' AND TABLE_NAME=:t AND COLUMN_NAME=:c
    ");
    $st->execute(array(':t' => $table, ':c' => $column));
    $type = $st->fetchColumn();
    $cache[$key] = $type ? strtolower($type) : null;
    return $cache[$key];
}

/* detect optional cols */
$UR_HAS_AT  = table_has_col($conn, 'UserRoles', 'AssignedAt');
$UR_HAS_BY  = table_has_col($conn, 'UserRoles', 'AssignedBy');
$RP_HAS_AT  = table_has_col($conn, 'RolePermissions', 'AssignedAt');
$RP_HAS_BY  = table_has_col($conn, 'RolePermissions', 'AssignedBy');

/* figure out AssignedBy types */
$UR_BY_TYPE = $UR_HAS_BY ? column_data_type($conn, 'UserRoles', 'AssignedBy') : null;
$RP_BY_TYPE = $RP_HAS_BY ? column_data_type($conn, 'RolePermissions', 'AssignedBy') : null;

/* helper: get :by value + PDO param type depending on column type */
function assigned_by_value_and_pdo_type($byType) {
    $auth = isset($_SESSION['auth_user']) ? $_SESSION['auth_user'] : array();
    $userId = (int)(isset($auth['UserID']) ? $auth['UserID'] : (isset($auth['id']) ? $auth['id'] : 0));
    $username = (string)(isset($auth['username']) ? $auth['username'] : (isset($auth['Username']) ? $auth['Username'] : ''));

    if ($byType === null) return array(null, null);

    if (strpos($byType, 'int') !== false) {
        // need INT
        if ($userId > 0) return array($userId, PDO::PARAM_INT);
        return array(null, PDO::PARAM_NULL);
    }

    // nvarchar / varchar => string
    if ($username === '' && $userId > 0) $username = (string)$userId;
    if ($username === '') $username = 'system';
    return array($username, PDO::PARAM_STR);
}

/* 3) CSRF */
if (!isset($_SESSION['csrf'])) {
    $_SESSION['csrf'] = function_exists('openssl_random_pseudo_bytes')
        ? bin2hex(openssl_random_pseudo_bytes(16))
        : substr(str_shuffle(md5(uniqid(mt_rand(), true))), 0, 32);
}
$CSRF = $_SESSION['csrf'];

function check_csrf() {
    if (!isset($_POST['csrf']) || $_POST['csrf'] !== $_SESSION['csrf']) {
        die('Invalid CSRF token');
    }
}

/* 4) Actions */
$msg = '';
$msg_type = 'success'; // 'success' | 'danger'

/* CREATE: assign role to user */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['act']) && $_POST['act'] === 'assign_user_role') {
    check_csrf();
    $userId = isset($_POST['user_id']) ? (int)$_POST['user_id'] : 0;
    $roleId = isset($_POST['role_id']) ? (int)$_POST['role_id'] : 0;

    if ($userId > 0 && $roleId > 0) {
        try {
            // dup check
            $chk = $conn->prepare("SELECT 1 FROM dbo.UserRoles WHERE UserID=:u AND RoleID=:r");
            $chk->execute(array(':u' => $userId, ':r' => $roleId));
            if ($chk->fetchColumn()) {
                $msg = "Assign failed: This user already has that role.";
                $msg_type = 'danger';
            } else {
                $cols = array('UserID', 'RoleID');
                $vals = array(':u', ':r');

                if ($UR_HAS_AT) { $cols[] = 'AssignedAt'; $vals[] = 'GETDATE()'; }
                if ($UR_HAS_BY) { $cols[] = 'AssignedBy'; $vals[] = ':by'; }

                $sql = "INSERT INTO dbo.UserRoles (" . implode(',', $cols) . ") VALUES (" . implode(',', $vals) . ")";
                $st  = $conn->prepare($sql);
                $st->bindValue(':u', $userId, PDO::PARAM_INT);
                $st->bindValue(':r', $roleId, PDO::PARAM_INT);

                if ($UR_HAS_BY) {
                    list($byVal, $pdoType) = assigned_by_value_and_pdo_type($UR_BY_TYPE);
                    if ($pdoType === PDO::PARAM_INT) {
                        if ($byVal === null) {
                            $st->bindValue(':by', null, PDO::PARAM_NULL);
                        } else {
                            $st->bindValue(':by', (int)$byVal, PDO::PARAM_INT);
                        }
                    } elseif ($pdoType === PDO::PARAM_STR) {
                        $st->bindValue(':by', (string)$byVal, PDO::PARAM_STR);
                    } else {
                        $st->bindValue(':by', null, PDO::PARAM_NULL);
                    }
                }

                $st->execute();
                $msg = "User role assigned.";
                $msg_type = 'success';
            }
        } catch (PDOException $e) {
            $msg = "Assign failed: " . h($e->getMessage());
            $msg_type = 'danger';
        }
    } else {
        $msg = "Select both user and role.";
        $msg_type = 'danger';
    }
}

/* CREATE: assign permission to role */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['act']) && $_POST['act'] === 'assign_role_permission') {
    check_csrf();
    $roleId = isset($_POST['role_id2']) ? (int)$_POST['role_id2'] : 0;
    $permId = isset($_POST['permission_id']) ? (int)$_POST['permission_id'] : 0;

    if ($roleId > 0 && $permId > 0) {
        try {
            $chk = $conn->prepare("SELECT 1 FROM dbo.RolePermissions WHERE RoleID=:r AND PermissionID=:p");
            $chk->execute(array(':r' => $roleId, ':p' => $permId));
            if ($chk->fetchColumn()) {
                $msg = "Assign failed: This role already has that permission.";
                $msg_type = 'danger';
            } else {
                $cols = array('RoleID', 'PermissionID');
                $vals = array(':r', ':p');
                if ($RP_HAS_AT) { $cols[] = 'AssignedAt'; $vals[] = 'GETDATE()'; }
                if ($RP_HAS_BY) { $cols[] = 'AssignedBy'; $vals[] = ':by'; }

                $sql = "INSERT INTO dbo.RolePermissions (" . implode(',', $cols) . ") VALUES (" . implode(',', $vals) . ")";
                $st  = $conn->prepare($sql);
                $st->bindValue(':r', $roleId, PDO::PARAM_INT);
                $st->bindValue(':p', $permId, PDO::PARAM_INT);

                if ($RP_HAS_BY) {
                    list($byVal, $pdoType) = assigned_by_value_and_pdo_type($RP_BY_TYPE);
                    if ($pdoType === PDO::PARAM_INT) {
                        if ($byVal === null) {
                            $st->bindValue(':by', null, PDO::PARAM_NULL);
                        } else {
                            $st->bindValue(':by', (int)$byVal, PDO::PARAM_INT);
                        }
                    } elseif ($pdoType === PDO::PARAM_STR) {
                        $st->bindValue(':by', (string)$byVal, PDO::PARAM_STR);
                    } else {
                        $st->bindValue(':by', null, PDO::PARAM_NULL);
                    }
                }

                $st->execute();
                $msg = "Permission assigned to role.";
                $msg_type = 'success';
            }
        } catch (PDOException $e) {
            $msg = "Assign failed: " . h($e->getMessage());
            $msg_type = 'danger';
        }
    } else {
        $msg = "Select both role and permission.";
        $msg_type = 'danger';
    }
}

/* DELETE: unassign user↔role */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['act']) && $_POST['act'] === 'unassign_user_role') {
    check_csrf();
    $userId = isset($_POST['user_id']) ? (int)$_POST['user_id'] : 0;
    $roleId = isset($_POST['role_id']) ? (int)$_POST['role_id'] : 0;

    if ($userId > 0 && $roleId > 0) {
        try {
            $st = $conn->prepare("DELETE FROM dbo.UserRoles WHERE UserID=:u AND RoleID=:r");
            $st->execute(array(':u' => $userId, ':r' => $roleId));
            $msg = "User role removed.";
            $msg_type = 'success';
        } catch (PDOException $e) {
            $msg = "Remove failed: " . h($e->getMessage());
            $msg_type = 'danger';
        }
    }
}

/* DELETE: unassign role↔permission */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['act']) && $_POST['act'] === 'unassign_role_permission') {
    check_csrf();
    $roleId = isset($_POST['role_id']) ? (int)$_POST['role_id'] : 0;
    $permId = isset($_POST['permission_id']) ? (int)$_POST['permission_id'] : 0;

    if ($roleId > 0 && $permId > 0) {
        try {
            $st = $conn->prepare("DELETE FROM dbo.RolePermissions WHERE RoleID=:r AND PermissionID=:p");
            $st->execute(array(':r' => $roleId, ':p' => $permId));
            $msg = "Permission removed from role.";
            $msg_type = 'success';
        } catch (PDOException $e) {
            $msg = "Remove failed: " . h($e->getMessage());
            $msg_type = 'danger';
        }
    }
}

/* 5) Fetch lists (for selects) */
try {
    $users = $conn->query("SELECT UserID, Username FROM dbo.Users ORDER BY Username")->fetchAll();
} catch (PDOException $e) { $users = array(); }

try {
    $roles = $conn->query("SELECT RoleID, RoleName FROM dbo.Roles ORDER BY RoleName")->fetchAll();
} catch (PDOException $e) { $roles = array(); }

try {
    $perms = $conn->query("SELECT PermissionID, [Code] FROM dbo.Permissions ORDER BY [Code]")->fetchAll();
} catch (PDOException $e) { $perms = array(); }

/* 6) Current assignments for tables */
try {
    $ur_select = "ur.UserID, ur.RoleID, u.Username, r.RoleName";
    if ($UR_HAS_AT) $ur_select .= ", ur.AssignedAt";

    if ($UR_HAS_BY) {
        if ($UR_BY_TYPE && strpos($UR_BY_TYPE, 'int') !== false) {
            $ur_select .= ", ur.AssignedBy, u2.Username AS AssignedByUsername";
            $user_roles = $conn->query("
                SELECT $ur_select
                  FROM dbo.UserRoles ur
                  JOIN dbo.Users u  ON u.UserID  = ur.UserID
                  JOIN dbo.Roles r  ON r.RoleID  = ur.RoleID
                  LEFT JOIN dbo.Users u2 ON u2.UserID = ur.AssignedBy
                 ORDER BY u.Username, r.RoleName
            ")->fetchAll();
        } else {
            $ur_select .= ", ur.AssignedBy";
            $user_roles = $conn->query("
                SELECT $ur_select
                  FROM dbo.UserRoles ur
                  JOIN dbo.Users u  ON u.UserID  = ur.UserID
                  JOIN dbo.Roles r  ON r.RoleID  = ur.RoleID
                 ORDER BY u.Username, r.RoleName
            ")->fetchAll();
        }
    } else {
        $user_roles = $conn->query("
            SELECT $ur_select
              FROM dbo.UserRoles ur
              JOIN dbo.Users u ON u.UserID = ur.UserID
              JOIN dbo.Roles r ON r.RoleID = ur.RoleID
             ORDER BY u.Username, r.RoleName
        ")->fetchAll();
    }
} catch (PDOException $e) { $user_roles = array(); }

try {
    $rp_select = "rp.RoleID, rp.PermissionID, r.RoleName, p.[Code] AS PermCode";
    if ($RP_HAS_AT) $rp_select .= ", rp.AssignedAt";

    if ($RP_HAS_BY) {
        if ($RP_BY_TYPE && strpos($RP_BY_TYPE, 'int') !== false) {
            $rp_select .= ", rp.AssignedBy, u3.Username AS AssignedByUsername";
            $role_perms = $conn->query("
                SELECT $rp_select
                  FROM dbo.RolePermissions rp
                  JOIN dbo.Roles r ON r.RoleID = rp.RoleID
                  JOIN dbo.Permissions p ON p.PermissionID = rp.PermissionID
                  LEFT JOIN dbo.Users u3 ON u3.UserID = rp.AssignedBy
                 ORDER BY r.RoleName, p.[Code]
            ")->fetchAll();
        } else {
            $rp_select .= ", rp.AssignedBy";
            $role_perms = $conn->query("
                SELECT $rp_select
                  FROM dbo.RolePermissions rp
                  JOIN dbo.Roles r ON r.RoleID = rp.RoleID
                  JOIN dbo.Permissions p ON p.PermissionID = rp.PermissionID
                 ORDER BY r.RoleName, p.[Code]
            ")->fetchAll();
        }
    } else {
        $role_perms = $conn->query("
            SELECT $rp_select
              FROM dbo.RolePermissions rp
              JOIN dbo.Roles r ON r.RoleID = rp.RoleID
              JOIN dbo.Permissions p ON p.PermissionID = rp.PermissionID
             ORDER BY r.RoleName, p.[Code]
        ")->fetchAll();
    }
} catch (PDOException $e) { $role_perms = array(); }

/* 7) Render */
require_once __DIR__ . '/../../include/header.php';
?>

<!-- Bootstrap Icons CDN (for icons in this page) -->
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
  .btn-violet{
    background:#7c3aed;
    color:#fff !important;
    border:none;
    border-radius:999px;
    font-size:13px;
    padding:6px 16px;
  }
  .btn-violet:hover{
    background:#6d28d9;
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

  <!-- Header (no filter here now) -->
  <div class="mb-3">
    <h1 class="page-title mb-1">
      <span class="page-title-badge">
        <i class="bi bi-shield-lock"></i>
      </span>
      <span>RBAC Assignments</span>
    </h1>
    <div class="page-subtitle">
      Assign roles & permissions with a clean overview of current mappings.
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

  <!-- Card: Assign Role to User -->
  <div class="card card-elevated mb-4">
    <div class="card-body">
      <div class="section-header">
        <div>
          <div class="section-title">
            <span class="section-title-icon">
              <i class="bi bi-person-badge"></i>
            </span>
            <span>Assign Role to User</span>
          </div>
          <div class="section-sub">
            নির্দিষ্ট User-কে একটি Role assign করুন।
          </div>
        </div>
      </div>

      <form method="post" class="row g-3" accept-charset="UTF-8">
        <input type="hidden" name="csrf" value="<?php echo h($CSRF); ?>">
        <input type="hidden" name="act" value="assign_user_role">

        <div class="col-12 col-md-6">
          <label class="form-label">
            <i class="bi bi-person me-1"></i> User
          </label>
          <select name="user_id" class="form-select" required>
            <option value="">-- select user --</option>
            <?php foreach ($users as $u): ?>
              <option value="<?php echo (int)$u['UserID']; ?>">
                <?php echo h($u['Username']); ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="col-12 col-md-6">
          <label class="form-label">
            <i class="bi bi-shield-check me-1"></i> Role
          </label>
          <select name="role_id" class="form-select" required>
            <option value="">-- select role --</option>
            <?php foreach ($roles as $r): ?>
              <option value="<?php echo (int)$r['RoleID']; ?>">
                <?php echo h($r['RoleName']); ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="col-12">
          <button class="btn btn-brand" type="submit">
            <i class="bi bi-plus-circle me-1"></i> Assign Role
          </button>
        </div>
      </form>
    </div>
  </div>

  <!-- Card: Assign Permission to Role -->
  <div class="card card-elevated mb-4">
    <div class="card-body">
      <div class="section-header">
        <div>
          <div class="section-title">
            <span class="section-title-icon" style="background:rgba(16,185,129,.12);color:#059669;">
              <i class="bi bi-key-fill"></i>
            </span>
            <span>Assign Permission to Role</span>
          </div>
          <div class="section-sub">
            Role ভিত্তিক permission mapping manage করুন।
          </div>
        </div>
      </div>

      <form method="post" class="row g-3" accept-charset="UTF-8">
        <input type="hidden" name="csrf" value="<?php echo h($CSRF); ?>">
        <input type="hidden" name="act" value="assign_role_permission">

        <div class="col-12 col-md-6">
          <label class="form-label">
            <i class="bi bi-shield-check me-1"></i> Role
          </label>
          <select name="role_id2" class="form-select" required>
            <option value="">-- select role --</option>
            <?php foreach ($roles as $r): ?>
              <option value="<?php echo (int)$r['RoleID']; ?>">
                <?php echo h($r['RoleName']); ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="col-12 col-md-6">
          <label class="form-label">
            <i class="bi bi-lock me-1"></i> Permission
          </label>
          <select name="permission_id" class="form-select" required>
            <option value="">-- select permission --</option>
            <?php foreach ($perms as $p): ?>
              <option value="<?php echo (int)$p['PermissionID']; ?>">
                <?php echo h($p['Code']); ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="col-12">
          <button class="btn btn-violet" type="submit">
            <i class="bi bi-plus-circle me-1"></i> Assign Permission
          </button>
        </div>
      </form>
    </div>
  </div>

  <!-- List: User ↔ Role -->
  <div class="card card-elevated mb-4">
    <div class="card-body">
      <div class="section-header">
        <div class="section-title">
          <span class="section-title-icon" style="background:rgba(59,130,246,.10);color:#1d4ed8;">
            <i class="bi bi-people"></i>
          </span>
          <span>User ↔ Role</span>
        </div>
        <span class="badge-soft">
          <i class="bi bi-list-ul me-1"></i>
          Total: <?php echo count($user_roles); ?>
        </span>
      </div>

      <!-- 🔎 Filter just above User↔Role table -->
      <form method="get" class="row g-2 align-items-end mb-3" accept-charset="UTF-8">
        <div class="col-sm-8">
          <label class="form-label mb-1">
            <i class="bi bi-search me-1"></i> Search in user-role
          </label>
          <div class="search-input-wrapper">
            <span class="search-input-icon">
              <i class="bi bi-search"></i>
            </span>
            <input type="text"
                   name="q"
                   class="form-control"
                   placeholder="Type user / role / assigned by..."
                   value="<?php echo h(isset($_GET['q']) ? $_GET['q'] : ''); ?>">
          </div>
        </div>
        <div class="col-sm-4 text-sm-end">
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
              <th>User</th>
              <th>Role</th>
              <?php if ($UR_HAS_AT): ?><th>Assigned</th><?php endif; ?>
              <?php if ($UR_HAS_BY): ?><th>By</th><?php endif; ?>
              <th class="text-end">Actions</th>
            </tr>
          </thead>
          <tbody>
          <?php
          $q = isset($_GET['q']) ? trim($_GET['q']) : '';
          foreach ($user_roles as $row):
              $show = true;
              if ($q !== '') {
                  $hay = strtolower(
                      (isset($row['Username']) ? $row['Username'] : '') . ' ' .
                      (isset($row['RoleName']) ? $row['RoleName'] : '') . ' ' .
                      (isset($row['AssignedByUsername']) ? $row['AssignedByUsername'] : (isset($row['AssignedBy']) ? $row['AssignedBy'] : ''))
                  );
                  $show = (strpos($hay, strtolower($q)) !== false);
              }
              if (!$show) continue;
          ?>
            <tr>
              <td><?php echo h($row['Username']); ?></td>
              <td><?php echo h($row['RoleName']); ?></td>
              <?php if ($UR_HAS_AT): ?>
                <td><?php echo h($row['AssignedAt']); ?></td>
              <?php endif; ?>
              <?php if ($UR_HAS_BY): ?>
                <td>
                  <?php
                  if (isset($row['AssignedByUsername']) && $row['AssignedByUsername'] !== null && $row['AssignedByUsername'] !== '') {
                      echo h($row['AssignedByUsername']);
                  } else {
                      echo h(isset($row['AssignedBy']) ? $row['AssignedBy'] : '');
                  }
                  ?>
                </td>
              <?php endif; ?>
              <td class="text-end">
                <form method="post"
                      class="d-inline"
                      onsubmit="return confirm('Remove this user-role assignment?');"
                      accept-charset="UTF-8">
                  <input type="hidden" name="csrf" value="<?php echo h($CSRF); ?>">
                  <input type="hidden" name="act" value="unassign_user_role">
                  <input type="hidden" name="user_id" value="<?php echo (int)$row['UserID']; ?>">
                  <input type="hidden" name="role_id" value="<?php echo (int)$row['RoleID']; ?>">
                  <button class="btn btn-danger-soft btn-sm" type="submit">
                    <i class="bi bi-trash me-1"></i> Remove
                  </button>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>

          <?php if (empty($user_roles)): ?>
            <tr>
              <td colspan="<?php echo 3 + (int)$UR_HAS_AT + (int)$UR_HAS_BY; ?>"
                  class="text-center text-muted py-4">
                No user-role assignments found.
              </td>
            </tr>
          <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>

  <!-- List: Role ↔ Permission -->
  <div class="card card-elevated">
    <div class="card-body">
      <div class="section-header">
        <div class="section-title">
          <span class="section-title-icon" style="background:rgba(124,58,237,.10);color:#7c3aed;">
            <i class="bi bi-link-45deg"></i>
          </span>
          <span>Role ↔ Permission</span>
        </div>
        <span class="badge-soft">
          <i class="bi bi-list-ul me-1"></i>
          Total: <?php echo count($role_perms); ?>
        </span>
      </div>

      <!-- 🔎 Filter just above Role↔Permission table -->
      <form method="get" class="row g-2 align-items-end mb-3" accept-charset="UTF-8">
        <div class="col-sm-8">
          <label class="form-label mb-1">
            <i class="bi bi-search me-1"></i> Search in role-permission
          </label>
          <div class="search-input-wrapper">
            <span class="search-input-icon">
              <i class="bi bi-search"></i>
            </span>
            <input type="text"
                   name="q"
                   class="form-control"
                   placeholder="Type role / permission / assigned by..."
                   value="<?php echo h(isset($_GET['q']) ? $_GET['q'] : ''); ?>">
          </div>
        </div>
        <div class="col-sm-4 text-sm-end">
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
              <th>Role</th>
              <th>Permission</th>
              <?php if ($RP_HAS_AT): ?><th>Assigned</th><?php endif; ?>
              <?php if ($RP_HAS_BY): ?><th>By</th><?php endif; ?>
              <th class="text-end">Actions</th>
            </tr>
          </thead>
          <tbody>
          <?php
          $q2 = isset($_GET['q']) ? trim($_GET['q']) : '';
          foreach ($role_perms as $row):
              $show = true;
              if ($q2 !== '') {
                  $hay = strtolower(
                      (isset($row['RoleName']) ? $row['RoleName'] : '') . ' ' .
                      (isset($row['PermCode']) ? $row['PermCode'] : '') . ' ' .
                      (isset($row['AssignedByUsername']) ? $row['AssignedByUsername'] : (isset($row['AssignedBy']) ? $row['AssignedBy'] : ''))
                  );
                  $show = (strpos($hay, strtolower($q2)) !== false);
              }
              if (!$show) continue;
          ?>
            <tr>
              <td><?php echo h($row['RoleName']); ?></td>
              <td><?php echo h($row['PermCode']); ?></td>
              <?php if ($RP_HAS_AT): ?>
                <td><?php echo h($row['AssignedAt']); ?></td>
              <?php endif; ?>
              <?php if ($RP_HAS_BY): ?>
                <td>
                  <?php
                  if (isset($row['AssignedByUsername']) && $row['AssignedByUsername'] !== null && $row['AssignedByUsername'] !== '') {
                      echo h($row['AssignedByUsername']);
                  } else {
                      echo h(isset($row['AssignedBy']) ? $row['AssignedBy'] : '');
                  }
                  ?>
                </td>
              <?php endif; ?>
              <td class="text-end">
                <form method="post"
                      class="d-inline"
                      onsubmit="return confirm('Remove this role-permission assignment?');"
                      accept-charset="UTF-8">
                  <input type="hidden" name="csrf" value="<?php echo h($CSRF); ?>">
                  <input type="hidden" name="act" value="unassign_role_permission">
                  <input type="hidden" name="role_id" value="<?php echo (int)$row['RoleID']; ?>">
                  <input type="hidden" name="permission_id" value="<?php echo (int)$row['PermissionID']; ?>">
                  <button class="btn btn-danger-soft btn-sm" type="submit">
                    <i class="bi bi-trash me-1"></i> Remove
                  </button>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>

          <?php if (empty($role_perms)): ?>
            <tr>
              <td colspan="<?php echo 3 + (int)$RP_HAS_AT + (int)$RP_HAS_BY; ?>"
                  class="text-center text-muted py-4">
                No role-permission assignments found.
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
