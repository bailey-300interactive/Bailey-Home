<?php

/**
 * Web Router File. All page requests start here as well as end here.
 * URL /p/p1/p2 → app/Controllers/<user folder>/<p>.php → app/Controllers/<p>.php → app/Controllers/default.php
 * @author Bailey Rotellini
 */

define('ROOT', realpath(__DIR__ . '/..') . '/');
require(ROOT . 'app/init.php');

$twig = TwigExtras::Make(ROOT . 'public/views/');

$pageVars = array();
$base = array();
$base['page_title'] = 'Home';
$base['site_profile'] = App::GetSiteProfileVariables();

// Only letters, numbers, dashes and underscores can name a controller
$p = preg_replace('/[^a-z0-9_-]/i', '', $_GET['p'] ?? '');
$p1 = preg_replace('/[^a-z0-9_.-]/i', '', $_GET['p1'] ?? '');
$p2 = preg_replace('/[^a-z0-9_.-]/i', '', $_GET['p2'] ?? '');
if ($p === '') {
    $p = 'index';
}
$base['section'] = $p;
$base['path'] = '/' . trim($p . '/' . $p1 . '/' . $p2, '/');

$controller = CONTROLLERS . 'default.php';
$folder = preg_replace('/[^a-z0-9_-]/i', '', $_SESSION['user_folder'] ?? '');
if ($folder !== '' && is_file(CONTROLLERS . $folder . '/' . $p . '.php') && $p !== 'default') {
    $controller = CONTROLLERS . $folder . '/' . $p . '.php';
} elseif (is_file(CONTROLLERS . $p . '.php') && $p !== 'default') {
    $controller = CONTROLLERS . $p . '.php';
}

$pageNeeded = '404';
$httpStatus = 200;

try {
    include($controller);
} catch (HttpException $e) {
    $httpStatus = (int)$e->getCode() ?: 404;
    $pageNeeded = 'error';
    $pageVars['error_title'] = $httpStatus === 403 ? 'Not allowed' : 'Not found';
    $base['page_title'] = $pageVars['error_title'];
    $pageVars['error_message'] = $e->getMessage();
} catch (Throwable $e) {
    $httpStatus = 500;
    (new Log())->write('Uncaught ' . get_class($e) . ': ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    $pageNeeded = 'error';
    $pageVars['error_title'] = 'Something went wrong';
    $base['page_title'] = 'Error';
    $pageVars['error_message'] = DEBUG ? $e->getMessage() . ' (' . basename($e->getFile()) . ':' . $e->getLine() . ')' : 'The error was logged. Try again, and check storage/logs if it keeps happening.';
}

if (App::IsAuthenticated()) {
    $base['menu'] = App::GetMenu();
    $base['timer'] = TimeTracker::RunningTimer(App::UserId());
    $base['vault_unlocked'] = Vault::IsUnlocked();
    // Choices for the quick-add window (on every page)
    try {
        $base['quick'] = array(
            'lists' => rows('SELECT * FROM reminder_lists ORDER BY sort, name'),
            'projects' => TimeTracker::ProjectOptions(),
            'services' => TimeTracker::Services(),
            'accounts' => rows('SELECT id, name FROM fin_accounts WHERE archived = 0 ORDER BY sort, name'),
            'categories' => rows('SELECT id, name, icon, kind FROM fin_categories WHERE archived = 0 ORDER BY sort, name'),
            'clients' => rows('SELECT id, name FROM clients WHERE archived = 0 ORDER BY name'),
            'payees' => db()->column("SELECT payee FROM fin_transactions WHERE payee IS NOT NULL AND kind = 'expense' GROUP BY payee ORDER BY MAX(date) DESC LIMIT 150"),
        );
    } catch (Throwable $e) {
        $base['quick'] = array();
    }
}

$base['csrf'] = App::CsrfToken();
$base['now'] = App::Now()->format('Y-m-d H:i:s');
$base['today'] = today();

$twig->addGlobal('data', $pageVars);
$twig->addGlobal('messages', App::CompileMessages());
$twig->addGlobal('base', $base);
$twig->addGlobal('session', $_SESSION);
$twig->addGlobal('user', App::User());

http_response_code($httpStatus);
header('Cache-Control: no-store');

try {
    echo $twig->render('pages/' . $pageNeeded . '.html');
} catch (Throwable $e) {
    (new Log())->write('Twig: ' . $e->getMessage());
    http_response_code(500);
    echo $twig->render('pages/error.html', array('data' => array(
        'error_title' => 'Something went wrong',
        'error_message' => DEBUG ? $e->getMessage() : 'The error was logged.'
    )));
}

// Send queued emails after the page has gone out (dev/cron.php catches anything left over)
if (App::IsAuthenticated()) {
    if (function_exists('fastcgi_finish_request')) {
        fastcgi_finish_request();
    }
    try {
        if (val('SELECT 1 FROM email_queue WHERE sent_at IS NULL AND attempts < 5 LIMIT 1')) {
            Mailer::Flush(5);
        }
    } catch (Throwable $e) {
        (new Log())->write('Mail flush: ' . $e->getMessage());
    }
}
