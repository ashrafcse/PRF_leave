<?php
/**
 * Safe Employee Seeder
 * - Does NOT insert explicit values into IDENTITY columns (e.g., Locations).
 * - Ensures FK rows exist by NAME, then fetches their IDs.
 * - Can work with EmployeeID as identity or non-identity via the EMPLOYEE_IDENTITY flag.
 */

require_once __DIR__ . '/db.php'; // $conn = new PDO(...) with ERRMODE_EXCEPTION recommended

// ================== CONFIG ==================
const EMPLOYEE_IDENTITY = false; // set true if EmployeeID is IDENTITY in dbo.Employees

// Seed data — edit as needed
$designations = ['Software Engineer', 'Senior Engineer', 'Manager'];
$departments  = ['IT', 'HR', 'Finance'];
$locations    = ['Dhaka HQ', 'Chittagong Office'];

$employees = [
  [
    'EmployeeCode' => 'EMP-0001',
    'FirstName' => 'Rakib',
    'LastName' => 'Hasan',
    'JobTitleName' => 'Manager',
    'DepartmentName' => 'IT',
    'LocationName' => 'Dhaka HQ',
    'Status' => 'Active',
    'Email_Office' => 'rakib@company.com',
  ],
  [
    'EmployeeCode' => 'EMP-0002',
    'FirstName' => 'Amrito',
    'LastName' => 'Bosu',
    'JobTitleName' => 'Senior Engineer',
    'DepartmentName' => 'IT',
    'LocationName' => 'Dhaka HQ',
    'Status' => 'Active',
    'Email_Office' => 'amrito@company.com',
  ],
];

