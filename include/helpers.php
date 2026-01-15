<?php
// include/helpers.php

/*
 |---------------------------------------------------------------
 | BASE PATH / BASE URL
 |---------------------------------------------------------------
*/

if (!defined('BASE_PATH')) {
    define('BASE_PATH', str_replace('\\', '/', dirname(__DIR__)));
}

$docRoot = '';
if (isset($_SERVER['DOCUMENT_ROOT'])) {
    $docRoot = rtrim(str_replace('\\', '/', realpath($_SERVER['DOCUMENT_ROOT'])), '/');
}

$rootPath = rtrim(str_replace('\\', '/', BASE_PATH), '/');

$baseUrl = '';
if ($docRoot !== '' && strpos($rootPath, $docRoot) === 0) {
    $baseUrl = substr($rootPath, strlen($docRoot));
}

if ($baseUrl === '' || $baseUrl === false) {
    $baseUrl = '/';
}

if (substr($baseUrl, -1) !== '/') {
    $baseUrl .= '/';
}

if (!defined('BASE_URL')) {
    define('BASE_URL', $baseUrl);
}

/*
 |---------------------------------------------------------------
 | COMMON HELPERS
 |---------------------------------------------------------------
*/

if (!function_exists('h')) {
    function h($s) {
        return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('asset')) {
    function asset($path) {
        return BASE_URL . 'assets/' . ltrim((string)$path, '/');
    }
}

/*
 |---------------------------------------------------------------
 | SESSION
 |---------------------------------------------------------------
*/

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../db.php';

/*
 |---------------------------------------------------------------
 | AUTH HELPERS
 |---------------------------------------------------------------
*/

if (!function_exists('current_user_id')) {
    function current_user_id() {

        if (function_exists('current_user')) {
            $u = current_user();
            if (is_array($u)) {
                if (isset($u['UserID'])) return (int)$u['UserID'];
                if (isset($u['user_id'])) return (int)$u['user_id'];
                if (isset($u['id'])) return (int)$u['id'];
                if (isset($u['userid'])) return (int)$u['userid'];
            }
        }

        if (isset($_SESSION['user_id'])) return (int)$_SESSION['user_id'];
        if (isset($_SESSION['userid'])) return (int)$_SESSION['userid'];
        if (isset($_SESSION['auth_user_id'])) return (int)$_SESSION['auth_user_id'];

        if (isset($_SESSION['user']) && is_array($_SESSION['user'])) {
            if (isset($_SESSION['user']['UserID'])) return (int)$_SESSION['user']['UserID'];
            if (isset($_SESSION['user']['id'])) return (int)$_SESSION['user']['id'];
        }

        return null;
    }
}

/*
 |---------------------------------------------------------------
 | PERMISSION HELPERS (RBAC)
 |---------------------------------------------------------------
*/

if (!function_exists('get_user_permissions')) {
    function get_user_permissions() {

        $uid = current_user_id();
        if (!$uid) return array();

        if (isset($_SESSION['permissions']) && is_array($_SESSION['permissions'])) {
            return $_SESSION['permissions'];
        }

        global $conn;
        $codes = array();

        // mysqli
        if ($conn instanceof mysqli) {

            $sql = "
                SELECT DISTINCT p.Code
                FROM UserRoles ur
                JOIN RolePermissions rp ON rp.RoleID = ur.RoleID
                JOIN Permissions p ON p.PermissionID = rp.PermissionID
                WHERE ur.UserID = ?
            ";

            if ($stmt = $conn->prepare($sql)) {
                $stmt->bind_param('i', $uid);
                $stmt->execute();
                $res = $stmt->get_result();
                while ($row = $res->fetch_assoc()) {
                    $codes[] = $row['Code'];
                }
                $stmt->close();
            }

        // PDO
        } elseif ($conn instanceof PDO) {

            $sql = "
                SELECT DISTINCT p.Code
                FROM UserRoles ur
                JOIN RolePermissions rp ON rp.RoleID = ur.RoleID
                JOIN Permissions p ON p.PermissionID = rp.PermissionID
                WHERE ur.UserID = :uid
            ";

            $stmt = $conn->prepare($sql);
            $stmt->execute(array(':uid' => $uid));

            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $codes[] = $row['Code'];
            }

        // sqlsrv
        } elseif (function_exists('sqlsrv_query')) {

            $sql = "
                SELECT DISTINCT p.Code
                FROM UserRoles ur
                JOIN RolePermissions rp ON rp.RoleID = ur.RoleID
                JOIN Permissions p ON p.PermissionID = rp.PermissionID
                WHERE ur.UserID = ?
            ";

            $stmt = sqlsrv_query($conn, $sql, array($uid));
            if ($stmt) {
                while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
                    $codes[] = $row['Code'];
                }
                sqlsrv_free_stmt($stmt);
            }
        }

        $_SESSION['permissions'] = $codes;
        return $codes;
    }
}

