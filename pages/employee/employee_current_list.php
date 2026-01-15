<?php
/*************************************************
 * Current Employee List (read-only list + modal)
 * Table: dbo.Employees  (SQL Server, PDO)
 * PHP 5.6 compatible
 *************************************************/
ini_set('display_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/../../init.php';
require_login();

/* ---------- Tiny helpers ---------- */
if (!function_exists('h')) {
    function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
}
function self_name(){ return strtok(basename($_SERVER['SCRIPT_NAME']), "?"); }
$self = self_name();
function errfmt(PDOException $e){
    $s = $e->getCode();
    $m = $e->getMessage();
    $c = (preg_match('/constraint\s+\'?([^\']+)/i', $m, $mm) ? " | Constraint: ".$mm[1] : "");
    return "SQLSTATE {$s}{$c} | {$m}";
}

/* ---------- Config ---------- */
$employeeEditPage = 'employees.php'; // existing full CRUD page

/* ---------- Static option arrays ---------- */
$BLOOD_GROUPS = array('A+','A-','B+','B-','O+','O-','AB+','AB-');

$INCREMENT_MONTHS = array(
    '1' => 'January',
    '7' => 'July'
);

$JOB_TYPES = array('Fixed term','Contractual');

$SALARY_LEVELS = array(
    'GS-1','GS-2','GS-3','GS-4','GS-5','GS-6','GS-7',
    'NO-A','NO-B','NO-C','NO-D','NO-E'
);

/* ---------- CSRF ---------- */
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

/* ---------- Alerts ---------- */
$msg = '';
$msg_type = 'success';

/* ---------- Filters: search + 5 dropdowns ---------- */
$filter_q      = isset($_GET['q'])      ? trim($_GET['q'])      : '';
$filter_blood  = isset($_GET['blood'])  ? trim($_GET['blood'])  : '';
$filter_inc    = isset($_GET['inc'])    ? trim($_GET['inc'])    : '';
$filter_job    = isset($_GET['job'])    ? trim($_GET['job'])    : '';
$filter_level  = isset($_GET['level'])  ? trim($_GET['level'])  : '';
$filter_step   = isset($_GET['step'])   ? trim($_GET['step'])   : '';

/* ---------- Status toggle / Delete (actions from modal) ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $act = isset($_POST['act']) ? $_POST['act'] : '';

    if ($act === 'toggle') {
        check_csrf();
        $id = (int)(isset($_POST['EmployeeID']) ? $_POST['EmployeeID'] : 0);
        $to = isset($_POST['to']) ? $_POST['to'] : 'Active';

        if ($id > 0) {
            try {
                $stmt = $conn->prepare("UPDATE dbo.Employees SET Status = :s WHERE EmployeeID = :id");
                $stmt->execute(array(':s' => $to, ':id' => $id));
                $msg = ($to === 'Active' ? 'Employee activated.' : 'Employee deactivated.');
                $msg_type = 'success';
            } catch (PDOException $e) {
                $msg = "Status change failed: " . h(errfmt($e));
                $msg_type = 'danger';
            }
        }
    }

    if ($act === 'delete') {
        check_csrf();
        $id = (int)(isset($_POST['EmployeeID']) ? $_POST['EmployeeID'] : 0);

        if ($id > 0) {
            try {
                $stmt = $conn->prepare("DELETE FROM dbo.Employees WHERE EmployeeID = :id");
                $stmt->execute(array(':id' => $id));
                $msg = "Employee deleted.";
                $msg_type = 'success';
            } catch (PDOException $e) {
                $msg = "Delete failed: " . h(errfmt($e));
                $msg_type = 'danger';
            }
        }
    }
}

/* ---------- Load list (Current list of employees) ---------- */
try {
    $where  = array();
    $params = array();

    if ($filter_q !== '') {
        $where[] = "(e.EmployeeCode LIKE :q
                 OR e.FirstName LIKE :q
                 OR e.LastName  LIKE :q
                 OR e.Email_Office LIKE :q
                 OR e.Email_Personal LIKE :q)";
        $params[':q'] = '%'.$filter_q.'%';
    }
    if ($filter_blood !== '') {
        $where[] = "e.Blood_group = :bg";
        $params[':bg'] = $filter_blood;
    }
    if ($filter_inc !== '') {
        $where[] = "e.Salary_increment_month = :inc";
        $params[':inc'] = $filter_inc;
    }
    if ($filter_job !== '') {
        $where[] = "e.Job_Type = :job";
        $params[':job'] = $filter_job;
    }
    if ($filter_level !== '') {
        $where[] = "e.Salary_level = :lvl";
        $params[':lvl'] = $filter_level; // varchar(10)
    }
    if ($filter_step !== '') {
        $where[] = "e.Salary_Steps = :stp";
        $params[':stp'] = (int)$filter_step; // int
    }

    $sql = "
      SELECT
        e.EmployeeID,
        e.EmployeeCode,
        e.FirstName,
        e.LastName,
        e.NationalID,
        e.Status,
        e.JobTitleID,
        e.DepartmentID,
        e.LocationID,
        e.SupervisorID_admin,
        e.SupervisorID_technical,
        e.Email_Office,
        e.Email_Personal,
        e.Phone1,
        e.Phone2,
        e.HireDate,
        e.EndDate,
        e.DOB,
        e.Blood_group,
        e.Salary_increment_month,
        e.Job_Type,
        e.Salary_level,
        e.Salary_Steps,
        e.CreatedAt,
        jt.JobTitleName,
        d.DepartmentName,
        l.LocationName,
        sa.FirstName + ' ' + ISNULL(sa.LastName,'') AS AdminSupervisorName
      FROM dbo.Employees e
      LEFT JOIN dbo.Designation jt ON jt.JobTitleID = e.JobTitleID
      LEFT JOIN dbo.Departments d  ON d.DepartmentID = e.DepartmentID
      LEFT JOIN dbo.Locations l    ON l.LocationID = e.LocationID
      LEFT JOIN dbo.Employees sa   ON sa.EmployeeID = e.SupervisorID_admin
    ";

    if (!empty($where)) {
        $sql .= " WHERE " . implode(" AND ", $where);
    }

    $sql .= " ORDER BY e.EmployeeID DESC"; // latest first

    if (!empty($params)) {
        $st = $conn->prepare($sql);
        $st->execute($params);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    } else {
        $rows = $conn->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (PDOException $e) {
    $rows = array();
    $msg = "Load list failed: " . h(errfmt($e));
    $msg_type = 'danger';
}

/* ---------- View ---------- */
require_once __DIR__ . '/../../include/header.php';
?>
<style>
  .page-wrap {
      max-width: 100%;
      margin: 28px auto;
      padding: 0 16px 32px;
  }

  .page-title {
      font-weight: 700;
      letter-spacing: .2px;
      color: #0f172a;
      display: flex;
      align-items: center;
      gap: 8px;
  }
  .page-title i{
      font-size: 22px;
      color: #4f46e5;
  }

  .page-subtitle{
      font-size: 13px;
      color: #6b7280;
  }

  .card-elevated {
      border-radius: 16px;
      border: 1px solid #e5e7eb;
      box-shadow: 0 18px 45px rgba(15, 23, 42, 0.12);
      overflow: hidden;
  }
  .card-elevated .card-body{
      background: radial-gradient(circle at top left, #eff6ff 0, #ffffff 45%, #f9fafb 100%);
  }

  .badge-soft {
      border-radius: 999px;
      padding: 5px 12px;
      font-size: 12px;
      font-weight: 500;
      color: #0f172a;
      background: #e0f2fe;
      border: 1px solid #bae6fd;
      display: inline-flex;
      align-items: center;
      gap: 6px;
  }
  .badge-soft i{
      font-size: 0.85rem;
      color: #0284c7;
  }

  .btn-brand {
      background: linear-gradient(135deg, #6366f1, #2563eb);
      color: #fff !important;
      border: none;
      padding: 0.55rem 1.4rem;
      font-weight: 600;
      border-radius: 999px;
      display: inline-flex;
      align-items: center;
      gap: 8px;
      box-shadow: 0 12px 25px rgba(37, 99, 235, 0.35);
      transition: all .15s ease-in-out;
  }
  .btn-brand i{
      font-size: 0.95rem;
  }
  .btn-brand:hover {
      background: linear-gradient(135deg, #4f46e5, #1d4ed8);
      transform: translateY(-1px);
      box-shadow: 0 16px 32px rgba(30, 64, 175, 0.45);
  }

  .btn-muted {
      background:#e5e7eb;
      color:#111827!important;
      border:none;
      border-radius: 999px;
      padding: 0.45rem 1.1rem;
      font-weight: 500;
      display: inline-flex;
      align-items: center;
      gap: 6px;
  }
  .btn-muted i{
      font-size: 0.9rem;
  }
  .btn-muted:hover{
      background:#d1d5db;
  }

  .btn-danger-soft{
      background:#fee2e2;
      color:#b91c1c!important;
      border:1px solid #fecaca;
      border-radius: 999px;
      padding: 0.45rem 1.1rem;
      font-weight: 500;
      display: inline-flex;
      align-items: center;
      gap: 6px;
  }
  .btn-danger-soft i{
      font-size: 0.9rem;
  }
  .btn-danger-soft:hover{
      background:#fecaca;
  }

  .form-label{
      font-weight:600;
      color:#374151;
      font-size: 13px;
  }
  .form-control, .form-select{
      border-radius:10px;
      border-color:#cbd5e1;
      font-size: 14px;
  }

  .section-title{
      font-weight:600;
      color:#111827;
      display:flex;
      align-items:center;
      gap:8px;
  }
  .section-title i{
      color:#4f46e5;
      font-size: 1rem;
  }

  .table thead th{
      background:#f9fafb;
      color:#4b5563;
      border-bottom:1px solid #e5e7eb;
      font-size:12px;
      text-transform:uppercase;
      letter-spacing:.04em;
      white-space: nowrap;
  }
  .table tbody td{
      vertical-align:middle;
      font-size:13px;
      color:#111827;
  }
  .table-hover tbody tr:hover{
      background-color:#eff6ff;
  }

  .status-pill{
      display: inline-flex;
      align-items: center;
      gap: 6px;
      padding: 3px 10px;
      border-radius: 999px;
      font-size: 11px;
      font-weight: 500;
  }
  .status-pill .status-dot{
      width: 8px;
      height: 8px;
      border-radius: 999px;
      background: #22c55e;
  }
  .status-pill-active{
      background: #ecfdf3;
      color: #166534;
  }
  .status-pill-inactive{
      background: #fef2f2;
      color: #b91c1c;
  }
  .status-pill-inactive .status-dot{
      background: #ef4444;
  }
  .status-pill-other{
      background:#eff6ff;
      color:#1d4ed8;
  }
  .status-pill-other .status-dot{
      background:#3b82f6;
  }

  .filters-helper{
      font-size:12px;
      color:#6b7280;
  }

  .modal-header{
      border-bottom:1px solid #e5e7eb;
  }
  .modal-title{
      font-weight:600;
      color:#1e3a8a;
  }
  #modalEmpSubTitle{
      font-size:12px;
      color:#6b7280;
  }
</style>

<div class="page-wrap">
  <div class="d-flex flex-wrap align-items-center justify-content-between mb-3 gap-2">
    <div>
      <h1 class="page-title mb-1">
        <i class="fas fa-address-card"></i>
        Employees
      </h1>
      <!-- <div class="page-subtitle">
        Current list of PRF employees – quick view with details & actions.
      </div> -->
    </div>
    <span class="badge-soft">
      <i class="fas fa-users"></i>
      Total Employees: <?php echo count($rows); ?>
    </span>
  </div>

  <?php if ($msg): ?>
    <div class="alert alert-<?php echo ($msg_type === 'danger' ? 'danger' : 'success'); ?> alert-dismissible fade show shadow-sm" role="alert">
      <?php echo $msg; ?>
      <button type="button" class="btn-close" data-bs-dismiss="alert" data-dismiss="alert" aria-label="Close"></button>
    </div>
  <?php endif; ?>

  <!-- List + Filters -->
  <div class="card card-elevated">
    <div class="card-body">
      <div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-2">
        <div class="section-title mb-0" style="    margin-bottom: 7px !important;
    margin-left: -10px;">
          <i class="fas fa-list-ul"></i>
          <span>Current list of employees</span>
        </div>
        
      </div>

      <!-- Filters row -->
      <form method="get" class="row g-2 mb-3" accept-charset="UTF-8">
        <div class="col-12 col-md-3">
          <label class="form-label small mb-1">Search (PRF ID / Name / Email)</label>
          <input type="text" name="q" class="form-control"
                 value="<?php echo h($filter_q); ?>" placeholder="Type to search...">
        </div>

        <div class="col-6 col-md-2">
          <label class="form-label small mb-1">Blood Group</label>
          <select name="blood" class="form-select">
            <option value="">All</option>
            <?php foreach ($BLOOD_GROUPS as $bg): ?>
              <option value="<?php echo h($bg); ?>" <?php echo ($filter_blood === $bg ? 'selected' : ''); ?>>
                <?php echo h($bg); ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="col-6 col-md-2">
          <label class="form-label small mb-1">Increment Month</label>
          <select name="inc" class="form-select">
            <option value="">All</option>
            <?php foreach ($INCREMENT_MONTHS as $code=>$label): ?>
              <option value="<?php echo h($code); ?>" <?php echo ($filter_inc === $code ? 'selected' : ''); ?>>
                <?php echo h($label); ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="col-6 col-md-2">
          <label class="form-label small mb-1">Job Type</label>
          <select name="job" class="form-select">
            <option value="">All</option>
            <?php foreach ($JOB_TYPES as $jt): ?>
              <option value="<?php echo h($jt); ?>" <?php echo ($filter_job === $jt ? 'selected' : ''); ?>>
                <?php echo h($jt); ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>

        <!-- <div class="col-6 col-md-2">
          <label class="form-label small mb-1">Salary Level</label>
          <select name="level" class="form-select">
            <option value="">All</option>
            <?php foreach ($SALARY_LEVELS as $lvl): ?>
              <option value="<?php echo h($lvl); ?>" <?php echo ($filter_level === $lvl ? 'selected' : ''); ?>>
                <?php echo h($lvl); ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div> -->

        <!-- <div class="col-6 col-md-1">
          <label class="form-label small mb-1">Step</label>
          <select name="step" class="form-select">
            <option value="">All</option>
            <?php for ($i=1; $i<=15; $i++): ?>
              <option value="<?php echo $i; ?>" <?php echo ((string)$filter_step === (string)$i ? 'selected' : ''); ?>>
                Step-<?php echo $i; ?>
              </option>
            <?php endfor; ?>
          </select>
        </div> -->

        <div class="col-12 d-flex gap-2 mt-1" style="    margin-top: 14px !important;">
          <button class="btn btn-brand" type="submit">
            <i class="fas fa-search"></i>
            Apply Filters
          </button>
          <a class="btn btn-muted" href="<?php echo h($self); ?>">
            <i class="fas fa-undo"></i>
            Reset
          </a>
        </div>
      </form>

      <!-- Current list of employees table -->
      <div class="table-responsive">
        <table class="table table-hover align-middle">
          <thead>
            <tr>
              <th>#</th>
              <th>PRF ID</th>
              <th>Name</th>
              <th>Designation</th>
              <th>Department</th>
              <th>Location</th>
              <th>Email (PRF)</th>
              <th>Email (Personal)</th>
              <th>Joining Date</th>
              <th>Immediate Supervisor</th>
              <!-- <th>Salary Level</th>
              <th>Salary Step</th> -->
              <th>Status</th>
              <!-- <th class="text-end">View</th> -->
            </tr>
          </thead>
          <tbody>
          <?php foreach ($rows as $r): ?>
            <?php
              $fullName = trim($r['FirstName'].' '.(isset($r['LastName']) ? $r['LastName'] : ''));
              $status = (string)$r['Status'];
              $pillClass = 'status-pill-other';
              if ($status === 'Active') {
                  $pillClass = 'status-pill-active';
              } elseif ($status === 'Inactive') {
                  $pillClass = 'status-pill-inactive';
              }

              $modalData = array(
                  'EmployeeID'           => (int)$r['EmployeeID'],
                  'EmployeeCode'         => $r['EmployeeCode'],
                  'FirstName'            => $r['FirstName'],
                  'LastName'             => $r['LastName'],
                  'FullName'             => $fullName,
                  'NationalID'           => $r['NationalID'],
                  'Status'               => $r['Status'],
                  'JobTitleName'         => $r['JobTitleName'],
                  'DepartmentName'       => $r['DepartmentName'],
                  'LocationName'         => $r['LocationName'],
                  'Email_Office'         => $r['Email_Office'],
                  'Email_Personal'       => $r['Email_Personal'],
                  'Phone1'               => $r['Phone1'],
                  'Phone2'               => $r['Phone2'],
                  'HireDate'             => substr((string)$r['HireDate'],0,10),
                  'EndDate'              => substr((string)$r['EndDate'],0,10),
                  'DOB'                  => substr((string)$r['DOB'],0,10),
                  'Blood_group'          => $r['Blood_group'],
                  'Salary_increment_month'=> $r['Salary_increment_month'],
                  'Job_Type'             => $r['Job_Type'],
                  'Salary_level'         => $r['Salary_level'],
                  'Salary_Steps'         => $r['Salary_Steps'],
                  'AdminSupervisorName'  => $r['AdminSupervisorName'],
                  'CreatedAt'            => substr((string)$r['CreatedAt'],0,19)
              );
            ?>
            <tr>
              <td><?php echo (int)$r['EmployeeID']; ?></td>
              <td><?php echo h($r['EmployeeCode']); ?></td>
              <td><?php echo h($fullName); ?></td>
              <td><?php echo h($r['JobTitleName']); ?></td>
              <td><?php echo h($r['DepartmentName']); ?></td>
              <td><?php echo h($r['LocationName']); ?></td>
              <td><?php echo h($r['Email_Office']); ?></td>
              <td><?php echo h($r['Email_Personal']); ?></td>
              <td><?php echo h(substr((string)$r['HireDate'],0,10)); ?></td>
              <td><?php echo h($r['AdminSupervisorName']); ?></td>
              <!-- <td><?php echo h($r['Salary_level']); ?></td>
              <td>
                <?php
                  echo ($r['Salary_Steps'] !== null && $r['Salary_Steps'] !== ''
                        ? 'Step-'.h($r['Salary_Steps'])
                        : '');
                ?>
              </td> -->
              <td>
                <span class="status-pill <?php echo $pillClass; ?>">
                  <span class="status-dot"></span>
                  <?php echo h($status); ?>
                </span>
              </td>
              <!-- <td class="text-end">
                <button type="button"
                        class="btn btn-muted btn-sm w-100 w-md-auto btn-view-employee"
                        data-emp="<?php echo h(json_encode($modalData)); ?>">
                  <i class="fas fa-eye"></i>
                  View
                </button>
              </td> -->
            </tr>
          <?php endforeach; ?>
          <?php if (empty($rows)): ?>
            <tr><td colspan="14" class="text-center text-muted py-4">
              <i class="fas fa-user-slash me-1"></i> No data
            </td></tr>
          <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>

<!-- View Modal -->
<div style="margin-top: 78px; margin-bottom: 100px;" class="modal fade" id="employeeViewModal" tabindex="-1" aria-labelledby="employeeViewLabel" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-scrollable" >
    <div class="modal-content">
      <div class="modal-header">
        <div>
          <h5 class="modal-title" id="employeeViewLabel">Employee Details</h5>
          <div class="small text-muted" id="modalEmpSubTitle"></div>
        </div>
        <button type="button"
                class="btn-close"
                data-bs-dismiss="modal"
                data-dismiss="modal"
                aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <table class="table table-sm mb-0">
          <tbody>
            <tr><th style="width:30%">PRF ID</th><td id="vEmployeeCode"></td></tr>
            <tr><th>Name</th><td id="vFullName"></td></tr>
            <tr><th>Status</th><td id="vStatus"></td></tr>
            <tr><th>Designation</th><td id="vJobTitle"></td></tr>
            <tr><th>Department</th><td id="vDepartment"></td></tr>
            <tr><th>Location</th><td id="vLocation"></td></tr>
            <tr><th>Immediate Supervisor</th><td id="vSupervisor"></td></tr>
            <tr><th>National ID</th><td id="vNationalID"></td></tr>
            <tr><th>Email (PRF)</th><td id="vEmailOffice"></td></tr>
            <tr><th>Email (Personal)</th><td id="vEmailPersonal"></td></tr>
            <tr><th>Phone 1</th><td id="vPhone1"></td></tr>
            <tr><th>Phone 2</th><td id="vPhone2"></td></tr>
            <tr><th>Joining Date</th><td id="vHireDate"></td></tr>
            <tr><th>End Date</th><td id="vEndDate"></td></tr>
            <tr><th>Date of Birth</th><td id="vDOB"></td></tr>
            <tr><th>Blood Group</th><td id="vBloodGroup"></td></tr>
            <tr><th>Increment Month</th><td id="vIncrementMonth"></td></tr>
            <tr><th>Job Type</th><td id="vJobType"></td></tr>
            <tr><th>Salary Level</th><td id="vSalaryLevel"></td></tr>
            <tr><th>Salary Step</th><td id="vSalaryStep"></td></tr>
            <tr><th>Created At</th><td id="vCreatedAt"></td></tr>
          </tbody>
        </table>
      </div>
      <div class="modal-footer d-flex justify-content-between" style="margin-bottom: 50px;">
        <div class="small text-muted">
          Current status: <span id="modalStatusBadge" class="fw-semibold"></span>
        </div>
        <div class="d-flex gap-2">
          <!-- Status change -->
          <form method="post" id="formToggleEmployee" class="d-inline">
            <input type="hidden" name="csrf" value="<?php echo h($CSRF); ?>">
            <input type="hidden" name="act" value="toggle">
            <input type="hidden" name="EmployeeID" id="modalToggleEmployeeID" value="">
            <input type="hidden" name="to" id="modalToggleTo" value="">
            <button type="submit" class="btn btn-muted btn-sm" id="modalToggleBtn">
              Change Status
            </button>
          </form>

          <!-- Edit (go to main employee page) -->
          <a href="#" class="btn btn-brand btn-sm" id="modalEditLink" target="_self">
            <i class="fas fa-pencil-alt"></i>
            Edit
          </a>

          <!-- Delete -->
          <form method="post" id="formDeleteEmployee" class="d-inline"
                onsubmit="return confirm('Delete this employee permanently?');">
            <input type="hidden" name="csrf" value="<?php echo h($CSRF); ?>">
            <input type="hidden" name="act" value="delete">
            <input type="hidden" name="EmployeeID" id="modalDeleteEmployeeID" value="">
            <button type="submit" class="btn btn-danger-soft btn-sm">
              <i class="fas fa-trash-alt"></i>
              Delete
            </button>
          </form>
        </div>
      </div>
    </div>
  </div>
</div>

<script>
(function(){
  var EMP_EDIT_PAGE = <?php echo json_encode($employeeEditPage); ?>;

  function setText(id, value){
    var el = document.getElementById(id);
    if (el) { el.textContent = value || ''; }
  }

  function showModal(){
    var modalEl = document.getElementById('employeeViewModal');

    // Bootstrap 5
    if (window.bootstrap && bootstrap.Modal) {
      var modal = bootstrap.Modal.getOrCreateInstance(modalEl);
      modal.show();
      return;
    }

    // Bootstrap 4 (jQuery plugin)
    if (window.jQuery && jQuery(modalEl).modal) {
      jQuery(modalEl).modal('show');
      return;
    }

    // Fallback: manual
    modalEl.classList.add('show');
    modalEl.style.display = 'block';
    modalEl.removeAttribute('aria-hidden');
  }

  function hideModal(){
    var modalEl = document.getElementById('employeeViewModal');

    // Bootstrap 5
    if (window.bootstrap && bootstrap.Modal) {
      var inst = bootstrap.Modal.getInstance(modalEl);
      if (inst) inst.hide();
      return;
    }

    // Bootstrap 4
    if (window.jQuery && jQuery(modalEl).modal) {
      jQuery(modalEl).modal('hide');
      return;
    }

    // Fallback
    modalEl.classList.remove('show');
    modalEl.style.display = 'none';
    modalEl.setAttribute('aria-hidden', 'true');
  }

  // View button → open modal + fill data
  document.addEventListener('click', function(e){
    var target = e.target || e.srcElement;
    var btn = null;

    if (target.classList && target.classList.contains('btn-view-employee')) {
      btn = target;
    } else if (target.closest) {
      btn = target.closest('.btn-view-employee');
    }

    if (!btn) return;

    var dataStr = btn.getAttribute('data-emp') || '{}';
    var emp = {};
    try { emp = JSON.parse(dataStr); } catch (err) { emp = {}; }

    // Fill modal fields
    setText('modalEmpSubTitle', emp.EmployeeCode ? ('PRF ID: ' + emp.EmployeeCode) : '');
    setText('vEmployeeCode', emp.EmployeeCode);
    setText('vFullName', emp.FullName);
    setText('vStatus', emp.Status);
    setText('vJobTitle', emp.JobTitleName);
    setText('vDepartment', emp.DepartmentName);
    setText('vLocation', emp.LocationName);
    setText('vSupervisor', emp.AdminSupervisorName);
    setText('vNationalID', emp.NationalID);
    setText('vEmailOffice', emp.Email_Office);
    setText('vEmailPersonal', emp.Email_Personal);
    setText('vPhone1', emp.Phone1);
    setText('vPhone2', emp.Phone2);
    setText('vHireDate', emp.HireDate);
    setText('vEndDate', emp.EndDate);
    setText('vDOB', emp.DOB);
    setText('vBloodGroup', emp.Blood_group);
    setText('vIncrementMonth', emp.Salary_increment_month);
    setText('vJobType', emp.Job_Type);
    setText('vSalaryLevel', emp.Salary_level);
    setText('vSalaryStep', emp.Salary_Steps ? ('Step-' + emp.Salary_Steps) : '');
    setText('vCreatedAt', emp.CreatedAt);

    // Status badge + toggle form
    setText('modalStatusBadge', emp.Status || '');
    var toggleId = document.getElementById('modalToggleEmployeeID');
    var toggleTo = document.getElementById('modalToggleTo');
    var toggleBtn = document.getElementById('modalToggleBtn');
    if (toggleId && toggleTo && toggleBtn) {
      toggleId.value = emp.EmployeeID || '';
      var nextStatus = (emp.Status === 'Active') ? 'Inactive' : 'Active';
      toggleTo.value = nextStatus;
      toggleBtn.textContent = (nextStatus === 'Active') ? 'Activate' : 'Deactivate';
    }

    // Delete form
    var delId = document.getElementById('modalDeleteEmployeeID');
    if (delId) {
      delId.value = emp.EmployeeID || '';
    }

    // Edit link
    var editLink = document.getElementById('modalEditLink');
    if (editLink) {
      if (emp.EmployeeID) {
        editLink.href = EMP_EDIT_PAGE + '?edit=' + encodeURIComponent(emp.EmployeeID);
      } else {
        editLink.href = EMP_EDIT_PAGE;
      }
    }

    // Finally show modal
    showModal();
  });

  // Close button manual fallback (when Bootstrap JS নাই / কাজ করছে না)
  var modalEl = document.getElementById('employeeViewModal');
  if (modalEl) {
    var closeBtns = modalEl.querySelectorAll('[data-bs-dismiss="modal"],[data-dismiss="modal"]');
    for (var i = 0; i < closeBtns.length; i++) {
      closeBtns[i].addEventListener('click', function(){
        hideModal();
      });
    }
  }
})();
</script>

<?php require_once __DIR__ . '/../../include/footer.php'; ?>
