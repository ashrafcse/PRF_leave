<?php 
/**********************************************
 * Reports dashboard
 * - HR report  (Employees ভিত্তিক)
 * - Asset report (Assets ভিত্তিক)
 * Permission অনুযায়ী সেকশন শো হবে
 **********************************************/
ini_set('display_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/../../init.php';
require_login();

if (!function_exists('h')) {
    function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
}

$hrError    = '';
$assetError = '';

// কোন ইউজার কি দেখতে পারবে
$canHR = (
    can('employees.manage') ||
    can('employees.history') ||
    can('departments.manage') ||
    can('designations.manage') ||
    can('locations.manage')
);

$canAsset = (
    can('assets.manage') ||
    can('assets.history') ||
    can('assets.categories') ||
    can('assets.models') ||
    can('assets.assignments') ||
    can('assets.assignments_history') ||
    can('assets.transfers') ||
    can('assets.m_logs') ||
    can('assets.m_schedules') ||
    can('assets.vendors') ||
    can('assets.notifications')
);

// ============ HR REPORT DATA ============ //
$hrSummary       = array();
$hrByDepartment  = array();
$hrByLocation    = array();

if ($canHR) {
    try {
        // মোট employee + status wise count
        $sql = "
          SELECT 
            COUNT(*)                                            AS total,
            SUM(CASE WHEN Status = 'Active'     THEN 1 ELSE 0 END) AS active,
            SUM(CASE WHEN Status = 'Inactive'   THEN 1 ELSE 0 END) AS inactive,
            SUM(CASE WHEN Status = 'On Leave'   THEN 1 ELSE 0 END) AS on_leave,
            SUM(CASE WHEN Status = 'Terminated' THEN 1 ELSE 0 END) AS terminated
          FROM dbo.Employees
        ";
        $hrSummary = $conn->query($sql)->fetch(PDO::FETCH_ASSOC);

        // Department wise employee count
        $sql = "
          SELECT d.DepartmentName, COUNT(*) AS total
          FROM dbo.Employees e
          JOIN dbo.Departments d ON d.DepartmentID = e.DepartmentID
          GROUP BY d.DepartmentName
          ORDER BY d.DepartmentName
        ";
        $hrByDepartment = $conn->query($sql)->fetchAll(PDO::FETCH_ASSOC);

        // Location wise employee count
        $sql = "
          SELECT l.LocationName, COUNT(*) AS total
          FROM dbo.Employees e
          JOIN dbo.Locations l ON l.LocationID = e.LocationID
          GROUP BY l.LocationName
          ORDER BY l.LocationName
        ";
        $hrByLocation = $conn->query($sql)->fetchAll(PDO::FETCH_ASSOC);

    } catch (PDOException $e) {
        $hrError = 'HR report load failed: '.h($e->getMessage());
    }
}

// ============ ASSET REPORT DATA ============ //
$assetSummary      = array();
$assetStatusRows   = array();
$assetByCategory   = array();
$assetByLocation   = array();
$topHolderRows     = array();

if ($canAsset) {
    try {
        // মোট asset + assigned / unassigned
        $sql = "
          SELECT 
            COUNT(*) AS total,
            SUM(CASE WHEN CurrentHolderEmployeeID IS NOT NULL THEN 1 ELSE 0 END) AS assigned,
            SUM(CASE WHEN CurrentHolderEmployeeID IS NULL      THEN 1 ELSE 0 END) AS unassigned
          FROM dbo.Assets
        ";
        $assetSummary = $conn->query($sql)->fetch(PDO::FETCH_ASSOC);

        // Status wise asset count
        $sql = "
          SELECT ISNULL(Status,'(Not set)') AS Status, COUNT(*) AS total
          FROM dbo.Assets
          GROUP BY ISNULL(Status,'(Not set)')
          ORDER BY Status
        ";
        $assetStatusRows = $conn->query($sql)->fetchAll(PDO::FETCH_ASSOC);

        // Category wise asset count
        $sql = "
          SELECT c.CategoryName, COUNT(*) AS total
          FROM dbo.Assets a
          JOIN dbo.AssetCategories c ON c.CategoryID = a.CategoryID
          GROUP BY c.CategoryName
          ORDER BY c.CategoryName
        ";
        $assetByCategory = $conn->query($sql)->fetchAll(PDO::FETCH_ASSOC);

        // Location wise asset count
        $sql = "
          SELECT l.LocationName, COUNT(*) AS total
          FROM dbo.Assets a
          JOIN dbo.Locations l ON l.LocationID = a.CurrentLocationID
          GROUP BY l.LocationName
          ORDER BY l.LocationName
        ";
        $assetByLocation = $conn->query($sql)->fetchAll(PDO::FETCH_ASSOC);

        // Top 10 asset holders (employee)
        $sql = "
          SELECT TOP 10
            e.EmployeeID,
            e.EmployeeCode,
            LTRIM(RTRIM(e.FirstName + ' ' + ISNULL(e.LastName,''))) AS FullName,
            COUNT(a.AssetID) AS AssetCount
          FROM dbo.Employees e
          JOIN dbo.Assets a ON a.CurrentHolderEmployeeID = e.EmployeeID
          GROUP BY e.EmployeeID, e.EmployeeCode, e.FirstName, e.LastName
          ORDER BY AssetCount DESC, FullName
        ";
        $topHolderRows = $conn->query($sql)->fetchAll(PDO::FETCH_ASSOC);

    } catch (PDOException $e) {
        $assetError = 'Asset report load failed: '.h($e->getMessage());
    }
}

/* ---------- View ---------- */
require_once __DIR__ . '/../../include/header.php';
?>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">

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
    opacity:0.3;
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

  .stat-card{
    padding:14px 14px;
    border-radius:14px;
    border:1px solid #e5e7eb;
    background:#f9fafb;
    position:relative;
    display:flex;
    align-items:center;
    gap:12px;
    overflow:hidden;
  }
  .stat-card::after{
    content:"";
    position:absolute;
    width:110px;
    height:110px;
    right:-40px;
    top:-40px;
    background:radial-gradient(circle at center, rgba(59,130,246,.25), transparent 60%);
    opacity:.3;
  }
  .stat-main{
    position:relative;
    z-index:1;
  }
  .stat-icon{
    width:34px;
    height:34px;
    border-radius:999px;
    display:inline-flex;
    align-items:center;
    justify-content:center;
    background:#1d4ed8;
    color:#eff6ff;
    font-size:16px;
    box-shadow:0 10px 18px rgba(37,99,235,.45);
    position:relative;
    z-index:1;
  }
  .stat-title{
    font-size:11px;
    text-transform:uppercase;
    letter-spacing:.08em;
    color:#64748b;
    margin-bottom:2px;
  }
  .stat-value{
    font-size:22px;
    font-weight:700;
    color:#0f172a;
    margin-top:0;
  }
  .stat-caption{
    font-size:12px;
    color:#6b7280;
    margin-top:2px;
  }

  .table thead th{
    background:#f8fafc;
    color:#334155;
    border-bottom:1px solid #e5e7eb;
    font-size:13px;
    text-transform:uppercase;
    letter-spacing:.04em;
  }
  .table tbody td{
    vertical-align:middle;
    font-size:13px;
  }
  .table-hover tbody tr:hover{
    background-color:#f1f5f9;
  }

  .table-section-title{
    display:flex;
    align-items:center;
    gap:8px;
    font-weight:600;
    color:#0f172a;
    margin-bottom:6px;
  }
  .table-section-title-icon{
    width:22px;
    height:22px;
    border-radius:999px;
    background:#e0f2fe;
    color:#0369a1;
    display:inline-flex;
    align-items:center;
    justify-content:center;
    font-size:13px;
  }

  .hint-link{
    margin-top:8px;
    font-size:12px;
    color:#6b7280;
  }
  .hint-link a{
    color:#2563eb;
    font-weight:500;
  }
  .hint-link a:hover{
    text-decoration:underline;
  }

  @media (max-width:576px){
    .stat-card{
      padding:10px 11px;
    }
    .stat-value{
      font-size:18px;
    }
  }
</style>

<div class="page-wrap">
  <h1 class="page-title mb-3">
    <span class="page-title-badge">
      <i class="bi bi-graph-up"></i>
    </span>
    <span>Reports</span>
  </h1>

  <?php if ($hrError): ?>
    <div class="alert alert-danger"><?php echo $hrError; ?></div>
  <?php endif; ?>

  <?php if ($assetError): ?>
    <div class="alert alert-danger"><?php echo $assetError; ?></div>
  <?php endif; ?>

  <?php if (!$canHR && !$canAsset): ?>
    <div class="alert alert-warning mb-0">
      <i class="bi bi-exclamation-triangle-fill"></i>
      You do not have permission to view reports.
    </div>
  <?php endif; ?>

  <!-- ================== HR REPORT (HR Role) ================== -->
  <?php if ($canHR): ?>
  <div class="card card-elevated mb-4">
    <div class="card-body">
      <div class="section-header">
        <div>
          <div class="section-title">
            <span class="section-title-icon">
              <i class="bi bi-people-fill"></i>
            </span>
            <span>HR Report (Employees)</span>
          </div>
          <div class="section-sub">
            Employees ভিত্তিক high-level summary
          </div>
        </div>
      </div>

      <div class="row g-3 mb-3">
        <div class="col-6 col-md-3">
          <div class="stat-card">
            <span class="stat-icon">
              <i class="bi bi-people"></i>
            </span>
            <div class="stat-main">
              <div class="stat-title">Total Employees</div>
              <div class="stat-value">
                <?php echo isset($hrSummary['total']) ? (int)$hrSummary['total'] : 0; ?>
              </div>
            </div>
          </div>
        </div>

        <div class="col-6 col-md-3">
          <div class="stat-card">
            <span class="stat-icon" style="background:#16a34a;box-shadow:0 10px 18px rgba(22,163,74,.45);">
              <i class="bi bi-person-check"></i>
            </span>
            <div class="stat-main">
              <div class="stat-title">Active</div>
              <div class="stat-value">
                <?php echo isset($hrSummary['active']) ? (int)$hrSummary['active'] : 0; ?>
              </div>
            </div>
          </div>
        </div>

        <div class="col-6 col-md-3">
          <div class="stat-card">
            <span class="stat-icon" style="background:#eab308;box-shadow:0 10px 18px rgba(234,179,8,.45);">
              <i class="bi bi-person-dash"></i>
            </span>
            <div class="stat-main">
              <div class="stat-title">On Leave</div>
              <div class="stat-value">
                <?php echo isset($hrSummary['on_leave']) ? (int)$hrSummary['on_leave'] : 0; ?>
              </div>
            </div>
          </div>
        </div>

        <div class="col-6 col-md-3">
          <div class="stat-card">
            <span class="stat-icon" style="background:#dc2626;box-shadow:0 10px 18px rgba(220,38,38,.45);">
              <i class="bi bi-person-x"></i>
            </span>
            <div class="stat-main">
              <div class="stat-title">Inactive / Terminated</div>
              <div class="stat-value">
                <?php
                  $inactive   = isset($hrSummary['inactive'])   ? (int)$hrSummary['inactive']   : 0;
                  $terminated = isset($hrSummary['terminated']) ? (int)$hrSummary['terminated'] : 0;
                  echo $inactive + $terminated;
                ?>
              </div>
              <div class="stat-caption">
                Inactive: <?php echo $inactive; ?> &nbsp;|&nbsp; Terminated: <?php echo $terminated; ?>
              </div>
            </div>
          </div>
        </div>
      </div>

      <div class="row g-4">
        <div class="col-md-6">
          <div class="table-section-title">
            <span class="table-section-title-icon">
              <i class="bi bi-diagram-3"></i>
            </span>
            <span>Employees by Department</span>
          </div>
          <div class="table-responsive">
            <table class="table table-sm table-hover">
              <thead>
                <tr>
                  <th>Department</th>
                  <th class="text-end">Employees</th>
                </tr>
              </thead>
              <tbody>
              <?php foreach ($hrByDepartment as $r): ?>
                <tr>
                  <td><?php echo h($r['DepartmentName']); ?></td>
                  <td class="text-end">
                    <span class="badge bg-light text-dark">
                      <?php echo (int)$r['total']; ?>
                    </span>
                  </td>
                </tr>
              <?php endforeach; ?>
              <?php if (empty($hrByDepartment)): ?>
                <tr><td colspan="2" class="text-center text-muted">No data</td></tr>
              <?php endif; ?>
              </tbody>
            </table>
          </div>
        </div>

        <div class="col-md-6">
          <div class="table-section-title">
            <span class="table-section-title-icon">
              <i class="bi bi-geo-alt"></i>
            </span>
            <span>Employees by Location</span>
          </div>
          <div class="table-responsive">
            <table class="table table-sm table-hover">
              <thead>
                <tr>
                  <th>Location</th>
                  <th class="text-end">Employees</th>
                </tr>
              </thead>
              <tbody>
              <?php foreach ($hrByLocation as $r): ?>
                <tr>
                  <td><?php echo h($r['LocationName']); ?></td>
                  <td class="text-end">
                    <span class="badge bg-light text-dark">
                      <?php echo (int)$r['total']; ?>
                    </span>
                  </td>
                </tr>
              <?php endforeach; ?>
              <?php if (empty($hrByLocation)): ?>
                <tr><td colspan="2" class="text-center text-muted">No data</td></tr>
              <?php endif; ?>
              </tbody>
            </table>
          </div>
        </div>
      </div>

      <div class="hint-link">
        Detailed employee list দেখতে চাইলে:
        <a href="<?php echo h(BASE_URL.'pages/employee/employee_current_list.php'); ?>">
          Employee Current List
        </a>
      </div>
    </div>
  </div>
  <?php endif; ?>

  <!-- ================== ASSET REPORT (Asset Manager) ================== -->
  <?php if ($canAsset): ?>
  <div class="card card-elevated mb-4">
    <div class="card-body">
      <div class="section-header">
        <div>
          <div class="section-title">
            <span class="section-title-icon" style="background:rgba(16,185,129,.10);color:#059669;">
              <i class="bi bi-hdd-stack"></i>
            </span>
            <span>Asset Report</span>
          </div>
          <div class="section-sub">
            Assets ভিত্তিক summary (assigned/unassigned, status, category ইত্যাদি)
          </div>
        </div>
      </div>

      <div class="row g-3 mb-3">
        <div class="col-6 col-md-3">
          <div class="stat-card">
            <span class="stat-icon" style="background:#0f766e;box-shadow:0 10px 18px rgba(15,118,110,.45);">
              <i class="bi bi-box-seam"></i>
            </span>
            <div class="stat-main">
              <div class="stat-title">Total Assets</div>
              <div class="stat-value">
                <?php echo isset($assetSummary['total']) ? (int)$assetSummary['total'] : 0; ?>
              </div>
            </div>
          </div>
        </div>

        <div class="col-6 col-md-3">
          <div class="stat-card">
            <span class="stat-icon" style="background:#4f46e5;box-shadow:0 10px 18px rgba(79,70,229,.45);">
              <i class="bi bi-person-badge"></i>
            </span>
            <div class="stat-main">
              <div class="stat-title">Assigned</div>
              <div class="stat-value">
                <?php echo isset($assetSummary['assigned']) ? (int)$assetSummary['assigned'] : 0; ?>
              </div>
            </div>
          </div>
        </div>

        <div class="col-6 col-md-3">
          <div class="stat-card">
            <span class="stat-icon" style="background:#38bdf8;box-shadow:0 10px 18px rgba(56,189,248,.45);">
              <i class="bi bi-inboxes"></i>
            </span>
            <div class="stat-main">
              <div class="stat-title">Unassigned</div>
              <div class="stat-value">
                <?php echo isset($assetSummary['unassigned']) ? (int)$assetSummary['unassigned'] : 0; ?>
              </div>
            </div>
          </div>
        </div>

        <div class="col-6 col-md-3">
          <div class="stat-card">
            <span class="stat-icon" style="background:#7c3aed;box-shadow:0 10px 18px rgba(124,58,237,.45);">
              <i class="bi bi-layers"></i>
            </span>
            <div class="stat-main">
              <div class="stat-title">Status Types</div>
              <div class="stat-value"><?php echo count($assetStatusRows); ?></div>
              <div class="stat-caption">From Assets.Status</div>
            </div>
          </div>
        </div>
      </div>

      <div class="row g-4">
        <div class="col-md-6">
          <div class="table-section-title">
            <span class="table-section-title-icon">
              <i class="bi bi-sliders2"></i>
            </span>
            <span>Assets by Status</span>
          </div>
          <div class="table-responsive">
            <table class="table table-sm table-hover">
              <thead>
                <tr>
                  <th>Status</th>
                  <th class="text-end">Assets</th>
                </tr>
              </thead>
              <tbody>
              <?php foreach ($assetStatusRows as $r): ?>
                <tr>
                  <td><?php echo h($r['Status']); ?></td>
                  <td class="text-end">
                    <span class="badge bg-light text-dark">
                      <?php echo (int)$r['total']; ?>
                    </span>
                  </td>
                </tr>
              <?php endforeach; ?>
              <?php if (empty($assetStatusRows)): ?>
                <tr><td colspan="2" class="text-center text-muted">No data</td></tr>
              <?php endif; ?>
              </tbody>
            </table>
          </div>
        </div>

        <div class="col-md-6">
          <div class="table-section-title">
            <span class="table-section-title-icon">
              <i class="bi bi-grid-1x2"></i>
            </span>
            <span>Assets by Category</span>
          </div>
          <div class="table-responsive">
            <table class="table table-sm table-hover">
              <thead>
                <tr>
                  <th>Category</th>
                  <th class="text-end">Assets</th>
                </tr>
              </thead>
              <tbody>
              <?php foreach ($assetByCategory as $r): ?>
                <tr>
                  <td><?php echo h($r['CategoryName']); ?></td>
                  <td class="text-end">
                    <span class="badge bg-light text-dark">
                      <?php echo (int)$r['total']; ?>
                    </span>
                  </td>
                </tr>
              <?php endforeach; ?>
              <?php if (empty($assetByCategory)): ?>
                <tr><td colspan="2" class="text-center text-muted">No data</td></tr>
              <?php endif; ?>
              </tbody>
            </table>
          </div>
        </div>

        <div class="col-md-6">
          <div class="table-section-title">
            <span class="table-section-title-icon">
              <i class="bi bi-geo-alt"></i>
            </span>
            <span>Assets by Location</span>
          </div>
          <div class="table-responsive">
            <table class="table table-sm table-hover">
              <thead>
                <tr>
                  <th>Location</th>
                  <th class="text-end">Assets</th>
                </tr>
              </thead>
              <tbody>
              <?php foreach ($assetByLocation as $r): ?>
                <tr>
                  <td><?php echo h($r['LocationName']); ?></td>
                  <td class="text-end">
                    <span class="badge bg-light text-dark">
                      <?php echo (int)$r['total']; ?>
                    </span>
                  </td>
                </tr>
              <?php endforeach; ?>
              <?php if (empty($assetByLocation)): ?>
                <tr><td colspan="2" class="text-center text-muted">No data</td></tr>
              <?php endif; ?>
              </tbody>
            </table>
          </div>
        </div>

        <div class="col-md-6">
          <div class="table-section-title">
            <span class="table-section-title-icon">
              <i class="bi bi-person-lines-fill"></i>
            </span>
            <span>Top 10 Asset Holders (Employees)</span>
          </div>
          <div class="table-responsive">
            <table class="table table-sm table-hover">
              <thead>
                <tr>
                  <th>PRF ID</th>
                  <th>Name</th>
                  <th class="text-end">Assets</th>
                </tr>
              </thead>
              <tbody>
              <?php foreach ($topHolderRows as $r): ?>
                <tr>
                  <td><?php echo h($r['EmployeeCode']); ?></td>
                  <td><?php echo h($r['FullName']); ?></td>
                  <td class="text-end">
                    <span class="badge bg-light text-dark">
                      <?php echo (int)$r['AssetCount']; ?>
                    </span>
                  </td>
                </tr>
              <?php endforeach; ?>
              <?php if (empty($topHolderRows)): ?>
                <tr><td colspan="3" class="text-center text-muted">No data</td></tr>
              <?php endif; ?>
              </tbody>
            </table>
          </div>
        </div>
      </div>

      <div class="hint-link">
        Full asset list দেখতে চাইলে:
        <a href="<?php echo h(BASE_URL.'pages/assets/assets.php'); ?>">Assets</a>
      </div>
    </div>
  </div>
  <?php endif; ?>
</div>

<?php require_once __DIR__ . '/../../include/footer.php'; ?>
