<?php
// logout.php — self-contained & safe

// (1) Try to load init.php if it exists (for shared helpers)
$initPath = __DIR__ . '/init.php';
if (is_file($initPath)) {
    // suppress accidental output from init.php (shouldn't print anything anyway)
    ob_start();
    include_once $initPath;
    ob_end_clean();
}

// (2) Ensure session started (no output before this file)
if (session_status() === PHP_SESSION_NONE) {
    $secure   = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    $httponly = true;
    session_set_cookie_params(0, '/', '', $secure, $httponly);
    session_start();
}

// (3) Minimal helpers (only if not defined already)
if (!function_exists('is_safe_relative_path')) {
    function is_safe_relative_path($p){
        if ($p === '' || $p === null) return false;
        if (preg_match('~^https?://~i', $p)) return false; // absolute URL not allowed
        if (strpos($p, '//') === 0) return false;          // protocol-relative
        if (strpos($p, '..') !== false) return false;      // path traversal
        return true;
    }
}

if (!function_exists('do_logout')) {
    function do_logout(){
        // wipe all session data
        $_SESSION = [];

        // delete session cookie
        if (ini_get('session.use_cookies')) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
        }

        // destroy session
        session_destroy();

        // regenerate id to drop any leftovers
        if (function_exists('session_regenerate_id')) {
            @session_regenerate_id(true);
        }
    }
}

// (4) Perform logout
do_logout();

// (5) Support optional ?next=... (only safe relative paths)
$next = isset($_GET['next']) ? $_GET['next'] : 'index.php';
if (!is_safe_relative_path($next)) {
    $next = 'index.php';
}

// (6) Redirect and exit
header('Location: ' . $next);
exit;
