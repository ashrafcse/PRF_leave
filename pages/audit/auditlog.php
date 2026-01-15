<?php
/**********************************************
 * Audit Log - Full CRUD (raw PHP)
 * Table: dbo.AuditLog
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

/* Convert HTML datetime-local -> SQL Server friendly */
function to_sql_datetime($s) {
  $s = trim((string)$s);
  if ($s === '') return null;
  $s = str_replace('T', ' ', $s);
  try { $dt = new DateTime($s); return $dt->format('Y-m-d H:i:s'); }
  catch (Throwable $e) { return null; }
}

/* 3) CSRF */
if (!isset($_SESSION['csrf'])) {
  $_SESSION['csrf'] = function_exists('openssl_random_pseudo_bytes')
    ? bin2hex(openssl_random_pseudo_bytes(16))
    : substr(str_shuffle(md5(uniqid(mt_rand(), true))), 0, 32);
}
$CSRF = $_SESSION['csrf'];
function check_csrf(){ if (!isset($_POST['csrf']) || $_POST['csrf'] !== $_SESSION['csrf']) die('Invalid CSRF token'); }

/* 4) Reference: users for dropdown */
try {
  $users = $conn->query("SELECT UserID, Username FROM dbo.Users ORDER BY Username")->fetchAll();
} catch (PDOException $e) { $users = []; }

/* 5) Actions */
$msg=''; $msg_type='success';
if (isset($_GET['ok']) && $_GET['ok']==='1'){ $msg='Audit entry created.'; }

$editRow = null;
$edit_id = isset($_GET['edit']) ? (int)$_GET['edit'] : 0;


