<?php
/**********************************************
 * My Profile – with avatar upload + preview
 * PHP 5.6 compatible
 **********************************************/
ini_set('display_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/../../init.php';
require_login();                         // must be logged in

/*------------------------------------------------
  Helpers
-------------------------------------------------*/
if (!function_exists('h')) {
    function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8', false); }
}
function self_name(){ return strtok(basename($_SERVER['SCRIPT_NAME']), "?"); }
$self = self_name();

/* RBAC (optional) */
function has_permission(PDO $conn, $code){
    if (!isset($_SESSION['auth_user'])) return false;

    // PHP 5.6 friendly
    $uid = 0;
    if (isset($_SESSION['auth_user']['UserID'])) {
        $uid = (int)$_SESSION['auth_user']['UserID'];
    } elseif (isset($_SESSION['auth_user']['id'])) {
        $uid = (int)$_SESSION['auth_user']['id'];
    }
    if ($uid <= 0) return false;

    $sql = "SELECT 1
              FROM dbo.UserRoles ur
              JOIN dbo.RolePermissions rp ON rp.RoleID = ur.RoleID
              JOIN dbo.Permissions p       ON p.PermissionID = rp.PermissionID
             WHERE ur.UserID = :uid
               AND p.Code    = :c";
    $st = $conn->prepare($sql);
    $st->execute(array(':uid'=>$uid, ':c'=>$code));
    return (bool)$st->fetchColumn();
}

/* CSRF */
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

/* Avatar helper – convert BLOB to data URL */
function user_avatar_src(array $row){
    // CHANGE THIS to your own default avatar
    $defaultAvatar = BASE_URL . 'assets/img/avatar-default.png';

    if (!isset($row['Avatar']) || empty($row['Avatar'])) return $defaultAvatar;

    $raw = $row['Avatar'];
    if (is_resource($raw)) {
        $bin = stream_get_contents($raw);
    } else {
        $bin = (string)$raw;
    }
    if ($bin === '') return $defaultAvatar;

    $mime = 'image/jpeg';
    if (function_exists('finfo_open')) {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        if ($finfo) {
            $det = finfo_buffer($finfo, $bin);
            finfo_close($finfo);
            if ($det) $mime = $det;
        }
    }
    if (strpos($mime, 'image/') !== 0) $mime = 'image/jpeg';

    return 'data:' . $mime . ';base64,' . base64_encode($bin);
}

/*------------------------------------------------
  Load current user
-------------------------------------------------*/
$meId = 0;
if (isset($_SESSION['auth_user']['UserID'])) {
    $meId = (int)$_SESSION['auth_user']['UserID'];
} elseif (isset($_SESSION['auth_user']['id'])) {
    $meId = (int)$_SESSION['auth_user']['id'];
}
if ($meId <= 0) {
    header('Location: ' . BASE_URL . 'login.php');
    exit;
}

try {
    $st = $conn->prepare("
        SELECT TOP 1 UserID, Username, Email, IsActive,
               CreatedAt, CreatedBy, LastLoginAt, PasswordHash, Avatar
          FROM dbo.Users
         WHERE UserID = :id
    ");
    $st->execute(array(':id'=>$meId));
    $me = $st->fetch(PDO::FETCH_ASSOC);
    if (!$me) die('User not found.');
} catch (PDOException $e) {
    die('Load failed: ' . h($e->getMessage()));
}

$avatarSrc = user_avatar_src($me);
$msg = '';
$msg_type = 'success';

/*------------------------------------------------
  Handle profile update (username/email/avatar)
-------------------------------------------------*/
$act = isset($_POST['act']) ? $_POST['act'] : '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $act === 'update_profile') {
    check_csrf();

    $username = isset($_POST['Username']) ? trim((string)$_POST['Username']) : '';
    $email    = isset($_POST['Email'])    ? trim((string)$_POST['Email'])    : '';

    if ($username === '') {
        $msg = 'Username is required.';
        $msg_type = 'danger';
    }

    // Avatar upload
    $avatarStream = null;
    $updateAvatar = false;

    if (isset($_FILES['avatar']) && $_FILES['avatar']['error'] !== UPLOAD_ERR_NO_FILE) {

        if ($_FILES['avatar']['error'] !== UPLOAD_ERR_OK) {
            $msg = 'Avatar upload failed.';
            $msg_type = 'danger';
        } else {
            // Big limit: 50MB (chaile change korte paro)
            $maxSize = 50 * 1024 * 1024;
            if ($_FILES['avatar']['size'] > $maxSize) {
                $msg = 'Photo too large (max ~50MB).';
                $msg_type = 'danger';
            } else {
                $allowed = array('image/jpeg','image/png','image/gif','image/webp');
                $mime    = null;

                if (function_exists('finfo_open')) {
                    $finfo = finfo_open(FILEINFO_MIME_TYPE);
                    if ($finfo) {
                        $mime = finfo_file($finfo, $_FILES['avatar']['tmp_name']);
                        finfo_close($finfo);
                    }
                }

                if ($mime && !in_array($mime, $allowed, true)) {
                    $msg = 'Only JPG / PNG / GIF / WEBP allowed.';
                    $msg_type = 'danger';
                } else {
                    $avatarStream = fopen($_FILES['avatar']['tmp_name'], 'rb');
                    if ($avatarStream === false) {
                        $msg = 'Could not read uploaded file.';
                        $msg_type = 'danger';
                    } else {
                        $updateAvatar = true;
                    }
                }
            }
        }
    }

    if ($msg_type !== 'danger') {
        try {
            // Unique username check
            $chk = $conn->prepare("SELECT 1 FROM dbo.Users WHERE Username = :u AND UserID <> :id");
            $chk->execute(array(':u'=>$username, ':id'=>$meId));

            if ($chk->fetchColumn()) {
                $msg = 'Update failed: Username already taken.';
                $msg_type = 'danger';
            } else {

                if ($updateAvatar) {
                    $sql = "UPDATE dbo.Users
                               SET Username = :u,
                                   Email    = :e,
                                   Avatar   = :a
                             WHERE UserID   = :id";
                    $stmt = $conn->prepare($sql);

                    $stmt->bindValue(':u', $username);
                    $stmt->bindValue(':e', ($email !== '' ? $email : null));
                    $stmt->bindValue(':id', $meId, PDO::PARAM_INT);

                    // IMPORTANT: send as binary LOB -> solves nvarchar/image clash
                    if (defined('PDO::SQLSRV_ENCODING_BINARY')) {
                        $stmt->bindParam(
                            ':a',
                            $avatarStream,
                            PDO::PARAM_LOB,
                            0,
                            PDO::SQLSRV_ENCODING_BINARY
                        );
                    } else {
                        $stmt->bindParam(':a', $avatarStream, PDO::PARAM_LOB);
                    }

                    $stmt->execute();
                    if (is_resource($avatarStream)) fclose($avatarStream);
                } else {
                    $stmt = $conn->prepare("
                        UPDATE dbo.Users
                           SET Username = :u,
                               Email    = :e
                         WHERE UserID   = :id
                    ");
                    $stmt->execute(array(
                        ':u'  => $username,
                        ':e'  => ($email !== '' ? $email : null),
                        ':id' => $meId
                    ));
                }

                // refresh session display name
                $_SESSION['auth_user']['username'] = $username;
                $_SESSION['auth_user']['Username'] = $username;

                $msg = 'Profile updated.';
                $msg_type = 'success';

                // reload fresh user (including avatar)
                $st = $conn->prepare("
                    SELECT TOP 1 UserID, Username, Email, IsActive,
                           CreatedAt, CreatedBy, LastLoginAt, PasswordHash, Avatar
                      FROM dbo.Users
                     WHERE UserID = :id
                ");
                $st->execute(array(':id'=>$meId));
                $me = $st->fetch(PDO::FETCH_ASSOC);
                $avatarSrc = user_avatar_src($me);
            }
        } catch (PDOException $e) {
            $msg = 'Update failed: ' . h($e->getMessage());
            $msg_type = 'danger';
        }
    }
}

/*------------------------------------------------
  Handle password change
-------------------------------------------------*/
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $act === 'change_password') {
    check_csrf();

    $old = isset($_POST['old_password']) ? (string)$_POST['old_password'] : '';
    $new = isset($_POST['new_password']) ? (string)$_POST['new_password'] : '';
    $cfn = isset($_POST['confirm_password']) ? (string)$_POST['confirm_password'] : '';

    if ($old === '' || $new === '' || $cfn === '') {
        $msg = 'All password fields are required.';
        $msg_type = 'danger';
    } elseif ($new !== $cfn) {
        $msg = 'New password and confirmation do not match.';
        $msg_type = 'danger';
    } elseif (strlen($new) < 8) {
        $msg = 'New password must be at least 8 characters.';
        $msg_type = 'danger';
    } else {
        try {
            $hash = isset($me['PasswordHash']) ? (string)$me['PasswordHash'] : '';
            if ($hash === '' || !password_verify($old, $hash)) {
                $msg = 'Old password is incorrect.';
                $msg_type = 'danger';
            } else {
                $newHash = password_hash($new, PASSWORD_DEFAULT);
                $st = $conn->prepare("UPDATE dbo.Users SET PasswordHash = :h WHERE UserID = :id");
                $st->execute(array(':h'=>$newHash, ':id'=>$meId));
                $msg = 'Password updated.';
                $msg_type = 'success';
            }
        } catch (PDOException $e) {
            $msg = 'Password change failed: ' . h($e->getMessage());
            $msg_type = 'danger';
        }
    }
}

/*------------------------------------------------
  Users list (RBAC)
-------------------------------------------------*/
$can_view_all = has_permission($conn, 'manage.users') || has_permission($conn, 'view.users');

try {
    if ($can_view_all) {
        $users = $conn->query("
            SELECT UserID, Username, Email, IsActive,
                   CreatedAt, CreatedBy, LastLoginAt
              FROM dbo.Users
             ORDER BY Username
        ")->fetchAll(PDO::FETCH_ASSOC);
    } else {
        $users = array(array(
            'UserID'     => $me['UserID'],
            'Username'   => $me['Username'],
            'Email'      => isset($me['Email']) ? $me['Email'] : '',
            'IsActive'   => $me['IsActive'],
            'CreatedAt'  => isset($me['CreatedAt']) ? $me['CreatedAt'] : '',
            'CreatedBy'  => isset($me['CreatedBy']) ? $me['CreatedBy'] : '',
            'LastLoginAt'=> isset($me['LastLoginAt']) ? $me['LastLoginAt'] : '',
        ));
    }
} catch (PDOException $e) {
    $users = array();
}

/*------------------------------------------------
  Render
-------------------------------------------------*/
require_once __DIR__ . '/../../include/header.php';
?>

<style>
  .profile-cover {
    background: linear-gradient(135deg, #0f172a, #1e3a8a);
    padding: 32px 24px;
    border-radius: 18px;
    color: #e5e7eb;
    position: relative;
    overflow: hidden;
  }
  .profile-cover::after{
    content:'';
    position:absolute;
    right:-80px;
    bottom:-80px;
    width:260px;height:260px;
    background: radial-gradient(circle at center, rgba(96,165,250,.35), transparent 60%);
    opacity:.8;
  }
  .profile-avatar-lg{
    width:120px;
    height:120px;
    border-radius:999px;
    object-fit:cover;
    border:3px solid rgba(248,250,252,.9);
    box-shadow:0 10px 30px rgba(15,23,42,.55);
  }
  .page-wrap { margin:28px auto; padding:0 12px; max-width:100%; }
  .section-card { border-radius:16px; border:1px solid #e5e7eb; box-shadow:0 8px 24px rgba(15,23,42,.05); }
  .badge-soft { border:1px solid #e2e8f0; background:#f8fafc; border-radius:999px; padding:4px 10px; font-size:12px; }
  .btn-brand { background:#2563eb; color:#fff!important; border:none; }
  .btn-brand:hover{ background:#1d4ed8; }
  .btn-muted { background:#e5e7eb; color:#111827!important; border:none; }
  .btn-muted:hover{ background:#d1d5db; }
  .form-label{ font-weight:600; color:#374151; }
  .form-control{ border-radius:10px; border-color:#cbd5e1; }
  .table thead th{ background:#f8fafc; color:#334155; border-bottom:1px solid #e5e7eb; }
  .table tbody td{ vertical-align:middle; }

  /* *** COLOR FIX FOR USERNAME + STATUS BADGE *** */
  .profile-cover h2,
  .profile-cover .h4 {
    color:#f9fafb !important;      /* pure white text */
  }
  .status-pill {
    display:inline-block;
    padding:4px 12px;
    border-radius:999px;
    font-size:12px;
    font-weight:500;
    background:rgba(15,23,42,.35);
    color:#f9fafb;
  }
  .status-pill.active {
    background:rgba(34,197,94,.35);  /* soft green */
    color:#ecfdf5;
  }
  .status-pill.inactive {
    background:rgba(148,163,184,.45);
    color:#e5e7eb;
  }
</style>

<div class="page-wrap">

  <!-- Profile header / hero -->
  <div class="profile-cover mb-4">
    <div class="row align-items-center gy-3">
      <div class="col-auto">
        <img id="avatarPreviewHeader"
             src="<?php echo h($avatarSrc); ?>"
             alt="Avatar"
             class="profile-avatar-lg">
      </div>
      <div class="col">
        <div class="d-flex flex-wrap align-items-center" style="gap:8px;margin-bottom:4px;">
          <h2 class="h4 mb-0 text-white"><?php echo h($me['Username']); ?></h2>
          <?php if ((int)$me['IsActive'] === 1): ?>
            <span class="status-pill active">Active user</span>
          <?php else: ?>
            <span class="status-pill inactive">Inactive</span>
          <?php endif; ?>
        </div>
        <div style="font-size:14px;color:#e5e7eb;">
          <?php echo h(isset($me['Email']) ? $me['Email'] : 'No email set'); ?>
        </div>
        <div style="font-size:12px;color:#cbd5f5;margin-top:4px;">
          <!-- Member since: <?php echo h(isset($me['CreatedAt']) ? $me['CreatedAt'] : ''); ?> -->
          <?php if (!empty($me['LastLoginAt'])): ?>
            Last login: <?php echo h($me['LastLoginAt']); ?>
          <?php endif; ?>
        </div>
      </div>
      <!-- <div class="col-auto">
        <a href="<?php echo h($self); ?>" class="btn btn-muted btn-sm">Refresh</a>
      </div> -->
    </div>
  </div>

  <?php if ($msg): ?>
    <div class="alert alert-<?php echo ($msg_type==='danger'?'danger':'success'); ?> alert-dismissible fade show" role="alert">
      <?php echo $msg; ?>
      <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
  <?php endif; ?>

  <div class="row g-4">
    <!-- Left column: profile + password -->
    <div class="col-12 col-lg-5">

      <!-- Profile form
      <div class="card section-card mb-4">
        <div class="card-body">
          <div class="d-flex justify-content-between align-items-center mb-3">
            <h5 class="mb-0">Profile info</h5>
            <span class="text-muted small">Basic details & photo</span>
          </div>

          <form method="post" enctype="multipart/form-data" accept-charset="UTF-8" class="row g-3">
            <input type="hidden" name="csrf" value="<?php echo h($CSRF); ?>">
            <input type="hidden" name="act" value="update_profile">

            <div class="col-12 text-center mb-2">
              <img id="avatarPreview"
                   src="<?php echo h($avatarSrc); ?>"
                   alt="Avatar"
                   class="profile-avatar-lg mb-2">
              <div>
                <label class="btn btn-muted btn-sm">
                  Change photo
                  <input type="file" name="avatar" id="avatarInput" accept="image/*" hidden>
                </label>
              </div>
              <div class="small text-muted mt-1">
                Any image size is fine (server limit up to ~50MB).
              </div>
            </div>

            <div class="col-12">
              <label class="form-label">Username</label>
              <input type="text" name="Username" class="form-control" required maxlength="100"
                     value="<?php echo h($me['Username']); ?>">
            </div>
            <div class="col-12">
              <label class="form-label">Email</label>
              <input type="email" name="Email" class="form-control" maxlength="200"
                     value="<?php echo h(isset($me['Email']) ? $me['Email'] : ''); ?>" placeholder="Optional">
            </div>

            <div class="col-12 d-grid">
              <button class="btn btn-brand">Save profile</button>
            </div>
          </form>
        </div>
      </div> -->

      <!-- Password form -->
      <div class="card section-card">
        <div class="card-body">
          <div class="d-flex justify-content-between align-items-center mb-3">
            <h5 class="mb-0">Security</h5>
            <span class="text-muted small">Change your password</span>
          </div>

          <form method="post" class="row g-3" autocomplete="off" accept-charset="UTF-8">
            <input type="hidden" name="csrf" value="<?php echo h($CSRF); ?>">
            <input type="hidden" name="act" value="change_password">

            <div class="col-12">
              <label class="form-label">Current password</label>
              <input type="password" name="old_password" class="form-control" required>
            </div>
            <div class="col-12">
              <label class="form-label">New password</label>
              <input type="password" name="new_password" class="form-control" required minlength="8">
            </div>
            <div class="col-12">
              <label class="form-label">Confirm new password</label>
              <input type="password" name="confirm_password" class="form-control" required minlength="8">
            </div>

            <div class="col-12 d-grid">
              <button class="btn btn-brand">Update password</button>
            </div>
          </form>
        </div>
      </div>

    </div>

    <!-- Right column: users list -->
    <!-- <div class="col-12 col-lg-7">
      <div class="card section-card h-100">
        <div class="card-body">
          <div class="d-flex align-items-center justify-content-between mb-3">
            <div>
              <h5 class="mb-0">
                <?php echo $can_view_all ? 'All users' : 'Your account details'; ?>
              </h5>
              <span class="text-muted small">
                <?php echo $can_view_all ? 'Visible users list' : 'Limited to your own record'; ?>
              </span>
            </div>
            <span class="badge-soft small">Total: <?php echo count($users); ?></span>
          </div>

          <div class="table-responsive">
            <table class="table table-hover align-middle">
              <thead>
                <tr>
                  <th>#</th>
                  <th>Username</th>
                  <th>Email</th>
                  <th>Status</th>
                  <th>Created</th>
                  <th>Created By</th>
                  <th>Last login</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($users as $u): ?>
                  <tr>
                    <td><?php echo (int)$u['UserID']; ?></td>
                    <td><?php echo h($u['Username']); ?></td>
                    <td><?php echo h(isset($u['Email']) ? $u['Email'] : ''); ?></td>
                    <td>
                      <?php if ((int)$u['IsActive'] === 1): ?>
                        <span class="badge-soft text-success">Active</span>
                      <?php else: ?>
                        <span class="badge-soft text-secondary">Inactive</span>
                      <?php endif; ?>
                    </td>
                    <td><?php echo h(isset($u['CreatedAt']) ? $u['CreatedAt'] : ''); ?></td>
                    <td><?php echo h(isset($u['CreatedBy']) ? $u['CreatedBy'] : ''); ?></td>
                    <td><?php echo h(isset($u['LastLoginAt']) ? $u['LastLoginAt'] : ''); ?></td>
                  </tr>
                <?php endforeach; ?>
                <?php if (empty($users)): ?>
                  <tr><td colspan="7" class="text-center text-muted py-4">No data</td></tr>
                <?php endif; ?>
              </tbody>
            </table>
          </div>

        </div>
      </div>
    </div> -->
  </div>

</div><!-- /.page-wrap -->

<script>
  // Avatar live preview (both header & form)
  document.addEventListener('DOMContentLoaded', function () {
    var fileInput = document.getElementById('avatarInput');
    var preview   = document.getElementById('avatarPreview');
    var previewH  = document.getElementById('avatarPreviewHeader');

    if (!fileInput || !preview) return;

    fileInput.addEventListener('change', function () {
      var file = fileInput.files && fileInput.files[0];
      if (!file) return;

      var reader = new FileReader();
      reader.onload = function (e) {
        preview.src  = e.target.result;
        if (previewH) previewH.src = e.target.result;
      };
      reader.readAsDataURL(file);
    });
  });
</script>

<?php require_once __DIR__ . '/../../include/footer.php'; ?>
