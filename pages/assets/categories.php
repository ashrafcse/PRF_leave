<?php
/******************************
 * Asset Categories - Full CRUD (raw PHP, PHP 5.6 compatible)
 * Table: dbo.AssetCategories
 ******************************/
ini_set('display_errors', 1);
error_reporting(E_ALL);

/* 1) Boot */
require_once __DIR__ . '/../../init.php';
require_login();

/* 2) Helpers */
if (!function_exists('h')) {
    function h($s){
        return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
    }
}
function self_name(){
    return strtok(basename($_SERVER['SCRIPT_NAME']), "?");
}
$self = self_name();

function normalize_name($s){
    $s = trim(preg_replace('/\s+/', ' ', (string)$s));
    return mb_strtolower($s, 'UTF-8');
}
function is_duplicate_pdo(PDOException $e){
    $code = $e->getCode();
    $msg  = $e->getMessage();
    return ($code === '23000') &&
           (stripos($msg,'unique')!==false ||
            stripos($msg,'duplicate')!==false ||
            stripos($msg,'uq_')!==false);
}

/* 3) CSRF */
if (!isset($_SESSION['csrf'])) {
    if (function_exists('openssl_random_pseudo_bytes')) {
        $_SESSION['csrf'] = bin2hex(openssl_random_pseudo_bytes(16));
    } else {
        $_SESSION['csrf'] = substr(str_shuffle(md5(uniqid(mt_rand(), true))), 0, 32);
    }
}
$CSRF = $_SESSION['csrf'];
function check_csrf(){
    if (!isset($_POST['csrf']) || $_POST['csrf'] !== $_SESSION['csrf']) {
        die('Invalid CSRF token');
    }
}

/* 4) Actions */
$msg      = '';
$msg_type = 'success';

if (isset($_GET['ok']) && $_GET['ok']==='1'){
    $msg      = 'Category created.';
    $msg_type = 'success';
}

$editRow = null;
$edit_id = isset($_GET['edit']) ? (int)$_GET['edit'] : 0;

