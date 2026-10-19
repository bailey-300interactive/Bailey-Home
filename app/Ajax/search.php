<?php

/**
 * Search everything (the Ctrl+K window)
 */

define('ROOT', realpath(__DIR__ . '/../..') . '/');
require(ROOT . 'app/init.php');

App::RequireLogin();
App::CheckCsrf();

$q = trim(post('q'));
if (mb_strlen($q) < 2) {
    json_out(array('result' => true, 'groups' => array()));
}
$like = '%' . $q . '%';
$groups = array();

// Pages (so "budgets" or "timesheet" jumps there)
$pages = array(
    'Dashboard' => '/', 'Reminders' => '/reminders', 'Vault' => '/vault', 'Password health' => '/vault/health', 'Password generator' => '/vault/generator',
    'Import passwords' => '/vault/import', 'Money' => '/money', 'Transactions' => '/money/transactions', 'Budgets' => '/money/budgets', 'Bills & subscriptions' => '/money/bills',
    'Accounts' => '/money/accounts', 'Goals' => '/money/goals', 'Money reports' => '/money/reports', 'Import transactions' => '/money/import', 'Categories & rules' => '/money/categories',
    'Time' => '/time', 'Timesheet' => '/time/timesheet', 'Clients' => '/time/clients', 'Projects' => '/time/projects', 'Invoices' => '/time/invoices', 'New invoice' => '/time/invoice-new',
    'Time reports' => '/time/reports', 'Services' => '/time/services', 'Profile' => '/profile',
);
if (App::IsAdmin()) {
    $pages['Settings'] = '/admin/settings';
    $pages['Users'] = '/admin/users';
}
$items = array();
foreach ($pages as $name => $url) {
    if (stripos($name, $q) !== false) {
        $items[] = array('title' => $name, 'url' => $url, 'icon' => '→', 'sub' => 'Page');
    }
}
if ($items) {
    $groups[] = array('label' => 'Go to', 'items' => array_slice($items, 0, 4));
}

$items = array();
foreach (rows('SELECT id, name, color FROM clients WHERE name LIKE ? OR contact_first LIKE ? OR contact_last LIKE ? OR email LIKE ? ORDER BY archived, name LIMIT 5', array($like, $like, $like, $like)) as $r) {
    $items[] = array('title' => $r['name'], 'url' => '/time/client/' . $r['id'], 'icon' => '💼', 'sub' => 'Client');
}
foreach (rows('SELECT p.id, p.name, c.name AS client FROM projects p JOIN clients c ON c.id = p.client_id WHERE p.name LIKE ? ORDER BY p.status = \'active\' DESC, p.name LIMIT 5', array($like)) as $r) {
    $items[] = array('title' => $r['name'], 'url' => '/time/project/' . $r['id'], 'icon' => '📁', 'sub' => $r['client']);
}
foreach (rows('SELECT i.id, i.number, i.total, i.status, c.name AS client FROM invoices i JOIN clients c ON c.id = i.client_id WHERE i.number LIKE ? OR i.title LIKE ? ORDER BY i.id DESC LIMIT 5', array($like, $like)) as $r) {
    $items[] = array('title' => $r['number'] . ' · ' . money($r['total']), 'url' => '/time/invoice/' . $r['id'], 'icon' => '🧾', 'sub' => $r['client'] . ' · ' . $r['status']);
}
if ($items) {
    $groups[] = array('label' => 'Work', 'items' => $items);
}

$items = array();
foreach (rows("SELECT id, title, due_date, status FROM reminders WHERE (title LIKE ? OR notes LIKE ?) ORDER BY status = 'open' DESC, due_date LIMIT 6", array($like, $like)) as $r) {
    $items[] = array('title' => $r['title'], 'url' => '/reminders' . ($r['status'] === 'done' ? '/done' : '') . '?open=' . $r['id'], 'icon' => $r['status'] === 'done' ? '✅' : '🔔', 'sub' => $r['due_date'] ? TwigExtras::RelativeDay($r['due_date']) : 'Someday');
}
if ($items) {
    $groups[] = array('label' => 'Reminders', 'items' => $items);
}

$items = array();
foreach (rows('SELECT id, name, amount, next_due FROM fin_bills WHERE name LIKE ? LIMIT 4', array($like)) as $r) {
    $items[] = array('title' => $r['name'] . ' · ' . money($r['amount']), 'url' => '/money/bills?pay=' . $r['id'], 'icon' => '📅', 'sub' => 'Bill, due ' . TwigExtras::RelativeDay($r['next_due']));
}
$payees = rows("SELECT payee, COUNT(*) AS n, -SUM(amount) AS total FROM fin_transactions WHERE payee LIKE ? AND kind = 'expense' GROUP BY payee ORDER BY n DESC LIMIT 4", array($like));
foreach ($payees as $r) {
    $items[] = array('title' => $r['payee'], 'url' => '/money/transactions?q=' . urlencode($r['payee']), 'icon' => '💳', 'sub' => $r['n'] . ' transactions · ' . money($r['total']));
}
foreach (rows('SELECT id, name FROM fin_accounts WHERE name LIKE ? LIMIT 3', array($like)) as $r) {
    $items[] = array('title' => $r['name'], 'url' => '/money/transactions?account=' . $r['id'], 'icon' => '🏦', 'sub' => 'Account');
}
if ($items) {
    $groups[] = array('label' => 'Money', 'items' => $items);
}

// Vault: names only, and only when it's open
if (Vault::Key(false) !== null) {
    $items = array();
    $ql = mb_strtolower($q);
    foreach (Vault::Items() as $it) {
        $d = $it['d'];
        $hay = mb_strtolower(($d['name'] ?? '') . ' ' . ($d['username'] ?? '') . ' ' . ($d['url'] ?? ''));
        if (str_contains($hay, $ql)) {
            $items[] = array('title' => $d['name'] ?? '', 'url' => '/vault?item=' . $it['id'], 'icon' => '🔑', 'sub' => $d['username'] ?? ($d['ssid'] ?? ''));
            if (count($items) >= 6) {
                break;
            }
        }
    }
    if ($items) {
        $groups[] = array('label' => 'Vault', 'items' => $items);
    }
} elseif (Vault::IsSetUp()) {
    $groups[] = array('label' => 'Vault', 'items' => array(array('title' => 'Unlock the vault to search passwords', 'url' => '/vault', 'icon' => '🔒', 'sub' => '')));
}

json_out(array('result' => true, 'groups' => $groups));
