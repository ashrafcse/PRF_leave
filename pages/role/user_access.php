<?php
// ----------------- Bootstrap (no external auth.php) -----------------
if (session_status() === PHP_SESSION_NONE) {
    $secure   = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    $httponly = true;
    session_set_cookie_params(0, '/', '', $secure, $httponly);
    session_start();
}

// DB connect (PDO sqlsrv)
$serverName  = "AMRITO_BOSU\\SQLEXPRESS";
$database    = "assetMng";
$db_username = ""; // SQL Auth হলে দিন; Windows Auth থাকলে কনফিগ অনুসারে ফাঁকা থাকতে পারে
$password    = "";

try {
    $conn = new PDO("sqlsrv:Server=$serverName;Database=$database", $db_username, $password);
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $conn->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    die('DB connect failed: ' . $e->getMessage());
}

// Helpers
function h($s){ return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }
function current_user(){ return isset($_SESSION['auth_user']) ? $_SESSION['auth_user'] : null; }
function require_login(){
    if (!current_user()){
        $target = isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : 'user_access.php';
        header('Location: login.php?next=' . rawurlencode($target));
        exit;
    }
}

// Optional: uncomment if এই পেজেও লগইন বাধ্যতামূলক করতে চান
// require_login();

// ----------------- Page Logic -----------------
$msg = '';
// ইউজার ড্রপডাউনের জন্য সব ইউজার
$users = $conn->query("SELECT UserID, Username FROM dbo.Users ORDER BY Username")->fetchAll();

// কোন ইউজার সিলেক্ট হয়েছে?
$uid = 0;
if (isset($_GET['uid'])) {
    $uid = (int)$_GET['uid'];
} elseif (isset($_POST['uid'])) {
    $uid = (int)$_POST['uid'];
}

// সিলেক্টেড ইউজারের বেসিক ইনফো
$userRow = null;
if ($uid > 0) {
    $st = $conn->prepare("SELECT TOP 1 UserID, Username, Email, IsActive, LastLoginAt FROM dbo.Users WHERE UserID=:id");
    $st->execute(array(':id'=>$uid));
    $userRow = $st->fetch();
}

