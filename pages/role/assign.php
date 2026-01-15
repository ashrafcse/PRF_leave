<?php
// -------- bootstrap (no external auth.php) --------
if (session_status() === PHP_SESSION_NONE) {
    $secure   = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    $httponly = true;
    session_set_cookie_params(0, '/', '', $secure, $httponly);
    session_start();
}

// DB connect
$serverName  = "AMRITO_BOSU\\SQLEXPRESS";
$database    = "assetMng";
$db_username = "";
$password    = "";

try {
    $conn = new PDO("sqlsrv:Server=$serverName;Database=$database", $db_username, $password);
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $conn->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    die('DB connect failed: ' . $e->getMessage());
}

// helpers
function h($s){ return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }
function current_user(){ return isset($_SESSION['auth_user']) ? $_SESSION['auth_user'] : null; }
function require_login(){
    if (!current_user()){
        $target = isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : 'assign.php';
        header('Location: login.php?next=' . rawurlencode($target));
        exit;
    }
}
function has_permission(PDO $conn, $code){
    $u = current_user(); if(!$u) return false;
    $sql = "SELECT 1
            FROM dbo.UserRoles ur
            JOIN dbo.RolePermissions rp ON rp.RoleID = ur.RoleID
            JOIN dbo.Permissions p ON p.PermissionID = rp.PermissionID
            WHERE ur.UserID = :uid AND p.Code = :c";
    $st = $conn->prepare($sql);
    $st->execute(array(':uid'=>(int)$u['UserID'], ':c'=>$code));
    return (bool)$st->fetch();
}
function require_permission(PDO $conn, $code){
    require_login();
    if (!has_permission($conn, $code)) { header('Location: forbidden.php'); exit; }
}

// ---- guards ----
require_login();
// চাইলে ব্যবহার করুন:
// require_permission($conn,'manage.roles');
// require_permission($conn,'manage.permissions');

$msg = '';

// POST: assign user → role
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['assign_user_role'])) {
  $userId = isset($_POST['user_id']) ? (int)$_POST['user_id'] : 0;
  $roleId = isset($_POST['role_id']) ? (int)$_POST['role_id'] : 0;
  if ($userId && $roleId) {
    try {
      $byVal = current_user() ? current_user()['Username'] : null;
      $stmt = $conn->prepare("INSERT INTO dbo.UserRoles (UserID, RoleID, AssignedAt, AssignedBy) VALUES (:u,:r,GETDATE(),:by)");
      $stmt->execute(array(':u'=>$userId, ':r'=>$roleId, ':by'=>$byVal));
      $msg = "User role assigned";
    } catch(PDOException $e) {
      $msg = "Assign failed (duplicate?)";
    }
  }
}

// POST: assign role → permission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['assign_role_permission'])) {
  $roleId = isset($_POST['role_id2']) ? (int)$_POST['role_id2'] : 0;
  $permId = isset($_POST['permission_id']) ? (int)$_POST['permission_id'] : 0;
  if ($roleId && $permId) {
    try {
      $stmt = $conn->prepare("INSERT INTO dbo.RolePermissions (RoleID, PermissionID) VALUES (:r,:p)");
      $stmt->execute(array(':r'=>$roleId, ':p'=>$permId));
      $msg = "Permission assigned to role";
    } catch(PDOException $e) {
      $msg = "Assign failed (duplicate?)";
    }
  }
}

// fetch lists
$users = $conn->query("SELECT UserID, Username FROM dbo.Users ORDER BY Username")->fetchAll();
$roles = $conn->query("SELECT RoleID, RoleName FROM dbo.Roles ORDER BY RoleName")->fetchAll();
$perms = $conn->query("SELECT PermissionID, [Code] FROM dbo.Permissions ORDER BY [Code]")->fetchAll();
?>
<!doctype html>
<html>
<head><meta charset="utf-8"><title>Assign</title></head>
<body style="font-family:system-ui">
  <?php if ($msg): ?>
    <div style="max-width:900px;margin:20px auto;background:#e0f7e0;padding:10px;border-radius:6px;"><?php echo h($msg); ?></div>
  <?php endif; ?>

  <div style="max-width:900px;margin:30px auto;display:grid;gap:24px;">
    <section>
      <h2>Assign Role to User</h2>
      <form method="post" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
        <input type="hidden" name="assign_user_role" value="1">
        <label>User
          <select name="user_id" required>
            <option value="">--select--</option>
            <?php foreach($users as $u): ?>
              <option value="<?php echo (int)$u['UserID']; ?>"><?php echo h($u['Username']); ?></option>
            <?php endforeach; ?>
          </select>
        </label>
        <label>Role
          <select name="role_id" required>
            <option value="">--select--</option>
            <?php foreach($roles as $r): ?>
              <option value="<?php echo (int)$r['RoleID']; ?>"><?php echo h($r['RoleName']); ?></option>
            <?php endforeach; ?>
          </select>
        </label>
        <button type="submit" style="padding:8px 12px;background:#2563eb;color:#fff;border:none;border-radius:6px;">Assign</button>
      </form>
    </section>

    <section>
      <h2>Assign Permission to Role</h2>
      <form method="post" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
        <input type="hidden" name="assign_role_permission" value="1">
        <label>Role
          <select name="role_id2" required>
            <option value="">--select--</option>
            <?php foreach($roles as $r): ?>
              <option value="<?php echo (int)$r['RoleID']; ?>"><?php echo h($r['RoleName']); ?></option>
            <?php endforeach; ?>
          </select>
        </label>
        <label>Permission
          <select name="permission_id" required>
            <option value="">--select--</option>
            <?php foreach($perms as $p): ?>
              <option value="<?php echo (int)$p['PermissionID']; ?>"><?php echo h($p['Code']); ?></option>
            <?php endforeach; ?>
          </select>
        </label>
        <button type="submit" style="padding:8px 12px;background:#7c3aed;color:#fff;border:none;border-radius:6px;">Assign</button>
      </form>
    </section>

    <p><a href="index.php">Back</a></p>
  </div>
</body>
</html>
