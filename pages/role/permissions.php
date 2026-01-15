<?php
// -------- bootstrap (no external auth.php) --------
if (session_status() === PHP_SESSION_NONE) {
    $secure   = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    $httponly = true;
    session_set_cookie_params(0, '/', '', $secure, $httponly);
    session_start();
}

// DB connect (PDO sqlsrv)
$serverName  = "AMRITO_BOSU\\SQLEXPRESS";
$database    = "assetMng";
$db_username = ""; // SQL Auth হলে দিন; না হলে কনফিগ অনুযায়ী ফাঁকা থাকতে পারে
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
        $target = isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : 'permissions.php';
        header('Location: login.php?next=' . rawurlencode($target));
        exit;
    }
}
// Optional: RBAC check
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
    if (!has_permission($conn, $code)) {
        header('Location: forbidden.php'); exit;
    }
}

// ---- guards ----
require_login();
// চাইলে ব্যবহার করুন: require_permission($conn,'manage.permissions');

// ---- page logic ----
$msg = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $code = isset($_POST['Code']) ? trim($_POST['Code']) : '';
  $desc = isset($_POST['Description']) ? trim($_POST['Description']) : '';
  if ($code !== '') {
    try {
      $conn->prepare("INSERT INTO dbo.Permissions ([Code],[Description]) VALUES (:c,:d)")
           ->execute(array(':c'=>$code, ':d'=>($desc !== '' ? $desc : null)));
      $msg = "Permission created";
    } catch(PDOException $e) {
      $msg = "Create failed (maybe duplicate)";
    }
  }
}

$rows = $conn->query("SELECT PermissionID, [Code], [Description] FROM dbo.Permissions ORDER BY [Code]")->fetchAll();
?>
<!doctype html>
<html>
<head><meta charset="utf-8"><title>Permissions</title></head>
<body style="font-family:system-ui">
  <?php if ($msg): ?>
    <div style="max-width:800px;margin:20px auto;background:#e0f7e0;padding:10px;border-radius:6px;"><?php echo h($msg); ?></div>
  <?php endif; ?>

  <div style="max-width:800px;margin:30px auto;">
    <h2>Permissions</h2>
    <form method="post" style="display:grid;gap:8px;max-width:420px;">
      <label>Code <input name="Code" required placeholder="e.g. manage.users"></label>
      <label>Description <input name="Description"></label>
      <button type="submit" style="padding:8px;background:#7c3aed;color:#fff;border:none;border-radius:6px;">Add Permission</button>
    </form>

    <h3 style="margin-top:20px">All Permissions</h3>
    <table border="1" cellpadding="6" cellspacing="0">
      <tr><th>ID</th><th>Code</th><th>Description</th></tr>
      <?php foreach($rows as $r): ?>
        <tr>
          <td><?php echo (int)$r['PermissionID']; ?></td>
          <td><?php echo h($r['Code']); ?></td>
          <td><?php echo h(isset($r['Description']) ? $r['Description'] : ''); ?></td>
        </tr>
      <?php endforeach; ?>
    </table>

    <p><a href="index.php">Back</a></p>
  </div>
</body>
</html>
