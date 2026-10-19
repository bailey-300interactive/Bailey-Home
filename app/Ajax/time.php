<?php

/**
 * Time actions: timer start/stop/resume, save/get/delete entries, timesheet cells
 */

define('ROOT', realpath(__DIR__ . '/../..') . '/');
require(ROOT . 'app/init.php');

App::RequireLogin();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_out(array('result' => false, 'message' => 'POST only'), 405);
}
App::CheckCsrf();
$uid = App::UserId();
$action = post('action');

try {
    switch ($action) {
        case 'start':
            $id = TimeTracker::Start($uid, $_POST);
            json_out(array('result' => true, 'id' => $id, 'timer' => TimeTracker::RunningTimer($uid)));

        case 'stop':
            $t = TimeTracker::Stop($uid);
            if (!$t) {
                throw new RuntimeException('No timer is running.');
            }
            json_out(array('result' => true, 'id' => $t['id'], 'duration' => hm($t['duration'])));

        case 'resume':
            TimeTracker::Resume($uid, post_id('id'));
            json_out(array('result' => true, 'message' => 'Timer started'));

        case 'timer-update':
            // change project / notes on the running timer
            $t = TimeTracker::RunningTimer($uid);
            if (!$t) {
                throw new RuntimeException('No timer is running.');
            }
            $projectId = post_id('project_id');
            update('time_entries', 'id', $t['id'], array(
                'project_id' => $projectId,
                'client_id' => $projectId ? (int)val('SELECT client_id FROM projects WHERE id = ?', array($projectId)) : null,
                'service_id' => post_id('service_id'),
                'notes' => post('notes') ?: null,
            ));
            json_out(array('result' => true));

        case 'save':
            $id = TimeTracker::Save(post_id('id'), $_POST);
            $e = TimeTracker::Entry($id);
            App::Activity('time', 'Logged ' . hm($e['seconds']) . ($e['project_name'] ? ' on ' . $e['project_name'] : ''), '/time');
            json_out(array('result' => true, 'id' => $id, 'message' => hm($e['seconds']) . ' saved' . ($e['project_name'] ? ' to ' . $e['project_name'] : '')));

        case 'get':
            $e = TimeTracker::Entry(post_id('id'));
            if (!$e) {
                throw new RuntimeException('Time entry not found.');
            }
            $e['duration_text'] = hm($e['seconds']);
            json_out(array('result' => true, 'entry' => $e));

        case 'delete':
            TimeTracker::Delete(post_id('id'));
            json_out(array('result' => true, 'message' => 'Deleted'));

        case 'cell':
            $who = post_id('user') ?: $uid;
            $s = TimeTracker::SetCell($who, post_id('project_id'), post_id('service_id'), post_id('client_id'), post('date'), post('value'));
            json_out(array('result' => true, 'seconds' => $s, 'text' => $s ? hm($s) : ''));

        case 'projects':
            // projects for a client (for selects)
            json_out(array('result' => true, 'projects' => rows("SELECT id, name, billing FROM projects WHERE client_id = ? AND status = 'active' ORDER BY name", array(post_id('client_id')))));
    }
} catch (RuntimeException $e) {
    json_out(array('result' => false, 'message' => $e->getMessage()));
}

json_out(array('result' => false, 'message' => 'Action not recognized'));
