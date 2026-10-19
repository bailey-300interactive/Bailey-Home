<?php

/**
 * Default controller: login, reset password, profile, the public invoice page, and anything unknown.
 */

if ($p === 'login') {
    if (App::IsAuthenticated()) {
        App::Redirect('/');
    }
    $pageNeeded = 'login';
    $base['page_title'] = 'Sign in';
    return;
}

if ($p === 'reset-password') {
    $pageNeeded = 'reset-password';
    $base['page_title'] = 'Reset password';
    if (!empty($_GET['token'])) {
        $u = AdminUser::FindByResetToken($_GET['token']);
        $pageVars['token'] = $_GET['token'];
        $pageVars['valid'] = (bool)$u;
        $pageVars['username'] = $u['username'] ?? '';
    }
    return;
}

// Public invoice link for clients: /i/<token>
if ($p === 'i') {
    $inv = $p1 !== '' ? row('SELECT id FROM invoices WHERE public_token = ?', array($p1)) : null;
    if (!$inv) {
        App::NotFound('This invoice link is not valid.');
    }
    $invoice = Invoice::Get($inv['id']);
    if ($invoice['status'] === 'draft' && !App::IsAuthenticated()) {
        App::NotFound('This invoice is not ready yet.');
    }
    if (!App::IsAuthenticated() && !$invoice['viewed_at']) {
        run('UPDATE invoices SET viewed_at = ? WHERE id = ?', array(App::UtcNow(), $invoice['id']));
    }
    $pageVars['invoice'] = $invoice;
    $pageVars['company'] = array(
        'name' => App::Setting('company_name'), 'email' => App::Setting('company_email'),
        'address' => App::Setting('company_address'), 'phone' => App::Setting('company_phone'),
        'pay' => App::Setting('payment_instructions'),
    );
    $pageVars['public'] = true;
    $base['page_title'] = 'Invoice ' . $invoice['number'];
    header('X-Robots-Tag: noindex');
    $pageNeeded = 'invoice-public';
    return;
}

App::RequireLogin();

