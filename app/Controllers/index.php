<?php

/**
 * Dashboard: everything at a glance.
 */

App::RequireLogin();
$uid = App::UserId();
$user = App::User();
$today = today();
$base['page_title'] = 'Dashboard';

$hour = (int)App::Now()->format('G');
$pageVars['greeting'] = $hour < 5 ? 'Up late' : ($hour < 12 ? 'Good morning' : ($hour < 17 ? 'Good afternoon' : 'Good evening'));

/* ---------- Time ---------- */
$weekStart = App::WeekStart($today);
$weekEnd = date('Y-m-d', strtotime($weekStart . ' +6 days'));
$weekEntries = TimeTracker::Entries(array('user' => $uid, 'from' => $weekStart, 'to' => $weekEnd), 5000);
$weekSeconds = array_sum(array_column($weekEntries, 'seconds'));
$todaySeconds = array_sum(array_map(function ($e) use ($today) { return $e['date'] === $today ? $e['seconds'] : 0; }, $weekEntries));
$weekValue = array_sum(array_column($weekEntries, 'value'));
$lastWeekSeconds = (int)val('SELECT COALESCE(SUM(duration), 0) FROM time_entries WHERE user_id = ? AND date BETWEEN ? AND ?', array($uid, date('Y-m-d', strtotime($weekStart . ' -7 days')), date('Y-m-d', strtotime($weekStart . ' -1 day'))));
$unbilled = TimeTracker::UnbilledByClient();
$unbilledTotal = array_sum($unbilled);
arsort($unbilled);
$topUnbilled = array();
foreach (array_slice($unbilled, 0, 4, true) as $cid => $v) {
    if ($v > 0) {
        $topUnbilled[] = array('id' => $cid, 'name' => val('SELECT name FROM clients WHERE id = ?', array($cid)), 'color' => val('SELECT color FROM clients WHERE id = ?', array($cid)), 'value' => $v);
    }
}

