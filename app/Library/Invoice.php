<?php

/**
 * Invoices built from unbilled time, flat fees and billable expenses.
 * Recording a payment adds the income to Money.
 */
class Invoice
{
    const STATUSES = array('draft' => 'Draft', 'sent' => 'Sent', 'partial' => 'Partly paid', 'paid' => 'Paid', 'void' => 'Void');
    const METHODS = array('ach' => 'Bank transfer / ACH', 'check' => 'Check', 'card' => 'Credit card', 'cash' => 'Cash', 'paypal' => 'PayPal', 'zelle' => 'Zelle', 'venmo' => 'Venmo', 'other' => 'Other');

    public static function Get($id)
    {
        $i = row('SELECT i.*, c.name AS client_name, c.contact_first, c.contact_last, c.email AS client_email, c.cc_emails, c.address1, c.address2, c.city, c.state, c.zip, c.country, c.phone AS client_phone
            FROM invoices i JOIN clients c ON c.id = i.client_id WHERE i.id = ?', array($id));
        if (!$i) {
            return null;
        }
        $i['lines'] = rows('SELECT l.*, p.name AS project_name FROM invoice_lines l LEFT JOIN projects p ON p.id = l.project_id WHERE l.invoice_id = ? ORDER BY l.sort, l.id', array($id));
        $i['payments'] = rows('SELECT pm.*, a.name AS account_name FROM invoice_payments pm LEFT JOIN fin_transactions t ON t.id = pm.transaction_id LEFT JOIN fin_accounts a ON a.id = t.account_id WHERE pm.invoice_id = ? ORDER BY pm.date, pm.id', array($id));
        $i['balance'] = round($i['total'] - $i['paid'], 2);
        $i['overdue'] = in_array($i['status'], array('sent', 'partial'), true) && $i['due_date'] && $i['due_date'] < today();
        $i['days_overdue'] = $i['overdue'] ? (int)(new DateTime($i['due_date']))->diff(new DateTime(today()))->days : 0;
        return $i;
    }

    public static function All(array $f = array())
    {
        $w = array();
        $p = array();
        if (!empty($f['client'])) {
            $w[] = 'i.client_id = ?';
            $p[] = (int)$f['client'];
        }
        if (!empty($f['status'])) {
            if ($f['status'] === 'outstanding') {
                $w[] = "i.status IN ('sent','partial')";
            } elseif ($f['status'] === 'overdue') {
                $w[] = "i.status IN ('sent','partial') AND i.due_date < ?";
                $p[] = today();
            } else {
                $w[] = 'i.status = ?';
                $p[] = $f['status'];
            }
        }
        if (!empty($f['q'])) {
            $w[] = '(i.number LIKE ? OR c.name LIKE ? OR i.title LIKE ?)';
            $q = '%' . $f['q'] . '%';
            array_push($p, $q, $q, $q);
        }
        $list = rows('SELECT i.*, c.name AS client_name, c.color AS client_color FROM invoices i JOIN clients c ON c.id = i.client_id'
            . ($w ? ' WHERE ' . implode(' AND ', $w) : '') . ' ORDER BY i.issue_date DESC, i.id DESC', $p);
        $t = today();
        foreach ($list as &$i) {
            $i['balance'] = round($i['total'] - $i['paid'], 2);
            $i['overdue'] = in_array($i['status'], array('sent', 'partial'), true) && $i['due_date'] && $i['due_date'] < $t;
        }
        return $list;
    }

    public static function NextNumber()
    {
        $prefix = App::Setting('invoice_prefix', 'INV-');
        $n = max(1, (int)App::Setting('invoice_next', 1001));
        while (val('SELECT 1 FROM invoices WHERE number = ?', array($prefix . $n))) {
            $n++;
        }
        return $prefix . $n;
    }

    /**
     * What could go on a new invoice for a client
     */
    public static function Unbilled($clientId, $from = null, $to = null, $projectIds = null)
    {
        $f = array('client' => $clientId, 'status' => 'unbilled', 'stopped' => 1, 'from' => $from, 'to' => $to);
        if ($projectIds) {
            $f['project'] = $projectIds;
        }
        $entries = array_values(array_filter(TimeTracker::Entries($f, 100000), function ($e) {
            return $e['billable_effective'];
        }));
        $p = array($clientId);
        $sql = "SELECT t.*, c.name AS category_name, pr.name AS project_name FROM fin_transactions t LEFT JOIN fin_categories c ON c.id = t.category_id LEFT JOIN projects pr ON pr.id = t.project_id
            WHERE t.client_id = ? AND t.billable = 1 AND t.invoice_id IS NULL";
        if ($from) { $sql .= ' AND t.date >= ?'; $p[] = $from; }
        if ($to) { $sql .= ' AND t.date <= ?'; $p[] = $to; }
        $expenses = rows($sql . ' ORDER BY t.date', $p);
        $flat = rows("SELECT * FROM projects WHERE client_id = ? AND billing = 'flat_fee' AND flat_fee > 0 AND flat_fee_invoice_id IS NULL AND status <> 'archived'", array($clientId));
        if ($projectIds) {
            $flat = array_values(array_filter($flat, function ($pr) use ($projectIds) { return in_array($pr['id'], $projectIds); }));
        }
        return array('entries' => $entries, 'expenses' => $expenses, 'flat' => $flat);
    }

    /**
     * Create an invoice. $opts: from, to, projects[], group (entry|service|project|day), include_expenses, include_flat, title, notes
     */
    public static function CreateFromUnbilled($clientId, array $opts)
    {
        $client = row('SELECT * FROM clients WHERE id = ?', array($clientId));
        if (!$client) {
            throw new RuntimeException('Pick a client.');
        }
        $u = self::Unbilled($clientId, valid_date($opts['from'] ?? ''), valid_date($opts['to'] ?? ''), !empty($opts['projects']) ? array_map('intval', (array)$opts['projects']) : null);
        $group = in_array($opts['group'] ?? '', array('entry', 'service', 'project', 'day'), true) ? $opts['group'] : 'service';

        db()->beginTransaction();
        try {
            $terms = $client['payment_terms'] !== null ? (int)$client['payment_terms'] : (int)App::Setting('invoice_terms', 30);
            $issue = valid_date($opts['issue_date'] ?? '') ?: today();
            $id = insert('invoices', array(
                'client_id' => $clientId,
                'number' => self::NextNumber(),
                'status' => 'draft',
                'issue_date' => $issue,
                'due_date' => date('Y-m-d', strtotime($issue . ' +' . $terms . ' days')),
                'title' => mb_substr(trim((string)($opts['title'] ?? '')), 0, 128) ?: null,
                'notes' => App::Setting('invoice_notes', ''),
                'terms' => $terms ? 'Payment due within ' . $terms . ' days.' : 'Due on receipt.',
                'currency' => $client['currency'] ?: App::Setting('currency', 'USD'),
                'tax_name' => App::Setting('tax_name', '') ?: null,
                'tax_rate' => (float)App::Setting('tax_rate', 0),
                'public_token' => token(24),
                'created_by' => App::UserId() ?: null,
                'created_at' => App::UtcNow(),
            ));
            $sort = 0;
            $lines = array();

            // Time
            foreach ($u['entries'] as $e) {
                switch ($group) {
                    case 'entry':
                        $k = 'e' . $e['id'];
                        $desc = trim(($e['project_name'] ? $e['project_name'] . ' · ' : '') . ($e['service_name'] ? $e['service_name'] : '') . "\n" . date('M j', strtotime($e['date'])) . ($e['notes'] ? ' — ' . $e['notes'] : ''));
                        break;
                    case 'project':
                        $k = 'p' . $e['project_id'] . '-' . $e['rate_effective'];
                        $desc = ($e['project_name'] ?: 'General') . ' — time';
                        break;
                    case 'day':
                        $k = 'd' . $e['date'] . '-' . $e['rate_effective'];
                        $desc = date('D, M j', strtotime($e['date']));
                        break;
                    default:
                        $k = 's' . $e['project_id'] . '-' . $e['service_id'] . '-' . $e['rate_effective'];
                        $desc = ($e['project_name'] ? $e['project_name'] . ' — ' : '') . ($e['service_name'] ?: 'Time');
                }
                if (!isset($lines[$k])) {
                    $lines[$k] = array('kind' => 'time', 'description' => $desc, 'seconds' => 0, 'rate' => $e['rate_effective'], 'project_id' => $e['project_id'], 'notes' => array());
                }
                $lines[$k]['seconds'] += $e['seconds'];
                if ($group !== 'entry' && $e['notes']) {
                    $lines[$k]['notes'][] = $e['notes'];
                }
                run('UPDATE time_entries SET invoice_id = ?, rate = ? WHERE id = ?', array($id, $e['rate_effective'], $e['id']));
            }
            foreach ($lines as $l) {
                $qty = round($l['seconds'] / 3600, 2);
                $desc = $l['description'];
                if (!empty($opts['include_notes']) && $l['notes']) {
                    $desc .= "\n" . implode('; ', array_slice(array_unique($l['notes']), 0, 12));
                }
                insert('invoice_lines', array('invoice_id' => $id, 'sort' => $sort++, 'kind' => 'time', 'description' => $desc, 'quantity' => $qty, 'rate' => $l['rate'], 'amount' => round($qty * $l['rate'], 2), 'project_id' => $l['project_id']));
            }

            // Flat fees
            if (!isset($opts['include_flat']) || $opts['include_flat']) {
                foreach ($u['flat'] as $pr) {
                    insert('invoice_lines', array('invoice_id' => $id, 'sort' => $sort++, 'kind' => 'item', 'description' => $pr['name'] . ' — flat fee', 'quantity' => 1, 'rate' => $pr['flat_fee'], 'amount' => $pr['flat_fee'], 'project_id' => $pr['id']));
                    run('UPDATE projects SET flat_fee_invoice_id = ? WHERE id = ?', array($id, $pr['id']));
                }
            }

            // Expenses
            if (!isset($opts['include_expenses']) || $opts['include_expenses']) {
                foreach ($u['expenses'] as $x) {
                    $amt = abs((float)$x['amount']);
                    insert('invoice_lines', array('invoice_id' => $id, 'sort' => $sort++, 'kind' => 'expense', 'description' => 'Expense: ' . ($x['payee'] ?: 'Purchase') . ' (' . date('M j', strtotime($x['date'])) . ')' . ($x['memo'] ? ' — ' . $x['memo'] : ''), 'quantity' => 1, 'rate' => $amt, 'amount' => $amt, 'project_id' => $x['project_id']));
                    run('UPDATE fin_transactions SET invoice_id = ? WHERE id = ?', array($id, $x['id']));
                }
            }
            if ($sort === 0) {
                throw new RuntimeException('Nothing unbilled for that client and those dates.');
            }
            self::Recalc($id);
            $num = (int)preg_replace('/\D/', '', substr(val('SELECT number FROM invoices WHERE id = ?', array($id)), strlen(App::Setting('invoice_prefix', 'INV-'))));
            App::SaveSetting('invoice_next', $num + 1);
            db()->executeTransaction();
        } catch (Throwable $e) {
            db()->rollBack();
            throw $e;
        }
        App::Activity('time', 'Created invoice ' . val('SELECT number FROM invoices WHERE id = ?', array($id)) . ' for ' . $client['name'], '/time/invoice/' . $id);
        return $id;
    }

    /** Blank invoice (add lines by hand) */
    public static function CreateBlank($clientId)
    {
        $client = row('SELECT * FROM clients WHERE id = ?', array($clientId));
        if (!$client) {
            throw new RuntimeException('Pick a client.');
        }
        $terms = $client['payment_terms'] !== null ? (int)$client['payment_terms'] : (int)App::Setting('invoice_terms', 30);
        $number = self::NextNumber();
        $id = insert('invoices', array(
            'client_id' => $clientId, 'number' => $number, 'status' => 'draft', 'issue_date' => today(),
            'due_date' => date('Y-m-d', strtotime(today() . ' +' . $terms . ' days')), 'notes' => App::Setting('invoice_notes', ''),
            'terms' => $terms ? 'Payment due within ' . $terms . ' days.' : 'Due on receipt.', 'currency' => $client['currency'] ?: App::Setting('currency', 'USD'),
            'tax_name' => App::Setting('tax_name', '') ?: null, 'tax_rate' => (float)App::Setting('tax_rate', 0),
            'public_token' => token(24), 'created_by' => App::UserId() ?: null, 'created_at' => App::UtcNow(),
        ));
        App::SaveSetting('invoice_next', (int)preg_replace('/\D/', '', substr($number, strlen(App::Setting('invoice_prefix', 'INV-')))) + 1);
        return $id;
    }

    /** Save the editable parts of an invoice (header + lines) */
    public static function SaveEdits($id, array $in)
    {
        $inv = row('SELECT * FROM invoices WHERE id = ?', array($id));
        if (!$inv) {
            throw new RuntimeException('Invoice not found.');
        }
        if ($inv['status'] === 'void') {
            throw new RuntimeException('This invoice is void.');
        }
        $number = mb_substr(trim((string)($in['number'] ?? $inv['number'])), 0, 32);
        if ($number !== $inv['number'] && val('SELECT 1 FROM invoices WHERE number = ? AND id <> ?', array($number, $id))) {
            throw new RuntimeException('Invoice ' . $number . ' already exists.');
        }
        update('invoices', 'id', $id, array(
            'number' => $number ?: $inv['number'],
            'issue_date' => valid_date($in['issue_date'] ?? '') ?: $inv['issue_date'],
            'due_date' => valid_date($in['due_date'] ?? '') ?: null,
            'po_number' => mb_substr(trim((string)($in['po_number'] ?? '')), 0, 64) ?: null,
            'title' => mb_substr(trim((string)($in['title'] ?? '')), 0, 128) ?: null,
            'notes' => trim((string)($in['notes'] ?? '')) ?: null,
            'terms' => trim((string)($in['terms'] ?? '')) ?: null,
            'discount_pct' => max(0, min(100, (float)($in['discount_pct'] ?? 0))),
            'tax_name' => mb_substr(trim((string)($in['tax_name'] ?? '')), 0, 32) ?: null,
            'tax_rate' => max(0, min(100, (float)($in['tax_rate'] ?? 0))),
        ));
        // Lines: line_id[], line_desc[], line_qty[], line_rate[]
        if (isset($in['line_desc']) && is_array($in['line_desc'])) {
            $keep = array();
            foreach ($in['line_desc'] as $i => $desc) {
                $desc = trim((string)$desc);
                $qty = (float)str_replace(',', '', (string)($in['line_qty'][$i] ?? 1));
                $rate = parse_money($in['line_rate'][$i] ?? 0);
                $lineId = (int)($in['line_id'][$i] ?? 0);
                if ($desc === '' && $rate == 0) {
                    continue;
                }
                $data = array('invoice_id' => $id, 'sort' => $i, 'description' => $desc ?: 'Item', 'quantity' => $qty, 'rate' => $rate, 'amount' => round($qty * $rate, 2));
                if ($lineId && val('SELECT 1 FROM invoice_lines WHERE id = ? AND invoice_id = ?', array($lineId, $id))) {
                    update('invoice_lines', 'id', $lineId, $data);
                    $keep[] = $lineId;
                } else {
                    $keep[] = insert('invoice_lines', $data + array('kind' => 'item'));
                }
            }
            // Lines removed: time for a project goes back to unbilled once no time line for that project is left
            $removed = rows('SELECT * FROM invoice_lines WHERE invoice_id = ?' . ($keep ? ' AND id NOT IN (' . implode(',', array_map('intval', $keep)) . ')' : ''), array($id));
            foreach ($removed as $r) {
                run('DELETE FROM invoice_lines WHERE id = ?', array($r['id']));
                if ($r['kind'] === 'item' && $r['project_id']) {
                    run('UPDATE projects SET flat_fee_invoice_id = NULL WHERE id = ? AND flat_fee_invoice_id = ?', array($r['project_id'], $id));
                }
            }
            foreach (array_unique(array_map(function ($r) { return (int)$r['project_id']; }, array_filter($removed, function ($r) { return $r['kind'] === 'time'; }))) as $pid) {
                $left = $pid ? val("SELECT 1 FROM invoice_lines WHERE invoice_id = ? AND kind = 'time' AND project_id = ?", array($id, $pid))
                             : val("SELECT 1 FROM invoice_lines WHERE invoice_id = ? AND kind = 'time' AND project_id IS NULL", array($id));
                if (!$left) {
                    run('UPDATE time_entries SET invoice_id = NULL, rate = NULL WHERE invoice_id = ? AND ' . ($pid ? 'project_id = ' . $pid : 'project_id IS NULL'), array($id));
                }
            }
            if (!val("SELECT 1 FROM invoice_lines WHERE invoice_id = ? AND kind = 'expense'", array($id))) {
                run('UPDATE fin_transactions SET invoice_id = NULL WHERE invoice_id = ? AND invoice_payment_id IS NULL', array($id));
            }
        }
        self::Recalc($id);
    }

    public static function Recalc($id)
    {
        $inv = row('SELECT * FROM invoices WHERE id = ?', array($id));
        $subtotal = (float)val('SELECT COALESCE(SUM(amount), 0) FROM invoice_lines WHERE invoice_id = ?', array($id));
        $discount = round($subtotal * $inv['discount_pct'] / 100, 2);
        $tax = round(($subtotal - $discount) * $inv['tax_rate'] / 100, 2);
        $total = round($subtotal - $discount + $tax, 2);
        $paid = (float)val('SELECT COALESCE(SUM(amount), 0) FROM invoice_payments WHERE invoice_id = ?', array($id));
        $status = $inv['status'];
        if ($status !== 'void' && $status !== 'draft') {
            $status = $paid >= $total - 0.005 && $total > 0 ? 'paid' : ($paid > 0 ? 'partial' : 'sent');
        } elseif ($status === 'draft' && $paid > 0) {
            $status = $paid >= $total - 0.005 ? 'paid' : 'partial';
        }
        update('invoices', 'id', $id, array('subtotal' => $subtotal, 'discount' => $discount, 'tax' => $tax, 'total' => $total, 'paid' => $paid, 'status' => $status,
            'paid_at' => $status === 'paid' ? ($inv['paid_at'] ?: App::UtcNow()) : null));
    }

    /**
     * Record a payment. With an account it also adds the income in Money.
     */
    public static function AddPayment($id, $amount, $date, $method, $notes, $accountId)
    {
        $inv = self::Get($id);
        if (!$inv || $inv['status'] === 'void') {
            throw new RuntimeException('Invoice not found.');
        }
        $amount = abs(parse_money($amount));
        if ($amount <= 0) {
            throw new RuntimeException('Enter the amount received.');
        }
        $date = valid_date($date) ?: today();
        $pid = insert('invoice_payments', array('invoice_id' => $id, 'date' => $date, 'amount' => $amount, 'method' => isset(self::METHODS[$method]) ? $method : null,
            'notes' => mb_substr(trim((string)$notes), 0, 255) ?: null, 'created_at' => App::UtcNow()));
        if ($accountId) {
            $cat = val("SELECT id FROM fin_categories WHERE name = 'Client income' AND kind = 'income'") ?: val("SELECT id FROM fin_categories WHERE kind = 'income' ORDER BY sort LIMIT 1");
            $tx = Finance::SaveTransaction(null, array('kind' => 'income', 'account_id' => $accountId, 'amount' => $amount, 'date' => $date, 'payee' => $inv['client_name'], 'memo' => 'Payment for invoice ' . $inv['number'], 'category_id' => $cat, 'client_id' => $inv['client_id']));
            run('UPDATE fin_transactions SET invoice_payment_id = ? WHERE id = ?', array($pid, $tx));
            run('UPDATE invoice_payments SET transaction_id = ? WHERE id = ?', array($tx, $pid));
        }
        if ($inv['status'] === 'draft') {
            run("UPDATE invoices SET status = 'sent', sent_at = COALESCE(sent_at, ?) WHERE id = ?", array(App::UtcNow(), $id));
        }
        self::Recalc($id);
        App::Activity('time', 'Payment of ' . money($amount) . ' on ' . $inv['number'] . ' from ' . $inv['client_name'], '/time/invoice/' . $id);
        return $pid;
    }

    public static function DeletePayment($paymentId)
    {
        $pm = row('SELECT * FROM invoice_payments WHERE id = ?', array($paymentId));
        if (!$pm) {
            return;
        }
        if ($pm['transaction_id']) {
            run('DELETE FROM fin_transactions WHERE id = ?', array($pm['transaction_id']));
        }
        run('DELETE FROM invoice_payments WHERE id = ?', array($paymentId));
        self::Recalc($pm['invoice_id']);
    }

    public static function MarkSent($id)
    {
        run("UPDATE invoices SET status = 'sent', sent_at = COALESCE(sent_at, ?) WHERE id = ? AND status = 'draft'", array(App::UtcNow(), $id));
        self::Recalc($id);
    }

    /** Email the client a link to view the invoice */
    public static function Send($id, $to, $message)
    {
        $inv = self::Get($id);
        if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('Enter the client\'s email address.');
        }
        $company = App::Setting('company_name', App::Setting('site_name', 'Home'));
        Mailer::Queue($to, trim($inv['contact_first'] . ' ' . $inv['contact_last']) ?: $inv['client_name'], 'Invoice ' . $inv['number'] . ' from ' . $company, 'invoice', array(
            'number' => $inv['number'], 'company' => $company, 'message' => $message, 'total' => money($inv['balance'] ?: $inv['total'], $inv['currency']),
            'due' => $inv['due_date'] ? date('F j, Y', strtotime($inv['due_date'])) : 'on receipt', 'url' => App::BaseUrl() . '/i/' . $inv['public_token'],
            'client' => $inv['client_name'], 'contact' => $inv['contact_first'],
        ));
        foreach (array_filter(array_map('trim', explode(',', (string)$inv['cc_emails']))) as $cc) {
            if (filter_var($cc, FILTER_VALIDATE_EMAIL)) {
                Mailer::Queue($cc, null, 'Invoice ' . $inv['number'] . ' from ' . $company, 'invoice', array('number' => $inv['number'], 'company' => $company, 'message' => $message,
                    'total' => money($inv['balance'] ?: $inv['total'], $inv['currency']), 'due' => $inv['due_date'] ? date('F j, Y', strtotime($inv['due_date'])) : 'on receipt',
                    'url' => App::BaseUrl() . '/i/' . $inv['public_token'], 'client' => $inv['client_name'], 'contact' => ''));
            }
        }
        self::MarkSent($id);
        App::Activity('time', 'Sent invoice ' . $inv['number'] . ' to ' . $to, '/time/invoice/' . $id);
    }

