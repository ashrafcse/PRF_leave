<?php
/****************************************************
 * supervisor_diagnostics.php
 * Run backend diagnostics for Supervisor dropdowns
 * Requires: init.php sets up $conn (PDO, SQLSRV)
 ****************************************************/
ini_set('display_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/../../init.php'; // adjust path if needed

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

function section($title){
  echo "<h2 style='margin:24px 0 8px;font:600 18px/1.2 system-ui,Segoe UI,Arial'>".h($title)."</h2>";
}

function printTable(array $rows){
  if (empty($rows)) { echo "<div style='color:#666'>0 rows</div>"; return; }
  echo "<div style='overflow:auto'><table border='1' cellspacing='0' cellpadding='6' style='border-collapse:collapse;font:13px/1.4 monospace'>";
  echo "<thead><tr style='background:#f5f7fb'>";
  foreach(array_keys($rows[0]) as $k) echo "<th>".h($k)."</th>";
  echo "</tr></thead><tbody>";
  foreach($rows as $r){
    echo "<tr>";
    foreach($r as $v) echo "<td>".h(is_null($v)?'NULL':(string)$v)."</td>";
    echo "</tr>";
  }
  echo "</tbody></table></div>";
}

function run(PDO $conn, string $sql, array $params = []): array {
  $st = $conn->prepare($sql);
  $st->execute($params);
  return $st->fetchAll(PDO::FETCH_ASSOC);
}

echo "<!doctype html><meta charset='utf-8'><title>Supervisor Diagnostics</title>";
echo "<div style='margin:24px auto;max-width:980px;padding:0 12px'>";
echo "<h1 style='font:700 22px/1.2 system-ui,Segoe UI,Arial;margin:0 0 12px'>Supervisor Diagnostics</h1>";
echo "<div style='color:#666;margin-bottom:16px'>Checks for roles, users-in-roles, user↔employee mapping, and final dropdown counts.</div>";

/* 1) Roles present? */
section('1) Roles exist');
$roles = run($conn, "
  SELECT RoleID, RoleName
  FROM dbo.Roles
  WHERE RoleName IN ('Administrative Supervisor','Technical Supervisor')
");
printTable($roles);
$roleIdAdmin = 0; $roleIdTech = 0;
foreach($roles as $r){
  if ($r['RoleName']==='Administrative Supervisor') $roleIdAdmin = (int)$r['RoleID'];
  if ($r['RoleName']==='Technical Supervisor')      $roleIdTech  = (int)$r['RoleID'];
}
echo "<div>AdminRoleID: <b>$roleIdAdmin</b> | TechRoleID: <b>$roleIdTech</b></div>";

/* 2) Users who have those roles */
section('2) Users assigned to those roles');
$usersInRoles = [];
if ($roleIdAdmin || $roleIdTech){
  $in = [];
  $params = [];
  if ($roleIdAdmin){ $in[]='?'; $params[]=$roleIdAdmin; }
  if ($roleIdTech){  $in[]='?'; $params[]=$roleIdTech;  }
  $sql = "
    SELECT ur.RoleID, u.UserID, u.Username, u.Email
    FROM dbo.UserRoles ur
    JOIN dbo.Users u ON u.UserID = ur.UserID
    WHERE ur.RoleID IN (".implode(',', $in).")
    ORDER BY ur.RoleID, u.UserID
  ";
  $usersInRoles = run($conn, $sql, $params);
}
printTable($usersInRoles);

/* 3) User ↔ Employee link (by Email_Office or EmployeeID = UserID) */
section('3) User ↔ Employee mapping');
$userEmployee = run($conn, "
  SELECT u.UserID, u.Email, e.EmployeeID, e.Email_Office
  FROM dbo.Users u
  LEFT JOIN dbo.Employees e
    ON  LOWER(LTRIM(RTRIM(e.Email_Office))) = LOWER(LTRIM(RTRIM(u.Email)))
    OR  e.EmployeeID = u.UserID
  WHERE u.UserID IN (SELECT ur.UserID FROM dbo.UserRoles ur)
  ORDER BY u.UserID
");
printTable($userEmployee);

/* 3b) Users-in-role that have NO employee match (why dropdown empty) */
section('3b) Users who have the role but NO matching employee');
$noMatch = run($conn, "
  SELECT u.UserID, u.Email, ur.RoleID
  FROM dbo.UserRoles ur
  JOIN dbo.Users u ON u.UserID = ur.UserID
  LEFT JOIN dbo.Employees e
    ON  LOWER(LTRIM(RTRIM(e.Email_Office))) = LOWER(LTRIM(RTRIM(u.Email)))
    OR  e.EmployeeID = u.UserID
  WHERE ur.RoleID IN (?,?)
    AND e.EmployeeID IS NULL
  ORDER BY ur.RoleID, u.UserID
", [$roleIdAdmin, $roleIdTech]);
printTable($noMatch);

if (!empty($noMatch)) {
  echo "<div style='margin:8px 0;color:#b91c1c'>
  ⛔ Above users are assigned to supervisor roles but don’t map to any Employees row.<br>
  ➜ Fix by creating an <b>Employees</b> row with <b>Email_Office</b> equal to the user’s <b>Email</b> (or set EmployeeID = UserID with IDENTITY_INSERT ON).</div>";
}

/* 4) Final counts (what your PHP dropdowns effectively see) */
section('4) Final counts used by dropdowns');
$adminCount = run($conn, "
  SELECT COUNT(*) AS AdminCount
  FROM dbo.Users u
  JOIN dbo.UserRoles ur ON ur.UserID = u.UserID
  JOIN dbo.Roles r ON r.RoleID = ur.RoleID AND r.RoleName='Administrative Supervisor'
  JOIN dbo.Employees e
    ON  LOWER(LTRIM(RTRIM(e.Email_Office))) = LOWER(LTRIM(RTRIM(u.Email)))
    OR  e.EmployeeID = u.UserID
");
$techCount = run($conn, "
  SELECT COUNT(*) AS TechCount
  FROM dbo.Users u
  JOIN dbo.UserRoles ur ON ur.UserID = u.UserID
  JOIN dbo.Roles r ON r.RoleID = ur.RoleID AND r.RoleName='Technical Supervisor'
  JOIN dbo.Employees e
    ON  LOWER(LTRIM(RTRIM(e.Email_Office))) = LOWER(LTRIM(RTRIM(u.Email)))
    OR  e.EmployeeID = u.UserID
");
printTable(array_merge($adminCount, $techCount));

/* 5) Helpful next steps snippet (SQL) */
section('5) How to seed quickly (SQL snippet)');
echo "<pre style='background:#0b1021;color:#d0e2ff;padding:12px;border-radius:8px;white-space:pre-wrap'>
-- Example: create a user and make them an Administrative Supervisor,
-- then create a matching Employees row (Email_Office must equal Users.Email)

DECLARE @AdminRoleID int = (SELECT RoleID FROM dbo.Roles WHERE RoleName='Administrative Supervisor');

INSERT INTO dbo.Users (Username, PasswordHash, Email, IsActive, CreatedAt, CreatedBy)
VALUES ('adminsup','x','adminsup@example.com',1,GETDATE(),NULL);

INSERT INTO dbo.UserRoles (UserID, RoleID, AssignedAt, AssignedBy)
SELECT u.UserID, @AdminRoleID, GETDATE(), NULL
FROM dbo.Users u WHERE u.Email='adminsup@example.com'
  AND NOT EXISTS (SELECT 1 FROM dbo.UserRoles ur WHERE ur.UserID=u.UserID AND ur.RoleID=@AdminRoleID);

INSERT INTO dbo.Employees
( EmployeeCode, FirstName, LastName, NationalID, Email_Office, Email_Personal, Phone1, Phone2,
  JobTitleID, DepartmentID, LocationID, SupervisorID_admin, SupervisorID_technical,
  HireDate, EndDate, Blood_group, Salary_increment_month, DOB, Job_Type, Salary_level, Salary_Steps,
  Status, CreatedAt, CreatedBy )
VALUES
('EMP-A001','Admin','Boss',NULL,'adminsup@example.com',NULL,'01234567890',NULL,
  1,1,1, NULL, NULL, GETDATE(), NULL, 'O+', NULL, '1990-01-01', 'Full-time', 'L1', 1, 'Active', GETDATE(), NULL);
</pre>";

echo "</div>";
