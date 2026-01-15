<?php 
/******************************
 * Assets - Full CRUD (raw PHP, PHP 5.6 compatible)
 * Table: dbo.Assets
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
         stripos($msg,'uq_')!==false ||
         stripos($msg,'IX_Assets_AssetTag')!==false);
}

/* HTML datetime-local -> SQL datetime */
function to_sql_datetime($s) {
    $s = trim((string)$s);
    if ($s === '') return null;
    $s = str_replace('T', ' ', $s);

    try {
        $dt = new DateTime($s);
        return $dt->format('Y-m-d H:i:s');
    } catch (Exception $e) {
        return null;
    }
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

/* 4) Reference lists (dropdowns) */
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
    $models = $conn->query("
        SELECT m.ModelID,
               ISNULL(m.ModelName,'')      AS ModelName,
               ISNULL(m.Manufacturer,'')   AS Manufacturer,
               c.CategoryName
          FROM dbo.AssetModels m
          JOIN dbo.AssetCategories c ON c.CategoryID = m.CategoryID
         ORDER BY c.CategoryName, m.Manufacturer, m.ModelName
    ")->fetchAll();
} catch (PDOException $e){
    $models = array();
}

try {
    $locations = $conn->query("
        SELECT LocationID, LocationName 
          FROM dbo.Locations 
         ORDER BY LocationName
    ")->fetchAll();
} catch (PDOException $e){
    $locations = array();
}

try {
    $employees = $conn->query("
        SELECT EmployeeID,
               FirstName + ' ' + ISNULL(LastName,'') AS EmpName
          FROM dbo.Employees
         ORDER BY FirstName, LastName
    ")->fetchAll();
} catch (PDOException $e){
    $employees = array();
}

$STATUS_OPTIONS    = array('InStock','Assigned','InRepair','Retired');
$ASSIGN_TO_OPTIONS = array('Location','Employee');

/* 5) Actions */
$msg      = '';
$msg_type = 'success';

if (isset($_GET['ok']) && $_GET['ok'] === '1') {
    $msg      = 'Asset created.';
    $msg_type = 'success';
}

$editRow = null;
$edit_id = isset($_GET['edit']) ? (int)$_GET['edit'] : 0;

/* CREATE */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['act']) && $_POST['act'] === 'create') {
    check_csrf();

    $AssetTag                = isset($_POST['AssetTag']) ? trim($_POST['AssetTag']) : '';
    $SerialNumber            = isset($_POST['SerialNumber']) ? trim($_POST['SerialNumber']) : '';
    $ModelID                 = (isset($_POST['ModelID']) && $_POST['ModelID'] !== '') ? (int)$_POST['ModelID'] : null;
    $AssetName               = isset($_POST['AssetName']) ? trim($_POST['AssetName']) : '';
    $CategoryID              = isset($_POST['CategoryID']) ? (int)$_POST['CategoryID'] : 0;
    $Description             = isset($_POST['Description']) ? trim($_POST['Description']) : '';
    $PurchaseDate            = isset($_POST['PurchaseDate']) ? trim($_POST['PurchaseDate']) : '';
    $PurchasePrice           = isset($_POST['PurchasePrice']) ? trim($_POST['PurchasePrice']) : '';
    $CurrentLocationID       = (isset($_POST['CurrentLocationID']) && $_POST['CurrentLocationID'] !== '') ? (int)$_POST['CurrentLocationID'] : 0;
    $CurrentHolderEmployeeID = (isset($_POST['CurrentHolderEmployeeID']) && $_POST['CurrentHolderEmployeeID'] !== '') ? (int)$_POST['CurrentHolderEmployeeID'] : null;
    $Status                  = isset($_POST['Status']) ? trim($_POST['Status']) : 'InStock';
    $IsConsumable            = isset($_POST['IsConsumable']) ? 1 : 0;
    $IsRechargeable          = isset($_POST['IsRechargeable']) ? 1 : 0;
    $AssignTo                = isset($_POST['AssignTo']) ? trim($_POST['AssignTo']) : '';

    $errors = array();

    if ($AssetTag === '')  $errors[] = 'Asset tag is required.';
    if ($AssetName === '') $errors[] = 'Asset name is required.';
    if ($CategoryID <= 0)  $errors[] = 'Category is required.';

    if ($PurchasePrice !== '' && !is_numeric($PurchasePrice)) {
        $errors[] = 'Purchase price must be numeric.';
    }

    if ($Status === '' || !in_array($Status, $STATUS_OPTIONS, true)) {
        $Status = 'InStock';
    }

    if ($AssignTo === '' || !in_array($AssignTo, $ASSIGN_TO_OPTIONS, true)) {
        $errors[] = 'AssignTo is required.';
    }

    if ($AssignTo === 'Location') {
        if ($CurrentLocationID <= 0) {
            $errors[] = 'Location is required when AssignTo = Location.';
        }
        $CurrentHolderEmployeeID = null;
    } elseif ($AssignTo === 'Employee') {
        if ($CurrentHolderEmployeeID === null || $CurrentHolderEmployeeID <= 0) {
            $errors[] = 'Employee is required when AssignTo = Employee.';
        }
        $CurrentLocationID = 0;
    }

    if (empty($errors)) {
        try {
            // Unique AssetTag check
            $chk = $conn->prepare("
                SELECT 1 
                  FROM dbo.Assets 
                 WHERE LOWER(LTRIM(RTRIM(AssetTag))) = :t
            ");
            $chk->execute(array(':t' => normalize_str($AssetTag)));

            if ($chk->fetchColumn()) {
                $msg      = "Create failed: AssetTag already exists.";
                $msg_type = 'danger';
            } else {
                $createdBy = isset($_SESSION['auth_user']['UserID'])
                           ? (int)$_SESSION['auth_user']['UserID']
                           : null;

                $st = $conn->prepare("
                    INSERT INTO dbo.Assets
                      (AssetTag, SerialNumber, ModelID, AssetName, CategoryID, [Description],
                       PurchaseDate, PurchasePrice, CurrentLocationID, CurrentHolderEmployeeID,
                       [Status], IsConsumable, IsRechargeable, CreatedAt, CreatedBy, AssignTo)
                    VALUES
                      (:tag, :sn, :model, :name, :cat, :desc,
                       :pdate, :pprice, :loc, :holder,
                       :status, :cons, :rech, GETDATE(), :by, :assignTo)
                ");

                $st->bindValue(':tag', $AssetTag, PDO::PARAM_STR);
                $st->bindValue(':sn',  ($SerialNumber !== '' ? $SerialNumber : null), ($SerialNumber !== '' ? PDO::PARAM_STR : PDO::PARAM_NULL));

                if ($ModelID === null) {
                    $st->bindValue(':model', null, PDO::PARAM_NULL);
                } else {
                    $st->bindValue(':model', $ModelID, PDO::PARAM_INT);
                }

                $st->bindValue(':name', $AssetName, PDO::PARAM_STR);
                $st->bindValue(':cat',  $CategoryID, PDO::PARAM_INT);
                $st->bindValue(':desc', ($Description !== '' ? $Description : null), ($Description !== '' ? PDO::PARAM_STR : PDO::PARAM_NULL));

                $pd = to_sql_datetime($PurchaseDate);
                $st->bindValue(':pdate', $pd, ($pd === null ? PDO::PARAM_NULL : PDO::PARAM_STR));

                $st->bindValue(':pprice', ($PurchasePrice !== '' ? $PurchasePrice : null), ($PurchasePrice !== '' ? PDO::PARAM_STR : PDO::PARAM_NULL));

                if ($CurrentLocationID > 0) {
                    $st->bindValue(':loc', $CurrentLocationID, PDO::PARAM_INT);
                } else {
                    $st->bindValue(':loc', null, PDO::PARAM_NULL);
                }

                if ($CurrentHolderEmployeeID === null) {
                    $st->bindValue(':holder', null, PDO::PARAM_NULL);
                } else {
                    $st->bindValue(':holder', $CurrentHolderEmployeeID, PDO::PARAM_INT);
                }

                $st->bindValue(':status', $Status, PDO::PARAM_STR);
                $st->bindValue(':cons',   $IsConsumable, PDO::PARAM_INT);
                $st->bindValue(':rech',   $IsRechargeable, PDO::PARAM_INT);
                if ($createdBy === null) {
                    $st->bindValue(':by', null, PDO::PARAM_NULL);
                } else {
                    $st->bindValue(':by', $createdBy, PDO::PARAM_INT);
                }
                $st->bindValue(':assignTo', $AssignTo, PDO::PARAM_STR);

                $st->execute();
                header('Location: '.$self.'?ok=1');
                exit;
            }
        } catch (PDOException $e) {
            if (is_duplicate_pdo($e)) {
                $msg = "Create failed: AssetTag already exists.";
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
          SELECT a.*,
                 c.CategoryName,
                 l.LocationName,
                 (e.FirstName + ' ' + ISNULL(e.LastName,'')) AS HolderName,
                 m.ModelName, m.Manufacturer,
                 u.Username AS CreatedByUsername
            FROM dbo.Assets a
            JOIN dbo.AssetCategories c ON c.CategoryID = a.CategoryID
            LEFT JOIN dbo.Locations l ON l.LocationID = a.CurrentLocationID
            LEFT JOIN dbo.Employees e ON e.EmployeeID = a.CurrentHolderEmployeeID
            LEFT JOIN dbo.AssetModels m ON m.ModelID = a.ModelID
            LEFT JOIN dbo.Users u ON u.UserID = a.CreatedBy
           WHERE a.AssetID = :id
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

    $AssetID                 = isset($_POST['AssetID']) ? (int)$_POST['AssetID'] : 0;
    $AssetTag                = isset($_POST['AssetTag']) ? trim($_POST['AssetTag']) : '';
    $SerialNumber            = isset($_POST['SerialNumber']) ? trim($_POST['SerialNumber']) : '';
    $ModelID                 = (isset($_POST['ModelID']) && $_POST['ModelID'] !== '') ? (int)$_POST['ModelID'] : null;
    $AssetName               = isset($_POST['AssetName']) ? trim($_POST['AssetName']) : '';
    $CategoryID              = isset($_POST['CategoryID']) ? (int)$_POST['CategoryID'] : 0;
    $Description             = isset($_POST['Description']) ? trim($_POST['Description']) : '';
    $PurchaseDate            = isset($_POST['PurchaseDate']) ? trim($_POST['PurchaseDate']) : '';
    $PurchasePrice           = isset($_POST['PurchasePrice']) ? trim($_POST['PurchasePrice']) : '';
    $CurrentLocationID       = (isset($_POST['CurrentLocationID']) && $_POST['CurrentLocationID'] !== '') ? (int)$_POST['CurrentLocationID'] : 0;
    $CurrentHolderEmployeeID = (isset($_POST['CurrentHolderEmployeeID']) && $_POST['CurrentHolderEmployeeID'] !== '') ? (int)$_POST['CurrentHolderEmployeeID'] : null;
    $Status                  = isset($_POST['Status']) ? trim($_POST['Status']) : 'InStock';
    $IsConsumable            = isset($_POST['IsConsumable']) ? 1 : 0;
    $IsRechargeable          = isset($_POST['IsRechargeable']) ? 1 : 0;
    $AssignTo                = isset($_POST['AssignTo']) ? trim($_POST['AssignTo']) : '';

    $errors = array();

    if ($AssetID <= 0)  $errors[] = 'Invalid asset.';
    if ($AssetTag === '')  $errors[] = 'Asset tag is required.';
    if ($AssetName === '') $errors[] = 'Asset name is required.';
    if ($CategoryID <= 0)  $errors[] = 'Category is required.';

    if ($PurchasePrice !== '' && !is_numeric($PurchasePrice)) {
        $errors[] = 'Purchase price must be numeric.';
    }

    if ($Status === '' || !in_array($Status, $STATUS_OPTIONS, true)) {
        $Status = 'InStock';
    }

    if ($AssignTo === '' || !in_array($AssignTo, $ASSIGN_TO_OPTIONS, true)) {
        $errors[] = 'AssignTo is required.';
    }

    if ($AssignTo === 'Location') {
        if ($CurrentLocationID <= 0) {
            $errors[] = 'Location is required when AssignTo = Location.';
        }
        $CurrentHolderEmployeeID = null;
    } elseif ($AssignTo === 'Employee') {
        if ($CurrentHolderEmployeeID === null || $CurrentHolderEmployeeID <= 0) {
            $errors[] = 'Employee is required when AssignTo = Employee.';
        }
        $CurrentLocationID = 0;
    }

    if (empty($errors)) {
        try {
            $chk = $conn->prepare("
                SELECT 1 
                  FROM dbo.Assets 
                 WHERE LOWER(LTRIM(RTRIM(AssetTag))) = :t 
                   AND AssetID <> :id
            ");
            $chk->execute(array(
                ':t'  => normalize_str($AssetTag),
                ':id' => $AssetID
            ));

            if ($chk->fetchColumn()) {
                $msg      = "Update failed: AssetTag already exists.";
                $msg_type = 'danger';
            } else {
                $st = $conn->prepare("
                  UPDATE dbo.Assets
                     SET AssetTag                 = :tag,
                         SerialNumber             = :sn,
                         ModelID                  = :model,
                         AssetName                = :name,
                         CategoryID               = :cat,
                         [Description]            = :desc,
                         PurchaseDate             = :pdate,
                         PurchasePrice            = :pprice,
                         CurrentLocationID        = :loc,
                         CurrentHolderEmployeeID  = :holder,
                         [Status]                 = :status,
                         IsConsumable             = :cons,
                         IsRechargeable           = :rech,
                         AssignTo                 = :assignTo
                   WHERE AssetID                 = :id
                ");

                $st->bindValue(':tag', $AssetTag, PDO::PARAM_STR);
                $st->bindValue(':sn',  ($SerialNumber !== '' ? $SerialNumber : null), ($SerialNumber !== '' ? PDO::PARAM_STR : PDO::PARAM_NULL));

                if ($ModelID === null) {
                    $st->bindValue(':model', null, PDO::PARAM_NULL);
                } else {
                    $st->bindValue(':model', $ModelID, PDO::PARAM_INT);
                }

                $st->bindValue(':name', $AssetName, PDO::PARAM_STR);
                $st->bindValue(':cat',  $CategoryID, PDO::PARAM_INT);
                $st->bindValue(':desc', ($Description !== '' ? $Description : null), ($Description !== '' ? PDO::PARAM_STR : PDO::PARAM_NULL));

                $pd = to_sql_datetime($PurchaseDate);
                $st->bindValue(':pdate', $pd, ($pd === null ? PDO::PARAM_NULL : PDO::PARAM_STR));

                $st->bindValue(':pprice', ($PurchasePrice !== '' ? $PurchasePrice : null), ($PurchasePrice !== '' ? PDO::PARAM_STR : PDO::PARAM_NULL));

                if ($CurrentLocationID > 0) {
                    $st->bindValue(':loc', $CurrentLocationID, PDO::PARAM_INT);
                } else {
                    $st->bindValue(':loc', null, PDO::PARAM_NULL);
                }

                if ($CurrentHolderEmployeeID === null) {
                    $st->bindValue(':holder', null, PDO::PARAM_NULL);
                } else {
                    $st->bindValue(':holder', $CurrentHolderEmployeeID, PDO::PARAM_INT);
                }

                $st->bindValue(':status',   $Status, PDO::PARAM_STR);
                $st->bindValue(':cons',     $IsConsumable, PDO::PARAM_INT);
                $st->bindValue(':rech',     $IsRechargeable, PDO::PARAM_INT);
                $st->bindValue(':assignTo', $AssignTo, PDO::PARAM_STR);
                $st->bindValue(':id',       $AssetID, PDO::PARAM_INT);

                $st->execute();
                header('Location: '.$self);
                exit;
            }
        } catch (PDOException $e) {
            if (is_duplicate_pdo($e)) {
                $msg = "Update failed: AssetTag already exists.";
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
    $id = isset($_POST['AssetID']) ? (int)$_POST['AssetID'] : 0;
    if ($id > 0) {
        try {
            $st = $conn->prepare("DELETE FROM dbo.Assets WHERE AssetID = :id");
            $st->execute(array(':id' => $id));
            $msg      = "Asset deleted.";
            $msg_type = 'success';
        } catch (PDOException $e) {
            $msg      = "Delete failed: " . h($e->getMessage());
            $msg_type = 'danger';
        }
    }
}

/* 6) List + search + filter system (like location/department) */
$search       = isset($_GET['q']) ? trim($_GET['q']) : '';
$assignFilter = isset($_GET['assign']) ? trim($_GET['assign']) : '';
if (!in_array($assignFilter, $ASSIGN_TO_OPTIONS, true)) {
    $assignFilter = '';
}

$categoryFilter = isset($_GET['cat']) ? (int)$_GET['cat'] : 0;
$statusFilter   = isset($_GET['status']) ? trim($_GET['status']) : '';
$locationFilter = isset($_GET['loc']) ? (int)$_GET['loc'] : 0;
if (!in_array($statusFilter, $STATUS_OPTIONS, true)) {
    $statusFilter = '';
}

try {
    $sql = "
      SELECT a.AssetID, a.AssetTag, a.SerialNumber, a.AssetName, a.Status,
             a.PurchaseDate, a.PurchasePrice, a.IsConsumable, a.IsRechargeable,
             a.AssignTo,
             c.CategoryName,
             l.LocationName,
             ISNULL(m.Manufacturer,'') AS Manufacturer,
             ISNULL(m.ModelName,'')    AS ModelName,
             (e.FirstName + ' ' + ISNULL(e.LastName,'')) AS HolderName,
             a.CreatedAt,
             u.Username AS CreatedByUsername
        FROM dbo.Assets a
        JOIN dbo.AssetCategories c ON c.CategoryID = a.CategoryID
        LEFT JOIN dbo.Locations l ON l.LocationID = a.CurrentLocationID
        LEFT JOIN dbo.AssetModels m ON m.ModelID = a.ModelID
        LEFT JOIN dbo.Employees e  ON e.EmployeeID = a.CurrentHolderEmployeeID
        LEFT JOIN dbo.Users u      ON u.UserID = a.CreatedBy
    ";

    $where  = "1=1";
    $params = array();

    if ($search !== '') {
        $where .= " AND (
              a.AssetTag     LIKE :q
           OR a.AssetName    LIKE :q
           OR c.CategoryName LIKE :q
           OR l.LocationName LIKE :q
           OR m.ModelName    LIKE :q
           OR m.Manufacturer LIKE :q
           OR a.SerialNumber LIKE :q
        )";
        $params[':q'] = '%'.$search.'%';
    }

    if ($assignFilter !== '') {
        $where .= " AND a.AssignTo = :assignTo";
        $params[':assignTo'] = $assignFilter;
    }

    if ($categoryFilter > 0) {
        $where .= " AND a.CategoryID = :catFilter";
        $params[':catFilter'] = $categoryFilter;
    }

    if ($statusFilter !== '') {
        $where .= " AND a.Status = :statusFilter";
        $params[':statusFilter'] = $statusFilter;
    }

    if ($locationFilter > 0) {
        $where .= " AND a.CurrentLocationID = :locFilter";
        $params[':locFilter'] = $locationFilter;
    }

    $sql .= " WHERE ".$where." ORDER BY a.AssetTag";

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

  .form-label{ font-weight:600; color:#374151; display:flex; align-items:center; gap:.35rem; font-size:.85rem; }
  .form-label i{ color:#64748b; font-size:.95rem; }
  .form-control, .form-select{ border-radius:10px; border-color:#cbd5e1; }

  .action-stack > *{ margin:4px; }
  @media (min-width:768px){
    .action-stack{ display:inline-flex; gap:6px; }
  }
  .table thead th{ background:#f8fafc; color:#334155; border-bottom:1px solid #e5e7eb; font-size:.8rem; white-space:nowrap; }
  .table tbody td{ vertical-align:middle; font-size:.85rem; }

  .assign-badge{ font-size:.7rem; border-radius:999px; padding:3px 8px; }
  .assign-location{ background:#ecfeff; color:#0891b2; border:1px solid #a5f3fc; }
  .assign-employee{ background:#eef2ff; color:#4f46e5; border:1px solid #c7d2fe; }

  @media (max-width:575.98px){
    .page-wrap{ margin-top:16px; }
  }
</style>

<div class="page-wrap">

  <!-- Header (simple like Location/Department) -->
  <div class="d-flex flex-wrap align-items-center justify-content-between mb-3">
    <h1 class="page-title mb-0">
      <i class="bi bi-box-seam"></i>
      <span>Assets</span>
    </h1>
  </div>

  <!-- Alerts -->
  <?php if ($msg): ?>
    <div class="alert alert-<?php echo ($msg_type === 'danger' ? 'danger' : 'success'); ?> alert-dismissible fade show" role="alert">
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
            <i class="bi bi-pencil-square me-1 text-primary"></i>Edit Asset
          </h5>
          <a class="btn btn-muted btn-sm" href="<?php echo h($self); ?>">
            <i class="bi bi-x-circle me-1"></i>Cancel
          </a>
        </div>

        <form method="post" accept-charset="UTF-8" data-assign-scope>
          <input type="hidden" name="csrf" value="<?php echo h($CSRF); ?>">
          <input type="hidden" name="act" value="update">
          <input type="hidden" name="AssetID" value="<?php echo (int)$editRow['AssetID']; ?>">

          <div class="row g-3">
            <div class="col-12 col-md-3">
              <label class="form-label">
                <i class="bi bi-hash"></i> Asset Tag
              </label>
              <input type="text" name="AssetTag" class="form-control" required maxlength="100"
                     value="<?php echo h($editRow['AssetTag']); ?>">
            </div>

            <div class="col-12 col-md-3">
              <label class="form-label">
                <i class="bi bi-upc-scan"></i> Serial (optional)
              </label>
              <input type="text" name="SerialNumber" class="form-control" maxlength="200"
                     value="<?php echo h($editRow['SerialNumber']); ?>">
            </div>

            <div class="col-12 col-md-6">
              <label class="form-label">
                <i class="bi bi-laptop"></i> Asset Name
              </label>
              <input type="text" name="AssetName" class="form-control" required maxlength="50"
                     value="<?php echo h($editRow['AssetName']); ?>">
            </div>

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
                <i class="bi bi-cpu"></i> Model (optional)
              </label>
              <select name="ModelID" class="form-select">
                <option value="">-- None --</option>
                <?php foreach($models as $m): ?>
                  <?php
                    $label = ($m['Manufacturer'] ? $m['Manufacturer'].' ' : '') . $m['ModelName'];
                    if ($label === '') {
                        $label = '(unnamed)';
                    }
                    $label = $m['CategoryName'].' — '.$label;
                  ?>
                  <option value="<?php echo (int)$m['ModelID']; ?>"
                    <?php echo ((int)(isset($editRow['ModelID']) ? $editRow['ModelID'] : 0) === (int)$m['ModelID'] ? 'selected' : ''); ?>>
                    <?php echo h($label); ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>

            <?php $assignVal = isset($editRow['AssignTo']) ? $editRow['AssignTo'] : 'Location'; ?>

            <div class="col-12 col-md-4">
              <label class="form-label">
                <i class="bi bi-diagram-3"></i> Assign To
              </label>
              <select name="AssignTo" class="form-select" data-role="assign-select">
                <option value="">-- Select --</option>
                <option value="Location" <?php echo ($assignVal === 'Location' ? 'selected' : ''); ?>>Location</option>
                <option value="Employee" <?php echo ($assignVal === 'Employee' ? 'selected' : ''); ?>>Employee</option>
              </select>
            </div>

            <div class="col-12 col-md-4" data-role="assign-location">
              <label class="form-label">
                <i class="bi bi-geo-alt"></i> Location
              </label>
              <select name="CurrentLocationID" class="form-select">
                <option value="">-- Select --</option>
                <?php foreach($locations as $l): ?>
                  <option value="<?php echo (int)$l['LocationID']; ?>"
                    <?php echo ((int)$editRow['CurrentLocationID'] === (int)$l['LocationID'] ? 'selected' : ''); ?>>
                    <?php echo h($l['LocationName']); ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>

            <div class="col-12 col-md-4" data-role="assign-employee">
              <label class="form-label">
                <i class="bi bi-person-badge"></i> Holder (Employee)
              </label>
              <select name="CurrentHolderEmployeeID" class="form-select">
                <option value="">-- None --</option>
                <?php foreach($employees as $e): ?>
                  <option value="<?php echo (int)$e['EmployeeID']; ?>"
                    <?php echo ((int)(isset($editRow['CurrentHolderEmployeeID']) ? $editRow['CurrentHolderEmployeeID'] : 0) === (int)$e['EmployeeID'] ? 'selected' : ''); ?>>
                    <?php echo h($e['EmpName']); ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>

            <div class="col-12">
              <label class="form-label">
                <i class="bi bi-card-text"></i> Description
              </label>
              <input type="text" name="Description" class="form-control" maxlength="500"
                     value="<?php echo h($editRow['Description']); ?>">
            </div>

            <div class="col-12 col-md-4">
              <label class="form-label">
                <i class="bi bi-calendar-event"></i> Purchase Date
              </label>
              <input type="datetime-local" name="PurchaseDate" class="form-control"
                     value="<?php echo ($editRow['PurchaseDate'] ? date('Y-m-d\TH:i', strtotime($editRow['PurchaseDate'])) : ''); ?>">
            </div>

            <div class="col-12 col-md-4">
              <label class="form-label">
                <i class="bi bi-cash-coin"></i> Purchase Price
              </label>
              <input type="number" step="0.01" name="PurchasePrice" class="form-control"
                     value="<?php echo h($editRow['PurchasePrice']); ?>">
            </div>

            <div class="col-12 col-md-4">
              <label class="form-label">
                <i class="bi bi-activity"></i> Status
              </label>
              <select name="Status" class="form-select">
                <?php foreach($STATUS_OPTIONS as $s): ?>
                  <option value="<?php echo h($s); ?>" <?php echo ($editRow['Status'] === $s ? 'selected' : ''); ?>>
                    <?php echo h($s); ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>

            <div class="col-12 col-md-4 d-flex align-items-center gap-4">
              <div class="form-check">
                <input class="form-check-input" type="checkbox" name="IsConsumable" id="isConsEdit"
                       <?php echo ((int)$editRow['IsConsumable'] === 1 ? 'checked' : ''); ?>>
                <label class="form-check-label" for="isConsEdit">Consumable</label>
              </div>
              <div class="form-check">
                <input class="form-check-input" type="checkbox" name="IsRechargeable" id="isRechEdit"
                       <?php echo ((int)$editRow['IsRechargeable'] === 1 ? 'checked' : ''); ?>>
                <label class="form-check-label" for="isRechEdit">Rechargeable</label>
              </div>
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
            <i class="bi bi-plus-circle me-1 text-success"></i>Add Asset
          </h5>
        </div>

        <form method="post" class="row g-3" accept-charset="UTF-8" data-assign-scope>
          <input type="hidden" name="csrf" value="<?php echo h($CSRF); ?>">
          <input type="hidden" name="act" value="create">

          <div class="col-12 col-md-3">
            <label class="form-label">
              <i class="bi bi-hash"></i> Asset Tag
            </label>
            <input type="text" name="AssetTag" class="form-control" required maxlength="100" placeholder="Unique tag">
          </div>

          <div class="col-12 col-md-3">
            <label class="form-label">
              <i class="bi bi-upc-scan"></i> Serial (optional)
            </label>
            <input type="text" name="SerialNumber" class="form-control" maxlength="200" placeholder="Serial number">
          </div>

          <div class="col-12 col-md-6">
            <label class="form-label">
              <i class="bi bi-laptop"></i> Asset Name
            </label>
            <input type="text" name="AssetName" class="form-control" required maxlength="50" placeholder="e.g. Laptop - John">
          </div>

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
              <i class="bi bi-cpu"></i> Model (optional)
            </label>
            <select name="ModelID" class="form-select">
              <option value="">-- None --</option>
              <?php foreach($models as $m): ?>
                <?php
                  $label = ($m['Manufacturer'] ? $m['Manufacturer'].' ' : '') . $m['ModelName'];
                  if ($label === '') {
                      $label = '(unnamed)';
                  }
                  $label = $m['CategoryName'].' — '.$label;
                ?>
                <option value="<?php echo (int)$m['ModelID']; ?>">
                  <?php echo h($label); ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="col-12 col-md-4">
            <label class="form-label">
              <i class="bi bi-diagram-3"></i> Assign To
            </label>
            <select name="AssignTo" class="form-select" data-role="assign-select">
              <option value="">-- Select --</option>
              <option value="Location" selected>Location</option>
              <option value="Employee">Employee</option>
            </select>
          </div>

          <div class="col-12 col-md-4" data-role="assign-location">
            <label class="form-label">
              <i class="bi bi-geo-alt"></i> Location
            </label>
            <select name="CurrentLocationID" class="form-select">
              <option value="">-- Select --</option>
              <?php foreach($locations as $l): ?>
                <option value="<?php echo (int)$l['LocationID']; ?>">
                  <?php echo h($l['LocationName']); ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="col-12 col-md-4" data-role="assign-employee">
            <label class="form-label">
              <i class="bi bi-person-badge"></i> Holder (Employee)
            </label>
            <select name="CurrentHolderEmployeeID" class="form-select">
              <option value="">-- None --</option>
              <?php foreach($employees as $e): ?>
                <option value="<?php echo (int)$e['EmployeeID']; ?>">
                  <?php echo h($e['EmpName']); ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="col-12">
            <label class="form-label">
              <i class="bi bi-card-text"></i> Description
            </label>
            <input type="text" name="Description" class="form-control" maxlength="500" placeholder="Optional notes">
          </div>

          <div class="col-12 col-md-4">
            <label class="form-label">
              <i class="bi bi-calendar-event"></i> Purchase Date
            </label>
            <input type="datetime-local" name="PurchaseDate" class="form-control">
          </div>

          <div class="col-12 col-md-4">
            <label class="form-label">
              <i class="bi bi-cash-coin"></i> Purchase Price
            </label>
            <input type="number" step="0.01" name="PurchasePrice" class="form-control" placeholder="e.g. 1200.00">
          </div>

          <div class="col-12 col-md-4">
            <label class="form-label">
              <i class="bi bi-activity"></i> Status
            </label>
            <select name="Status" class="form-select">
              <?php foreach($STATUS_OPTIONS as $s): ?>
                <option value="<?php echo h($s); ?>" <?php echo ($s === 'InStock' ? 'selected' : ''); ?>>
                  <?php echo h($s); ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="col-12 col-md-8 d-flex align-items-center gap-4">
            <div class="form-check">
              <input class="form-check-input" type="checkbox" name="IsConsumable" id="isConsCreate">
              <label class="form-check-label" for="isConsCreate">Consumable</label>
            </div>
            <div class="form-check">
              <input class="form-check-input" type="checkbox" name="IsRechargeable" id="isRechCreate">
              <label class="form-check-label" for="isRechCreate">Rechargeable</label>
            </div>
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
          <i class="bi bi-list-ul me-1"></i>All Assets
        </h5>
        <span class="text-muted small">
          <i class="bi bi-collection me-1"></i>Total: <?php echo count($rows); ?>
        </span>
      </div>

      <!-- Search + Filters just above table with icons -->
      <form method="get" class="mb-3" accept-charset="UTF-8">
        <div class="row g-2 align-items-end">

          <div class="col-12 col-md-4">
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
                     placeholder="Tag, name, category, location..."
                     value="<?php echo h($search); ?>">
            </div>
          </div>

          <div class="col-6 col-md-3">
            <label class="form-label">
              <i class="bi bi-filter"></i> Category
            </label>
            <select name="cat" class="form-select">
              <option value="">All</option>
              <?php foreach($categories as $c): ?>
                <option value="<?php echo (int)$c['CategoryID']; ?>"
                  <?php echo ($categoryFilter == (int)$c['CategoryID'] ? 'selected' : ''); ?>>
                  <?php echo h($c['CategoryName']); ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="col-6 col-md-3">
            <label class="form-label">
              <i class="bi bi-activity"></i> Status
            </label>
            <select name="status" class="form-select">
              <option value="">All</option>
              <?php foreach($STATUS_OPTIONS as $s): ?>
                <option value="<?php echo h($s); ?>" <?php echo ($statusFilter === $s ? 'selected' : ''); ?>>
                  <?php echo h($s); ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="col-6 col-md-2">
            <label class="form-label">
              <i class="bi bi-diagram-3"></i> Assign
            </label>
            <select name="assign" class="form-select">
              <option value="">All</option>
              <option value="Location" <?php echo ($assignFilter === 'Location' ? 'selected' : ''); ?>>Location</option>
              <option value="Employee" <?php echo ($assignFilter === 'Employee' ? 'selected' : ''); ?>>Employee</option>
            </select>
          </div>

          <div class="col-6 col-md-3">
            <label class="form-label">
              <i class="bi bi-geo-alt"></i> Location
            </label>
            <select name="loc" class="form-select">
              <option value="">All</option>
              <?php foreach($locations as $l): ?>
                <option value="<?php echo (int)$l['LocationID']; ?>"
                  <?php echo ($locationFilter == (int)$l['LocationID'] ? 'selected' : ''); ?>>
                  <?php echo h($l['LocationName']); ?>
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
              <th>Tag</th>
              <th>Name</th>
              <th>Category</th>
              <th>Model</th>
              <th>Assign</th>
              <th>Location</th>
              <th>Holder</th>
              <th>Status</th>
              <th>Price</th>
              <th>Created</th>
              <th class="text-end">Actions</th>
            </tr>
          </thead>
          <tbody>
          <?php foreach($rows as $r): ?>
            <tr>
              <td><?php echo (int)$r['AssetID']; ?></td>
              <td><?php echo h($r['AssetTag']); ?></td>
              <td><?php echo h($r['AssetName']); ?></td>
              <td><?php echo h($r['CategoryName']); ?></td>
              <td><?php echo h(trim($r['Manufacturer'].' '.$r['ModelName'])); ?></td>
              <td>
                <?php if ($r['AssignTo'] === 'Employee'): ?>
                  <span class="assign-badge assign-employee">
                    <i class="bi bi-person-fill me-1"></i>Employee
                  </span>
                <?php elseif ($r['AssignTo'] === 'Location'): ?>
                  <span class="assign-badge assign-location">
                    <i class="bi bi-geo-alt-fill me-1"></i>Location
                  </span>
                <?php else: ?>
                  <span class="badge-soft text-muted">N/A</span>
                <?php endif; ?>
              </td>
              <td><?php echo h($r['LocationName']); ?></td>
              <td><?php echo h($r['HolderName']); ?></td>
              <td><?php echo h($r['Status']); ?></td>
              <td><?php echo h($r['PurchasePrice']); ?></td>
              <td><?php echo h($r['CreatedAt']); ?></td>
              <td class="text-end">
                <div class="action-stack">
                  <a class="btn btn-muted btn-sm w-100 w-md-auto"
                     href="<?php echo h($self); ?>?edit=<?php echo (int)$r['AssetID']; ?>">
                    <i class="bi bi-pencil"></i>
                  </a>

                  <form method="post" class="d-inline" onsubmit="return confirm('Delete this asset permanently?');" accept-charset="UTF-8">
                    <input type="hidden" name="csrf" value="<?php echo h($CSRF); ?>">
                    <input type="hidden" name="act" value="delete">
                    <input type="hidden" name="AssetID" value="<?php echo (int)$r['AssetID']; ?>">
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
              <td colspan="12" class="text-center text-muted py-4">No data</td>
            </tr>
          <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>

</div><!-- /.page-wrap -->

<script>
// Simple JS (ES5) so PHP 5.6 environments are fine
document.addEventListener('DOMContentLoaded', function () {
    var scopes = document.querySelectorAll('[data-assign-scope]');
    if (!scopes.length) return;

    for (var i = 0; i < scopes.length; i++) {
        (function(scope){
            var select        = scope.querySelector('[data-role="assign-select"]');
            var locationGroup = scope.querySelector('[data-role="assign-location"]');
            var employeeGroup = scope.querySelector('[data-role="assign-employee"]');

            if (!select) { return; }

            function refresh() {
                var val = select.value;
                if (val === 'Employee') {
                    if (locationGroup) locationGroup.style.display = 'none';
                    if (employeeGroup) employeeGroup.style.display = '';
                } else if (val === 'Location') {
                    if (locationGroup) locationGroup.style.display = '';
                    if (employeeGroup) employeeGroup.style.display = 'none';
                } else {
                    if (locationGroup) locationGroup.style.display = '';
                    if (employeeGroup) employeeGroup.style.display = '';
                }
            }

            refresh();
            select.addEventListener('change', refresh);
        })(scopes[i]);
    }
});
</script>

<?php require_once __DIR__ . '/../../include/footer.php'; ?>