    public static function Void($id)
    {
        self::Release($id);
        run("UPDATE invoices SET status = 'void' WHERE id = ?", array($id));
    }

    public static function Delete($id)
    {
        $inv = row('SELECT * FROM invoices WHERE id = ?', array($id));
        if (!$inv) {
            return;
        }
        if ((float)$inv['paid'] > 0) {
            throw new RuntimeException('Remove the payments first, or void the invoice instead.');
        }
        self::Release($id);
        run('DELETE FROM invoice_lines WHERE invoice_id = ?', array($id));
        run('DELETE FROM invoices WHERE id = ?', array($id));
    }

    /** Put the time, flat fees and expenses back to unbilled */
    private static function Release($id)
    {
        run('UPDATE time_entries SET invoice_id = NULL, rate = NULL WHERE invoice_id = ?', array($id));
        run('UPDATE fin_transactions SET invoice_id = NULL WHERE invoice_id = ? AND invoice_payment_id IS NULL', array($id));
        run('UPDATE projects SET flat_fee_invoice_id = NULL WHERE flat_fee_invoice_id = ?', array($id));
    }

    public static function Duplicate($id)
    {
        $inv = self::Get($id);
        $new = self::CreateBlank($inv['client_id']);
        update('invoices', 'id', $new, array('title' => $inv['title'], 'notes' => $inv['notes'], 'terms' => $inv['terms'], 'po_number' => $inv['po_number'], 'discount_pct' => $inv['discount_pct'], 'tax_name' => $inv['tax_name'], 'tax_rate' => $inv['tax_rate']));
        foreach ($inv['lines'] as $l) {
            insert('invoice_lines', array('invoice_id' => $new, 'sort' => $l['sort'], 'kind' => 'item', 'description' => $l['description'], 'quantity' => $l['quantity'], 'rate' => $l['rate'], 'amount' => $l['amount']));
        }
        self::Recalc($new);
        return $new;
    }
}
