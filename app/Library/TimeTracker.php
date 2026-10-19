<?php

/**
 * Time tracking, FreshBooks style: Clients → Projects → time entries (with a service).
 */
class TimeTracker
{
    const BILLING = array(
        'hourly_project' => 'Hourly, one project rate',
        'hourly_service' => 'Hourly, by service rate',
        'hourly_user' => 'Hourly, by team member rate',
        'flat_fee' => 'Flat fee',
        'non_billable' => 'Non-billable',
    );

    /* ================= Rates ================= */

    /**
     * Hourly rate an entry bills at (null = not billed by the hour)
     */
    public static function RateFor($project, $service, $user, $client = null)
    {
        $default = (float)App::Setting('default_rate', 0);
        $clientRate = $client && $client['default_rate'] !== null ? (float)$client['default_rate'] : null;
        if (!$project) {
            return $clientRate ?? ($default ?: null);
        }
        switch ($project['billing']) {
            case 'non_billable':
            case 'flat_fee':
                return null;
            case 'hourly_service':
                if ($service && $service['rate'] !== null) {
                    return (float)$service['rate'];
                }
                break;
            case 'hourly_user':
                if ($user && $user['hourly_rate'] !== null) {
                    return (float)$user['hourly_rate'];
                }
                break;
            default:
                if ($project['rate'] !== null) {
                    return (float)$project['rate'];
                }
        }
        return $project['rate'] !== null ? (float)$project['rate'] : ($clientRate ?? ($default ?: null));
    }

    /**
     * Adds rate, value and billable_effective to entry rows (needs the joined columns from Entries()).
     */
    public static function Price(array &$entries)
    {
        static $users = null, $services = null;
        if ($users === null) {
            $users = array();
            foreach (rows('SELECT Id_admin_users, hourly_rate FROM admin_users') as $u) {
                $users[$u['Id_admin_users']] = $u;
            }
            $services = array();
            foreach (rows('SELECT * FROM services') as $s) {
                $services[$s['id']] = $s;
            }
        }
        foreach ($entries as &$e) {
            $seconds = self::Seconds($e);
            $e['seconds'] = $seconds;
            $service = $services[$e['service_id']] ?? null;
            $billable = (int)$e['billable'] === 1 && ($e['project_billing'] ?? '') !== 'non_billable' && (!$service || (int)$service['billable'] === 1);
            if ($e['invoice_id'] && $e['rate'] !== null) {
                $rate = (float)$e['rate']; // locked when invoiced
            } else {
                $project = $e['project_id'] ? array('billing' => $e['project_billing'], 'rate' => $e['project_rate']) : null;
                $client = $e['client_id'] ? array('default_rate' => $e['client_rate']) : null;
                $rate = self::RateFor($project, $service, $users[$e['user_id']] ?? null, $client);
            }
            $e['billable_effective'] = $billable && $rate !== null;
            $e['rate_effective'] = $rate;
            $e['value'] = $e['billable_effective'] ? round($seconds / 3600 * $rate, 2) : 0;
            $e['running'] = $e['duration'] === null && $e['started_at'] !== null;
            $e['started_ts'] = $e['started_at'] ? strtotime($e['started_at'] . ' UTC') : null;
        }
    }

    /** Seconds of an entry (counting a running timer up to now) */
    public static function Seconds(array $e)
    {
        if ($e['duration'] === null && !empty($e['started_at'])) {
            return max(0, time() - strtotime($e['started_at'] . ' UTC'));
        }
        return (int)$e['duration'];
    }

    /* ================= Entries ================= */