$pageVars['time'] = array(
    'week_seconds' => $weekSeconds,
    'today_seconds' => $todaySeconds,
    'week_value' => $weekValue,
    'last_week_seconds' => $lastWeekSeconds,
    'goal' => (float)($user['weekly_hours_goal'] ?? 0),
    'unbilled' => $unbilledTotal,
    'top_unbilled' => $topUnbilled,
    'chart' => TimeTracker::DailyByClient($uid, $weekStart, $weekEnd),
    'timer' => TimeTracker::RunningTimer($uid),
    'recent' => rows('SELECT e.project_id, e.service_id, MAX(e.id) AS last_id, pr.name AS project_name, c.name AS client_name, c.color FROM time_entries e
        JOIN projects pr ON pr.id = e.project_id JOIN clients c ON c.id = pr.client_id WHERE e.user_id = ? AND pr.status = \'active\'
        GROUP BY e.project_id, e.service_id, pr.name, c.name, c.color ORDER BY last_id DESC LIMIT 4', array($uid)),
);

/* ---------- Invoices ---------- */
$pageVars['invoices'] = array(
    'outstanding' => (float)val("SELECT COALESCE(SUM(total - paid), 0) FROM invoices WHERE status IN ('sent','partial')"),
    'overdue' => (float)val("SELECT COALESCE(SUM(total - paid), 0) FROM invoices WHERE status IN ('sent','partial') AND due_date < ?", array($today)),
    'overdue_count' => (int)val("SELECT COUNT(*) FROM invoices WHERE status IN ('sent','partial') AND due_date < ?", array($today)),
    'attention' => rows("SELECT i.*, c.name AS client_name, c.color AS client_color, (i.total - i.paid) AS balance FROM invoices i JOIN clients c ON c.id = i.client_id
        WHERE i.status = 'draft' OR (i.status IN ('sent','partial') AND i.due_date < ?) ORDER BY i.status = 'draft', i.due_date LIMIT 5", array(date('Y-m-d', strtotime($today . ' +7 days')))),
    'paid_month' => (float)val('SELECT COALESCE(SUM(amount), 0) FROM invoice_payments WHERE date >= ?', array(substr($today, 0, 7) . '-01')),
);

/* ---------- Reminders ---------- */
$agenda = Reminder::ForView('today');
$upcoming = array_slice(Reminder::ForView('upcoming'), 0, 6);
$pageVars['reminders'] = array(
    'today' => $agenda,
    'upcoming' => $upcoming,
    'bills' => array_values(array_filter(Finance::Bills(true), function ($b) { return $b['days'] <= max(7, (int)$b['remind_days']); })),
    'done_today' => (int)val('SELECT COUNT(*) FROM reminder_completions WHERE completed_at >= ?', array(App::ToUtc($today . ' 00:00:00'))),
);

/* ---------- Money ---------- */
$hasMoney = (bool)val('SELECT 1 FROM fin_accounts LIMIT 1');
if ($hasMoney) {
    $budget = Finance::BudgetMonth(substr($today, 0, 7));
    $pageVars['money'] = array(
        'budget' => $budget,
        'by_category' => array_slice(Finance::SpendingByCategory($budget['from'], $budget['to']), 0, 7),
        'net' => Finance::NetWorth(),
        'monthly' => Finance::MonthlyTotals(6),
        'top_budgets' => array_slice(array_values(array_filter($budget['categories'], function ($c) { return $c['budget'] > 0; })), 0, 99),
        'last_month_same_day' => (float)val("SELECT COALESCE(-SUM(amount), 0) FROM fin_transactions WHERE kind = 'expense' AND date BETWEEN ? AND ?",
            array(date('Y-m-01', strtotime(substr($today, 0, 7) . '-01 -1 month')), date('Y-m-d', strtotime($today . ' -1 month')))),
        'uncategorized' => (int)val("SELECT COUNT(*) FROM fin_transactions WHERE category_id IS NULL AND kind <> 'transfer'"),
        'goals' => rows('SELECT g.*, a.opening_balance + COALESCE((SELECT SUM(t.amount) FROM fin_transactions t WHERE t.account_id = a.id), 0) AS account_balance FROM fin_goals g LEFT JOIN fin_accounts a ON a.id = g.account_id WHERE g.archived = 0 ORDER BY g.target_date IS NULL, g.target_date LIMIT 3'),
    );
    usort($pageVars['money']['top_budgets'], function ($a, $b) { return ($b['spent'] / $b['budget']) <=> ($a['spent'] / $a['budget']); });
    $pageVars['money']['top_budgets'] = array_slice($pageVars['money']['top_budgets'], 0, 4);
    foreach ($pageVars['money']['goals'] as &$g) {
        $g['current'] = $g['account_id'] ? max(0, (float)$g['account_balance']) : (float)$g['saved'];
        $g['pct'] = $g['target'] > 0 ? min(100, round($g['current'] / $g['target'] * 100)) : 0;
    }
    unset($g);
}
$pageVars['has_money'] = $hasMoney;

/* ---------- Vault ---------- */
$vault = array('set_up' => Vault::IsSetUp($uid), 'unlocked' => Vault::IsUnlocked(), 'count' => (int)val('SELECT COUNT(*) FROM vault_items WHERE user_id = ?', array($uid)));
if ($vault['unlocked']) {
    $h = Vault::Health();
    $vault['health'] = array('score' => $h['score'], 'weak' => count($h['weak']), 'reused' => count($h['reused']), 'old' => count($h['old']));
    $vault['recent'] = array();
    foreach (rows('SELECT id FROM vault_items WHERE user_id = ? AND last_used_at IS NOT NULL ORDER BY last_used_at DESC LIMIT 4', array($uid)) as $r) {
        $it = Vault::Item($r['id']);
        if ($it) {
            $vault['recent'][] = array('id' => $it['id'], 'name' => $it['d']['name'] ?? '', 'sub' => $it['d']['username'] ?? '');
        }
    }
}
$pageVars['vault'] = $vault;

/* ---------- Activity ---------- */
$pageVars['activity'] = rows('SELECT a.*, u.first_name FROM activity a LEFT JOIN admin_users u ON u.Id_admin_users = a.user_id ORDER BY a.id DESC LIMIT 8');
$pageVars['week_start'] = $weekStart;
$pageVars['first_run'] = !(int)val('SELECT COUNT(*) FROM clients') && !$hasMoney && !$vault['count'] && !(int)val('SELECT COUNT(*) FROM reminders');

$pageNeeded = 'dashboard';
