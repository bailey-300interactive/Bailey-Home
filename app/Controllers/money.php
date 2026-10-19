<?php

/**
 * Money pages: /money (overview), /money/transactions, /money/budgets, /money/bills, /money/accounts,
 * /money/goals, /money/reports, /money/import, /money/categories, /money/receipt/<file>
 */

App::RequireLogin();
$base['page_title'] = 'Money';
$month = preg_match('/^\d{4}-\d{2}$/', $_GET['month'] ?? '') ? $_GET['month'] : substr(today(), 0, 7);
$pageVars['month'] = $month;
$pageVars['month_label'] = date('F Y', strtotime($month . '-01'));
$pageVars['prev_month'] = date('Y-m', strtotime($month . '-01 -1 month'));
$pageVars['next_month'] = date('Y-m', strtotime($month . '-01 +1 month'));
$pageVars['accounts'] = Finance::Accounts();
$pageVars['categories'] = Finance::Categories();
$pageVars['account_types'] = Finance::ACCOUNT_TYPES;
$pageVars['frequencies'] = Finance::FREQUENCIES;
$pageVars['clients'] = rows('SELECT id, name FROM clients WHERE archived = 0 ORDER BY name');
$pageVars['projects'] = TimeTracker::ProjectOptions();

/* ---------- Form posts ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    App::CheckCsrf();
    $action = post('action');
    $back = $_SERVER['REQUEST_URI'];
    try {
        switch ($action) {
            case 'account-save':
                $data = array(
                    'name' => mb_substr(post('name'), 0, 80) ?: 'Account', 'type' => isset(Finance::ACCOUNT_TYPES[post('type')]) ? post('type') : 'checking',
                    'institution' => mb_substr(post('institution'), 0, 80) ?: null, 'last4' => preg_replace('/\D/', '', substr(post('last4'), -4)) ?: null,
                    'opening_balance' => parse_money(post('opening_balance')), 'opening_date' => valid_date(post('opening_date')),
                    'credit_limit' => post('credit_limit') !== '' ? parse_money(post('credit_limit')) : null,
                    'color' => preg_match('/^#[0-9a-f]{6}$/i', post('color')) ? post('color') : '#3b7ddd', 'in_net_worth' => post_bool('in_net_worth'), 'archived' => post_bool('archived'),
                );
                if (in_array($data['type'], array('credit', 'loan'), true) && $data['opening_balance'] > 0) {
                    $data['opening_balance'] = -$data['opening_balance']; // money owed is negative
                }
                if (post_id('id')) {
                    update('fin_accounts', 'id', post_id('id'), $data);
                } else {
                    $data['created_at'] = App::UtcNow();
                    $data['sort'] = (int)val('SELECT COALESCE(MAX(sort), 0) + 1 FROM fin_accounts');
                    insert('fin_accounts', $data);
                }
                App::QueueMessage('Account saved.');
                break;

            case 'account-reconcile':
                // Set today's balance: adds an adjustment so the balance matches the bank
                $acc = row('SELECT a.*, a.opening_balance + COALESCE((SELECT SUM(amount) FROM fin_transactions WHERE account_id = a.id), 0) AS balance FROM fin_accounts a WHERE a.id = ?', array(post_id('id')));
                $target = parse_money(post('balance'));
                if ($acc && in_array($acc['type'], array('credit', 'loan'), true) && $target > 0) {
                    $target = -$target;
                }
                $diff = round($target - (float)$acc['balance'], 2);
                if ($acc && abs($diff) >= 0.01) {
                    insert('fin_transactions', array('account_id' => $acc['id'], 'date' => today(), 'payee' => 'Balance adjustment', 'amount' => $diff, 'kind' => $diff > 0 ? 'income' : 'expense', 'category_id' => null, 'memo' => 'Matched to the bank balance', 'created_by' => App::UserId(), 'created_at' => App::UtcNow()));
                }
                App::QueueMessage('Balance updated.');
                break;

            case 'account-delete':
                if (val('SELECT COUNT(*) FROM fin_transactions WHERE account_id = ?', array(post_id('id')))) {
                    throw new RuntimeException('That account has transactions. Archive it instead.');
                }
                run('DELETE FROM fin_accounts WHERE id = ?', array(post_id('id')));
                break;

            case 'category-save':
                $data = array('name' => mb_substr(post('name'), 0, 64), 'kind' => post('kind') === 'income' ? 'income' : 'expense', 'icon' => mb_substr(post('icon'), 0, 8) ?: null,
                    'color' => preg_match('/^#[0-9a-f]{6}$/i', post('color')) ? post('color') : '#adb5bd', 'budget' => post('budget') !== '' ? abs(parse_money(post('budget'))) : null, 'archived' => post_bool('archived'));
                if ($data['name'] === '') {
                    throw new RuntimeException('Name the category.');
                }
                if (post_id('id')) {
                    update('fin_categories', 'id', post_id('id'), $data);
                } else {
                    $data['sort'] = (int)val('SELECT COALESCE(MAX(sort), 0) + 1 FROM fin_categories');
                    insert('fin_categories', $data);
                }
                break;

            case 'category-delete':
                $to = post_id('move_to');
                run('UPDATE fin_transactions SET category_id = ? WHERE category_id = ?', array($to, post_id('id')));
                run('UPDATE fin_bills SET category_id = ? WHERE category_id = ?', array($to, post_id('id')));
                run('DELETE FROM fin_rules WHERE category_id = ?', array(post_id('id')));
                run('DELETE FROM fin_categories WHERE id = ?', array(post_id('id')));
                App::QueueMessage('Category deleted.');
                break;

            case 'rule-save':
                if (post('match_text') === '') {
                    throw new RuntimeException('Enter the text to match.');
                }
                $data = array('match_text' => mb_substr(post('match_text'), 0, 128), 'category_id' => post_id('category_id'), 'rename_to' => mb_substr(post('rename_to'), 0, 128) ?: null);
                if (post_id('id')) {
                    update('fin_rules', 'id', post_id('id'), $data);
                } else {
                    insert('fin_rules', $data + array('created_at' => App::UtcNow()));
                }
                if (post_bool('apply')) {
                    App::QueueMessage('Rule saved. Updated ' . Finance::ApplyRules() . ' uncategorized transactions.');
                } else {
                    App::QueueMessage('Rule saved.');
                }
                break;

            case 'rule-delete':
                run('DELETE FROM fin_rules WHERE id = ?', array(post_id('id')));
                break;

            case 'rules-apply':
                App::QueueMessage('Updated ' . Finance::ApplyRules() . ' transactions.');
                break;

            case 'bill-save':
                $data = array(
                    'name' => mb_substr(post('name'), 0, 80), 'amount' => abs(parse_money(post('amount'))), 'variable_amount' => post_bool('variable_amount'),
                    'frequency' => isset(Finance::FREQUENCIES[post('frequency')]) ? post('frequency') : 'monthly', 'next_due' => valid_date(post('next_due')) ?: today(),
                    'account_id' => post_id('account_id'), 'category_id' => post_id('category_id'), 'is_subscription' => post_bool('is_subscription'), 'autopay' => post_bool('autopay'),
                    'remind_days' => max(0, min(30, (int)post('remind_days', 3))), 'url' => mb_substr(post('url'), 0, 255) ?: null, 'notes' => mb_substr(post('notes'), 0, 255) ?: null, 'active' => post('active', '1') === '1' ? 1 : 0,
                );
                if ($data['name'] === '') {
                    throw new RuntimeException('Name the bill.');
                }
                if (post_id('id')) {
                    update('fin_bills', 'id', post_id('id'), $data);
                } else {
                    insert('fin_bills', $data + array('created_at' => App::UtcNow()));
                }
                App::QueueMessage('Saved ' . $data['name'] . '.');
                $back = '/money/bills';
                break;

            case 'bill-pay':
                $r = Finance::PayBill(post_id('id'), post('amount'), post('date'), post_bool('record'), post_id('account_id'));
                App::QueueMessage('Marked paid. Next due ' . TwigExtras::RelativeDay($r['next']) . '.');
                $back = '/money/bills';
                break;

            case 'bill-skip':
                $b = row('SELECT * FROM fin_bills WHERE id = ?', array(post_id('id')));
                if ($b) {
                    update('fin_bills', 'id', $b['id'], array('next_due' => Finance::NextBillDate($b['next_due'], $b['frequency'])));
                }
                $back = '/money/bills';
                break;

            case 'bill-delete':
                run('DELETE FROM fin_bills WHERE id = ?', array(post_id('id')));
                $back = '/money/bills';
                break;

            case 'bill-from-suggestion':
                insert('fin_bills', array('name' => mb_substr(post('name'), 0, 80), 'amount' => abs(parse_money(post('amount'))), 'frequency' => 'monthly', 'next_due' => valid_date(post('next_due')) ?: today(),
                    'account_id' => post_id('account_id'), 'category_id' => post_id('category_id'), 'is_subscription' => 1, 'remind_days' => 3, 'active' => 1, 'created_at' => App::UtcNow()));
                App::QueueMessage('Added ' . post('name') . ' to bills.');
                $back = '/money/bills';
                break;

            case 'budget-save':
                foreach ((array)($_POST['budget'] ?? array()) as $catId => $amount) {
                    run('UPDATE fin_categories SET budget = ? WHERE id = ?', array(trim($amount) === '' ? null : abs(parse_money($amount)), (int)$catId));
                }
                App::QueueMessage('Budgets saved.');
                break;

            case 'budget-suggest':
                foreach (Finance::BudgetMonth(substr(today(), 0, 7))['categories'] as $c) {
                    if ($c['budget'] === null && $c['avg'] > 0) {
                        run('UPDATE fin_categories SET budget = ? WHERE id = ?', array(ceil($c['avg'] / 10) * 10, $c['id']));
                    }
                }
                App::QueueMessage('Filled empty budgets from your last 3 months of spending.');
                break;

            case 'goal-save':
                $data = array('name' => mb_substr(post('name'), 0, 80), 'emoji' => mb_substr(post('emoji'), 0, 8) ?: '🎯', 'target' => abs(parse_money(post('target'))), 'saved' => abs(parse_money(post('saved'))),
                    'account_id' => post_id('account_id'), 'target_date' => valid_date(post('target_date')), 'color' => preg_match('/^#[0-9a-f]{6}$/i', post('color')) ? post('color') : '#1cbb8c', 'archived' => post_bool('archived'));
                if ($data['name'] === '' || $data['target'] <= 0) {
                    throw new RuntimeException('Give the goal a name and a target.');
                }
                if (post_id('id')) {
                    update('fin_goals', 'id', post_id('id'), $data);
                } else {
                    insert('fin_goals', $data + array('created_at' => App::UtcNow()));
                }
                break;

            case 'goal-add':
                run('UPDATE fin_goals SET saved = GREATEST(0, saved + ?) WHERE id = ?', array(parse_money(post('amount')), post_id('id')));
                App::QueueMessage('Goal updated.');
                break;

            case 'goal-delete':
                run('DELETE FROM fin_goals WHERE id = ?', array(post_id('id')));
                break;

            case 'import-upload':
                $rows = read_csv_upload('file');
                if (count($rows) < 2) {
                    throw new RuntimeException('That file has no rows.');
                }
                $name = 'import-' . App::UserId() . '-' . bin2hex(random_bytes(6)) . '.json';
                file_put_contents(STORAGE . 'exports/' . $name, json_encode($rows));
                $_SESSION['money_import'] = array('file' => $name, 'account_id' => post_id('account_id'), 'name' => $_FILES['file']['name']);
                App::Redirect('/money/import');

            case 'import-map':
                $_SESSION['money_import']['map'] = array_map(function ($v) { return $v === '' ? '' : (int)$v; }, (array)($_POST['map'] ?? array()));
                $_SESSION['money_import']['flip'] = post_bool('flip');
                $_SESSION['money_import']['account_id'] = post_id('account_id');
                $_SESSION['money_import']['header'] = post_bool('header');
                App::Redirect('/money/import?step=review');

            case 'import-commit':
                $imp = $_SESSION['money_import'] ?? null;
                if (!$imp) {
                    throw new RuntimeException('Upload the file again.');
                }
                $rows = json_decode((string)@file_get_contents(STORAGE . 'exports/' . basename($imp['file'])), true) ?: array();
                if ($imp['header']) {
                    array_shift($rows);
                }
                $prepared = Finance::PrepareImport($rows, $imp['map'], $imp['flip'], $imp['account_id']);
                $take = array_flip(array_map('intval', (array)($_POST['take'] ?? array())));
                $cats = (array)($_POST['cat'] ?? array());
                $payees = (array)($_POST['payee'] ?? array());
                $n = 0;
                db()->beginTransaction();
                foreach ($prepared as $t) {
                    if (!isset($take[$t['i']])) {
                        continue;
                    }
                    $payee = isset($payees[$t['i']]) ? mb_substr(trim($payees[$t['i']]), 0, 128) : $t['payee'];
                    $catId = isset($cats[$t['i']]) && $cats[$t['i']] !== '' ? (int)$cats[$t['i']] : null;
                    insert('fin_transactions', array(
                        'account_id' => $imp['account_id'], 'date' => $t['date'], 'payee' => $payee ?: null, 'memo' => $t['memo'] ?: ($t['original'] !== $payee ? $t['original'] : null),
                        'amount' => $t['amount'], 'kind' => $t['amount'] < 0 ? 'expense' : 'income', 'category_id' => $catId, 'import_hash' => $t['hash'],
                        'created_by' => App::UserId(), 'created_at' => App::UtcNow(),
                    ));
                    $n++;
                }
                db()->executeTransaction();
                @unlink(STORAGE . 'exports/' . basename($imp['file']));
                unset($_SESSION['money_import']);
                App::Activity('money', 'Imported ' . $n . ' transactions', '/money/transactions');
                App::QueueMessage('Imported ' . $n . ' transactions.');
                App::Redirect('/money/transactions?account=' . $imp['account_id']);

            case 'import-cancel':
                if (!empty($_SESSION['money_import']['file'])) {
                    @unlink(STORAGE . 'exports/' . basename($_SESSION['money_import']['file']));
                }
                unset($_SESSION['money_import']);
                App::Redirect('/money/import');

            case 'tx-bulk':
                $ids = array_map('intval', (array)($_POST['ids'] ?? array()));
                if ($ids) {
                    $in = implode(',', $ids);
                    if (post('bulk') === 'delete') {
                        foreach ($ids as $id) {
                            try { Finance::DeleteTransaction($id); } catch (RuntimeException $e) {}
                        }
                        App::QueueMessage('Deleted ' . count($ids) . ' transactions.');
                    } elseif (post('bulk') === 'category') {
                        run("UPDATE fin_transactions SET category_id = ? WHERE id IN ($in) AND kind <> 'transfer'", array(post_id('category_id')));
                        App::QueueMessage('Updated ' . count($ids) . ' transactions.');
                    }
                }
                break;
        }
    } catch (RuntimeException $e) {
        db()->rollBack();
        App::QueueMessage($e->getMessage(), 'error');
    }
    App::Redirect($back);
}

switch ($p1) {
    /* ---------- Receipt files (only for signed-in people) ---------- */
    case 'receipt':
        $file = basename($p2);
        $path = STORAGE . 'receipts/' . $file;
        if ($file === '' || !is_file($path) || !val('SELECT 1 FROM fin_transactions WHERE receipt = ?', array($file))) {
            App::NotFound();
        }
        $mime = (new finfo(FILEINFO_MIME_TYPE))->file($path);
        header('Content-Type: ' . $mime);
        header('Content-Length: ' . filesize($path));
        header('Content-Disposition: inline; filename="' . $file . '"');
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: private, max-age=86400');
        readfile($path);
        exit;

    case 'transactions':
        $f = array_intersect_key($_GET, array_flip(array('account', 'category', 'kind', 'client', 'billable', 'from', 'to', 'month', 'tag', 'q')));
        $page = max(1, (int)($_GET['page'] ?? 1));
        if (isset($_GET['export'])) {
            list($list) = Finance::Search($f, 100000);
            csv_out('transactions-' . today() . '.csv', array('Date', 'Account', 'Payee', 'Category', 'Amount', 'Kind', 'Memo', 'Tags', 'Client', 'Project', 'Billable', 'Invoice'), array_map(function ($t) {
                return array($t['date'], $t['account_name'], $t['payee'], $t['category_name'], number_format($t['amount'], 2, '.', ''), $t['kind'], $t['memo'], $t['tags'], $t['client_name'], $t['project_name'], $t['billable'] ? 'yes' : '', $t['invoice_number']);
            }, $list));
        }
        list($list, $count, $sums) = Finance::Search($f, 100, ($page - 1) * 100);
        $pageVars += array('list' => $list, 'count' => $count, 'sums' => $sums, 'f' => $f, 'page' => $page, 'pages' => (int)ceil($count / 100),
            'uncategorized' => (int)val("SELECT COUNT(*) FROM fin_transactions WHERE category_id IS NULL AND kind <> 'transfer'"));
        $base['page_title'] = 'Transactions';
        $pageNeeded = 'money/transactions';
        return;

    case 'budgets':
        $pageVars['b'] = Finance::BudgetMonth($month);
        $base['page_title'] = 'Budgets';
        $pageNeeded = 'money/budgets';
        return;

    case 'bills':
        $bills = Finance::Bills();
        $monthly = $subs = 0;
        foreach ($bills as $b) {
            if ($b['active']) {
                $monthly += $b['monthly'];
                if ($b['is_subscription']) {
                    $subs += $b['monthly'];
                }
            }
        }
        $pageVars += array('bills' => $bills, 'monthly' => $monthly, 'subs' => $subs, 'suggested' => Finance::SuggestedBills(), 'pay' => isset($_GET['pay']) ? (int)$_GET['pay'] : null);
        $base['page_title'] = 'Bills & subscriptions';
        $pageNeeded = 'money/bills';
        return;

    case 'accounts':
        $pageVars['all_accounts'] = Finance::Accounts(true);
        $pageVars['net'] = Finance::NetWorth();
        $pageVars['series'] = Finance::NetWorthSeries(12);
        $base['page_title'] = 'Accounts';
        $pageNeeded = 'money/accounts';
        return;

    case 'goals':
        $goals = rows('SELECT g.*, a.name AS account_name, a.opening_balance + COALESCE((SELECT SUM(t.amount) FROM fin_transactions t WHERE t.account_id = a.id), 0) AS account_balance FROM fin_goals g LEFT JOIN fin_accounts a ON a.id = g.account_id ORDER BY g.archived, g.target_date IS NULL, g.target_date, g.name');
        foreach ($goals as &$g) {
            $g['current'] = $g['account_id'] ? max(0, (float)$g['account_balance']) : (float)$g['saved'];
            $g['pct'] = $g['target'] > 0 ? min(100, round($g['current'] / $g['target'] * 100)) : 0;
            $g['per_month'] = null;
            if ($g['target_date'] && $g['current'] < $g['target']) {
                $months = max(1, (int)ceil((strtotime($g['target_date']) - strtotime(today())) / (86400 * 30.44)));
                $g['per_month'] = round(($g['target'] - $g['current']) / $months, 2);
                $g['months_left'] = $months;
            }
        }
        $pageVars['goals'] = $goals;
        $base['page_title'] = 'Goals';
        $pageNeeded = 'money/goals';
        return;

    case 'reports':
        $range = $_GET['range'] ?? 'month';
        $to = today();
        switch ($range) {
            case 'last-month': $from = date('Y-m-01', strtotime(substr($to, 0, 7) . '-01 -1 month')); $to = date('Y-m-t', strtotime($from)); break;
            case '3m': $from = date('Y-m-d', strtotime($to . ' -3 months')); break;
            case 'ytd': $from = substr($to, 0, 4) . '-01-01'; break;
            case '12m': $from = date('Y-m-d', strtotime($to . ' -12 months')); break;
            case 'last-year': $from = (substr($to, 0, 4) - 1) . '-01-01'; $to = (substr($to, 0, 4) - 1) . '-12-31'; break;
            default: $range = 'month'; $from = substr($to, 0, 7) . '-01';
        }
        $pageVars += array(
            'range' => $range, 'from' => $from, 'to' => $to,
            'by_category' => Finance::SpendingByCategory($from, $to),
            'payees' => Finance::TopPayees($from, $to, 12),
            'monthly' => Finance::MonthlyTotals(12),
            'totals' => row("SELECT COALESCE(SUM(CASE WHEN kind = 'income' THEN amount END), 0) AS income, COALESCE(-SUM(CASE WHEN kind = 'expense' THEN amount END), 0) AS spent FROM fin_transactions WHERE date BETWEEN ? AND ?", array($from, $to)),
            'business' => rows("SELECT COALESCE(cl.name, 'No client') AS client, -SUM(t.amount) AS spent, SUM(t.billable) AS billable FROM fin_transactions t LEFT JOIN clients cl ON cl.id = t.client_id WHERE t.kind = 'expense' AND t.client_id IS NOT NULL AND t.date BETWEEN ? AND ? GROUP BY cl.name ORDER BY spent DESC", array($from, $to)),
        );
        $base['page_title'] = 'Money reports';
        $pageNeeded = 'money/reports';
        return;

    case 'import':
        $imp = $_SESSION['money_import'] ?? null;
        if ($imp) {
            $rows = json_decode((string)@file_get_contents(STORAGE . 'exports/' . basename($imp['file'])), true);
            if (!$rows) {
                unset($_SESSION['money_import']);
                App::Redirect('/money/import');
            }
            $header = $rows[0];
            $pageVars['imp'] = $imp;
            $pageVars['header'] = $header;
            $pageVars['sample'] = array_slice($rows, 1, 5);
            $pageVars['guess'] = $imp['map'] ?? Finance::GuessColumns($header);
            if (($_GET['step'] ?? '') === 'review' && isset($imp['map'])) {
                if ($imp['header']) {
                    array_shift($rows);
                }
                $pageVars['prepared'] = Finance::PrepareImport($rows, $imp['map'], $imp['flip'], $imp['account_id']);
                $pageVars['step'] = 'review';
                $pageVars['account_name'] = val('SELECT name FROM fin_accounts WHERE id = ?', array($imp['account_id']));
            } else {
                $pageVars['step'] = 'map';
            }
        } else {
            $pageVars['step'] = 'upload';
        }
        $base['page_title'] = 'Import transactions';
        $pageNeeded = 'money/import';
        return;

    case 'categories':
        $pageVars['all_categories'] = rows('SELECT c.*, (SELECT COUNT(*) FROM fin_transactions t WHERE t.category_id = c.id) AS n FROM fin_categories c ORDER BY c.kind DESC, c.archived, c.sort, c.name');
        $pageVars['rules'] = rows('SELECT r.*, c.name AS category_name, c.icon FROM fin_rules r LEFT JOIN fin_categories c ON c.id = r.category_id ORDER BY r.sort, r.match_text');
        $base['page_title'] = 'Categories & rules';
        $pageNeeded = 'money/categories';
        return;

    case '':
        break;

    default:
        App::NotFound();
}

/* ---------- Overview ---------- */
$budget = Finance::BudgetMonth($month);
list($recent) = Finance::Search(array(), 8);
$pageVars += array(
    'net' => Finance::NetWorth(),
    'budget' => $budget,
    'monthly' => Finance::MonthlyTotals(6),
    'by_category' => Finance::SpendingByCategory($budget['from'], $budget['to']),
    'recent' => $recent,
    'bills' => array_slice(array_filter(Finance::Bills(true), function ($b) { return $b['days'] <= 21; }), 0, 6),
    'pace' => Finance::SpendingPace(),
    'goals' => rows('SELECT * FROM fin_goals WHERE archived = 0 ORDER BY target_date IS NULL, target_date LIMIT 3'),
    'uncategorized' => (int)val("SELECT COUNT(*) FROM fin_transactions WHERE category_id IS NULL AND kind <> 'transfer'"),
    'unbilled_expenses' => (float)val('SELECT COALESCE(-SUM(amount), 0) FROM fin_transactions WHERE billable = 1 AND invoice_id IS NULL'),
);
$pageNeeded = 'money/index';