/* CREATE */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (isset($_POST['act']) ? $_POST['act'] : '') === 'create') {
    check_csrf();

    $TableName         = isset($_POST['TableName']) ? trim($_POST['TableName']) : '';
    $RecordID          = isset($_POST['RecordID']) ? trim($_POST['RecordID']) : '';
    $ColumnName        = isset($_POST['ColumnName']) ? trim($_POST['ColumnName']) : '';
    $OldValue          = isset($_POST['OldValue']) ? trim($_POST['OldValue']) : '';
    $NewValue          = isset($_POST['NewValue']) ? trim($_POST['NewValue']) : '';
    $PerformedByUserID = (isset($_POST['PerformedByUserID']) && $_POST['PerformedByUserID'] !== '') ? (int)$_POST['PerformedByUserID'] : null;
    $PerformedAt       = isset($_POST['PerformedAt']) ? to_sql_datetime($_POST['PerformedAt']) : null; // NULL if empty
    $Action            = isset($_POST['Action']) ? trim($_POST['Action']) : '';

  $errors=[];
  if ($TableName==='') $errors[]='Table name is required.';
  if ($Action==='')    $errors[]='Action is required.';

  if (empty($errors)){
    try{
      $st = $conn->prepare("
        INSERT INTO dbo.AuditLog
          (TableName, RecordID, ColumnName, OldValue, NewValue,
           PerformedByUserID, PerformedAt, Action)
        VALUES
          (:t, :rid, :col, :old, :new, :by, :at, :act)
      ");
      $st->bindValue(':t', $TableName, PDO::PARAM_STR);
      $st->bindValue(':rid', ($RecordID!==''?$RecordID:null), $RecordID!==''?PDO::PARAM_STR:PDO::PARAM_NULL);
      $st->bindValue(':col', ($ColumnName!==''?$ColumnName:null), $ColumnName!==''?PDO::PARAM_STR:PDO::PARAM_NULL);
      $st->bindValue(':old', ($OldValue!==''?$OldValue:null), $OldValue!==''?PDO::PARAM_STR:PDO::PARAM_NULL);
      $st->bindValue(':new', ($NewValue!==''?$NewValue:null), $NewValue!==''?PDO::PARAM_STR:PDO::PARAM_NULL);
      if ($PerformedByUserID===null) $st->bindValue(':by', null, PDO::PARAM_NULL); else $st->bindValue(':by', $PerformedByUserID, PDO::PARAM_INT);
      $st->bindValue(':at', $PerformedAt, $PerformedAt===null?PDO::PARAM_NULL:PDO::PARAM_STR); // NULL -> default GETDATE()
      $st->bindValue(':act', $Action, PDO::PARAM_STR);
      $st->execute();

      header('Location: '.$self.'?ok=1'); exit;
    }catch(PDOException $e){
      $msg="Create failed: ".h($e->getMessage()); $msg_type='danger';
    }
  } else { $msg=implode(' ', $errors); $msg_type='danger'; }
}

/* LOAD EDIT ROW */
if ($edit_id>0){
  try{
    $st = $conn->prepare("
      SELECT al.*, u.Username AS PerformedByName
        FROM dbo.AuditLog al
        LEFT JOIN dbo.Users u ON u.UserID = al.PerformedByUserID
       WHERE al.AuditID = :id
    ");
    $st->execute([':id'=>$edit_id]);
    $editRow = $st->fetch();
    if (!$editRow){ $msg="Row not found for edit."; $msg_type='danger'; $edit_id=0; }
  }catch(PDOException $e){ $msg="Load edit row failed: ".h($e->getMessage()); $msg_type='danger'; }
}

/* UPDATE (rare for audit, but provided per your pattern) */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (isset($_POST['act']) ? $_POST['act'] : '') === 'update') {
    check_csrf();

    $AuditID           = isset($_POST['AuditID']) ? (int)$_POST['AuditID'] : 0;
    $TableName         = isset($_POST['TableName']) ? trim($_POST['TableName']) : '';
    $RecordID          = isset($_POST['RecordID']) ? trim($_POST['RecordID']) : '';
    $ColumnName        = isset($_POST['ColumnName']) ? trim($_POST['ColumnName']) : '';
    $OldValue          = isset($_POST['OldValue']) ? trim($_POST['OldValue']) : '';
    $NewValue          = isset($_POST['NewValue']) ? trim($_POST['NewValue']) : '';
    $PerformedByUserID = (isset($_POST['PerformedByUserID']) && $_POST['PerformedByUserID'] !== '') ? (int)$_POST['PerformedByUserID'] : null;
    $PerformedAt       = isset($_POST['PerformedAt']) ? to_sql_datetime($_POST['PerformedAt']) : null;
    $Action            = isset($_POST['Action']) ? trim($_POST['Action']) : '';

  $errors=[];
  if ($AuditID<=0)    $errors[]='Invalid row.';
  if ($TableName==='') $errors[]='Table name is required.';
  if ($Action==='')    $errors[]='Action is required.';

  if (empty($errors)){
    try{
      $st = $conn->prepare("
        UPDATE dbo.AuditLog
           SET TableName = :t,
               RecordID = :rid,
               ColumnName = :col,
               OldValue = :old,
               NewValue = :new,
               PerformedByUserID = :by,
               PerformedAt = :at,
               Action = :act
         WHERE AuditID = :id
      ");
      $st->bindValue(':t', $TableName, PDO::PARAM_STR);
      $st->bindValue(':rid', ($RecordID!==''?$RecordID:null), $RecordID!==''?PDO::PARAM_STR:PDO::PARAM_NULL);
      $st->bindValue(':col', ($ColumnName!==''?$ColumnName:null), $ColumnName!==''?PDO::PARAM_STR:PDO::PARAM_NULL);
      $st->bindValue(':old', ($OldValue!==''?$OldValue:null), $OldValue!==''?PDO::PARAM_STR:PDO::PARAM_NULL);
      $st->bindValue(':new', ($NewValue!==''?$NewValue:null), $NewValue!==''?PDO::PARAM_STR:PDO::PARAM_NULL);
      if ($PerformedByUserID===null) $st->bindValue(':by', null, PDO::PARAM_NULL); else $st->bindValue(':by', $PerformedByUserID, PDO::PARAM_INT);
      $st->bindValue(':at', $PerformedAt, $PerformedAt===null?PDO::PARAM_NULL:PDO::PARAM_STR);
      $st->bindValue(':act', $Action, PDO::PARAM_STR);
      $st->bindValue(':id', $AuditID, PDO::PARAM_INT);
      $st->execute();

      header('Location: '.$self); exit;
    }catch(PDOException $e){
      $msg="Update failed: ".h($e->getMessage()); $msg_type='danger';
    }
  } else { $msg=implode(' ', $errors); $msg_type='danger'; }
}

/* DELETE (use with care) */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (isset($_POST['act']) ? $_POST['act'] : '') === 'delete') {
    check_csrf();
    $id = isset($_POST['AuditID']) ? (int)$_POST['AuditID'] : 0;
  if ($id>0){
    try{
      $conn->prepare("DELETE FROM dbo.AuditLog WHERE AuditID=:id")->execute([':id'=>$id]);
      $msg="Audit entry deleted."; $msg_type='success';
    }catch(PDOException $e){ $msg="Delete failed: ".h($e->getMessage()); $msg_type='danger'; }
  }
}

/* 6) List + Search */
$search = isset($_GET['q']) ? trim($_GET['q']) : '';

try{
  if ($search!==''){
    $st = $conn->prepare("
      SELECT al.AuditID, al.TableName, al.RecordID, al.ColumnName,
             al.OldValue, al.NewValue, al.PerformedByUserID, u.Username AS PerformedByName,
             al.PerformedAt, al.Action
        FROM dbo.AuditLog al
        LEFT JOIN dbo.Users u ON u.UserID = al.PerformedByUserID
       WHERE al.TableName LIKE :q
          OR al.RecordID LIKE :q
          OR al.ColumnName LIKE :q
          OR al.Action LIKE :q
       ORDER BY al.PerformedAt DESC, al.AuditID DESC
    ");
    $st->execute([':q'=>'%'.$search.'%']);
    $rows = $st->fetchAll();
  } else {
    $rows = $conn->query("
      SELECT al.AuditID, al.TableName, al.RecordID, al.ColumnName,
             al.OldValue, al.NewValue, al.PerformedByUserID, u.Username AS PerformedByName,
             al.PerformedAt, al.Action
        FROM dbo.AuditLog al
        LEFT JOIN dbo.Users u ON u.UserID = al.PerformedByUserID
       ORDER BY al.PerformedAt DESC, al.AuditID DESC
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
  .form-control, .form-select, textarea{ border-radius:10px; border-color:#cbd5e1; }
  .action-stack>*{ margin:4px; }
  @media(min-width:768px){ .action-stack{ display:inline-flex; gap:6px; } }
  .table thead th{ background:#f8fafc; color:#334155; border-bottom:1px solid #e5e7eb; }
  .table tbody td{ vertical-align:middle; }
  .truncate{ max-width:380px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
</style>

<div class="page-wrap">

  <!-- Header / Search -->
  <div class="d-flex flex-wrap align-items-center justify-content-between mb-3 gap-2">
    <h1 class="page-title mb-0">Audit Log</h1>

    <form method="get" class="w-100 w-md-auto" accept-charset="UTF-8">
      <div class="input-group">
        <input type="text" name="q" class="form-control" placeholder="Search table, record, column, action..."
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
          <h5 class="mb-0">Edit Audit Entry</h5>
          <a class="btn btn-muted btn-sm" href="<?php echo h($self); ?>">Cancel</a>
        </div>

        <form method="post" accept-charset="UTF-8">
          <input type="hidden" name="csrf" value="<?php echo h($CSRF); ?>">
          <input type="hidden" name="act" value="update">
          <input type="hidden" name="AuditID" value="<?php echo (int)$editRow['AuditID']; ?>">

          <div class="row g-3">
            <div class="col-12 col-md-4">
              <label class="form-label">Table Name</label>
              <input type="text" name="TableName" class="form-control" maxlength="128" required
                     value="<?php echo h($editRow['TableName']); ?>">
            </div>
            <div class="col-12 col-md-4">
              <label class="form-label">Record ID</label>
              <input type="text" name="RecordID" class="form-control" maxlength="100"
                     value="<?php echo h($editRow['RecordID']); ?>">
            </div>
            <div class="col-12 col-md-4">
              <label class="form-label">Column</label>
              <input type="text" name="ColumnName" class="form-control" maxlength="128"
                     value="<?php echo h($editRow['ColumnName']); ?>">
            </div>

            <div class="col-12">
              <label class="form-label">Old Value</label>
              <textarea name="OldValue" class="form-control" rows="2"><?php echo h($editRow['OldValue']); ?></textarea>
            </div>
            <div class="col-12">
              <label class="form-label">New Value</label>
              <textarea name="NewValue" class="form-control" rows="2"><?php echo h($editRow['NewValue']); ?></textarea>
            </div>

            <div class="col-12 col-md-4">
              <label class="form-label">Performed By (User)</label>
              <select name="PerformedByUserID" class="form-select">
                <option value="">-- None --</option>
                <?php foreach($users as $u): ?>
                  <option value="<?php echo (int)$u['UserID']; ?>"
                    <?php echo ((int)(isset($editRow['PerformedByUserID']) ? $editRow['PerformedByUserID'] : 0) === (int)$u['UserID'] ? 'selected' : ''); ?>

                    <?php echo h($u['Username']); ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>

            <div class="col-12 col-md-4">
              <label class="form-label">Performed At</label>
              <input type="datetime-local" name="PerformedAt" class="form-control"
                     value="<?php echo ($editRow['PerformedAt']?date('Y-m-d\TH:i', strtotime($editRow['PerformedAt'])):''); ?>">
            </div>

            <div class="col-12 col-md-4">
              <label class="form-label">Action</label>
              <input type="text" name="Action" class="form-control" maxlength="50" required
                     value="<?php echo h($editRow['Action']); ?>" placeholder="INSERT/UPDATE/DELETE">
            </div>

            <div class="col-12 d-grid d-md-inline">
              <button class="btn btn-brand w-100 w-md-auto">Update</button>
            </div>
          </div>
        </form>

      <?php else: ?>
        <h5 class="mb-3">Add Audit Entry (manual)</h5>
        <form method="post" class="row g-3" accept-charset="UTF-8">
          <input type="hidden" name="csrf" value="<?php echo h($CSRF); ?>">
          <input type="hidden" name="act" value="create">

          <div class="col-12 col-md-4">
            <label class="form-label">Table Name</label>
            <input type="text" name="TableName" class="form-control" maxlength="128" required placeholder="dbo.Assets">
          </div>
          <div class="col-12 col-md-4">
            <label class="form-label">Record ID</label>
            <input type="text" name="RecordID" class="form-control" maxlength="100" placeholder="e.g. 123">
          </div>
          <div class="col-12 col-md-4">
            <label class="form-label">Column</label>
            <input type="text" name="ColumnName" class="form-control" maxlength="128" placeholder="AssetName">
          </div>

          <div class="col-12">
            <label class="form-label">Old Value</label>
            <textarea name="OldValue" class="form-control" rows="2"></textarea>
          </div>
          <div class="col-12">
            <label class="form-label">New Value</label>
            <textarea name="NewValue" class="form-control" rows="2"></textarea>
          </div>

          <div class="col-12 col-md-4">
            <label class="form-label">Performed By (User)</label>
            <select name="PerformedByUserID" class="form-select">
              <option value="">-- None --</option>
              <?php foreach($users as $u): ?>
                <option value="<?php echo (int)$u['UserID']; ?>"><?php echo h($u['Username']); ?></option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="col-12 col-md-4">
            <label class="form-label">Performed At</label>
            <input type="datetime-local" name="PerformedAt" class="form-control" placeholder="optional (default now)">
          </div>

          <div class="col-12 col-md-4">
            <label class="form-label">Action</label>
            <input type="text" name="Action" class="form-control" maxlength="50" required placeholder="INSERT/UPDATE/DELETE">
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
        <h5 class="mb-0">All Audit Entries</h5>
        <span class="text-muted small">Total: <?php echo count($rows); ?></span>
      </div>

      <div class="table-responsive">
        <table class="table table-hover align-middle">
          <thead>
            <tr>
              <th>#</th>
              <th>Table</th>
              <th>Record</th>
              <th>Column</th>
              <th>Old</th>
              <th>New</th>
              <th>Action</th>
              <th>By</th>
              <th>At</th>
              <th class="text-end">Actions</th>
            </tr>
          </thead>
          <tbody>
          <?php foreach($rows as $r): ?>
            <tr>
              <td><?php echo (int)$r['AuditID']; ?></td>
              <td><?php echo h($r['TableName']); ?></td>
              <td><?php echo h($r['RecordID']); ?></td>
              <td><?php echo h($r['ColumnName']); ?></td>
              <td class="truncate" title="<?php echo h($r['OldValue']); ?>"><?php echo h($r['OldValue']); ?></td>
              <td class="truncate" title="<?php echo h($r['NewValue']); ?>"><?php echo h($r['NewValue']); ?></td>
              <td><?php echo h($r['Action']); ?></td>
              <td><?php echo h($r['PerformedByName']); ?></td>
              <td><?php echo h($r['PerformedAt']); ?></td>
              <td class="text-end">
                <div class="action-stack">
                  <a class="btn btn-muted btn-sm w-100 w-md-auto"
                     href="<?php echo h($self); ?>?edit=<?php echo (int)$r['AuditID']; ?>">Edit</a>

                  <form method="post" class="d-inline" onsubmit="return confirm('Delete this audit entry permanently?');" accept-charset="UTF-8">
                    <input type="hidden" name="csrf" value="<?php echo h($CSRF); ?>">
                    <input type="hidden" name="act" value="delete">
                    <input type="hidden" name="AuditID" value="<?php echo (int)$r['AuditID']; ?>">
                    <button class="btn btn-danger-soft btn-sm w-100 w-md-auto" type="submit">Delete</button>
                  </form>
                </div>
              </td>
            </tr>
          <?php endforeach; ?>
          <?php if (empty($rows)): ?>
            <tr><td colspan="10" class="text-center text-muted py-4">No data</td></tr>
          <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>

</div><!-- /.page-wrap -->

<?php require_once __DIR__ . '/../../include/footer.php'; ?>
