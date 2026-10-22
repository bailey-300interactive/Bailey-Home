<?php

/**
 * Admin: /admin/settings and /admin/users (administrators only)
 */

App::RequireAdmin();

$settingKeys = array(
    'site_name', 'currency', 'timezone', 'week_start', 'company_name', 'company_email', 'company_phone', 'company_address', 'payment_instructions',
    'invoice_prefix', 'invoice_next', 'invoice_terms', 'invoice_notes', 'default_rate', 'tax_name', 'tax_rate', 'vault_auto_lock', 'digest_hour', 'round_minutes',
);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    App::CheckCsrf();
    $action = post('action');
    try {
        switch ($action) {
            case 'settings':
                foreach ($settingKeys as $k) {
                    if (!isset($_POST[$k])) {
                        continue;
                    }
                    $v = post($k);
                    switch ($k) {
                        case 'timezone': if (!in_array($v, DateTimeZone::listIdentifiers(), true)) continue 2; break;
                        case 'currency': $v = strtoupper(substr(preg_replace('/[^A-Za-z]/', '', $v), 0, 3)) ?: 'USD'; break;
                        case 'week_start': $v = $v === 'sunday' ? 'sunday' : 'monday'; break;
                        case 'invoice_next': case 'invoice_terms': case 'vault_auto_lock': case 'digest_hour': case 'round_minutes': $v = (string)max(0, (int)$v); break;
                        case 'default_rate': case 'tax_rate': $v = (string)(float)str_replace(array('$', ','), '', $v); break;
                        default: $v = mb_substr($v, 0, 2000);
                    }
                    App::SaveSetting($k, $v);
                }
                App::QueueMessage('Settings saved.');
                break;

            case 'test-email':
                $u = App::User();
                $m = new Mailer();
                $r = $m->Send(array(array('email' => $u['email'] ?: $u['username'], 'name' => $u['first_name'])), 'Test email from ' . App::Setting('site_name', 'Home'), 'reminder', array('title' => 'Email is working', 'notes' => 'This is a test from Admin → Settings.', 'when' => App::Now()->format('l, M j \a\t g:i A'), 'url' => App::BaseUrl() . '/reminders'));
                App::QueueMessage($r === true ? 'Test email sent to ' . ($u['email'] ?: $u['username']) . '.' : 'Email failed: ' . $r, $r === true ? 'success' : 'error');
                break;

            case 'user-save':
                $id = post_id('id');
                $email = post('email');
                if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    throw new RuntimeException('Enter a valid email.');
                }
                if (val('SELECT 1 FROM admin_users WHERE (username = ? OR email = ?) AND Id_admin_users <> ?', array($email, $email, (int)$id))) {
                    throw new RuntimeException('Someone already uses that email.');
                }
                $level = post('auth_level') === '0' ? ADMIN : MEMBER;
                if ($id === App::UserId() && $level !== ADMIN) {
                    throw new RuntimeException('You can\'t remove your own admin access.');
                }
                $data = array('username' => $email, 'email' => $email, 'first_name' => mb_substr(post('first_name'), 0, 64), 'last_name' => mb_substr(post('last_name'), 0, 64),
                    'title' => mb_substr(post('title'), 0, 64) ?: null, 'auth_level' => $level, 'hourly_rate' => post('hourly_rate') !== '' ? parse_money(post('hourly_rate')) : null,
                    'active' => $id === App::UserId() ? 1 : post_bool('active'));
                if ($id) {
                    update('admin_users', 'Id_admin_users', $id, $data);
                    if (($_POST['password'] ?? '') !== '') {
                        if ($problem = AdminUser::PasswordProblem($_POST['password'])) {
                            throw new RuntimeException($problem);
                        }
                        AdminUser::SetPassword($id, $_POST['password']);
                    }
                    App::QueueMessage('Saved.');
                } else {
                    $pw = $_POST['password'] ?? '';
                    if ($pw !== '' && ($problem = AdminUser::PasswordProblem($pw))) {
                        throw new RuntimeException($problem);
                    }
                    $data += array('password' => $pw !== '' ? password_hash($pw, PASSWORD_DEFAULT) : null, 'created_at' => App::UtcNow(), 'weekly_hours_goal' => 40);
                    $newId = insert('admin_users', $data);
                    if ($pw === '' || post_bool('send_invite')) {
                        AdminUser::SendReset($email);
                    }
                    App::QueueMessage('Added ' . $data['first_name'] . '.' . ($pw === '' ? ' They got an email to choose a password.' : ''));
                }
                break;

            case 'user-reset-vault':
                $id = post_id('id');
                if (post('confirm') !== 'ERASE') {
                    throw new RuntimeException('Type ERASE to confirm.');
                }
                Vault::Destroy($id);
                App::QueueMessage('That person\'s vault was erased. They can set up a new one.', 'warning');
                break;

            case 'user-unlock':
                run('UPDATE admin_users SET failed_logins = 0, locked_until = NULL WHERE Id_admin_users = ?', array(post_id('id')));
                App::QueueMessage('Login unlocked.');
                break;

            case 'user-2fa-off':
                run('UPDATE admin_users SET totp_enabled = 0, totp_secret = NULL WHERE Id_admin_users = ?', array(post_id('id')));
                App::QueueMessage('Two-step verification turned off for that person.', 'warning');
                break;
        }
    } catch (RuntimeException $e) {
        App::QueueMessage($e->getMessage(), 'error');
    }
    App::Redirect($_SERVER['REQUEST_URI']);
}

if ($p1 === 'users') {
    $pageVars['users'] = AdminUser::ListAll();
    $base['page_title'] = 'Users';
    $pageNeeded = 'admin/users';
    return;
}

if ($p1 === 'settings' || $p1 === '') {
    $pageVars['s'] = App::Settings();
    $pageVars['timezones'] = DateTimeZone::listIdentifiers();
    $mail = @parse_ini_file(ROOT . 'settings/mail.ini.php') ?: array();
    $pageVars['mail'] = array('driver' => $mail['Driver'] ?? 'smtp', 'host' => $mail['Host'] ?? '', 'from' => $mail['FromEmail'] ?? ($mail['Username'] ?? ''));
    $pageVars['queue'] = array('waiting' => (int)val('SELECT COUNT(*) FROM email_queue WHERE sent_at IS NULL AND attempts < 5'), 'failed' => (int)val('SELECT COUNT(*) FROM email_queue WHERE sent_at IS NULL AND attempts >= 5'), 'last_error' => val('SELECT last_error FROM email_queue WHERE last_error IS NOT NULL ORDER BY id DESC LIMIT 1'));
    $pageVars['cron_ok'] = is_file(ROOT . 'storage/cache/cron.lock') && filemtime(ROOT . 'storage/cache/cron.lock') > time() - 600;
    $pageVars['writable'] = array_filter(array('storage/receipts', 'storage/logs', 'storage/cache', 'storage/exports', 'storage/sessions'), function ($d) { return !is_writable(ROOT . $d); });
    $pageVars['https'] = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    $base['page_title'] = 'Settings';
    $pageNeeded = 'admin/settings';
    return;
}

App::NotFound();
