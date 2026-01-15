<?php
/**********************************************
 * Notifications - Full CRUD (raw PHP)
 * Table: dbo.Notifications
 **********************************************/
ini_set('display_errors', 1);
error_reporting(E_ALL);

/* 1) Boot */
require_once __DIR__ . '/../../init.php';
require_login();

/* 2) Helpers */
if (!function_exists('h')) {
  function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
}
function self_name(){ return strtok(basename($_SERVER['SCRIPT_NAME']), "?"); }
$self = self_name();

/* 3) CSRF */
if (!isset($_SESSION['csrf'])) {
  $_SESSION['csrf'] = function_exists('openssl_random_pseudo_bytes')
    ? bin2hex(openssl_random_pseudo_bytes(16))
    : substr(str_shuffle(md5(uniqid(mt_rand(), true))), 0, 32);
}
$CSRF = $_SESSION['csrf'];
function check_csrf(){ if (!isset($_POST['csrf']) || $_POST['csrf'] !== $_SESSION['csrf']) die('Invalid CSRF token'); }

/* 4) Reference lists (users & assets for dropdowns) */
try {
  $users = $conn->query("SELECT UserID, Username FROM dbo.Users ORDER BY Username")->fetchAll();
} catch (PDOException $e) { $users = []; }

try {
  $assets = $conn->query("SELECT AssetID, AssetTag, AssetName FROM dbo.Assets ORDER BY AssetTag")->fetchAll();
} catch (PDOException $e) { $assets = []; }

/* 5) Actions */
$msg=''; $msg_type='success';
if (isset($_GET['ok']) && $_GET['ok']==='1'){ $msg='Notification created.'; }

$editRow = null;
$edit_id = isset($_GET['edit']) ? (int)$_GET['edit'] : 0;


