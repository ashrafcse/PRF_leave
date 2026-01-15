<?php
/******************************
 * Asset Models - Full CRUD (raw PHP, PHP 5.6 compatible)
 * Table: dbo.AssetModels
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

function normalize_str($s){
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

/* 4) Reference lists (for dropdowns) */
try {
    $categories = $conn->query("
        SELECT CategoryID, CategoryName 
          FROM dbo.AssetCategories 
         ORDER BY CategoryName
    ")->fetchAll();
} catch (PDOException $e){
    $categories = array();
}

try {
    $vendors = $conn->query("
        SELECT VendorID, VendorName 
          FROM dbo.AssetVendor 
         ORDER BY VendorName
    ")->fetchAll();
} catch (PDOException $e){
    $vendors = array();
}

/* 5) Actions */
$msg      = '';
$msg_type = 'success';

if (isset($_GET['ok']) && $_GET['ok'] === '1') {
    $msg      = 'Model created.';
    $msg_type = 'success';
}

$editRow = null;
$edit_id = isset($_GET['edit']) ? (int)$_GET['edit'] : 0;

/* CREATE */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['act']) && $_POST['act'] === 'create') {
    check_csrf();

    $CategoryID     = isset($_POST['CategoryID']) ? (int)$_POST['CategoryID'] : 0;
    $Manufacturer   = isset($_POST['Manufacturer']) ? trim($_POST['Manufacturer']) : '';
    $ModelName      = isset($_POST['ModelName']) ? trim($_POST['ModelName']) : '';
    $Spec           = isset($_POST['Spec']) ? trim($_POST['Spec']) : '';
    $WarrantyMonths = (isset($_POST['WarrantyMonths']) && $_POST['WarrantyMonths'] !== '') ? (int)$_POST['WarrantyMonths'] : null;
    $VendorID       = (isset($_POST['VendorID']) && $_POST['VendorID'] !== '') ? (int)$_POST['VendorID'] : null;

    $errors = array();
    if ($CategoryID <= 0) {
        $errors[] = 'Category is required.';
    }
    if ($ModelName === '' && $Manufacturer === '') {
        $errors[] = 'Provide Model Name or Manufacturer.';
    }

    if (empty($errors)) {
        try {
            // Duplicate check: same Category + ModelName + Manufacturer
            $chk = $conn->prepare("
                SELECT 1 
                  FROM dbo.AssetModels
                 WHERE CategoryID = :cat
                   AND ISNULL(LOWER(LTRIM(RTRIM(ModelName))),'')      = :mn
                   AND ISNULL(LOWER(LTRIM(RTRIM(Manufacturer))),'')   = :mf
            ");
            $chk->execute(array(
                ':cat' => $CategoryID,
                ':mn'  => normalize_str($ModelName),
                ':mf'  => normalize_str($Manufacturer)
            ));
            if ($chk->fetchColumn()) {
                $msg      = "Create failed: Model already exists in this category.";
                $msg_type = 'danger';
            } else {
                $createdBy = isset($_SESSION['auth_user']['UserID'])
                           ? (int)$_SESSION['auth_user']['UserID']
                           : null;

                $st = $conn->prepare("
                    INSERT INTO dbo.AssetModels
                      (CategoryID, Manufacturer, ModelName, Spec, WarrantyMonths, VendorID, CreatedAt, CreatedBy)
                    VALUES
                      (:cat, :manu, :name, :spec, :war, :vendor, GETDATE(), :by)
                ");
                $st->bindValue(':cat', $CategoryID, PDO::PARAM_INT);
                $st->bindValue(':manu', ($Manufacturer !== '' ? $Manufacturer : null), ($Manufacturer !== '' ? PDO::PARAM_STR : PDO::PARAM_NULL));
                $st->bindValue(':name', ($ModelName !== '' ? $ModelName : null), ($ModelName !== '' ? PDO::PARAM_STR : PDO::PARAM_NULL));
                $st->bindValue(':spec', ($Spec !== '' ? $Spec : null), ($Spec !== '' ? PDO::PARAM_STR : PDO::PARAM_NULL));

                if ($WarrantyMonths === null) {
                    $st->bindValue(':war', null, PDO::PARAM_NULL);
                } else {
                    $st->bindValue(':war', $WarrantyMonths, PDO::PARAM_INT);
                }

                if ($VendorID === null) {
                    $st->bindValue(':vendor', null, PDO::PARAM_NULL);
                } else {
                    $st->bindValue(':vendor', $VendorID, PDO::PARAM_INT);
                }

                if ($createdBy === null) {
                    $st->bindValue(':by', null, PDO::PARAM_NULL);
                } else {
                    $st->bindValue(':by', $createdBy, PDO::PARAM_INT);
                }

                $st->execute();
                header('Location: '.$self.'?ok=1');
                exit;
            }
        } catch (PDOException $e) {
            if (is_duplicate_pdo($e)) {
                $msg = "Create failed: Duplicate model.";
            } else {
                $msg = "Create failed: " . h($e->getMessage());
            }
            $msg_type = 'danger';
        }
    } else {
        $msg      = implode(' ', $errors);
        $msg_type = 'danger';
    }
}

/* PREPARE EDIT */
if ($edit_id > 0) {
    try {
        $st = $conn->prepare("
          SELECT m.ModelID, m.CategoryID, m.Manufacturer, m.ModelName, m.Spec,
                 m.WarrantyMonths, m.VendorID, m.CreatedAt, m.CreatedBy,
                 c.CategoryName, v.VendorName, u.Username AS CreatedByUsername
            FROM dbo.AssetModels m
            JOIN dbo.AssetCategories c ON c.CategoryID = m.CategoryID
            LEFT JOIN dbo.AssetVendor v ON v.VendorID = m.VendorID
            LEFT JOIN dbo.Users u ON u.UserID = m.CreatedBy
           WHERE m.ModelID = :id
        ");
        $st->execute(array(':id' => $edit_id));
        $editRow = $st->fetch();
        if (!$editRow) {
            $msg      = "Row not found for edit.";
            $msg_type = 'danger';
            $edit_id  = 0;
        }
    } catch (PDOException $e) {
        $msg      = "Load edit row failed: " . h($e->getMessage());
        $msg_type = 'danger';
    }
}

/* UPDATE */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['act']) && $_POST['act'] === 'update') {
    check_csrf();

    $ModelID        = isset($_POST['ModelID']) ? (int)$_POST['ModelID'] : 0;
    $CategoryID     = isset($_POST['CategoryID']) ? (int)$_POST['CategoryID'] : 0;
    $Manufacturer   = isset($_POST['Manufacturer']) ? trim($_POST['Manufacturer']) : '';
    $ModelName      = isset($_POST['ModelName']) ? trim($_POST['ModelName']) : '';
    $Spec           = isset($_POST['Spec']) ? trim($_POST['Spec']) : '';
    $WarrantyMonths = (isset($_POST['WarrantyMonths']) && $_POST['WarrantyMonths'] !== '') ? (int)$_POST['WarrantyMonths'] : null;
    $VendorID       = (isset($_POST['VendorID']) && $_POST['VendorID'] !== '') ? (int)$_POST['VendorID'] : null;

    $errors = array();
    if ($ModelID <= 0) {
        $errors[] = 'Invalid model.';
    }
    if ($CategoryID <= 0) {
        $errors[] = 'Category is required.';
    }
    if ($ModelName === '' && $Manufacturer === '') {
        $errors[] = 'Provide Model Name or Manufacturer.';
    }

    if (empty($errors)) {
        try {
            $chk = $conn->prepare("
                SELECT 1 
                  FROM dbo.AssetModels
                 WHERE CategoryID = :cat
                   AND ISNULL(LOWER(LTRIM(RTRIM(ModelName))),'')      = :mn
                   AND ISNULL(LOWER(LTRIM(RTRIM(Manufacturer))),'')   = :mf
                   AND ModelID <> :id
            ");
            $chk->execute(array(
                ':cat' => $CategoryID,
                ':mn'  => normalize_str($ModelName),
                ':mf'  => normalize_str($Manufacturer),
                ':id'  => $ModelID
            ));
            if ($chk->fetchColumn()) {
                $msg      = "Update failed: Model already exists in this category.";
                $msg_type = 'danger';
            } else {
                $st = $conn->prepare("
                  UPDATE dbo.AssetModels
                     SET CategoryID     = :cat,
                         Manufacturer   = :manu,
                         ModelName      = :name,
                         Spec           = :spec,
                         WarrantyMonths = :war,
                         VendorID       = :vendor
                   WHERE ModelID        = :id
                ");
                $st->bindValue(':cat', $CategoryID, PDO::PARAM_INT);
                $st->bindValue(':manu', ($Manufacturer !== '' ? $Manufacturer : null), ($Manufacturer !== '' ? PDO::PARAM_STR : PDO::PARAM_NULL));
                $st->bindValue(':name', ($ModelName !== '' ? $ModelName : null), ($ModelName !== '' ? PDO::PARAM_STR : PDO::PARAM_NULL));
                $st->bindValue(':spec', ($Spec !== '' ? $Spec : null), ($Spec !== '' ? PDO::PARAM_STR : PDO::PARAM_NULL));

                if ($WarrantyMonths === null) {
                    $st->bindValue(':war', null, PDO::PARAM_NULL);
                } else {
                    $st->bindValue(':war', $WarrantyMonths, PDO::PARAM_INT);
                }

                if ($VendorID === null) {
                    $st->bindValue(':vendor', null, PDO::PARAM_NULL);
                } else {
                    $st->bindValue(':vendor', $VendorID, PDO::PARAM_INT);
                }

                $st->bindValue(':id', $ModelID, PDO::PARAM_INT);
                $st->execute();
                header('Location: '.$self);
                exit;
            }
        } catch (PDOException $e) {
            if (is_duplicate_pdo($e)) {
                $msg = "Update failed: Duplicate model.";
            } else {
                $msg = "Update failed: " . h($e->getMessage());
            }
            $msg_type = 'danger';
        }
    } else {
        $msg      = implode(' ', $errors);
        $msg_type = 'danger';
    }
}

/* DELETE */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['act']) && $_POST['act'] === 'delete') {
    check_csrf();

    $id = isset($_POST['ModelID']) ? (int)$_POST['ModelID'] : 0;
    if ($id > 0) {
        try {
            $st = $conn->prepare("DELETE FROM dbo.AssetModels WHERE ModelID = :id");
            $st->execute(array(':id' => $id));
            $msg      = "Model deleted.";
            $msg_type = 'success';
        } catch (PDOException $e) {
            $msg      = "Delete failed: " . h($e->getMessage());
            $msg_type = 'danger';
        }
    }
}

/* 6) List + search + category filter */
$search    = isset($_GET['q'])   ? trim($_GET['q'])   : '';
$catFilter = isset($_GET['cat']) ? (int)$_GET['cat']  : 0;

try {
    $sql = "
      SELECT m.ModelID, m.CategoryID, m.Manufacturer, m.ModelName, m.Spec,
             m.WarrantyMonths, m.VendorID, m.CreatedAt, m.CreatedBy,
             c.CategoryName, v.VendorName, u.Username AS CreatedByUsername
        FROM dbo.AssetModels m
        JOIN dbo.AssetCategories c ON c.CategoryID = m.CategoryID
        LEFT JOIN dbo.AssetVendor v ON v.VendorID = m.VendorID
        LEFT JOIN dbo.Users u ON u.UserID = m.CreatedBy
    ";

    $where  = "1=1";
    $params = array();

    if ($search !== '') {
        $where .= " AND (
              m.ModelName    LIKE :q
           OR m.Manufacturer LIKE :q
           OR c.CategoryName LIKE :q
           OR v.VendorName   LIKE :q
        )";
        $params[':q'] = '%'.$search.'%';
    }

    if ($catFilter > 0) {
        $where .= " AND m.CategoryID = :cat";
        $params[':cat'] = $catFilter;
    }

    $sql .= " WHERE ".$where." ORDER BY c.CategoryName, m.ModelName";

    $st = $conn->prepare($sql);
    $st->execute($params);
    $rows = $st->fetchAll();
} catch (PDOException $e) {
    $rows     = array();
    $msg      = "Load list failed: " . h($e->getMessage());
    $msg_type = 'danger';
}

