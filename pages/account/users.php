<?php 
/**********************************************
 * Users - Full CRUD (same design as Roles page)
 * Table: dbo.Users
 *   UserID (int, PK), Username (nvarchar 100, not null),
 *   PasswordHash (nvarchar 200, not null), Email (nvarchar 200, null),
 *   IsActive (bit, not null), CreatedAt (datetime, not null),
 *   CreatedBy (int, null), LastLoginAt (datetime, null/opt)
 **********************************************/
ini_set('display_errors', 1);
error_reporting(E_ALL);

/* 1) Boot */
require_once __DIR__ . '/../../init.php';
require_login(); // must be logged in

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

/* RBAC (optional but recommended) */
function has_permission(PDO $conn, $code){
  if (!isset($_SESSION['auth_user'])) return false;
  $uid = (int)(
    (isset($_SESSION['auth_user']['UserID']) ? $_SESSION['auth_user']['UserID'] :
    (isset($_SESSION['auth_user']['id']) ? $_SESSION['auth_user']['id'] : 0))
  );
  if ($uid <= 0) return false;
  $sql = "SELECT 1
            FROM dbo.UserRoles ur
            JOIN dbo.RolePermissions rp ON rp.RoleID = ur.RoleID
            JOIN dbo.Permissions p ON p.PermissionID = rp.PermissionID
           WHERE ur.UserID = :uid AND p.Code = :c";
  $st = $conn->prepare($sql);
  $st->execute(array(':uid'=>$uid, ':c'=>$code));
  return (bool)$st->fetch();
}
function require_permission(PDO $conn, $code){
  if (!has_permission($conn, $code)) { header('Location: '.BASE_URL.'forbidden.php'); exit; }
}

// require_permission($conn, 'manage.users');

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

/* 4) Page state */
$msg = '';
$msg_type = 'success';

if (isset($_GET['ok']) && $_GET['ok'] === '1') {
  $msg = 'User created.';
  $msg_type = 'success';
}

$search  = isset($_GET['q']) ? trim((string)$_GET['q']) : '';
$edit_id = isset($_GET['edit']) ? (int)$_GET['edit'] : 0;
$editRow = null;

