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


// force login if not public
if (!route_is_public()) require_login();

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

    $_SESSION['auth_user'] = array(
    'id'         => (int)$user['id'],
    'UserID'     => (int)$user['id'],
    'username'   => $user['username'],
    'email'      => isset($user['email']) ? $user['email'] : null,
    'EmployeeID' => isset($user['employee_id']) ? $user['employee_id'] : null,
    'login_at'   => date('c')
);

    if (function_exists('session_regenerate_id')) session_regenerate_id(true);

    return [true, 'OK'];
}
