<?php

/**
 * Sample data so you can try everything: php dev/install.php --demo
 * Adds clients, projects, ~8 weeks of time, invoices, accounts, 3 months of transactions, bills, goals and reminders.
 * Only runs when there are no clients and no money accounts yet.
 */

if (PHP_SAPI !== 'cli') {
    exit;
}
if ((int)val('SELECT COUNT(*) FROM clients') || (int)val('SELECT COUNT(*) FROM fin_accounts')) {
    say('Demo skipped: there is already data.');
    return;
}
mt_srand(42);
$u = row('SELECT * FROM admin_users ORDER BY Id_admin_users LIMIT 1');
$_SESSION = array('authenticated' => true, 'user_Id' => (int)$u['Id_admin_users'], 'auth_level' => (int)$u['auth_level']);
$uid = (int)$u['Id_admin_users'];
$now = App::UtcNow();
$today = today();
$d = function ($days) use ($today) { return date('Y-m-d', strtotime($today . ' ' . ($days >= 0 ? '+' : '') . $days . ' days')); };

App::SaveSetting('company_name', '300 Interactive');
App::SaveSetting('company_email', 'billing@300interactive.com');
App::SaveSetting('company_address', "123 Market Street, Suite 4\nNew Castle, PA 16101");
App::SaveSetting('payment_instructions', 'ACH or check payable to 300 Interactive. Zelle: billing@300interactive.com');

/* ---------- Clients & projects ---------- */
$svc = array();
foreach (rows('SELECT id, name FROM services') as $s) {
    $svc[$s['name']] = (int)$s['id'];
}
$clients = array(
    array('Keystone Dental Group', 'Maria', 'Lopez', 'maria@keystonedental.example', '#2a78d6', 125, array(
        array('Website rebuild', 'hourly_service', null, null, 80, null),
        array('Monthly hosting & care', 'flat_fee', null, 450, null, null),
    )),
    array('Brandywine Brewing Co.', 'Tom', 'Becker', 'tom@brandywinebrew.example', '#eb6834', 110, array(
        array('Online store', 'hourly_project', 110, null, 60, null),
        array('Taproom menu screens', 'hourly_project', 110, null, null, 2500),
    )),
    array('Riverside Realty', 'Jen', 'Park', 'jen@riversiderealty.example', '#1baf7a', 135, array(
        array('Server migration', 'hourly_project', 135, null, 30, null),
    )),
    array('Lakeview Montessori', 'Sam', 'Rivera', 'office@lakeviewmontessori.example', '#4a3aa7', 95, array(
        array('Enrollment portal', 'hourly_project', 95, null, 40, null),
        array('Internal R&D', 'non_billable', null, null, null, null),
    )),
);
$projectIds = array();
foreach ($clients as $c) {
    $cid = insert('clients', array('name' => $c[0], 'contact_first' => $c[1], 'contact_last' => $c[2], 'email' => $c[3], 'color' => $c[4], 'default_rate' => $c[5],
        'city' => 'New Castle', 'state' => 'PA', 'zip' => '16101', 'address1' => mt_rand(100, 999) . ' Main St', 'payment_terms' => 30, 'created_at' => $now));
    foreach ($c[6] as $p) {
        $projectIds[] = array('id' => insert('projects', array('client_id' => $cid, 'name' => $p[0], 'billing' => $p[1], 'rate' => $p[2], 'flat_fee' => $p[3], 'budget_hours' => $p[4], 'budget_amount' => $p[5], 'status' => 'active', 'color' => $c[4], 'created_at' => $now)), 'client' => $cid, 'billing' => $p[1], 'name' => $p[0]);
    }
}

/* ---------- Time: last 8 weeks, weekdays ---------- */
$notes = array(
    'Website rebuild' => array('Homepage layout and hero', 'Booking form + validation', 'Provider bios page', 'Accessibility fixes', 'Staging review with Maria'),
    'Monthly hosting & care' => array('WordPress + plugin updates', 'Backup check', 'Uptime alert follow-up'),
    'Online store' => array('Product import from spreadsheet', 'Checkout testing', 'Shipping rules', 'Call with Tom about launch'),
    'Taproom menu screens' => array('Menu screen template', 'Untappd feed sync'),
    'Server migration' => array('New droplet + hardening', 'DNS cutover plan', 'Migrated MLS sync cron', 'SSL + redirects'),
    'Enrollment portal' => array('Parent login flow', 'Waitlist report', 'Payment plan form'),
    'Internal R&D' => array('Ezra platform admin', 'Bug tracker tweaks'),
);
$services = array('Website rebuild' => array('Development', 'Design', 'Meetings'), 'Online store' => array('Development'), 'Server migration' => array('Server & infrastructure'),
    'Enrollment portal' => array('Development', 'Design'), 'Monthly hosting & care' => array('Support'), 'Taproom menu screens' => array('Design', 'Development'), 'Internal R&D' => array('Internal / non-billable'));