/* 5) Load row for edit (if any) */
if ($edit_id > 0) {
  try {
    $st = $conn->prepare("
      SELECT TOP 1 UserID, Username, Email, IsActive, CreatedAt, CreatedBy, LastLoginAt
        FROM dbo.Users
       WHERE UserID = :id
    ");
    $st->execute(array(':id'=>$edit_id));
    $editRow = $st->fetch(PDO::FETCH_ASSOC);
    if (!$editRow) { $msg='Row not found for edit.'; $msg_type='danger'; $edit_id=0; }
  } catch (PDOException $e) {
    $msg='Load edit row failed: '.h($e->getMessage()); $msg_type='danger';
  }
}

/* 6) Actions (no output before these) */

/* CREATE */
if ($_SERVER['REQUEST_METHOD']==='POST' && (isset($_POST['act']) ? $_POST['act'] : '')==='create') {
  check_csrf();
  $Username = isset($_POST['Username']) ? trim((string)$_POST['Username']) : '';
  $Email    = isset($_POST['Email']) ? trim((string)$_POST['Email']) : '';
  $IsActive = isset($_POST['IsActive']) ? 1 : 0;
  $Pwd      = isset($_POST['Password']) ? (string)$_POST['Password'] : '';
  $Pwd2     = isset($_POST['ConfirmPassword']) ? (string)$_POST['ConfirmPassword'] : '';

  if ($Username === '' || $Pwd === '' || $Pwd2 === '') {
    $msg = 'Username, Password and Confirm Password are required.';
    $msg_type = 'danger';
  } elseif ($Pwd !== $Pwd2) {
    $msg = 'Password and Confirm Password do not match.';
    $msg_type = 'danger';
  } elseif (strlen($Pwd) < 8) {
    $msg = 'Password must be at least 8 characters.';
    $msg_type = 'danger';
  } else {
    try {
      // duplicate username
      $chk = $conn->prepare("SELECT 1 FROM dbo.Users WHERE Username = :u");
      $chk->execute(array(':u'=>$Username));
      if ($chk->fetchColumn()) {
        $msg = 'Create failed: Username already exists.';
        $msg_type = 'danger';
      } else {
        $hash = password_hash($Pwd, PASSWORD_DEFAULT);
        $by = (int)(
          (isset($_SESSION['auth_user']['UserID']) ? $_SESSION['auth_user']['UserID'] :
          (isset($_SESSION['auth_user']['id']) ? $_SESSION['auth_user']['id'] : 0))
        );
        $st = $conn->prepare("
          INSERT INTO dbo.Users (Username, PasswordHash, Email, IsActive, CreatedAt, CreatedBy)
          VALUES (:u, :h, :e, :a, GETDATE(), :by)
        ");
        $st->bindValue(':u',  $Username, PDO::PARAM_STR);
        $st->bindValue(':h',  $hash, PDO::PARAM_STR);
        if ($Email==='') $st->bindValue(':e', null, PDO::PARAM_NULL); else $st->bindValue(':e', $Email, PDO::PARAM_STR);
        $st->bindValue(':a',  $IsActive, PDO::PARAM_INT);
        if ($by>0) $st->bindValue(':by', $by, PDO::PARAM_INT); else $st->bindValue(':by', null, PDO::PARAM_NULL);
        $st->execute();

        header('Location: '.$self.'?ok=1'); 
        exit;
      }
    } catch (PDOException $e) {
      $msg = 'Create failed: '.h($e->getMessage());
      $msg_type = 'danger';
    }
  }
}

/* UPDATE (basic fields; optional password reset) */
if ($_SERVER['REQUEST_METHOD']==='POST' && (isset($_POST['act']) ? $_POST['act'] : '')==='update') {
  check_csrf();
  $UserID   = isset($_POST['UserID']) ? (int)$_POST['UserID'] : 0;
  $Username = isset($_POST['Username']) ? trim((string)$_POST['Username']) : '';
  $Email    = isset($_POST['Email']) ? trim((string)$_POST['Email']) : '';
  $IsActive = isset($_POST['IsActive']) ? 1 : 0;
  $NewPwd   = isset($_POST['NewPassword']) ? (string)$_POST['NewPassword'] : '';
  $NewPwd2  = isset($_POST['ConfirmNewPassword']) ? (string)$_POST['ConfirmNewPassword'] : '';

  if ($UserID<=0 || $Username==='') {
    $msg = 'Invalid data.'; $msg_type='danger';
  } else {
    try {
      // username duplicate (ignore self)
      $chk = $conn->prepare("SELECT 1 FROM dbo.Users WHERE Username=:u AND UserID<>:id");
      $chk->execute(array(':u'=>$Username, ':id'=>$UserID));
      if ($chk->fetchColumn()) {
        $msg = 'Update failed: Username already exists.'; $msg_type='danger';
      } else {
        // base update
        $st = $conn->prepare("
          UPDATE dbo.Users
             SET Username=:u, Email=:e, IsActive=:a
           WHERE UserID=:id
        ");
        $st->bindValue(':u',$Username, PDO::PARAM_STR);
        if ($Email==='') $st->bindValue(':e', null, PDO::PARAM_NULL); else $st->bindValue(':e',$Email,PDO::PARAM_STR);
        $st->bindValue(':a',$IsActive,PDO::PARAM_INT);
        $st->bindValue(':id',$UserID,PDO::PARAM_INT);
        $st->execute();

        // optional password change
        if ($NewPwd !== '' || $NewPwd2 !== '') {
          if ($NewPwd !== $NewPwd2) {
            $msg = 'Update saved, but password not changed (confirmation mismatch).';
            $msg_type = 'danger';
          } elseif (strlen($NewPwd) < 8) {
            $msg = 'Update saved, but password not changed (min 8 chars).';
            $msg_type = 'danger';
          } else {
            $hash = password_hash($NewPwd, PASSWORD_DEFAULT);
            $p = $conn->prepare("UPDATE dbo.Users SET PasswordHash=:h WHERE UserID=:id");
            $p->execute(array(':h'=>$hash, ':id'=>$UserID));
            $msg = 'User updated (including password).';
            $msg_type = 'success';
          }
        } else {
          $msg = 'User updated.';
          $msg_type = 'success';
        }

        header('Location: '.$self); 
        exit;
      }
    } catch (PDOException $e) {
      $msg = 'Update failed: '.h($e->getMessage()); $msg_type='danger';
    }
  }
}

/* TOGGLE ACTIVE */
if ($_SERVER['REQUEST_METHOD']==='POST' && (isset($_POST['act']) ? $_POST['act'] : '')==='toggle') {
  check_csrf();
  $id = isset($_POST['UserID']) ? (int)$_POST['UserID'] : 0;
  $to = isset($_POST['to']) ? (int)$_POST['to'] : 0;
  if ($id>0) {
    try {
      $conn->prepare("UPDATE dbo.Users SET IsActive=:a WHERE UserID=:id")->execute(array(':a'=>$to, ':id'=>$id));
      $msg = $to ? 'Activated.' : 'Deactivated.'; $msg_type='success';
    } catch (PDOException $e) { $msg='Toggle failed: '.h($e->getMessage()); $msg_type='danger'; }
  }
}

/* DELETE */
if ($_SERVER['REQUEST_METHOD']==='POST' && (isset($_POST['act']) ? $_POST['act'] : '')==='delete') {
  check_csrf();
  $id = isset($_POST['UserID']) ? (int)$_POST['UserID'] : 0;
  if ($id>0) {
    try {
      $conn->prepare("DELETE FROM dbo.Users WHERE UserID=:id")->execute(array(':id'=>$id));
      $msg = 'User deleted.'; $msg_type='success';
    } catch (PDOException $e) { $msg='Delete failed: '.h($e->getMessage()); $msg_type='danger'; }
  }
}

/* 7) List query */
try {
  if ($search !== '') {
    $st = $conn->prepare("
      SELECT UserID, Username, Email, IsActive, CreatedAt, CreatedBy, LastLoginAt
        FROM dbo.Users
       WHERE Username LIKE :q OR Email LIKE :q
       ORDER BY Username
    ");
    $st->execute(array(':q'=>'%'.$search.'%'));
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
  } else {
    $rows = $conn->query("
      SELECT UserID, Username, Email, IsActive, CreatedAt, CreatedBy, LastLoginAt
        FROM dbo.Users
       ORDER BY Username
    ")->fetchAll(PDO::FETCH_ASSOC);
  }
} catch (PDOException $e) { 
  $rows=array(); 
  $msg='Load list failed: '.h($e->getMessage()); 
  $msg_type='danger'; 
}

/* 8) Render */
require_once __DIR__ . '/../../include/header.php';
?>

<!-- Bootstrap Icons CDN (icon না পাওয়ার সমস্যা fix) -->
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

  .table-users{
    border-radius:14px;
    overflow:hidden;
  }
  .table-users thead th{
    background:#f8fafc;
    color:#334155;
    border-bottom:1px solid #e5e7eb;
    font-size:12px;
    text-transform:uppercase;
    letter-spacing:.06em;
  }
  .table-users tbody td{
    vertical-align:middle;
    font-size:13px;
    border-top:1px solid #e5e7eb;
  }
  .table-users tbody tr:nth-child(even){
    background:#f9fafb;
  }
  .table-users tbody tr:hover{
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
      <span>Users</span>
    </h1>
    <div class="page-subtitle">
      System users manage করুন, status, email ও login history সহ।
    </div>
  </div>

  <!-- Alerts -->
  <?php if ($msg): ?>
    <div class="alert alert-<?php echo ($msg_type==='danger'?'danger':'success'); ?> alert-dismissible fade show" role="alert">
      <i class="bi <?php echo ($msg_type==='danger'?'bi-exclamation-triangle-fill':'bi-check-circle-fill'); ?>"></i>
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
            <span>Edit User</span>
          </div>
          <a class="btn btn-muted btn-sm" href="<?php echo h($self); ?>">
            <i class="bi bi-x-circle"></i>&nbsp;Cancel
          </a>
        </div>

        <form method="post" class="row g-3" accept-charset="UTF-8" autocomplete="off">
          <input type="hidden" name="csrf" value="<?php echo h($CSRF); ?>">
          <input type="hidden" name="act" value="update">
          <input type="hidden" name="UserID" value="<?php echo (int)$editRow['UserID']; ?>">

          <div class="col-12 col-md-4">
            <label class="form-label">
              <i class="bi bi-person"></i>&nbsp;Username
            </label>
            <input type="text" name="Username" class="form-control" required maxlength="100"
                   value="<?php echo h($editRow['Username']); ?>">
          </div>
          <div class="col-12 col-md-4">
            <label class="form-label">
              <i class="bi bi-envelope"></i>&nbsp;Email
            </label>
            <input type="email" name="Email" class="form-control" maxlength="200"
                   value="<?php echo h(isset($editRow['Email']) ? $editRow['Email'] : ''); ?>">
          </div>
          <div class="col-12 col-md-4 d-flex align-items-end">
            <div class="form-check">
              <input class="form-check-input" type="checkbox" name="IsActive" id="isActiveEdit"
                     <?php echo ((int)$editRow['IsActive']===1?'checked':''); ?>>
              <label class="form-check-label" for="isActiveEdit">Active</label>
            </div>
          </div>

          <div class="col-12 col-md-6">
            <label class="form-label">
              <i class="bi bi-key"></i>&nbsp;New Password (optional)
            </label>
            <input type="password" name="NewPassword" class="form-control" minlength="8" placeholder="Leave blank to keep current">
          </div>
          <div class="col-12 col-md-6">
            <label class="form-label">
              <i class="bi bi-key-fill"></i>&nbsp;Confirm New Password
            </label>
            <input type="password" name="ConfirmNewPassword" class="form-control" minlength="8" placeholder="Leave blank to keep current">
          </div>

          <div class="col-12">
            <div class="text-muted small">
              <span class="badge-soft">
                <i class="bi bi-calendar"></i>&nbsp;
                <?php echo 'Created: '.h(isset($editRow['CreatedAt']) ? $editRow['CreatedAt'] : ''); ?>
              </span>
              &nbsp;
              <span class="badge-soft">
                <i class="bi bi-person-badge"></i>&nbsp;
                <?php echo 'By: '.h(isset($editRow['CreatedBy']) ? $editRow['CreatedBy'] : ''); ?>
              </span>
              &nbsp;
              <span class="badge-soft">
                <i class="bi bi-clock-history"></i>&nbsp;
                <?php echo 'Last login: '.h(isset($editRow['LastLoginAt']) ? $editRow['LastLoginAt'] : ''); ?>
              </span>
            </div>
          </div>

          <div class="col-12 d-grid d-md-inline">
            <button class="btn btn-brand w-100 w-md-auto">
              <i class="bi bi-save"></i>&nbsp;Update User
            </button>
          </div>
        </form>

      <?php else: ?>
        <div class="section-header">
          <div class="section-title">
            <span class="section-title-icon">
              <i class="bi bi-person-plus-fill"></i>
            </span>
            <span>Add User</span>
          </div>
        </div>

        <form method="post" class="row g-3" accept-charset="UTF-8" autocomplete="off">
          <input type="hidden" name="csrf" value="<?php echo h($CSRF); ?>">
          <input type="hidden" name="act" value="create">

          <div class="col-12 col-md-4">
            <label class="form-label">
              <i class="bi bi-person"></i>&nbsp;Username
            </label>
            <input type="text" name="Username" class="form-control" required maxlength="100" placeholder="e.g. jdoe">
          </div>
          <div class="col-12 col-md-4">
            <label class="form-label">
              <i class="bi bi-envelope"></i>&nbsp;Email
            </label>
            <input type="email" name="Email" class="form-control" maxlength="200" placeholder="Optional">
          </div>
          <div class="col-12 col-md-4 d-flex align-items-end">
            <div class="form-check">
              <input class="form-check-input" type="checkbox" name="IsActive" id="isActiveCreate" checked>
              <label class="form-check-label" for="isActiveCreate">Active</label>
            </div>
          </div>

          <div class="col-12 col-md-6">
            <label class="form-label">
              <i class="bi bi-key"></i>&nbsp;Password
            </label>
            <input type="password" name="Password" class="form-control" required minlength="8" placeholder="min 8 characters">
          </div>
          <div class="col-12 col-md-6">
            <label class="form-label">
              <i class="bi bi-key-fill"></i>&nbsp;Confirm Password
            </label>
            <input type="password" name="ConfirmPassword" class="form-control" required minlength="8">
          </div>

          <div class="col-12 d-grid d-md-inline">
            <button class="btn btn-brand w-100 w-md-auto">
              <i class="bi bi-plus-circle"></i>&nbsp;Create User
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
          <span>All Users</span>
        </div>
        <span class="badge-soft">
          <i class="bi bi-collection"></i>&nbsp;Total: <?php echo count($rows); ?>
        </span>
      </div>

      <!-- 🔎 Filter just above table -->
      <form method="get" class="row g-2 align-items-end mb-3" accept-charset="UTF-8">
        <div class="col-sm-8">
          <label class="form-label mb-1">
            <i class="bi bi-search"></i>&nbsp;Search users
          </label>
          <div class="search-input-wrapper">
            <span class="search-input-icon">
              <i class="bi bi-search"></i>
            </span>
            <input type="text"
                   name="q"
                   class="form-control"
                   placeholder="Type username or email..."
                   value="<?php echo h($search); ?>">
          </div>
        </div>
        <div class="col-sm-4 text-sm-end">
          <div class="d-flex justify-content-sm-end gap-2 mt-3 mt-sm-0">
            <button class="btn btn-brand" type="submit">
              <i class="bi bi-filter"></i>&nbsp;Filter
            </button>
            <a class="btn btn-muted" href="<?php echo h($self); ?>">
              <i class="bi bi-x-circle"></i>&nbsp;Reset
            </a>
          </div>
        </div>
      </form>

      <div class="table-responsive">
        <table class="table table-hover table-users align-middle">
          <thead>
            <tr>
              <th class="col-id">ID</th>
              <th>Username</th>
              <th>Email</th>
              <th>Status</th>
              <th>Created</th>
              <th>By</th>
              <th>Last Login</th>
              <th class="text-end col-actions">Actions</th>
            </tr>
          </thead>
          <tbody>
          <?php foreach($rows as $r): ?>
            <tr>
              <td class="col-id">
                <span class="id-pill">
                  #<?php echo (int)$r['UserID']; ?>
                </span>
              </td>
              <td><?php echo h($r['Username']); ?></td>
              <td><?php echo h(isset($r['Email']) ? $r['Email'] : ''); ?></td>
              <td>
                <?php if ((int)$r['IsActive']===1): ?>
                  <span class="badge-soft status-badge-active">
                    <i class="bi bi-check-circle"></i>&nbsp;Active
                  </span>
                <?php else: ?>
                  <span class="badge-soft status-badge-inactive">
                    <i class="bi bi-dash-circle"></i>&nbsp;Inactive
                  </span>
                <?php endif; ?>
              </td>
              <td><?php echo h(isset($r['CreatedAt']) ? $r['CreatedAt'] : ''); ?></td>
              <td><?php echo h(isset($r['CreatedBy']) ? $r['CreatedBy'] : ''); ?></td>
              <td><?php echo h(isset($r['LastLoginAt']) ? $r['LastLoginAt'] : ''); ?></td>
              <td class="text-end col-actions">
                <div class="actions-stack">

                  <!-- Edit -->
                  <a class="btn btn-muted btn-icon btn-sm"
                     href="<?php echo h($self); ?>?edit=<?php echo (int)$r['UserID']; ?>"
                     title="Edit user">
                    <i class="bi bi-pencil"></i>
                  </a>

                  <!-- Toggle -->
                  <form method="post" 
                        onsubmit="return confirm('Toggle active status?');"
                        accept-charset="UTF-8">
                    <input type="hidden" name="csrf" value="<?php echo h($CSRF); ?>">
                    <input type="hidden" name="act" value="toggle">
                    <input type="hidden" name="UserID" value="<?php echo (int)$r['UserID']; ?>">
                    <input type="hidden" name="to" value="<?php echo ((int)$r['IsActive']===1?0:1); ?>">
                    <button class="btn btn-muted btn-icon btn-sm" type="submit"
                            title="<?php echo ((int)$r['IsActive']===1?'Deactivate':'Activate'); ?>">
                      <?php if ((int)$r['IsActive']===1): ?>
                        <i class="bi bi-pause"></i>
                      <?php else: ?>
                        <i class="bi bi-play-fill"></i>
                      <?php endif; ?>
                    </button>
                  </form>

                  <!-- Delete -->
                  <form method="post"
                        onsubmit="return confirm('Delete this user permanently?');"
                        accept-charset="UTF-8">
                    <input type="hidden" name="csrf" value="<?php echo h($CSRF); ?>">
                    <input type="hidden" name="act" value="delete">
                    <input type="hidden" name="UserID" value="<?php echo (int)$r['UserID']; ?>">
                    <button class="btn btn-danger-soft btn-icon btn-sm" type="submit" title="Delete user">
                      <i class="bi bi-trash"></i>
                    </button>
                  </form>

                </div>
              </td>
            </tr>
          <?php endforeach; ?>

          <?php if (empty($rows)): ?>
            <tr>
              <td colspan="8" class="text-center text-muted py-4">
                No users found.
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
