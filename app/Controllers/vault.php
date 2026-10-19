<?php

/**
 * Vault pages: /vault, /vault/health, /vault/generator, /vault/import, /vault/settings
 */

App::RequireLogin();
$uid = App::UserId();
$base['page_title'] = 'Vault';

/* ---------- Setup / unlock / recover / lock (form posts) ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array(post('action'), array('setup', 'unlock', 'recover', 'lock'), true)) {
    App::CheckCsrf();
    $action = post('action');
    if ($action === 'lock') {
        Vault::Lock();
        App::QueueMessage('Vault locked.', 'info');
        App::Redirect('/vault');
    }
    if (!App::RateLimit('vault-unlock:' . $uid, 10, 900)) {
        App::QueueMessage('Too many tries. Wait 15 minutes.', 'error');
        App::Redirect('/vault');
    }
    $master = $_POST['master'] ?? '';
    if ($action === 'setup') {
        if ($master !== ($_POST['master2'] ?? '')) {
            App::QueueMessage('The two master passwords are different.', 'error');
            App::Redirect('/vault');
        }
        if (mb_strlen($master) < 12 || Vault::Strength($master) < 3) {
            App::QueueMessage('Make the master password longer: 4 or more random words works well.', 'error');
            App::Redirect('/vault');
        }
        $u = App::User();
        if ($u['password'] && password_verify($master, $u['password'])) {
            App::QueueMessage('Use a different password from your login password.', 'error');
            App::Redirect('/vault');
        }
        $_SESSION['vault_recovery'] = Vault::Setup($uid, $master, post('hint'));
        App::Activity('vault', 'Set up the vault', '/vault');
        App::Redirect('/vault/recovery');
    }
    if ($action === 'unlock') {
        if (Vault::Unlock($uid, $master)) {
            $to = $_SESSION['vault_return'] ?? '/vault';
            unset($_SESSION['vault_return']);
            App::Redirect(str_starts_with($to, '/vault') ? $to : '/vault');
        }
        App::QueueMessage('Wrong master password.', 'error');
        App::Redirect('/vault');
    }
    if ($action === 'recover') {
        if ($master !== ($_POST['master2'] ?? '')) {
            App::QueueMessage('The two new master passwords are different.', 'error');
            App::Redirect('/vault/recover');
        }
        if (mb_strlen($master) < 12 || Vault::Strength($master) < 3) {
            App::QueueMessage('Make the new master password longer.', 'error');
            App::Redirect('/vault/recover');
        }
        $code = Vault::Recover($uid, post('code'), $master, post('hint'));
        if (!$code) {
            App::QueueMessage('That recovery code didn\'t work.', 'error');
            App::Redirect('/vault/recover');
        }
        $_SESSION['vault_recovery'] = $code;
        App::Activity('vault', 'Reset the master password with the recovery code', '/vault');
        App::Redirect('/vault/recovery');
    }
}

/* ---------- Not set up yet ---------- */
if (!Vault::IsSetUp($uid)) {
    $pageNeeded = 'vault/setup';
    $pageVars['has_legacy'] = true;
    return;
}

/* ---------- Show the recovery code once ---------- */
if ($p1 === 'recovery') {
    if (empty($_SESSION['vault_recovery'])) {
        App::Redirect('/vault');
    }
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        App::CheckCsrf();
        unset($_SESSION['vault_recovery']);
        App::Redirect('/vault/import');
    }
    $pageVars['code'] = $_SESSION['vault_recovery'];
    $pageVars['name'] = App::User()['first_name'];
    $pageVars['created'] = App::Now()->format('F j, Y');
    $pageNeeded = 'vault/recovery';
    return;
}

if ($p1 === 'recover') {
    $pageNeeded = 'vault/recover';
    return;
}

/* ---------- Locked ---------- */
if (!Vault::IsUnlocked() && $p1 !== 'generator') {
    $_SESSION['vault_return'] = $_SERVER['REQUEST_URI'] ?? '/vault';
    $pageVars['hint'] = Vault::KeyRow($uid)['hint'];
    $pageVars['count'] = (int)val('SELECT COUNT(*) FROM vault_items WHERE user_id = ?', array($uid));
    $pageNeeded = 'vault/locked';
    return;
}
Vault::Key(); // vault activity: push auto-lock back
$pageVars['seconds_left'] = Vault::SecondsLeft();