/* CREATE */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['act']) && $_POST['act'] === 'create') {
    check_csrf();

    $name = isset($_POST['CategoryName']) ? trim($_POST['CategoryName']) : '';
    $desc = isset($_POST['Description'])  ? trim($_POST['Description'])  : '';

    if ($name !== ''){
        try{
            // duplicate (case-insensitive)
            $norm = normalize_name($name);
            $chk = $conn->prepare("
                SELECT 1 
                  FROM dbo.AssetCategories 
                 WHERE LOWER(LTRIM(RTRIM(CategoryName))) = :n
            ");
            $chk->execute(array(':n'=>$norm));
            if ($chk->fetchColumn()){
                $msg      = "Create failed: Category already exists.";
                $msg_type = 'danger';
            } else {
                $createdBy = isset($_SESSION['auth_user']['UserID'])
                           ? (int)$_SESSION['auth_user']['UserID']
                           : null;

                $st = $conn->prepare("
                  INSERT INTO dbo.AssetCategories (CategoryName, [Description], CreatedAt, CreatedBy)
                  VALUES (:n, :d, GETDATE(), :by)
                ");
                $st->bindValue(':n', $name, PDO::PARAM_STR);
                if ($desc==='') $st->bindValue(':d', null, PDO::PARAM_NULL);
                else            $st->bindValue(':d', $desc, PDO::PARAM_STR);

                if ($createdBy===null) $st->bindValue(':by', null, PDO::PARAM_NULL);
                else                   $st->bindValue(':by', $createdBy, PDO::PARAM_INT);

                $st->execute();
                header('Location: '.$self.'?ok=1');
                exit;
            }
        }catch(PDOException $e){
            if (is_duplicate_pdo($e)) {
                $msg = "Create failed: Category already exists.";
            } else {
                $msg = "Create failed: ".h($e->getMessage());
            }
            $msg_type='danger';
        }
    } else {
        $msg      = "Category name is required.";
        $msg_type = 'danger';
    }
}

/* PREPARE EDIT */
if ($edit_id>0){
    try{
        $st = $conn->prepare("
          SELECT c.CategoryID, c.CategoryName, c.[Description], c.CreatedAt, c.CreatedBy,
                 u.Username AS CreatedByUsername
            FROM dbo.AssetCategories c
            LEFT JOIN dbo.Users u ON u.UserID = c.CreatedBy
           WHERE c.CategoryID = :id
        ");
        $st->execute(array(':id'=>$edit_id));
        $editRow = $st->fetch();
        if (!$editRow){
            $msg      = "Row not found for edit.";
            $msg_type = 'danger';
            $edit_id  = 0;
        }
    }catch(PDOException $e){
        $msg      = "Load edit row failed: ".h($e->getMessage());
        $msg_type = 'danger';
    }
}

/* UPDATE */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['act']) && $_POST['act'] === 'update') {
    check_csrf();

    $id   = isset($_POST['CategoryID'])   ? (int)$_POST['CategoryID']   : 0;
    $name = isset($_POST['CategoryName']) ? trim($_POST['CategoryName']) : '';
    $desc = isset($_POST['Description'])  ? trim($_POST['Description'])  : '';

    if ($id>0 && $name!==''){
        try{
            $norm = normalize_name($name);
            $chk = $conn->prepare("
              SELECT 1 
                FROM dbo.AssetCategories
               WHERE LOWER(LTRIM(RTRIM(CategoryName))) = :n 
                 AND CategoryID <> :id
            ");
            $chk->execute(array(':n'=>$norm, ':id'=>$id));
            if ($chk->fetchColumn()){
                $msg      = "Update failed: Category already exists.";
                $msg_type = 'danger';
            } else {
                $st = $conn->prepare("
                  UPDATE dbo.AssetCategories
                     SET CategoryName = :n,
                         [Description] = :d
                   WHERE CategoryID = :id
                ");
                $st->bindValue(':n', $name, PDO::PARAM_STR);
                if ($desc==='') $st->bindValue(':d', null, PDO::PARAM_NULL);
                else            $st->bindValue(':d', $desc, PDO::PARAM_STR);
                $st->bindValue(':id', $id, PDO::PARAM_INT);
                $st->execute();
                header('Location: '.$self);
                exit;
            }
        }catch(PDOException $e){
            if (is_duplicate_pdo($e)) {
                $msg = "Update failed: Category already exists.";
            } else {
                $msg = "Update failed: ".h($e->getMessage());
            }
            $msg_type='danger';
        }
    } else {
        $msg      = "Invalid data.";
        $msg_type = 'danger';
    }
}

/* DELETE */
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['act']) && $_POST['act']==='delete'){
    check_csrf();
    $id = isset($_POST['CategoryID']) ? (int)$_POST['CategoryID'] : 0;
    if ($id>0){
        try{
            $st = $conn->prepare("DELETE FROM dbo.AssetCategories WHERE CategoryID = :id");
            $st->execute(array(':id'=>$id));
            $msg      = "Category deleted.";
            $msg_type = 'success';
        }catch(PDOException $e){
            $msg      = "Delete failed: ".h($e->getMessage());
            $msg_type = 'danger';
        }
    }
}

/* 5) List + search */
$search = isset($_GET['q']) ? trim($_GET['q']) : '';
try{
    if ($search!==''){
        $st = $conn->prepare("
          SELECT c.CategoryID, c.CategoryName, c.[Description], c.CreatedAt, c.CreatedBy,
                 u.Username AS CreatedByUsername
            FROM dbo.AssetCategories c
            LEFT JOIN dbo.Users u ON u.UserID = c.CreatedBy
           WHERE c.CategoryName LIKE :q OR c.[Description] LIKE :q
           ORDER BY c.CategoryName
        ");
        $st->execute(array(':q'=>'%'.$search.'%'));
        $rows = $st->fetchAll();
    } else {
        $rows = $conn->query("
          SELECT c.CategoryID, c.CategoryName, c.[Description], c.CreatedAt, c.CreatedBy,
                 u.Username AS CreatedByUsername
            FROM dbo.AssetCategories c
            LEFT JOIN dbo.Users u ON u.UserID = c.CreatedBy
           ORDER BY c.CategoryName
        ")->fetchAll();
    }
} catch(PDOException $e){
    $rows    = array();
    $msg     = "Load list failed: ".h($e->getMessage());
    $msg_type= 'danger';
}

/* 6) Render */
require_once __DIR__ . '/../../include/header.php';
?>
<!-- Bootstrap Icons (safe even if already loaded) -->
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">

<style>
  .page-wrap{ margin:24px auto; padding:0 12px; }
  .page-title{ font-weight:700; letter-spacing:.2px; display:flex; align-items:center; gap:.45rem; }
  .page-title i{ font-size:1.4rem; color:#2563eb; }

  .card-elevated{ border:1px solid #e5e7eb; border-radius:14px; box-shadow:0 8px 22px rgba(15,23,42,.06); }
  .badge-soft{ border:1px solid #e2e8f0; background:#f8fafc; border-radius:999px; padding:4px 10px; font-size:12px; }
  .btn-brand{ background:linear-gradient(135deg,#2563eb,#1d4ed8); color:#fff!important; border:none; }
  .btn-brand:hover{ filter:brightness(1.05); }
  .btn-muted{ background:#e5e7eb; color:#111827!important; border:none; }
  .btn-muted:hover{ background:#d1d5db; }
  .btn-danger-soft{ background:#fee2e2; color:#b91c1c!important; border:1px solid #fecaca; }
  .btn-danger-soft:hover{ background:#fecaca; }

  .form-label{ font-weight:600; color:#374151; display:flex; align-items:center; gap:.35rem; font-size:.9rem; }
  .form-label i{ color:#64748b; font-size:.95rem; }
  .form-control{ border-radius:10px; border-color:#cbd5e1; }

  .action-stack>*{ margin:4px; }
  @media(min-width:768px){ .action-stack{ display:inline-flex; gap:6px; } }

  .table thead th{ background:#f8fafc; color:#334155; border-bottom:1px solid #e5e7eb; font-size:.8rem; white-space:nowrap; }
  .table tbody td{ vertical-align:middle; font-size:.85rem; }

  @media (max-width:575.98px){
    .page-wrap{ margin-top:16px; }
  }
</style>

<div class="page-wrap">

  <!-- Header (same vibe as Assets/Location/Department) -->
  <div class="d-flex flex-wrap align-items-center justify-content-between mb-3">
    <h1 class="page-title mb-0">
      <i class="bi bi-tags"></i>
      <span>Asset Categories</span>
    </h1>
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
          <h5 class="mb-0">
            <i class="bi bi-pencil-square me-1 text-primary"></i>Edit Category
          </h5>
          <a class="btn btn-muted btn-sm" href="<?php echo h($self); ?>">
            <i class="bi bi-x-circle me-1"></i>Cancel
          </a>
        </div>

        <form method="post" accept-charset="UTF-8">
          <input type="hidden" name="csrf" value="<?php echo h($CSRF); ?>">
          <input type="hidden" name="act" value="update">
          <input type="hidden" name="CategoryID" value="<?php echo (int)$editRow['CategoryID']; ?>">

          <div class="row g-3">
            <div class="col-12 col-md-6">
              <label class="form-label">
                <i class="bi bi-grid"></i> Category Name
              </label>
              <input type="text" name="CategoryName" class="form-control" required maxlength="150"
                     value="<?php echo h($editRow['CategoryName']); ?>">
            </div>

            <div class="col-12 col-md-6">
              <label class="form-label">
                <i class="bi bi-card-text"></i> Description
              </label>
              <input type="text" name="Description" class="form-control" maxlength="500"
                     value="<?php echo h($editRow['Description']); ?>">
            </div>

            <div class="col-12">
              <div class="text-muted small">
                <i class="bi bi-clock-history me-1"></i>
                Created:
                <span class="badge-soft"><?php echo h($editRow['CreatedAt']); ?></span>
                by
                <span class="badge-soft">
                  <?php echo h(isset($editRow['CreatedByUsername']) ? $editRow['CreatedByUsername'] : ''); ?>
                </span>
              </div>
            </div>

            <div class="col-12 d-grid d-md-inline">
              <button class="btn btn-brand w-100 w-md-auto">
                <i class="bi bi-save2 me-1"></i>Update
              </button>
            </div>
          </div>
        </form>

      <?php else: ?>

        <div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-3">
          <h5 class="mb-0">
            <i class="bi bi-plus-circle me-1 text-success"></i>Add Category
          </h5>
        </div>

        <form method="post" class="row g-3" accept-charset="UTF-8">
          <input type="hidden" name="csrf" value="<?php echo h($CSRF); ?>">
          <input type="hidden" name="act" value="create">

          <div class="col-12 col-md-6">
            <label class="form-label">
              <i class="bi bi-grid"></i> Category Name
            </label>
            <input type="text" name="CategoryName" class="form-control" required maxlength="150"
                   placeholder="e.g. Laptop">
          </div>

          <div class="col-12 col-md-6">
            <label class="form-label">
              <i class="bi bi-card-text"></i> Description
            </label>
            <input type="text" name="Description" class="form-control" maxlength="500"
                   placeholder="Optional description">
          </div>

          <div class="col-12 d-grid d-md-inline">
            <button class="btn btn-brand w-100 w-md-auto">
              <i class="bi bi-plus-circle me-1"></i>Create
            </button>
          </div>
        </form>
      <?php endif; ?>
    </div>
  </div>

  <!-- List -->
  <div class="card card-elevated">
    <div class="card-body">

      <div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-2">
        <h5 class="mb-0">
          <i class="bi bi-list-ul me-1"></i>All Categories
        </h5>
        <span class="text-muted small">
          <i class="bi bi-collection me-1"></i>Total: <?php echo count($rows); ?>
        </span>
      </div>

      <!-- Search just above table with icon (same vibe as assets) -->
      <form method="get" class="mb-3" accept-charset="UTF-8">
        <div class="row g-2 align-items-end">

          <div class="col-12 col-md-6">
            <label class="form-label">
              <i class="bi bi-search"></i> Search
            </label>
            <div class="input-group">
              <span class="input-group-text">
                <i class="bi bi-search"></i>
              </span>
              <input type="text"
                     name="q"
                     class="form-control"
                     placeholder="Search by name or description..."
                     value="<?php echo h($search); ?>">
            </div>
          </div>

          <div class="col-12 col-md-auto">
            <label class="form-label d-none d-md-block">&nbsp;</label>
            <div>
              <button class="btn btn-brand me-1" type="submit">
                <i class="bi bi-funnel me-1"></i>Apply
              </button>
              <a class="btn btn-muted" href="<?php echo h($self); ?>">
                <i class="bi bi-arrow-clockwise"></i>
              </a>
            </div>
          </div>

        </div>
      </form>

      <div class="table-responsive">
        <table class="table table-hover align-middle">
          <thead>
            <tr>
              <th>#</th>
              <th>Name</th>
              <th>Description</th>
              <th>Created</th>
              <th>By</th>
              <th class="text-end">Actions</th>
            </tr>
          </thead>
          <tbody>
          <?php foreach($rows as $r): ?>
            <tr>
              <td><?php echo (int)$r['CategoryID']; ?></td>
              <td><?php echo h($r['CategoryName']); ?></td>
              <td><?php echo h($r['Description']); ?></td>
              <td><?php echo h($r['CreatedAt']); ?></td>
              <td><?php echo h(isset($r['CreatedByUsername']) ? $r['CreatedByUsername'] : ''); ?></td>
              <td class="text-end">
                <div class="action-stack">
                  <a class="btn btn-muted btn-sm w-100 w-md-auto"
                     href="<?php echo h($self); ?>?edit=<?php echo (int)$r['CategoryID']; ?>">
                    <i class="bi bi-pencil"></i>
                  </a>

                  <form method="post" class="d-inline"
                        onsubmit="return confirm('Delete this category permanently?');"
                        accept-charset="UTF-8">
                    <input type="hidden" name="csrf" value="<?php echo h($CSRF); ?>">
                    <input type="hidden" name="act" value="delete">
                    <input type="hidden" name="CategoryID" value="<?php echo (int)$r['CategoryID']; ?>">
                    <button class="btn btn-danger-soft btn-sm w-100 w-md-auto" type="submit">
                      <i class="bi bi-trash"></i>
                    </button>
                  </form>
                </div>
              </td>
            </tr>
          <?php endforeach; ?>

          <?php if (empty($rows)): ?>
            <tr>
              <td colspan="6" class="text-center text-muted py-4">No data</td>
            </tr>
          <?php endif; ?>

          </tbody>
        </table>
      </div>
    </div>
  </div>

</div><!-- /.page-wrap -->

<?php require_once __DIR__ . '/../../include/footer.php'; ?>