// ============== HELPERS (NO IDENTITY INSERT) ==============
function ensure_row_by_name(PDO $conn, string $table, string $idCol, string $nameCol, string $name): int {
  // Insert by name ONLY if not exists (do not touch identity column)
  $ins = $conn->prepare("
    INSERT INTO {$table} ({$nameCol})
    SELECT :name
    WHERE NOT EXISTS (SELECT 1 FROM {$table} WHERE {$nameCol} = :name)
  ");
  $ins->execute([':name' => $name]);

  // Fetch id
  $sel = $conn->prepare("SELECT {$idCol} FROM {$table} WHERE {$nameCol} = :name");
  $sel->execute([':name' => $name]);
  $id = (int)$sel->fetchColumn();
  if ($id <= 0) {
    throw new RuntimeException("Failed to obtain ID from {$table} for '{$name}'.");
  }
  return $id;
}

function normalize_code($s){
  $s = trim(preg_replace('/\s+/', ' ', (string)$s));
  return mb_strtolower($s, 'UTF-8');
}

function employee_code_exists(PDO $conn, string $code): bool {
  $q = $conn->prepare("SELECT 1 FROM dbo.Employees WHERE LOWER(RTRIM(LTRIM(EmployeeCode))) = :c");
  $q->execute([':c' => normalize_code($code)]);
  return (bool)$q->fetchColumn();
}

// Non-identity EmployeeID safe insert (MAX+1 under SERIALIZABLE + table lock)
function insert_employee_non_identity(PDO $conn, array $row, int $createdBy = 1): void {
  $conn->exec("SET TRANSACTION ISOLATION LEVEL SERIALIZABLE");
  $conn->beginTransaction();
  try {
    // Duplicate EmployeeCode check inside txn
    if (employee_code_exists($conn, $row['EmployeeCode'])) {
      throw new RuntimeException('DUPLICATE_CODE');
    }

    // Get next EmployeeID with table lock
    $nextId = (int)$conn->query("
      SELECT ISNULL(MAX(EmployeeID),0)+1
      FROM dbo.Employees WITH (TABLOCKX, HOLDLOCK)
    ")->fetchColumn();

    $stmt = $conn->prepare("
      INSERT INTO dbo.Employees
      (
        EmployeeID, EmployeeCode, FirstName, LastName,
        JobTitleID, DepartmentID, LocationID,
        Status, Email_Office, CreatedAt, CreatedBy
      )
      VALUES
      (
        :EmployeeID, :EmployeeCode, :FirstName, :LastName,
        :JobTitleID, :DepartmentID, :LocationID,
        :Status, :Email_Office, GETDATE(), :CreatedBy
      )
    ");
    $stmt->execute([
      ':EmployeeID'   => $nextId,
      ':EmployeeCode' => $row['EmployeeCode'],
      ':FirstName'    => $row['FirstName'],
      ':LastName'     => $row['LastName'] ?? null,
      ':JobTitleID'   => (int)$row['JobTitleID'],
      ':DepartmentID' => (int)$row['DepartmentID'],
      ':LocationID'   => (int)$row['LocationID'],
      ':Status'       => $row['Status'] ?? 'Active',
      ':Email_Office' => $row['Email_Office'] ?? null,
      ':CreatedBy'    => $createdBy,
    ]);

    $conn->commit();
    echo "✅ Inserted Employee #{$nextId} ({$row['EmployeeCode']})<br>";
  } catch (Throwable $e) {
    if ($conn->inTransaction()) $conn->rollBack();
    if ($e instanceof RuntimeException && $e->getMessage()==='DUPLICATE_CODE') {
      echo "⚠️ Skipped: EmployeeCode already exists ({$row['EmployeeCode']})<br>";
      return;
    }
    throw $e;
  }
}

// Identity EmployeeID version (let SQL Server generate EmployeeID)
function insert_employee_identity(PDO $conn, array $row, int $createdBy = 1): void {
  // Duplicate EmployeeCode check outside txn is okay here; simple seeder
  if (employee_code_exists($conn, $row['EmployeeCode'])) {
    echo "⚠️ Skipped: EmployeeCode already exists ({$row['EmployeeCode']})<br>";
    return;
  }

  $stmt = $conn->prepare("
    INSERT INTO dbo.Employees
      (EmployeeCode, FirstName, LastName,
       JobTitleID, DepartmentID, LocationID,
       Status, Email_Office, CreatedAt, CreatedBy)
    VALUES
      (:EmployeeCode, :FirstName, :LastName,
       :JobTitleID, :DepartmentID, :LocationID,
       :Status, :Email_Office, GETDATE(), :CreatedBy)
  ");
  $stmt->execute([
    ':EmployeeCode' => $row['EmployeeCode'],
    ':FirstName'    => $row['FirstName'],
    ':LastName'     => $row['LastName'] ?? null,
    ':JobTitleID'   => (int)$row['JobTitleID'],
    ':DepartmentID' => (int)$row['DepartmentID'],
    ':LocationID'   => (int)$row['LocationID'],
    ':Status'       => $row['Status'] ?? 'Active',
    ':Email_Office' => $row['Email_Office'] ?? null,
    ':CreatedBy'    => 1,
  ]);

  echo "✅ Inserted Employee ({$row['EmployeeCode']})<br>";
}

// ================== RUN ==================
try {
  // Ensure lookup rows exist (NO explicit IDs → no IDENTITY_INSERT needed)
  foreach ($designations as $name) {
    ensure_row_by_name($conn, 'dbo.Designation', 'JobTitleID', 'JobTitleName', $name);
  }
  foreach ($departments as $name) {
    ensure_row_by_name($conn, 'dbo.Departments', 'DepartmentID', 'DepartmentName', $name);
  }
  foreach ($locations as $name) {
    ensure_row_by_name($conn, 'dbo.Locations', 'LocationID', 'LocationName', $name);
  }

  // Build name→id maps
  $nameToJobId = [];
  $q = $conn->query("SELECT JobTitleID, JobTitleName FROM dbo.Designation");
  foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) { $nameToJobId[$r['JobTitleName']] = (int)$r['JobTitleID']; }

  $nameToDeptId = [];
  $q = $conn->query("SELECT DepartmentID, DepartmentName FROM dbo.Departments");
  foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) { $nameToDeptId[$r['DepartmentName']] = (int)$r['DepartmentID']; }

  $nameToLocId = [];
  $q = $conn->query("SELECT LocationID, LocationName FROM dbo.Locations");
  foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) { $nameToLocId[$r['LocationName']] = (int)$r['LocationID']; }

  // Seed Employees
  foreach ($employees as $e) {
    $row = $e; // copy
    // Resolve FKs by name
    $row['JobTitleID']   = $nameToJobId[$e['JobTitleName']]   ?? null;
    $row['DepartmentID'] = $nameToDeptId[$e['DepartmentName']] ?? null;
    $row['LocationID']   = $nameToLocId[$e['LocationName']]   ?? null;

    if (!$row['JobTitleID'] || !$row['DepartmentID'] || !$row['LocationID']) {
      echo "❌ Missing FK for employee {$e['EmployeeCode']} (check names)\n<br>";
      continue;
    }

    if (EMPLOYEE_IDENTITY) {
      insert_employee_identity($conn, $row);
    } else {
      insert_employee_non_identity($conn, $row);
    }
  }

  echo "<br><strong>✅ Done.</strong>";
} catch (PDOException $ex) {
  echo "❌ PDO Error: " . htmlspecialchars($ex->getMessage(), ENT_QUOTES, 'UTF-8');
} catch (Throwable $ex) {
  echo "❌ Error: " . htmlspecialchars($ex->getMessage(), ENT_QUOTES, 'UTF-8');
}
