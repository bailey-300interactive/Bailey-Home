<?php

/**
 * Home installer (command line only).
 *
 *   php dev/install.php                    tables, starter data, your admin login
 *   php dev/install.php --reset-password   change anyone's login password
 *   php dev/install.php --demo             also add sample clients, time, money and reminders
 *
 * Safe to run again: it only adds what's missing and runs new migrations.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

define('ROOT', realpath(__DIR__ . '/..') . '/');
require(ROOT . 'app/init.php');

$args = getopt('', array('reset-password', 'demo', 'username:', 'password:', 'first:', 'last:', 'email:'));

function say($s) { fwrite(STDOUT, $s . PHP_EOL); }
function ask($q, $default = '') {
    fwrite(STDOUT, $q . ($default !== '' ? " [$default]" : '') . ': ');
    $a = trim((string)fgets(STDIN));
    return $a === '' ? $default : $a;
}
function ask_secret($q) {
    fwrite(STDOUT, $q . ': ');
    if (DIRECTORY_SEPARATOR === '/' && posix_isatty(STDIN)) {
        shell_exec('stty -echo');
        $a = trim((string)fgets(STDIN));
        shell_exec('stty echo');
        fwrite(STDOUT, PHP_EOL);
        return $a;
    }
    return trim((string)fgets(STDIN));
}

try {
    db();
} catch (Throwable $e) {
    say('✗ ' . $e->getMessage());
    exit(1);
}

/* ---------- Reset password ---------- */
if (isset($args['reset-password'])) {
    $username = $args['username'] ?? ask('Username (email) to change');
    $u = row('SELECT * FROM admin_users WHERE username = ?', array($username));
    if (!$u) {
        say('✗ No user with that username.');
        exit(1);
    }
    $pw = $args['password'] ?? ask_secret('New password (12+ characters)');
    if (strlen($pw) < 12) {
        say('✗ Use at least 12 characters.');
        exit(1);
    }
    run('UPDATE admin_users SET password = ?, failed_logins = 0, locked_until = NULL WHERE Id_admin_users = ?', array(password_hash($pw, PASSWORD_DEFAULT), $u['Id_admin_users']));
    say('✓ Password changed. (The Vault master password is separate and is not changed by this.)');
    exit(0);
}

/* ---------- Tables ---------- */
say('Creating tables…');
$sql = file_get_contents(ROOT . 'dev/database/schema.sql');
$sql = preg_replace('/^--.*$/m', '', $sql);
foreach (array_filter(array_map('trim', explode(';', $sql))) as $stmt) {
    db()->query($stmt);
}
say('✓ Tables ready');

/* ---------- Migrations ---------- */
$fresh = (int)val('SELECT COUNT(*) FROM settings') === 0;
$files = glob(ROOT . 'dev/database/migrations/*.{sql,php}', GLOB_BRACE) ?: array();
sort($files);
foreach ($files as $f) {
    $name = basename($f);
    if (val('SELECT 1 FROM migrations WHERE name = ?', array($name))) {
        continue;
    }
    if (!$fresh) {
        say('  running migration ' . $name);
        if (str_ends_with($f, '.php')) {
            require $f;
        } else {
            foreach (array_filter(array_map('trim', explode(';', preg_replace('/^--.*$/m', '', file_get_contents($f))))) as $stmt) {
                db()->query($stmt);
            }
        }
    }
    run('INSERT INTO migrations (name, ran_at) VALUES (?, ?)', array($name, App::UtcNow()));
}

/* ---------- Starter data ---------- */
run("INSERT IGNORE INTO user_level_settings (Id_user_level_settings, display_name, folder) VALUES (0, 'Administrator', 'admin'), (10, 'Member', 'user')");

$defaults = array(
    'site_name' => 'Home',
    'currency' => 'USD',
    'timezone' => 'America/New_York',
    'week_start' => 'monday',
    'company_name' => '300 Interactive',
    'company_email' => '',
    'company_address' => '',
    'company_phone' => '',
    'invoice_prefix' => 'INV-',
    'invoice_next' => '1001',
    'invoice_terms' => '30',
    'invoice_notes' => 'Thank you for your business!',
    'default_rate' => '100',
    'tax_name' => '',
    'tax_rate' => '0',
    'vault_auto_lock' => '15',
    'digest_hour' => '7',
    'round_minutes' => '0',
    'secret_key' => bin2hex(random_bytes(32)),
);
foreach ($defaults as $k => $v) {
    run('INSERT IGNORE INTO settings (k, v) VALUES (?, ?)', array($k, $v));
}

