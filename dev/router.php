<?php

/**
 * Router for PHP's built-in server, does what .htaccess does:
 *   php -S localhost:8000 dev/router.php
 */

$root = realpath(__DIR__ . '/..');
$uri = rawurldecode(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));
$_SERVER['DOCUMENT_ROOT'] = $root;

// Private paths
if (preg_match('#(^|/)\.(?!well-known)#', $uri) || preg_match('#^/(app(?!/Ajax/)|storage|settings|dev|docs|routes|public/views|public/mail)(/|$)#', $uri)
    || preg_match('#\.(sql|md|log|ini|bak|json|lock|sample)$#i', $uri) || $uri === '/configuration.php') {
    http_response_code(404);
    echo 'Not found';
    return true;
}

$file = $root . $uri;
if ($uri !== '/' && is_file($file)) {
    if (str_ends_with($file, '.php')) {
        chdir(dirname($file));
        require $file;
        return true;
    }
    return false; // static file
}

if ($uri === '/logout') {
    require $root . '/logout.php';
    return true;
}

$parts = array_values(array_filter(explode('/', trim($uri, '/')), 'strlen'));
if (empty($parts) || $parts[0] === 'index' || $parts[0] === 'index.php') {
    $parts = array('index');
}
$_GET['p'] = $parts[0];
if (isset($parts[1])) {
    $_GET['p1'] = $parts[1];
}
if (isset($parts[2])) {
    $_GET['p2'] = $parts[2];
}
require $root . '/routes/web.php';
return true;