/* CREATE */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['act']) && $_POST['act'] === 'create') {
  check_csrf();

  $TargetUserID   = isset($_POST['TargetUserID']) && $_POST['TargetUserID'] !== '' ? (int)$_POST['TargetUserID'] : null;
  $Message        = isset($_POST['Message']) ? trim($_POST['Message']) : '';
  $RelatedAssetID = isset($_POST['RelatedAssetID']) && $_POST['RelatedAssetID'] !== '' ? (int)$_POST['RelatedAssetID'] : null;
  $IsRead         = isset($_POST['IsRead']) ? 1 : 0;
  $CreatedBy      = isset($_SESSION['auth_user']['UserID']) ? (int)$_SESSION['auth_user']['UserID'] : null;

  $errors=[];
  if ($Message === '') $errors[] = 'Message is required.';

  if (empty($errors)){
    try {
      $st = $conn->prepare("
        INSERT INTO dbo.Notifications
          (TargetUserID, Message, RelatedAssetID, IsRead, CreatedAt, CreatedBy)
        VALUES
          (:target, :msg, :asset, :isread, GETDATE(), :by_created)
      ");
      if ($TargetUserID===null) $st->bindValue(':target', null, PDO::PARAM_NULL); else $st->bindValue(':target', $TargetUserID, PDO::PARAM_INT);
      $st->bindValue(':msg', $Message, PDO::PARAM_STR);
      if ($RelatedAssetID===null) $st->bindValue(':asset', null, PDO::PARAM_NULL); else $st->bindValue(':asset', $RelatedAssetID, PDO::PARAM_INT);
      $st->bindValue(':isread', (int)$IsRead, PDO::PARAM_INT);
      if ($CreatedBy===null) $st->bindValue(':by_created', null, PDO::PARAM_NULL); else $st->bindValue(':by_created', $CreatedBy, PDO::PARAM_INT);
      $st->execute();

      header('Location: '.$self.'?ok=1'); exit;
    } catch (PDOException $e) {
      $msg = "Create failed: ".h($e->getMessage()); $msg_type='danger';
    }
  } else { $msg = implode(' ', $errors); $msg_type='danger'; }
}

/* LOAD EDIT ROW */
if ($edit_id>0){
  try{
    $st = $conn->prepare("
      SELECT n.*, u.Username AS TargetUserName, a.AssetTag, a.AssetName,
             cu.Username AS CreatedByName
        FROM dbo.Notifications n
        LEFT JOIN dbo.Users u ON u.UserID = n.TargetUserID
        LEFT JOIN dbo.Assets a ON a.AssetID = n.RelatedAssetID
        LEFT JOIN dbo.Users cu ON cu.UserID = n.CreatedBy
       WHERE n.NotificationID = :id
    ");
    $st->execute([':id'=>$edit_id]);
    $editRow = $st->fetch();
    if (!$editRow){ $msg = "Row not found for edit."; $msg_type='danger'; $edit_id=0; }
  }catch (PDOException $e){ $msg="Load edit row failed: ".h($e->getMessage()); $msg_type='danger'; }
}

/* UPDATE */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['act']) && $_POST['act'] === 'update') {
  check_csrf();

  $NotificationID = isset($_POST['NotificationID']) ? (int)$_POST['NotificationID'] : 0;
  $TargetUserID   = isset($_POST['TargetUserID']) && $_POST['TargetUserID'] !== '' ? (int)$_POST['TargetUserID'] : null;
  $Message        = isset($_POST['Message']) ? trim($_POST['Message']) : '';
  $RelatedAssetID = isset($_POST['RelatedAssetID']) && $_POST['RelatedAssetID'] !== '' ? (int)$_POST['RelatedAssetID'] : null;
  $IsRead         = isset($_POST['IsRead']) ? 1 : 0;
  $errors=[];
  if ($NotificationID<=0) $errors[]='Invalid row.';
  if ($Message==='')      $errors[]='Message is required.';

  if (empty($errors)){
    try{
      $st = $conn->prepare("
        UPDATE dbo.Notifications
           SET TargetUserID = :target,
               Message = :msg,
               RelatedAssetID = :asset,
               IsRead = :isread
         WHERE NotificationID = :id
      ");
      if ($TargetUserID===null) $st->bindValue(':target', null, PDO::PARAM_NULL); else $st->bindValue(':target', $TargetUserID, PDO::PARAM_INT);
      $st->bindValue(':msg', $Message, PDO::PARAM_STR);
      if ($RelatedAssetID===null) $st->bindValue(':asset', null, PDO::PARAM_NULL); else $st->bindValue(':asset', $RelatedAssetID, PDO::PARAM_INT);
      $st->bindValue(':isread', (int)$IsRead, PDO::PARAM_INT);
      $st->bindValue(':id', $NotificationID, PDO::PARAM_INT);
      $st->execute();

      header('Location: '.$self); exit;
    }catch(PDOException $e){
      $msg="Update failed: ".h($e->getMessage()); $msg_type='danger';
    }
  } else { $msg=implode(' ', $errors); $msg_type='danger'; }
}

/* DELETE */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['act']) && $_POST['act'] === 'delete') {
  check_csrf();
  $id = isset($_POST['NotificationID']) ? (int)$_POST['NotificationID'] : 0;

  if ($id>0){
    try{
      $conn->prepare("DELETE FROM dbo.Notifications WHERE NotificationID=:id")->execute([':id'=>$id]);
      $msg="Notification deleted."; $msg_type='success';
    }catch(PDOException $e){ $msg="Delete failed: ".h($e->getMessage()); $msg_type='danger'; }
  }
}

/* TOGGLE READ/UNREAD (quick action) */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['act']) && $_POST['act'] === 'toggle_read') {
  check_csrf();
  $id = isset($_POST['NotificationID']) ? (int)$_POST['NotificationID'] : 0;
  $to = isset($_POST['to']) ? (int)$_POST['to'] : 0;

  if ($id > 0) {
    try {
      $stmt = $conn->prepare("UPDATE dbo.Notifications SET IsRead = :r WHERE NotificationID = :id");
      $stmt->execute(array(':r' => $to, ':id' => $id));
      $msg = $to ? "Marked as read." : "Marked as unread.";
      $msg_type = 'success';
    } catch (PDOException $e) {
      $msg = "Toggle failed: " . h($e->getMessage());
      $msg_type = 'danger';
    }
  }
}


