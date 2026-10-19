<?php

/**
 * Money: accounts, transactions, budgets, bills, goals, rules, imports and reports.
 * Amounts: money in is positive, money out is negative.
 */
class Finance
{
    const ACCOUNT_TYPES = array(
        'checking' => 'Checking', 'savings' => 'Savings', 'credit' => 'Credit card', 'cash' => 'Cash',
        'investment' => 'Investment', 'retirement' => 'Retirement', 'loan' => 'Loan / mortgage', 'other' => 'Other',
    );
    const FREQUENCIES = array(
        'weekly' => 'Weekly', 'biweekly' => 'Every 2 weeks', 'monthly' => 'Monthly', 'quarterly' => 'Every 3 months',
        'semiannual' => 'Every 6 months', 'yearly' => 'Yearly',
    );
    const PER_MONTH = array('weekly' => 52 / 12, 'biweekly' => 26 / 12, 'monthly' => 1, 'quarterly' => 1 / 3, 'semiannual' => 1 / 6, 'yearly' => 1 / 12);

    /* ================= Accounts ================= */

    public static function Accounts($includeArchived = false)
    {
        return rows('SELECT a.*, a.opening_balance + COALESCE((SELECT SUM(t.amount) FROM fin_transactions t WHERE t.account_id = a.id), 0) AS balance,
                (SELECT COUNT(*) FROM fin_transactions t WHERE t.account_id = a.id) AS tx_count,
                (SELECT MAX(t.date) FROM fin_transactions t WHERE t.account_id = a.id) AS last_tx
            FROM fin_accounts a ' . ($includeArchived ? '' : 'WHERE a.archived = 0 ') . 'ORDER BY a.archived, a.sort, a.name');
    }

    public static function IsLiability($type)
    {
        return in_array($type, array('credit', 'loan'), true);
    }

    public static function NetWorth()
    {
        $assets = $debts = 0;
        foreach (self::Accounts() as $a) {
            if (!$a['in_net_worth']) {
                continue;
            }
            if ($a['balance'] >= 0) {
                $assets += $a['balance'];
            } else {
                $debts += $a['balance'];
            }
        }
        return array('assets' => round($assets, 2), 'debts' => round($debts, 2), 'net' => round($assets + $debts, 2));
    }

    /** Net worth at the end of each of the last $months months */
    public static function NetWorthSeries($months = 12)
    {
        $opening = (float)val('SELECT COALESCE(SUM(opening_balance), 0) FROM fin_accounts WHERE in_net_worth = 1');
        $labels = $values = array();
        $end = new DateTime(today());
        for ($i = $months - 1; $i >= 0; $i--) {
            $d = (clone $end)->modify('last day of -' . $i . ' months');
            if ($i === 0) {
                $d = clone $end;
            }
            $sum = (float)val('SELECT COALESCE(SUM(t.amount), 0) FROM fin_transactions t JOIN fin_accounts a ON a.id = t.account_id WHERE a.in_net_worth = 1 AND t.date <= ?', array($d->format('Y-m-d')));
            $labels[] = $d->format('M y');
            $values[] = round($opening + $sum, 2);
        }
        return array('labels' => $labels, 'values' => $values);
    }

    /* ================= Categories ================= */

    public static function Categories($kind = null, $includeArchived = false)
    {
        $w = array();
        $p = array();
        if ($kind) {
            $w[] = 'kind = ?';
            $p[] = $kind;
        }
        if (!$includeArchived) {
            $w[] = 'archived = 0';
        }
        return rows('SELECT * FROM fin_categories' . ($w ? ' WHERE ' . implode(' AND ', $w) : '') . ' ORDER BY kind DESC, sort, name', $p);
    }

    /* ================= Transactions ================= */

    /**
     * Save a transaction from form input. Handles expense / income / transfer.
     * Returns the id (the "from" side for transfers).
     */
    public static function SaveTransaction($id, array $in)
    {
        $kind = in_array($in['kind'] ?? '', array('expense', 'income', 'transfer'), true) ? $in['kind'] : 'expense';
        $amount = abs(parse_money($in['amount'] ?? 0));
        $date = valid_date($in['date'] ?? '') ?: today();
        $accountId = (int)($in['account_id'] ?? 0);
        if (!$accountId || !val('SELECT 1 FROM fin_accounts WHERE id = ?', array($accountId))) {
            throw new RuntimeException('Pick an account.');
        }
        if ($amount <= 0) {
            throw new RuntimeException('Enter an amount.');
        }
        $base = array(
            'date' => $date,
            'payee' => mb_substr(trim((string)($in['payee'] ?? '')), 0, 128) ?: null,
            'memo' => mb_substr(trim((string)($in['memo'] ?? '')), 0, 255) ?: null,
            'tags' => self::CleanTags($in['tags'] ?? ''),
            'cleared' => isset($in['cleared']) ? (int)!empty($in['cleared']) : 1,
        );

        if ($kind === 'transfer') {
            $toId = (int)($in['to_account_id'] ?? 0);
            if (!$toId || $toId === $accountId) {
                throw new RuntimeException('Pick a different account to move the money to.');
            }
            $fromName = val('SELECT name FROM fin_accounts WHERE id = ?', array($accountId));
            $toName = val('SELECT name FROM fin_accounts WHERE id = ?', array($toId));
            db()->beginTransaction();
            try {
                $group = null;
                if ($id) {
                    $group = val('SELECT transfer_group FROM fin_transactions WHERE id = ?', array($id));
                    if ($group) {
                        run('DELETE FROM fin_transactions WHERE transfer_group = ?', array($group));
                    } else {
                        run('DELETE FROM fin_transactions WHERE id = ?', array($id));
                    }
                }
                $group = $group ?: token(12);
                $common = $base + array('kind' => 'transfer', 'transfer_group' => $group, 'category_id' => null, 'created_by' => App::UserId() ?: null, 'created_at' => App::UtcNow());
                $fromId = insert('fin_transactions', array_merge($common, array('account_id' => $accountId, 'amount' => -$amount, 'payee' => $base['payee'] ?: 'Transfer to ' . $toName)));
                insert('fin_transactions', array_merge($common, array('account_id' => $toId, 'amount' => $amount, 'payee' => $base['payee'] ?: 'Transfer from ' . $fromName)));
                db()->executeTransaction();
            } catch (Throwable $e) {
                db()->rollBack();
                throw $e;
            }
            return $fromId;
        }

        $catId = !empty($in['category_id']) ? (int)$in['category_id'] : null;
        $clientId = !empty($in['client_id']) ? (int)$in['client_id'] : null;
        $projectId = !empty($in['project_id']) ? (int)$in['project_id'] : null;
        if ($projectId && !$clientId) {
            $clientId = (int)val('SELECT client_id FROM projects WHERE id = ?', array($projectId)) ?: null;
        }
        $data = $base + array(
            'account_id' => $accountId,
            'kind' => $kind,
            'amount' => $kind === 'expense' ? -$amount : $amount,
            'category_id' => $catId,
            'client_id' => $clientId,
            'project_id' => $projectId,
            'billable' => $kind === 'expense' && $clientId && !empty($in['billable']) ? 1 : 0,
            'transfer_group' => null,
        );
        if (!$data['category_id'] && $data['payee']) {
            $rule = self::MatchRule($data['payee']);
            if ($rule && $rule['category_id']) {
                $data['category_id'] = (int)$rule['category_id'];
            }
        }
        if ($id) {
            $old = row('SELECT * FROM fin_transactions WHERE id = ?', array($id));
            if (!$old) {
                throw new RuntimeException('Transaction not found.');
            }
            if ($old['transfer_group']) {
                run('DELETE FROM fin_transactions WHERE transfer_group = ? AND id <> ?', array($old['transfer_group'], $id));
            }
            if ($old['invoice_id']) {
                unset($data['billable']); // already billed: keep it attached to its invoice
            }
            update('fin_transactions', 'id', $id, $data);
            return (int)$id;
        }
        $data['created_by'] = App::UserId() ?: null;
        $data['created_at'] = App::UtcNow();
        return insert('fin_transactions', $data);
    }

    public static function DeleteTransaction($id)
    {
        $t = row('SELECT * FROM fin_transactions WHERE id = ?', array($id));
        if (!$t) {
            return false;
        }
        if ($t['invoice_payment_id']) {
            throw new RuntimeException('This is an invoice payment. Remove the payment from the invoice instead.');
        }
        if ($t['transfer_group']) {
            run('DELETE FROM fin_transactions WHERE transfer_group = ?', array($t['transfer_group']));
        } else {
            run('DELETE FROM fin_transactions WHERE id = ?', array($id));
        }
        if ($t['receipt']) {
            @unlink(STORAGE . 'receipts/' . basename($t['receipt']));
        }
        return true;
    }

    /**
     * Filtered list of transactions. Returns array(rows, total count, sums)
     */
    public static function Search(array $f, $limit = 100, $offset = 0)
    {
        list($where, $p) = self::Filter($f);
        $sql = ' FROM fin_transactions t
            LEFT JOIN fin_accounts a ON a.id = t.account_id
            LEFT JOIN fin_categories c ON c.id = t.category_id
            LEFT JOIN clients cl ON cl.id = t.client_id
            LEFT JOIN projects pr ON pr.id = t.project_id
            LEFT JOIN invoices inv ON inv.id = t.invoice_id' . ($where ? ' WHERE ' . implode(' AND ', $where) : '');
        $count = (int)val('SELECT COUNT(*)' . $sql, $p);
        $sums = row('SELECT COALESCE(SUM(CASE WHEN t.amount > 0 AND t.kind <> \'transfer\' THEN t.amount END), 0) AS money_in, COALESCE(SUM(CASE WHEN t.amount < 0 AND t.kind <> \'transfer\' THEN t.amount END), 0) AS money_out' . $sql, $p);
        $list = rows('SELECT t.*, a.name AS account_name, a.color AS account_color, c.name AS category_name, c.icon AS category_icon, c.color AS category_color,
                cl.name AS client_name, pr.name AS project_name, inv.number AS invoice_number' . $sql . '
            ORDER BY t.date DESC, t.id DESC LIMIT ' . (int)$limit . ' OFFSET ' . (int)$offset, $p);
        return array($list, $count, $sums);
    }

    public static function Filter(array $f)
    {
        $w = array();
        $p = array();
        if (!empty($f['account'])) {
            $w[] = 't.account_id = ?';
            $p[] = (int)$f['account'];
        }
        if (!empty($f['category'])) {
            if ($f['category'] === 'none') {
                $w[] = "t.category_id IS NULL AND t.kind <> 'transfer'";
            } else {
                $w[] = 't.category_id = ?';
                $p[] = (int)$f['category'];
            }
        }
        if (!empty($f['kind']) && in_array($f['kind'], array('expense', 'income', 'transfer'), true)) {
            $w[] = 't.kind = ?';
            $p[] = $f['kind'];
        }
        if (!empty($f['client'])) {
            $w[] = 't.client_id = ?';
            $p[] = (int)$f['client'];
        }
        if (!empty($f['billable'])) {
            $w[] = $f['billable'] === 'unbilled' ? 't.billable = 1 AND t.invoice_id IS NULL' : 't.billable = 1';
        }
        if (!empty($f['from']) && valid_date($f['from'])) {
            $w[] = 't.date >= ?';
            $p[] = $f['from'];
        }
        if (!empty($f['to']) && valid_date($f['to'])) {
            $w[] = 't.date <= ?';
            $p[] = $f['to'];
        }
        if (!empty($f['month']) && preg_match('/^\d{4}-\d{2}$/', $f['month'])) {
            $w[] = 't.date BETWEEN ? AND ?';
            $p[] = $f['month'] . '-01';
            $p[] = date('Y-m-t', strtotime($f['month'] . '-01'));
        }
        if (!empty($f['tag'])) {
            $w[] = 'CONCAT(",", t.tags, ",") LIKE ?';
            $p[] = '%,' . trim($f['tag']) . ',%';
        }
        if (!empty($f['q'])) {
            $w[] = '(t.payee LIKE ? OR t.memo LIKE ? OR t.tags LIKE ? OR CAST(ABS(t.amount) AS CHAR) LIKE ?)';
            $q = '%' . $f['q'] . '%';
            array_push($p, $q, $q, $q, '%' . ltrim(str_replace(array('$', ','), '', $f['q']), '-') . '%');
        }
        return array($w, $p);
    }

    /* ================= Rules ================= */

    public static function MatchRule($payee)
    {
        static $rules = null;
        if ($rules === null) {
            $rules = rows('SELECT * FROM fin_rules ORDER BY sort, LENGTH(match_text) DESC');
        }
        $payee = mb_strtolower((string)$payee);
        foreach ($rules as $r) {
            if ($r['match_text'] !== '' && str_contains($payee, mb_strtolower($r['match_text']))) {
                return $r;
            }
        }
        return null;
    }

    /** Learn from the past: most-used category for a payee */
    public static function GuessCategory($payee)
    {
        $rule = self::MatchRule($payee);
        if ($rule && $rule['category_id']) {
            return (int)$rule['category_id'];
        }
        $id = val('SELECT category_id FROM fin_transactions WHERE payee = ? AND category_id IS NOT NULL GROUP BY category_id ORDER BY COUNT(*) DESC LIMIT 1', array($payee));
        return $id ? (int)$id : null;
    }

    /** Apply all rules to uncategorised transactions. Returns how many changed. */
    public static function ApplyRules()
    {
        $n = 0;
        foreach (rows("SELECT id, payee FROM fin_transactions WHERE category_id IS NULL AND kind <> 'transfer' AND payee IS NOT NULL") as $t) {
            $r = self::MatchRule($t['payee']);
            if ($r) {
                $upd = array();
                if ($r['category_id']) {
                    $upd['category_id'] = $r['category_id'];
                }
                if ($r['rename_to']) {
                    $upd['payee'] = $r['rename_to'];
                }
                if ($upd) {
                    update('fin_transactions', 'id', $t['id'], $upd);
                    $n++;
                }
            }
        }
        return $n;
    }

    /* ================= Bills ================= */

    public static function Bills($activeOnly = false)
    {
        $list = rows('SELECT b.*, c.name AS category_name, c.icon AS category_icon, a.name AS account_name FROM fin_bills b
            LEFT JOIN fin_categories c ON c.id = b.category_id LEFT JOIN fin_accounts a ON a.id = b.account_id'
            . ($activeOnly ? ' WHERE b.active = 1' : '') . ' ORDER BY b.active DESC, b.next_due');
        $t = today();
        foreach ($list as &$b) {
            $b['days'] = (int)(new DateTime($t))->diff(new DateTime($b['next_due']))->format('%r%a');
            $b['monthly'] = round($b['amount'] * (self::PER_MONTH[$b['frequency']] ?? 1), 2);
        }
        return $list;
    }

    public static function NextBillDate($date, $frequency)
    {
        $d = new DateTime($date);
        $day = (int)$d->format('j');
        $months = array('monthly' => 1, 'quarterly' => 3, 'semiannual' => 6, 'yearly' => 12);
        if ($frequency === 'weekly') {
            $d->modify('+1 week');
        } elseif ($frequency === 'biweekly') {
            $d->modify('+2 weeks');
        } else {
            $n = $months[$frequency] ?? 1;
            $d->modify('first day of this month')->modify('+' . $n . ' months');
            $d->setDate((int)$d->format('Y'), (int)$d->format('n'), min($day, (int)$d->format('t')));
        }
        return $d->format('Y-m-d');
    }

    /**
     * Mark a bill paid: records the expense (optional) and moves it to its next date.
     */
    public static function PayBill($id, $amount = null, $date = null, $record = true, $accountId = null)
    {
        $b = row('SELECT * FROM fin_bills WHERE id = ?', array($id));
        if (!$b) {
            throw new RuntimeException('Bill not found.');
        }
        $amount = $amount !== null && $amount !== '' ? abs(parse_money($amount)) : (float)$b['amount'];
        $date = valid_date($date) ?: today();
        $txId = null;
        $accountId = $accountId ?: $b['account_id'];
        if ($record) {
            if (!$accountId) {
                throw new RuntimeException('Pick which account paid it.');
            }
            $txId = self::SaveTransaction(null, array('kind' => 'expense', 'account_id' => $accountId, 'amount' => $amount, 'date' => $date, 'payee' => $b['name'], 'category_id' => $b['category_id']));
            run('UPDATE fin_transactions SET bill_id = ? WHERE id = ?', array($id, $txId));
        }
        // One payment covers one period. If several were missed, the next one still shows as due.
        $next = self::NextBillDate($b['next_due'], $b['frequency']);
        update('fin_bills', 'id', $id, array('next_due' => $next, 'last_paid' => $date));
        App::Activity('money', 'Paid ' . $b['name'] . ' (' . money($amount) . ')', '/money/bills');
        return array('next' => $next, 'transaction' => $txId);
    }

    /* ================= Budgets ================= */

    /**
     * Spending vs budget for a month (YYYY-MM).
     */
    public static function BudgetMonth($month)
    {
        $from = $month . '-01';
        $to = date('Y-m-t', strtotime($from));
        $spent = array();
        foreach (rows("SELECT category_id, -SUM(amount) AS spent FROM fin_transactions WHERE kind = 'expense' AND date BETWEEN ? AND ? GROUP BY category_id", array($from, $to)) as $r) {
            $spent[(int)$r['category_id']] = (float)$r['spent'];
        }
        // average of the 3 months before, to suggest budgets
        $avgFrom = date('Y-m-01', strtotime($from . ' -3 months'));
        $avgTo = date('Y-m-t', strtotime($from . ' -1 month'));
        $avg = array();
        foreach (rows("SELECT category_id, -SUM(amount) / 3 AS a FROM fin_transactions WHERE kind = 'expense' AND date BETWEEN ? AND ? GROUP BY category_id", array($avgFrom, $avgTo)) as $r) {
            $avg[(int)$r['category_id']] = round((float)$r['a'], 2);
        }
        $daysInMonth = (int)date('t', strtotime($from));
        $isCurrent = $month === substr(today(), 0, 7);
        $dayOfMonth = $isCurrent ? (int)date('j', strtotime(today())) : ($month < substr(today(), 0, 7) ? $daysInMonth : 0);

        $cats = array();
        $totalBudget = $totalSpent = 0;
        foreach (self::Categories('expense') as $c) {
            $s = $spent[$c['id']] ?? 0;
            $budget = $c['budget'] !== null ? (float)$c['budget'] : null;
            if ($budget === null && $s == 0) {
                $c['spent'] = 0;
                $c['avg'] = $avg[$c['id']] ?? 0;
                $c['unbudgeted'] = true;
                $cats[] = $c;
                continue;
            }
            $c['spent'] = round($s, 2);
            $c['avg'] = $avg[$c['id']] ?? 0;
            $c['budget'] = $budget;
            $c['left'] = $budget !== null ? round($budget - $s, 2) : null;
            $c['pct'] = $budget ? min(100, round($s / $budget * 100)) : 0;
            // on pace? expected spend by today
            $c['pace'] = $budget && $dayOfMonth ? round($budget * $dayOfMonth / $daysInMonth, 2) : null;
            $c['status'] = $budget === null ? 'none' : ($s > $budget ? 'over' : ($c['pace'] !== null && $s > $c['pace'] * 1.1 ? 'warn' : 'ok'));
            $c['unbudgeted'] = false;
            if ($budget !== null) {
                $totalBudget += $budget;
            }
            $totalSpent += $s;
            $cats[] = $c;
        }
        $uncategorised = $spent[0] ?? 0;
        $income = (float)val("SELECT COALESCE(SUM(amount), 0) FROM fin_transactions WHERE kind = 'income' AND date BETWEEN ? AND ?", array($from, $to));
        return array(
            'month' => $month, 'from' => $from, 'to' => $to,
            'categories' => $cats,
            'budget' => round($totalBudget, 2),
            'spent' => round($totalSpent + $uncategorised, 2),
            'uncategorised' => round($uncategorised, 2),
            'income' => round($income, 2),
            'day' => $dayOfMonth, 'days' => $daysInMonth,
            'pct_of_month' => $daysInMonth ? round($dayOfMonth / $daysInMonth * 100) : 0,
        );
    }

    /* ================= Reports ================= */

    /** Income and spending per month for the last $months months */
    public static function MonthlyTotals($months = 6)
    {
        $out = array('labels' => array(), 'income' => array(), 'expense' => array(), 'net' => array());
        for ($i = $months - 1; $i >= 0; $i--) {
            $m = date('Y-m', strtotime(substr(today(), 0, 7) . '-01 -' . $i . ' months'));
            $r = row("SELECT COALESCE(SUM(CASE WHEN kind = 'income' THEN amount END), 0) AS inc, COALESCE(-SUM(CASE WHEN kind = 'expense' THEN amount END), 0) AS exp FROM fin_transactions WHERE date BETWEEN ? AND ?", array($m . '-01', date('Y-m-t', strtotime($m . '-01'))));
            $out['labels'][] = date('M', strtotime($m . '-01'));
            $out['income'][] = round((float)$r['inc'], 2);
            $out['expense'][] = round((float)$r['exp'], 2);
            $out['net'][] = round((float)$r['inc'] - (float)$r['exp'], 2);
        }
        return $out;
    }

    /** Spending by category between two dates */
    public static function SpendingByCategory($from, $to)
    {
        return rows("SELECT COALESCE(c.name, 'Uncategorized') AS name, COALESCE(c.color, '#adb5bd') AS color, c.icon, c.id, -SUM(t.amount) AS total, COUNT(*) AS n
            FROM fin_transactions t LEFT JOIN fin_categories c ON c.id = t.category_id
            WHERE t.kind = 'expense' AND t.date BETWEEN ? AND ? GROUP BY c.id, c.name, c.color, c.icon HAVING total > 0 ORDER BY total DESC", array($from, $to));
    }

    public static function TopPayees($from, $to, $limit = 10)
    {
        return rows("SELECT payee, -SUM(amount) AS total, COUNT(*) AS n FROM fin_transactions WHERE kind = 'expense' AND payee IS NOT NULL AND date BETWEEN ? AND ? GROUP BY payee ORDER BY total DESC LIMIT " . (int)$limit, array($from, $to));
    }

    /** Daily cumulative spending this month vs last month (for the "pace" chart) */
    public static function SpendingPace()
    {
        $thisStart = substr(today(), 0, 7) . '-01';
        $lastStart = date('Y-m-01', strtotime($thisStart . ' -1 month'));
        $series = function ($start) {
            $end = date('Y-m-t', strtotime($start));
            $byDay = array();
            foreach (rows("SELECT DAY(date) AS d, -SUM(amount) AS s FROM fin_transactions WHERE kind = 'expense' AND date BETWEEN ? AND ? GROUP BY DAY(date)", array($start, $end)) as $r) {
                $byDay[(int)$r['d']] = (float)$r['s'];
            }
            $out = array();
            $sum = 0;
            for ($d = 1; $d <= 31; $d++) {
                $sum += $byDay[$d] ?? 0;
                $out[] = round($sum, 2);
            }
            return $out;
        };
        $today = (int)date('j', strtotime(today()));
        $this1 = array_slice($series($thisStart), 0, $today);
        return array('this' => $this1, 'last' => array_slice($series($lastStart), 0, (int)date('t', strtotime($lastStart))));
    }

    /** Recurring charges found in the last 4 months that aren't bills yet */
    public static function SuggestedBills()
    {
        $since = date('Y-m-d', strtotime(today() . ' -120 days'));
        $known = array_map('mb_strtolower', db()->column('SELECT name FROM fin_bills'));
        $out = array();
        foreach (rows("SELECT payee, COUNT(DISTINCT DATE_FORMAT(date, '%Y-%m')) AS months, ROUND(-AVG(amount), 2) AS avg_amount, MIN(-amount) AS lo, MAX(-amount) AS hi, MAX(date) AS last_date, MAX(category_id) AS category_id, MAX(account_id) AS account_id
            FROM fin_transactions WHERE kind = 'expense' AND payee IS NOT NULL AND date >= ? GROUP BY payee HAVING months >= 3 ORDER BY avg_amount DESC LIMIT 12", array($since)) as $r) {
            if (in_array(mb_strtolower($r['payee']), $known, true)) {
                continue;
            }
            if ($r['hi'] > $r['lo'] * 1.25 + 5) {
                continue; // amounts too different to be a subscription
            }
            $r['next_due'] = self::NextBillDate($r['last_date'], 'monthly');
            $out[] = $r;
        }
        return $out;
    }

    /* ================= Bank CSV import ================= */

    /**
     * Guess which columns hold the date, description and amount
     */
    public static function GuessColumns(array $header)
    {
        $h = array_map(function ($c) { return strtolower(trim($c)); }, $header);
        $find = function ($names) use ($h) {
            foreach ($names as $n) {
                foreach ($h as $i => $col) {
                    if ($col === $n) {
                        return $i;
                    }
                }
            }
            foreach ($names as $n) {
                foreach ($h as $i => $col) {
                    if (str_contains($col, $n)) {
                        return $i;
                    }
                }
            }
            return null;
        };
        return array(
            'date' => $find(array('date', 'posted date', 'posting date', 'transaction date', 'trans. date')),
            'payee' => $find(array('description', 'payee', 'merchant', 'name', 'details', 'memo')),
            'amount' => $find(array('amount', 'transaction amount')),
            'debit' => $find(array('debit', 'withdrawal', 'withdrawals', 'money out')),
            'credit' => $find(array('credit', 'deposit', 'deposits', 'money in')),
            'category' => $find(array('category')),
            'memo' => $find(array('memo', 'notes', 'reference')),
        );
    }

    /**
     * Turn bank CSV rows into transactions to review.
     * $map: column indexes. $flip: the bank shows spending as positive numbers.
     */
    public static function PrepareImport(array $rows, array $map, $flip, $accountId)
    {
        $out = array();
        foreach ($rows as $i => $r) {
            $date = isset($map['date']) && $map['date'] !== '' ? parse_any_date($r[(int)$map['date']] ?? '') : null;
            if (!$date) {
                continue;
            }
            if (isset($map['amount']) && $map['amount'] !== '') {
                $amount = parse_money($r[(int)$map['amount']] ?? '');
                if ($flip) {
                    $amount = -$amount;
                }
            } else {
                $debit = isset($map['debit']) && $map['debit'] !== '' ? abs(parse_money($r[(int)$map['debit']] ?? '')) : 0;
                $credit = isset($map['credit']) && $map['credit'] !== '' ? abs(parse_money($r[(int)$map['credit']] ?? '')) : 0;
                $amount = $credit - $debit;
            }
            if ($amount == 0) {
                continue;
            }
            $payee = trim(preg_replace('/\s+/', ' ', (string)($r[(int)($map['payee'] ?? 0)] ?? '')));
            $memo = isset($map['memo']) && $map['memo'] !== '' && $map['memo'] != ($map['payee'] ?? null) ? trim((string)($r[(int)$map['memo']] ?? '')) : '';
            $rule = self::MatchRule($payee);
            $clean = $rule && $rule['rename_to'] ? $rule['rename_to'] : self::CleanPayee($payee);
            $hash = sha1($accountId . '|' . $date . '|' . number_format($amount, 2, '.', '') . '|' . mb_strtolower($payee));
            $dup = (bool)val('SELECT 1 FROM fin_transactions WHERE import_hash = ?', array($hash))
                || (bool)val('SELECT 1 FROM fin_transactions WHERE account_id = ? AND date = ? AND amount = ? AND (payee = ? OR payee = ?) LIMIT 1', array($accountId, $date, $amount, $payee, $clean));
            $out[] = array(
                'i' => $i, 'date' => $date, 'payee' => $clean, 'original' => $payee, 'memo' => $memo, 'amount' => round($amount, 2),
                'category_id' => $amount < 0 ? self::GuessCategory($clean) : (self::GuessCategory($clean) ?: null),
                'hash' => $hash, 'duplicate' => $dup,
            );
        }
        return $out;
    }

    /** "SQ *COFFEE SHOP 1234 NEW CASTLE PA" → "Coffee Shop" */
    public static function CleanPayee($s)
    {
        $s = preg_replace('/^(SQ \*|TST\* |PAYPAL \*|POS |DEBIT CARD PURCHASE |PURCHASE AUTHORIZED ON \d\d\/\d\d |CHECKCARD \d+ |ACH DEBIT |ACH CREDIT )/i', '', trim($s));
        $s = preg_replace('/\s+#?\d{3,}.*$/', '', $s);              // store numbers and what follows
        $s = preg_replace('/\s+[A-Z]{2}\s*$/', '', $s);              // trailing state
        $s = preg_replace('/\s{2,}.*$/', '', $s);
        $s = trim($s, " *-.");
        if ($s === '') {
            return 'Unknown';
        }
        if (strtoupper($s) === $s) {
            $s = ucwords(strtolower($s));
        }
        return mb_substr($s, 0, 128);
    }

    /* ================= Helpers ================= */

    public static function CleanTags($s)
    {
        $tags = array_filter(array_map(function ($t) { return mb_strtolower(trim($t, " #\t")); }, explode(',', (string)$s)));
        return $tags ? mb_substr(implode(',', array_unique($tags)), 0, 255) : null;
    }

    /** Store an uploaded receipt, returns the file name */
    public static function StoreReceipt($field)
    {
        if (empty($_FILES[$field]) || $_FILES[$field]['error'] === UPLOAD_ERR_NO_FILE) {
            return null;
        }
        if ($_FILES[$field]['error'] !== UPLOAD_ERR_OK) {
            throw new RuntimeException('The receipt did not upload. It may be too big.');
        }
        $mime = (new finfo(FILEINFO_MIME_TYPE))->file($_FILES[$field]['tmp_name']);
        $ext = array('image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/heic' => 'heic', 'application/pdf' => 'pdf')[$mime] ?? null;
        if (!$ext) {
            throw new RuntimeException('Receipts can be JPG, PNG, WEBP, HEIC or PDF.');
        }
        $name = gmdate('Ymd') . '-' . bin2hex(random_bytes(8)) . '.' . $ext;
        if (!is_dir(STORAGE . 'receipts')) {
            mkdir(STORAGE . 'receipts', 0775, true);
        }
        move_uploaded_file($_FILES[$field]['tmp_name'], STORAGE . 'receipts/' . $name);
        return $name;
    }
}
