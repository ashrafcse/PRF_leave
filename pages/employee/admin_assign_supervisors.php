<?php 
/************************************************************
 * Admin: Assign Supervisors (Roles) + Ensure Employee mapping
 * Uses common header/footer like Employee module
 ************************************************************/
ini_set('display_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/../../init.php';
require_login();

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

/* ---------- CONFIG ---------- */
const ROLE_ADMIN_NAME = 'Administrative Supervisor';
const ROLE_TECH_NAME  = 'Technical Supervisor';

/* ---------- helpers ---------- */
function errfmt(PDOException $e){
    $s = $e->getCode();
    $m = $e->getMessage();
    $c = (preg_match('/constraint\s+\'?([^\']+)/i', $m, $mm) ? " | Constraint: ".$mm[1] : "");
    return "SQLSTATE {$s}{$c} | {$m}";
}

function fetch_role_ids(PDO $conn): array {
  $st = $conn->prepare("SELECT RoleName, RoleID FROM dbo.Roles WHERE RoleName IN (?,?)");
  $st->execute([ROLE_ADMIN_NAME, ROLE_TECH_NAME]);
  $m = ['admin'=>0,'tech'=>0];
  foreach($st->fetchAll(PDO::FETCH_ASSOC) as $r){
    if ($r['RoleName']===ROLE_ADMIN_NAME) $m['admin'] = (int)$r['RoleID'];
    if ($r['RoleName']===ROLE_TECH_NAME)  $m['tech']  = (int)$r['RoleID'];
  }
  return $m;
}

/* trim to column length (prevents truncation errors) */
function fitlen(?string $v, int $max): string {
  $v = (string)$v;
  if (mb_strlen($v,'UTF-8') > $max) {
    $v = mb_substr($v, 0, $max, 'UTF-8');
  }
  return $v;
}

function list_users(PDO $conn): array {
  $sql="SELECT u.UserID, u.Username, u.Email,
              CASE WHEN u.IsActive=1 THEN 'Active' ELSE 'Inactive' END AS UserStatus
        FROM dbo.Users u
        ORDER BY u.UserID";
  return $conn->query($sql)->fetchAll(PDO::FETCH_ASSOC);
}

function user_has_role(PDO $conn, int $userId, int $roleId): bool {
  if ($roleId <= 0) return false; // unknown role, treat as not assigned
  $st=$conn->prepare("SELECT 1 FROM dbo.UserRoles WHERE UserID=? AND RoleID=?");
  $st->execute([$userId,$roleId]);
  return (bool)$st->fetchColumn();
}

function set_user_role(PDO $conn, int $userId, int $roleId, bool $on): void {
  // role id invalid হলে কিছুই করব না
  if ($roleId <= 0) return;

  if ($on) {
    $sql="IF NOT EXISTS (SELECT 1 FROM dbo.UserRoles WHERE UserID=? AND RoleID=?)
         INSERT INTO dbo.UserRoles(UserID,RoleID,AssignedAt,AssignedBy) VALUES(?,?,GETDATE(),NULL)";
    $st=$conn->prepare($sql);
    $st->execute([$userId,$roleId,$userId,$roleId]);
  } else {
    $st=$conn->prepare("DELETE FROM dbo.UserRoles WHERE UserID=? AND RoleID=?");
    $st->execute([$userId,$roleId]);
  }
}

/* minimal Employees row create, if needed */
function ensure_employee_for_user(PDO $conn, int $userId): void {
  // user info
  $st=$conn->prepare("SELECT Username, Email FROM dbo.Users WHERE UserID=?");
  $st->execute([$userId]);
  $u=$st->fetch(PDO::FETCH_ASSOC);
  if(!$u) return;

  // already mapped?
  $st=$conn->prepare("
    SELECT EmployeeID
    FROM dbo.Employees
    WHERE LOWER(LTRIM(RTRIM(Email_Office))) = LOWER(LTRIM(RTRIM(?)))
  ");
  $st->execute([$u['Email']]);
  if ($st->fetchColumn()) return;

  // defaults for required FKs
  $jobId = (int)$conn->query("SELECT TOP 1 JobTitleID FROM dbo.Designation ORDER BY JobTitleID")->fetchColumn();
  $depId = (int)$conn->query("SELECT TOP 1 DepartmentID FROM dbo.Departments ORDER BY DepartmentID")->fetchColumn();
  $locId = (int)$conn->query("SELECT TOP 1 LocationID FROM dbo.Locations ORDER BY LocationID")->fetchColumn();
  if(!$jobId || !$depId || !$locId){
    throw new RuntimeException("Missing default FK (Designation/Departments/Locations).");
  }

  // safe values
  $code  = fitlen('USR-'.str_pad((string)$userId, 5, '0', STR_PAD_LEFT), 50);
  $first = fitlen($u['Username'] ?: ('User'.$userId), 100);
  $last  = fitlen('', 100);
  $natId = fitlen('', 100);
  $emailOffice   = fitlen((string)$u['Email'], 200);
  $emailPersonal = fitlen('', 50);
  $phone1 = fitlen('', 11);
  $phone2 = fitlen('', 11);
  $blood  = fitlen('', 2);
  $incMon = fitlen('', 1);
  $jobType= fitlen('', 50);
  $salLvl = fitlen('', 10);
  $status = fitlen('Active', 50);
  $salSteps = 0;
  $createdBy = 0;
  $supAdmin = null;
  $supTech  = null;
  $dob = '1900-01-01';

  $sql = "
    INSERT INTO dbo.Employees
      (EmployeeCode, FirstName, LastName, NationalID,
       Email_Office, Email_Personal, Phone1, Phone2,
       JobTitleID, DepartmentID, LocationID,
       SupervisorID_admin, SupervisorID_technical,
       HireDate, EndDate, Blood_group, Salary_increment_month,
       DOB, Job_Type, Salary_level, Salary_Steps,
       Status, CreatedAt, CreatedBy)
    VALUES
      (:EmployeeCode, :FirstName, :LastName, :NationalID,
       :Email_Office, :Email_Personal, :Phone1, :Phone2,
       :JobTitleID, :DepartmentID, :LocationID,
       :SupervisorID_admin, :SupervisorID_technical,
       GETDATE(), NULL, :Blood_group, :Salary_increment_month,
       :DOB, :Job_Type, :Salary_level, :Salary_Steps,
       :Status, GETDATE(), :CreatedBy)
  ";
  $stmt=$conn->prepare($sql);
  $stmt->execute([
    ':EmployeeCode'           => $code,
    ':FirstName'              => $first,
    ':LastName'               => $last,
    ':NationalID'             => $natId,
    ':Email_Office'           => $emailOffice,
    ':Email_Personal'         => $emailPersonal,
    ':Phone1'                 => $phone1,
    ':Phone2'                 => $phone2,
    ':JobTitleID'             => $jobId,
    ':DepartmentID'           => $depId,
    ':LocationID'             => $locId,
    ':SupervisorID_admin'     => $supAdmin,
    ':SupervisorID_technical' => $supTech,
    ':Blood_group'            => $blood,
    ':Salary_increment_month' => $incMon,
    ':DOB'                    => $dob,
    ':Job_Type'               => $jobType,
    ':Salary_level'           => $salLvl,
    ':Salary_Steps'           => $salSteps,
    ':Status'                 => $status,
    ':CreatedBy'              => $createdBy,
  ]);
}

/* count for dropdown info text */
function dropdown_count(PDO $conn, string $roleName): int {
  $st=$conn->prepare("
    SELECT COUNT(*)
    FROM dbo.Users u
    JOIN dbo.UserRoles ur ON ur.UserID=u.UserID
    JOIN dbo.Roles r ON r.RoleID=ur.RoleID AND r.RoleName=?
    JOIN dbo.Employees e
      ON  LOWER(LTRIM(RTRIM(e.Email_Office))) = LOWER(LTRIM(RTRIM(u.Email)))
      OR  e.EmployeeID = u.UserID
  ");
  $st->execute([$roleName]);
  return (int)$st->fetchColumn();
}

/* ---------- POST (save) ---------- */
$msg = '';
$msg_type = 'success';

if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['save_roles'])) {
  $roleIds = fetch_role_ids($conn);
  $adminId = (int)$roleIds['admin'];
  $techId  = (int)$roleIds['tech'];

  if ($adminId <= 0 && $techId <= 0) {
      $msg = "Save failed: Required roles '".ROLE_ADMIN_NAME."' এবং '".ROLE_TECH_NAME."' dbo.Roles টেবিলে পাওয়া যায়নি। আগে এই role গুলো create করুন।";
      $msg_type = 'danger';
  } else {
      $conn->beginTransaction();
      try{
        foreach($_POST['user'] ?? [] as $uid => $row){
          $uid = (int)$uid;

          if ($adminId > 0) {
            set_user_role($conn, $uid, $adminId, isset($row['admin']));
          }
          if ($techId > 0) {
            set_user_role($conn, $uid, $techId,  isset($row['tech']));
          }

          if ( ($adminId > 0 && isset($row['admin'])) ||
               ($techId  > 0 && isset($row['tech'])) ) {
            ensure_employee_for_user($conn, $uid);
          }
        }
        $conn->commit();

        $parts = [];
        if ($adminId > 0) $parts[] = "Admin dropdown: ".dropdown_count($conn, ROLE_ADMIN_NAME);
        if ($techId  > 0) $parts[] = "Tech dropdown: ".dropdown_count($conn, ROLE_TECH_NAME);
        $msg = "Saved. ".implode(', ', $parts);
        $msg_type = 'success';
      }catch(Throwable $e){
        if($conn->inTransaction()) $conn->rollBack();
        if ($e instanceof PDOException) {
            $msg = "Save failed: ".h(errfmt($e));
        } else {
            $msg = "Save failed: ".h($e->getMessage());
        }
        $msg_type = 'danger';
      }
  }
}

/* ---------- Page data ---------- */
$roles = fetch_role_ids($conn);
$users = list_users($conn);

/* ---------- View (common header/footer) ---------- */
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

  .card-elevated {
      border-radius: 16px;
      border: 1px solid #e5e7eb;
      box-shadow: 0 18px 45px rgba(15, 23, 42, 0.12);
      overflow: hidden;
  }

  .card-elevated .card-body{
      background: radial-gradient(circle at top left, #eff6ff 0, #ffffff 45%, #f9fafb 100%);
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

  .table thead th{
      background: #f9fafb;
      color: #4b5563;
      border-bottom: 1px solid #e5e7eb;
      font-size: 13px;
      text-transform: uppercase;
      letter-spacing: .04em;
  }
  .table tbody td{
      vertical-align: middle;
      font-size: 14px;
      color: #111827;
  }
  .table-hover tbody tr:hover{
      background-color: #eff6ff;
  }

  .user-id-cell{
      font-weight: 600;
      color: #4f46e5;
      font-size: 13px;
  }
  .email-cell{
      font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
      font-size: 13px;
      color: #4b5563;
  }

  .status-pill{
      display: inline-flex;
      align-items: center;
      gap: 6px;
      padding: 3px 10px;
      border-radius: 999px;
      font-size: 12px;
      font-weight: 500;
  }
  .status-pill .status-dot{
      width: 8px;
      height: 8px;
      border-radius: 999px;
      background: #22c55e;
  }
  .status-pill.status-pill-active{
      background: #ecfdf3;
      color: #166534;
  }
  .status-pill.status-pill-inactive{
      background: #fef2f2;
      color: #b91c1c;
  }
  .status-pill.status-pill-inactive .status-dot{
      background: #ef4444;
  }

  .role-header-icon{
      margin-right: 4px;
      font-size: 0.9rem;
      color: #4f46e5;
  }

  .table-legend{
      font-size: 13px;
      color: #6b7280;
  }

  .checkbox-cell input[type="checkbox"]{
      width: 18px;
      height: 18px;
      cursor: pointer;
      accent-color: #4f46e5; /* modern browsers */
  }

  .muted-na{
      font-size: 11px;
      color: #9ca3af;
      display: inline-flex;
      align-items: center;
      gap: 4px;
  }
  .muted-na i{
      font-size: 0.9rem;
  }

  .helper-tip{
      font-size: 12px;
      color: #6b7280;
  }
</style>

<div class="page-wrap">
  <div class="d-flex flex-wrap align-items-center justify-content-between mb-3 gap-2">
    <div>
      <h1 class="page-title mb-1">
        <i class="fas fa-user-shield"></i>
        Assign Supervisors
      </h1>
      <div class="text-muted small">
        <?=h(ROLE_ADMIN_NAME)?> এবং <?=h(ROLE_TECH_NAME)?> role ইউজারদের Assign করুন
      </div>
    </div>
    <span class="badge-soft">
      <i class="fas fa-users"></i>
      মোট ইউজার: <?= count($users) ?>
    </span>
  </div>

  <?php if ($msg): ?>
    <div class="alert alert-<?php echo ($msg_type==='danger'?'danger':'success'); ?> alert-dismissible fade show shadow-sm" role="alert">
      <?php echo $msg; ?>
      <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
  <?php endif; ?>

  <div class="card card-elevated">
    <div class="card-body">
      <p class="mb-2 table-legend">
        নিচের লিস্ট থেকে ইউজার সিলেক্ট করে
        <b><?=h(ROLE_ADMIN_NAME)?></b> অথবা
        <b><?=h(ROLE_TECH_NAME)?></b> checkbox tick করুন।
        Save করার সাথে সাথে যাদের Supervisor role আছে তাদের জন্য
        <code>Employees</code> টেবিলে matching row (Email_Office = Users.Email) create নিশ্চিত করা হবে।
      </p>

      <p class="helper-tip mb-3">
        <i class="fas fa-info-circle me-1"></i>
        টেবিলের উপর hover করলে row highlight হবে, যাতে দ্রুত স্ক্যান করা সহজ হয়।
      </p>

      <form method="post" class="table-responsive">
        <input type="hidden" name="save_roles" value="1">

        <table class="table table-hover align-middle mb-0">
          <thead>
            <tr>
              <th><i class="fas fa-hashtag me-1"></i> UserID</th>
              <th><i class="fas fa-user me-1"></i> Username</th>
              <th><i class="fas fa-envelope me-1"></i> Email</th>
              <th><i class="fas fa-circle me-1"></i> Status</th>
              <th class="text-center">
                <i class="fas fa-user-tie role-header-icon"></i>
                <?=h(ROLE_ADMIN_NAME)?>
              </th>
              <th class="text-center">
                <i class="fas fa-cogs role-header-icon"></i>
                <?=h(ROLE_TECH_NAME)?>
              </th>
            </tr>
          </thead>
          <tbody>
            <?php foreach($users as $u):
              $hasAdmin = $roles['admin'] ? user_has_role($conn,(int)$u['UserID'],$roles['admin']) : false;
              $hasTech  = $roles['tech']  ? user_has_role($conn,(int)$u['UserID'],$roles['tech'])  : false;
              $isActive = $u['UserStatus'] === 'Active';
            ?>
            <tr>
              <td class="user-id-cell"><?= (int)$u['UserID'] ?></td>
              <td><?= h($u['Username']) ?></td>
              <td class="email-cell"><?= h($u['Email']) ?></td>
              <td>
                <?php if ($isActive): ?>
                  <span class="status-pill status-pill-active">
                    <span class="status-dot"></span> Active
                  </span>
                <?php else: ?>
                  <span class="status-pill status-pill-inactive">
                    <span class="status-dot"></span> Inactive
                  </span>
                <?php endif; ?>
              </td>
              <td class="text-center checkbox-cell">
                <?php if ($roles['admin'] > 0): ?>
                  <input type="checkbox"
                         name="user[<?= (int)$u['UserID'] ?>][admin]"
                         <?= $hasAdmin ? 'checked' : '' ?>>
                <?php else: ?>
                  <span class="muted-na">
                    <i class="fas fa-exclamation-circle"></i> N/A
                  </span>
                <?php endif; ?>
              </td>
              <td class="text-center checkbox-cell">
                <?php if ($roles['tech'] > 0): ?>
                  <input type="checkbox"
                         name="user[<?= (int)$u['UserID'] ?>][tech]"
                         <?= $hasTech ? 'checked' : '' ?>>
                <?php else: ?>
                  <span class="muted-na">
                    <i class="fas fa-exclamation-circle"></i> N/A
                  </span>
                <?php endif; ?>
              </td>
            </tr>
            <?php endforeach; ?>
            <?php if (empty($users)): ?>
              <tr>
                <td colspan="6" class="text-center text-muted py-4">
                  <i class="fas fa-user-slash me-1"></i> No users
                </td>
              </tr>
            <?php endif; ?>
          </tbody>
        </table>

        <div class="mt-4 d-flex justify-content-between flex-wrap gap-2">
          <button class="btn btn-brand">
            <i class="fas fa-save"></i>
            Save changes
          </button>
          <span class="helper-tip">
            <i class="fas fa-lightbulb me-1"></i>
            Tip: Supervisor assign করার পর Employees module থেকে details fine-tune করতে পারবেন।
          </span>
        </div>
      </form>

      <hr class="my-4">
      <p class="small text-muted mb-0">
        <i class="fas fa-database me-1"></i>
        আগে <code>dbo.Roles</code> টেবিলে
        <b><?=h(ROLE_ADMIN_NAME)?></b> এবং <b><?=h(ROLE_TECH_NAME)?></b> role গুলো create আছে কিনা
        দেখে নিন। তারপর এখানে Save করলে Employees পেইজের Supervisor dropdown-এ নামগুলো দেখাবে।
      </p>
    </div>
  </div>
</div>

<?php require_once __DIR__ . '/../../include/footer.php'; ?>
