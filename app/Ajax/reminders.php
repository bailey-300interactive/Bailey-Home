<?php

/**
 * Reminder actions: quick add (+ live preview), save, complete, reopen, snooze, delete, get
 */

define('ROOT', realpath(__DIR__ . '/../..') . '/');
require(ROOT . 'app/init.php');

App::RequireLogin();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_out(array('result' => false, 'message' => 'POST only'), 405);
}
App::CheckCsrf();
$action = post('action');

/** Little chips that show how the quick-add text was understood */
function preview_html(array $q)
{
    $chips = array();
    $chips[] = '<span class="chip chip-blue">✏️ ' . h($q['title']) . '</span>';
    if ($q['due_date']) {
        $chips[] = '<span class="chip chip-green">📅 ' . h(TwigExtras::RelativeDay($q['due_date'])) . ($q['due_date'] !== today() && strlen(TwigExtras::RelativeDay($q['due_date'])) < 10 ? ' ' . date('M j', strtotime($q['due_date'])) : '') . '</span>';
    }
    if ($q['due_time']) {
        $chips[] = '<span class="chip chip-green">⏰ ' . date('g:i A', strtotime($q['due_time'])) . '</span>';
    }
    if ($q['repeat_unit']) {
        $chips[] = '<span class="chip chip-purple">🔁 ' . h(Reminder::RepeatText($q)) . '</span>';
    }
    if ($q['priority']) {
        $chips[] = '<span class="chip chip-red">❗ ' . Reminder::PRIORITIES[$q['priority']] . '</span>';
    }
    if ($q['list_id']) {
        $l = row('SELECT name, icon FROM reminder_lists WHERE id = ?', array($q['list_id']));
        if ($l) {
            $chips[] = '<span class="chip">' . h($l['icon'] . ' ' . $l['name']) . '</span>';
        }
    }
    return implode(' ', $chips);
}

try {
    switch ($action) {
        case 'parse':
            $q = Reminder::ParseQuick(post('text'));
            json_out(array('result' => true, 'parsed' => $q, 'html' => preview_html($q)));

        case 'quick':
            $q = Reminder::ParseQuick(post('text'));
            if (post_id('list_id') && !$q['list_id']) {
                $q['list_id'] = post_id('list_id');
            }
            if (post('due_date') && !$q['due_date']) {
                $q['due_date'] = post('due_date');
            }
            $id = Reminder::Save(null, $q);
            $when = $q['due_date'] ? ' for ' . TwigExtras::RelativeDay($q['due_date']) . ($q['due_time'] ? ' at ' . date('g:i A', strtotime($q['due_time'])) : '') : '';
            json_out(array('result' => true, 'id' => $id, 'message' => 'Reminder added' . $when));

        case 'save':
            $in = $_POST;
            $in['repeat_unit'] = post('repeat') === 'custom' ? post('repeat_unit') : (post('repeat') === 'weekdays' ? 'week' : post('repeat'));
            if (post('repeat') === 'weekdays') {
                $in['repeat_days'] = array('mon', 'tue', 'wed', 'thu', 'fri');
                $in['repeat_interval'] = 1;
            } elseif (post('repeat') !== 'custom') {
                $in['repeat_interval'] = 1;
                if (post('repeat') !== 'week') {
                    $in['repeat_days'] = array();
                }
            }
            $id = Reminder::Save(post_id('id'), $in);
            json_out(array('result' => true, 'id' => $id, 'message' => 'Saved'));

        case 'get':
            $r = row('SELECT * FROM reminders WHERE id = ?', array(post_id('id')));
            if (!$r) {
                throw new RuntimeException('Reminder not found.');
            }
            $r['repeat_days'] = $r['repeat_days'] ? explode(',', $r['repeat_days']) : array();
            $r['repeat'] = !$r['repeat_unit'] ? '' : ($r['repeat_unit'] === 'week' && $r['repeat_days'] === array('mon', 'tue', 'wed', 'thu', 'fri') && $r['repeat_interval'] == 1 ? 'weekdays' : ($r['repeat_interval'] > 1 || ($r['repeat_unit'] === 'week' && $r['repeat_days']) ? 'custom' : $r['repeat_unit']));
            $r['due_time'] = $r['due_time'] ? substr($r['due_time'], 0, 5) : '';
            json_out(array('result' => true, 'reminder' => $r));

        case 'complete':
            $res = Reminder::Complete(post_id('id'));
            json_out(array('result' => true, 'next' => $res['next'], 'message' => $res['next'] ? 'Done. Next one ' . TwigExtras::RelativeDay($res['next']) : 'Done'));

        case 'reopen':
            Reminder::Reopen(post_id('id'));
            json_out(array('result' => true));

        case 'snooze':
            $d = Reminder::Snooze(post_id('id'), post('until'));
            json_out(array('result' => true, 'message' => 'Snoozed until ' . TwigExtras::RelativeDay($d)));

        case 'delete':
            run('DELETE FROM reminders WHERE id = ?', array(post_id('id')));
            json_out(array('result' => true, 'message' => 'Deleted'));

        case 'move':
            $date = post('due_date') === '' ? null : valid_date(post('due_date'));
            $r = row('SELECT * FROM reminders WHERE id = ?', array(post_id('id')));
            if ($r) {
                update('reminders', 'id', $r['id'], array('due_date' => $date, 'due_at' => Reminder::DueAt($date, $r['due_time']), 'notified_at' => null, 'snoozed_until' => null));
            }
            json_out(array('result' => true));
    }
} catch (RuntimeException $e) {
    json_out(array('result' => false, 'message' => $e->getMessage()));
}

json_out(array('result' => false, 'message' => 'Action not recognized'));