if ($p === 'profile') {
    $uid = App::UserId();
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        App::CheckCsrf();
        $action = post('action');
        try {
            if ($action === 'details') {
                $email = post('email');
                if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    throw new RuntimeException('Enter a valid email.');
                }
                if (val('SELECT 1 FROM admin_users WHERE (username = ? OR email = ?) AND Id_admin_users <> ?', array($email, $email, $uid))) {
                    throw new RuntimeException('Another login already uses that email.');
                }
                $tz = in_array(post('timezone'), DateTimeZone::listIdentifiers(), true) ? post('timezone') : null;
                update('admin_users', 'Id_admin_users', $uid, array(
                    'first_name' => mb_substr(post('first_name'), 0, 64), 'last_name' => mb_substr(post('last_name'), 0, 64),
                    'title' => mb_substr(post('title'), 0, 64) ?: null, 'email' => $email, 'username' => $email, 'timezone' => $tz,
                    'site_theme' => in_array(post('site_theme'), array('default', 'dark'), true) ? post('site_theme') : 'default',
                    'hourly_rate' => post('hourly_rate') !== '' ? parse_money(post('hourly_rate')) : null,
                    'weekly_hours_goal' => post('weekly_hours_goal') !== '' ? (float)post('weekly_hours_goal') : null,
                    'notify_reminders' => post_bool('notify_reminders'), 'notify_digest' => post_bool('notify_digest'),
                ));
                $u = row('SELECT * FROM admin_users WHERE Id_admin_users = ?', array($uid));
                $_SESSION['name'] = trim($u['first_name'] . ' ' . $u['last_name']);
                $_SESSION['username'] = $u['username'];
                $_SESSION['site_theme'] = $u['site_theme'];
                App::QueueMessage('Profile saved.');
            } elseif ($action === 'password') {
                $u = row('SELECT * FROM admin_users WHERE Id_admin_users = ?', array($uid));
                if (!password_verify($_POST['current'] ?? '', $u['password'])) {
                    throw new RuntimeException('Your current password is wrong.');
                }
                if (($_POST['new'] ?? '') !== ($_POST['new2'] ?? '')) {
                    throw new RuntimeException('The new passwords are different.');
                }
                if ($problem = AdminUser::PasswordProblem($_POST['new'] ?? '')) {
                    throw new RuntimeException($problem);
                }
                AdminUser::SetPassword($uid, $_POST['new']);
                App::QueueMessage('Login password changed.');
            } elseif ($action === 'sms-send-code') {
                $phone = Sms::StartVerify($uid, post('phone'));
                App::QueueMessage('Code sent to ' . Sms::Pretty($phone) . '.');
            } elseif ($action === 'sms-confirm') {
                $phone = Sms::FinishVerify($uid, post('code'));
                run('UPDATE admin_users SET sms_reminders = 1 WHERE Id_admin_users = ?', array($uid));
                App::QueueMessage('Texts will go to ' . Sms::Pretty($phone) . '.');
            } elseif ($action === 'sms-cancel') {
                unset($_SESSION['sms_verify']);
            } elseif ($action === 'sms-prefs') {
                run('UPDATE admin_users SET sms_reminders = ?, sms_digest = ? WHERE Id_admin_users = ?', array(post_bool('sms_reminders'), post_bool('sms_digest'), $uid));
                App::QueueMessage('Text settings saved.');
            } elseif ($action === 'sms-test') {
                $u = row('SELECT * FROM admin_users WHERE Id_admin_users = ?', array($uid));
                if (!$u['phone_verified_at'] || !App::RateLimit('sms-test:' . $uid, 3, 3600)) {
                    throw new RuntimeException('Confirm your number first (and at most 3 tests an hour).');
                }
                $r = Sms::Send($u['phone'], 'Test from ' . App::Setting('site_name', 'Home') . ': texts are working.');
                if (!$r['ok']) {
                    throw new RuntimeException($r['error']);
                }
                App::QueueMessage('Test text sent.');
            } elseif ($action === 'sms-remove') {
                run('UPDATE admin_users SET phone = NULL, phone_verified_at = NULL, sms_reminders = 0, sms_digest = 0 WHERE Id_admin_users = ?', array($uid));
                App::QueueMessage('Phone number removed. No more texts.', 'warning');
            } elseif ($action === '2fa-start') {
                $_SESSION['totp_setup'] = Totp::NewSecret();
            } elseif ($action === '2fa-confirm') {
                $secret = $_SESSION['totp_setup'] ?? '';
                if (!$secret || !Totp::Verify($secret, post('code'))) {
                    throw new RuntimeException('That code didn\'t match. Scan the QR code again and use the newest code.');
                }
                run('UPDATE admin_users SET totp_secret = ?, totp_enabled = 1 WHERE Id_admin_users = ?', array($secret, $uid));
                unset($_SESSION['totp_setup']);
                App::QueueMessage('Two-step verification is on.');
            } elseif ($action === '2fa-cancel') {
                unset($_SESSION['totp_setup']);
            } elseif ($action === '2fa-off') {
                $u = row('SELECT * FROM admin_users WHERE Id_admin_users = ?', array($uid));
                if (!password_verify($_POST['current'] ?? '', $u['password'])) {
                    throw new RuntimeException('Enter your login password to turn it off.');
                }
                run('UPDATE admin_users SET totp_secret = NULL, totp_enabled = 0 WHERE Id_admin_users = ?', array($uid));
                App::QueueMessage('Two-step verification is off.', 'warning');
            }
        } catch (RuntimeException $e) {
            App::QueueMessage($e->getMessage(), 'error');
        }
        App::Redirect('/profile');
    }
    $user = row('SELECT * FROM admin_users WHERE Id_admin_users = ?', array($uid));
    $pageVars['user'] = $user;
    $pageVars['timezones'] = DateTimeZone::listIdentifiers();
    if (!empty($_SESSION['totp_setup'])) {
        $pageVars['totp_setup'] = $_SESSION['totp_setup'];
        $pageVars['totp_uri'] = Totp::Uri($_SESSION['totp_setup'], $user['username'], App::Setting('site_name', 'Home'));
    }
    $pageVars['vault'] = Vault::KeyRow($uid);
    $pageVars['sms'] = array('enabled' => Sms::Enabled(), 'pending' => $_SESSION['sms_verify']['phone'] ?? null, 'pretty' => Sms::Pretty($user['phone']));
    if ($pageVars['sms']['pending']) {
        $pageVars['sms']['pending_pretty'] = Sms::Pretty($pageVars['sms']['pending']);
    }
    $base['page_title'] = 'Profile';
    $pageNeeded = 'profile';
    return;
}

App::NotFound();
