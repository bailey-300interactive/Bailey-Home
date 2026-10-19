<?php

/**
 * Vault actions for the vault page (all need the vault unlocked)
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

// Status check doesn't count as activity, so it never keeps the vault open by itself
if ($action === 'status') {
    json_out(array('result' => true, 'unlocked' => Vault::Key(false) !== null, 'seconds_left' => Vault::SecondsLeft()));
}
if ($action === 'generate') {
    json_out(array('result' => true, 'password' => Vault::Generate((int)post('length', 20), post('upper', '1') === '1', post('digits', '1') === '1', post('symbols', '1') === '1')));
}
if ($action === 'strength') {
    json_out(array('result' => true, 'score' => Vault::Strength($_POST['password'] ?? '')));
}

Vault::RequireUnlocked();

try {
    switch ($action) {
        case 'ping':
            // activity on the vault page keeps it open
            json_out(array('result' => true, 'seconds_left' => Vault::SecondsLeft()));

        case 'get':
            $it = Vault::Item(post_id('id'));
            if (!$it) {
                throw new RuntimeException('Item not found.');
            }
            $d = $it['d'];
            $out = array(
                'id' => (int)$it['id'], 'type' => $it['type'], 'folder_id' => $it['folder_id'], 'favorite' => (int)$it['favorite'],
                'd' => $d, 'strength' => isset($d['password']) && $d['password'] !== '' ? Vault::Strength($d['password']) : null,
                'created' => App::Local($it['created_at'])->format('M j, Y'), 'updated' => App::Local($it['updated_at'])->format('M j, Y g:i A'),
                'changed' => $it['password_changed_at'] ? App::Local($it['password_changed_at'])->format('M j, Y') : null,
                'source' => $it['source'], 'history' => count(Vault::History($it['id'])),
                'reused' => 0,
            );
            if (!empty($d['password'])) {
                foreach (Vault::Items() as $other) {
                    if ($other['id'] != $it['id'] && ($other['d']['password'] ?? null) === $d['password']) {
                        $out['reused']++;
                    }
                }
            }
            json_out(array('result' => true, 'item' => $out));

        case 'save':
            $id = post_id('id');
            $type = post('type', 'login');
            $data = $_POST;
            $data['fields'] = null;
            $newId = Vault::Save($id, $type, $data, post_id('folder_id'), post_bool('favorite'));
            App::Activity('vault', ($id ? 'Updated ' : 'Added ') . 'a vault item', '/vault');
            json_out(array('result' => true, 'id' => $newId, 'message' => 'Saved'));

        case 'delete':
            Vault::Delete(post_id('id'));
            json_out(array('result' => true, 'message' => 'Deleted'));

        case 'favorite':
            run('UPDATE vault_items SET favorite = 1 - favorite WHERE id = ? AND user_id = ?', array(post_id('id'), $uid));
            json_out(array('result' => true));

        case 'move':
            $folder = post_id('folder_id');
            if ($folder && !val('SELECT 1 FROM vault_folders WHERE id = ? AND user_id = ?', array($folder, $uid))) {
                throw new RuntimeException('Folder not found.');
            }
            run('UPDATE vault_items SET folder_id = ? WHERE id = ? AND user_id = ?', array($folder, post_id('id'), $uid));
            json_out(array('result' => true));

        case 'used':
            run('UPDATE vault_items SET last_used_at = ? WHERE id = ? AND user_id = ?', array(App::UtcNow(), post_id('id'), $uid));
            json_out(array('result' => true));

        case 'totp':
            $it = Vault::Item(post_id('id'));
            $code = $it ? Totp::Now($it['d']['totp'] ?? '') : null;
            if (!$code) {
                throw new RuntimeException('That 2FA key is not valid.');
            }
            json_out(array('result' => true) + $code);

        case 'history':
            $h = Vault::History(post_id('id'));
            foreach ($h as &$x) {
                $x['when'] = App::Local($x['created_at'])->format('M j, Y g:i A');
                unset($x['created_at']);
            }
            json_out(array('result' => true, 'history' => $h));

        case 'folder':
            $name = mb_substr(post('name'), 0, 64);
            if ($name === '') {
                throw new RuntimeException('Name the folder.');
            }
            json_out(array('result' => true, 'id' => Vault::FolderByName($name)));
    }
} catch (RuntimeException $e) {
    json_out(array('result' => false, 'message' => $e->getMessage()));
}

json_out(array('result' => false, 'message' => 'Action not recognized'));