    /**
     * Time entries with client/project/service names. $f: user, client, project, service, from, to, billable, status (unbilled|billed), invoice, q
     */
    public static function Entries(array $f = array(), $limit = 1000)
    {
        $w = array();
        $p = array();
        foreach (array('id' => 'e.id', 'user' => 'e.user_id', 'client' => 'e.client_id', 'project' => 'e.project_id', 'service' => 'e.service_id', 'invoice' => 'e.invoice_id') as $k => $col) {
            if (!empty($f[$k])) {
                if (is_array($f[$k])) {
                    $ids = array_map('intval', $f[$k]);
                    $w[] = $col . ' IN (' . implode(',', $ids ?: array(0)) . ')';
                } else {
                    $w[] = $col . ' = ?';
                    $p[] = (int)$f[$k];
                }
            }
        }
        if (!empty($f['from']) && valid_date($f['from'])) {
            $w[] = 'e.date >= ?';
            $p[] = $f['from'];
        }
        if (!empty($f['to']) && valid_date($f['to'])) {
            $w[] = 'e.date <= ?';
            $p[] = $f['to'];
        }
        if (isset($f['billable']) && $f['billable'] !== '') {
            $w[] = 'e.billable = ?';
            $p[] = (int)$f['billable'];
        }
        if (!empty($f['status'])) {
            $w[] = $f['status'] === 'billed' ? 'e.invoice_id IS NOT NULL' : 'e.invoice_id IS NULL';
        }
        if (!empty($f['stopped'])) {
            $w[] = 'e.duration IS NOT NULL';
        }
        if (!empty($f['q'])) {
            $w[] = '(e.notes LIKE ? OR pr.name LIKE ? OR c.name LIKE ?)';
            $q = '%' . $f['q'] . '%';
            array_push($p, $q, $q, $q);
        }
        $list = rows('SELECT e.*, c.name AS client_name, c.color AS client_color, c.default_rate AS client_rate,
                pr.name AS project_name, pr.billing AS project_billing, pr.rate AS project_rate, pr.color AS project_color,
                s.name AS service_name, CONCAT(u.first_name, \' \', u.last_name) AS user_name, i.number AS invoice_number, i.status AS invoice_status
            FROM time_entries e
            LEFT JOIN clients c ON c.id = e.client_id
            LEFT JOIN projects pr ON pr.id = e.project_id
            LEFT JOIN services s ON s.id = e.service_id
            LEFT JOIN admin_users u ON u.Id_admin_users = e.user_id
            LEFT JOIN invoices i ON i.id = e.invoice_id' . ($w ? ' WHERE ' . implode(' AND ', $w) : '') . '
            ORDER BY e.date DESC, e.started_at IS NULL, e.started_at DESC, e.id DESC LIMIT ' . (int)$limit, $p);
        self::Price($list);
        return $list;
    }

    public static function Entry($id)
    {
        $l = self::Entries(array('id' => (int)$id), 1);
        return $l[0] ?? null;
    }

    /**
     * Save a time entry from form input. Returns the id.
     */
    public static function Save($id, array $in, $uid = null)
    {
        $uid = $uid ?: App::UserId();
        $projectId = !empty($in['project_id']) ? (int)$in['project_id'] : null;
        $clientId = !empty($in['client_id']) ? (int)$in['client_id'] : null;
        if ($projectId) {
            $clientId = (int)val('SELECT client_id FROM projects WHERE id = ?', array($projectId)) ?: $clientId;
        }
        $duration = isset($in['duration']) ? parse_duration($in['duration']) : null;
        $round = (int)App::Setting('round_minutes', 0);
        if ($duration && $round > 0) {
            $duration = (int)(ceil($duration / ($round * 60)) * $round * 60);
        }
        $data = array(
            'client_id' => $clientId,
            'project_id' => $projectId,
            'service_id' => !empty($in['service_id']) ? (int)$in['service_id'] : null,
            'date' => valid_date($in['date'] ?? '') ?: today(),
            'notes' => trim((string)($in['notes'] ?? '')) ?: null,
            'billable' => isset($in['billable']) ? (int)!empty($in['billable']) : 1,
            'updated_at' => App::UtcNow(),
        );
        if ($id) {
            $old = row('SELECT * FROM time_entries WHERE id = ?', array($id));
            if (!$old) {
                throw new RuntimeException('Time entry not found.');
            }
            if ($old['invoice_id'] && in_array(val('SELECT status FROM invoices WHERE id = ?', array($old['invoice_id'])), array('sent', 'partial', 'paid'), true)) {
                throw new RuntimeException('This time is on an invoice that was already sent. Edit the invoice instead.');
            }
            if ($old['duration'] !== null || $duration) {
                $data['duration'] = $duration ?? (int)$old['duration'];
                if ($old['duration'] === null && $duration) {
                    $data['started_at'] = $old['started_at'];
                }
            }
            update('time_entries', 'id', $id, $data);
            return (int)$id;
        }
        if (!$duration) {
            throw new RuntimeException('Enter how long, like 1:30 or 1.5 or 90m.');
        }
        $data += array('user_id' => $uid, 'duration' => $duration, 'created_at' => App::UtcNow());
        $id = insert('time_entries', $data);
        return $id;
    }

    public static function Delete($id)
    {
        $e = row('SELECT * FROM time_entries WHERE id = ?', array($id));
        if (!$e) {
            return false;
        }
        if ($e['invoice_id']) {
            throw new RuntimeException('This time is on invoice ' . val('SELECT number FROM invoices WHERE id = ?', array($e['invoice_id'])) . '. Take it off the invoice first.');
        }
        run('DELETE FROM time_entries WHERE id = ?', array($id));
        return true;
    }

    /* ================= Timer ================= */

    public static function RunningTimer($uid)
    {
        try {
            $e = row('SELECT e.*, pr.name AS project_name, c.name AS client_name, s.name AS service_name FROM time_entries e
                LEFT JOIN projects pr ON pr.id = e.project_id LEFT JOIN clients c ON c.id = e.client_id LEFT JOIN services s ON s.id = e.service_id
                WHERE e.user_id = ? AND e.duration IS NULL AND e.started_at IS NOT NULL ORDER BY e.id DESC LIMIT 1', array($uid));
        } catch (Throwable $e) {
            return null;
        }
        if (!$e) {
            return null;
        }
        $e['elapsed'] = self::Seconds($e);
        $e['started_ts'] = strtotime($e['started_at'] . ' UTC');
        return $e;
    }

    /** Start a timer (stops any running one first). Returns the new entry id. */
    public static function Start($uid, array $in)
    {
        self::Stop($uid);
        $projectId = !empty($in['project_id']) ? (int)$in['project_id'] : null;
        $clientId = $projectId ? (int)val('SELECT client_id FROM projects WHERE id = ?', array($projectId)) : (!empty($in['client_id']) ? (int)$in['client_id'] : null);
        return insert('time_entries', array(
            'user_id' => $uid, 'client_id' => $clientId ?: null, 'project_id' => $projectId,
            'service_id' => !empty($in['service_id']) ? (int)$in['service_id'] : null,
            'date' => today(), 'started_at' => App::UtcNow(), 'duration' => null,
            'notes' => trim((string)($in['notes'] ?? '')) ?: null, 'billable' => 1, 'created_at' => App::UtcNow(),
        ));
    }

    /** Stop the running timer. Returns the stopped entry or null. */
    public static function Stop($uid)
    {
        $t = self::RunningTimer($uid);
        if (!$t) {
            return null;
        }
        $seconds = max(60, self::Seconds($t));
        $round = (int)App::Setting('round_minutes', 0);
        if ($round > 0) {
            $seconds = (int)(ceil($seconds / ($round * 60)) * $round * 60);
        }
        run('UPDATE time_entries SET duration = ?, updated_at = ? WHERE id = ?', array($seconds, App::UtcNow(), $t['id']));
        $t['duration'] = $seconds;
        return $t;
    }

    /** Start another timer like an existing entry ("play" on a row) */
    public static function Resume($uid, $entryId)
    {
        $e = row('SELECT * FROM time_entries WHERE id = ?', array($entryId));
        if (!$e) {
            throw new RuntimeException('Time entry not found.');
        }
        return self::Start($uid, array('project_id' => $e['project_id'], 'client_id' => $e['client_id'], 'service_id' => $e['service_id'], 'notes' => $e['notes']));
    }

    /* ================= Timesheet ================= */

    /**
     * Week grid: rows = project+service, columns = 7 days
     */
    public static function Week($uid, $weekStart)
    {
        $days = array();
        for ($i = 0; $i < 7; $i++) {
            $days[] = date('Y-m-d', strtotime($weekStart . ' +' . $i . ' days'));
        }
        $entries = self::Entries(array('user' => $uid, 'from' => $days[0], 'to' => $days[6]), 2000);
        $grid = array();
        $totals = array_fill_keys($days, 0);
        foreach ($entries as $e) {
            $key = ($e['project_id'] ?: 0) . '-' . ($e['service_id'] ?: 0) . '-' . ($e['project_id'] ? 0 : ($e['client_id'] ?: 0));
            if (!isset($grid[$key])) {
                $grid[$key] = array(
                    'project_id' => $e['project_id'], 'service_id' => $e['service_id'], 'client_id' => $e['client_id'],
                    'project_name' => $e['project_name'], 'client_name' => $e['client_name'], 'service_name' => $e['service_name'],
                    'color' => $e['project_color'] ?: $e['client_color'], 'days' => array_fill_keys($days, 0), 'entries' => array_fill_keys($days, array()),
                    'locked' => array_fill_keys($days, false), 'total' => 0, 'value' => 0,
                );
            }
            $grid[$key]['days'][$e['date']] += $e['seconds'];
            $grid[$key]['entries'][$e['date']][] = $e['id'];
            if ($e['invoice_id'] || $e['running']) {
                $grid[$key]['locked'][$e['date']] = true;
            }
            $grid[$key]['total'] += $e['seconds'];
            $grid[$key]['value'] += $e['value'];
            $totals[$e['date']] += $e['seconds'];
        }
        uasort($grid, function ($a, $b) {
            return strcmp(($a['client_name'] ?? '') . $a['project_name'], ($b['client_name'] ?? '') . $b['project_name']);
        });
        return array('days' => $days, 'rows' => array_values($grid), 'totals' => $totals, 'total' => array_sum($totals), 'value' => array_sum(array_column($grid, 'value')));
    }

    /**
     * Set one timesheet cell. Replaces that day's (un-invoiced) entries for the row with one entry.
     */
    public static function SetCell($uid, $projectId, $serviceId, $clientId, $date, $durationText)
    {
        $date = valid_date($date);
        if (!$date) {
            throw new RuntimeException('Bad date.');
        }
        $seconds = parse_duration($durationText);
        $p = array($uid, $date);
        $w = 'user_id = ? AND date = ? AND invoice_id IS NULL AND duration IS NOT NULL';
        $w .= $projectId ? ' AND project_id = ?' : ' AND project_id IS NULL';
        if ($projectId) $p[] = $projectId;
        $w .= $serviceId ? ' AND service_id = ?' : ' AND service_id IS NULL';
        if ($serviceId) $p[] = $serviceId;
        if (!$projectId) {
            $w .= $clientId ? ' AND client_id = ?' : ' AND client_id IS NULL';
            if ($clientId) $p[] = $clientId;
        }
        $existing = rows('SELECT * FROM time_entries WHERE ' . $w . ' ORDER BY id', $p);
        $notes = implode("\n", array_filter(array_column($existing, 'notes')));
        run('DELETE FROM time_entries WHERE ' . $w, $p);
        if ($seconds > 0) {
            if ($projectId) {
                $clientId = (int)val('SELECT client_id FROM projects WHERE id = ?', array($projectId)) ?: $clientId;
            }
            insert('time_entries', array('user_id' => $uid, 'client_id' => $clientId ?: null, 'project_id' => $projectId ?: null, 'service_id' => $serviceId ?: null,
                'date' => $date, 'duration' => $seconds, 'notes' => $notes ?: null, 'billable' => 1, 'created_at' => App::UtcNow()));
        }
        return $seconds;
    }

    /* ================= Clients & projects ================= */

    /** Clients with totals: hours, unbilled value, outstanding invoices */
    public static function Clients($includeArchived = false)
    {
        $list = rows('SELECT c.*,
                (SELECT COUNT(*) FROM projects p WHERE p.client_id = c.id AND p.status = \'active\') AS active_projects,
                (SELECT COALESCE(SUM(duration), 0) FROM time_entries e WHERE e.client_id = c.id) AS seconds,
                (SELECT COALESCE(SUM(i.total - i.paid), 0) FROM invoices i WHERE i.client_id = c.id AND i.status IN (\'sent\', \'partial\')) AS outstanding,
                (SELECT COALESCE(SUM(i.paid), 0) FROM invoices i WHERE i.client_id = c.id AND i.status <> \'void\') AS collected,
                (SELECT MAX(e.date) FROM time_entries e WHERE e.client_id = c.id) AS last_tracked
            FROM clients c ' . ($includeArchived ? '' : 'WHERE c.archived = 0 ') . 'ORDER BY c.archived, c.name');
        $unbilled = self::UnbilledByClient();
        foreach ($list as &$c) {
            $c['unbilled'] = $unbilled[$c['id']] ?? 0;
        }
        return $list;
    }

    /** client_id => unbilled value (time + billable expenses) */
    public static function UnbilledByClient()
    {
        $out = array();
        $entries = self::Entries(array('status' => 'unbilled', 'stopped' => 1), 100000);
        foreach ($entries as $e) {
            if ($e['client_id']) {
                $out[$e['client_id']] = ($out[$e['client_id']] ?? 0) + $e['value'];
            }
        }
        foreach (rows("SELECT client_id, -SUM(amount) AS s FROM fin_transactions WHERE billable = 1 AND invoice_id IS NULL AND client_id IS NOT NULL GROUP BY client_id") as $r) {
            $out[$r['client_id']] = ($out[$r['client_id']] ?? 0) + (float)$r['s'];
        }
        foreach (rows("SELECT client_id, SUM(flat_fee) AS s FROM projects WHERE billing = 'flat_fee' AND flat_fee > 0 AND flat_fee_invoice_id IS NULL AND status <> 'archived' GROUP BY client_id") as $r) {
            $out[$r['client_id']] = ($out[$r['client_id']] ?? 0) + (float)$r['s'];
        }
        return array_map(function ($v) { return round($v, 2); }, $out);
    }

    public static function Projects($clientId = null, $status = 'active')
    {
        $w = array();
        $p = array();
        if ($clientId) {
            $w[] = 'p.client_id = ?';
            $p[] = $clientId;
        }
        if ($status && $status !== 'all') {
            $w[] = 'p.status = ?';
            $p[] = $status;
        }
        $list = rows('SELECT p.*, c.name AS client_name, c.color AS client_color,
                (SELECT COALESCE(SUM(e.duration), 0) FROM time_entries e WHERE e.project_id = p.id) AS seconds,
                (SELECT COALESCE(SUM(e.duration), 0) FROM time_entries e WHERE e.project_id = p.id AND e.invoice_id IS NULL) AS unbilled_seconds,
                (SELECT MAX(e.date) FROM time_entries e WHERE e.project_id = p.id) AS last_tracked
            FROM projects p JOIN clients c ON c.id = p.client_id' . ($w ? ' WHERE ' . implode(' AND ', $w) : '') . ' ORDER BY c.name, p.name', $p);
        foreach ($list as &$pr) {
            self::ProjectNumbers($pr);
        }
        return $list;
    }

    /** Adds billed/unbilled values and budget burn to a project row */
    public static function ProjectNumbers(array &$pr)
    {
        $entries = self::Entries(array('project' => $pr['id'], 'stopped' => 1), 100000);
        $value = $unbilled = 0;
        foreach ($entries as $e) {
            $value += $e['value'];
            if (!$e['invoice_id']) {
                $unbilled += $e['value'];
            }
        }
        $pr['value'] = round($value, 2);
        $pr['unbilled'] = round($unbilled, 2);
        if ($pr['billing'] === 'flat_fee') {
            $pr['value'] = (float)$pr['flat_fee'];
            $pr['unbilled'] = $pr['flat_fee_invoice_id'] ? 0 : (float)$pr['flat_fee'];
            $pr['effective_rate'] = $pr['seconds'] > 0 ? round($pr['flat_fee'] / ($pr['seconds'] / 3600), 2) : null;
        }
        $pr['hours'] = hours($pr['seconds']);
        $pr['budget_pct'] = null;
        if ($pr['budget_hours'] > 0) {
            $pr['budget_pct'] = round($pr['hours'] / $pr['budget_hours'] * 100);
        } elseif ($pr['budget_amount'] > 0) {
            $pr['budget_pct'] = round($pr['value'] / $pr['budget_amount'] * 100);
        }
        return $pr;
    }

    /** Projects grouped by client, for selects */
    public static function ProjectOptions($includeId = null)
    {
        $list = rows("SELECT p.id, p.name, p.client_id, p.billing, c.name AS client_name FROM projects p JOIN clients c ON c.id = p.client_id
            WHERE (p.status = 'active' AND c.archived = 0) OR p.id = ? ORDER BY c.name, p.name", array((int)$includeId));
        $out = array();
        foreach ($list as $p) {
            $out[$p['client_name']][] = $p;
        }
        return $out;
    }

    public static function Services()
    {
        return rows('SELECT * FROM services WHERE archived = 0 ORDER BY sort, name');
    }

    /* ================= Reports ================= */

    /** Seconds per day for the last N days (stacked by client for the dashboard chart) */
    public static function DailyByClient($uid, $from, $to)
    {
        $days = array();
        for ($d = $from; $d <= $to; $d = date('Y-m-d', strtotime($d . ' +1 day'))) {
            $days[] = $d;
        }
        $series = array();
        foreach (rows('SELECT e.date, COALESCE(c.name, \'No client\') AS client, COALESCE(c.color, \'#adb5bd\') AS color, SUM(COALESCE(e.duration, TIMESTAMPDIFF(SECOND, e.started_at, UTC_TIMESTAMP()))) AS s
            FROM time_entries e LEFT JOIN clients c ON c.id = e.client_id WHERE e.user_id = ? AND e.date BETWEEN ? AND ? GROUP BY e.date, c.name, c.color', array($uid, $from, $to)) as $r) {
            if (!isset($series[$r['client']])) {
                $series[$r['client']] = array('name' => $r['client'], 'color' => $r['color'], 'data' => array_fill_keys($days, 0));
            }
            $series[$r['client']]['data'][$r['date']] = round($r['s'] / 3600, 2);
        }
        foreach ($series as &$s) {
            $s['data'] = array_values($s['data']);
        }
        return array('days' => $days, 'series' => array_values($series));
    }

    /**
     * Grouped report: by client → project, or by service, or by user, or by day
     */
    public static function Report(array $f, $groupBy = 'client')
    {
        $entries = self::Entries($f + array('stopped' => 1), 100000);
        $groups = array();
        $tot = array('seconds' => 0, 'billable_seconds' => 0, 'value' => 0, 'unbilled' => 0, 'billed' => 0);
        foreach ($entries as $e) {
            switch ($groupBy) {
                case 'service': $k = $e['service_name'] ?: 'No service'; $sub = $e['client_name'] ?: 'No client'; break;
                case 'user': $k = $e['user_name']; $sub = $e['project_name'] ?: ($e['client_name'] ?: 'No project'); break;
                case 'day': $k = $e['date']; $sub = $e['project_name'] ?: ($e['client_name'] ?: 'No project'); break;
                case 'project': $k = ($e['client_name'] ?: 'No client') . ' — ' . ($e['project_name'] ?: 'No project'); $sub = $e['service_name'] ?: 'No service'; break;
                default: $k = $e['client_name'] ?: 'No client'; $sub = $e['project_name'] ?: 'No project';
            }
            if (!isset($groups[$k])) {
                $groups[$k] = array('name' => $k, 'seconds' => 0, 'billable_seconds' => 0, 'value' => 0, 'unbilled' => 0, 'subs' => array(), 'client_id' => $e['client_id'], 'color' => $e['client_color']);
            }
            if (!isset($groups[$k]['subs'][$sub])) {
                $groups[$k]['subs'][$sub] = array('name' => $sub, 'seconds' => 0, 'value' => 0, 'unbilled' => 0);
            }
            foreach (array(&$groups[$k], &$groups[$k]['subs'][$sub]) as &$g) {
                $g['seconds'] += $e['seconds'];
                $g['value'] += $e['value'];
                if (!$e['invoice_id']) {
                    $g['unbilled'] += $e['value'];
                }
            }
            unset($g);
            if ($e['billable_effective']) {
                $groups[$k]['billable_seconds'] += $e['seconds'];
                $tot['billable_seconds'] += $e['seconds'];
            }
            $tot['seconds'] += $e['seconds'];
            $tot['value'] += $e['value'];
            if ($e['invoice_id']) {
                $tot['billed'] += $e['value'];
            } else {
                $tot['unbilled'] += $e['value'];
            }
        }
        if ($groupBy === 'day') {
            krsort($groups);
        } else {
            uasort($groups, function ($a, $b) { return $b['seconds'] <=> $a['seconds']; });
        }
        foreach ($groups as &$g) {
            uasort($g['subs'], function ($a, $b) { return $b['seconds'] <=> $a['seconds']; });
            $g['subs'] = array_values($g['subs']);
        }
        return array('groups' => array_values($groups), 'totals' => $tot, 'entries' => $entries);
    }

    /** Accounts receivable aging */
    public static function Aging()
    {
        $buckets = array('current' => 0, '1-30' => 0, '31-60' => 0, '61-90' => 0, '90+' => 0);
        $byClient = array();
        $t = today();
        foreach (rows("SELECT i.*, c.name AS client_name FROM invoices i JOIN clients c ON c.id = i.client_id WHERE i.status IN ('sent','partial')") as $i) {
            $bal = round($i['total'] - $i['paid'], 2);
            $late = $i['due_date'] ? (int)(new DateTime($i['due_date']))->diff(new DateTime($t))->format('%r%a') : 0;
            $b = $late <= 0 ? 'current' : ($late <= 30 ? '1-30' : ($late <= 60 ? '31-60' : ($late <= 90 ? '61-90' : '90+')));
            $buckets[$b] += $bal;
            if (!isset($byClient[$i['client_id']])) {
                $byClient[$i['client_id']] = array('name' => $i['client_name'], 'id' => $i['client_id'], 'total' => 0) + array_fill_keys(array_keys($buckets), 0);
            }
            $byClient[$i['client_id']][$b] += $bal;
            $byClient[$i['client_id']]['total'] += $bal;
        }
        usort($byClient, function ($a, $b) { return $b['total'] <=> $a['total']; });
        return array('buckets' => $buckets, 'clients' => $byClient, 'total' => array_sum($buckets));
    }

    /** Payments received per month (revenue) for the last N months */
    public static function Revenue($months = 12, $clientId = null)
    {
        $labels = $values = array();
        for ($i = $months - 1; $i >= 0; $i--) {
            $m = date('Y-m', strtotime(substr(today(), 0, 7) . '-01 -' . $i . ' months'));
            $p = array($m . '-01', date('Y-m-t', strtotime($m . '-01')));
            $sql = 'SELECT COALESCE(SUM(pm.amount), 0) FROM invoice_payments pm JOIN invoices i ON i.id = pm.invoice_id WHERE pm.date BETWEEN ? AND ?';
            if ($clientId) {
                $sql .= ' AND i.client_id = ?';
                $p[] = $clientId;
            }
            $labels[] = date('M', strtotime($m . '-01'));
            $values[] = round((float)val($sql, $p), 2);
        }
        return array('labels' => $labels, 'values' => $values);
    }
}