if ((int)val('SELECT COUNT(*) FROM fin_categories') === 0) {
    $cats = array(
        // name, kind, icon, color, budget
        array('Paycheck', 'income', '💼', '#1cbb8c', null),
        array('Client income', 'income', '🧾', '#17a2b8', null),
        array('Other income', 'income', '💵', '#20c997', null),
        array('Housing', 'expense', '🏠', '#3b7ddd', null),
        array('Utilities', 'expense', '💡', '#fcb92c', null),
        array('Groceries', 'expense', '🛒', '#28a745', null),
        array('Dining out', 'expense', '🍔', '#fd7e14', null),
        array('Transportation', 'expense', '🚗', '#6f42c1', null),
        array('Kids', 'expense', '🧸', '#e83e8c', null),
        array('Health', 'expense', '🩺', '#dc3545', null),
        array('Shopping', 'expense', '🛍️', '#6610f2', null),
        array('Subscriptions', 'expense', '📺', '#17a2b8', null),
        array('Entertainment', 'expense', '🎬', '#ff6384', null),
        array('Business', 'expense', '🖥️', '#495057', null),
        array('Insurance', 'expense', '🛡️', '#6c757d', null),
        array('Gifts & giving', 'expense', '🎁', '#d63384', null),
        array('Savings', 'expense', '🐷', '#20c997', null),
        array('Other', 'expense', '📦', '#adb5bd', null),
    );
    foreach ($cats as $i => $c) {
        insert('fin_categories', array('name' => $c[0], 'kind' => $c[1], 'icon' => $c[2], 'color' => $c[3], 'budget' => $c[4], 'sort' => $i));
    }
    say('✓ Money categories added');
}

if ((int)val('SELECT COUNT(*) FROM reminder_lists') === 0) {
    foreach (array(array('Personal', '#3b7ddd', '🙂'), array('Home', '#1cbb8c', '🏠'), array('Work', '#fcb92c', '💼'), array('Money', '#dc3545', '💳')) as $i => $l) {
        insert('reminder_lists', array('name' => $l[0], 'color' => $l[1], 'icon' => $l[2], 'sort' => $i));
    }
    say('✓ Reminder lists added');
}

if ((int)val('SELECT COUNT(*) FROM services') === 0) {
    foreach (array(array('Development', 125), array('Design', 110), array('Server & infrastructure', 135), array('Meetings', 100), array('Support', 95)) as $i => $s) {
        insert('services', array('name' => $s[0], 'rate' => $s[1], 'billable' => 1, 'sort' => $i));
    }
    insert('services', array('name' => 'Internal / non-billable', 'rate' => null, 'billable' => 0, 'sort' => 99));
    say('✓ Services added');
}

/* ---------- Folders ---------- */
foreach (array('storage/receipts', 'storage/logs', 'storage/cache', 'storage/exports') as $d) {
    if (!is_dir(ROOT . $d)) {
        @mkdir(ROOT . $d, 0775, true);
    }
    say((is_writable(ROOT . $d) ? '✓ ' : '✗ not writable: ') . $d);
}

/* ---------- Admin login ---------- */
if ((int)val('SELECT COUNT(*) FROM admin_users') === 0) {
    say('');
    say('Create your login');
    $username = $args['username'] ?? ask('Email (used to sign in)');
    if (!filter_var($username, FILTER_VALIDATE_EMAIL)) {
        say('✗ That is not an email address.');
        exit(1);
    }
    $first = $args['first'] ?? ask('First name', 'Bailey');
    $last = $args['last'] ?? ask('Last name', '');
    $pw = $args['password'] ?? ask_secret('Password (12+ characters)');
    if (strlen($pw) < 12) {
        say('✗ Use at least 12 characters.');
        exit(1);
    }
    insert('admin_users', array(
        'username' => $username, 'email' => $username, 'password' => password_hash($pw, PASSWORD_DEFAULT),
        'auth_level' => ADMIN, 'first_name' => $first, 'last_name' => $last, 'title' => 'Administrator',
        'hourly_rate' => 125, 'weekly_hours_goal' => 40, 'created_at' => App::UtcNow()
    ));
    say('✓ Login created. Sign in, then open Vault to set your master password.');
}

if (isset($args['demo'])) {
    require ROOT . 'dev/database/demo.php';
}

say('');
say('Done. ' . (val('SELECT COUNT(*) FROM admin_users') . ' user(s).'));
