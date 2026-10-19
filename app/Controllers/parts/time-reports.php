<?php

/**
 * /time/reports: time summary & details, client report, invoices aging, revenue, project profitability.
 * Included by app/Controllers/time.php.
 */

$type = in_array($_GET['type'] ?? '', array('time', 'clients', 'aging', 'revenue', 'projects'), true) ? $_GET['type'] : 'time';
$range = $_GET['range'] ?? 'month';
$t = today();
switch ($range) {
    case 'week': $from = App::WeekStart($t); $to = date('Y-m-d', strtotime($from . ' +6 days')); break;
    case 'last-week': $from = date('Y-m-d', strtotime(App::WeekStart($t) . ' -7 days')); $to = date('Y-m-d', strtotime($from . ' +6 days')); break;
    case 'last-month': $from = date('Y-m-01', strtotime(substr($t, 0, 7) . '-01 -1 month')); $to = date('Y-m-t', strtotime($from)); break;
    case 'quarter': $q = (int)ceil(date('n', strtotime($t)) / 3); $from = date('Y', strtotime($t)) . '-' . str_pad((string)(($q - 1) * 3 + 1), 2, '0', STR_PAD_LEFT) . '-01'; $to = date('Y-m-t', strtotime($from . ' +2 months')); break;
    case 'year': $from = substr($t, 0, 4) . '-01-01'; $to = substr($t, 0, 4) . '-12-31'; break;
    case 'last-year': $from = (substr($t, 0, 4) - 1) . '-01-01'; $to = (substr($t, 0, 4) - 1) . '-12-31'; break;
    case 'all': $from = '2000-01-01'; $to = '2099-12-31'; break;
    case 'custom': $from = valid_date($_GET['from'] ?? '') ?: substr($t, 0, 7) . '-01'; $to = valid_date($_GET['to'] ?? '') ?: $t; break;
    default: $range = 'month'; $from = substr($t, 0, 7) . '-01'; $to = date('Y-m-t', strtotime($from));
}
$f = array(
    'from' => $from, 'to' => $to,
    'client' => (int)($_GET['client'] ?? 0) ?: null,
    'project' => (int)($_GET['project'] ?? 0) ?: null,
    'user' => (int)($_GET['user'] ?? 0) ?: null,
    'service' => (int)($_GET['service'] ?? 0) ?: null,
    'status' => in_array($_GET['status'] ?? '', array('billed', 'unbilled'), true) ? $_GET['status'] : null,
    'billable' => isset($_GET['billable']) && $_GET['billable'] !== '' ? (int)$_GET['billable'] : '',
);
$group = in_array($_GET['group'] ?? '', array('client', 'project', 'service', 'user', 'day'), true) ? $_GET['group'] : 'client';

$pageVars += array('type' => $type, 'range' => $range, 'from' => $from, 'to' => $to, 'f' => $f, 'group' => $group);

if ($type === 'time') {
    $report = TimeTracker::Report($f, $group);
    if (isset($_GET['export'])) {
        csv_out('time-' . $from . '-to-' . $to . '.csv', array('Date', 'Person', 'Client', 'Project', 'Service', 'Notes', 'Hours', 'Billable', 'Rate', 'Amount', 'Invoice'), array_map(function ($e) {
            return array($e['date'], $e['user_name'], $e['client_name'], $e['project_name'], $e['service_name'], $e['notes'], number_format(hours($e['seconds']), 2, '.', ''),
                $e['billable_effective'] ? 'yes' : 'no', $e['rate_effective'] !== null ? number_format($e['rate_effective'], 2, '.', '') : '', number_format($e['value'], 2, '.', ''), $e['invoice_number']);
        }, array_reverse($report['entries'])));
    }
    $pageVars['report'] = $report;
    // daily hours for the chart
    $chart = array();
    foreach ($report['entries'] as $e) {
        $chart[$e['date']] = ($chart[$e['date']] ?? 0) + $e['seconds'];
    }
    ksort($chart);
    $pageVars['chart'] = array('labels' => array_map(function ($d) { return date('M j', strtotime($d)); }, array_keys($chart)), 'values' => array_map(function ($s) { return round($s / 3600, 2); }, array_values($chart)));
}

