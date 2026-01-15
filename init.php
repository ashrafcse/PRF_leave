<?php
// init.php (project root)

//
// ---------- SESSION (secure cookies) ----------
//
if (session_status() === PHP_SESSION_NONE) {
    $secure   = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    $httponly = true;
    session_set_cookie_params(0, '/', '', $secure, $httponly);
    session_start();
}

//
// ---------- HELPERS / BASE_URL ----------
//
require_once __DIR__ . '/include/helpers.php'; // h(), asset(), BASE_PATH/BASE_URL

//
// ---------- DB CONFIG ----------
//
if (!extension_loaded('pdo_sqlsrv')) {
    die('PHP extension "pdo_sqlsrv" is not loaded.');
}

$DB = array(
    'host'     => '27.147.225.171',
    'database' => 'dbPRFAssetMgt',
    'username' => 'sa',
    'password' => 'LocK@DMServer',
    'timeout'  => 6,
);

$DEV_SHOW_PDO_ERROR = true;

//
// ---------- PDO CONNECT (sqlsrv) ----------
//
function db_connect_sqlsrv(array $DB, $showError = false){
    $servers = array(
        $DB['host'] . ',1433',
        $DB['host'],
    );
    $combos = array(
        array('enc'=>false, 'trust'=>false),
        array('enc'=>true,  'trust'=>true),
        array('enc'=>true,  'trust'=>false),
    );

    $last = null;
    foreach ($servers as $srv) {
        foreach ($combos as $c) {
            try {
                $dsn = "sqlsrv:Server={$srv};Database={$DB['database']};LoginTimeout={$DB['timeout']}";
                if ($c['enc']) {
                    $dsn .= ";Encrypt=Yes";
                    if ($c['trust']) $dsn .= ";TrustServerCertificate=Yes";
                }
                $pdo = new PDO($dsn, $DB['username'], $DB['password']);
                $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
                $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
                return $pdo;
            } catch (PDOException $e) {
                $last = $e;
            }
        }
    }
    if ($showError && $last) {
        die('Database connection failed: ' . h($last->getMessage()));
    }
    error_log('DB connect error: ' . ($last ? $last->getMessage() : 'unknown'));
    die('Database connection failed.');
}

try {
    $conn = db_connect_sqlsrv($DB, $DEV_SHOW_PDO_ERROR);
} catch (Exception $e) {
    die('Database connection failed: ' . h($e->getMessage()));
}

//
// ---------- AUTH HELPERS ----------
//
function is_safe_relative_path($p){
    if ($p === '' || $p === null) return false;
    if (preg_match('~^https?://~i', $p)) return false;
    if (strpos($p, '//') === 0) return false;
    if (strpos($p, '..') !== false) return false;
    return true;
}

function current_user(){
    return isset($_SESSION['auth_user']) ? $_SESSION['auth_user'] : null;
}

function require_login(){
    if (!current_user()) {
        $next = isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '';
        $qs = $next ? ('?next=' . rawurlencode($next)) : '';
        header('Location: ' . BASE_URL . 'login.php' . $qs);
        exit;
    }
}

function logout(){
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time()-42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    session_destroy();
}

function route_is_public() {
    $public = array(
        '/login.php',
        '/logout.php',
        '/index.php',
    );

    $uri = isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '';
    
    // Allow assets and include folders
    if (preg_match('~^' . preg_quote(BASE_URL, '~') . '(assets/|include/)~i', $uri)) {
        return true;
    }

    $path = $uri;
    if (strpos($path, BASE_URL) === 0) {
        $path = substr($path, strlen(BASE_URL) - 1);
        if ($path === false) { 
            $path = $uri; 
        }
    }

    $qpos = strpos($path, '?');
    if ($qpos !== false) {
        $path = substr($path, 0, $qpos);
    }

    if ($path === '' || $path === '/') {
        $path = '/index.php';
    }

    return in_array($path, $public, true);
}

//
// ---------- EMPLOYEE MAPPING FUNCTIONS ----------
//

/**
 * Get current employee ID with auto-mapping if needed
 */
