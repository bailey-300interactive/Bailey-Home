<?php

/**
 * Admin User Class (framework class, hardened)
 * @author Bailey Rotellini
 */
class AdminUser extends Crud
{
    protected $table = 'admin_users';
    protected $pk = 'Id_admin_users';
    protected $settingsTable = 'user_level_settings';

    const MAX_TRIES = 5;
    const LOCK_MINUTES = 10;

    public function __construct($Id = false)
    {
        parent::__construct($Id);
    }

    public static function FindByUsername($username)
    {
        return row('SELECT * FROM admin_users WHERE username = ? OR email = ? LIMIT 1', array(trim((string)$username), trim((string)$username)));
    }

    public static function SetPassword($uid, $password)
    {
        run('UPDATE admin_users SET password = ?, reset_token = NULL, reset_expires = NULL, reset_password = 0, failed_logins = 0, locked_until = NULL WHERE Id_admin_users = ?', array(password_hash($password, PASSWORD_DEFAULT), $uid));
    }

    public static function PasswordProblem($password)
    {
        if (strlen($password) < 12) {
            return 'Use at least 12 characters.';
        }
        if (Vault::Strength($password) < 2) {
            return 'That password is too easy to guess. Try a few random words together.';
        }
        return null;
    }

    /**
     * Check a username and password. Returns array(status, user) where status is
     * ok | 2fa | locked | wrong | inactive
     */
    public static function Attempt($username, $password)
    {
        $u = self::FindByUsername($username);
        if (!$u) {
            password_verify($password, '$2y$10$p6NpzoRKndDoelZStgeF5ufKFu2ojGHUJ.stU92aiL6rip1BiUg8i'); // same timing as a real check
            return array('wrong', null);
        }
        if (!$u['active']) {
            return array('inactive', null);
        }
        if ($u['locked_until'] && $u['locked_until'] > App::UtcNow()) {
            return array('locked', $u);
        }
        if (!$u['password'] || !password_verify($password, $u['password'])) {
            $fails = (int)$u['failed_logins'] + 1;
            $lock = $fails >= self::MAX_TRIES ? gmdate('Y-m-d H:i:s', time() + self::LOCK_MINUTES * 60) : null;
            run('UPDATE admin_users SET failed_logins = ?, locked_until = ? WHERE Id_admin_users = ?', array($lock ? 0 : $fails, $lock, $u['Id_admin_users']));
            return array($lock ? 'locked' : 'wrong', $u);
        }
        if (password_needs_rehash($u['password'], PASSWORD_DEFAULT)) {
            run('UPDATE admin_users SET password = ? WHERE Id_admin_users = ?', array(password_hash($password, PASSWORD_DEFAULT), $u['Id_admin_users']));
        }
        run('UPDATE admin_users SET failed_logins = 0, locked_until = NULL WHERE Id_admin_users = ?', array($u['Id_admin_users']));
        return array($u['totp_enabled'] ? '2fa' : 'ok', $u);
    }

    /** Start the session for a user (framework session variables) */
    public static function StartSession(array $u)
    {
        session_regenerate_id(true);
        $level = row('SELECT * FROM user_level_settings WHERE Id_user_level_settings = ?', array($u['auth_level']));
        $_SESSION['authenticated'] = true;
        $_SESSION['user_Id'] = (int)$u['Id_admin_users'];
        $_SESSION['username'] = $u['username'];
        $_SESSION['name'] = trim($u['first_name'] . ' ' . $u['last_name']);
        $_SESSION['first_name'] = $u['first_name'];
        $_SESSION['title'] = $u['title'];
        $_SESSION['auth_level'] = (int)$u['auth_level'];
        $_SESSION['user_folder'] = $level['folder'] ?? 'user';
        $_SESSION['auth_text'] = $level['display_name'] ?? 'Member';
        $_SESSION['site_theme'] = $u['site_theme'];
        $_SESSION['profile_image'] = $u['profile_image'];
        unset($_SESSION['pending_2fa']);
        run('UPDATE admin_users SET last_login = ? WHERE Id_admin_users = ?', array(App::UtcNow(), $u['Id_admin_users']));
    }

    /** Email a reset link. Always looks the same to the caller. */
    public static function SendReset($username)
    {
        $u = self::FindByUsername($username);
        if (!$u || !$u['active']) {
            return;
        }
        $token = token(32);
        run('UPDATE admin_users SET reset_token = ?, reset_expires = ?, reset_password = 1 WHERE Id_admin_users = ?', array(hash('sha256', $token), gmdate('Y-m-d H:i:s', time() + 3600), $u['Id_admin_users']));
        Mailer::Queue($u['email'] ?: $u['username'], $u['first_name'], 'Reset your password', 'reset-password', array(
            'first_name' => $u['first_name'], 'url' => App::BaseUrl() . '/reset-password?token=' . $token
        ));
        Mailer::Flush(5);
    }

    public static function FindByResetToken($token)
    {
        if (!is_string($token) || strlen($token) < 20) {
            return null;
        }
        $u = row('SELECT * FROM admin_users WHERE reset_token = ? AND reset_password = 1 AND reset_expires > ?', array(hash('sha256', $token), App::UtcNow()));
        return $u ?: null;
    }

    public static function ListAll()
    {
        return rows('SELECT u.*, l.display_name AS level_name, (SELECT 1 FROM vault_keys k WHERE k.user_id = u.Id_admin_users) AS has_vault FROM admin_users u LEFT JOIN user_level_settings l ON l.Id_user_level_settings = u.auth_level ORDER BY u.first_name, u.username');
    }

    public static function Options()
    {
        return rows("SELECT Id_admin_users AS id, TRIM(CONCAT(first_name, ' ', last_name)) AS name FROM admin_users WHERE active = 1 ORDER BY first_name");
    }
}
