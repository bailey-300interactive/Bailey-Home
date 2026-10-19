<?php

/**
 * Login, 2-step code and password reset ({result, message} JSON like the framework)
 */

define('ROOT', realpath(__DIR__ . '/../..') . '/');
require(ROOT . 'app/init.php');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_out(array('result' => false, 'message' => 'POST only'), 405);
}
App::CheckCsrf();

$action = post('action');
$ip = App::ClientIp();

if ($action === 'login') {
    if (!App::RateLimit('login-ip:' . $ip, 20, 900)) {
        json_out(array('result' => false, 'message' => 'Too many tries. Wait 15 minutes and try again.'));
    }
    list($status, $u) = AdminUser::Attempt(post('username'), $_POST['password'] ?? '');
    switch ($status) {
        case 'ok':
            AdminUser::StartSession($u);
            $to = $_SESSION['after_login'] ?? '/';
            unset($_SESSION['after_login']);
            json_out(array('result' => true, 'message' => 'Signed in', 'redirect' => str_starts_with($to, '/') && !str_starts_with($to, '//') ? $to : '/'));
        case '2fa':
            session_regenerate_id(true);
            $_SESSION['pending_2fa'] = array('uid' => (int)$u['Id_admin_users'], 'until' => time() + 300);
            json_out(array('result' => true, 'twofa' => true, 'message' => 'Enter the 6-digit code from your authenticator app.'));
        case 'locked':
            json_out(array('result' => false, 'message' => 'Too many wrong passwords. This login is locked for ' . AdminUser::LOCK_MINUTES . ' minutes.'));
        case 'inactive':
            json_out(array('result' => false, 'message' => 'This login has been turned off.'));
        default:
            json_out(array('result' => false, 'message' => 'Incorrect username or password'));
    }
}

if ($action === 'totp') {
    $p = $_SESSION['pending_2fa'] ?? null;
    if (!$p || time() > $p['until']) {
        unset($_SESSION['pending_2fa']);
        json_out(array('result' => false, 'restart' => true, 'message' => 'That took too long. Sign in again.'));
    }
    if (!App::RateLimit('totp:' . $p['uid'], 6, 300)) {
        unset($_SESSION['pending_2fa']);
        json_out(array('result' => false, 'restart' => true, 'message' => 'Too many wrong codes. Sign in again.'));
    }
    $u = row('SELECT * FROM admin_users WHERE Id_admin_users = ?', array($p['uid']));
    if ($u && $u['totp_secret'] && Totp::Verify($u['totp_secret'], post('code'))) {
        AdminUser::StartSession($u);
        $to = $_SESSION['after_login'] ?? '/';
        unset($_SESSION['after_login']);
        json_out(array('result' => true, 'message' => 'Signed in', 'redirect' => str_starts_with($to, '/') && !str_starts_with($to, '//') ? $to : '/'));
    }
    json_out(array('result' => false, 'message' => 'That code didn\'t work. Check the time on your phone and try the newest code.'));
}

if ($action === 'forgot') {
    if (App::RateLimit('forgot:' . $ip, 5, 3600)) {
        AdminUser::SendReset(post('username'));
    }
    json_out(array('result' => true, 'message' => 'If that login exists, a reset link is on its way. It works for one hour.'));
}

if ($action === 'reset') {
    $u = AdminUser::FindByResetToken(post('token'));
    if (!$u) {
        json_out(array('result' => false, 'message' => 'That reset link has expired. Ask for a new one.'));
    }
    $pw = $_POST['password'] ?? '';
    if ($pw !== ($_POST['password2'] ?? '')) {
        json_out(array('result' => false, 'message' => 'The two passwords are different.'));
    }
    if ($problem = AdminUser::PasswordProblem($pw)) {
        json_out(array('result' => false, 'message' => $problem));
    }
    AdminUser::SetPassword($u['Id_admin_users'], $pw);
    json_out(array('result' => true, 'message' => 'Password changed. Sign in with it now.'));
}

json_out(array('result' => false, 'message' => 'Action not recognized'));