for ($day = -55; $day <= 0; $day++) {
    $date = $d($day);
    $dow = (int)date('N', strtotime($date));
    if ($dow >= 6) {
        continue;
    }
    $n = mt_rand(2, 4);
    for ($i = 0; $i < $n; $i++) {
        $p = $projectIds[mt_rand(0, count($projectIds) - 1)];
        if ($p['name'] === 'Taproom menu screens' && $day < -30) {
            continue;
        }
        $svcName = $services[$p['name']][mt_rand(0, count($services[$p['name']]) - 1)];
        insert('time_entries', array('user_id' => $uid, 'client_id' => $p['client'], 'project_id' => $p['id'], 'service_id' => $svc[$svcName] ?? null, 'date' => $date,
            'duration' => mt_rand(3, 14) * 900, 'notes' => $notes[$p['name']][mt_rand(0, count($notes[$p['name']]) - 1)], 'billable' => 1, 'created_at' => $now));
    }
}

/* ---------- Money ---------- */
$acct = array(
    'checking' => insert('fin_accounts', array('name' => 'PNC Checking', 'type' => 'checking', 'institution' => 'PNC', 'last4' => '4417', 'opening_balance' => 6200, 'opening_date' => $d(-100), 'color' => '#2a78d6', 'in_net_worth' => 1, 'sort' => 1, 'created_at' => $now)),
    'business' => insert('fin_accounts', array('name' => '300 Interactive Business', 'type' => 'checking', 'institution' => 'PNC', 'last4' => '8820', 'opening_balance' => 3100, 'opening_date' => $d(-100), 'color' => '#4a3aa7', 'in_net_worth' => 1, 'sort' => 2, 'created_at' => $now)),
    'savings' => insert('fin_accounts', array('name' => 'Ally Savings', 'type' => 'savings', 'institution' => 'Ally', 'opening_balance' => 12500, 'opening_date' => $d(-100), 'color' => '#1baf7a', 'in_net_worth' => 1, 'sort' => 3, 'created_at' => $now)),
    'card' => insert('fin_accounts', array('name' => 'Chase Sapphire', 'type' => 'credit', 'institution' => 'Chase', 'last4' => '9031', 'opening_balance' => -840, 'opening_date' => $d(-100), 'credit_limit' => 12000, 'color' => '#eb6834', 'in_net_worth' => 1, 'sort' => 4, 'created_at' => $now)),
);
$cat = array();
foreach (rows('SELECT id, name FROM fin_categories') as $c) {
    $cat[$c['name']] = (int)$c['id'];
}
foreach (array('Groceries' => 900, 'Dining out' => 350, 'Utilities' => 320, 'Transportation' => 400, 'Kids' => 250, 'Shopping' => 300, 'Subscriptions' => 120, 'Entertainment' => 150, 'Housing' => 1850) as $name => $b) {
    run('UPDATE fin_categories SET budget = ? WHERE id = ?', array($b, $cat[$name]));
}
$tx = function ($account, $date, $payee, $amount, $category, $extra = array()) use ($now, $uid) {
    return insert('fin_transactions', array_merge(array('account_id' => $account, 'date' => $date, 'payee' => $payee, 'amount' => $amount, 'kind' => $amount < 0 ? 'expense' : 'income',
        'category_id' => $category, 'created_by' => $uid, 'created_at' => $now), $extra));
};
$grocers = array('Giant Eagle', 'Aldi', 'Costco', 'Trader Joe\'s');
$food = array('Chipotle', 'Primanti Bros', 'Panera Bread', 'Sheetz', 'Starbucks', 'Pizza Joe\'s');
$shops = array('Amazon', 'Target', 'Home Depot', 'Old Navy');
for ($day = -95; $day <= 0; $day++) {
    $date = $d($day);
    $dom = (int)date('j', strtotime($date));
    if ($dom === 1) {
        $tx($acct['checking'], $date, 'Mortgage - Dollar Bank', -1850, $cat['Housing']);
    }
    if ($dom === 15 || $dom === 30) {
        $tx($acct['checking'], $date, 'Paycheck - Agency', 3280, $cat['Paycheck']);
    }
    if ($dom === 5) {
        $tx($acct['card'], $date, 'Netflix', -15.49, $cat['Subscriptions']);
        $tx($acct['card'], $date, 'Spotify', -11.99, $cat['Subscriptions']);
    }
    if ($dom === 8) {
        $tx($acct['checking'], $date, 'Penn Power', -(mt_rand(11000, 16000) / 100), $cat['Utilities']);
        $tx($acct['business'], $date, 'DigitalOcean', -(mt_rand(8800, 9600) / 100), $cat['Business'], array('memo' => 'Client droplets'));
    }
    if ($dom === 12) {
        $tx($acct['checking'], $date, 'Comcast Xfinity', -89.99, $cat['Utilities']);
        $tx($acct['checking'], $date, 'Transfer to savings', -500, null, array('kind' => 'transfer', 'transfer_group' => 'demo' . $day, 'payee' => 'Transfer to Ally Savings'));
        $tx($acct['savings'], $date, 'Transfer from PNC Checking', 500, null, array('kind' => 'transfer', 'transfer_group' => 'demo' . $day));
    }
    if ($dom === 20) {
        $tx($acct['checking'], $date, 'Erie Insurance', -168.40, $cat['Insurance']);
    }
    if ($dom === 22) {
        $tx($acct['checking'], $date, 'Chase card payment', -900, null, array('kind' => 'transfer', 'transfer_group' => 'democ' . $day));
        $tx($acct['card'], $date, 'Payment from PNC', 900, null, array('kind' => 'transfer', 'transfer_group' => 'democ' . $day));
    }
    if (mt_rand(0, 100) < 30) {
        $tx($acct['card'], $date, $grocers[mt_rand(0, 3)], -(mt_rand(3500, 18000) / 100), $cat['Groceries']);
    }
    if (mt_rand(0, 100) < 35) {
        $tx($acct['card'], $date, $food[mt_rand(0, 5)], -(mt_rand(600, 5200) / 100), $cat['Dining out']);
    }
    if (mt_rand(0, 100) < 12) {
        $tx($acct['card'], $date, $shops[mt_rand(0, 3)], -(mt_rand(1500, 12000) / 100), $cat['Shopping']);
    }
    if (mt_rand(0, 100) < 14) {
        $tx($acct['card'], $date, 'Sheetz Fuel', -(mt_rand(3800, 6200) / 100), $cat['Transportation']);
    }
    if (mt_rand(0, 100) < 6) {
        $tx($acct['card'], $date, 'Kidville Play Center', -(mt_rand(1500, 4500) / 100), $cat['Kids']);
    }
}
// a few uncategorized + a billable expense
$tx($acct['card'], $d(-3), 'Venmo', -42.00, null);
$tx($acct['card'], $d(-1), 'SQ *CUP OF JOY', -6.75, null);
$tx($acct['business'], $d(-9), 'Envato Market', -59.00, $cat['Business'], array('client_id' => val("SELECT id FROM clients WHERE name LIKE 'Keystone%'"), 'billable' => 1, 'memo' => 'Stock photos for provider pages'));

