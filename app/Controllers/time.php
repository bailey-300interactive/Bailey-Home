<?php

/**
 * Time tracking: /time, /time/timesheet, /time/clients, /time/client/<id>, /time/projects, /time/project/<id>,
 * /time/services, /time/reports, /time/invoices, /time/invoice/<id>, /time/invoice-new
 */

App::RequireLogin();
$uid = App::UserId();
$base['page_title'] = 'Time';
$pageVars['billing'] = TimeTracker::BILLING;
$pageVars['services'] = TimeTracker::Services();
$pageVars['project_options'] = TimeTracker::ProjectOptions();
$pageVars['client_options'] = rows('SELECT id, name, color FROM clients WHERE archived = 0 ORDER BY name');
$pageVars['users'] = AdminUser::Options();
$currency = App::Setting('currency', 'USD');

/* ---------- Form posts ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    App::CheckCsrf();
    $action = post('action');
    $back = $_SERVER['REQUEST_URI'];
    try {
        switch ($action) {
            case 'client-save':
                $data = array(
                    'name' => mb_substr(post('name'), 0, 128), 'contact_first' => mb_substr(post('contact_first'), 0, 64) ?: null, 'contact_last' => mb_substr(post('contact_last'), 0, 64) ?: null,
                    'email' => filter_var(post('email'), FILTER_VALIDATE_EMAIL) ?: null, 'cc_emails' => mb_substr(post('cc_emails'), 0, 255) ?: null, 'phone' => mb_substr(post('phone'), 0, 40) ?: null,
                    'website' => mb_substr(post('website'), 0, 190) ?: null, 'address1' => mb_substr(post('address1'), 0, 128) ?: null, 'address2' => mb_substr(post('address2'), 0, 128) ?: null,
                    'city' => mb_substr(post('city'), 0, 64) ?: null, 'state' => mb_substr(post('state'), 0, 64) ?: null, 'zip' => mb_substr(post('zip'), 0, 16) ?: null, 'country' => mb_substr(post('country'), 0, 64) ?: null,
                    'currency' => preg_match('/^[A-Z]{3}$/', post('currency')) ? post('currency') : null,
                    'default_rate' => post('default_rate') !== '' ? parse_money(post('default_rate')) : null,
                    'payment_terms' => post('payment_terms') !== '' ? max(0, (int)post('payment_terms')) : null,
                    'color' => preg_match('/^#[0-9a-f]{6}$/i', post('color')) ? post('color') : '#3b7ddd', 'notes' => post('notes') ?: null, 'archived' => post_bool('archived'),
                );
                if ($data['name'] === '') {
                    throw new RuntimeException('Enter the client\'s name.');
                }
                if (post_id('id')) {
                    update('clients', 'id', post_id('id'), $data);
                    $id = post_id('id');
                } else {
                    $id = insert('clients', $data + array('created_at' => App::UtcNow()));
                    App::Activity('time', 'Added client ' . $data['name'], '/time/client/' . $id);
                    if (post_bool('with_project')) {
                        insert('projects', array('client_id' => $id, 'name' => 'General', 'billing' => 'hourly_project', 'rate' => $data['default_rate'] ?? (float)App::Setting('default_rate', 100), 'status' => 'active', 'color' => $data['color'], 'created_at' => App::UtcNow()));
                    }
                }
                App::QueueMessage('Client saved.');
                $back = '/time/client/' . $id;
                break;

            case 'client-delete':
                $id = post_id('id');
                if (val('SELECT COUNT(*) FROM time_entries WHERE client_id = ?', array($id)) || val('SELECT COUNT(*) FROM invoices WHERE client_id = ?', array($id))) {
                    throw new RuntimeException('This client has time or invoices. Archive it instead.');
                }
                run('DELETE FROM projects WHERE client_id = ?', array($id));
                run('DELETE FROM clients WHERE id = ?', array($id));
                App::QueueMessage('Client deleted.');
                $back = '/time/clients';
                break;

            case 'project-save':
                $clientId = post_id('client_id');
                if (!$clientId || !val('SELECT 1 FROM clients WHERE id = ?', array($clientId))) {
                    throw new RuntimeException('Pick a client.');
                }
                $data = array(
                    'client_id' => $clientId, 'name' => mb_substr(post('name'), 0, 128), 'description' => post('description') ?: null,
                    'billing' => isset(TimeTracker::BILLING[post('billing')]) ? post('billing') : 'hourly_project',
                    'rate' => post('rate') !== '' ? parse_money(post('rate')) : null, 'flat_fee' => post('flat_fee') !== '' ? parse_money(post('flat_fee')) : null,
                    'budget_hours' => post('budget_hours') !== '' ? (float)post('budget_hours') : null, 'budget_amount' => post('budget_amount') !== '' ? parse_money(post('budget_amount')) : null,
                    'due_date' => valid_date(post('due_date')), 'status' => in_array(post('status'), array('active', 'completed', 'archived'), true) ? post('status') : 'active',
                    'color' => preg_match('/^#[0-9a-f]{6}$/i', post('color')) ? post('color') : null,
                );
                if ($data['name'] === '') {
                    throw new RuntimeException('Name the project.');
                }
                if (post_id('id')) {
                    update('projects', 'id', post_id('id'), $data);
                    // keep entries' client in step if the project moved to another client
                    run('UPDATE time_entries SET client_id = ? WHERE project_id = ? AND invoice_id IS NULL', array($clientId, post_id('id')));
                    $id = post_id('id');
                } else {
                    $id = insert('projects', $data + array('created_at' => App::UtcNow()));
                    App::Activity('time', 'Added project ' . $data['name'], '/time/project/' . $id);
                }
                App::QueueMessage('Project saved.');
                $back = '/time/project/' . $id;
                break;

            case 'project-delete':
                $id = post_id('id');
                if (val('SELECT COUNT(*) FROM time_entries WHERE project_id = ?', array($id))) {
                    throw new RuntimeException('This project has time. Archive it instead.');
                }
                $cid = val('SELECT client_id FROM projects WHERE id = ?', array($id));
                run('DELETE FROM projects WHERE id = ?', array($id));
                $back = '/time/client/' . $cid;
                break;

            case 'service-save':
                $data = array('name' => mb_substr(post('name'), 0, 64), 'rate' => post('rate') !== '' ? parse_money(post('rate')) : null, 'billable' => post_bool('billable'), 'archived' => post_bool('archived'));
                if ($data['name'] === '') {
                    throw new RuntimeException('Name the service.');
                }
                if (post_id('id')) {
                    update('services', 'id', post_id('id'), $data);
                } else {
                    insert('services', $data + array('sort' => (int)val('SELECT COALESCE(MAX(sort), 0) + 1 FROM services')));
                }
                break;

            case 'invoice-create':
                if (post('mode') === 'blank') {
                    $id = Invoice::CreateBlank(post_id('client_id'));
                } else {
                    $id = Invoice::CreateFromUnbilled(post_id('client_id'), array(
                        'from' => post('from'), 'to' => post('to'), 'projects' => $_POST['projects'] ?? null, 'group' => post('group'),
                        'include_expenses' => post_bool('include_expenses'), 'include_flat' => post_bool('include_flat'), 'include_notes' => post_bool('include_notes'), 'title' => post('title'),
                    ));
                }
                App::QueueMessage('Invoice created. Check it over, then send it.');
                $back = '/time/invoice/' . $id;
                break;

            case 'invoice-save':
                Invoice::SaveEdits(post_id('id'), $_POST);
                App::QueueMessage('Invoice saved.');
                break;

            case 'invoice-send':
                Invoice::Send(post_id('id'), post('to'), post('message'));
                Mailer::Flush(5);
                App::QueueMessage('Invoice sent to ' . post('to') . '.');
                break;

            case 'invoice-mark-sent':
                Invoice::MarkSent(post_id('id'));
                App::QueueMessage('Marked as sent.');
                break;

            case 'invoice-payment':
                Invoice::AddPayment(post_id('id'), post('amount'), post('date'), post('method'), post('notes'), post_id('account_id'));
                App::QueueMessage('Payment recorded' . (post_id('account_id') ? ' and added to Money.' : '.'));
                break;

            case 'invoice-payment-delete':
                Invoice::DeletePayment(post_id('payment_id'));
                App::QueueMessage('Payment removed.');
                break;

            case 'invoice-void':
                Invoice::Void(post_id('id'));
                App::QueueMessage('Invoice voided. Its time and expenses are unbilled again.', 'warning');
                break;

            case 'invoice-delete':
                Invoice::Delete(post_id('id'));
                App::QueueMessage('Invoice deleted.');
                $back = '/time/invoices';
                break;

            case 'invoice-duplicate':
                $back = '/time/invoice/' . Invoice::Duplicate(post_id('id'));
                break;

            case 'invoice-remind':
                $inv = Invoice::Get(post_id('id'));
                if ($inv) {
                    $rid = Reminder::Save(null, array('title' => 'Follow up on ' . $inv['number'] . ' (' . $inv['client_name'] . ')', 'due_date' => valid_date(post('date')) ?: date('Y-m-d', strtotime(today() . ' +7 days')), 'link_type' => 'invoice', 'link_id' => $inv['id'], 'priority' => 2, 'list_id' => val("SELECT id FROM reminder_lists WHERE name = 'Work'")));
                    App::QueueMessage('Reminder added.');
                }
                break;
        }
    } catch (RuntimeException $e) {
        App::QueueMessage($e->getMessage(), 'error');
    }
    App::Redirect($back);
}

switch ($p1) {
    case '':
        // Day/week list of entries with the timer on top
        $range = in_array($_GET['range'] ?? '', array('day', 'week', 'month'), true) ? $_GET['range'] : 'week';
        $date = valid_date($_GET['date'] ?? '') ?: today();
        if ($range === 'day') {
            $from = $to = $date;
        } elseif ($range === 'month') {
            $from = substr($date, 0, 7) . '-01';
            $to = date('Y-m-t', strtotime($from));
        } else {
            $from = App::WeekStart($date);
            $to = date('Y-m-d', strtotime($from . ' +6 days'));
        }
        $who = isset($_GET['user']) && $_GET['user'] === 'all' ? null : ((int)($_GET['user'] ?? 0) ?: $uid);
        $entries = TimeTracker::Entries(array('user' => $who, 'from' => $from, 'to' => $to, 'client' => $_GET['client'] ?? null));
        $days = array();
        $tot = array('seconds' => 0, 'billable' => 0, 'value' => 0);
        foreach ($entries as $e) {
            $days[$e['date']]['entries'][] = $e;
            $days[$e['date']]['seconds'] = ($days[$e['date']]['seconds'] ?? 0) + $e['seconds'];
            $tot['seconds'] += $e['seconds'];
            if ($e['billable_effective']) {
                $tot['billable'] += $e['seconds'];
            }
            $tot['value'] += $e['value'];
        }
        krsort($days);
        $step = $range === 'day' ? '1 day' : ($range === 'month' ? '1 month' : '1 week');
        $pageVars += array(
            'range' => $range, 'date' => $date, 'from' => $from, 'to' => $to, 'days' => $days, 'tot' => $tot, 'who' => $who, 'client_filter' => (int)($_GET['client'] ?? 0),
            'prev' => date('Y-m-d', strtotime($date . ' -' . $step)), 'next' => date('Y-m-d', strtotime($date . ' +' . $step)),
            'timer' => TimeTracker::RunningTimer($uid),
            'recent' => rows('SELECT e.project_id, e.service_id, MAX(e.id) AS last_id, pr.name AS project_name, c.name AS client_name, s.name AS service_name, c.color FROM time_entries e
                JOIN projects pr ON pr.id = e.project_id JOIN clients c ON c.id = pr.client_id LEFT JOIN services s ON s.id = e.service_id
                WHERE e.user_id = ? AND pr.status = \'active\' GROUP BY e.project_id, e.service_id, pr.name, c.name, s.name, c.color ORDER BY last_id DESC LIMIT 5', array($uid)),
        );
        $base['page_title'] = 'Time';
        $pageNeeded = 'time/index';
        return;

    case 'timesheet':
        $start = App::WeekStart(valid_date($_GET['week'] ?? '') ?: today());
        $who = (int)($_GET['user'] ?? 0) ?: $uid;
        $pageVars['week'] = TimeTracker::Week($who, $start);
        $pageVars['start'] = $start;
        $pageVars['who'] = $who;
        $pageVars['prev'] = date('Y-m-d', strtotime($start . ' -7 days'));
        $pageVars['next'] = date('Y-m-d', strtotime($start . ' +7 days'));
        $pageVars['goal'] = (float)(row('SELECT weekly_hours_goal FROM admin_users WHERE Id_admin_users = ?', array($who))['weekly_hours_goal'] ?? 0);
        $base['page_title'] = 'Timesheet';
        $pageNeeded = 'time/timesheet';
        return;

    case 'clients':
        $show = $_GET['show'] ?? 'active';
        $clients = TimeTracker::Clients($show === 'all');
        $pageVars += array('clients' => $clients, 'show' => $show,
            'totals' => array('unbilled' => array_sum(array_column($clients, 'unbilled')), 'outstanding' => array_sum(array_column($clients, 'outstanding'))),
            'new' => !empty($_GET['new']));
        $base['page_title'] = 'Clients';
        $pageNeeded = 'time/clients';
        return;

    case 'client':
        $client = row('SELECT * FROM clients WHERE id = ?', array((int)$p2));
        if (!$client) {
            App::NotFound();
        }
        $from = valid_date($_GET['from'] ?? '') ?: date('Y-m-d', strtotime(today() . ' -90 days'));
        $to = valid_date($_GET['to'] ?? '') ?: today();
        $unbilled = TimeTracker::UnbilledByClient()[$client['id']] ?? 0;
        $report = TimeTracker::Report(array('client' => $client['id'], 'from' => $from, 'to' => $to), 'project');
        $pageVars += array(
            'client' => $client,
            'projects' => TimeTracker::Projects($client['id'], 'all'),
            'invoices' => Invoice::All(array('client' => $client['id'])), 'statuses' => Invoice::STATUSES,
            'report' => $report,
            'from' => $from, 'to' => $to,
            'stats' => array(
                'unbilled' => $unbilled,
                'outstanding' => (float)val("SELECT COALESCE(SUM(total - paid), 0) FROM invoices WHERE client_id = ? AND status IN ('sent','partial')", array($client['id'])),
                'collected' => (float)val("SELECT COALESCE(SUM(pm.amount), 0) FROM invoice_payments pm JOIN invoices i ON i.id = pm.invoice_id WHERE i.client_id = ?", array($client['id'])),
                'collected_year' => (float)val("SELECT COALESCE(SUM(pm.amount), 0) FROM invoice_payments pm JOIN invoices i ON i.id = pm.invoice_id WHERE i.client_id = ? AND pm.date >= ?", array($client['id'], substr(today(), 0, 4) . '-01-01')),
                'hours_all' => hours(val('SELECT COALESCE(SUM(duration), 0) FROM time_entries WHERE client_id = ?', array($client['id']))),
                'expenses' => (float)val("SELECT COALESCE(-SUM(amount), 0) FROM fin_transactions WHERE client_id = ? AND kind = 'expense'", array($client['id'])),
            ),
            'revenue' => TimeTracker::Revenue(12, $client['id']),
            'reminders' => rows("SELECT * FROM reminders WHERE status = 'open' AND link_type = 'client' AND link_id = ? ORDER BY due_date", array($client['id'])),
        );
        $base['page_title'] = $client['name'];
        $pageNeeded = 'time/client';
        return;

    case 'projects':
        $status = in_array($_GET['status'] ?? '', array('active', 'completed', 'archived', 'all'), true) ? $_GET['status'] : 'active';
        $pageVars['projects'] = TimeTracker::Projects((int)($_GET['client'] ?? 0) ?: null, $status);
        $pageVars['status'] = $status;
        $base['page_title'] = 'Projects';
        $pageNeeded = 'time/projects';
        return;

    case 'project':
        $project = row('SELECT p.*, c.name AS client_name, c.color AS client_color FROM projects p JOIN clients c ON c.id = p.client_id WHERE p.id = ?', array((int)$p2));
        if (!$project) {
            App::NotFound();
        }
        $project['seconds'] = (int)val('SELECT COALESCE(SUM(duration), 0) FROM time_entries WHERE project_id = ?', array($project['id']));
        $project['unbilled_seconds'] = (int)val('SELECT COALESCE(SUM(duration), 0) FROM time_entries WHERE project_id = ? AND invoice_id IS NULL', array($project['id']));
        TimeTracker::ProjectNumbers($project);
        $report = TimeTracker::Report(array('project' => $project['id']), 'service');
        $pageVars += array(
            'project' => $project,
            'entries' => TimeTracker::Entries(array('project' => $project['id']), 300),
            'by_service' => $report['groups'],
            'by_user' => TimeTracker::Report(array('project' => $project['id']), 'user')['groups'],
            'expenses' => rows("SELECT * FROM fin_transactions WHERE project_id = ? ORDER BY date DESC", array($project['id'])),
            'invoices' => rows('SELECT DISTINCT i.* FROM invoices i JOIN invoice_lines l ON l.invoice_id = i.id WHERE l.project_id = ? ORDER BY i.issue_date DESC', array($project['id'])),
            'weekly' => rows("SELECT YEARWEEK(date, 1) AS wk, MIN(date) AS d, SUM(duration) AS s FROM time_entries WHERE project_id = ? AND date >= ? GROUP BY YEARWEEK(date, 1) ORDER BY wk", array($project['id'], date('Y-m-d', strtotime(today() . ' -16 weeks')))),
        );
        $base['page_title'] = $project['name'];
        $pageNeeded = 'time/project';
        return;

    case 'services':
        $pageVars['all_services'] = rows('SELECT s.*, (SELECT COALESCE(SUM(duration), 0) FROM time_entries e WHERE e.service_id = s.id) AS seconds FROM services s ORDER BY s.archived, s.sort, s.name');
        $base['page_title'] = 'Services';
        $pageNeeded = 'time/services';
        return;

    case 'reports':
        require CONTROLLERS . 'parts/time-reports.php';
        return;

    case 'invoices':
        $f = array('client' => $_GET['client'] ?? null, 'status' => $_GET['status'] ?? null, 'q' => $_GET['q'] ?? null);
        $pageVars['invoices'] = Invoice::All($f);
        $pageVars['f'] = $f;
        $pageVars['statuses'] = Invoice::STATUSES;
        $pageVars['sum'] = row("SELECT COALESCE(SUM(CASE WHEN status IN ('sent','partial') THEN total - paid END), 0) AS outstanding,
                COALESCE(SUM(CASE WHEN status IN ('sent','partial') AND due_date < ? THEN total - paid END), 0) AS overdue,
                COALESCE(SUM(CASE WHEN status = 'draft' THEN total END), 0) AS draft FROM invoices", array(today()));
        $pageVars['paid_year'] = (float)val('SELECT COALESCE(SUM(amount), 0) FROM invoice_payments WHERE date >= ?', array(substr(today(), 0, 4) . '-01-01'));
        $base['page_title'] = 'Invoices';
        $pageNeeded = 'time/invoices';
        return;

    case 'invoice-new':
        $clientId = (int)($_GET['client'] ?? 0);
        $pageVars['client_id'] = $clientId;
        $pageVars['unbilled_by_client'] = TimeTracker::UnbilledByClient();
        if ($clientId) {
            $u = Invoice::Unbilled($clientId);
            $pageVars['unbilled'] = $u;
            $pageVars['client_projects'] = rows("SELECT * FROM projects WHERE client_id = ? AND status <> 'archived' ORDER BY name", array($clientId));
            $pageVars['oldest'] = $u['entries'] ? min(array_column($u['entries'], 'date')) : today();
            $pageVars['time_total'] = array_sum(array_column($u['entries'], 'value'));
            $pageVars['time_seconds'] = array_sum(array_column($u['entries'], 'seconds'));
            $pageVars['exp_total'] = -array_sum(array_column($u['expenses'], 'amount'));
            $pageVars['flat_total'] = array_sum(array_column($u['flat'], 'flat_fee'));
        }
        $base['page_title'] = 'New invoice';
        $pageNeeded = 'time/invoice-new';
        return;

    case 'invoice':
        $inv = Invoice::Get((int)$p2);
        if (!$inv) {
            App::NotFound();
        }
        $pageVars['invoice'] = $inv;
        $pageVars['company'] = array('name' => App::Setting('company_name'), 'email' => App::Setting('company_email'), 'address' => App::Setting('company_address'), 'phone' => App::Setting('company_phone'), 'pay' => App::Setting('payment_instructions'));
        $pageVars['methods'] = Invoice::METHODS;
        $pageVars['accounts'] = rows("SELECT id, name FROM fin_accounts WHERE archived = 0 AND type IN ('checking','savings','cash','other') ORDER BY sort, name");
        $pageVars['statuses'] = Invoice::STATUSES;
        $pageVars['public_url'] = App::BaseUrl() . '/i/' . $inv['public_token'];
        $pageVars['edit'] = isset($_GET['edit']) || $inv['status'] === 'draft';
        $pageVars['entries'] = TimeTracker::Entries(array('invoice' => $inv['id']), 1000);
        $base['page_title'] = 'Invoice ' . $inv['number'];
        $pageNeeded = 'time/invoice';
        return;

    default:
        App::NotFound();
}