if (!function_exists('can')) {
    function can($code) {
        $perms = get_user_permissions();
        if (in_array('*', $perms, true)) return true;
        return in_array($code, $perms, true);
    }
}

/*
 |---------------------------------------------------------------
 | SUPERVISOR HELPERS (PHP 5.6 SAFE)
 |---------------------------------------------------------------
*/

if (!function_exists('get_employee_id')) {
    function get_employee_id() {
        if (isset($_SESSION['auth_user']) && isset($_SESSION['auth_user']['EmployeeID'])) {
            return (int)$_SESSION['auth_user']['EmployeeID'];
        }
        return 0;
    }
}

if (!function_exists('is_supervisor')) {
    function is_supervisor() {

        $employeeId = get_employee_id();
        if ($employeeId === 0) return false;

        global $conn;

        $sql = "
            SELECT SupervisorID_admin, SupervisorID_technical, SupervisorID_2ndLevel
            FROM [dbPRFAssetMgt].[dbo].[Employees]
            WHERE EmployeeID = :id
        ";

        try {
            $stmt = $conn->prepare($sql);
            $stmt->bindParam(':id', $employeeId, PDO::PARAM_INT);
            $stmt->execute();
            $row = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$row) return false;

            return (
                $row['SupervisorID_admin'] !== null ||
                $row['SupervisorID_technical'] !== null ||
                $row['SupervisorID_2ndLevel'] !== null
            );
        } catch (Exception $e) {
            error_log($e->getMessage());
            return false;
        }
    }
}

if (!function_exists('get_supervisor_levels')) {
    function get_supervisor_levels() {

        $levels = array(
            'is_l1' => false,
            'is_l2' => false,
            'is_2nd_level' => false
        );

        $employeeId = get_employee_id();
        if ($employeeId === 0) return $levels;

        global $conn;

        $sql = "
            SELECT SupervisorID_admin, SupervisorID_technical, SupervisorID_2ndLevel
            FROM [dbPRFAssetMgt].[dbo].[Employees]
            WHERE EmployeeID = :id
        ";

        try {
            $stmt = $conn->prepare($sql);
            $stmt->bindParam(':id', $employeeId, PDO::PARAM_INT);
            $stmt->execute();
            $row = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($row) {
                $levels['is_l1'] = ($row['SupervisorID_admin'] !== null);
                $levels['is_l2'] = ($row['SupervisorID_technical'] !== null);
                $levels['is_2nd_level'] = ($row['SupervisorID_2ndLevel'] !== null);
            }
        } catch (Exception $e) {
            error_log($e->getMessage());
        }

        return $levels;
    }
}

function is_l1_supervisor() {
    $l = get_supervisor_levels();
    return $l['is_l1'];
}

function is_l2_supervisor() {
    $l = get_supervisor_levels();
    return $l['is_l2'];
}

function is_2nd_level_supervisor() {
    $l = get_supervisor_levels();
    return $l['is_2nd_level'];
}

/*
 |---------------------------------------------------------------
 | ERROR PAGE
 |---------------------------------------------------------------
*/

if (!function_exists('showError')) {
    function showError($message) {
        ?>
        <!DOCTYPE html>
        <html>
        <head>
            <meta charset="utf-8">
            <title>Error</title>
            <style>
                body {font-family: Arial; background:#f0f2f5; display:flex; align-items:center; justify-content:center; height:100vh;}
                .box {background:#fff; padding:30px; border-radius:8px; text-align:center;}
                .btn {padding:10px 15px; background:#007bff; color:#fff; text-decoration:none; border-radius:4px;}
            </style>
        </head>
        <body>
            <div class="box">
                <h2>Error</h2>
                <p><?php echo h($message); ?></p>
                <a href="<?php echo BASE_URL; ?>dashboard.php" class="btn">Dashboard</a>
            </div>
        </body>
        </html>
        <?php
        exit;
    }
}
