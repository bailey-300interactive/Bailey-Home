<?php

/**
 * Reminders: /reminders (today), /reminders/upcoming, /reminders/all, /reminders/someday, /reminders/done, /reminders/list/<id>
 */

App::RequireLogin();
$base['page_title'] = 'Reminders';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    App::CheckCsrf();
    $action = post('action');
    if ($action === 'list-save') {
        $name = mb_substr(post('name'), 0, 64);
        if ($name !== '') {
            $data = array('name' => $name, 'color' => preg_match('/^#[0-9a-f]{6}$/i', post('color')) ? post('color') : '#3b7ddd', 'icon' => mb_substr(post('icon'), 0, 8) ?: null);
            if (post_id('id')) {
                update('reminder_lists', 'id', post_id('id'), $data);
            } else {
                $data['sort'] = (int)val('SELECT COALESCE(MAX(sort), 0) + 1 FROM reminder_lists');
                insert('reminder_lists', $data);
            }
        }
    } elseif ($action === 'list-delete') {
        run('UPDATE reminders SET list_id = NULL WHERE list_id = ?', array(post_id('id')));
        run('DELETE FROM reminder_lists WHERE id = ?', array(post_id('id')));
        App::QueueMessage('List deleted. Its reminders are still here, without a list.');
        App::Redirect('/reminders');
    } elseif ($action === 'clear-done') {
        run("DELETE FROM reminders WHERE status = 'done' AND completed_at < ?", array(gmdate('Y-m-d H:i:s', strtotime('-30 days'))));
        App::QueueMessage('Cleared reminders finished more than 30 days ago.');
    }
    App::Redirect($_SERVER['REQUEST_URI']);
}

$view = in_array($p1, array('upcoming', 'all', 'someday', 'done', 'list'), true) ? $p1 : 'today';
$listId = $view === 'list' ? (int)$p2 : null;
$list = null;
if ($listId) {
    $list = row('SELECT * FROM reminder_lists WHERE id = ?', array($listId));
    if (!$list) {
        App::NotFound();
    }
}
$search = trim($_GET['q'] ?? '');
if ($view === 'done') {
    // Every tick, including each time a repeating reminder was done
    $items = rows("SELECT c.id AS completion_id, c.reminder_id AS id, c.title, c.due_date, c.completed_at, r.status, r.repeat_unit, r.repeat_interval, r.repeat_days, r.repeat_from,
            r.priority, r.notes, l.name AS list_name, l.color AS list_color, l.icon AS list_icon, NULL AS due_time, NULL AS link_type, NULL AS link_id
        FROM reminder_completions c LEFT JOIN reminders r ON r.id = c.reminder_id LEFT JOIN reminder_lists l ON l.id = r.list_id
        WHERE (? = '' OR c.title LIKE ?) ORDER BY c.completed_at DESC LIMIT 300", array($search, '%' . $search . '%'));
    foreach ($items as &$r) {
        $r['repeat_text'] = Reminder::RepeatText($r);
        $r['overdue'] = false;
        $r['snoozed'] = false;
        $r['link'] = null;
    }
    unset($r);
} else {
    $items = Reminder::ForView($view === 'list' ? 'all' : $view, $listId, $search);
}

// Group by day
$groups = array();
$t = today();
$tomorrow = date('Y-m-d', strtotime($t . ' +1 day'));
foreach ($items as $r) {
    if ($view === 'done') {
        $key = App::Local($r['completed_at'])->format('Y-m-d');
        $label = TwigExtras::RelativeDay($key);
    } elseif (!$r['due_date']) {
        $key = 'zzzz';
        $label = 'Someday';
    } elseif ($r['due_date'] < $t) {
        $key = '0000';
        $label = 'Overdue';
    } else {
        $key = $r['due_date'];
        $label = $r['due_date'] === $t ? 'Today' : ($r['due_date'] === $tomorrow ? 'Tomorrow' : date('l, M j', strtotime($r['due_date'])));
        if ($r['due_date'] > date('Y-m-d', strtotime($t . ' +6 days'))) {
            $label = date('D, M j', strtotime($r['due_date']));
        }
    }
    if (!isset($groups[$key])) {
        $groups[$key] = array('label' => $label, 'items' => array(), 'bills' => array(), 'overdue' => $key === '0000');
    }
    $groups[$key]['items'][] = $r;
}
// Bills show up in Today / Upcoming
if (in_array($view, array('today', 'upcoming'), true) && !$search) {
    foreach (Reminder::UpcomingBills($view === 'today' ? 0 : 14) as $b) {
        if ($view === 'upcoming' && $b['next_due'] <= $t) {
            continue;
        }
        $key = $b['next_due'] < $t ? '0000' : $b['next_due'];
        if (!isset($groups[$key])) {
            $groups[$key] = array('label' => $key === '0000' ? 'Overdue' : TwigExtras::RelativeDay($b['next_due']), 'items' => array(), 'bills' => array(), 'overdue' => $key === '0000');
        }
        $groups[$key]['bills'][] = $b;
    }
}
if ($view === 'done') {
    krsort($groups);
} else {
    ksort($groups);
}

$counts = array(
    'today' => (int)val("SELECT COUNT(*) FROM reminders WHERE status = 'open' AND due_date <= ?", array($t)),
    'upcoming' => (int)val("SELECT COUNT(*) FROM reminders WHERE status = 'open' AND due_date > ? AND due_date <= ?", array($t, date('Y-m-d', strtotime($t . ' +14 days')))),
    'all' => (int)val("SELECT COUNT(*) FROM reminders WHERE status = 'open'"),
    'someday' => (int)val("SELECT COUNT(*) FROM reminders WHERE status = 'open' AND due_date IS NULL"),
    'done' => (int)val("SELECT COUNT(*) FROM reminder_completions WHERE completed_at >= ?", array(gmdate('Y-m-d H:i:s', strtotime('-7 days')))),
);
$lists = rows("SELECT l.*, (SELECT COUNT(*) FROM reminders r WHERE r.list_id = l.id AND r.status = 'open') AS n FROM reminder_lists l ORDER BY l.sort, l.name");

$pageVars += array(
    'view' => $view, 'list' => $list, 'groups' => array_values($groups), 'count' => count($items), 'counts' => $counts, 'lists' => $lists,
    'search' => $search, 'users' => AdminUser::Options(), 'open' => isset($_GET['open']) ? (int)$_GET['open'] : null,
    'titles' => array('today' => 'Today', 'upcoming' => 'Next 2 weeks', 'all' => 'All open', 'someday' => 'Someday', 'done' => 'Completed'),
    'streak' => (int)val('SELECT COUNT(*) FROM reminder_completions WHERE completed_at >= ?', array(App::ToUtc(today() . ' 00:00:00'))),
);
$base['page_title'] = $list ? $list['name'] : $pageVars['titles'][$view];
$pageNeeded = 'reminders/index';