if ($type === 'clients') {
    // Per client for the period: hours, billable value, invoiced, collected, outstanding now, unbilled now
    $out = array();
    foreach (rows('SELECT * FROM clients ORDER BY name') as $c) {
        $r = TimeTracker::Report(array('client' => $c['id'], 'from' => $from, 'to' => $to), 'client');
        $invoiced = (float)val("SELECT COALESCE(SUM(total), 0) FROM invoices WHERE client_id = ? AND status <> 'void' AND status <> 'draft' AND issue_date BETWEEN ? AND ?", array($c['id'], $from, $to));
        $collected = (float)val('SELECT COALESCE(SUM(pm.amount), 0) FROM invoice_payments pm JOIN invoices i ON i.id = pm.invoice_id WHERE i.client_id = ? AND pm.date BETWEEN ? AND ?', array($c['id'], $from, $to));
        $expenses = (float)val("SELECT COALESCE(-SUM(amount), 0) FROM fin_transactions WHERE client_id = ? AND kind = 'expense' AND date BETWEEN ? AND ?", array($c['id'], $from, $to));
        if (!$r['totals']['seconds'] && !$invoiced && !$collected && $c['archived']) {
            continue;
        }
        $out[] = array('id' => $c['id'], 'name' => $c['name'], 'color' => $c['color'], 'seconds' => $r['totals']['seconds'], 'billable_seconds' => $r['totals']['billable_seconds'],
            'value' => $r['totals']['value'], 'invoiced' => $invoiced, 'collected' => $collected, 'expenses' => $expenses,
            'effective_rate' => $r['totals']['seconds'] > 0 ? round($collected / ($r['totals']['seconds'] / 3600), 2) : null, 'projects' => $r['groups'][0]['subs'] ?? array());
    }
    usort($out, function ($a, $b) { return $b['value'] <=> $a['value'] ?: $b['seconds'] <=> $a['seconds']; });
    $pageVars['clients_report'] = $out;
    if (isset($_GET['export'])) {
        csv_out('clients-' . $from . '-to-' . $to . '.csv', array('Client', 'Hours', 'Billable hours', 'Billable value', 'Invoiced', 'Collected', 'Expenses'), array_map(function ($c) {
            return array($c['name'], number_format(hours($c['seconds']), 2, '.', ''), number_format(hours($c['billable_seconds']), 2, '.', ''), $c['value'], $c['invoiced'], $c['collected'], $c['expenses']);
        }, $out));
    }
}

if ($type === 'aging') {
    $pageVars['aging'] = TimeTracker::Aging();
}

if ($type === 'revenue') {
    $pageVars['revenue'] = TimeTracker::Revenue(12);
    $pageVars['by_client'] = rows('SELECT c.name, c.color, SUM(pm.amount) AS total, COUNT(DISTINCT i.id) AS n FROM invoice_payments pm JOIN invoices i ON i.id = pm.invoice_id JOIN clients c ON c.id = i.client_id WHERE pm.date BETWEEN ? AND ? GROUP BY c.id, c.name, c.color ORDER BY total DESC', array($from, $to));
    $pageVars['payments'] = rows('SELECT pm.*, i.number, i.id AS invoice_id, c.name AS client FROM invoice_payments pm JOIN invoices i ON i.id = pm.invoice_id JOIN clients c ON c.id = i.client_id WHERE pm.date BETWEEN ? AND ? ORDER BY pm.date DESC', array($from, $to));
}

if ($type === 'projects') {
    $list = TimeTracker::Projects(null, 'all');
    foreach ($list as &$p) {
        $p['expenses'] = (float)val("SELECT COALESCE(-SUM(amount), 0) FROM fin_transactions WHERE project_id = ? AND kind = 'expense'", array($p['id']));
        $p['effective_rate'] = $p['seconds'] > 0 ? round(($p['value'] - $p['expenses']) / ($p['seconds'] / 3600), 2) : null;
    }
    $pageVars['projects_report'] = array_values(array_filter($list, function ($p) { return $p['seconds'] > 0 || $p['status'] === 'active'; }));
}

$pageVars['project_list'] = rows('SELECT p.id, p.name, c.name AS client FROM projects p JOIN clients c ON c.id = p.client_id ORDER BY c.name, p.name');
$base['page_title'] = 'Time reports';
$pageNeeded = 'time/reports';
