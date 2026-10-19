<?php

define('ROOT', realpath(__DIR__) . '/');
require(ROOT . 'app/init.php');

Vault::Lock();
$_SESSION = array();
if (ini_get('session.use_cookies')) {
    $p = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
}
session_destroy();

App::Redirect('/login');