/* 6) List + Search */
$search = isset($_GET['q']) ? trim($_GET['q']) : '';

try{
  if ($search!==''){
    $st = $conn->prepare("
      SELECT n.NotificationID, n.TargetUserID, u.Username AS TargetUserName,
             n.Message, n.RelatedAssetID, a.AssetTag, a.AssetName,
             n.IsRead, n.CreatedAt, cu.Username AS CreatedByName
        FROM dbo.Notifications n
        LEFT JOIN dbo.Users u ON u.UserID = n.TargetUserID
        LEFT JOIN dbo.Assets a ON a.AssetID = n.RelatedAssetID
        LEFT JOIN dbo.Users cu ON cu.UserID = n.CreatedBy
       WHERE n.Message LIKE :q
          OR u.Username LIKE :q
          OR a.AssetTag LIKE :q
          OR a.AssetName LIKE :q
       ORDER BY n.CreatedAt DESC
    ");
    $st->execute([':q'=>'%'.$search.'%']);
    $rows = $st->fetchAll();
  } else {
    $rows = $conn->query("
      SELECT n.NotificationID, n.TargetUserID, u.Username AS TargetUserName,
             n.Message, n.RelatedAssetID, a.AssetTag, a.AssetName,
             n.IsRead, n.CreatedAt, cu.Username AS CreatedByName
        FROM dbo.Notifications n
        LEFT JOIN dbo.Users u ON u.UserID = n.TargetUserID
        LEFT JOIN dbo.Assets a ON a.AssetID = n.RelatedAssetID
        LEFT JOIN dbo.Users cu ON cu.UserID = n.CreatedBy
       ORDER BY n.CreatedAt DESC
    ")->fetchAll();
  }
}catch(PDOException $e){ $rows=[]; $msg="Load list failed: ".h($e->getMessage()); $msg_type='danger'; }

/* 7) Render */
require_once __DIR__ . '/../../include/header.php';
?>
<style>
  .page-wrap{ margin:28px auto; padding:0 12px; }
  .page-title{ font-weight:700; letter-spacing:.2px; }
  .card-elevated{ border:1px solid #e5e7eb; border-radius:12px; box-shadow:0 8px 24px rgba(2,6,23,.06); }
  .badge-soft{ border:1px solid #e2e8f0; background:#f8fafc; border-radius:999px; padding:4px 10px; font-size:12px; }
  .btn-brand{ background:#2563eb; color:#fff!important; border:none; }
  .btn-brand:hover{ background:#1d4ed8; }
  .btn-muted{ background:#e5e7eb; color:#111827!important; border:none; }
  .btn-danger-soft{ background:#fee2e2; color:#b91c1c!important; border:1px solid #fecaca; }
  .form-label{ font-weight:600; color:#374151; }
  .form-control, .form-select{ border-radius:10px; border-color:#cbd5e1; }
  .action-stack>*{ margin:4px; }
  @media(min-width:768px){ .action-stack{ display:inline-flex; gap:6px; } }
  .table thead th{ background:#f8fafc; color:#334155; border-bottom:1px solid #e5e7eb; }
  .table tbody td{ vertical-align:middle; }
</style>

<div class="page-wrap">

  <!-- Header / Search -->
  <div class="d-flex flex-wrap align-items-center justify-content-between mb-3 gap-2">
    <h1 class="page-title mb-0">Notifications</h1>

    <form method="get" class="w-100 w-md-auto" accept-charset="UTF-8">
      <div class="input-group">
        <input type="text" name="q" class="form-control" placeholder="Search message, user, asset..."
               value="<?php echo h($search); ?>">
        <button class="btn btn-brand" type="submit">Search</button>
        <a class="btn btn-muted" href="<?php echo h($self); ?>">Reset</a>
      </div>
    </form>
  </div>

  <!-- Alerts -->
  <?php if ($msg): ?>
    <div class="alert alert-<?php echo ($msg_type==='danger'?'danger':'success'); ?> alert-dismissible fade show" role="alert">
      <?php echo $msg; ?>
      <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close" style="box-shadow:none;outline:none;"></button>
    </div>
  <?php endif; ?>

  <!-- Create / Edit Card -->
  <div class="card card-elevated mb-4">
    <div class="card-body">
      <?php if (!empty($editRow)): ?>
        <div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-3">
          <h5 class="mb-0">Edit Notification</h5>
          <a class="btn btn-muted btn-sm" href="<?php echo h($self); ?>">Cancel</a>
        </div>

        <form method="post" accept-charset="UTF-8">
          <input type="hidden" name="csrf" value="<?php echo h($CSRF); ?>">
          <input type="hidden" name="act" value="update">
          <input type="hidden" name="NotificationID" value="<?php echo (int)$editRow['NotificationID']; ?>">

          <div class="row g-3">
            <div class="col-12 col-md-4">
              <label class="form-label">Target User (optional)</label>
              <select name="TargetUserID" class="form-select">
                <option value="">-- None --</option>
                <?php foreach($users as $u): ?>
                  <option value="<?php echo (int)$u['UserID']; ?>"
                    <?php echo ((int)(isset($editRow['TargetUserID']) ? $editRow['TargetUserID'] : 0) === (int)$u['UserID'] ? 'selected' : ''); ?>>

                    <?php echo h($u['Username']); ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>

            <div class="col-12 col-md-4">
              <label class="form-label">Related Asset (optional)</label>
              <select name="RelatedAssetID" class="form-select">
                <option value="">-- None --</option>
                <?php foreach($assets as $a): ?>
                  <option value="<?php echo (int)$a['AssetID']; ?>"
                    <?php echo ((int)(isset($editRow['RelatedAssetID']) ? $editRow['RelatedAssetID'] : 0) === (int)$a['AssetID'] ? 'selected' : ''); ?>>

                    <?php echo h($a['AssetTag'].' — '.$a['AssetName']); ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>

            <div class="col-12 col-md-4 d-flex align-items-end">
              <div class="form-check">
                <input class="form-check-input" type="checkbox" name="IsRead" id="isReadEdit"
                       <?php echo ((int)$editRow['IsRead']===1?'checked':''); ?>>
                <label class="form-check-label" for="isReadEdit">Read</label>
              </div>
            </div>

            <div class="col-12">
              <label class="form-label">Message</label>
              <textarea name="Message" class="form-control" rows="3" maxlength="1000" required><?php echo h($editRow['Message']); ?></textarea>
            </div>

            <div class="col-12">
              <div class="text-muted small">
                Created: <span class="badge-soft"><?php echo h($editRow['CreatedAt']); ?></span>
                by <span class="badge-soft"><?php echo h(isset($editRow['CreatedByName']) ? $editRow['CreatedByName'] : ''); ?></span>

              </div>
            </div>

            <div class="col-12 d-grid d-md-inline">
              <button class="btn btn-brand w-100 w-md-auto">Update</button>
            </div>
          </div>
        </form>

      <?php else: ?>
        <h5 class="mb-3">Add Notification</h5>
        <form method="post" class="row g-3" accept-charset="UTF-8">
          <input type="hidden" name="csrf" value="<?php echo h($CSRF); ?>">
          <input type="hidden" name="act" value="create">

          <div class="col-12 col-md-4">
            <label class="form-label">Target User (optional)</label>
            <select name="TargetUserID" class="form-select">
              <option value="">-- None --</option>
              <?php foreach($users as $u): ?>
                <option value="<?php echo (int)$u['UserID']; ?>"><?php echo h($u['Username']); ?></option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="col-12 col-md-4">
            <label class="form-label">Related Asset (optional)</label>
            <select name="RelatedAssetID" class="form-select">
              <option value="">-- None --</option>
              <?php foreach($assets as $a): ?>
                <option value="<?php echo (int)$a['AssetID']; ?>"><?php echo h($a['AssetTag'].' — '.$a['AssetName']); ?></option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="col-12 col-md-4 d-flex align-items-end">
            <div class="form-check">
              <input class="form-check-input" type="checkbox" name="IsRead" id="isReadCreate">
              <label class="form-check-label" for="isReadCreate">Read</label>
            </div>
          </div>

          <div class="col-12">
            <label class="form-label">Message</label>
            <textarea name="Message" class="form-control" rows="3" maxlength="1000" required></textarea>
          </div>

          <div class="col-12 d-grid d-md-inline">
            <button class="btn btn-brand w-100 w-md-auto">Create</button>
          </div>
        </form>
      <?php endif; ?>
    </div>
  </div>

  <!-- List -->
  <div class="card card-elevated">
    <div class="card-body">
      <div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-2">
        <h5 class="mb-0">All Notifications</h5>
        <span class="text-muted small">Total: <?php echo count($rows); ?></span>
      </div>

      <div class="table-responsive">
        <table class="table table-hover align-middle">
          <thead>
            <tr>
              <th>#</th>
              <th>To</th>
              <th>Asset</th>
              <th>Message</th>
              <th>Status</th>
              <th>Created</th>
              <th class="text-end">Actions</th>
            </tr>
          </thead>
          <tbody>
          <?php foreach($rows as $r): ?>
            <tr>
              <td><?php echo (int)$r['NotificationID']; ?></td>
              <td><?php echo h($r['TargetUserName']); ?></td>
              <td><?php echo h($r['AssetTag']); ?></td>
              <td style="max-width:420px;"><?php echo h($r['Message']); ?></td>
              <td>
                <?php if ((int)$r['IsRead']===1): ?>
                  <span class="badge-soft text-success">Read</span>
                <?php else: ?>
                  <span class="badge-soft text-secondary">Unread</span>
                <?php endif; ?>
              </td>
              <td><?php echo h($r['CreatedAt']); ?></td>
              <td class="text-end">
                <div class="action-stack">
                  <a class="btn btn-muted btn-sm w-100 w-md-auto"
                     href="<?php echo h($self); ?>?edit=<?php echo (int)$r['NotificationID']; ?>">Edit</a>

                  <!-- Toggle read/unread -->
                  <form method="post" class="d-inline" onsubmit="return confirm('Toggle read status?');" accept-charset="UTF-8">
                    <input type="hidden" name="csrf" value="<?php echo h($CSRF); ?>">
                    <input type="hidden" name="act" value="toggle_read">
                    <input type="hidden" name="NotificationID" value="<?php echo (int)$r['NotificationID']; ?>">
                    <input type="hidden" name="to" value="<?php echo ((int)$r['IsRead']===1?0:1); ?>">
                    <button class="btn btn-muted btn-sm w-100 w-md-auto" type="submit">
                      <?php echo ((int)$r['IsRead']===1?'Mark Unread':'Mark Read'); ?>
                    </button>
                  </form>

                  <!-- Delete -->
                  <form method="post" class="d-inline" onsubmit="return confirm('Delete this notification permanently?');" accept-charset="UTF-8">
                    <input type="hidden" name="csrf" value="<?php echo h($CSRF); ?>">
                    <input type="hidden" name="act" value="delete">
                    <input type="hidden" name="NotificationID" value="<?php echo (int)$r['NotificationID']; ?>">
                    <button class="btn btn-danger-soft btn-sm w-100 w-md-auto" type="submit">Delete</button>
                  </form>
                </div>
              </td>
            </tr>
          <?php endforeach; ?>
          <?php if (empty($rows)): ?>
            <tr><td colspan="7" class="text-center text-muted py-4">No data</td></tr>
          <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>

</div><!-- /.page-wrap -->

<?php require_once __DIR__ . '/../../include/footer.php'; ?>