/* 7) Render */
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
  .form-control, .form-select{ border-radius:10px; border-color:#cbd5e1; }

  .action-stack>*{ margin:4px; }
  @media(min-width:768px){
    .action-stack{ display:inline-flex; gap:6px; }
  }

  .table thead th{ background:#f8fafc; color:#334155; border-bottom:1px solid #e5e7eb; font-size:.8rem; white-space:nowrap; }
  .table tbody td{ vertical-align:middle; font-size:.85rem; }

  @media (max-width:575.98px){
    .page-wrap{ margin-top:16px; }
  }
</style>

<div class="page-wrap">

  <!-- Header -->
  <div class="d-flex flex-wrap align-items-center justify-content-between mb-3 gap-2">
    <h1 class="page-title mb-0">
      <i class="bi bi-cpu"></i>
      <span>Asset Models</span>
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
            <i class="bi bi-pencil-square me-1 text-primary"></i>Edit Model
          </h5>
          <a class="btn btn-muted btn-sm" href="<?php echo h($self); ?>">
            <i class="bi bi-x-circle me-1"></i>Cancel
          </a>
        </div>

        <form method="post" accept-charset="UTF-8">
          <input type="hidden" name="csrf" value="<?php echo h($CSRF); ?>">
          <input type="hidden" name="act" value="update">
          <input type="hidden" name="ModelID" value="<?php echo (int)$editRow['ModelID']; ?>">

          <div class="row g-3">
            <div class="col-12 col-md-4">
              <label class="form-label">
                <i class="bi bi-grid"></i> Category
              </label>
              <select name="CategoryID" class="form-select" required>
                <option value="">-- Select --</option>
                <?php foreach($categories as $c): ?>
                  <option value="<?php echo (int)$c['CategoryID']; ?>"
                    <?php echo ((int)$editRow['CategoryID'] === (int)$c['CategoryID'] ? 'selected' : ''); ?>>
                    <?php echo h($c['CategoryName']); ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>

            <div class="col-12 col-md-4">
              <label class="form-label">
                <i class="bi bi-building"></i> Manufacturer
              </label>
              <input type="text" name="Manufacturer" class="form-control" maxlength="200"
                     value="<?php echo h($editRow['Manufacturer']); ?>">
            </div>

            <div class="col-12 col-md-4">
              <label class="form-label">
                <i class="bi bi-cpu-fill"></i> Model Name
              </label>
              <input type="text" name="ModelName" class="form-control" maxlength="200"
                     value="<?php echo h($editRow['ModelName']); ?>">
            </div>

            <div class="col-12">
              <label class="form-label">
                <i class="bi bi-card-text"></i> Spec
              </label>
              <input type="text" name="Spec" class="form-control" maxlength="1000"
                     value="<?php echo h($editRow['Spec']); ?>">
            </div>

            <div class="col-12 col-md-4">
              <label class="form-label">
                <i class="bi bi-shield-check"></i> Warranty (months)
              </label>
              <input type="number" name="WarrantyMonths" class="form-control" min="0" step="1"
                     value="<?php echo h($editRow['WarrantyMonths']); ?>">
            </div>

            <div class="col-12 col-md-4">
              <label class="form-label">
                <i class="bi bi-truck"></i> Vendor (optional)
              </label>
              <select name="VendorID" class="form-select">
                <option value="">-- None --</option>
                <?php foreach($vendors as $v): ?>
                  <option value="<?php echo (int)$v['VendorID']; ?>"
                    <?php echo ((int)(isset($editRow['VendorID']) ? $editRow['VendorID'] : 0) === (int)$v['VendorID'] ? 'selected' : ''); ?>>
                    <?php echo h($v['VendorName']); ?>
                  </option>
                <?php endforeach; ?>
              </select>
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
            <i class="bi bi-plus-circle me-1 text-success"></i>Add Model
          </h5>
        </div>

        <form method="post" class="row g-3" accept-charset="UTF-8">
          <input type="hidden" name="csrf" value="<?php echo h($CSRF); ?>">
          <input type="hidden" name="act" value="create">

          <div class="col-12 col-md-4">
            <label class="form-label">
              <i class="bi bi-grid"></i> Category
            </label>
            <select name="CategoryID" class="form-select" required>
              <option value="">-- Select --</option>
              <?php foreach($categories as $c): ?>
                <option value="<?php echo (int)$c['CategoryID']; ?>">
                  <?php echo h($c['CategoryName']); ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="col-12 col-md-4">
            <label class="form-label">
              <i class="bi bi-building"></i> Manufacturer
            </label>
            <input type="text" name="Manufacturer" class="form-control" maxlength="200"
                   placeholder="e.g. Dell / HP (optional)">
          </div>

          <div class="col-12 col-md-4">
            <label class="form-label">
              <i class="bi bi-cpu-fill"></i> Model Name
            </label>
            <input type="text" name="ModelName" class="form-control" maxlength="200"
                   placeholder="e.g. Latitude 5440 (optional)">
          </div>

          <div class="col-12">
            <label class="form-label">
              <i class="bi bi-card-text"></i> Spec
            </label>
            <input type="text" name="Spec" class="form-control" maxlength="1000"
                   placeholder="CPU/RAM/SSD/Notes (optional)">
          </div>

          <div class="col-12 col-md-4">
            <label class="form-label">
              <i class="bi bi-shield-check"></i> Warranty (months)
            </label>
            <input type="number" name="WarrantyMonths" class="form-control" min="0" step="1"
                   placeholder="e.g. 12">
          </div>

          <div class="col-12 col-md-4">
            <label class="form-label">
              <i class="bi bi-truck"></i> Vendor (optional)
            </label>
            <select name="VendorID" class="form-select">
              <option value="">-- None --</option>
              <?php foreach($vendors as $v): ?>
                <option value="<?php echo (int)$v['VendorID']; ?>">
                  <?php echo h($v['VendorName']); ?>
                </option>
              <?php endforeach; ?>
            </select>
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
          <i class="bi bi-list-ul me-1"></i>All Models
        </h5>
        <span class="text-muted small">
          <i class="bi bi-collection me-1"></i>Total: <?php echo count($rows); ?>
        </span>
      </div>

      <!-- Search + Category filter just above table with icons -->
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
                     placeholder="Search model, manufacturer, category, vendor..."
                     value="<?php echo h($search); ?>">
            </div>
          </div>

          <div class="col-12 col-md-3">
            <label class="form-label">
              <i class="bi bi-grid"></i> Category
            </label>
            <select name="cat" class="form-select">
              <option value="">All</option>
              <?php foreach($categories as $c): ?>
                <option value="<?php echo (int)$c['CategoryID']; ?>"
                  <?php echo ($catFilter === (int)$c['CategoryID'] ? 'selected' : ''); ?>>
                  <?php echo h($c['CategoryName']); ?>
                </option>
              <?php endforeach; ?>
            </select>
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
              <th>Category</th>
              <th>Manufacturer</th>
              <th>Model</th>
              <th>Warranty</th>
              <th>Vendor</th>
              <th>Created</th>
              <th>By</th>
              <th class="text-end">Actions</th>
            </tr>
          </thead>
          <tbody>
          <?php foreach($rows as $r): ?>
            <tr>
              <td><?php echo (int)$r['ModelID']; ?></td>
              <td><?php echo h($r['CategoryName']); ?></td>
              <td><?php echo h($r['Manufacturer']); ?></td>
              <td><?php echo h($r['ModelName']); ?></td>
              <td><?php echo h($r['WarrantyMonths']); ?></td>
              <td><?php echo h($r['VendorName']); ?></td>
              <td><?php echo h($r['CreatedAt']); ?></td>
              <td><?php echo h(isset($r['CreatedByUsername']) ? $r['CreatedByUsername'] : ''); ?></td>
              <td class="text-end">
                <div class="action-stack">
                  <a class="btn btn-muted btn-sm w-100 w-md-auto"
                     href="<?php echo h($self); ?>?edit=<?php echo (int)$r['ModelID']; ?>">
                    <i class="bi bi-pencil"></i>
                  </a>

                  <form method="post" class="d-inline"
                        onsubmit="return confirm('Delete this model permanently?');"
                        accept-charset="UTF-8">
                    <input type="hidden" name="csrf" value="<?php echo h($CSRF); ?>">
                    <input type="hidden" name="act" value="delete">
                    <input type="hidden" name="ModelID" value="<?php echo (int)$r['ModelID']; ?>">
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
              <td colspan="9" class="text-center text-muted py-4">No data</td>
            </tr>
          <?php endif; ?>

          </tbody>
        </table>
      </div>
    </div>
  </div>

</div><!-- /.page-wrap -->

<?php require_once __DIR__ . '/../../include/footer.php'; ?>
