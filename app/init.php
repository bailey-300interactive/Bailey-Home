<?php

/**
 * Everything an entry point needs: session, config, database, Twig, libraries.
 * Included by routes/web.php, every app/Ajax file and the dev/ scripts.
 */

if (!defined('ROOT')) {
    define('ROOT', realpath(__DIR__ . '/..') . '/');
}

require_once(ROOT . 'configuration.php');

error_reporting(E_ALL);
ini_set('display_errors', DEBUG ? '1' : '0');
ini_set('log_errors', '1');
ini_set('error_log', ROOT . 'storage/logs/php-errors.log');
date_default_timezone_set('UTC');

if (PHP_SAPI !== 'cli' && session_status() === PHP_SESSION_NONE) {
    $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    session_name('home_sid');
    session_set_cookie_params(array(
        'lifetime' => 0,
        'path' => '/',
        'secure' => $secure,
        'httponly' => true,
        'samesite' => 'Lax'
    ));
    ini_set('session.use_strict_mode', '1');
    ini_set('session.gc_maxlifetime', (string)SESSION_IDLE_SECONDS);
    // Keep our session files in our own folder. In the shared system folder, the server's own cleanup
    // (Debian's sessionclean cron, or another site's PHP) deletes them after php.ini's 24 minutes,
    // which signs you out no matter what gc_maxlifetime says above.
    if (is_dir(ROOT . 'storage/sessions') && is_writable(ROOT . 'storage/sessions')) {
        session_save_path(ROOT . 'storage/sessions');
        ini_set('session.gc_probability', '1');
        ini_set('session.gc_divisor', '100');
    }
    session_start();
}

require_once(ROOT . 'app/Modules/database/Db.class.php');
require_once(ROOT . 'app/Modules/database/easyCRUD/easyCRUD.class.php');
require_once(ROOT . 'app/Modules/twig/vendor/autoload.php');
require_once(ROOT . 'app/Library/helpers.php');
require_once(ROOT . 'app/Library/App.php');

// Classes in app/Library load themselves on first use
spl_autoload_register(function ($class) {
    $file = ROOT . 'app/Library/' . basename(str_replace('\\', '/', $class)) . '.php';
    if (is_file($file)) {
        require_once($file);
    }
});

// Security headers for every page
if (PHP_SAPI !== 'cli' && !headers_sent()) {
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: SAMEORIGIN');
    header('Referrer-Policy: same-origin');
}

// Session ended but this device was "kept signed in": sign back in from the cookie
if (PHP_SAPI !== 'cli' && empty($_SESSION['authenticated']) && !empty($_COOKIE[AdminUser::REMEMBER_COOKIE])) {
    try {
        AdminUser::ResumeFromCookie();
    } catch (Throwable $e) {
        // before install, or the database is down: carry on signed out
    }
}