switch ($p1) {
    case 'health':
        $items = Vault::Items();
        $pageVars['health'] = Vault::Health($items);
        $base['page_title'] = 'Password health';
        $pageNeeded = 'vault/health';
        return;

    case 'generator':
        $base['page_title'] = 'Password generator';
        $pageNeeded = 'vault/generator';
        return;

    case 'import':
        require CONTROLLERS . 'parts/vault-import.php';
        return;

    case 'settings':
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            App::CheckCsrf();
            $action = post('action');
            try {
                if ($action === 'change') {
                    if (($_POST['new'] ?? '') !== ($_POST['new2'] ?? '')) {
                        throw new RuntimeException('The two new master passwords are different.');
                    }
                    if (mb_strlen($_POST['new'] ?? '') < 12 || Vault::Strength($_POST['new']) < 3) {
                        throw new RuntimeException('Make the new master password longer.');
                    }
                    if (!App::RateLimit('vault-change:' . $uid, 5, 900) || !Vault::ChangeMaster($uid, $_POST['current'] ?? '', $_POST['new'], post('hint'))) {
                        throw new RuntimeException('Your current master password is wrong.');
                    }
                    App::Activity('vault', 'Changed the master password', '/vault');
                    App::QueueMessage('Master password changed. Your recovery code still works.');
                } elseif ($action === 'autolock') {
                    $m = max(1, min(240, (int)post('minutes')));
                    run('UPDATE vault_keys SET auto_lock_minutes = ? WHERE user_id = ?', array($m, $uid));
                    $_SESSION['vault']['minutes'] = $m;
                    App::QueueMessage('The vault now locks after ' . $m . ' minutes.');
                } elseif ($action === 'recovery') {
                    if (!Vault::CheckMaster($uid, $_POST['current'] ?? '')) {
                        throw new RuntimeException('Wrong master password.');
                    }
                    $_SESSION['vault_recovery'] = Vault::RegenerateRecovery($uid);
                    App::Redirect('/vault/recovery');
                } elseif ($action === 'folder-add') {
                    $name = mb_substr(post('name'), 0, 64);
                    if ($name !== '') {
                        Vault::FolderByName($name);
                    }
                } elseif ($action === 'folder-rename') {
                    run('UPDATE vault_folders SET name = ? WHERE id = ? AND user_id = ?', array(mb_substr(post('name'), 0, 64), post_id('id'), $uid));
                } elseif ($action === 'folder-delete') {
                    run('UPDATE vault_items SET folder_id = NULL WHERE folder_id = ? AND user_id = ?', array(post_id('id'), $uid));
                    run('DELETE FROM vault_folders WHERE id = ? AND user_id = ?', array(post_id('id'), $uid));
                    App::QueueMessage('Folder deleted. Its items are now unfiled.');
                } elseif ($action === 'destroy') {
                    if (post('confirm') !== 'DELETE' || !Vault::CheckMaster($uid, $_POST['current'] ?? '')) {
                        throw new RuntimeException('Type DELETE and your master password to erase the vault.');
                    }
                    Vault::Destroy($uid);
                    App::QueueMessage('Your vault was erased.', 'warning');
                    App::Redirect('/vault');
                }
            } catch (RuntimeException $e) {
                App::QueueMessage($e->getMessage(), 'error');
            }
            App::Redirect('/vault/settings');
        }
        $pageVars['key'] = Vault::KeyRow($uid);
        $pageVars['folders'] = Vault::Folders();
        $base['page_title'] = 'Vault settings';
        $pageNeeded = 'vault/settings';
        return;

    case '':
        break;

    default:
        App::NotFound();
}

/* ---------- Main vault view ---------- */
$items = Vault::Items();
$folders = Vault::Folders();
$list = array();
$counts = array('all' => 0, 'favorites' => 0, 'login' => 0, 'card' => 0, 'note' => 0, 'wifi' => 0, 'server' => 0);
foreach ($items as $it) {
    $d = $it['d'];
    $list[] = array(
        'id' => (int)$it['id'],
        'type' => $it['type'],
        'folder_id' => $it['folder_id'] ? (int)$it['folder_id'] : null,
        'favorite' => (int)$it['favorite'],
        'name' => $d['name'] ?? '',
        'sub' => $it['type'] === 'card' ? (($d['brand'] ?? '') . ' •••• ' . substr(preg_replace('/\D/', '', $d['number'] ?? ''), -4)) : ($it['type'] === 'wifi' ? ($d['ssid'] ?? '') : ($d['username'] ?? ($d['host'] ?? ''))),
        'url' => $d['url'] ?? '',
        'totp' => !empty($d['totp']),
        'weak' => !empty($d['password']) && Vault::Strength($d['password']) <= 1,
        'search' => mb_strtolower(implode(' ', array($d['name'] ?? '', $d['username'] ?? '', $d['url'] ?? '', $d['host'] ?? '', $d['ssid'] ?? '', $d['notes'] ?? ''))),
        'updated' => $it['updated_at'],
    );
    $counts['all']++;
    $counts[$it['type']] = ($counts[$it['type']] ?? 0) + 1;
    if ($it['favorite']) {
        $counts['favorites']++;
    }
}
$pageVars['items'] = $list;
$pageVars['folders'] = $folders;
$pageVars['counts'] = $counts;
$pageVars['types'] = Vault::TYPES;
$pageVars['open_id'] = isset($_GET['item']) ? (int)$_GET['item'] : null;
$pageVars['new_type'] = isset($_GET['new'], Vault::TYPES[$_GET['new']]) ? $_GET['new'] : null;
$pageNeeded = 'vault/index';
