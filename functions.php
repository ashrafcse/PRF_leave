<?php
// functions.php (PHP 5.6 compatible)

/** Minimal HTML escape */
function h($s) {
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}

/** Return logged-in user array or null */
function current_user() {
    return isset($_SESSION['auth_user']) ? $_SESSION['auth_user'] : null;
}

/** Guard for protected pages */
function require_login() {
    if (!current_user()) {
        // Optional: keep the page user wanted to visit
        $target = isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : 'dashboard.php';
        header('Location: login.php?next=' . rawurlencode($target));
        exit;
    }
}

/** Try login; on success sets session & updates LastLoginAt; returns [bool, message] */
function try_login(PDO $conn, $username, $password) {
    $username = trim($username);

    // generic message to avoid user enumeration
    $invalidMsg = 'Invalid credentials or inactive account.';

    // fetch user by username (PDO sqlsrv supports named params)
    $sql = "SELECT TOP 1 UserID, Username, PasswordHash, Email, IsActive
            FROM dbo.Users WHERE Username = :u";
    $stmt = $conn->prepare($sql);
    $stmt->execute(array(':u' => $username));
    $user = $stmt->fetch();

    if (!$user) {
        // timing equalization against dummy hash (optional)
        password_verify($password, '$2y$10$abcdefghijklmnopqrstuvabcdefghiABCDEFGHIJ123456');
        return array(false, $invalidMsg);
    }

    if ((int)$user['IsActive'] !== 1) {
        return array(false, $invalidMsg);
    }

    if (!password_verify($password, $user['PasswordHash'])) {
        return array(false, $invalidMsg);
    }

    // success: set session
    $_SESSION['auth_user'] = array(
        'UserID'   => (int)$user['UserID'],
        'Username' => $user['Username'],
        'Email'    => $user['Email']
    );

    // update last login
    $up = $conn->prepare("UPDATE dbo.Users SET LastLoginAt = GETDATE() WHERE UserID = :id");
    $up->execute(array(':id' => $user['UserID']));

    return array(true, 'ok');
}

/** Logout and destroy session */
function do_logout() {
    if (session_status() === PHP_SESSION_ACTIVE) {
        $_SESSION = array();
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000,
                $params['path'], $params['domain'],
                $params['secure'], $params['httponly']
            );
        }
        session_destroy();
    }
}
