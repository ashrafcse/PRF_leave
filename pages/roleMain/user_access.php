<?php
/**********************************************
 * User Access Viewer (Roles & Permissions)
 * - Same design language as Roles/Designation/Assignments
 * - PHP 5.6 compatible
 **********************************************/
ini_set('display_errors', 1);
error_reporting(E_ALL);

/* 1) Boot */
require_once __DIR__ . '/../../init.php';
require_login(); // block unauthenticated

/* 2) Helpers */
if (!function_exists('h')) {
  function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8', false); }
}
function self_name(){ return strtok(basename($_SERVER['SCRIPT_NAME']), "?"); }
$self = self_name();

/* column helpers */
function table_has_col(PDO $conn, $table, $column){
  static $cache = [];
  $key = strtolower($table.'|'.$column.'|has');
  if (array_key_exists($key, $cache)) return $cache[$key];
  $st = $conn->prepare("
    SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
     WHERE TABLE_SCHEMA='dbo' AND TABLE_NAME=:t AND COLUMN_NAME=:c
  ");
  $st->execute([':t'=>$table, ':c'=>$column]);
  $has = (bool)$st->fetchColumn();
  $cache[$key] = $has;
  return $has;
}
function column_data_type(PDO $conn, $table, $column){
  static $cache = [];
  $key = strtolower($table.'|'.$column.'|type');
  if (array_key_exists($key, $cache)) return $cache[$key];
  $st = $conn->prepare("
    SELECT DATA_TYPE
      FROM INFORMATION_SCHEMA.COLUMNS
     WHERE TABLE_SCHEMA='dbo' AND TABLE_NAME=:t AND COLUMN_NAME=:c
  ");
  $st->execute([':t'=>$table, ':c'=>$column]);
  $type = $st->fetchColumn();
  $cache[$key] = $type ? strtolower($type) : null;
  return $cache[$key];
}

/* detect optional cols for pretty display */
$UR_HAS_AT  = table_has_col($conn, 'UserRoles', 'AssignedAt');
$UR_HAS_BY  = table_has_col($conn, 'UserRoles', 'AssignedBy');
$UR_BY_TYPE = $UR_HAS_BY ? column_data_type($conn, 'UserRoles', 'AssignedBy') : null;

/* 3) Page logic */
$msg = '';

/* users for the picker */
try {
  $users = $conn->query("SELECT UserID, Username FROM dbo.Users ORDER BY Username")->fetchAll();
} catch (PDOException $e) { $users = []; }

/* selected user id */
$uid = 0;
if (isset($_GET['uid']))      $uid = (int)$_GET['uid'];
elseif (isset($_POST['uid'])) $uid = (int)$_POST['uid'];

/* selected user basic info */
$userRow = null;
if ($uid > 0) {
  try {
    $st = $conn->prepare("
      SELECT TOP 1 UserID, Username, Email, IsActive, LastLoginAt
        FROM dbo.Users
       WHERE UserID = :id
    ");
    $st->execute([':id'=>$uid]);
    $userRow = $st->fetch();
  } catch (PDOException $e) { $msg = "Load user failed: ".h($e->getMessage()); }
}

/* Assigned Roles for user */
$roles = [];
if ($userRow) {
  try {
    if ($UR_HAS_BY && $UR_BY_TYPE && strpos($UR_BY_TYPE, 'int') !== false) {
      // AssignedBy = int → join to Users to show username
      $st = $conn->prepare("
        SELECT r.RoleID, r.RoleName, r.[Description],
               ur.AssignedAt, ur.AssignedBy, u2.Username AS AssignedByUsername
          FROM dbo.UserRoles ur
          JOIN dbo.Roles r ON r.RoleID = ur.RoleID
          LEFT JOIN dbo.Users u2 ON u2.UserID = ur.AssignedBy
         WHERE ur.UserID = :uid
         ORDER BY r.RoleName
      ");
    } else {
      // string or no join
      $st = $conn->prepare("
        SELECT r.RoleID, r.RoleName, r.[Description],
               ur.AssignedAt, ur.AssignedBy
          FROM dbo.UserRoles ur
          JOIN dbo.Roles r ON r.RoleID = ur.RoleID
         WHERE ur.UserID = :uid
         ORDER BY r.RoleName
      ");
    }
    $st->execute([':uid'=>$uid]);
    $roles = $st->fetchAll();
  } catch (PDOException $e) { $msg = "Load roles failed: ".h($e->getMessage()); }
}

/* Effective Permissions (distinct) */
$perms = [];
if ($userRow) {
  try {
    $st = $conn->prepare("
      SELECT DISTINCT p.PermissionID, p.[Code], p.[Description]
        FROM dbo.UserRoles ur
        JOIN dbo.RolePermissions rp ON rp.RoleID = ur.RoleID
        JOIN dbo.Permissions p ON p.PermissionID = rp.PermissionID
       WHERE ur.UserID = :uid
       ORDER BY p.[Code]
    ");
    $st->execute([':uid'=>$uid]);
    $perms = $st->fetchAll();
  } catch (PDOException $e) { $msg = "Load permissions failed: ".h($e->getMessage()); }
}

/* Role → Permission mapping (for display) */
$rolePermMap = [];
if ($userRow) {
  try {
    $st = $conn->prepare("
      SELECT r.RoleName, p.[Code]
        FROM dbo.UserRoles ur
        JOIN dbo.Roles r ON r.RoleID = ur.RoleID
        JOIN dbo.RolePermissions rp ON rp.RoleID = r.RoleID
        JOIN dbo.Permissions p ON p.PermissionID = rp.PermissionID
       WHERE ur.UserID = :uid
       ORDER BY r.RoleName, p.[Code]
    ");
    $st->execute([':uid'=>$uid]);
    while ($row = $st->fetch()) {
      $rn = (string)$row['RoleName'];
      if (!isset($rolePermMap[$rn])) $rolePermMap[$rn] = [];
      $rolePermMap[$rn][] = (string)$row['Code'];
    }
  } catch (PDOException $e) { /* ignore mapping errors */ }
}

/* 4) Render */
require_once __DIR__ . '/../../include/header.php';
?>

<!-- Bootstrap Icons (used across pages) -->
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">

<style>
  .page-wrap{ margin:28px auto; padding:0 12px 40px; }
  .page-title{ font-weight:700; letter-spacing:.2px; font-size:24px; color:#0f172a; display:flex; align-items:center; gap:10px; }
  .title-badge{ width:38px; height:38px; border-radius:999px; display:inline-flex; align-items:center; justify-content:center;
    background:linear-gradient(135deg,#4f46e5,#0ea5e9); color:#fff; box-shadow:0 8px 18px rgba(79,70,229,.35); font-size:18px; }
  .page-sub{ font-size:13px; color:#64748b; }

  .hero{ border-radius:18px; border:1px solid #e5e7eb; box-shadow:0 14px 40px rgba(15,23,42,.08);
    background:linear-gradient(90deg, #eef5fb 0%, #eaf7f5 100%); padding:14px 16px; }

  .card-elevated{ border-radius:18px; border:1px solid #e5e7eb; box-shadow:0 14px 40px rgba(15,23,42,.10); background:#fff; }
  .badge-soft{ border:1px solid #e2e8f0; background:#f8fafc; border-radius:999px; padding:3px 8px; font-size:11px; color:#475569; }

  .table thead th{ background:#f8fafc; color:#334155; border-bottom:1px solid #e5e7eb; font-size:12px; text-transform:uppercase; letter-spacing:.06em; }
  .table tbody td{ vertical-align:middle; font-size:13px; }
  .table-hover tbody tr:hover{ background-color:#f1f5f9; }

  .btn-brand{ background:#2563eb; color:#fff !important; border:none; border-radius:999px; padding:8px 16px; }
  .btn-brand:hover{ background:#1d4ed8; }
  .btn-muted{ background:#eef2f7; color:#0f172a !important; border:1px solid #e5e7eb; border-radius:999px; padding:8px 14px; }
  .btn-muted:hover{ background:#e6edf6; }

  /* compact inline toolbar */
  .toolbar-inline{ display:flex; align-items:center; gap:10px; flex-wrap:wrap; }
  .toolbar-label{ font-size:12px; font-weight:600; color:#374151; display:flex; align-items:center; gap:6px;
    background:#eef2ff; border:1px solid #e5e7eb; padding:6px 10px; border-radius:999px; }
  .picker{ position:relative; min-width:280px; max-width:420px; }
  .picker .bi-search{ position:absolute; left:10px; top:50%; transform:translateY(-50%); color:#9ca3af; font-size:14px; pointer-events:none; }
  .picker select{ height:40px; padding-left:32px; }
  .toolbar-inline .btn, .toolbar-inline .form-select{ height:40px; }

  .pill{ display:inline-block; padding:3px 8px; border-radius:999px; background:#f1f5f9; border:1px solid #e2e8f0; margin:2px 6px 2px 0; }

  @media (max-width:576px){
    .picker{ min-width:100%; max-width:100%; }
    .page-title{ font-size:20px; }
  }
</style>

<div class="page-wrap">

  <!-- HERO: Title + compact selector -->
  <div class="hero mb-3">
    <div class="d-flex flex-column flex-xl-row align-items-xl-center justify-content-between gap-3">
      <!-- Title -->
      <div class="pe-xl-3" style="min-width:260px;">
        <h1 class="page-title mb-1">
          <span class="title-badge"><i class="bi bi-person-lock"></i></span>
          <span>User Access</span>
        </h1>
        <div class="page-sub">নির্দিষ্ট user-এর role ও effective permission এক জায়গায় দেখুন।</div>
      </div>

      <!-- Inline toolbar -->
      <form method="get" class="toolbar-inline w-100 w-xl-auto" accept-charset="UTF-8">
        <span class="toolbar-label"><i class="bi bi-people"></i> Select user</span>

        <div class="picker flex-grow-1">
          <i class="bi bi-search"></i>
          <select name="uid" class="form-select" required>
            <option value="">-- select user --</option>
            <?php foreach($users as $u): ?>
              <option value="<?php echo (int)$u['UserID']; ?>" <?php echo ($uid === (int)$u['UserID'] ? 'selected' : ''); ?>>
                <?php echo h($u['Username']); ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>

        <button class="btn btn-brand" type="submit"><i class="bi bi-eye me-1"></i>View</button>
        <a class="btn btn-muted" href="<?php echo h($self); ?>"><i class="bi bi-x-circle me-1"></i>Reset</a>
      </form>
    </div>
  </div>

  <!-- Alerts -->
  <?php if ($msg): ?>
    <div class="alert alert-warning alert-dismissible fade show" role="alert">
      <?php echo $msg; ?>
      <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close" style="box-shadow:none; outline:none;"></button>
    </div>
  <?php endif; ?>

  <?php if ($uid > 0 && !$userRow): ?>
    <div class="card card-elevated mb-4"><div class="card-body"><strong>User not found.</strong></div></div>
  <?php endif; ?>

  <?php if ($userRow): ?>
    <!-- User summary -->
    <div class="card card-elevated mb-4">
      <div class="card-body">
        <h5 class="mb-2">User</h5>
        <div class="text-muted small mb-2">
          <span class="badge-soft">ID #<?php echo (int)$userRow['UserID']; ?></span>
        </div>
        <div class="row g-3">
          <div class="col-12 col-md-4">
            <div><strong>Username:</strong> <?php echo h($userRow['Username']); ?></div>
          </div>
          <div class="col-12 col-md-4">
            <div><strong>Email:</strong> <?php echo h(isset($userRow['Email']) ? $userRow['Email'] : ''); ?></div>
          </div>
          <div class="col-12 col-md-4">
            <div>
              <strong>Status:</strong>
              <?php if ((int)$userRow['IsActive'] === 1): ?>
                <span class="badge-soft text-success">Active</span>
              <?php else: ?>
                <span class="badge-soft text-secondary">Inactive</span>
              <?php endif; ?>
              &nbsp;|&nbsp;
              <strong>Last login:</strong> <?php echo h(isset($userRow['LastLoginAt']) ? $userRow['LastLoginAt'] : '—'); ?>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- Roles & Effective Permissions -->
    <div class="row g-3">
      <div class="col-12 col-lg-6">
        <div class="card card-elevated h-100">
          <div class="card-body">
            <div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-2">
              <h5 class="mb-0">Assigned Roles</h5>
              <span class="text-muted small">Total: <?php echo count($roles); ?></span>
            </div>

            <?php if (empty($roles)): ?>
              <div class="text-muted">No roles assigned.</div>
            <?php else: ?>
              <div class="table-responsive">
                <table class="table table-hover align-middle">
                  <thead>
                    <tr>
                      <th>#</th>
                      <th>Role</th>
                      <th>Description</th>
                      <?php if ($UR_HAS_AT): ?><th>Assigned At</th><?php endif; ?>
                      <?php if ($UR_HAS_BY): ?><th>Assigned By</th><?php endif; ?>
                    </tr>
                  </thead>
                  <tbody>
                    <?php foreach($roles as $i => $r): ?>
                      <tr>
                        <td><?php echo $i+1; ?></td>
                        <td><?php echo h($r['RoleName']); ?></td>
                        <td><?php echo h(isset($r['Description']) ? $r['Description'] : ''); ?></td>
                        <?php if ($UR_HAS_AT): ?>
                          <td><?php echo h(isset($r['AssignedAt']) ? $r['AssignedAt'] : ''); ?></td>
                        <?php endif; ?>
                        <?php if ($UR_HAS_BY): ?>
                          <td>
                            <?php
                              if (isset($r['AssignedByUsername']) && $r['AssignedByUsername']!=='') {
                                echo h($r['AssignedByUsername']);
                              } else {
                                echo h(isset($r['AssignedBy']) ? $r['AssignedBy'] : '');
                              }
                            ?>
                          </td>
                        <?php endif; ?>
                      </tr>
                    <?php endforeach; ?>
                  </tbody>
                </table>
              </div>
            <?php endif; ?>
          </div>
        </div>
      </div>

      <div class="col-12 col-lg-6">
        <div class="card card-elevated h-100">
          <div class="card-body">
            <div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-2">
              <h5 class="mb-0">Effective Permissions</h5>
              <span class="text-muted small">Total: <?php echo count($perms); ?></span>
            </div>

            <?php if (empty($perms)): ?>
              <div class="text-muted">No permissions (via roles) found.</div>
            <?php else: ?>
              <div class="table-responsive">
                <table class="table table-hover align-middle">
                  <thead>
                    <tr>
                      <th>#</th>
                      <th>Code</th>
                      <th>Description</th>
                    </tr>
                  </thead>
                  <tbody>
                    <?php foreach($perms as $i => $p): ?>
                      <tr>
                        <td><?php echo $i+1; ?></td>
                        <td><span class="pill"><?php echo h(isset($p['Code']) ? $p['Code'] : ''); ?></span></td>
                        <td><?php echo h(isset($p['Description']) ? $p['Description'] : ''); ?></td>
                      </tr>
                    <?php endforeach; ?>
                  </tbody>
                </table>
              </div>
            <?php endif; ?>
          </div>
        </div>
      </div>
    </div>

    <!-- Mapping -->
    <div class="card card-elevated mt-3">
      <div class="card-body">
        <h5 class="mb-2">Role → Permission Mapping</h5>
        <?php if (empty($rolePermMap)): ?>
          <div class="text-muted">No mapping to display.</div>
        <?php else: ?>
          <?php foreach($rolePermMap as $roleName => $permCodes): ?>
            <p class="mb-1">
              <strong><?php echo h($roleName); ?>:</strong>
              <?php foreach($permCodes as $c): ?>
                <span class="pill"><?php echo h($c); ?></span>
              <?php endforeach; ?>
            </p>
          <?php endforeach; ?>
        <?php endif; ?>
      </div>
    </div>
  <?php endif; ?>

</div><!-- /.page-wrap -->

<?php require_once __DIR__ . '/../../include/footer.php'; ?>
