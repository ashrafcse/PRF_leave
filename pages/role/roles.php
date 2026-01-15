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
        $target = isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : 'roles.php';
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
// চাইলে ব্যবহার করুন: require_permission($conn,'manage.roles');

// ---- page logic ----
$msg = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $role = isset($_POST['RoleName']) ? trim($_POST['RoleName']) : '';
  $desc = isset($_POST['Description']) ? trim($_POST['Description']) : '';
  if ($role !== '') {
    try {
      $conn->prepare("INSERT INTO dbo.Roles (RoleName, [Description]) VALUES (:n,:d)")
           ->execute(array(':n'=>$role, ':d'=>($desc !== '' ? $desc : null)));
      $msg = "Role created";
    } catch(PDOException $e) {
      $msg = "Create failed (maybe duplicate)";
    }
  }
}

$rows = $conn->query("SELECT RoleID, RoleName, [Description] FROM dbo.Roles ORDER BY RoleName")->fetchAll();
?>
<!doctype html>
<html>
<head><meta charset="utf-8"><title>Roles</title></head>
<body style="font-family:system-ui">
  <?php if ($msg): ?>
    <div style="max-width:800px;margin:20px auto;background:#e0f7e0;padding:10px;border-radius:6px;"><?php echo h($msg); ?></div>
  <?php endif; ?>

  <div style="max-width:800px;margin:30px auto;">
    <h2>Roles</h2>
    <form method="post" style="display:grid;gap:8px;max-width:420px;">
      <label>Role Name <input name="RoleName" required></label>
      <label>Description <input name="Description"></label>
      <button type="submit" style="padding:8px;background:#2563eb;color:#fff;border:none;border-radius:6px;">Add Role</button>
    </form>

    <h3 style="margin-top:20px">All Roles</h3>
    <table border="1" cellpadding="6" cellspacing="0">
      <tr><th>ID</th><th>Name</th><th>Description</th></tr>
      <?php foreach($rows as $r): ?>
        <tr>
          <td><?php echo (int)$r['RoleID']; ?></td>
          <td><?php echo h($r['RoleName']); ?></td>
          <td><?php echo h(isset($r['Description']) ? $r['Description'] : ''); ?></td>
        </tr>
      <?php endforeach; ?>
    </table>

    <p><a href="index.php">Back</a></p>
  </div>
</body>
</html>