insert('fin_rules', array('match_text' => 'Giant Eagle', 'category_id' => $cat['Groceries'], 'created_at' => $now));
insert('fin_rules', array('match_text' => 'Sheetz Fuel', 'category_id' => $cat['Transportation'], 'created_at' => $now));

foreach (array(
    array('Mortgage - Dollar Bank', 1850, 'monthly', 1, 'Housing', 0, 1, 'checking'),
    array('Penn Power', 140, 'monthly', 8, 'Utilities', 0, 0, 'checking', 1),
    array('Comcast Xfinity', 89.99, 'monthly', 12, 'Utilities', 0, 1, 'checking'),
    array('Erie Insurance', 168.40, 'monthly', 20, 'Insurance', 0, 0, 'checking'),
    array('Netflix', 15.49, 'monthly', 5, 'Subscriptions', 1, 1, 'card'),
    array('Spotify', 11.99, 'monthly', 5, 'Subscriptions', 1, 1, 'card'),
    array('Adobe Creative Cloud', 59.99, 'monthly', 18, 'Business', 1, 1, 'business'),
    array('Domain renewals', 210, 'yearly', 0, 'Business', 0, 0, 'business'),
) as $b) {
    $due = $b[3] ? date('Y-m-', strtotime($today)) . str_pad((string)$b[3], 2, '0', STR_PAD_LEFT) : $d(19);
    if ($b[3] && $due < $d(-2)) {
        $due = Finance::NextBillDate($due, 'monthly');
    }
    insert('fin_bills', array('name' => $b[0], 'amount' => $b[1], 'frequency' => $b[2], 'next_due' => $due, 'category_id' => $cat[$b[4]], 'is_subscription' => $b[5], 'autopay' => $b[6],
        'account_id' => $acct[$b[7]], 'variable_amount' => $b[8] ?? 0, 'remind_days' => 3, 'active' => 1, 'created_at' => $now));
}
insert('fin_goals', array('name' => 'Emergency fund', 'emoji' => '🛟', 'target' => 20000, 'account_id' => $acct['savings'], 'target_date' => $d(300), 'color' => '#1baf7a', 'created_at' => $now));
insert('fin_goals', array('name' => 'Outer Banks trip', 'emoji' => '🏖️', 'target' => 3500, 'saved' => 1350, 'target_date' => $d(240), 'color' => '#2a78d6', 'created_at' => $now));

