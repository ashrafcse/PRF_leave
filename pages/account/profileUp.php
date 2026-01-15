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

/* Avatar helper – universal solution for varbinary(MAX) */
function user_avatar_src($conn, $user_id, $row = null) {
    // CHANGE THIS to your own default avatar path
    $defaultAvatar = BASE_URL . 'assets/img/avatar-default.png';
    
    // Check if we have avatar data in the row
    if ($row && isset($row['Avatar']) && !empty($row['Avatar'])) {
        try {
            // Try to get the avatar directly using a separate query
            $stmt = $conn->prepare("SELECT Avatar FROM dbo.Users WHERE UserID = :id");
            $stmt->execute(array(':id' => $user_id));
            $avatarResult = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($avatarResult && isset($avatarResult['Avatar']) && !empty($avatarResult['Avatar'])) {
                $avatarData = $avatarResult['Avatar'];
                
                // Handle resource/stream
                if (is_resource($avatarData)) {
                    $avatarData = stream_get_contents($avatarData);
                }
                
                // Convert to data URL
                if (!empty($avatarData) && is_string($avatarData)) {
                    // Detect MIME type
                    $mime = 'image/jpeg';
                    
                    // Check for common image signatures
                    $signature = substr($avatarData, 0, 4);
                    if ($signature === "\x89PNG") {
                        $mime = 'image/png';
                    } elseif (strpos($signature, "GIF") === 0) {
                        $mime = 'image/gif';
                    } elseif ($signature === "\xFF\xD8\xFF") {
                        $mime = 'image/jpeg';
                    } elseif (substr($avatarData, 0, 12) === "RIFF" && strpos($avatarData, "WEBP", 8) !== false) {
                        $mime = 'image/webp';
                    }
                    
                    // Encode to base64
                    $base64 = base64_encode($avatarData);
                    return 'data:' . $mime . ';base64,' . $base64;
                }
            }
        } catch (Exception $e) {
            // Fall through to default
            error_log("Avatar retrieval error: " . $e->getMessage());
        }
    }
    
    // Alternative: Check for separate avatar file storage
    $avatarFile = BASE_URL . 'uploads/avatars/' . $user_id . '.jpg';
    $avatarPath = $_SERVER['DOCUMENT_ROOT'] . parse_url($avatarFile, PHP_URL_PATH);
    
    // Remove any double slashes in path
    $avatarPath = str_replace('//', '/', $avatarPath);
    
    if (file_exists($avatarPath)) {
        return $avatarFile . '?t=' . filemtime($avatarPath);
    }
    
    // Fallback to default
    return $defaultAvatar;
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
    // First get user data without avatar (to avoid encoding issues)
    $st = $conn->prepare("
        SELECT TOP 1 UserID, Username, Email, IsActive,
               CreatedAt, CreatedBy, LastLoginAt, PasswordHash
          FROM dbo.Users
         WHERE UserID = :id
    ");
    $st->execute(array(':id'=>$meId));
    $me = $st->fetch(PDO::FETCH_ASSOC);
    if (!$me) die('User not found.');
} catch (PDOException $e) {
    die('Load failed: ' . h($e->getMessage()));
}

$avatarSrc = user_avatar_src($conn, $meId, $me);
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

    // Avatar upload handling
    $avatarData = null;
    $updateAvatar = false;
    $avatarError = '';

    if (isset($_FILES['avatar']) && $_FILES['avatar']['error'] !== UPLOAD_ERR_NO_FILE) {

        if ($_FILES['avatar']['error'] !== UPLOAD_ERR_OK) {
            $uploadErrors = array(
                0 => 'There is no error, the file uploaded with success',
                1 => 'The uploaded file exceeds the upload_max_filesize directive in php.ini',
                2 => 'The uploaded file exceeds the MAX_FILE_SIZE directive that was specified in the HTML form',
                3 => 'The uploaded file was only partially uploaded',
                4 => 'No file was uploaded',
                6 => 'Missing a temporary folder',
                7 => 'Failed to write file to disk.',
                8 => 'A PHP extension stopped the file upload.'
            );
            $avatarError = 'Avatar upload failed: ' . (isset($uploadErrors[$_FILES['avatar']['error']]) ? $uploadErrors[$_FILES['avatar']['error']] : 'Unknown error');
        } else {
            // Reasonable limit: 5MB for avatars
            $maxSize = 5 * 1024 * 1024;
            if ($_FILES['avatar']['size'] > $maxSize) {
                $avatarError = 'Photo too large (max 5MB).';
            } else {
                $tempFile = $_FILES['avatar']['tmp_name'];
                
                // Validate it's an image
                $imageInfo = @getimagesize($tempFile);
                if (!$imageInfo) {
                    $avatarError = 'Invalid image file. Please upload a valid image.';
                } else {
                    $allowedMimes = array('image/jpeg', 'image/png', 'image/gif', 'image/webp');
                    if (!in_array($imageInfo['mime'], $allowedMimes, true)) {
                        $avatarError = 'Invalid image type. Only JPG, PNG, GIF, and WebP allowed.';
                    } else {
                        // Read file as binary data
                        $avatarData = file_get_contents($tempFile);
                        if ($avatarData === false) {
                            $avatarError = 'Could not read uploaded file.';
                        } else {
                            $updateAvatar = true;
                        }
                    }
                }
            }
        }

        if ($avatarError) {
            $msg = $avatarError;
            $msg_type = 'danger';
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
                if ($updateAvatar && $avatarData !== null) {
                    // Option 1: Direct binary update (works with SQLSRV driver)
                    $sql = "UPDATE dbo.Users 
                               SET Username = :u,
                                   Email = :e,
                                   Avatar = CONVERT(varbinary(MAX), :a)
                             WHERE UserID = :id";
                    
                    $stmt = $conn->prepare($sql);
                    
                    // Bind parameters
                    $stmt->bindValue(':u', $username);
                    $stmt->bindValue(':e', ($email !== '' ? $email : null));
                    $stmt->bindValue(':id', $meId, PDO::PARAM_INT);
                    
                    // Bind avatar as binary using proper encoding
                    $stmt->bindValue(':a', $avatarData, PDO::PARAM_LOB);
                    
                    $stmt->execute();
                    
                } else {
                    // Update without avatar
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

                // Refresh session data
                $_SESSION['auth_user']['username'] = $username;
                $_SESSION['auth_user']['Username'] = $username;

                $msg = 'Profile updated successfully.';
                $msg_type = 'success';

                // Reload user data
                $st = $conn->prepare("
                    SELECT TOP 1 UserID, Username, Email, IsActive,
                           CreatedAt, CreatedBy, LastLoginAt, PasswordHash
                      FROM dbo.Users
                     WHERE UserID = :id
                ");
                $st->execute(array(':id'=>$meId));
                $me = $st->fetch(PDO::FETCH_ASSOC);
                $avatarSrc = user_avatar_src($conn, $meId, $me);
            }
        } catch (PDOException $e) {
            // Try alternative method if the first one fails
            if ($updateAvatar && $avatarData !== null) {
                try {
                    // Option 2: Alternative method using 0x prefix for binary
                    $hexData = bin2hex($avatarData);
                    $sql = "UPDATE dbo.Users 
                               SET Username = :u,
                                   Email = :e,
                                   Avatar = 0x" . $hexData . "
                             WHERE UserID = :id";
                    
                    $stmt = $conn->prepare("
                        UPDATE dbo.Users
                           SET Username = :u,
                               Email = :e
                         WHERE UserID = :id
                    ");
                    $stmt->execute(array(
                        ':u'  => $username,
                        ':e'  => ($email !== '' ? $email : null),
                        ':id' => $meId
                    ));
                    
                    // Then update avatar separately
                    $updateStmt = $conn->prepare("
                        UPDATE dbo.Users
                           SET Avatar = 0x" . $hexData . "
                         WHERE UserID = :id
                    ");
                    $updateStmt->execute(array(':id' => $meId));
                    
                    // Refresh session data
                    $_SESSION['auth_user']['username'] = $username;
                    $_SESSION['auth_user']['Username'] = $username;
                    
                    $msg = 'Profile updated successfully.';
                    $msg_type = 'success';
                    
                    // Reload user data
                    $st = $conn->prepare("
                        SELECT TOP 1 UserID, Username, Email, IsActive,
                               CreatedAt, CreatedBy, LastLoginAt, PasswordHash
                          FROM dbo.Users
                         WHERE UserID = :id
                    ");
                    $st->execute(array(':id'=>$meId));
                    $me = $st->fetch(PDO::FETCH_ASSOC);
                    $avatarSrc = user_avatar_src($conn, $meId, $me);
                    
                } catch (PDOException $e2) {
                    $msg = 'Update failed: ' . h($e2->getMessage());
                    $msg_type = 'danger';
                }
            } else {
                $msg = 'Update failed: ' . h($e->getMessage());
                $msg_type = 'danger';
            }
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
            // Get current hash
            $st = $conn->prepare("SELECT PasswordHash FROM dbo.Users WHERE UserID = :id");
            $st->execute(array(':id'=>$meId));
            $hashResult = $st->fetch(PDO::FETCH_ASSOC);
            $hash = isset($hashResult['PasswordHash']) ? (string)$hashResult['PasswordHash'] : '';
            
            if ($hash === '' || !password_verify($old, $hash)) {
                $msg = 'Old password is incorrect.';
                $msg_type = 'danger';
            } else {
                $newHash = password_hash($new, PASSWORD_DEFAULT);
                $st = $conn->prepare("UPDATE dbo.Users SET PasswordHash = :h WHERE UserID = :id");
                $st->execute(array(':h'=>$newHash, ':id'=>$meId));
                $msg = 'Password updated successfully.';
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
    margin-bottom: 2rem;
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
  .profile-avatar-preview{
    width:120px;
    height:120px;
    border-radius:999px;
    object-fit:cover;
    border:3px solid #e5e7eb;
    cursor:pointer;
    transition: all 0.3s ease;
  }
  .profile-avatar-preview:hover{
    transform: scale(1.05);
    border-color: #2563eb;
  }
  .page-wrap { margin:0 auto; padding:0 16px; max-width:1400px; }
  .section-card { 
    border-radius:16px; 
    border:1px solid #e2e8f0; 
    background: white;
    box-shadow:0 8px 24px rgba(15,23,42,.05);
    margin-bottom: 1.5rem;
    overflow: hidden;
  }
  .card-body {
    padding: 1.5rem;
  }
  .badge-soft { 
    border:1px solid #e2e8f0; 
    background:#f8fafc; 
    border-radius:999px; 
    padding:4px 10px; 
    font-size:12px; 
  }
  .btn-brand { 
    background:#2563eb; 
    color:#fff!important; 
    border:none; 
    border-radius: 10px;
    padding: 10px 20px;
    font-weight: 600;
    transition: all 0.3s ease;
  }
  .btn-brand:hover{ 
    background:#1d4ed8; 
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(37, 99, 235, 0.3);
  }
  .btn-muted { 
    background:#f1f5f9; 
    color:#475569!important; 
    border:1px solid #cbd5e1;
    border-radius: 10px;
    padding: 8px 16px;
    font-weight: 500;
  }
  .btn-muted:hover{ 
    background:#e2e8f0; 
    border-color: #94a3b8;
  }
  .form-label{ 
    font-weight:600; 
    color:#1e293b; 
    margin-bottom: 0.5rem;
    display: block;
  }
  .form-control{ 
    border-radius:10px; 
    border:2px solid #cbd5e1;
    padding: 10px 14px;
    transition: all 0.3s ease;
  }
  .form-control:focus {
    border-color: #2563eb;
    box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.1);
    outline: none;
  }
  .table {
    width: 100%;
    border-collapse: separate;
    border-spacing: 0;
  }
  .table thead th{ 
    background:#f8fafc; 
    color:#334155; 
    border-bottom:2px solid #e2e8f0;
    padding: 12px 16px;
    font-weight: 600;
    font-size: 0.875rem;
    text-transform: uppercase;
    letter-spacing: 0.05em;
  }
  .table tbody td{ 
    vertical-align:middle;
    padding: 12px 16px;
    border-bottom: 1px solid #f1f5f9;
  }
  .table tbody tr:hover {
    background-color: #f8fafc;
  }

  /* *** COLOR FIX FOR USERNAME + STATUS BADGE *** */
  .profile-cover h2,
  .profile-cover .h4 {
    color:#f9fafb !important;
    font-weight: 700;
  }
  .status-pill {
    display:inline-block;
    padding:6px 14px;
    border-radius:999px;
    font-size:12px;
    font-weight:600;
    background:rgba(15,23,42,.35);
    color:#f9fafb;
    text-transform: uppercase;
    letter-spacing: 0.05em;
  }
  .status-pill.active {
    background:rgba(34,197,94,.35);
    color:#ecfdf5;
  }
  .status-pill.inactive {
    background:rgba(148,163,184,.45);
    color:#e5e7eb;
  }
  
  /* Modern file upload */
  .avatar-upload-wrapper {
    position: relative;
    display: inline-block;
  }
  .avatar-upload-overlay {
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    background: rgba(0, 0, 0, 0.5);
    border-radius: 999px;
    display: flex;
    align-items: center;
    justify-content: center;
    opacity: 0;
    transition: opacity 0.3s ease;
    cursor: pointer;
  }
  .avatar-upload-wrapper:hover .avatar-upload-overlay {
    opacity: 1;
  }
  .avatar-upload-overlay i {
    color: white;
    font-size: 24px;
  }
  
  /* Alert styling */
  .alert {
    border-radius: 10px;
    padding: 1rem 1.25rem;
    margin-bottom: 1.5rem;
    border: 1px solid transparent;
  }
  .alert-success {
    background-color: #d1fae5;
    border-color: #a7f3d0;
    color: #065f46;
  }
  .alert-danger {
    background-color: #fee2e2;
    border-color: #fecaca;
    color: #991b1b;
  }
  
  /* Responsive design */
  @media (max-width: 768px) {
    .profile-cover {
      padding: 24px 16px;
      text-align: center;
    }
    .profile-avatar-lg {
      width: 100px;
      height: 100px;
    }
    .section-card {
      margin-bottom: 1rem;
    }
    .card-body {
      padding: 1.25rem;
    }
  }
</style>

<div class="page-wrap">

  <!-- Profile header / hero -->
  <div class="profile-cover">
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
          Member since: <?php echo h(isset($me['CreatedAt']) ? $me['CreatedAt'] : ''); ?>
          <?php if (!empty($me['LastLoginAt'])): ?>
            · Last login: <?php echo h($me['LastLoginAt']); ?>
          <?php endif; ?>
        </div>
      </div>
      <div class="col-auto">
        <a href="<?php echo h($self); ?>" class="btn btn-muted btn-sm">Refresh</a>
      </div>
    </div>
  </div>

  <?php if ($msg): ?>
    <div class="alert alert-<?php echo ($msg_type==='danger'?'danger':'success'); ?>">
      <?php echo $msg; ?>
    </div>
  <?php endif; ?>

  <div class="row g-4">
    <!-- Left column: profile + password -->
    <div class="col-12 col-lg-5">

      <!-- Profile form -->
      <div class="card section-card">
        <div class="card-body">
          <div class="d-flex justify-content-between align-items-center mb-3">
            <h5 class="mb-0">Profile info</h5>
            <span class="text-muted small">Basic details & photo</span>
          </div>

          <form method="post" enctype="multipart/form-data" accept-charset="UTF-8" class="row g-3">
            <input type="hidden" name="csrf" value="<?php echo h($CSRF); ?>">
            <input type="hidden" name="act" value="update_profile">

            <div class="col-12 text-center mb-4">
              <div class="avatar-upload-wrapper">
                <img id="avatarPreview"
                     src="<?php echo h($avatarSrc); ?>"
                     alt="Avatar"
                     class="profile-avatar-preview mb-2">
                <label class="avatar-upload-overlay" for="avatarInput">
                  <i class="fas fa-camera"></i>
                </label>
              </div>
              <div>
                <label class="btn btn-muted btn-sm mt-2">
                  <i class="fas fa-upload me-1"></i> Upload new photo
                  <input type="file" name="avatar" id="avatarInput" accept="image/jpeg,image/png,image/gif,image/webp" hidden>
                </label>
              </div>
              <div class="small text-muted mt-2">
                JPG, PNG, GIF or WebP (max 5MB)
              </div>
            </div>

           <div class="col-12">
  <label class="form-label">Username</label>
  <input type="text"
         name="Username"
         class="form-control"
         value="<?php echo h($me['Username']); ?>"
         readonly
         inputmode="numeric"
         pattern="[0-9]+"
         title="Username must contain digits only">
</div>

<div class="col-12">
  <label class="form-label">Email</label>
  <input type="email"
         name="Email"
         class="form-control"
         maxlength="200"
         value="<?php echo h(isset($me['Email']) ? $me['Email'] : ''); ?>"
         placeholder="Optional">
</div>


            <div class="col-12 d-grid">
              <button class="btn btn-brand" type="submit">
                <i class="fas fa-save me-1"></i> Save profile
              </button>
            </div>
          </form>
        </div>
      </div>

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
              <button class="btn btn-brand" type="submit">
                <i class="fas fa-key me-1"></i> Update password
              </button>
            </div>
          </form>
        </div>
      </div>

    </div>

    <!-- Right column: users list -->
    <div class="col-12 col-lg-7">
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
                    <td>
                      <strong><?php echo h($u['Username']); ?></strong>
                      <?php if ($u['UserID'] == $meId): ?>
                        <span class="badge-soft ms-1">You</span>
                      <?php endif; ?>
                    </td>
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
    </div>
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

      // Validate file size (5MB limit)
      if (file.size > 5 * 1024 * 1024) {
        alert('File is too large! Maximum size is 5MB.');
        fileInput.value = '';
        return;
      }

      // Validate file type
      var validTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
      if (!validTypes.includes(file.type)) {
        alert('Invalid file type! Please upload a JPG, PNG, GIF, or WebP image.');
        fileInput.value = '';
        return;
      }

      var reader = new FileReader();
      reader.onload = function (e) {
        preview.src  = e.target.result;
        if (previewH) previewH.src = e.target.result;
      };
      reader.readAsDataURL(file);
    });
    
    // Click on preview image to trigger file input
    preview.addEventListener('click', function() {
      fileInput.click();
    });
  });
</script>

<?php require_once __DIR__ . '/../../include/footer.php'; ?>