<?php
/******************************
 * Asset Vendors - Full CRUD (raw PHP, styled like Assets/Models pages)
 * Table: dbo.AssetVendor
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
           (stripos($msg, 'unique')   !== false ||
            stripos($msg, 'duplicate')!== false ||
            stripos($msg, 'uq_')      !== false);
}

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

/* 4) Actions */
$msg      = '';
$msg_type = 'success';

if (isset($_GET['ok']) && $_GET['ok'] === '1') {
    $msg      = 'Vendor created.';
    $msg_type = 'success';
}

$editRow = null;
$edit_id = isset($_GET['edit']) ? (int)$_GET['edit'] : 0;

/* CREATE */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (isset($_POST['act']) ? $_POST['act'] : '') === 'create') {
    check_csrf();

    $VendorName          = isset($_POST['VendorName']) ? trim($_POST['VendorName']) : '';
    $VendorAddress       = isset($_POST['VendorAddress']) ? trim($_POST['VendorAddress']) : '';
    $VendorContactPerson = isset($_POST['VendorContactPerson']) ? trim($_POST['VendorContactPerson']) : '';
    $VendorMobileNumber  = isset($_POST['VendorMobileNumber']) ? trim($_POST['VendorMobileNumber']) : '';
    $VendorEmail         = isset($_POST['VendorEmail']) ? trim($_POST['VendorEmail']) : '';
    $IsActive            = isset($_POST['IsActive']) ? 1 : 0;

    $errors = array();
    if ($VendorName === '')         $errors[] = 'Vendor name is required.';
    if ($VendorAddress === '')      $errors[] = 'Vendor address is required.';
    if ($VendorMobileNumber === '') $errors[] = 'Mobile number is required.';
    if ($VendorEmail !== '' && !filter_var($VendorEmail, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Invalid email format.';
    }

    if (empty($errors)) {
        try {
            // Duplicate check: by name OR mobile
            $chk = $conn->prepare("
                SELECT TOP 1 1
                  FROM dbo.AssetVendor
                 WHERE LOWER(LTRIM(RTRIM(VendorName))) = :n
                    OR VendorMobileNumber = :m
            ");
            $chk->execute(array(
                ':n' => normalize_str($VendorName),
                ':m' => $VendorMobileNumber
            ));
            if ($chk->fetchColumn()) {
                $msg      = "Create failed: Duplicate vendor (name/mobile already exists).";
                $msg_type = 'danger';
            } else {
                $createdBy = isset($_SESSION['auth_user']['UserID'])
                           ? (int)$_SESSION['auth_user']['UserID']
                           : null;

                $st = $conn->prepare("
                  INSERT INTO dbo.AssetVendor
                    (VendorName, VendorAddress, VendorContactPerson, VendorMobileNumber, VendorEmail,
                     IsActive, CreatedAt, CreatedBy)
                  VALUES
                    (:n, :addr, :cp, :mobile, :email, :active, GETDATE(), :by)
                ");
                $st->bindValue(':n',     $VendorName, PDO::PARAM_STR);
                $st->bindValue(':addr',  $VendorAddress, PDO::PARAM_STR);
                if ($VendorContactPerson === '') {
                    $st->bindValue(':cp', null, PDO::PARAM_NULL);
                } else {
                    $st->bindValue(':cp', $VendorContactPerson, PDO::PARAM_STR);
                }
                $st->bindValue(':mobile', $VendorMobileNumber, PDO::PARAM_STR);
                if ($VendorEmail === '') {
                    $st->bindValue(':email', null, PDO::PARAM_NULL);
                } else {
                    $st->bindValue(':email', $VendorEmail, PDO::PARAM_STR);
                }
                $st->bindValue(':active', $IsActive, PDO::PARAM_INT);

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
                $msg = "Create failed: Duplicate vendor.";
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
          SELECT v.VendorID, v.VendorName, v.VendorAddress, v.VendorContactPerson,
                 v.VendorMobileNumber, v.VendorEmail, v.IsActive,
                 v.CreatedAt, v.CreatedBy,
                 u.Username AS CreatedByUsername
            FROM dbo.AssetVendor v
            LEFT JOIN dbo.Users u ON u.UserID = v.CreatedBy
           WHERE v.VendorID = :id
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
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (isset($_POST['act']) ? $_POST['act'] : '') === 'update') {
    check_csrf();

    $VendorID            = isset($_POST['VendorID']) ? (int)$_POST['VendorID'] : 0;
    $VendorName          = isset($_POST['VendorName']) ? trim($_POST['VendorName']) : '';
    $VendorAddress       = isset($_POST['VendorAddress']) ? trim($_POST['VendorAddress']) : '';
    $VendorContactPerson = isset($_POST['VendorContactPerson']) ? trim($_POST['VendorContactPerson']) : '';
    $VendorMobileNumber  = isset($_POST['VendorMobileNumber']) ? trim($_POST['VendorMobileNumber']) : '';
    $VendorEmail         = isset($_POST['VendorEmail']) ? trim($_POST['VendorEmail']) : '';
    $IsActive            = isset($_POST['IsActive']) ? 1 : 0;

    $errors = array();
    if ($VendorID <= 0)           $errors[] = 'Invalid vendor.';
    if ($VendorName === '')       $errors[] = 'Vendor name is required.';
    if ($VendorAddress === '')    $errors[] = 'Vendor address is required.';
    if ($VendorMobileNumber === '') $errors[] = 'Mobile number is required.';
    if ($VendorEmail !== '' && !filter_var($VendorEmail, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Invalid email format.';
    }

    if (empty($errors)) {
        try {
            // Duplicate (ignore self)
            $chk = $conn->prepare("
                SELECT TOP 1 1
                  FROM dbo.AssetVendor
                 WHERE (LOWER(LTRIM(RTRIM(VendorName))) = :n
                     OR VendorMobileNumber = :m)
                   AND VendorID <> :id
            ");
            $chk->execute(array(
                ':n'  => normalize_str($VendorName),
                ':m'  => $VendorMobileNumber,
                ':id' => $VendorID
            ));
            if ($chk->fetchColumn()) {
                $msg      = "Update failed: Duplicate vendor (name/mobile already exists).";
                $msg_type = 'danger';
            } else {
                $st = $conn->prepare("
                  UPDATE dbo.AssetVendor
                     SET VendorName          = :n,
                         VendorAddress       = :addr,
                         VendorContactPerson = :cp,
                         VendorMobileNumber  = :mobile,
                         VendorEmail         = :email,
                         IsActive            = :active
                   WHERE VendorID            = :id
                ");
                $st->bindValue(':n',     $VendorName, PDO::PARAM_STR);
                $st->bindValue(':addr',  $VendorAddress, PDO::PARAM_STR);
                if ($VendorContactPerson === '') {
                    $st->bindValue(':cp', null, PDO::PARAM_NULL);
                } else {
                    $st->bindValue(':cp', $VendorContactPerson, PDO::PARAM_STR);
                }
                $st->bindValue(':mobile', $VendorMobileNumber, PDO::PARAM_STR);
                if ($VendorEmail === '') {
                    $st->bindValue(':email', null, PDO::PARAM_NULL);
                } else {
                    $st->bindValue(':email', $VendorEmail, PDO::PARAM_STR);
                }
                $st->bindValue(':active', $IsActive, PDO::PARAM_INT);
                $st->bindValue(':id',     $VendorID, PDO::PARAM_INT);
                $st->execute();
                header('Location: '.$self);
                exit;
            }
        } catch (PDOException $e) {
            if (is_duplicate_pdo($e)) {
                $msg = "Update failed: Duplicate vendor.";
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

/* TOGGLE ACTIVE */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (isset($_POST['act']) ? $_POST['act'] : '') === 'toggle') {
    check_csrf();

    $id = isset($_POST['VendorID']) ? (int)$_POST['VendorID'] : 0;
    $to = isset($_POST['to']) ? (int)$_POST['to'] : 0;

    if ($id > 0) {
        try {
            $st = $conn->prepare("UPDATE dbo.AssetVendor SET IsActive = :a WHERE VendorID = :id");
            $st->execute(array(':a' => $to, ':id' => $id));
            $msg      = $to ? "Activated." : "Deactivated.";
            $msg_type = 'success';
        } catch (PDOException $e) {
            $msg      = "Toggle failed: " . h($e->getMessage());
            $msg_type = 'danger';
        }
    }
}

/* DELETE */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (isset($_POST['act']) ? $_POST['act'] : '') === 'delete') {
    check_csrf();

    $id = isset($_POST['VendorID']) ? (int)$_POST['VendorID'] : 0;
    if ($id > 0) {
        try {
            $st = $conn->prepare("DELETE FROM dbo.AssetVendor WHERE VendorID = :id");
            $st->execute(array(':id' => $id));
            $msg      = "Vendor deleted.";
            $msg_type = 'success';
        } catch (PDOException $e) {
            $msg      = "Delete failed: " . h($e->getMessage());
            $msg_type = 'danger';
        }
    }
}

/* 5) List + search + status filter */
$search       = isset($_GET['q']) ? trim($_GET['q']) : '';
$statusFilter = isset($_GET['status']) ? trim($_GET['status']) : '';
if ($statusFilter !== '0' && $statusFilter !== '1') {
    $statusFilter = '';
}

try {
    $sql = "
      SELECT v.VendorID, v.VendorName, v.VendorAddress, v.VendorContactPerson,
             v.VendorMobileNumber, v.VendorEmail, v.IsActive,
             v.CreatedAt, v.CreatedBy, u.Username AS CreatedByUsername
        FROM dbo.AssetVendor v
        LEFT JOIN dbo.Users u ON u.UserID = v.CreatedBy
    ";

    $where  = "1=1";
    $params = array();

    if ($search !== '') {
        $where .= " AND (
              v.VendorName        LIKE :q
           OR v.VendorAddress     LIKE :q
           OR v.VendorMobileNumber LIKE :q
           OR v.VendorEmail       LIKE :q
        )";
        $params[':q'] = '%'.$search.'%';
    }

    if ($statusFilter !== '') {
        $where .= " AND v.IsActive = :st";
        $params[':st'] = (int)$statusFilter;
    }

    $sql .= " WHERE ".$where." ORDER BY v.VendorName";

    $st = $conn->prepare($sql);
    $st->execute($params);
    $rows = $st->fetchAll();
} catch (PDOException $e) {
    $rows     = array();
    $msg      = "Load list failed: " . h($e->getMessage());
    $msg_type = 'danger';
}

/* 6) Render */
require_once __DIR__ . '/../../include/header.php';
?>
<!-- Bootstrap Icons (for nice icons on buttons/labels) -->
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">

<style>
  .page-wrap{ margin:24px auto; padding:0 12px; }
  .page-title{
    font-weight:700;
    letter-spacing:.2px;
    display:flex;
    align-items:center;
    gap:.45rem;
  }
  .page-title i{ font-size:1.4rem; color:#2563eb; }

  .card-elevated{
    border:1px solid #e5e7eb;
    border-radius:14px;
    box-shadow:0 8px 22px rgba(15,23,42,.06);
  }

  .badge-soft{
    border:1px solid #e2e8f0;
    background:#f8fafc;
    border-radius:999px;
    padding:4px 10px;
    font-size:12px;
  }

  .btn-brand{
    background:linear-gradient(135deg,#2563eb,#1d4ed8);
    color:#fff!important;
    border:none;
  }
  .btn-brand:hover{ filter:brightness(1.05); }
  .btn-muted{
    background:#e5e7eb;
    color:#111827!important;
    border:none;
  }
  .btn-muted:hover{ background:#d1d5db; }
  .btn-danger-soft{
    background:#fee2e2;
    color:#b91c1c!important;
    border:1px solid #fecaca;
  }
  .btn-danger-soft:hover{ background:#fecaca; }

  .form-label{
    font-weight:600;
    color:#374151;
    display:flex;
    align-items:center;
    gap:.35rem;
    font-size:.9rem;
  }
  .form-label i{ color:#64748b; font-size:.95rem; }

  .form-control, .form-select{
    border-radius:10px;
    border-color:#cbd5e1;
  }

  .action-stack > *{ margin:4px; }
  @media (min-width:768px){
    .action-stack{ display:inline-flex; gap:6px; }
  }

  .table thead th{
    background:#f8fafc;
    color:#334155;
    border-bottom:1px solid #e5e7eb;
    font-size:.8rem;
    white-space:nowrap;
  }
  .table tbody td{
    vertical-align:middle;
    font-size:.85rem;
  }

  .status-badge{
    font-size:.75rem;
    border-radius:999px;
    padding:3px 9px;
    display:inline-flex;
    align-items:center;
    gap:.25rem;
  }
  .status-active{
    background:#ecfdf5;
    color:#15803d;
    border:1px solid #bbf7d0;
  }
  .status-inactive{
    background:#f9fafb;
    color:#6b7280;
    border:1px solid #e5e7eb;
  }

  @media (max-width:575.98px){
    .page-wrap{ margin-top:16px; }
  }
</style>

<div class="page-wrap">

  <!-- Header -->
  <div class="d-flex flex-wrap align-items-center justify-content-between mb-3 gap-2">
    <h1 class="page-title mb-0">
      <i class="bi bi-truck"></i>
      <span>Vendors</span>
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
            <i class="bi bi-pencil-square me-1 text-primary"></i>Edit Vendor
          </h5>
          <a class="btn btn-muted btn-sm" href="<?php echo h($self); ?>">
            <i class="bi bi-x-circle me-1"></i>Cancel
          </a>
        </div>

        <form method="post" accept-charset="UTF-8">
          <input type="hidden" name="csrf" value="<?php echo h($CSRF); ?>">
          <input type="hidden" name="act" value="update">
          <input type="hidden" name="VendorID" value="<?php echo (int)$editRow['VendorID']; ?>">

          <div class="row g-3">
            <div class="col-12 col-md-6">
              <label class="form-label">
                <i class="bi bi-building"></i> Vendor Name
              </label>
              <input type="text" name="VendorName" class="form-control" required maxlength="50"
                     value="<?php echo h($editRow['VendorName']); ?>">
            </div>
            <div class="col-12 col-md-6">
              <label class="form-label">
                <i class="bi bi-geo-alt"></i> Address
              </label>
              <input type="text" name="VendorAddress" class="form-control" required maxlength="50"
                     value="<?php echo h($editRow['VendorAddress']); ?>">
            </div>

            <div class="col-12 col-md-4">
              <label class="form-label">
                <i class="bi bi-person"></i> Contact Person
              </label>
              <input type="text" name="VendorContactPerson" class="form-control" maxlength="50"
                     value="<?php echo h($editRow['VendorContactPerson']); ?>">
            </div>
            <div class="col-12 col-md-4">
              <label class="form-label">
                <i class="bi bi-phone"></i> Mobile
              </label>
              <input type="text" name="VendorMobileNumber" class="form-control" required maxlength="50"
                     value="<?php echo h($editRow['VendorMobileNumber']); ?>">
            </div>
            <div class="col-12 col-md-4">
              <label class="form-label">
                <i class="bi bi-envelope"></i> Email
              </label>
              <input type="email" name="VendorEmail" class="form-control" maxlength="50"
                     value="<?php echo h($editRow['VendorEmail']); ?>">
            </div>

            <div class="col-12">
              <div class="form-check">
                <input class="form-check-input" type="checkbox" name="IsActive" id="isActiveEdit"
                  <?php echo ((int)$editRow['IsActive'] === 1 ? 'checked' : ''); ?>>
                <label class="form-check-label" for="isActiveEdit">Active</label>
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
            <i class="bi bi-plus-circle me-1 text-success"></i>Add Vendor
          </h5>
        </div>

        <form method="post" class="row g-3" accept-charset="UTF-8">
          <input type="hidden" name="csrf" value="<?php echo h($CSRF); ?>">
          <input type="hidden" name="act" value="create">

          <div class="col-12 col-md-6">
            <label class="form-label">
              <i class="bi bi-building"></i> Vendor Name
            </label>
            <input type="text" name="VendorName" class="form-control" required maxlength="50"
                   placeholder="e.g. Tech World Ltd.">
          </div>
          <div class="col-12 col-md-6">
            <label class="form-label">
              <i class="bi bi-geo-alt"></i> Address
            </label>
            <input type="text" name="VendorAddress" class="form-control" required maxlength="50"
                   placeholder="Street / Area">
          </div>

          <div class="col-12 col-md-4">
            <label class="form-label">
              <i class="bi bi-person"></i> Contact Person
            </label>
            <input type="text" name="VendorContactPerson" class="form-control" maxlength="50"
                   placeholder="Optional">
          </div>
          <div class="col-12 col-md-4">
            <label class="form-label">
              <i class="bi bi-phone"></i> Mobile
            </label>
            <input type="text" name="VendorMobileNumber" class="form-control" required maxlength="50"
                   placeholder="01XXXXXXXXX">
          </div>
          <div class="col-12 col-md-4">
            <label class="form-label">
              <i class="bi bi-envelope"></i> Email
            </label>
            <input type="email" name="VendorEmail" class="form-control" maxlength="50"
                   placeholder="vendor@mail.com">
          </div>

          <div class="col-12">
            <div class="form-check">
              <input class="form-check-input" type="checkbox" name="IsActive" id="isActiveCreate" checked>
              <label class="form-check-label" for="isActiveCreate">Active</label>
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
          <i class="bi bi-list-ul me-1"></i>All Vendors
        </h5>
        <span class="text-muted small">
          <i class="bi bi-collection me-1"></i>Total: <?php echo count($rows); ?>
        </span>
      </div>

      <!-- Search + Status filter (above table, with icons) -->
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
                     placeholder="Search name, address, mobile, email..."
                     value="<?php echo h($search); ?>">
            </div>
          </div>

          <div class="col-12 col-md-3">
            <label class="form-label">
              <i class="bi bi-toggle-on"></i> Status
            </label>
            <select name="status" class="form-select">
              <option value="">All</option>
              <option value="1" <?php echo ($statusFilter === '1' ? 'selected' : ''); ?>>Active</option>
              <option value="0" <?php echo ($statusFilter === '0' ? 'selected' : ''); ?>>Inactive</option>
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
              <th>Name</th>
              <th>Address</th>
              <th>Contact</th>
              <th>Mobile</th>
              <th>Email</th>
              <th>Status</th>
              <th>Created</th>
              <th>By</th>
              <th class="text-end">Actions</th>
            </tr>
          </thead>
          <tbody>
          <?php foreach ($rows as $r): ?>
            <tr>
              <td><?php echo (int)$r['VendorID']; ?></td>
              <td><?php echo h($r['VendorName']); ?></td>
              <td><?php echo h($r['VendorAddress']); ?></td>
              <td><?php echo h($r['VendorContactPerson']); ?></td>
              <td><?php echo h($r['VendorMobileNumber']); ?></td>
              <td><?php echo h($r['VendorEmail']); ?></td>
              <td>
                <?php if ((int)$r['IsActive'] === 1): ?>
                  <span class="status-badge status-active">
                    <i class="bi bi-check-circle-fill"></i>Active
                  </span>
                <?php else: ?>
                  <span class="status-badge status-inactive">
                    <i class="bi bi-x-circle-fill"></i>Inactive
                  </span>
                <?php endif; ?>
              </td>
              <td><?php echo h($r['CreatedAt']); ?></td>
              <td><?php echo h(isset($r['CreatedByUsername']) ? $r['CreatedByUsername'] : ''); ?></td>

              <td class="text-end">
                <div class="action-stack">
                  <!-- Edit -->
                  <a class="btn btn-muted btn-sm w-100 w-md-auto"
                     href="<?php echo h($self); ?>?edit=<?php echo (int)$r['VendorID']; ?>"
                     title="Edit vendor">
                    <i class="bi bi-pencil"></i>
                  </a>

                  <!-- Toggle -->
                  <form method="post" class="d-inline"
                        onsubmit="return confirm('Toggle active status?');"
                        accept-charset="UTF-8">
                    <input type="hidden" name="csrf" value="<?php echo h($CSRF); ?>">
                    <input type="hidden" name="act" value="toggle">
                    <input type="hidden" name="VendorID" value="<?php echo (int)$r['VendorID']; ?>">
                    <input type="hidden" name="to" value="<?php echo ((int)$r['IsActive'] === 1 ? 0 : 1); ?>">
                    <button class="btn btn-muted btn-sm w-100 w-md-auto" type="submit"
                            title="Toggle Active/Inactive">
                      <?php if ((int)$r['IsActive'] === 1): ?>
                        <i class="bi bi-toggle-on"></i>
                      <?php else: ?>
                        <i class="bi bi-toggle-off"></i>
                      <?php endif; ?>
                    </button>
                  </form>

                  <!-- Delete -->
                  <form method="post" class="d-inline"
                        onsubmit="return confirm('Delete this vendor permanently?');"
                        accept-charset="UTF-8">
                    <input type="hidden" name="csrf" value="<?php echo h($CSRF); ?>">
                    <input type="hidden" name="act" value="delete">
                    <input type="hidden" name="VendorID" value="<?php echo (int)$r['VendorID']; ?>">
                    <button class="btn btn-danger-soft btn-sm w-100 w-md-auto" type="submit"
                            title="Delete vendor">
                      <i class="bi bi-trash"></i>
                    </button>
                  </form>
                </div>
              </td>
            </tr>
          <?php endforeach; ?>

          <?php if (empty($rows)): ?>
            <tr>
              <td colspan="10" class="text-center text-muted py-4">No data</td>
            </tr>
          <?php endif; ?>

          </tbody>
        </table>
      </div>
    </div>
  </div>

</div><!-- /.page-wrap -->

<?php require_once __DIR__ . '/../../include/footer.php'; ?>