function get_current_employee_id(PDO $conn, $autoMap = true) {
    if (!isset($_SESSION['auth_user']) || !isset($_SESSION['auth_user']['UserID'])) {
        return null;
    }
    
    $userId = (int)$_SESSION['auth_user']['UserID'];
    
    // Check if already in session
    if (isset($_SESSION['auth_user']['EmployeeID']) && $_SESSION['auth_user']['EmployeeID'] > 0) {
        return (int)$_SESSION['auth_user']['EmployeeID'];
    }
    
    // Try to resolve from database
    try {
        // First, check Users table
        $stmt = $conn->prepare("
            SELECT u.EmployeeID, u.Username, u.Email 
            FROM dbo.Users u 
            WHERE u.UserID = :uid
        ");
        $stmt->execute([':uid' => $userId]);
        $user = $stmt->fetch();
        
        if (!$user) return null;
        
        $employeeId = isset($user['EmployeeID']) ? (int)$user['EmployeeID'] : 0;
        
        // If EmployeeID exists in Users table
        if ($employeeId > 0) {
            // Verify the employee exists
            $stmt2 = $conn->prepare("SELECT EmployeeID FROM dbo.Employees WHERE EmployeeID = :eid");
            $stmt2->execute([':eid' => $employeeId]);
            $empCheck = $stmt2->fetch();
            
            if ($empCheck) {
                $_SESSION['auth_user']['EmployeeID'] = $employeeId;
                return $employeeId;
            }
        }
        
        // Try to map by Username = EmployeeCode (Primary method)
        $username = isset($user['Username']) ? trim($user['Username']) : '';
        if ($username !== '') {
            $stmt = $conn->prepare("
                SELECT EmployeeID, EmployeeCode 
                FROM dbo.Employees 
                WHERE EmployeeCode = :code
            ");
            $stmt->execute([':code' => $username]);
            $emp = $stmt->fetch();
            
            if ($emp && isset($emp['EmployeeID'])) {
                $employeeId = (int)$emp['EmployeeID'];
                
                // Auto-update Users table if requested
                if ($autoMap) {
                    try {
                        $upd = $conn->prepare("
                            UPDATE dbo.Users 
                            SET EmployeeID = :eid 
                            WHERE UserID = :uid
                        ");
                        $upd->execute([':eid' => $employeeId, ':uid' => $userId]);
                    } catch(Exception $e) {
                        error_log("Auto-map update failed: " . $e->getMessage());
                    }
                }
                
                $_SESSION['auth_user']['EmployeeID'] = $employeeId;
                return $employeeId;
            }
        }
        
        // Try to map by Email (Secondary method)
        $email = isset($user['Email']) ? trim($user['Email']) : '';
        if ($email !== '' && $employeeId === 0) {
            $stmt = $conn->prepare("
                SELECT EmployeeID 
                FROM dbo.Employees 
                WHERE LOWER(Email_Office) = LOWER(:email) 
                   OR LOWER(Email_Personal) = LOWER(:email)
            ");
            $stmt->execute([':email' => $email]);
            $emp = $stmt->fetch();
            
            if ($emp && isset($emp['EmployeeID'])) {
                $employeeId = (int)$emp['EmployeeID'];
                
                // Auto-update Users table if requested
                if ($autoMap) {
                    try {
                        $upd = $conn->prepare("
                            UPDATE dbo.Users 
                            SET EmployeeID = :eid 
                            WHERE UserID = :uid
                        ");
                        $upd->execute([':eid' => $employeeId, ':uid' => $userId]);
                    } catch(Exception $e) {
                        error_log("Auto-map update failed: " . $e->getMessage());
                    }
                }
                
                $_SESSION['auth_user']['EmployeeID'] = $employeeId;
                return $employeeId;
            }
        }
        
        return null;
        
    } catch(Exception $e) {
        error_log("Employee ID resolution error: " . $e->getMessage());
        return null;
    }
}

/**
 * Get current employee details
 */
function get_current_employee_details(PDO $conn) {
    $employeeId = get_current_employee_id($conn, false);
    
    if (!$employeeId) {
        return null;
    }
    
    try {
        $stmt = $conn->prepare("
            SELECT 
                e.EmployeeID,
                e.EmployeeCode,
                e.FirstName,
                e.LastName,
                e.Email_Office,
                e.JobTitleID,
                e.DepartmentID,
                e.LocationID,
                e.SupervisorID_admin,
                e.SupervisorID_technical,
                e.SupervisorID_2ndLevel
            FROM dbo.Employees e
            WHERE e.EmployeeID = :eid
        ");
        $stmt->execute([':eid' => $employeeId]);
        $employee = $stmt->fetch();
        
        return $employee ?: null;
        
    } catch(Exception $e) {
        error_log("Employee details error: " . $e->getMessage());
        return null;
    }
}

/**
 * Verify and fix username-EmployeeCode mapping
 */
function verify_employee_mapping(PDO $conn, $userId) {
    try {
        $stmt = $conn->prepare("
            SELECT u.Username, u.EmployeeID
            FROM dbo.Users u
            WHERE u.UserID = :uid
        ");
        $stmt->execute([':uid' => $userId]);
        $user = $stmt->fetch();
        
        if (!$user) return false;
        
        $username = isset($user['Username']) ? trim($user['Username']) : '';
        $currentEmployeeId = isset($user['EmployeeID']) ? (int)$user['EmployeeID'] : 0;
        
        if ($username === '') return false;
        
        // Check if there's an employee with Username as EmployeeCode
        $stmt2 = $conn->prepare("
            SELECT EmployeeID 
            FROM dbo.Employees 
            WHERE EmployeeCode = :code
        ");
        $stmt2->execute([':code' => $username]);
        $correctEmp = $stmt2->fetch();
        
        if ($correctEmp && isset($correctEmp['EmployeeID'])) {
            $correctEmployeeId = (int)$correctEmp['EmployeeID'];
            
            // If mapping is different or doesn't exist, update it
            if ($currentEmployeeId !== $correctEmployeeId) {
                $upd = $conn->prepare("
                    UPDATE dbo.Users 
                    SET EmployeeID = :eid 
                    WHERE UserID = :uid
                ");
                $upd->execute([
                    ':eid' => $correctEmployeeId,
                    ':uid' => $userId
                ]);
                
                // Update session
                if (isset($_SESSION['auth_user'])) {
                    $_SESSION['auth_user']['EmployeeID'] = $correctEmployeeId;
                }
                
                return true;
            }
        }
        
        return false;
    } catch(Exception $e) {
        error_log("Verify mapping error: " . $e->getMessage());
        return false;
    }
}

//
// ---------- LOGIN FUNCTION ----------
//
function try_login(PDO $conn, $username, $password){
    $username = trim((string)$username);
    $password = (string)$password;

    if ($username === '' || $password === '') return [false, 'Username and password are required.'];

    $sql = "SELECT TOP 1
                [UserID]       AS id,
                [Username]     AS username,
                [PasswordHash] AS password_hash,
                [Email]        AS email,
                [IsActive]     AS is_active,
                [EmployeeID]   AS employee_id
            FROM [dbo].[Users]
            WHERE [Username] = :u";
    $st = $conn->prepare($sql);
    $st->execute([':u' => $username]);
    $user = $st->fetch();

    if (!$user) {
        return array(false, 'Invalid username or password.');
    }

    if ((int)(isset($user['is_active']) ? $user['is_active'] : 0) === 0) {
        return array(false, 'This account is disabled.');
    }

    if (empty($user['password_hash']) || !password_verify($password, $user['password_hash'])) {
        return array(false, 'Invalid username or password.');
    }

    try {
        $u = $conn->prepare("UPDATE [dbo].[Users] SET [LastLoginAt] = GETDATE() WHERE [UserID] = :id");
        $u->execute([':id' => $user['id']]);
    } catch (PDOException $e) {
        error_log('LastLoginAt update failed: ' . $e->getMessage());
    }

    // Store user data in session
    $_SESSION['auth_user'] = array(
        'UserID'       => (int)$user['id'],
        'username'     => $user['username'],
        'email'        => isset($user['email']) ? $user['email'] : null,
        'EmployeeID'   => isset($user['employee_id']) ? (int)$user['employee_id'] : null,
        'login_at'     => date('c')
    );
    
    // Verify and fix mapping if needed
    verify_employee_mapping($conn, (int)$user['id']);
    
    // Get employee details if available
    $employeeId = get_current_employee_id($conn, false);
    if ($employeeId) {
        try {
            $stmt = $conn->prepare("
                SELECT FirstName, LastName 
                FROM dbo.Employees 
                WHERE EmployeeID = :eid
            ");
            $stmt->execute([':eid' => $employeeId]);
            $emp = $stmt->fetch();
            
            if ($emp) {
                $_SESSION['auth_user']['FullName'] = trim(
                    (isset($emp['FirstName']) ? $emp['FirstName'] : '') . ' ' . 
                    (isset($emp['LastName']) ? $emp['LastName'] : '')
                );
            }
        } catch(Exception $e) {
            error_log("Employee details fetch failed: " . $e->getMessage());
        }
    }

    if (function_exists('session_regenerate_id')) session_regenerate_id(true);

    return [true, 'OK'];
}

// force login if not public
if (!route_is_public()) require_login();
?>