/* ---------- Invoices ---------- */
$clientIds = db()->column('SELECT id FROM clients ORDER BY id');
// Older invoices from time up to ~4 weeks ago, for 3 clients; paid / partly paid / overdue
$issue = $d(-27);
foreach (array_slice($clientIds, 0, 3) as $i => $cid) {
    $id = Invoice::CreateFromUnbilled($cid, array('from' => $d(-60), 'to' => $d(-28), 'group' => 'service', 'include_expenses' => 0, 'include_flat' => $i === 0, 'issue_date' => $issue));
    Invoice::MarkSent($id);
    run('UPDATE invoices SET sent_at = ? WHERE id = ?', array(App::ToUtc($issue . ' 10:00'), $id));
    $inv = Invoice::Get($id);
    if ($i === 0) {
        Invoice::AddPayment($id, $inv['total'], $d(-10), 'ach', '', $acct['business']);
    } elseif ($i === 1) {
        Invoice::AddPayment($id, round($inv['total'] / 2, 2), $d(-6), 'check', 'Check #2214', $acct['business']);
    } else {
        run('UPDATE invoices SET due_date = ? WHERE id = ?', array($d(-4), $id));
    }
}
// A draft for the 4th client
Invoice::CreateFromUnbilled($clientIds[3], array('from' => $d(-60), 'to' => $d(-14), 'group' => 'project', 'include_expenses' => 1, 'include_flat' => 1));

/* ---------- Running timer ---------- */
insert('time_entries', array('user_id' => $uid, 'client_id' => $projectIds[0]['client'], 'project_id' => $projectIds[0]['id'], 'service_id' => $svc['Development'], 'date' => $today,
    'started_at' => gmdate('Y-m-d H:i:s', time() - 47 * 60), 'duration' => null, 'notes' => 'Insurance page + forms', 'billable' => 1, 'created_at' => $now));

/* ---------- Reminders ---------- */
$lists = array();
foreach (rows('SELECT id, name FROM reminder_lists') as $l) {
    $lists[$l['name']] = (int)$l['id'];
}
foreach (array(
    'Take out trash and recycling every tue 7pm #home',
    'Renew Riverside SSL certificate in 3 days !high #work',
    'Call Tom about store launch tomorrow 10am #work',
    'Pediatrician appointment for Ezra next thursday 9:30am #personal',
    'Change furnace filter every 3 months #home',
    'Water the garden every day 6pm #home',
    'Back up Ezra apps database every week #work',
    'Pay estimated taxes on the 15th !high #money',
    'Order birthday gift for Mom',
) as $text) {
    $q = Reminder::ParseQuick($text);
    Reminder::Save(null, $q);
}
// one overdue
Reminder::Save(null, array('title' => 'Send Keystone the staging link', 'due_date' => $d(-1), 'priority' => 2, 'list_id' => $lists['Work'] ?? null, 'notify' => 1));
Reminder::Save(null, array('title' => 'Review this month\'s budget', 'due_date' => $today, 'due_time' => '20:00', 'list_id' => $lists['Money'] ?? null, 'notify' => 1));
$r = Reminder::Save(null, array('title' => 'Check the mail', 'due_date' => $today, 'list_id' => $lists['Home'] ?? null));
Reminder::Complete($r);

App::Activity('time', 'Sample data added', '/');
say('✓ Demo data added');