// ইউজারের Roles
$roles = array();
if ($userRow) {
    $st = $conn->prepare("
        SELECT r.RoleID, r.RoleName, r.[Description], ur.AssignedAt, ur.AssignedBy
        FROM dbo.UserRoles ur
        JOIN dbo.Roles r ON r.RoleID = ur.RoleID
        WHERE ur.UserID = :uid
        ORDER BY r.RoleName
    ");
    $st->execute(array(':uid'=>$uid));
    $roles = $st->fetchAll();
}

// ইউজারের effective Permissions (distinct)
$perms = array();
if ($userRow) {
    $st = $conn->prepare("
        SELECT DISTINCT p.PermissionID, p.[Code], p.[Description]
        FROM dbo.UserRoles ur
        JOIN dbo.RolePermissions rp ON rp.RoleID = ur.RoleID
        JOIN dbo.Permissions p ON p.PermissionID = rp.PermissionID
        WHERE ur.UserID = :uid
        ORDER BY p.[Code]
    ");
    $st->execute(array(':uid'=>$uid));
    $perms = $st->fetchAll();
}

// Bonus: Role → Permissions ম্যাপ (বুঝতে সুবিধা হয় কোন role কোন perm দিচ্ছে)
$rolePermMap = array(); // RoleName => [perm codes...]
if ($userRow && !empty($roles)) {
    $st = $conn->prepare("
        SELECT r.RoleName, p.[Code]
        FROM dbo.UserRoles ur
        JOIN dbo.Roles r ON r.RoleID = ur.RoleID
        JOIN dbo.RolePermissions rp ON rp.RoleID = r.RoleID
        JOIN dbo.Permissions p ON p.PermissionID = rp.PermissionID
        WHERE ur.UserID = :uid
        ORDER BY r.RoleName, p.[Code]
    ");
    $st->execute(array(':uid'=>$uid));
    while ($row = $st->fetch()) {
        $rn = $row['RoleName'];
        if (!isset($rolePermMap[$rn])) $rolePermMap[$rn] = array();
        $rolePermMap[$rn][] = $row['Code'];
    }
}
?>
<!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <title>User Access (Roles & Permissions)</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <style>
    body{font-family:system-ui, -apple-system, Segoe UI, Roboto, Arial; background:#f8fafc; margin:0; padding:0;}
    .wrap{max-width:1000px; margin:30px auto; background:#fff; border:1px solid #e5e7eb; border-radius:12px; padding:20px; box-shadow:0 6px 20px rgba(0,0,0,.06);}
    .row{display:flex; gap:20px; flex-wrap:wrap;}
    .card{flex:1 1 320px; border:1px solid #e5e7eb; border-radius:10px; padding:16px;}
    h1{margin:0 0 16px 0;}
    table{border-collapse:collapse; width:100%;}
    th,td{border:1px solid #e5e7eb; padding:8px; text-align:left;}
    th{background:#f1f5f9;}
    .badge{display:inline-block; padding:3px 8px; border-radius:999px; background:#eef2ff;}
    .muted{color:#64748b;}
    .pill{display:inline-block; padding:3px 8px; border-radius:999px; background:#f1f5f9; border:1px solid #e2e8f0; margin:2px 6px 2px 0;}
    .topbar{display:flex; justify-content:space-between; align-items:center; margin-bottom:16px;}
    .btn{padding:8px 12px; background:#0ea5e9; color:#fff; border:none; border-radius:8px; cursor:pointer;}
    .btn:hover{background:#0284c7;}
    select{padding:6px 8px;}
  </style>
</head>
<body>
  <div class="wrap">
    <div class="topbar">
      <h1>User Access</h1>
      <a class="btn" href="index.php">Back</a>
    </div>

    <form method="get" class="row" style="align-items:flex-end; margin-bottom:16px;">
      <label>Choose user
        <select name="uid" required>
          <option value="">-- select user --</option>
          <?php foreach($users as $u): ?>
            <option value="<?php echo (int)$u['UserID']; ?>" <?php echo ($uid == (int)$u['UserID'] ? 'selected' : ''); ?>>
              <?php echo h($u['Username']); ?>
            </option>
          <?php endforeach; ?>
        </select>
      </label>
      <button type="submit" class="btn">View</button>
    </form>

    <?php if (!$userRow && $uid > 0): ?>
      <div class="card"><strong>User not found.</strong></div>
    <?php endif; ?>

    <?php if ($userRow): ?>
      <div class="row">
        <div class="card" style="flex:1 1 100%">
          <h2 style="margin-top:0">User</h2>
          <div><span class="badge">ID #<?php echo (int)$userRow['UserID']; ?></span></div>
          <p style="margin:8px 0 0 0"><strong>Username:</strong> <?php echo h($userRow['Username']); ?></p>
          <p class="muted" style="margin:4px 0"><strong>Email:</strong> <?php echo h(isset($userRow['Email']) ? $userRow['Email'] : ''); ?></p>
          <p class="muted" style="margin:4px 0">
            <strong>Status:</strong> <?php echo ((int)$userRow['IsActive'] === 1 ? 'Active' : 'Inactive'); ?> |
            <strong>Last login:</strong> <?php echo h(isset($userRow['LastLoginAt']) ? $userRow['LastLoginAt'] : '—'); ?>
          </p>
        </div>
      </div>

      <div class="row" style="margin-top:16px;">
        <div class="card">
          <h2 style="margin-top:0">Assigned Roles</h2>
          <?php if (empty($roles)): ?>
            <p class="muted">No roles assigned.</p>
          <?php else: ?>
            <table>
              <tr><th>#</th><th>Role</th><th>Description</th><th>Assigned At</th><th>Assigned By</th></tr>
              <?php foreach($roles as $i => $r): ?>
                <tr>
                  <td><?php echo $i+1; ?></td>
                  <td><?php echo h($r['RoleName']); ?></td>
                  <td><?php echo h(isset($r['Description']) ? $r['Description'] : ''); ?></td>
                  <td><?php echo h(isset($r['AssignedAt']) ? $r['AssignedAt'] : ''); ?></td>
                  <td><?php echo h(isset($r['AssignedBy']) ? $r['AssignedBy'] : ''); ?></td>
                </tr>
              <?php endforeach; ?>
            </table>
          <?php endif; ?>
        </div>

        <div class="card">
          <h2 style="margin-top:0">Effective Permissions</h2>
          <?php if (empty($perms)): ?>
            <p class="muted">No permissions (via roles) found.</p>
          <?php else: ?>
            <table>
              <tr><th>#</th><th>Code</th><th>Description</th></tr>
              <?php foreach($perms as $i => $p): ?>
                <tr>
                  <td><?php echo $i+1; ?></td>
                  <td><span class="pill"><?php echo h($p['Code']); ?></span></td>
                  <td><?php echo h(isset($p['Description']) ? $p['Description'] : ''); ?></td>
                </tr>
              <?php endforeach; ?>
            </table>
          <?php endif; ?>
        </div>
      </div>

      <div class="card" style="margin-top:16px;">
        <h2 style="margin-top:0">Role → Permission Mapping</h2>
        <?php if (empty($rolePermMap)): ?>
          <p class="muted">No mapping to display.</p>
        <?php else: ?>
          <?php foreach($rolePermMap as $roleName => $permCodes): ?>
            <p>
              <strong><?php echo h($roleName); ?>:</strong>
              <?php foreach($permCodes as $c): ?>
                <span class="pill"><?php echo h($c); ?></span>
              <?php endforeach; ?>
            </p>
          <?php endforeach; ?>
        <?php endif; ?>
      </div>
    <?php endif; ?>
  </div>
</body>
</html>
